"""One scheduled forward update, with independent atomic operational records."""
import argparse
import json
import os
import pathlib
import re
import subprocess
import sys
import tempfile
import time
import uuid

ROOT = pathlib.Path(__file__).resolve().parents[1]


def save_record(path, record):
    path.parent.mkdir(parents=True, exist_ok=True)
    with tempfile.NamedTemporaryFile(mode="w", encoding="utf-8", dir=path.parent, delete=False) as f:
        tmp = pathlib.Path(f.name)
        json.dump(record, f, ensure_ascii=False, allow_nan=False)
        f.flush()
        os.fsync(f.fileno())
    try:
        os.replace(tmp, path)
    finally:
        tmp.unlink(missing_ok=True)


def update(config_path, state_dir, runner=subprocess.run):
    config = json.loads(config_path.read_text(encoding="utf-8"))
    account = config.get("id", "")
    if not isinstance(account, str) or not re.fullmatch(r"[a-z0-9_-]{1,64}", account):
        raise ValueError("Invalid account ID")
    run_id = uuid.uuid4().hex
    log = state_dir / "runs" / (account + "-forward") / (run_id + ".json")
    record = {"schema": 1, "run_id": run_id, "account": account, "mode": "forward",
              "started_at": int(time.time()), "finished_at": None, "status": "running", "stage": "collect"}
    save_record(log, record)  # Refuse to run without operational logging.
    env = dict(os.environ, PAPER_STATE_DIR=str(state_dir))
    eval_symbols = dict(config.get("symbols") or {})
    universe = config.get("universe")
    try:
        # Concurrent scheduler invocations never share mutable collection files.
        with tempfile.TemporaryDirectory(prefix="paper-input-") as tmp:
            if universe == "kr_amount_scan":
                record["stage"] = "scan"
                save_record(log, record)
                limit = int(config.get("scan_limit") or 100)
                scan = runner(["php", str(ROOT / "bin/paper_scan_universe.php"),
                               "--config=" + str(config_path), "--limit=" + str(limit)],
                              cwd=ROOT, env=env, check=True, timeout=2400,
                              capture_output=True, text=True, encoding="utf-8", errors="replace")
                payload = json.loads(scan.stdout)
                if not isinstance(payload, dict) or payload.get("ok") is not True:
                    raise ValueError("Invalid scan universe")
                eval_symbols = payload.get("symbols") or {}
                if isinstance(eval_symbols, list):
                    eval_symbols = {}
                if not isinstance(eval_symbols, dict):
                    raise ValueError("Invalid scan symbols")
                record["scan"] = {"summary": payload.get("summary"), "held": payload.get("held"),
                                  "candidates": payload.get("candidates"), "rr_audit": payload.get("rr_audit")}
                save_record(log, record)
                if eval_symbols == {}:
                    record["status"] = "success"
                    record["summary"] = {"account": account, "mode": "forward",
                                         "empty_universe": True, "halted": False}
                    print(json.dumps(record["summary"], ensure_ascii=False))
                    return 0
            record["stage"] = "collect"
            save_record(log, record)
            names = list(eval_symbols)
            runner([sys.executable, str(ROOT / "bin/fetch_comparison_data.py"), "--years=2",
                    "--symbols=" + ",".join(names), "--out=" + tmp],
                   cwd=ROOT, env=env, check=True, timeout=1800)
            if any(re.fullmatch(r"\d{6}(?:\.(?:KS|KQ))?", str(symbol), re.I) for symbol in names):
                record["stage"] = "naver_session"
                save_record(log, record)
                runner(["php", str(ROOT / "bin/paper_patch_naver_daily.php"), "--dir=" + tmp],
                       cwd=ROOT, env=env, check=True, timeout=300)
            extra = []
            if universe == "kr_amount_scan":
                symbols_file = pathlib.Path(tmp) / "runtime-symbols.json"
                symbols_file.write_text(json.dumps(eval_symbols, ensure_ascii=False), encoding="utf-8")
                extra.append("--symbols-file=" + str(symbols_file))
            record["stage"] = "account"
            save_record(log, record)
            result = runner(["php", str(ROOT / "bin/paper_account.php"), "--config=" + str(config_path),
                             "--data=" + tmp, "--mode=forward", *extra], cwd=ROOT, env=env,
                            check=True, timeout=1800, capture_output=True, text=True,
                            encoding="utf-8", errors="replace")
            summary = json.loads(result.stdout)
            if not isinstance(summary, dict) or summary.get("account") != account or summary.get("mode") != "forward":
                raise ValueError("Invalid account summary")
            record["summary"] = summary
            record["status"] = "halted" if summary.get("halted") else "success"
            print(json.dumps(summary, ensure_ascii=False))
            return 2 if summary.get("halted") else 0
    except (Exception, KeyboardInterrupt) as exc:
        record["status"] = "failed"
        record["error_type"] = type(exc).__name__
        if isinstance(exc, subprocess.CalledProcessError):
            record["exit_code"] = exc.returncode
            if exc.stderr:
                print(exc.stderr, file=sys.stderr)
        print("Paper update failed at " + record["stage"] + ": " + type(exc).__name__, file=sys.stderr)
        return 1
    finally:
        record["finished_at"] = int(time.time())
        save_record(log, record)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--config", default="config/paper-us.json")
    args = parser.parse_args()
    config_path = pathlib.Path(args.config)
    if not config_path.is_absolute():
        config_path = ROOT / config_path
    state_dir = pathlib.Path(os.environ.get("PAPER_STATE_DIR", str(ROOT.parent / "stock-personal-paper")))
    if not state_dir.is_absolute():
        state_dir = ROOT / state_dir
    return update(config_path.resolve(), state_dir.resolve())


if __name__ == "__main__":
    sys.exit(main())
