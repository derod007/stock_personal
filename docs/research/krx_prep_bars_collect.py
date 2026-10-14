#!/usr/bin/env python3
"""Collect KRX daily equity originals for 240 trading days before 2024-10-08.

Does not scale prices, mix Yahoo, insert bars, or run accounts.
Empty API responses are not holidays. AUTH_KEY is never logged or written.
"""
from __future__ import annotations

import argparse
import hashlib
import importlib.util
import json
import os
import re
import shutil
import time
from datetime import date
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[2]
PILOT_PATH = ROOT / "docs" / "research" / "historical-amount-top100-pilot.py"
DOCS = ROOT / "docs" / "krx-prep-bars-before-20241008"
CALENDAR_PATH = DOCS / "trading-calendar.json"
RAW_ROOT = ROOT / "data" / "raw" / "research" / "krx-prep-bars-before-20241008"
EVAL_START = date(2024, 10, 8)
PREP_BARS = 240
PRICE_BASIS = "KRX TDD 원본, 수정 여부 미확정"
PROBE_SHA = {
    "20231010_kospi_equity.json": "c8a83f956708ffb190549d6f310591d8c72f5610c83105deb438963feeea1b9b",
    "20231010_kosdaq_equity.json": "b0d8a7ef122c7a1a3481278d40ab6bce1882598a8d3d884408e7db70bf3876f9",
    "20241007_kospi_equity.json": "1e8370851e06fc1b54180bbc287f087c73a5e59bd52e7a9547f4a9867646ab4f",
    "20241007_kosdaq_equity.json": "58e2818c6907c57834480415ec1173f85b688b56aedea0267a6f3c9564bc1193",
}
PROBE_CANDIDATES = [
    ROOT / "data" / "raw" / "research" / "krx-price-basis-prep-probe",
    ROOT.parent / "noramu-work-krx-price-basis" / "data" / "raw" / "research" / "krx-price-basis-prep-probe",
]
KEY_CANDIDATES = [
    ROOT / "data" / "raw" / "research" / "historical-amount-top100-202510" / ".krx-auth-key",
    ROOT.parent / "noramu" / "data" / "raw" / "research" / "historical-amount-top100-202510" / ".krx-auth-key",
]
SPEC_PAGE = "https://openapi.krx.co.kr/contents/OPP/USES/service/OPPUSES002_S2.cmd?BO_ID=JvJFzlAENzZlPBDNGAWC"
SPEC_PAGE_KSQ = "https://openapi.krx.co.kr/contents/OPP/USES/service/OPPUSES002_S2.cmd?BO_ID=hZjGpkllgCBCWqeTsYFj"
HREF_RE = re.compile(r"""(?:href|action|src)\s*=\s*["']([^"']+)["']""", re.I)
DOWN_RE = re.compile(r"(fileDown|GuideFile|guideFile|명세서|\.docx|\.pdf|\.xlsx)", re.I)
PRICE_WORD_RE = re.compile(r"원주가|수정주가|수정계수|단순주가|조정주가")


def load_pilot():
    spec = importlib.util.spec_from_file_location("hist_top100_pilot", PILOT_PATH)
    mod = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    spec.loader.exec_module(mod)
    return mod


