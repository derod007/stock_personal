"""Tertile screen on 60-bar return and volume ratio. Does not retune stops or search extra combos."""
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
VERSION = "top100_pre_rise_v3_rev1"
SEED = 1042018
N_BOOT = 1000
FEATURES = ("close_change_60", "vol_ratio_10_50")
COMBO = "close_change_60_top_and_vol_ratio_10_50_top"


def load_v2():
    spec = importlib.util.spec_from_file_location(
        "top100_pre_rise_episode", ROOT / "docs/research/top100_pre_rise_episode.py"
    )
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


def tertile_cuts(xs: list[float]) -> dict:
    return {
        "p33": quantile(xs, 1 / 3),
        "p67": quantile(xs, 2 / 3),
        "n": len(xs),
    }


def band(x: float, cuts: dict) -> int:
    if x < cuts["p33"]:
        return 1
    if x < cuts["p67"]:
        return 2
    return 3


def _mean(xs: list[float]) -> float | None:
    return None if not xs else sum(xs) / len(xs)


def _round(x, n=4):
    return None if x is None else round(x, n)


def dist(xs: list[float]) -> dict:
    if not xs:
        return {"n": 0}
    return {
        "n": len(xs),
        "p25": _round(quantile(xs, 0.25)),
        "median": _round(quantile(xs, 0.5)),
        "p75": _round(quantile(xs, 0.75)),
    }


def overlap(rise: list[float], flat: list[float]) -> dict:
    if not rise or not flat:
        return {}
    rmed = quantile(rise, 0.5)
    fmed = quantile(flat, 0.5)
    rp25, rp75 = quantile(rise, 0.25), quantile(rise, 0.75)
    fp25, fp75 = quantile(flat, 0.25), quantile(flat, 0.75)
    iqr_ov = max(0.0, min(rp75, fp75) - max(rp25, fp25))
    return {
        "rise_median": _round(rmed),
        "nonrise_median": _round(fmed),
        "rise_at_or_below_nonrise_median_pct": _round(100 * sum(x <= fmed for x in rise) / len(rise), 2),
        "nonrise_at_or_above_rise_median_pct": _round(100 * sum(x >= rmed for x in flat) / len(flat), 2),
        "iqr_overlap": _round(iqr_ov),
        "iqr_overlap_over_rise_iqr": _round(iqr_ov / (rp75 - rp25), 4) if rp75 > rp25 else None,
    }


def window_days(period_days: list[str], anchor: str | None, end: str | None) -> list[str]:
    if not period_days or not anchor or not end:
        return []
    try:
        i = period_days.index(anchor)
        j = period_days.index(end)
    except ValueError:
        return []
    if j < i:
        return []
    return period_days[i : j + 1]


def rec_cover_kind(rec_n: int, need_n: int) -> str:
    if need_n <= 0 or rec_n <= 0:
        return "none"
    if rec_n < need_n:
        return "partial"
    return "full"


def rec_status(caught: bool, rec_n: int, need_n: int) -> str:
    if caught:
        return "caught"
    if need_n > 0 and rec_n == need_n:
        return "not_caught"
    return "unknown"


def rec_cover_for_episode(e: dict, period: dict | None) -> tuple[int, int]:
    if period is None:
        return 0, 0
    span = window_days(period.get("days") or [], e.get("anchor"), e.get("end"))
    covered = period.get("covered") or set()
    rec_n = sum(1 for d in span if d in covered)
    return rec_n, len(span)


