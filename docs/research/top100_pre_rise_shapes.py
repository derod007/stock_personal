"""Unlabeled 6-type clustering of 60-bar price and volume. Does not retune K, weights, or stops."""
from __future__ import annotations

import argparse
import hashlib
import importlib.util
import json
import math
import random
from collections import defaultdict
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
VERSION = "top100_pre_rise_v4_rev1"
SEED = 1042019
K = 6
MAX_ITER = 50
N_BARS = 60
N_FAINT = 12
N_BOOT = 1000
TYPE1 = 1
DOC_CHARTS = ROOT / "docs/top100-pre-rise-v4/charts"


def load_mod(name: str, rel: str):
    spec = importlib.util.spec_from_file_location(name, ROOT / rel)
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


def sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def quantile(xs: list[float], p: float) -> float | None:
    if not xs:
        return None
    s = sorted(xs)
    if len(s) == 1:
        return s[0]
    i = (len(s) - 1) * p
    lo = int(math.floor(i))
    hi = min(len(s) - 1, int(math.ceil(i)))
    if lo == hi:
        return s[lo]
    w = i - lo
    return s[lo] * (1 - w) + s[hi] * w


def _mean(xs: list[float]) -> float | None:
    return None if not xs else sum(xs) / len(xs)


def _round(x, n=4):
    return None if x is None else round(x, n)


def l2_unit(xs: list[float]) -> list[float] | None:
    n = math.sqrt(sum(x * x for x in xs))
    if n <= 0 or not math.isfinite(n):
        return None
    return [x / n for x in xs]


def series60(past: list[dict]) -> dict | None:
    if len(past) < N_BARS:
        return None
    win = past[-N_BARS:]
    closes = [b["close"] for b in win]
    vols = [b["volume"] for b in win]
    c_t = closes[-1]
    vmean = sum(vols) / N_BARS
    if c_t <= 0 or vmean <= 0:
        return None
    if any((not math.isfinite(c)) or c <= 0 for c in closes):
        return None
    if any((not math.isfinite(v)) or v < 0 for v in vols):
        return None
    price = [c / c_t for c in closes]
    vol = [v / vmean for v in vols]
    lp = l2_unit(price)
    lv = l2_unit(vol)
    if lp is None or lv is None:
        return None
    return {"price": price, "vol": vol, "feat": lp + lv}


def sqdist(a: list[float], b: list[float]) -> float:
    s = 0.0
    for i, av in enumerate(a):
        d = av - b[i]
        s += d * d
    return s


def kmeans_pp(X: list[list[float]], k: int, rng: random.Random) -> list[list[float]]:
    n = len(X)
    centers = [X[rng.randrange(n)][:]]
    for _ in range(1, k):
        d2 = [min(sqdist(x, c) for c in centers) for x in X]
        total = sum(d2)
        if total <= 0:
            centers.append(X[rng.randrange(n)][:])
            continue
        r = rng.random() * total
        acc = 0.0
        pick = n - 1
        for i, d in enumerate(d2):
            acc += d
            if acc >= r:
                pick = i
                break
        centers.append(X[pick][:])
    return centers


def kmeans(X: list[list[float]], k: int = K, seed: int = SEED, max_iter: int = MAX_ITER):
    if len(X) < k:
        raise ValueError("not enough rows for k-means")
    rng = random.Random(seed)
    centers = kmeans_pp(X, k, rng)
    labels = [0] * len(X)
    dim = len(X[0])
    n_iter = 0
    for n_iter in range(1, max_iter + 1):
        changed = False
        for i, x in enumerate(X):
            best, bd = 0, float("inf")
            for j, c in enumerate(centers):
                d = sqdist(x, c)
                if d < bd:
                    best, bd = j, d
            if labels[i] != best:
                labels[i] = best
                changed = True
        for j in range(k):
            members = [X[i] for i, lab in enumerate(labels) if lab == j]
            if not members:
                far = max(range(len(X)), key=lambda i: sqdist(X[i], centers[j]))
                centers[j] = X[far][:]
                continue
            centers[j] = [sum(m[d] for m in members) / len(members) for d in range(dim)]
        if not changed:
            break
    inertia = sum(sqdist(X[i], centers[labels[i]]) for i in range(len(X)))
    return {"labels": labels, "centers": centers, "n_iter": n_iter, "inertia": inertia, "k": k, "seed": seed}


def assign(X: list[list[float]], centers: list[list[float]]) -> list[int]:
    out = []
    for x in X:
        best, bd = 0, float("inf")
        for j, c in enumerate(centers):
            d = sqdist(x, c)
            if d < bd:
                best, bd = j, d
        out.append(best)
    return out


def rec_for(e: dict, period: dict, v3) -> dict:
    rec_n, need_n = v3.rec_cover_for_episode(e, period)
    caught = bool(e.get("caught_in_window"))
    return {
        "rec_status": v3.rec_status(caught, rec_n, need_n),
        "rec_cover_n": rec_n,
        "rec_cover_need": need_n,
        "rec_cover_kind": v3.rec_cover_kind(rec_n, need_n),
    }


def rows_from_period(period: dict, v2, v3) -> tuple[list[dict], dict]:
    episodes = v2.build_episodes(period)
    cache: dict[str, tuple] = {}
    rows = []
    skip = defaultdict(int)
    dataset = period["dataset"]
    for e in episodes:
        reason = e.get("skip")
        if reason:
            skip[reason] += 1
            continue
        if not e.get("immediate") or not e.get("unconstrained"):
            skip["missing_outcome"] += 1
            continue
        sym = e["symbol"]
        if sym not in cache:
            cache[sym] = v2.load_symbol(dataset / "bars" / f"{sym}.json")
        bars, _ = cache[sym]
        past = v2._past(bars, e["anchor"])
        ser = series60(past)
        if ser is None:
            skip["insufficient_history"] += 1
            continue
        unc = e["unconstrained"]
        imm = e["immediate"]
        rec = rec_for(e, period, v3)
        rows.append(
            {
                "symbol": sym,
                "anchor": e["anchor"],
                "end": e.get("end"),
                "price": ser["price"],
                "vol": ser["vol"],
                "feat": ser["feat"],
                "hit15": bool(unc["hit15"]),
                "bars_to_15": unc.get("bars_to_15"),
                "max_dd_window_pct": unc.get("max_dd_window_pct"),
                "max_dd_before_hit_day_pct": unc.get("max_dd_before_hit_day_pct"),
                "net": imm["tradable_net_pct"],
                "win": bool(imm["tradable_win"]),
                "exit_reason": imm.get("exit_reason") or "time",
                "left_censored": bool(e.get("left_censored")),
                "new_entry_status": "unknown",
                **rec,
            }
        )
    return rows, dict(skip)


