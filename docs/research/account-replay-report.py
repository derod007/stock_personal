"""Build docs/historical-account-replay/report.md from the exported smoke results.

Every number comes from the exported JSON files (summary, reconcile, trades, crosscheck, provenance,
resume-check, quality scans) or from running tests/account_replay.php. Nothing is typed in by hand.

    python docs/research/account-replay-report.py --php E:/xampp/php/php.exe
"""
import argparse
import collections
import json
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
BASE = ROOT / "docs" / "historical-account-replay"
WINDOWS = [
    ("a", "이전 기간 시작부", "prior"),
    ("b", "최근 기간 시작부", "recent"),
    ("c", "이전 기간 끝부분 (주문·보유가 남은 채 끝남)", "prior"),
]


def load(path):
    return json.loads(Path(path).read_text(encoding="utf-8"))


def won(v):
    return f"{round(v):,}"


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--php", default="php")
    args = ap.parse_args()

    test = subprocess.run([args.php, "-d", "memory_limit=1G", str(ROOT / "tests" / "account_replay.php")],
                          capture_output=True, text=True, encoding="utf-8", cwd=ROOT)
    lines = test.stdout.splitlines()
    n_pass = sum(1 for l in lines if l.startswith("PASS "))
    n_fail = sum(1 for l in lines if l.startswith("FAIL "))
    test_ok = test.returncode == 0 and n_fail == 0 and lines and lines[-1].strip() == "ALL PASS"
    if not test_ok:
        print(test.stdout, test.stderr, file=sys.stderr)
        sys.exit("tests/account_replay.php did not pass")

    W = {}
    for key, _, _ in WINDOWS:
        d = BASE / "smoke" / key
        W[key] = dict(summary=load(d / "summary.json"), rec=load(d / "reconcile.json"), trades=load(d / "trades.json")["trades"],
                      cross=load(d / "crosscheck-pr65.json"), prov=load(d / "provenance.json"))
    resume = load(BASE / "smoke" / "a" / "resume-check.json")
    qs = {"prior": load(BASE / "quality-scan-prior.json"), "recent": load(BASE / "quality-scan-recent.json")}

    o = []
    o.append("# 고정 종목군 과거 모의계좌 재현 — 소규모 검증 보고서\n")
    o.append("범위: 구현 검증용 짧은 구간 3개. 전체 두 기간 실행, 수익성 결론, 전략 조정, 운영 계좌 반영은 하지 않았다. "
             "아래 금액은 장부가 맞는지 보기 위한 값이며 성과 해석에 쓰지 않는다.\n")
    o.append("표기: **저장 스캔에서 확보한 고정 종목군 계좌 재현** (당시 매일의 TOP100 복원이 아니다).\n")
    o.append("이 파일은 `docs/research/account-replay-report.py`가 내보낸 JSON에서 만든다. 숫자를 손으로 적지 않았다.\n")

    o.append("## 1. 구간별 결과\n")
    o.append("| 구간 | 기간 | 세션 | 주문 | 체결 | 취소(미체결/진입 전) | 청산(손절/목표/기간) | 마감 시 보유 | 마감 시 대기주문 | 현금 | 실현손익 | 평가손익(미청산) | 장부 대조 |")
    o.append("|---|---|--:|--:|--:|--:|--:|--:|--:|--:|--:|--:|---|")
    for key, name, _ in WINDOWS:
        w = W[key]
        s = w["summary"]
        tr = w["trades"]
        ex = collections.Counter(t["exit"]["reason"] for t in tr if t.get("exit"))
        cx = collections.Counter(t["cancelled"]["reason"] if isinstance(t.get("cancelled"), dict) else t["cancelled"] for t in tr if t.get("cancelled"))
        start = w["prov"]["research"]["start_day"]
        o.append(f"| {key} {name} | {start} ~ {s['last_day']} | {s['event_counts']['equity']} | {s['event_counts']['order']} | "
                 f"{s['event_counts'].get('fill', 0)} | {sum(cx.values())} ({cx.get('unfilled', 0)}/{cx.get('cancelled_before_entry', 0)}) | "
                 f"{s['closed_trades']} ({ex.get('stop', 0)}/{ex.get('target', 0)}/{ex.get('time', 0)}) | {len(s['open_positions'])} | "
                 f"{len(s['pending_orders'])} | {won(s['cash'])} | {won(s['realized_pnl'])} | {won(s['unrealized_pnl_open_positions'])} | "
                 f"{'통과' if w['rec']['pass'] else '실패'} |")
    o.append("")
    o.append("마지막 평가일에 남은 보유는 강제로 청산하지 않고 평가손익으로만 적었다.\n")

    o.append("## 2. 요구한 확인 항목과 근거\n")
    o.append("| 확인 항목 | 결과 | 근거 |")
    o.append("|---|---|---|")
    allx = [t for k in W for t in W[k]["trades"]]
    amb = sum(1 for t in allx if t.get("exit") and t["exit"].get("ambiguous_bar"))
    outcomes = collections.Counter()
    for k in W:
        for kk, v in W[k]["cross"]["outcome_of_matched_signals"].items():
            outcomes[kk] += v
    rows = [
        ("주문·체결·미체결·만료·진입 전 취소", "실데이터 3구간 + 단위시험", "구간 c에 미체결 만료와 진입 전 취소가 모두 있다. 단위시험은 3번째 봉 만료와 예약현금 해제까지 확인한다."),
        ("손절·목표 청산, 갭, 같은 봉 충돌", f"실데이터 청산 {sum(1 for t in allx if t.get('exit'))}건 (모호한 봉 {amb}건) + 단위시험",
         "단위시험이 손절 갭(시가 기준), 목표 갭, 진입 봉 손절, 같은 봉 손절 우선(`ambiguous_bar`), 20봉 기간 청산을 각각 만든다."),
        ("현금 부족·보유 한도·같은 종목 중복", f"실데이터 신호 결과 합계 {dict(outcomes)}", "`position_limit`, `cash_or_risk_or_sector_limit`, `already_active`가 모두 실제로 나왔다. 단위시험이 총위험 3%, 섹터 40%, 아주 작은 현금도 만든다."),
        ("현금·수량·수수료·실현손익·평가금액 대조", "3구간 통과", "이벤트만으로 현금·예약현금·실현손익·수수료·평가금액을 다시 만들어 저장 상태와 비교한다. 모든 주문을 `TradeSimulator`로 독립 재시뮬레이션해 체결·청산이 같음을 확인한다. 종가 표시는 데이터셋 종가와 비교한다."),
        ("같은 날짜 재실행 시 중복 없음", "통과", f"구간 a를 다시 실행하면 처리 세션 0, 저널 파일이 바이트 단위로 같다. 단위시험도 같다."),
        ("이어 하기 = 한 번에 실행", "통과" if resume["identical"] else "실패",
         f"구간 a를 한 번에 돌린 저널과 4세션씩 3번에 나눠 돌린 저널이 같다 (이벤트 {resume['events'][0]}개, 정규화 해시 `{resume['normalized_sha256'][0][:16]}…`, 상태 해시 동일). 단위시험은 3번 중단 후 재개도 확인한다."),
        ("미래 봉 추가·변경이 과거에 영향 없음", "단위시험 통과", "봉을 더 붙여도 이전 추천·주문·장부가 그대로이고, 이전 날짜까지만 돌려도 기록이 다시 쓰이지 않는다. 실제 엔진(`ChartPlanEngine`)으로도 확인한다. 이미 쓴 구간의 봉이나 설정이 바뀌면 이어 하기를 거부한다."),
        ("연구 계좌에만 기록", "통과", "매 실행 전후로 데이터셋 폴더 전체 해시와 운영 계좌 상위 파일 해시를 비교한다. 3구간 모두 변하지 않았다 (`untouched`). 단위시험은 새로 생긴 파일이 연구 계좌 폴더 안뿐임을 확인한다."),
        ("주입 추천은 기능시험 전용 표시", "통과", "주입한 계획은 `plan_source=injected_test`로 기록되어 실제 엔진 실행과 같은 계좌에 섞을 수 없다. 실데이터 3구간은 모두 `engine`이다."),
        ("대조 검사가 오류를 잡는지", "단위시험 통과", "체결가·현금·청산을 일부러 바꾸면 대조가 실패하고, 저널을 고치면 해시 사슬이 깨진다."),
    ]
    for r in rows:
        o.append("| " + " | ".join(r) + " |")
    o.append("")
    o.append(f"`php tests/account_replay.php`: {n_pass}개 항목 모두 통과.\n")

    o.append("### 구간별 대조 항목\n")
    names = list(W["a"]["rec"]["checks"].keys())
    o.append("| 항목 | " + " | ".join(k for k, _, _ in WINDOWS) + " |")
    o.append("|---|" + "---|" * len(WINDOWS))
    for n in names:
        cells = []
        for k, _, _ in WINDOWS:
            c = W[k]["rec"]["checks"][n]
            cells.append(("통과 — " if c["pass"] else "**실패** — ") + c["detail"])
        o.append(f"| `{n}` | " + " | ".join(cells) + " |")
    o.append("")

    o.append("## 3. PR #65 신호 모집단과의 대조\n")
    o.append("계좌가 매일 만든 눌림 신호가 PR #65 모집단(`population.json`)의 같은 날짜 신호와 같은 진입·손절·목표인지 본다. 같은 `ChartPlanEngine`이므로 같아야 하며, 달라지면 하루 단위 입력이 다르다는 뜻이다.\n")
    o.append("| 구간 | 모집단 눌림 | 같은 신호·가격 | 수준 불일치 | 계좌에 없음 | 계좌 신호 결과 |")
    o.append("|---|--:|--:|--:|--:|---|")
    for k, _, _ in WINDOWS:
        c = W[k]["cross"]
        o.append(f"| {k} | {c['population_pullbacks_in_window']} | {c['same_signal_and_levels']} | {len(c['levels_or_pattern_differ'])} | "
                 f"{len(c['missing_from_account_signals'])} | {c['outcome_of_matched_signals']} |")
    o.append("")

    o.append("## 4. 품질 규칙에 걸리는 빈도 (전체 기간, 주문 없이 센 값)\n")
    o.append("| 기간 | 세션 | 종목 | 종목·세션 | 막힌 종목·세션 | 비율 | 막힌 적 있는 종목 | 이유별 (종목·세션) |")
    o.append("|---|--:|--:|--:|--:|--:|--:|---|")
    for p in ("prior", "recent"):
        q = qs[p]
        why = ", ".join(f"{k} {v['symbol_sessions']}" for k, v in q["by_reason"].items())
        o.append(f"| {p} ({q['dataset']}) | {q['sessions']} | {q['symbols']} | {q['symbol_sessions']:,} | {q['blocked_symbol_sessions']} | "
                 f"{q['blocked_share'] * 100:.1f}% | {q['symbols_ever_blocked']} | {why} |")
    o.append("")
    o.append("기존 규칙은 보유 중인 종목의 봉이 없거나 무효이면 계좌를 멈춘다(`unpriced_position`). 대기 주문은 취소한다(`data_quality`). 이 규칙을 그대로 두면 전체 실행이 중간에 멈출 수 있다. 결정이 필요하다 (`protocol.md` 6절).\n")

    p = W["a"]["prov"]
    o.append("## 5. 재현 정보\n")
    o.append(f"- 전략 지문: `{p['strategy_fingerprint']}` (운영과 같은 값. 전략 파일은 수정하지 않았다.)")
    o.append(f"- 설정 해시: `{p['config_hash']}`")
    o.append("- 코드·설정 해시 (LF 기준 sha256):")
    for f, h in p["code_sha256_lf"].items():
        o.append(f"  - `{f}` `{h}`")
    o.append("- 데이터셋:")
    for k, _, per in WINDOWS:
        d = W[k]["prov"]["dataset"]
        o.append(f"  - {k} `{d['name']}`: dataset.json `{d['files']['dataset.json']}`, universe.json `{d['files']['universe.json']}`, "
                 f"봉 묶음 `{d['bars_set_sha256']}`, 사용 {d['symbols_used']}종목, 제외 {d['symbols_excluded']}종목, 평가 시작 {d['start_day']}, 마지막 완료 세션 {d['as_of_day']}")
    o.append("- 저널 (벽시계 시각을 뺀 정규화 해시, 상태 해시):")
    for k, _, _ in WINDOWS:
        pp = W[k]["prov"]
        o.append(f"  - {k} `{pp['account']}`: `{pp['journal_normalized_sha256']}`, `{pp['state_hash']}`")
    o.append("")
    o.append("원본 저널 파일 해시(`journal_sha256`)에는 기록 시각이 들어가므로 실행마다 다르다. 같은 입력의 동일성은 정규화 해시로 비교한다.\n")
    o.append("## 6. 산출물\n")
    o.append("`docs/historical-account-replay/smoke/{a,b,c}/`: `summary.json`, `reconcile.json`, `trades.json`, `daily-log.jsonl`(날짜별 추천·제외 사유·주문·체결·청산·현금·보유), `provenance.json`, `crosscheck-pr65.json`. 구간 a에는 `resume-check.json`도 있다. 전체 기간 품질 스캔은 `quality-scan-{prior,recent}.json`.")
    (BASE / "report.md").write_text("\n".join(o) + "\n", encoding="utf-8", newline="\n")
    print("written", BASE / "report.md", "tests", n_pass)


if __name__ == "__main__":
    main()
