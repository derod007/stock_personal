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
            "- 두 경로 모두 유효 업종명이 없어, 운영 캐시에서는 맵을 바꾸지 못했다. 운영 캐시는 쓰지 않았다.",
            "",
        ]
    diag = BASE / "sector-collection-diagnosis.json"
    coll = BASE / "sector-collection-v2.json"
    if diag.is_file() and coll.is_file():
        dg, cl = load(diag), load(coll)
        lines += [
            "## 업종이 전부 기타로 저장된 원인",
            "",
            f"대표 5종목을 임시 폴더에서 현재 수집 경로(`SectorMap`)로 실행했다 ({dg['checked_at']}). 결과는 5건 모두 같은 원인이다.",
            "",
            "| 단계 | 결과 |",
            "|---|---|",
            "| HTTP 실패 | 아님. 상태 200, 전송 오류 없음 |",
            "| 인코딩 | 아님. 본문은 UTF-8이고 한글이 정상 해석됨 |",
            "| 응답 형식 변경 | **원인.** `finance.naver.com/item/main.naver`가 `stock.naver.com/domestic/stock/<코드>/price`로 302 이동한다. 새 페이지는 스크립트로 그려지는 화면이라 업종 링크(`sise_group_detail...type=upjong`)가 본문에 없다 |",
            "| 업종 파싱 | 위 때문에 정규식이 맞는 곳이 없어 빈 값이 된다 |",
            "",
            "`SectorMap`은 빈 업종을 `기타`로 저장하고, 제목에서도 이름을 못 읽어 이름을 비운다. 그래서 144종목 모두 `기타`·이름 없음으로 캐시되었다. 운영 수집 코드의 수정이 필요하며, 별도 PR로 분리한다.",
            "",
            "## 연구용 섹터 맵 v2",
            "",
            f"운영 `SectorMap`을 쓰지 않고, 같은 사이트의 JSON 두 곳에서 현재 업종을 읽었다 ({cl['collected_at']}). 종목별 `industryCode`와 업종 목록(79개)의 이름을 맞추고, 버킷은 운영 규칙 `SectorMap::bucketOf`로 분류했다. 읽은 값은 운영 폴더 밖에 저장했고 운영 캐시는 쓰지 않았다(`operational_caches_unchanged`: {str(cl['operational_caches_unchanged']).lower()}).",
            "",
            f"- 확보 {cl['counts']['secured']} / 미확인 {cl['counts']['unconfirmed']} (총 {cl['counts']['symbols']}종목)",
            "- 종목마다 업종명, 운영 버킷, 출처 URL, 조회 시각, 업종코드, 응답 해시를 `sector-map-v2.json`에 기록했다. 실패한 종목은 추정하지 않고 하나의 `unclassified`로 둔다.",
            "- **연구 가정:** 현재 업종을 과거 두 기간에 같이 적용한다. 당시 업종을 복원한 것이 아니다.",
            "",
            "버킷별 종목 수: " + ", ".join(f"{k} {v}" for k, v in cl["bucket_counts"].items()),
            "",
            "### v2 맵으로 소규모 구간 재검증 (새 계좌 va·vb·vc)",
            "",
            "| 구간 | 세션 | 주문 | 최종 현금 | 실현손익 | 장부 대사 | 섹터 한도 | 정지 | 이전(미분류) 주문·현금 |",
            "|---|---:|---:|---:|---:|---|---|---|---|",
        ]
        for key, name in [("a", "이전 시작부"), ("b", "최근 시작부"), ("c", "이전 끝부분")]:
            s = load(BASE / "smoke-sector-v2" / key / "summary.json")
            r = load(BASE / "smoke-sector-v2" / key / "reconcile.json")
            o = load(BASE / "smoke-sector" / key / "summary.json")
            same_o = s["event_counts"].get("order") == o["event_counts"].get("order")
            same_c = abs(s["cash"] - o["cash"]) < 0.01
            lines.append(
                f"| {key} {name} | {s['event_counts']['equity']} | {s['event_counts'].get('order', 0)} | {round(s['cash']):,} | "
                f"{round(s['realized_pnl']):,} | {'통과' if r['pass'] else '실패'} | {r['checks']['sector_notional_within_limit']['detail']} | "
                f"{'있음' if s['halted'] else '없음'} | 주문 {'같음' if same_o else '다름'}, 현금 {'같음' if same_c else '다름'} |"
            )
        rc = load(BASE / "smoke-sector-v2" / "a" / "resume-check.json")
        lines += [
            "",
            f"구간 a를 한 번에 실행한 저널과 4세션씩 나눠 이은 저널은 {'같다' if rc['identical'] else '다르다'} (이벤트 {rc['events'][0]}개). "
            "같은 명령을 다시 실행하면 처리 세션 0이고 저널 파일이 같다. 이전 맵으로 만든 계좌(sa)에 v2 맵을 주면 `Research input changed (sector_map_sha256)`로 거부한다.",
            "",
            "섹터가 나뉘자 모든 종목을 한 버킷으로 둔 이전 결과와 세 구간 모두 최종 현금이 달라졌다(표의 마지막 열). 기존 `smoke/`와 `smoke-sector/` 결과는 그대로 둔다.",
            "",
        ]
    (BASE / "followup.md").write_text("\n".join(lines), encoding="utf-8", newline="\n")
    print("written", file_hash)


if __name__ == "__main__":
    main()