def band_series(rows: list[dict], key: str) -> dict:
    if not rows:
        return {}
    cols = list(zip(*(r[key] for r in rows)))
    return {
        "p25": [_round(quantile(list(col), 0.25)) for col in cols],
        "p50": [_round(quantile(list(col), 0.5)) for col in cols],
        "p75": [_round(quantile(list(col), 0.75)) for col in cols],
    }


def rec_counts(rows: list[dict]) -> dict:
    status = {"unknown": 0, "not_caught": 0, "caught": 0}
    cover = {"none": 0, "partial": 0, "full": 0}
    for r in rows:
        st = r.get("rec_status")
        if st in status:
            status[st] += 1
        ck = r.get("rec_cover_kind")
        if ck in cover:
            cover[ck] += 1
    return {"n": len(rows), "rec_status_n": status, "rec_cover_kind_n": cover}


def group_metrics(rows: list[dict], base: dict | None = None) -> dict:
    if not rows:
        return {"n": 0, "symbols": 0}
    hits = [r for r in rows if r["hit15"]]
    nets = [r["net"] for r in rows]
    losses = [x for x in nets if x < 0]
    by_sym: dict[str, list] = defaultdict(list)
    months: dict[str, int] = defaultdict(int)
    for r in rows:
        by_sym[r["symbol"]].append(r)
        months[r["anchor"][:7]] += 1
    bars_hit = [r["bars_to_15"] for r in hits if r.get("bars_to_15") is not None]
    dd_all = [r["max_dd_window_pct"] for r in rows if r.get("max_dd_window_pct") is not None]
    dd_hit = [r["max_dd_before_hit_day_pct"] for r in hits if r.get("max_dd_before_hit_day_pct") is not None]
    n = len(rows)
    hit_pct = 100 * len(hits) / n
    out = {
        "n": n,
        "symbols": len(by_sym),
        "hit15_n": len(hits),
        "hit15_pct": _round(hit_pct, 2),
        "fail_n": n - len(hits),
        "fail_pct": _round(100 - hit_pct, 2),
        "mean_net_pct": _round(_mean(nets)),
        "median_net_pct": _round(quantile(nets, 0.5)),
        "win_pct": _round(100 * sum(r["win"] for r in rows) / n, 2),
        "mean_loss_net_pct": _round(_mean(losses)),
        "median_bars_to_15": _round(quantile(bars_hit, 0.5), 2),
        "median_max_dd_window_pct": _round(quantile(dd_all, 0.5)),
        "median_max_dd_before_hit_day_pct": _round(quantile(dd_hit, 0.5)),
        "symbol_equal_hit15_pct": _round(100 * _mean([sum(x["hit15"] for x in v) / len(v) for v in by_sym.values()]), 2),
        "symbol_equal_mean_net_pct": _round(_mean([sum(x["net"] for x in v) / len(v) for v in by_sym.values()])),
        "max_windows_per_symbol": max(len(v) for v in by_sym.values()),
        "busiest_month": max(months, key=months.get) if months else None,
        "busiest_month_share_pct": _round(100 * max(months.values()) / n, 2) if months else None,
        "month_n": len(months),
        "price": band_series(rows, "price"),
        "vol": band_series(rows, "vol"),
    }
    if base and base.get("n"):
        out["vs_all_hit15_pct"] = _round(out["hit15_pct"] - base["hit15_pct"], 2) if out.get("hit15_pct") is not None else None
        out["vs_all_mean_net_pct"] = _round(out["mean_net_pct"] - base["mean_net_pct"]) if out.get("mean_net_pct") is not None else None
        out["vs_all_symbol_equal_mean_net_pct"] = (
            _round(out["symbol_equal_mean_net_pct"] - base["symbol_equal_mean_net_pct"])
            if out.get("symbol_equal_mean_net_pct") is not None and base.get("symbol_equal_mean_net_pct") is not None
            else None
        )
        out["share_of_sample_pct"] = _round(100 * n / base["n"], 2)
        out["share_of_rises_pct"] = _round(100 * len(hits) / base["hit15_n"], 2) if base.get("hit15_n") else None
    return out


def nearest(rows: list[dict], feat: list[float]) -> dict | None:
    if not rows:
        return None
    i = min(range(len(rows)), key=lambda j: sqdist(rows[j]["feat"], feat))
    r = rows[i]
    return {
        "symbol": r["symbol"],
        "anchor": r["anchor"],
        "hit15": r["hit15"],
        "net": _round(r["net"]),
        "path_span": _round(path_span(r)),
        "exit_reason": r.get("exit_reason"),
        "dist": _round(math.sqrt(sqdist(r["feat"], feat))),
    }


def describe_type(m: dict) -> str:
    price = (m.get("price") or {}).get("p50") or []
    vol = (m.get("vol") or {}).get("p50") or []
    if len(price) < N_BARS or len(vol) < N_BARS:
        return "이름 없음"
    bits = []
    early_p, late_p = price[0], price[-1]
    span = max(price) - min(price)
    if early_p <= 0.70:
        bits.append("초반 종가가 T의 70% 이하에서 시작해 올라옴")
    elif early_p >= 1.20:
        bits.append("초반 종가가 T보다 20% 이상 높아 이후 내려옴")
    if span < 0.08:
        bits.append("가격 중앙 경로 진폭이 작다")
    v_early = _mean(vol[:20]) or 0
    v_late = _mean(vol[-10:]) or 0
    if v_early > 0 and v_late >= v_early * 1.4:
        bits.append("후반 10봉 상대거래량이 초반 20봉보다 크다")
    elif v_early > 0 and v_late <= v_early * 0.7:
        bits.append("후반 10봉 상대거래량이 초반보다 작다")
    return " · ".join(bits) if bits else "가격·거래량 중앙 경로에서 뚜렷한 공통 이름을 붙이지 않음"