def sha256_bytes(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def sha256_file(path: Path) -> str:
    return sha256_bytes(path.read_bytes())


def write_json(path: Path, payload: Any) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


def load_auth_key(pilot: Any) -> str:
    env = (os.environ.get("KRX_AUTH_KEY") or os.environ.get("KRX_API_KEY") or "").strip()
    if env:
        return env
    for path in KEY_CANDIDATES:
        if path.is_file():
            return path.read_text(encoding="utf-8").strip()
    return (pilot.load_auth_key() or "").strip()


def last_n_trading_days(calendar: dict[str, Any], before: date, n: int) -> list[str]:
    pilot = load_pilot()
    search_from = date.fromisoformat(calendar["search_range"]["from"])
    search_to = date.fromisoformat(calendar["search_range"]["to"])
    if search_to >= before:
        search_to = before.replace(day=before.day) 
    # search_to is already the day before eval start in this calendar
    days = pilot.candidate_trading_days(search_from, search_to, calendar)
    days = [d for d in days if d < before.strftime("%Y%m%d")]
    if len(days) < n:
        raise SystemExit(f"not enough documented trading days: {len(days)} < {n}")
    return days[-n:]


def freeze_days() -> dict[str, Any]:
    calendar = json.loads(CALENDAR_PATH.read_text(encoding="utf-8"))
    days = last_n_trading_days(calendar, EVAL_START, PREP_BARS)
    payload = {
        "schema": 1,
        "kind": "krx_prep_bars_trading_days_v1",
        "eval_start": EVAL_START.isoformat(),
        "prep_bars": PREP_BARS,
        "from": f"{days[0][0:4]}-{days[0][4:6]}-{days[0][6:8]}",
        "to": f"{days[-1][0:4]}-{days[-1][4:6]}-{days[-1][6:8]}",
        "day_count": len(days),
        "days": days,
        "price_basis": PRICE_BASIS,
        "empty_response_is_not_holiday": True,
        "calendar": str(CALENDAR_PATH.relative_to(ROOT)).replace("\\", "/"),
        "includes_year_end_closure_20231229": "20231229" in (calendar.get("closed_weekdays") or {}),
        "includes_temporary_holiday_20241001": "20241001" in (calendar.get("closed_weekdays") or {}),
        "excludes_eval_start": "20241008" not in days,
    }
    write_json(DOCS / "trading-days.json", payload)
    return payload


def probe_dir() -> Path | None:
    for p in PROBE_CANDIDATES:
        if p.is_dir():
            return p
    return None


def reuse_pr89(dest_raw: Path, days: list[str]) -> dict[str, Any]:
    src = probe_dir()
    rec: dict[str, Any] = {
        "source_dir_found": src is not None,
        "source_dir": str(src) if src else None,
        "copied": [],
        "skipped_not_in_calendar": [],
        "missing": [],
        "hash_mismatch": [],
        "issues": [],
    }
    dest_raw.mkdir(parents=True, exist_ok=True)
    day_set = set(days)
    for name, expected in PROBE_SHA.items():
        compact = name.split("_", 1)[0]
        if src is None:
            rec["missing"].append(name)
            rec["issues"].append({"file": name, "issue": "probe_dir_missing"})
            continue
        src_path = src / name
        if not src_path.is_file():
            rec["missing"].append(name)
            rec["issues"].append({"file": name, "issue": "probe_file_missing"})
            continue
        got = sha256_file(src_path)
        hash_ok = got == expected
        if not hash_ok:
            rec["hash_mismatch"].append({"file": name, "expected": expected, "got": got})
            rec["issues"].append({"file": name, "issue": "probe_hash_mismatch"})
            continue
        if compact not in day_set:
            rec["skipped_not_in_calendar"].append({"file": name, "sha256": got, "hash_ok": True})
            continue
        shutil.copy2(src_path, dest_raw / name)
        rec["copied"].append({"file": name, "sha256": got})
    return rec


def collect_days(days: list[str], sleep_s: float) -> dict[str, Any]:
    pilot = load_pilot()
    dest_raw = RAW_ROOT / "raw"
    dest_raw.mkdir(parents=True, exist_ok=True)
    reuse = reuse_pr89(dest_raw, days)
    auth = load_auth_key(pilot)
    summary: dict[str, Any] = {
        "schema": 1,
        "kind": "krx_prep_bars_collect_v1",
        "price_basis": PRICE_BASIS,
        "no_yahoo_mix": True,
        "no_ratio_adjust": True,
        "no_account_run": True,
        "empty_response_is_not_holiday": True,
        "started": pilot.now_kst(),
        "requests": 0,
        "retries": 0,
        "bytes": 0,
        "errors": [],
        "ok_dates": [],
        "blocked_dates": [],
        "days": [],
        "pr89_reuse": reuse,
        "auth_key_present": bool(auth),
        "raw_dir_gitignored": True,
    }
    if reuse["issues"]:
        summary["status"] = "stopped_reuse"
        summary["finished"] = pilot.now_kst()
        return summary
    if not auth:
        summary["status"] = "skipped_no_auth_key"
        summary["finished"] = pilot.now_kst()
        return summary
    for day in days:
        market_results: dict[str, dict[str, Any]] = {}
        files = []
        for market in ("kospi_equity", "kosdaq_equity"):
            dest = dest_raw / f"{day}_{market}.json"
            if dest.is_file():
                cached = pilot.validate_openapi_body(dest.read_bytes(), day)
                if cached.get("kind") == "empty":
                    dest.unlink()
            rec = pilot.resolve_market_response(
                dest=dest,
                expected_date=day,
                market=market,
                auth_key=auth,
                summary=summary,
                sleep_s=sleep_s,
            )
            rec.pop("block", None)
            rec.pop("head", None)
            market_results[market] = rec
            files.append({k: rec.get(k) for k in (
                "market", "ok", "kind", "reuse", "sha256", "bytes", "n",
                "found_dates", "cache_rejected", "http_status",
            )})
        decision = pilot.decide_day_selection(market_results)
        day_rec = {
            "date": day,
            "files": files,
            "select": decision["select"],
            "status": decision["status"],
            "market_kinds": decision["market_kinds"],
            "holiday_confirmed": False,
        }
        if decision["select"]:
            summary["ok_dates"].append(day)
        else:
            summary["blocked_dates"].append(day)
            if decision["status"] == "auth":
                summary["days"].append(day_rec)
                summary["status"] = "stopped_auth"
                summary["finished"] = pilot.now_kst()
                return summary
        summary["days"].append(day_rec)
    summary["status"] = "ok" if len(summary["ok_dates"]) == len(days) else "incomplete"
    summary["finished"] = pilot.now_kst()
    summary["ok_day_count"] = len(summary["ok_dates"])
    summary["day_count"] = len(days)
    summary["errors"] = [
        {k: v for k, v in e.items() if k != "head"}
        for e in (summary.get("errors") or [])
    ]
    return summary


def _abs_url(base: str, href: str) -> str:
    if href.startswith("http://") or href.startswith("https://"):
        return href
    if href.startswith("//"):
        return "https:" + href
    if href.startswith("/"):
        from urllib.parse import urljoin
        return urljoin(base, href)
    from urllib.parse import urljoin
    return urljoin(base, href)


def spec_probe() -> dict[str, Any]:
    pilot = load_pilot()
    out: dict[str, Any] = {
        "schema": 1,
        "kind": "krx_openapi_spec_probe_v1",
        "price_basis": PRICE_BASIS,
        "pages": [],
        "downloads": [],
        "quotes": [],
        "status": "unconfirmed",
        "note": "Open API TDD 원주가/수정주가 문구가 없으면 미확정.",
    }
    dest = RAW_ROOT / "spec"
    dest.mkdir(parents=True, exist_ok=True)
    candidates: list[str] = []
    form_posts: list[dict[str, str]] = []
    for url in (SPEC_PAGE, SPEC_PAGE_KSQ):
        status, _, body = pilot.http_get(url, {"Accept": "text/html"})
        text = body.decode("utf-8", "replace")
        page = {
            "url": url,
            "status": status,
            "bytes": len(body),
            "sha256": sha256_bytes(body),
            "has_원주가": "원주가" in text,
            "has_수정주가": "수정주가" in text,
        }
        out["pages"].append(page)
        for m in PRICE_WORD_RE.finditer(text):
            start = max(0, m.start() - 40)
            end = min(len(text), m.end() + 80)
            snippet = re.sub(r"\s+", " ", text[start:end]).strip()
            out["quotes"].append({"page": url, "match": m.group(0), "snippet": snippet})
        for href in HREF_RE.findall(text):
            if DOWN_RE.search(href):
                candidates.append(_abs_url(url, href))
        for extra in re.findall(r"['\"](/contents/OPP/[^'\"]*(?:Down|Guide|file)[^'\"]*)['\"]", text, re.I):
            candidates.append(_abs_url(url, extra))
        fields: dict[str, str] = {}
        for inp in re.finditer(r"<input[^>]+>", text, re.I):
            tag = inp.group(0)
            name_m = re.search(r'name=["\']([^"\']+)["\']', tag, re.I)
            val_m = re.search(r'value=["\']([^"\']*)["\']', tag, re.I)
            if name_m:
                name = name_m.group(1)
                if name.upper() in {"AUTH_KEY", "KRX_AUTH_KEY", "KRX_API_KEY"}:
                    continue
                fields[name] = val_m.group(1) if val_m else ""
        if fields.get("BO_ID"):
            form_posts.append(fields)
    tried = []
    for fields in form_posts:
        status, body = pilot.http_post(
            "https://openapi.krx.co.kr/contents/OPP/USES/service/downloadApiDoc.cmd",
            fields,
            {"Referer": SPEC_PAGE},
        )
        rec = {
            "url": "https://openapi.krx.co.kr/contents/OPP/USES/service/downloadApiDoc.cmd",
            "method": "POST",
            "bo_id": fields.get("BO_ID"),
            "api_path": fields.get("apiTestUrl"),
            "status": status,
            "bytes": len(body),
            "sha256": sha256_bytes(body),
        }
        text_body = ""
        if body[:2] == b"PK":
            import zipfile
            import io
            try:
                with zipfile.ZipFile(io.BytesIO(body)) as zf:
                    xml = zf.read("word/document.xml").decode("utf-8", "replace") if "word/document.xml" in zf.namelist() else ""
                    text_body = re.sub(r"<[^>]+>", " ", xml)
                    text_body = re.sub(r"\s+", " ", text_body)
            except zipfile.BadZipFile:
                rec["kind"] = "bad_zip"
        rec["text_head"] = text_body[:400]
        rec["has_원주가"] = "원주가" in text_body
        rec["has_수정주가"] = "수정주가" in text_body
        rec["has_TDD_CLSPRC"] = "TDD_CLSPRC" in text_body
        rec["tdd_fields"] = sorted(set(re.findall(r"TDD_[A-Z]+", text_body)))
        rec["tdd_labels"] = []
        for field in rec["tdd_fields"]:
            m = re.search(re.escape(field) + r".{0,80}", text_body)
            if m:
                rec["tdd_labels"].append(re.sub(r"\s+", " ", m.group(0)).strip())
        for m in PRICE_WORD_RE.finditer(text_body):
            start = max(0, m.start() - 40)
            end = min(len(text_body), m.end() + 80)
            out["quotes"].append({"page": rec["url"], "match": m.group(0), "snippet": text_body[start:end].strip()})
        if body[:2] == b"PK" and (len(body) > 5000 or rec.get("has_TDD_CLSPRC")):
            name = f"{fields.get('BO_ID') or 'spec'}.docx"
            (dest / name).write_bytes(body)
            rec["saved_gitignored"] = name
            rec["kind"] = "binary_spec"
        else:
            rec["kind"] = "short_or_not_spec"
        tried.append(rec)
        time.sleep(0.3)
    for href in list(dict.fromkeys(candidates)):
        if "downloadApiDoc.cmd" in href:
            continue
        status, hdrs, body = pilot.http_get(href, {"Accept": "*/*"})
        ctype = (hdrs or {}).get("content-type", "")
        rec = {
            "url": href,
            "status": status,
            "bytes": len(body),
            "content_type": ctype,
            "sha256": sha256_bytes(body),
        }
        text = body.decode("utf-8", "replace") if body[:4] not in (b"PK\x03\x04", b"%PDF") else ""
        if text:
            rec["looks_html"] = "<html" in text.lower()
            rec["has_원주가"] = "원주가" in text
            rec["has_수정주가"] = "수정주가" in text
            for m in PRICE_WORD_RE.finditer(text):
                start = max(0, m.start() - 40)
                end = min(len(text), m.end() + 80)
                out["quotes"].append({
                    "page": href,
                    "match": m.group(0),
                    "snippet": re.sub(r"\s+", " ", text[start:end]).strip(),
                })
        if body[:4] in (b"PK\x03\x04", b"%PDF") or "officedocument" in ctype or "octet-stream" in ctype:
            suffix = ".docx" if body[:4] == b"PK\x03\x04" else (".pdf" if body[:4] == b"%PDF" else ".bin")
            path = dest / f"stk_bydd_trd_spec{suffix}"
            path.write_bytes(body)
            rec["saved_gitignored"] = path.name
            rec["kind"] = "binary_spec"
        tried.append(rec)
        time.sleep(0.3)
    out["downloads"] = tried
    if any(q.get("match") in ("원주가", "수정주가") for q in out["quotes"]):
        out["status"] = "wording_found_not_applied"
    elif any(d.get("kind") == "binary_spec" and d.get("has_TDD_CLSPRC") for d in tried):
        out["status"] = "file_saved_tdd_fields_adjustment_unconfirmed"
    elif any(d.get("kind") == "binary_spec" for d in tried):
        out["status"] = "file_saved_wording_unconfirmed"
    else:
        out["status"] = "unconfirmed_download_or_wording_missing"
    return out


def public_hashes(days: list[str]) -> dict[str, Any]:
    raw = RAW_ROOT / "raw"
    files = {}
    for day in days:
        for market in ("kospi_equity", "kosdaq_equity"):
            name = f"{day}_{market}.json"
            path = raw / name
            if path.is_file():
                files[name] = sha256_file(path)
    return {
        "schema": 1,
        "kind": "krx_prep_bars_file_hashes_v1",
        "price_basis": PRICE_BASIS,
        "note": "원본 JSON은 Git에 없다. 이 파일은 SHA256만 둔다.",
        "n": len(files),
        "raw": files,
    }


def main() -> int:
    p = argparse.ArgumentParser()
    p.add_argument("--freeze-days", action="store_true")
    p.add_argument("--spec", action="store_true")
    p.add_argument("--collect", action="store_true")
    p.add_argument("--hashes", action="store_true")
    p.add_argument("--sleep", type=float, default=1.2)
    args = p.parse_args()
    if not any((args.freeze_days, args.spec, args.collect, args.hashes)):
        args.freeze_days = True
    days_payload = None
    if args.freeze_days or args.collect or args.hashes:
        days_payload = freeze_days() if args.freeze_days or not (DOCS / "trading-days.json").is_file() else json.loads((DOCS / "trading-days.json").read_text(encoding="utf-8"))
        days = list(days_payload["days"])
        if len(days) != PREP_BARS:
            raise SystemExit(f"expected {PREP_BARS} days, got {len(days)}")
    if args.spec:
        spec = spec_probe()
        write_json(DOCS / "spec-probe.json", spec)
        print(json.dumps({"spec": spec["status"], "quotes": len(spec["quotes"]), "downloads": len(spec["downloads"])}, ensure_ascii=False))
    if args.collect:
        summary = collect_days(days, args.sleep)
        write_json(DOCS / "collect.json", summary)
        print(json.dumps({
            "status": summary.get("status"),
            "requests": summary.get("requests"),
            "retries": summary.get("retries"),
            "ok_dates": len(summary.get("ok_dates") or []),
            "blocked": summary.get("blocked_dates") or [],
            "reuse_copied": len((summary.get("pr89_reuse") or {}).get("copied") or []),
        }, ensure_ascii=False))
        if summary.get("status") not in {"ok"}:
            return 1
    if args.hashes:
        write_json(DOCS / "file-hashes.json", public_hashes(days))
        print(json.dumps({"hashes": len(public_hashes(days)["raw"])}, ensure_ascii=False))
    if args.freeze_days and not args.collect:
        print(json.dumps({
            "days": days_payload["day_count"],
            "from": days_payload["from"],
            "to": days_payload["to"],
            "year_end_closed": days_payload["includes_year_end_closure_20231229"],
            "temporary_holiday_20241001": days_payload["includes_temporary_holiday_20241001"],
        }, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
