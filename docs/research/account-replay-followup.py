"""Write docs/historical-account-replay/followup.md from the sector map and smoke-sector exports."""
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
BASE = ROOT / "docs" / "historical-account-replay"


def load(path):
    return json.loads(Path(path).read_text(encoding="utf-8"))


def main():
    m = load(BASE / "sector-map.json")
    sel = load(BASE / "selection-check.json")
    # Same normalization as PaperStrategyVersion::fileHash.
    file_hash = __import__("hashlib").sha256((BASE / "sector-map.json").read_bytes().replace(b"\r\n", b"\n")).hexdigest()
    lines = [
        "# 섹터 고정과 후보 대응 — 추가 검증",
        "",
        m["assumption"],
        "",
        "저장 스캔 원본에는 업종 칸이 없다. 종목 목록은 그 파일에서 오고, 업종은 운영 캐시 `data/cache/sector/sector_<코드>.json`을 읽기만 해서 `SectorMap::bucketOf`로 다시 매긴다. 캐시 파일은 바꾸지 않았다.",
        "",
        "## 섹터 매핑",
        "",
        f"- 대상 {m['counts']['symbols']}종목. 확보 {m['counts']['secured']}건, 미확인 {m['counts']['unconfirmed']}건. 캐시 저장 버킷과 현재 규칙이 달랐던 건 {m['counts']['bucket_rule_differs']}건.",
        f"- 미확인 이유: {json.dumps(m['unconfirmed_reasons'], ensure_ascii=False)}. 캐시의 업종명이 비어 `기타`로 저장된 자리표시자다. 그 값을 운영 버킷 other로 올리지는 않았다.",
        "- 미확인 종목은 버킷 이름 `unclassified` 하나다. 종목별 버킷으로 40% 한도를 나누지 않는다.",
        f"- 매핑 파일 LF 해시 `{file_hash}`.",
        "- 같은 종목은 두 기간에 같은 칸을 쓴다. 분류가 바뀌면 그 연구 계좌는 이어 가지 않는다.",
        "",
        "## 후보 선별 대응",
        "",
        sel["note"],
        "",
        "| 사례 | 패턴 상태 | 발표된 계획 | 주문 가능 | 스캐너 후보 | 유니버스 선택 | 일치 |",
        "|---|---|---|---|---|---|---|",
    ]
    for row in sel["cases"]:
        lines.append(
            f"| {row['label']} | {row['pattern_status']} | {row['final_status']} | {str(row['order_ready']).lower()} | "
            f"{str(row['entry_recommend']).lower()} | {str(row['selected']).lower()} | {str(row['agree']).lower()} |"
        )
    lines += [
        "",
        "보유 종목은 후보가 아니어도, 스캔 목록이 비어도 평가 대상에 남는다.",
        "",
        "## 짧은 구간 (고정 맵, 별도 계좌)",
        "",
        "이전의 전부 미분류 결과(`smoke/a`, `smoke/b`, `smoke/c`)는 그대로 두었다. 아래는 `smoke-sector/`다.",
        "",
        "| 구간 | 세션 | 주문 | 현금 | 실현손익 | 장부 | 섹터 한도 | 정지 | 기존 미분류와 주문·현금 |",
        "|---|--:|--:|--:|--:|---|---|---|---|",
    ]
    for key, name in [("a", "이전 시작부"), ("b", "최근 시작부"), ("c", "이전 끝부분")]:
        s = load(BASE / "smoke-sector" / key / "summary.json")
        r = load(BASE / "smoke-sector" / key / "reconcile.json")
        old = load(BASE / "smoke" / key / "summary.json")
        same = s["event_counts"].get("order") == old["event_counts"].get("order") and abs(s["cash"] - old["cash"]) < 0.01
        lines.append(
            f"| {key} {name} | {s['event_counts']['equity']} | {s['event_counts'].get('order', 0)} | {round(s['cash']):,} | "
            f"{round(s['realized_pnl']):,} | {'통과' if r['pass'] else '실패'} | {r['checks']['sector_notional_within_limit']['detail']} | "
            f"{'있음' if s['halted'] else '없음'} | {'같음' if same else '다름'} |"
        )
    resume = load(BASE / "smoke-sector" / "a" / "resume-check.json")
    lines += [
        "",
        f"구간 a를 한 번에 실행한 저널과 4세션씩 나눠 이은 저널은 {'같다' if resume['identical'] else '다르다'} "
        f"(이벤트 {resume['events'][0]}개, 정규화 해시 `{resume['normalized_sha256'][0]}`). "
        "같은 명령을 다시 실행하면 처리 세션 0이고 저널 파일이 바이트 단위로 같다.",
        "",
        "세 구간 모두 정지하지 않았다. 보유 종목의 봉이 없거나 무효이면 계좌를 멈추는 기존 규칙은 유지한다. 보간하거나 날짜를 건너뛰지 않는다.",
        "",
        "## 섹터 캐시 경로",
        "",
    ]
    check = BASE / "sector-path-check.json"
    if check.is_file():
        p = load(check)
        a, b = p["primary"], p["supplement"]
        lines += [
            "`paper_scan_universe.php`는 `KrAmountScanner`에 `data/raw/cache`를 넘기고, 스캐너는 그 아래 `sector`에서 업종을 읽는다. `data/cache/sector`는 다른 캐시다. 업종명 `기타`는 분류로 세지 않는다. 두 캐시 모두 쓰지 않았다.",
            "",
            f"- 운영 스캐너 경로 `{a['path']}`: 유효 업종명 {a['counts']['secured']}건, 자리표시자 {a['counts']['placeholder']}건, 파일 없음 {a['counts']['missing']}건.",
            f"- 다른 캐시 `{b['path']}`: 유효 업종명 {b['counts']['secured']}건, 자리표시자 {b['counts']['placeholder']}건, 파일 없음 {b['counts']['missing']}건.",
            f"- 보완으로 채운 종목 {len(p['supplement_used'])}건, 업종명이 서로 다른 종목 {len(p['conflicts'])}건.",
            "- 유효 업종명이 없어 연구용 섹터 맵은 바꾸지 않았고, 소규모 계좌도 다시 돌리지 않았다. 운영 캐시는 쓰지 않았다.",
            "",
        ]
    (BASE / "followup.md").write_text("\n".join(lines), encoding="utf-8", newline="\n")
    print("written", file_hash)


if __name__ == "__main__":
    main()