def _poly(xs, ys) -> str:
    return " ".join(f"{x:.1f},{y:.1f}" for x, y in zip(xs, ys))


def _yscale(v, lo, hi, y0, y1):
    if hi <= lo:
        hi = lo + 1
    t = (v - lo) / (hi - lo)
    return y1 - t * (y1 - y0)


def svg_cluster(typ: int, rows: list[dict], center_feat: list[float], title: str, seed: int) -> str:
    w, h = 760, 560
    rng = random.Random(seed + typ)
    faint_n = min(N_FAINT, len(rows))
    faint = [rows[rng.randrange(len(rows))] for _ in range(faint_n)] if rows else []
    xs = [50 + i * (680 / 59) for i in range(N_BARS)]
    prices = [r["price"] for r in rows] if rows else []
    vols = [r["vol"] for r in rows] if rows else []
    pvals = [v for row in prices for v in row] or [0.5, 1.5]
    vvals = [v for row in vols for v in row] or [0.0, 2.0]
    plo, phi = max(0.2, min(pvals)), min(2.4, max(pvals))
    if phi - plo < 0.2:
        phi = plo + 0.2
    vlo, vhi = 0.0, min(4.0, max(2.0, max(vvals) if vvals else 2.0))
    p25 = [quantile([r["price"][i] for r in rows], 0.25) or 1 for i in range(N_BARS)] if rows else [1] * N_BARS
    p50 = [quantile([r["price"][i] for r in rows], 0.5) or 1 for i in range(N_BARS)] if rows else [1] * N_BARS
    p75 = [quantile([r["price"][i] for r in rows], 0.75) or 1 for i in range(N_BARS)] if rows else [1] * N_BARS
    v25 = [quantile([r["vol"][i] for r in rows], 0.25) or 1 for i in range(N_BARS)] if rows else [1] * N_BARS
    v50 = [quantile([r["vol"][i] for r in rows], 0.5) or 1 for i in range(N_BARS)] if rows else [1] * N_BARS
    v75 = [quantile([r["vol"][i] for r in rows], 0.75) or 1 for i in range(N_BARS)] if rows else [1] * N_BARS

    def py(v):
        return _yscale(v, plo, phi, 40, 270)

    def vy(v):
        return _yscale(min(v, vhi), vlo, vhi, 320, 520)

    band_p = _poly(xs + xs[::-1], [py(v) for v in p25] + [py(v) for v in p75[::-1]])
    band_v = _poly(xs + xs[::-1], [vy(v) for v in v25] + [vy(v) for v in v75[::-1]])
    parts = [
        f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {w} {h}" width="{w}" height="{h}">',
        '<rect width="100%" height="100%" fill="#fff"/>',
        f'<text x="50" y="22" font-size="14" font-family="sans-serif">{title}</text>',
        f'<polygon points="{band_p}" fill="#c5d4e8" opacity="0.85"/>',
        f'<polygon points="{band_v}" fill="#e8d9c5" opacity="0.85"/>',
    ]
    for r in faint:
        parts.append(
            f'<polyline fill="none" stroke="#999" stroke-width="0.8" opacity="0.35" points="{_poly(xs, [py(v) for v in r["price"]])}"/>'
        )
        parts.append(
            f'<polyline fill="none" stroke="#999" stroke-width="0.8" opacity="0.35" points="{_poly(xs, [vy(v) for v in r["vol"]])}"/>'
        )
    parts.append(f'<polyline fill="none" stroke="#1d4f91" stroke-width="2.2" points="{_poly(xs, [py(v) for v in p50])}"/>')
    parts.append(f'<polyline fill="none" stroke="#8a4b12" stroke-width="2.2" points="{_poly(xs, [vy(v) for v in v50])}"/>')
    rise = [r for r in rows if r["hit15"]]
    flat = [r for r in rows if not r["hit15"]]
    near = nearest(rows, center_feat)
    nr = nearest(rise, center_feat)
    nf = nearest(flat, center_feat)

    def add_ex(ex, color, dash=""):
        if not ex:
            return
        r = next((x for x in rows if x["symbol"] == ex["symbol"] and x["anchor"] == ex["anchor"]), None)
        if not r:
            return
        ds = f' stroke-dasharray="{dash}"' if dash else ""
        parts.append(
            f'<polyline fill="none" stroke="{color}" stroke-width="1.6"{ds} points="{_poly(xs, [py(v) for v in r["price"]])}"/>'
        )

    add_ex(near, "#111")
    add_ex(nr, "#2a7a3a", "6 3")
    add_ex(nf, "#a33", "2 3")
    parts += [
        '<text x="50" y="300" font-size="11" font-family="sans-serif">price = close / close_T (band p25-p75, blue median)</text>',
        '<text x="50" y="540" font-size="11" font-family="sans-serif">vol = volume / mean_60 (band p25-p75, brown median). gray=sample paths; black=nearest; green dash=rise; red dash=non-rise</text>',
        '<text x="50" y="286" font-size="10" font-family="sans-serif">-59</text>',
        '<text x="710" y="286" font-size="10" font-family="sans-serif">T</text>',
        "</svg>",
    ]
    return "\n".join(parts) + "\n"


def examples_block(rows: list[dict], center: list[float]) -> dict:
    rise = [r for r in rows if r["hit15"]]
    flat = [r for r in rows if not r["hit15"]]
    return {
        "nearest": nearest(rows, center),
        "nearest_rise": nearest(rise, center),
        "nearest_nonrise": nearest(flat, center),
    }