def valid_row(e: dict, period: dict | None = None) -> dict | None:
    sh = e.get("shape")
    imm = e.get("immediate")
    unc = e.get("unconstrained")
    if not sh or not imm or not unc:
        return None
    cc = sh.get("close_change_60")
    vr = sh.get("vol_ratio_10_50")
    if cc is None or vr is None:
        return None
    if not math.isfinite(cc) or not math.isfinite(vr):
        return None
    rec_n, need_n = rec_cover_for_episode(e, period)
    caught = bool(e.get("caught_in_window"))
    return {
        "symbol": e["symbol"],
        "anchor": e["anchor"],
        "close_change_60": cc,
        "vol_ratio_10_50": vr,
        "hit15": bool(unc["hit15"]),
        "net": imm["tradable_net_pct"],
        "win": bool(imm["tradable_win"]),
        "rec_status": rec_status(caught, rec_n, need_n),
        "rec_cover_n": rec_n,
        "rec_cover_need": need_n,
        "rec_cover_kind": rec_cover_kind(rec_n, need_n),
        "new_entry_status": "unknown",
        "left_censored": bool(e.get("left_censored")),
    }


def rows_from_episodes(episodes: list[dict], period: dict | None = None) -> list[dict]:
    out = []
    for e in episodes:
        r = valid_row(e, period)
        if r:
            out.append(r)
    return out


