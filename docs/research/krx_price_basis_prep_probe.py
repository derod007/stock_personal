#!/usr/bin/env python3
"""Access-check KRX daily equity for the MA240 prep window. Few requests only.

Does not collect the full window, insert bars, scale prices, or run accounts.
Never logs or writes the AUTH_KEY.
"""
from __future__ import annotations

import argparse
import importlib.util
import json
import os
from datetime import date, timedelta
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[2]
PILOT_PATH = ROOT / "docs" / "research" / "historical-amount-top100-pilot.py"
PUBLIC_OUT = ROOT / "docs" / "krx-price-basis-prep-20241008"
RAW_OUT = ROOT / "data" / "raw" / "research" / "krx-price-basis-prep-probe"
EVAL_START = date(2024, 10, 8)
PREP_CALENDAR_START = date(2023, 10, 8)
PROBE_DAYS = ("20231010", "20241007")
MAX_REQUESTS = 4
# Weekdays in the prep window that are ordinary KR public holidays KRX usually closes.
# Empty API responses are not used as holiday proof.
PREP_HOLIDAYS = {
    "2023-12-25",
    "2024-01-01",
    "2024-02-09",
    "2024-02-12",
    "2024-03-01",
    "2024-04-10",
    "2024-05-01",
    "2024-05-06",
    "2024-05-15",
    "2024-06-06",
    "2024-08-15",
    "2024-09-16",
    "2024-09-17",
    "2024-09-18",
    "2024-10-03",
}
KEY_CANDIDATES = [
    ROOT / "data" / "raw" / "research" / "historical-amount-top100-202510" / ".krx-auth-key",
    ROOT.parent / "noramu" / "data" / "raw" / "research" / "historical-amount-top100-202510" / ".krx-auth-key",
]


def load_pilot():
    spec = importlib.util.spec_from_file_location("hist_top100_pilot", PILOT_PATH)
    mod = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    spec.loader.exec_module(mod)
    return mod


def load_auth_key(pilot: Any) -> str:
    env = (os.environ.get("KRX_AUTH_KEY") or os.environ.get("KRX_API_KEY") or "").strip()
    if env:
        return env
    for path in KEY_CANDIDATES:
        if path.is_file():
            return path.read_text(encoding="utf-8").strip()
    return (pilot.load_auth_key() or "").strip()


def weekdays_minus_listed_holidays(start: date, end: date) -> dict[str, Any]:
    days = []
    skipped_holiday = []
    cur = start
    while cur <= end:
        if cur.weekday() < 5:
            iso = cur.isoformat()
            if iso in PREP_HOLIDAYS:
                skipped_holiday.append(iso)
            else:
                days.append(iso)
        cur += timedelta(days=1)
    return {
        "from": start.isoformat(),
        "to": end.isoformat(),
        "weekdays_minus_listed_holidays": len(days),
        "listed_holidays_skipped": skipped_holiday,
        "holiday_list_complete": False,
        "empty_api_not_used_as_holiday": True,
        "first": days[0] if days else None,
        "last": days[-1] if days else None,
    }


def existing_original_span(krx_raw: Path) -> dict[str, Any]:
    files = sorted(p.name for p in krx_raw.glob("*_kospi_equity.json")) if krx_raw.is_dir() else []
    kosdaq = sorted(p.name for p in krx_raw.glob("*_kosdaq_equity.json")) if krx_raw.is_dir() else []
    return {
        "dir_exists": krx_raw.is_dir(),
        "kospi_files": len(files),
        "kosdaq_files": len(kosdaq),
        "first_kospi": files[0] if files else None,
        "last_kospi": files[-1] if files else None,
        "has_20231010": (krx_raw / "20231010_kospi_equity.json").is_file() if krx_raw.is_dir() else False,
        "has_20241007": (krx_raw / "20241007_kospi_equity.json").is_file() if krx_raw.is_dir() else False,
        "has_20241008": (krx_raw / "20241008_kospi_equity.json").is_file() if krx_raw.is_dir() else False,
    }


def public_probe_row(rec: dict[str, Any]) -> dict[str, Any]:
    return {
        "date": rec.get("date"),
        "market": rec.get("market"),
        "reuse_existing_eval_original": rec.get("reuse_existing_eval_original"),
        "http_status": rec.get("http_status"),
        "kind": rec.get("kind"),
        "ok": rec.get("ok"),
        "n": rec.get("n"),
        "bytes": rec.get("bytes"),
        "sha256": rec.get("sha256"),
        "found_dates": rec.get("found_dates"),
        "head": rec.get("head"),
        "saved_gitignored": rec.get("saved_gitignored"),
    }