def period_types(rows: list[dict], labels: list[int], centers: list[list[float]]) -> dict:
    base = group_metrics(rows)
    types = []
    for k in range(K):
        g = [rows[i] for i, lab in enumerate(labels) if lab == k]
        m = group_metrics(g, base)
        m["type"] = k
        m["description"] = describe_type(m)
        m["examples"] = examples_block(g, centers[k])
        types.append(m)
    return {
        "n": len(rows),
        "base": base,
        "types": types,
        "rec": rec_counts(rows),
        "rise_n": base.get("hit15_n", 0),
        "fail_n": base.get("fail_n", 0),
    }


def path_span(r: dict) -> float:
    p = r.get("price") or []
    return (max(p) - min(p)) if p else 0.0


def path_diversity(rows: list[dict]) -> dict:
    spans = [path_span(r) for r in rows]
    n = len(spans)
    if not n:
        return {"n": 0}
    return {
        "n": n,
        "span_p25": _round(quantile(spans, 0.25)),
        "span_p50": _round(quantile(spans, 0.5)),
        "span_p75": _round(quantile(spans, 0.75)),
        "span_ge_015_pct": _round(100 * sum(s >= 0.15 for s in spans) / n, 2),
        "span_ge_030_pct": _round(100 * sum(s >= 0.30 for s in spans) / n, 2),
    }


def monthly_nets(rows: list[dict]) -> list[dict]:
    by: dict[str, list[float]] = defaultdict(list)
    for r in rows:
        by[r["anchor"][:7]].append(r["net"])
    return [{"month": m, "n": len(v), "mean_net_pct": _round(_mean(v))} for m, v in sorted(by.items())]