def rec_day_meta(period: dict) -> dict:
    days = period.get("days") or []
    covered = period.get("covered") or set()
    miss = [d for d in days if d not in covered]
    return {
        "period_n": len(days),
        "covered_n": sum(1 for d in days if d in covered),
        "uncovered_n": len(miss),
        "uncovered_first": miss[0] if miss else None,
        "uncovered_last": miss[-1] if miss else None,
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


def group_metrics(rows: list[dict]) -> dict:
    if not rows:
        return {"n": 0, "symbols": 0}
    nets = [r["net"] for r in rows]
    losses = [x for x in nets if x < 0]
    hits = sum(r["hit15"] for r in rows)
    wins = sum(r["win"] for r in rows)
    by_sym: dict[str, list[dict]] = defaultdict(list)
    for r in rows:
        by_sym[r["symbol"]].append(r)
    sym_hit = [sum(x["hit15"] for x in v) / len(v) for v in by_sym.values()]
    sym_net = [sum(x["net"] for x in v) / len(v) for v in by_sym.values()]
    return {
        "n": len(rows),
        "symbols": len(by_sym),
        "hit15_n": hits,
        "hit15_pct": _round(100 * hits / len(rows), 2),
        "mean_net_pct": _round(_mean(nets)),
        "median_net_pct": _round(quantile(nets, 0.5)),
        "win_pct": _round(100 * wins / len(rows), 2),
        "mean_loss_net_pct": _round(_mean(losses)),
        "loss_n": len(losses),
        "symbol_equal_hit15_pct": _round(100 * _mean(sym_hit), 2),
        "symbol_equal_mean_net_pct": _round(_mean(sym_net)),
    }


def delta(group: dict, base: dict) -> dict:
    if not group.get("n") or not base.get("n"):
        return {}
    out = {}
    for k in ("hit15_pct", "mean_net_pct", "median_net_pct", "win_pct", "symbol_equal_mean_net_pct"):
        if group.get(k) is None or base.get(k) is None:
            out[k] = None
        else:
            out[k] = _round(group[k] - base[k])
    return out


def symbol_bootstrap_diff(rows: list[dict], keep, n: int = N_BOOT, seed: int = SEED) -> dict:
    by: dict[str, list[dict]] = defaultdict(list)
    for r in rows:
        by[r["symbol"]].append(r)
    symbols = list(by)
    if len(symbols) < 2:
        return {"n_boot": 0}
    rng = random.Random(seed)
    diffs_trade = []
    diffs_sym = []
    for _ in range(n):
        draw = [symbols[rng.randrange(len(symbols))] for _ in symbols]
        sel = [w for s in draw for w in by[s]]
        grp = [w for w in sel if keep(w)]
        if not sel or not grp:
            continue
        diffs_trade.append(_mean([w["net"] for w in grp]) - _mean([w["net"] for w in sel]))
        all_s = []
        grp_s = []
        for s in draw:
            all_s.append(_mean([w["net"] for w in by[s]]))
            kept = [w for w in by[s] if keep(w)]
            if kept:
                grp_s.append(_mean([w["net"] for w in kept]))
        if grp_s:
            diffs_sym.append(_mean(grp_s) - _mean(all_s))
    if not diffs_trade:
        return {"n_boot": 0}
    return {
        "n_boot": len(diffs_trade),
        "seed": seed,
        "trade_mean_minus_all_p025": _round(quantile(diffs_trade, 0.025)),
        "trade_mean_minus_all_p975": _round(quantile(diffs_trade, 0.975)),
        "symbol_mean_minus_all_p025": _round(quantile(diffs_sym, 0.025)) if diffs_sym else None,
        "symbol_mean_minus_all_p975": _round(quantile(diffs_sym, 0.975)) if diffs_sym else None,
    }


def apply_cuts(rows: list[dict], cuts: dict) -> list[dict]:
    out = []
    for r in rows:
        item = dict(r)
        item["band_close_change_60"] = band(r["close_change_60"], cuts["close_change_60"])
        item["band_vol_ratio_10_50"] = band(r["vol_ratio_10_50"], cuts["vol_ratio_10_50"])
        item["combo_top"] = item["band_close_change_60"] == 3 and item["band_vol_ratio_10_50"] == 3
        out.append(item)
    return out


def period_block(rows: list[dict], cuts: dict) -> dict:
    labeled = apply_cuts(rows, cuts)
    base = group_metrics(labeled)
    rise = [r["close_change_60"] for r in labeled if r["hit15"]]
    flat = [r["close_change_60"] for r in labeled if not r["hit15"]]
    vr = [r["vol_ratio_10_50"] for r in labeled if r["hit15"]]
    vf = [r["vol_ratio_10_50"] for r in labeled if not r["hit15"]]
    bands = {}
    for feat in FEATURES:
        key = f"band_{feat}"
        bands[feat] = {}
        for b in (1, 2, 3):
            g = [r for r in labeled if r[key] == b]
            m = group_metrics(g)
            m["vs_all"] = delta(m, base)
            m["bootstrap_mean_net_minus_all"] = symbol_bootstrap_diff(labeled, lambda r, k=key, bb=b: r[k] == bb)
            bands[feat][str(b)] = m
    combo_rows = [r for r in labeled if r["combo_top"]]
    combo = group_metrics(combo_rows)
    combo["vs_all"] = delta(combo, base)
    combo["bootstrap_mean_net_minus_all"] = symbol_bootstrap_diff(labeled, lambda r: r["combo_top"])
    return {
        "valid_n": len(labeled),
        "base": base,
        "rec": {
            "all": rec_counts(labeled),
            "combo_top": rec_counts(combo_rows),
        },
        "dist_close_change_60": {
            "rise": dist(rise),
            "nonrise": dist(flat),
            "overlap": overlap(rise, flat),
        },
        "dist_vol_ratio_10_50": {
            "rise": dist(vr),
            "nonrise": dist(vf),
            "overlap": overlap(vr, vf),
        },
        "bands": bands,
        "combo_top": combo,
    }


def judge(prior: dict, recent: dict) -> dict:
    def mean_ok(block):
        g = block["combo_top"]
        b = block["base"]
        if not g.get("n") or g.get("mean_net_pct") is None or b.get("mean_net_pct") is None:
            return False
        return g["mean_net_pct"] > 0 and g["mean_net_pct"] > b["mean_net_pct"]

    def hit_ok(block):
        g = block["combo_top"]
        b = block["base"]
        return g.get("n") and g.get("hit15_pct") is not None and b.get("hit15_pct") is not None and g["hit15_pct"] > b["hit15_pct"]

    both_mean = mean_ok(prior) and mean_ok(recent)
    both_hit = hit_ok(prior) and hit_ok(recent)
    if both_mean:
        observation = "유리함"
        note = "고정 조합이 두 기간 모두 비용 반영 평균이 양수이고 전체 유효 표본보다 크다. 탐색 관찰이며 운영 채택이 아니다."
    elif both_hit:
        observation = "구별력만 있음"
        note = "두 기간 모두 +15% 도달률은 전체보다 높으나, 비용 반영 평균이 양수이면서 전체보다 나은 조건은 동시에 채우지 못했다."
    else:
        observation = "근거 부족"
        note = "고정 조합이 두 기간에서 도달률·순손익을 함께 개선하지 못했다."
    return {
        "observation": observation,
        "label": "채택 근거 부족" if not both_mean else "유리함",
        "adoption": "안 함",
        "exploration": "종료",
        "note": note + " 현재 거래 모형에서 이 조합 탐색을 종료한다. 하위 거래량 조합·손절 변경은 하지 않는다.",
        "combo_mean_ok_both_periods": both_mean,
        "combo_hit_ok_both_periods": both_hit,
    }


def render_report(rep: dict) -> str:
    cuts = rep["cuts"]
    j = rep["judgment"]
    lines = [
        "# 60봉 상승률·거래량 비 3분위 탐색 v3",
        "",
        "탐색 연구다. PR #103·#104 결과는 유지한다. 손절 5%·목표 15%·T+20 종료를 바꾸지 않았다. 운영·원본·계좌는 바꾸지 않았다. 두 기간은 이미 썼으므로 독립 검증이 아니다.",
        "",
        f"판단: **{j['label']}** (관찰 {j.get('observation')}). {j['note']}",
        "",
        "## 구간 경계와 분석 기준",
        "",
        "경계는 이전 기간 유효 표본의 특징 값만으로, 결과 라벨 없이 1/3·2/3 분위로 정했다. 최근 기간에 같은 숫자를 적용했다.",
        "",
        "| 특징 | n(이전 유효) | 1/3분위 | 2/3분위 |",
        "|---|---:|---:|---:|",
        f"| 60봉 종가 상승률 | {cuts['close_change_60']['n']} | {cuts['close_change_60']['p33']} | {cuts['close_change_60']['p67']} |",
        f"| 거래량 비 10/50 | {cuts['vol_ratio_10_50']['n']} | {cuts['vol_ratio_10_50']['p33']} | {cuts['vol_ratio_10_50']['p67']} |",
        "",
        "| 항목 | 값 |",
        "|---|---|",
        f"| version | {rep['version']} |",
        f"| 가격 기준 | {rep['price_basis']} |",
        f"| 스크립트 sha256 | `{rep['hashes']['script']}` |",
        f"| 프로토콜 sha256 | `{rep['hashes']['protocol']}` |",
        f"| 이전 candidates sha256 | `{rep['hashes']['prior_candidates']}` |",
        f"| 최근 candidates sha256 | `{rep['hashes']['recent_candidates']}` |",
        f"| 재표집 | 종목 단위 {N_BOOT}회, seed {SEED} |",
        "| 조합 | 60봉 상승률 상 AND 거래량 비 상 (하나뿐) |",
        "| 신규 진입 | unknown |",
        "",
        "## 상승·비상승 분포 (라벨은 집계용, 경계 계산에 쓰지 않음)",
        "",
        "| 기간 | 특징 | 상승 n / p25 / 중앙 / p75 | 비상승 n / p25 / 중앙 / p75 | 상승이 비상승 중앙 이하% | 비상승이 상승 중앙 이상% | IQR 겹침 |",
        "|---|---|---|---|---:|---:|---:|",
    ]
    for key in ("prior", "recent"):
        p = rep["periods"][key]
        for feat, title in (("dist_close_change_60", "60봉 상승률"), ("dist_vol_ratio_10_50", "거래량 비")):
            d = p[feat]
            r, f, o = d["rise"], d["nonrise"], d["overlap"]
            lines.append(
                f"| {p['label']} | {title} | {r.get('n', 0)} / {r.get('p25')} / {r.get('median')} / {r.get('p75')} | "
                f"{f.get('n', 0)} / {f.get('p25')} / {f.get('median')} / {f.get('p75')} | "
                f"{o.get('rise_at_or_below_nonrise_median_pct')} | {o.get('nonrise_at_or_above_rise_median_pct')} | {o.get('iqr_overlap')} |"
            )

    def fmt(x):
        return "—" if x is None else x

    def row_line(label, name, m):
        vs = m.get("vs_all") or {}
        boot = m.get("bootstrap_mean_net_minus_all") or {}
        lo, hi = boot.get("trade_mean_minus_all_p025"), boot.get("trade_mean_minus_all_p975")
        ci = "—" if lo is None or hi is None else f"{lo}~{hi}"
        return (
            f"| {label} | {name} | {m.get('n', 0)} | {m.get('symbols', 0)} | {m.get('hit15_pct')} | "
            f"{m.get('mean_net_pct')} | {m.get('median_net_pct')} | {m.get('win_pct')} | {m.get('mean_loss_net_pct')} | "
            f"{fmt(vs.get('hit15_pct'))} | {fmt(vs.get('mean_net_pct'))} | {m.get('symbol_equal_mean_net_pct')} | {ci} |"
        )

    lines += [
        "",
        "## 단독 3분위 (거래별 동일 가중, 전체 대비 차이)",
        "",
        "숫자: n / 종목 수 / +15% 도달% / 평균순익% / 중앙순익% / 승률% / 손실평균% / 도달 차이 / 평균순익 차이 / 종목평균 순익% / 종목재표집 평균차 2.5~97.5.",
        "",
        "| 기간 | 구간 | n | 종목 | 도달% | 평균% | 중앙% | 승률% | 손실평균% | 도달Δ | 평균Δ | 종목평균% | 재표집 평균Δ |",
        "|---|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---|",
    ]
    for key in ("prior", "recent"):
        p = rep["periods"][key]
        lines.append(row_line(p["label"], "전체 유효", p["base"]))
        for feat, title in (("close_change_60", "60봉 상승률"), ("vol_ratio_10_50", "거래량 비")):
            for b, lab in (("1", "하"), ("2", "중"), ("3", "상")):
                lines.append(row_line(p["label"], f"{title} {lab}", p["bands"][feat][b]))
    lines += [
        "",
        "## 고정 조합 하나: 60봉 상승률 상 AND 거래량 비 상",
        "",
        "| 기간 | n | 종목 | 도달% | 평균% | 중앙% | 승률% | 손실평균% | 도달Δ | 평균Δ | 종목평균% | 재표집 평균Δ |",
        "|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---|",
    ]
    combo_sym_ci = []
    for key in ("prior", "recent"):
        p = rep["periods"][key]
        c = p["combo_top"]
        vs = c.get("vs_all") or {}
        boot = c.get("bootstrap_mean_net_minus_all") or {}
        lo, hi = boot.get("trade_mean_minus_all_p025"), boot.get("trade_mean_minus_all_p975")
        slo, shi = boot.get("symbol_mean_minus_all_p025"), boot.get("symbol_mean_minus_all_p975")
        ci = f"{lo}~{hi}" if lo is not None and hi is not None else "—"
        sci = f"{slo}~{shi}" if slo is not None and shi is not None else "—"
        combo_sym_ci.append(sci)
        lines.append(
            f"| {p['label']} | {c.get('n', 0)} | {c.get('symbols', 0)} | {c.get('hit15_pct')} | {c.get('mean_net_pct')} | "
            f"{c.get('median_net_pct')} | {c.get('win_pct')} | {c.get('mean_loss_net_pct')} | {vs.get('hit15_pct')} | "
            f"{vs.get('mean_net_pct')} | {c.get('symbol_equal_mean_net_pct')} | {ci} |"
        )
    lines += [
        "",
        "같은 종목의 여러 구간이 있으므로 표본 수로 독립을 주장하지 않는다. "
        "재표집은 종목을 복원추출하며, 표의 구간은 거래평균 차이. "
        f"종목평균 재표집Δ는 이전 {combo_sym_ci[0]}, 최근 {combo_sym_ci[1]}다.",
        "",
        "## 추천 커버 (손익 표와 분리)",
        "",
        "주 분석은 위 전체 유효 표본 손익이다. 추천 여부는 여기서만 센다. "
        "`not_caught`는 T..T+20 **모든** 날에 추천 기록이 있고 그 종목 신호가 없을 때만 쓴다. "
        "일부 날만 기록이 있는데 신호를 못 찾으면 `unknown`이다. 미추천이 아니다. "
        "기록이 있는 날에서 신호를 찾으면 부분 커버여도 `caught`다.",
        "",
        "| 기간 | 집단 | n | unknown | not_caught | caught | 커버 없음 | 부분 | 전체 |",
        "|---|---|---:|---:|---:|---:|---:|---:|---:|",
    ]
    for key in ("prior", "recent"):
        p = rep["periods"][key]
        rec = p.get("rec") or {}
        for gname, gkey in (("전체 유효", "all"), ("고정 조합", "combo_top")):
            g = rec.get(gkey) or {}
            st = g.get("rec_status_n") or {}
            cv = g.get("rec_cover_kind_n") or {}
            lines.append(
                f"| {p['label']} | {gname} | {g.get('n', 0)} | {st.get('unknown', 0)} | "
                f"{st.get('not_caught', 0)} | {st.get('caught', 0)} | {cv.get('none', 0)} | "
                f"{cv.get('partial', 0)} | {cv.get('full', 0)} |"
            )
    rec_notes = []
    for key in ("prior", "recent"):
        p = rep["periods"][key]
        meta = p.get("rec_days") or {}
        rec_notes.append(
            f"{p['label']}: 거래일 {meta.get('period_n')} 중 추천 기록 {meta.get('covered_n')}일"
            + (
                f" (없음 {meta.get('uncovered_n')}일 {meta.get('uncovered_first')}~{meta.get('uncovered_last')})"
                if meta.get("uncovered_n")
                else ""
            )
        )
    lines.append("")
    lines.append(
        "기간 말 기록이 없는 날을 포함하는 구간은 미래창 부족·봉 없음으로 유효 표본에 안 들어오면, "
        "유효 구간이 모두 전체 커버여서 unknown이 0일 수 있다. 그래도 부분 커버 미발견을 not_caught로 넣지 않는다. "
        + " / ".join(rec_notes)
        + "."
    )
    lines += [
        "",
        "## PR #103~#105",
        "",
        "| PR | 시도 | 결과 | 채택 |",
        "|---|---|---|---|",
        "| #103 | 일별 unique·미포착, 판단일 세 차트 특징 | 두 기간에서 유리함 없음. 특징 참이어도 평균 순익이 음수 | 안 함 |",
        "| #104 | 비중첩 관찰 구간, 즉시 vs 특징 대기, 차트 상승=unconstrained.hit15 | 즉시·대기 모두 두 기간 평균이 동시에 양수가 아님. 대기가 즉시보다 나쁨 | 안 함 |",
        "| #105 | 60봉 상승률·거래량 비 3분위, 상위 AND 하나 | 도달률은 오르나 비용 반영 평균은 전체보다 나쁨. 부분 커버 미발견은 unknown | 안 함. 이 후보 탐색 종료 |",
        "",
        "## 결론",
        "",
        f"**{j['label']}.** 관찰은 {j.get('observation')}. {j['note']}",
        "",
        "| 항목 | 내용 |",
        "|---|---|",
        "| 도달률 vs 순손익 | 위 표에서 도달Δ와 평균Δ를 따로 본다. 상위 구간은 도달률이 올라도 승률·평균순익은 더 나쁜 경우가 많다 |",
        f"| 운영 | 채택 {j.get('adoption', '안 함')} |",
        f"| 탐색 | {j.get('exploration', '종료')}. 하위 거래량 조합·손절 변경 없음 |",
        "| 가격 | Yahoo 일봉, 수정 여부 미확정. 이상은 삭제·보정하지 않음. 큰 손실 근거는 v2 보고서 |",
        "| 기간 | 이미 쓴 두 기간. 독립 검증 아님 |",
        f"| 재현 | `python docs/research/top100_pre_rise_tertiles.py --output <dir> --report docs/top100-pre-rise-v3/report.md` |",
        "",
        "결과 JSON은 Git 밖 `history-research/top100-pre-rise-v3/summary.json`, 경계는 `cuts.json`. 초안 보고서는 `report-before-rev1.md`.",
        "",
    ]
    return "\n".join(lines) + "\n"


def main(argv=None) -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--output", required=True)
    ap.add_argument("--report")
    args = ap.parse_args(argv)
    v2 = load_v2()
    out = Path(args.output)
    out.mkdir(parents=True, exist_ok=True)
    prior_p = v2.load_period("prior")
    recent_p = v2.load_period("recent")
    prior_rows = rows_from_episodes(v2.build_episodes(prior_p), prior_p)
    cuts = {
        "close_change_60": tertile_cuts([r["close_change_60"] for r in prior_rows]),
        "vol_ratio_10_50": tertile_cuts([r["vol_ratio_10_50"] for r in prior_rows]),
        "source": "prior_valid_rows_labels_unused",
        "p33_p67": "linear interpolation on sorted values",
    }
    (out / "cuts.json").write_text(json.dumps(cuts, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    recent_rows = rows_from_episodes(v2.build_episodes(recent_p), recent_p)
    report = {
        "version": VERSION,
        "price_basis": v2.PRICE_BASIS,
        "window_name": "nonoverlapping_top100_observation_window",
        "new_entry_status": "unknown",
        "combo": COMBO,
        "cuts": cuts,
        "hashes": {
            "script": sha256(Path(__file__)),
            "protocol": sha256(ROOT / "docs/top100-pre-rise-v3/protocol.md"),
            "v2_script": sha256(ROOT / "docs/research/top100_pre_rise_episode.py"),
            "prior_candidates": prior_p["candidates_sha256"],
            "recent_candidates": recent_p["candidates_sha256"],
            "prior_signals": prior_p["signals_sha256"],
            "recent_signals": recent_p["signals_sha256"],
        },
        "periods": {
            "prior": {"label": prior_p["label"], "rec_days": rec_day_meta(prior_p), **period_block(prior_rows, cuts)},
            "recent": {"label": recent_p["label"], "rec_days": rec_day_meta(recent_p), **period_block(recent_rows, cuts)},
        },
        "limitations": [
            "Exploratory; already-used periods, not independent validation",
            "Does not change 5% stop, 15% target, or T+20 hold",
            "Yahoo daily bars, adjustment unverified",
            "Repeated windows per symbol; trade count is not independent n",
        ],
    }
    report["judgment"] = judge(report["periods"]["prior"], report["periods"]["recent"])
    (out / "summary.json").write_text(json.dumps(report, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    md = render_report(report)
    (out / "report.md").write_text(md, encoding="utf-8")
    if args.report:
        Path(args.report).write_text(md, encoding="utf-8")
    print(
        json.dumps(
            {
                "judgment": report["judgment"],
                "cuts": cuts,
                "prior_combo": report["periods"]["prior"]["combo_top"],
                "recent_combo": report["periods"]["recent"]["combo_top"],
                "prior_base": report["periods"]["prior"]["base"],
                "recent_base": report["periods"]["recent"]["base"],
                "prior_rec": report["periods"]["prior"]["rec"],
                "recent_rec": report["periods"]["recent"]["rec"],
            },
            ensure_ascii=False,
            indent=2,
        )
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