def main() -> int:
    p = argparse.ArgumentParser()
    p.add_argument("--krx-raw", required=True, help="Existing eval-period KRX originals (reuse only)")
    p.add_argument("--out", default=str(PUBLIC_OUT))
    p.add_argument("--raw-out", default=str(RAW_OUT))
    p.add_argument("--sleep", type=float, default=1.2)
    args = p.parse_args()

    pilot = load_pilot()
    krx_raw = Path(args.krx_raw)
    out_dir = Path(args.out)
    raw_out = Path(args.raw_out)
    auth_key = load_auth_key(pilot)
    span = existing_original_span(krx_raw)
    prep_count = weekdays_minus_listed_holidays(date(2023, 10, 10), date(2024, 10, 7))
    extra_days = prep_count["weekdays_minus_listed_holidays"]
    expected_full_requests = extra_days * 2

    summary: dict[str, Any] = {
        "schema": 1,
        "kind": "krx_price_basis_prep_probe_v1",
        "writes_eval_originals": False,
        "full_collect": False,
        "no_price_scaling": True,
        "no_account_run": True,
        "max_requests": MAX_REQUESTS,
        "requests": 0,
        "prep": {
            "eval_start": EVAL_START.isoformat(),
            "eval_end": "2025-10-02",
            "preparation_start_calendar": PREP_CALENDAR_START.isoformat(),
            "prep_bars_target": 240,
            "source": "PaperHistoryResearch::PREP_BARS and evalStartDay(evalStart)",
            "existing_eval_originals": span,
            "extra_trading_days_estimate": prep_count,
            "expected_full_collect_requests_if_later": expected_full_requests,
            "note": "Estimate is weekdays minus a listed holiday set. Not a KRX calendar collect.",
        },
        "access": {
            "openapi_coverage_official": "2010-01-04",
            "auth_key_present": bool(auth_key),
            "daily_limit_official": 10000,
        },
        "probes": [],
        "facts": [],
        "unconfirmed": [],
    }

    if span["has_20241008"] and not span["has_20241007"] and not span["has_20231010"]:
        summary["facts"].append(
            "기존 KRX 원본은 평가 시작일 2024-10-08부터이며 준비 구간 2023-10-10·2024-10-07 파일은 없다."
        )

    if not auth_key:
        summary["access"]["status"] = "skipped_no_auth_key"
        summary["facts"].append("인증키가 없어 본호출 접근 확인을 건너뛰었다.")
        out_dir.mkdir(parents=True, exist_ok=True)
        (out_dir / "probe-summary.json").write_text(
            json.dumps(summary, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
        )
        print(json.dumps({"out": str(out_dir / "probe-summary.json"), "requests": 0, "status": "skipped_no_auth_key"}, ensure_ascii=False))
        return 0

    raw_out.mkdir(parents=True, exist_ok=True)
    for compact in PROBE_DAYS:
        for market in ("kospi_equity", "kosdaq_equity"):
            if summary["requests"] >= MAX_REQUESTS:
                raise SystemExit("probe request cap reached")
            dest = raw_out / f"{compact}_{market}.json"
            rec: dict[str, Any] = {
                "date": compact,
                "market": market,
                "reuse_existing_eval_original": False,
            }
            eval_name = f"{compact}_{market}.json"
            if krx_raw.is_dir() and (krx_raw / eval_name).is_file():
                rec["reuse_existing_eval_original"] = True
                rec["kind"] = "unexpected_eval_original_present"
                rec["ok"] = False
                summary["probes"].append(public_probe_row(rec))
                continue
            path = pilot.PROD_PATHS[market]
            status, _, body = pilot.http_get(
                f"{pilot.OPENAPI}{path}?basDd={compact}",
                {"Accept": "application/json", "AUTH_KEY": auth_key},
            )
            summary["requests"] += 1
            rec["http_status"] = status
            rec["bytes"] = len(body)
            rec["sha256"] = pilot.sha256_bytes(body)
            rec["head"] = pilot.redact_secrets(body[:160].decode("utf-8", "replace"), extra=auth_key)
            http_kind = pilot.classify_http_status(status, body)
            if http_kind != "ok_http":
                rec["ok"] = False
                rec["kind"] = http_kind
                summary["probes"].append(public_probe_row(rec))
                continue
            dest.write_bytes(body)
            parsed = pilot.validate_openapi_body(body, compact)
            rec.update({
                "ok": parsed["ok"],
                "kind": parsed["kind"],
                "n": parsed["n"],
                "found_dates": parsed.get("found_dates") or [],
                "saved_gitignored": True,
            })
            rec["head"] = "[redacted body not in git]"
            summary["probes"].append(public_probe_row(rec))
            import time
            time.sleep(args.sleep)

    ok_n = sum(1 for r in summary["probes"] if r.get("ok"))
    summary["access"]["status"] = "ok" if ok_n == len(summary["probes"]) and summary["probes"] else "blocked"
    summary["access"]["ok_probes"] = ok_n
    if ok_n == 4:
        summary["facts"].append(
            "준비 구간 끝(2024-10-07)과 시작 근처(2023-10-10) 양 시장 본호출이 비어 있지 않고 BAS_DD가 요청일과 같다."
        )
        summary["facts"].append(
            f"전체 수집은 하지 않았다. 나중에 하면 추가 거래일 추정 {extra_days}일 × 2시장 ≈ {expected_full_requests}회."
        )
    out_dir.mkdir(parents=True, exist_ok=True)
    (out_dir / "probe-summary.json").write_text(
        json.dumps(summary, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
    )
    print(json.dumps({
        "out": str(out_dir / "probe-summary.json"),
        "requests": summary["requests"],
        "ok": ok_n,
        "status": summary["access"]["status"],
        "extra_days_estimate": extra_days,
        "expected_full_requests": expected_full_requests,
    }, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