def split_halves(rows: list[dict], days: list[str] | None) -> dict:
    if days:
        mid = days[len(days) // 2]
    else:
        anchors = sorted(r["anchor"] for r in rows)
        mid = anchors[len(anchors) // 2] if anchors else ""
    first = [r for r in rows if r["anchor"] < mid]
    second = [r for r in rows if r["anchor"] >= mid]
    def blk(xs):
        return {"n": len(xs), "mean_net_pct": _round(_mean([r["net"] for r in xs])), "symbols": len({r["symbol"] for r in xs})}
    return {"split_day": mid, "first": blk(first), "second": blk(second)}


def leave_out_top_symbols(rows: list[dict], n_drop: int) -> dict:
    by: dict[str, float] = defaultdict(float)
    for r in rows:
        by[r["symbol"]] += r["net"]
    ranked = sorted(by, key=lambda s: by[s], reverse=True)
    drop = ranked[:n_drop]
    kept = [r for r in rows if r["symbol"] not in set(drop)]
    return {
        "n_drop": n_drop,
        "dropped": [{"symbol": s, "sum_net_pct": _round(by[s])} for s in drop],
        "n_left": len(kept),
        "symbols_left": len({r["symbol"] for r in kept}),
        "mean_net_pct": _round(_mean([r["net"] for r in kept])),
    }


def exit_contrib(rows: list[dict]) -> dict:
    total = sum(r["net"] for r in rows)
    out = {}
    for reason in ("target", "stop", "time"):
        g = [r for r in rows if r.get("exit_reason") == reason]
        s = sum(r["net"] for r in g)
        out[reason] = {
            "n": len(g),
            "share_n_pct": _round(100 * len(g) / len(rows), 2) if rows else None,
            "mean_net_pct": _round(_mean([r["net"] for r in g])),
            "sum_net_pct": _round(s),
            "share_sum_pct": _round(100 * s / total, 2) if total else None,
        }
    return out


def symbol_bootstrap_type(all_rows: list[dict], typ: int, n: int = N_BOOT, seed: int = SEED) -> dict:
    by: dict[str, list[dict]] = defaultdict(list)
    for r in all_rows:
        by[r["symbol"]].append(r)
    symbols = list(by)
    if len(symbols) < 2:
        return {"n_boot": 0}
    rng = random.Random(seed)
    means, diffs, sm_means, sm_diffs = [], [], [], []
    for _ in range(n):
        draw = [symbols[rng.randrange(len(symbols))] for _ in symbols]
        sel = [w for s in draw for w in by[s]]
        grp = [w for w in sel if w.get("cluster") == typ]
        if not sel or not grp:
            continue
        m_g, m_a = _mean([w["net"] for w in grp]), _mean([w["net"] for w in sel])
        means.append(m_g)
        diffs.append(m_g - m_a)
        all_s, grp_s = [], []
        for s in draw:
            all_s.append(_mean([w["net"] for w in by[s]]))
            kept = [w for w in by[s] if w.get("cluster") == typ]
            if kept:
                grp_s.append(_mean([w["net"] for w in kept]))
        if grp_s:
            sm_means.append(_mean(grp_s))
            sm_diffs.append(_mean(grp_s) - _mean(all_s))
    if not diffs:
        return {"n_boot": 0}
    return {
        "n_boot": len(diffs),
        "seed": seed,
        "trade_mean_p025": _round(quantile(means, 0.025)),
        "trade_mean_p975": _round(quantile(means, 0.975)),
        "trade_mean_minus_all_p025": _round(quantile(diffs, 0.025)),
        "trade_mean_minus_all_p975": _round(quantile(diffs, 0.975)),
        "symbol_mean_p025": _round(quantile(sm_means, 0.025)) if sm_means else None,
        "symbol_mean_p975": _round(quantile(sm_means, 0.975)) if sm_means else None,
        "symbol_mean_minus_all_p025": _round(quantile(sm_diffs, 0.025)) if sm_diffs else None,
        "symbol_mean_minus_all_p975": _round(quantile(sm_diffs, 0.975)) if sm_diffs else None,
    }


def type_followup(all_rows: list[dict], typ: int, days: list[str], base: dict) -> dict:
    g = [r for r in all_rows if r.get("cluster") == typ]
    m = group_metrics(g, base)
    return {
        "type": typ,
        "n": len(g),
        "symbols": m.get("symbols"),
        "trade_mean_net_pct": m.get("mean_net_pct"),
        "symbol_equal_mean_net_pct": m.get("symbol_equal_mean_net_pct"),
        "vs_all_mean_net_pct": m.get("vs_all_mean_net_pct"),
        "vs_all_symbol_equal_mean_net_pct": m.get("vs_all_symbol_equal_mean_net_pct"),
        "hit15_pct": m.get("hit15_pct"),
        "monthly": monthly_nets(g),
        "halves": split_halves(g, days),
        "leave_out_top1": leave_out_top_symbols(g, 1),
        "leave_out_top5": leave_out_top_symbols(g, 5),
        "exits": exit_contrib(g),
        "path_diversity": path_diversity(g),
        "bootstrap": symbol_bootstrap_type(all_rows, typ),
        "examples": None,
    }


def pnl_ok_type(block_t: dict, block_base: dict) -> bool:
    g, b = block_t, block_base
    if g.get("mean_net_pct") is None or b.get("mean_net_pct") is None:
        return False
    return g["mean_net_pct"] > 0 and g["mean_net_pct"] > b["mean_net_pct"]


def judge(prior: dict, recent: dict) -> dict:
    hit, pnl, both = [], [], []
    for k in range(K):
        p = prior["types"][k]
        r = recent["types"][k]
        pd, rd = p.get("vs_all_hit15_pct"), r.get("vs_all_hit15_pct")
        hit_ok = pd is not None and rd is not None and pd > 0 and rd > 0
        net_ok = pnl_ok_type(p, prior["base"]) and pnl_ok_type(r, recent["base"])
        if hit_ok:
            hit.append(k)
        if net_ok:
            pnl.append(k)
        if hit_ok and net_ok:
            both.append(k)
    # 기존 판정: 도달률 우위 유형만 수익성 게이트에 넣음 = both
    enrich = hit
    trade_ok = both
    if trade_ok:
        label = "후속 진입 검증 후보"
        note = "일부 유형이 두 기간 모두 상승 도달률과 비용 반영 평균에서 전체보다 낫다. 운영 채택이 아니며 조건·손절을 더하지 않는다."
    elif enrich:
        label = "관찰 가치"
        note = (
            f"기존 판정은 도달률 우위 유형만 수익성을 보므로 유지한다. 도달률 우위 {hit}, "
            f"순손익 우위(평균>0 이고 전체보다 큼) {pnl}, 둘 다 {both}. "
            "도달률이 높은 유형은 실패 겹침이 남아 있다. 조건·손절을 더하지 않는다."
        )
    else:
        label = "근거 부족"
        note = "같은 6중심이 두 기간에 쓰여도 상승 사례에서 반복적으로 더 자주 나타난다고 보기 어렵다. 조건·손절을 더하지 않는다."
    return {
        "label": label,
        "note": note,
        "types_hit_above_all_both_periods": hit,
        "types_pnl_above_all_both_periods": pnl,
        "types_hit_and_pnl_both_periods": both,
        "types_trade_ok_both_periods": trade_ok,
        "original_pnl_gate_required_hit": True,
        "followup": "결과 확인 후 유형 1 순손익 지속성만 검사. 군집·진입·손절·목표는 고정.",
        "adoption": "안 함",
    }


def fmt(x):
    return "—" if x is None else x


def render_report(rep: dict) -> str:
    j = rep["judgment"]
    lines = [
        "# 상승 전 60봉 흐름 6유형 v4",
        "",
        "탐색 연구다. PR #103·#104·#105 결과는 유지한다. 손절 5%·목표 15%·T+20을 바꾸지 않았다. 운영·원본·계좌는 바꾸지 않았다. 두 기간은 이미 썼으므로 독립 검증이 아니다.",
        "",
        "이번 유형 1 지속성 확인은 초안 결과를 본 뒤의 후속 탐색이다. 군집·진입·손절·목표는 그대로다.",
        "",
        f"판단: **{j['label']}**. {j['note']}",
        "",
        "## 고정 방법 (결과 보기 전)",
        "",
        "| 항목 | 값 |",
        "|---|---|",
        f"| version | {rep['version']} |",
        f"| K | {K} |",
        "| 정규화 | 표본마다 close/close_T, volume/mean_60, 각 L2=1 후 1:1 연결 |",
        "| 거리 | 120차원 유클리드 |",
        f"| 군집 | k-means++, seed {SEED}, max_iter {MAX_ITER}, n_init 1 |",
        "| 최근 기간 | 이전 기간 중심만 배정. 재학습 없음 |",
        f"| 가격 기준 | {rep['price_basis']} |",
        f"| 스크립트 sha256 | `{rep['hashes']['script']}` |",
        f"| 프로토콜 sha256 | `{rep['hashes']['protocol']}` |",
        f"| 이전 candidates | `{rep['hashes']['prior_candidates']}` |",
        f"| 최근 candidates | `{rep['hashes']['recent_candidates']}` |",
        "",
        "## 이전 기간에서 묶은 6유형",
        "",
    ]
    p = rep["periods"]["prior"]
    r = rep["periods"]["recent"]
    for t in p["types"]:
        k = t["type"]
        ex = t.get("examples") or {}
        lines += [
            f"### 유형 {k}",
            "",
            f"{t.get('description')}",
            "",
            f"![prior type {k}](charts/prior-{k}.svg)",
            "",
            f"대표 최근접: {fmt((ex.get('nearest') or {}).get('symbol'))} @ {fmt((ex.get('nearest') or {}).get('anchor'))}. "
            f"상승 최근접: {fmt((ex.get('nearest_rise') or {}).get('symbol'))} @ {fmt((ex.get('nearest_rise') or {}).get('anchor'))}. "
            f"비상승 최근접: {fmt((ex.get('nearest_nonrise') or {}).get('symbol'))} @ {fmt((ex.get('nearest_nonrise') or {}).get('anchor'))}.",
            "",
        ]
    lines += [
        "## 기간별 유형 성과 (차트 상승 = unconstrained.hit15, 순손익은 보조)",
        "",
        "숫자: n / 종목 / 표본점유% / 상승% / 실패% / 전체 대비 도달Δ / 상승 점유% / 중앙 도달봉 / 창 최대하락% 중앙 / 보조 평균순익% / 보조 평균Δ / 가장 바쁜 달 비중%.",
        "",
        "| 기간 | 유형 | n | 종목 | 표본% | 상승% | 실패% | 도달Δ | 상승점유% | 도달봉 | 최대하락% | 평균순익% | 순익Δ | 바쁜달% |",
        "|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|",
    ]
    for key in ("prior", "recent"):
        blk = rep["periods"][key]
        b = blk["base"]
        lines.append(
            f"| {blk['label']} | 전체 | {b.get('n', 0)} | {b.get('symbols', 0)} | 100 | {b.get('hit15_pct')} | {b.get('fail_pct')} | "
            f"— | — | {b.get('median_bars_to_15')} | {b.get('median_max_dd_window_pct')} | {b.get('mean_net_pct')} | — | {b.get('busiest_month_share_pct')} |"
        )
        for t in blk["types"]:
            lines.append(
                f"| {blk['label']} | {t['type']} | {t.get('n', 0)} | {t.get('symbols', 0)} | {fmt(t.get('share_of_sample_pct'))} | {t.get('hit15_pct')} | {t.get('fail_pct')} | "
                f"{fmt(t.get('vs_all_hit15_pct'))} | {fmt(t.get('share_of_rises_pct'))} | {fmt(t.get('median_bars_to_15'))} | "
                f"{fmt(t.get('median_max_dd_window_pct'))} | {fmt(t.get('mean_net_pct'))} | {fmt(t.get('vs_all_mean_net_pct'))} | {fmt(t.get('busiest_month_share_pct'))} |"
            )
    lines += [
        "",
        "최근 기간은 이전 기간 중심에 배정만 했다. 같은 종목의 여러 구간이 있으므로 n으로 독립을 주장하지 않는다. 거래 순손익은 보조다. 유형 2·3·4는 설명 문구가 같아도 중심은 다르며, 이름을 억지로 나누지 않았다.",
        "",
        "## 도달률 우위와 순손익 우위 (분리)",
        "",
        "기존 판정은 도달률 우위 유형만 수익성 게이트에 넣었다. 아래는 6유형 전체를 따로 본 결과다.",
        "",
        f"| 구분 | 유형 |",
        f"|---|---|",
        f"| 도달률 우위 (두 기간 모두 전체보다 높음) | {j.get('types_hit_above_all_both_periods')} |",
        f"| 순손익 우위 (두 기간 모두 평균>0 이고 전체보다 큼) | {j.get('types_pnl_above_all_both_periods')} |",
        f"| 둘 다 | {j.get('types_hit_and_pnl_both_periods')} |",
        "",
    ]
    t1s = rep.get("type1_followup") or {}
    lines += [
        "## 유형 1 순손익 지속성 (후속 탐색, 분류 고정)",
        "",
        "초안 summary에서 유형 1만 두 기간 비용 반영 평균이 양수이자 전체보다 컸다. 중심·배정·진입·손절 5%·목표 15%는 바꾸지 않았다.",
        "",
    ]
    for key in ("prior", "recent"):
        blk = t1s.get(key) or {}
        boot = blk.get("bootstrap") or {}
        h = blk.get("halves") or {}
        e1 = blk.get("leave_out_top1") or {}
        e5 = blk.get("leave_out_top5") or {}
        div = blk.get("path_diversity") or {}
        ex = blk.get("exits") or {}
        lab = rep["periods"][key]["label"]
        lines += [
            f"### {lab}",
            "",
            f"거래 평균 {fmt(blk.get('trade_mean_net_pct'))}% (전체 대비 {fmt(blk.get('vs_all_mean_net_pct'))}), "
            f"종목 평균 {fmt(blk.get('symbol_equal_mean_net_pct'))}% (전체 대비 {fmt(blk.get('vs_all_symbol_equal_mean_net_pct'))}). "
            f"n={blk.get('n')} 종목 {blk.get('symbols')}.",
            "",
            f"전반(< {h.get('split_day')}) n={ (h.get('first') or {}).get('n') } 평균 {fmt((h.get('first') or {}).get('mean_net_pct'))}%. "
            f"후반 n={ (h.get('second') or {}).get('n') } 평균 {fmt((h.get('second') or {}).get('mean_net_pct'))}%.",
            "",
            f"상위 1종목 제외 평균 {fmt(e1.get('mean_net_pct'))}% (제외 {fmt(((e1.get('dropped') or [{}])[0] or {}).get('symbol'))}). "
            f"상위 5종목 제외 평균 {fmt(e5.get('mean_net_pct'))}%.",
            "",
            f"종목 재표집 1000회 거래평균 {fmt(boot.get('trade_mean_p025'))}~{fmt(boot.get('trade_mean_p975'))}, "
            f"전체 대비 차이 {fmt(boot.get('trade_mean_minus_all_p025'))}~{fmt(boot.get('trade_mean_minus_all_p975'))}. "
            f"종목평균 차이 {fmt(boot.get('symbol_mean_minus_all_p025'))}~{fmt(boot.get('symbol_mean_minus_all_p975'))}.",
            "",
            "| 청산 | n | 건수점유% | 평균% | 합계 기여% |",
            "|---|---:|---:|---:|---:|",
        ]
        for reason, title in (("target", "목표"), ("stop", "손절"), ("time", "기간종료")):
            er = ex.get(reason) or {}
            lines.append(f"| {title} | {er.get('n', 0)} | {fmt(er.get('share_n_pct'))} | {fmt(er.get('mean_net_pct'))} | {fmt(er.get('share_sum_pct'))} |")
        lines += [
            "",
            "| 월 | n | 평균% |",
            "|---|---:|---:|",
        ]
        for row in blk.get("monthly") or []:
            lines.append(f"| {row.get('month')} | {row.get('n')} | {fmt(row.get('mean_net_pct'))} |")
        lines += [
            "",
            f"개별 경로 진폭(max−min of close/close_T) 중앙 {fmt(div.get('span_p50'))}, "
            f"p25–p75 {fmt(div.get('span_p25'))}–{fmt(div.get('span_p75'))}. "
            f"진폭 ≥0.15인 구간 {fmt(div.get('span_ge_015_pct'))}%, ≥0.30인 구간 {fmt(div.get('span_ge_030_pct'))}%. "
            "중앙 경로가 평평해도 개별 차트를 모두 저변동·횡보로 단정하지 않는다.",
            "",
        ]
    pex = ((rep["periods"]["prior"]["types"][TYPE1].get("examples") or {}) if rep["periods"]["prior"].get("types") else {}) or {}
    lines += [
        "### 유형 1 대표·성공·실패 경로",
        "",
        "아래는 이전 기간 유형 1의 중심 최근접, 상승 최근접, 비상승 최근접이다. 중앙선이 아니라 개별 60봉이다.",
        "",
        "![type1 nearest](charts/prior-1-nearest.svg)",
        "",
        "![type1 rise](charts/prior-1-rise.svg)",
        "",
        "![type1 nonrise](charts/prior-1-nonrise.svg)",
        "",
        f"최근접 {fmt((pex.get('nearest') or {}).get('symbol'))} @ {fmt((pex.get('nearest') or {}).get('anchor'))} "
        f"hit15={fmt((pex.get('nearest') or {}).get('hit15'))} 진폭 {fmt((pex.get('nearest') or {}).get('path_span'))} "
        f"청산 {fmt((pex.get('nearest') or {}).get('exit_reason'))} 순익 {fmt((pex.get('nearest') or {}).get('net'))}%. "
        f"상승 최근접 {fmt((pex.get('nearest_rise') or {}).get('symbol'))} @ {fmt((pex.get('nearest_rise') or {}).get('anchor'))} "
        f"진폭 {fmt((pex.get('nearest_rise') or {}).get('path_span'))} 순익 {fmt((pex.get('nearest_rise') or {}).get('net'))}%. "
        f"비상승 최근접 {fmt((pex.get('nearest_nonrise') or {}).get('symbol'))} @ {fmt((pex.get('nearest_nonrise') or {}).get('anchor'))} "
        f"진폭 {fmt((pex.get('nearest_nonrise') or {}).get('path_span'))} 순익 {fmt((pex.get('nearest_nonrise') or {}).get('net'))}%.",
        "",
    ]
    lines += [
        "",
        "### 최근 기간 배정 차트",
        "",
    ]
    for t in r["types"]:
        lines.append(f"![recent type {t['type']}](charts/recent-{t['type']}.svg)")
        lines.append("")
    pr = p.get("rec") or {}
    rr = r.get("rec") or {}
    lines += [
        "## 추천 커버 (손익과 분리)",
        "",
        f"이전 unknown/not_caught/caught = {pr.get('rec_status_n', {}).get('unknown', 0)}/{pr.get('rec_status_n', {}).get('not_caught', 0)}/{pr.get('rec_status_n', {}).get('caught', 0)}. "
        f"최근 = {rr.get('rec_status_n', {}).get('unknown', 0)}/{rr.get('rec_status_n', {}).get('not_caught', 0)}/{rr.get('rec_status_n', {}).get('caught', 0)}. "
        "부분 커버 미발견은 unknown이다.",
        "",
        "## 결론",
        "",
        f"**{j['label']}.** {j['note']}",
        "",
        "| 질문 | 내용 |",
        "|---|---|",
        f"| 반복 유형 | 중심은 이전 기간에서만 학습하고 최근에 배정. 두 기간 모두 전체보다 도달률이 높은 유형: {j.get('types_hit_above_all_both_periods')} |",
        "| 상승에서 더 자주? | 표의 상승점유%와 표본 점유(n/전체)를 비교 |",
        "| 실패 겹침 | 실패%가 남아 있으면 같은 모양이 비상승에도 많음 |",
        f"| 순손익 우위 | {j.get('types_pnl_above_all_both_periods')} (도달률 게이트 없이 별도) |",
        f"| 둘 다 | {j.get('types_hit_and_pnl_both_periods')} |",
        f"| 채택 | {j.get('adoption')} |",
        "| 구제 | 조건·손절 추가 없음 |",
        "| 후속 | 결과 확인 후 유형 1만 고정 분류로 지속성 검사. 초안은 report-before-rev1.md |",
        f"| 재현 | `python docs/research/top100_pre_rise_shapes.py --output <dir> --report docs/top100-pre-rise-v4/report.md` |",
        "",
        "결과 JSON은 Git 밖 `history-research/top100-pre-rise-v4/summary.json`, 중심은 `centers.json`.",
        "",
    ]
    return "\n".join(lines) + "\n"


def tag_cluster(rows: list[dict], labels: list[int]) -> None:
    for r, lab in zip(rows, labels):
        r["cluster"] = lab


def write_type1_examples(rows: list[dict], labels: list[int], center: list[float], examples: dict, out_dirs: list[Path]):
    g = [r for r, lab in zip(rows, labels) if lab == TYPE1]
    mapping = (
        ("nearest", "prior-1-nearest.svg"),
        ("nearest_rise", "prior-1-rise.svg"),
        ("nearest_nonrise", "prior-1-nonrise.svg"),
    )
    for key, name in mapping:
        ex = examples.get(key) or {}
        one = [r for r in g if r["symbol"] == ex.get("symbol") and r["anchor"] == ex.get("anchor")]
        title = f"type1 {key} {ex.get('symbol')} {ex.get('anchor')} span={ex.get('path_span')}"
        svg = svg_cluster(TYPE1, one, center, title, SEED)
        for d in out_dirs:
            (d / name).write_text(svg, encoding="utf-8")


def write_charts(period_key: str, rows: list[dict], labels: list[int], centers: list, out_dirs: list[Path], label: str):
    by = defaultdict(list)
    for r, lab in zip(rows, labels):
        by[lab].append(r)
    for d in out_dirs:
        d.mkdir(parents=True, exist_ok=True)
    for k in range(K):
        g = by.get(k, [])
        title = f"{label} type {k} n={len(g)}"
        svg = svg_cluster(k, g, centers[k], title, SEED)
        name = f"{period_key}-{k}.svg"
        for d in out_dirs:
            (d / name).write_text(svg, encoding="utf-8")


def main(argv=None) -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--output", required=True)
    ap.add_argument("--report")
    args = ap.parse_args(argv)
    v2 = load_mod("top100_pre_rise_episode", "docs/research/top100_pre_rise_episode.py")
    v3 = load_mod("top100_pre_rise_tertiles", "docs/research/top100_pre_rise_tertiles.py")
    out = Path(args.output)
    out.mkdir(parents=True, exist_ok=True)
    prior_p = v2.load_period("prior")
    recent_p = v2.load_period("recent")
    prior_rows, prior_skip = rows_from_period(prior_p, v2, v3)
    X = [r["feat"] for r in prior_rows]
    fit = kmeans(X, K, SEED, MAX_ITER)
    prior_labels = fit["labels"]
    centers = fit["centers"]
    (out / "centers.json").write_text(
        json.dumps({"k": K, "seed": SEED, "n_iter": fit["n_iter"], "inertia": fit["inertia"], "centers": centers}, indent=2)
        + "\n",
        encoding="utf-8",
    )
    recent_rows, recent_skip = rows_from_period(recent_p, v2, v3)
    recent_labels = assign([r["feat"] for r in recent_rows], centers)
    tag_cluster(prior_rows, prior_labels)
    tag_cluster(recent_rows, recent_labels)
    prior_block = period_types(prior_rows, prior_labels, centers)
    recent_block = period_types(recent_rows, recent_labels, centers)
    prior_block["label"] = prior_p["label"]
    recent_block["label"] = recent_p["label"]
    prior_block["skip"] = prior_skip
    recent_block["skip"] = recent_skip
    t1_follow = {
        "prior": type_followup(prior_rows, TYPE1, prior_p["days"], prior_block["base"]),
        "recent": type_followup(recent_rows, TYPE1, recent_p["days"], recent_block["base"]),
    }
    t1_follow["prior"]["examples"] = prior_block["types"][TYPE1].get("examples")
    chart_dirs = [out / "charts", DOC_CHARTS]
    write_charts("prior", prior_rows, prior_labels, centers, chart_dirs, prior_p["label"])
    write_charts("recent", recent_rows, recent_labels, centers, chart_dirs, recent_p["label"])
    write_type1_examples(prior_rows, prior_labels, centers[TYPE1], prior_block["types"][TYPE1].get("examples") or {}, chart_dirs)
    report = {
        "version": VERSION,
        "price_basis": v2.PRICE_BASIS,
        "k": K,
        "seed": SEED,
        "max_iter": MAX_ITER,
        "feature": "l2(price/close_T) concat l2(vol/mean_60)",
        "window_name": "nonoverlapping_top100_observation_window",
        "new_entry_status": "unknown",
        "kmeans": {"n_iter": fit["n_iter"], "inertia": _round(fit["inertia"], 2), "prior_n": len(prior_rows)},
        "hashes": {
            "script": sha256(Path(__file__)),
            "protocol": sha256(ROOT / "docs/top100-pre-rise-v4/protocol.md"),
            "v2_script": sha256(ROOT / "docs/research/top100_pre_rise_episode.py"),
            "prior_candidates": prior_p["candidates_sha256"],
            "recent_candidates": recent_p["candidates_sha256"],
            "prior_signals": prior_p["signals_sha256"],
            "recent_signals": recent_p["signals_sha256"],
        },
        "periods": {"prior": prior_block, "recent": recent_block},
        "type1_followup": t1_follow,
        "limitations": [
            "Exploratory; already-used periods, not independent validation",
            "Yahoo daily bars, adjustment unverified",
            "K and weights frozen; not tuned on outcomes",
            "Type-1 persistence check is post-hoc after seeing draft results",
            "Repeated windows per symbol; trade count is not independent n",
        ],
    }
    report["judgment"] = judge(prior_block, recent_block)
    slim = json.loads(json.dumps(report))
    for key in ("prior", "recent"):
        for t in slim["periods"][key]["types"]:
            t.pop("price", None)
            t.pop("vol", None)
    (out / "summary.json").write_text(json.dumps(slim, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    md = render_report(report)
    (out / "report.md").write_text(md, encoding="utf-8")
    if args.report:
        Path(args.report).write_text(md, encoding="utf-8")
    print(
        json.dumps(
            {
                "judgment": report["judgment"],
                "kmeans": report["kmeans"],
                "prior_n": prior_block["n"],
                "recent_n": recent_block["n"],
                "prior_types": [{"type": t["type"], "n": t["n"], "hit15_pct": t["hit15_pct"], "mean_net_pct": t.get("mean_net_pct"), "description": t["description"]} for t in prior_block["types"]],
                "recent_types": [{"type": t["type"], "n": t["n"], "hit15_pct": t["hit15_pct"], "mean_net_pct": t.get("mean_net_pct")} for t in recent_block["types"]],
                "type1_followup": {
                    "prior": {k: t1_follow["prior"][k] for k in ("n", "trade_mean_net_pct", "symbol_equal_mean_net_pct", "vs_all_mean_net_pct", "leave_out_top1", "leave_out_top5", "halves", "exits", "path_diversity", "bootstrap") if k in t1_follow["prior"]},
                    "recent": {k: t1_follow["recent"][k] for k in ("n", "trade_mean_net_pct", "symbol_equal_mean_net_pct", "vs_all_mean_net_pct", "leave_out_top1", "leave_out_top5", "halves", "exits", "path_diversity", "bootstrap") if k in t1_follow["recent"]},
                },
            },
            ensure_ascii=False,
            indent=2,
        )
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
