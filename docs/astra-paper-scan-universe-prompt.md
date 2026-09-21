# Astra 프롬프트 — 모의계좌는 고정 종목이 아니라 당일 스캔 추천으로 매매

아래를 그대로 붙여 넣으면 됩니다.

---

작업 시작 전 `docs/work-log.md` 맨 위를 읽고, `진행`이면 그 작업을 덮지 마라. 끝나면 맨 위에 끝 항목을 추가한다. 설계 설명은 쓰지 말고 한 일과 파일만 적는다. 커밋하지 마라. 한국어로 짧게 보고한다.

## 하려는 것

한국 **실제 모의 매매 계좌**(`paper-kr`)를 돌릴 때 종목을 3개·10개로 고정하지 마라.

매일 한 번 **거래대금 스캔**(TOP100)을 돌리고, 거기서 나온 **추천**으로만 신규 매수를 검토하고, 보유는 기존 규칙대로 매도·손절·목표가 처리한다.

예전 3종목 지정, 연구용 고정 10종목(`research-kr-v1` / `config/paper-research-kr-v1.json`)으로 모의 매매하지 마라. 그 연구 계좌는 이 작업에서 건드리지 말고, 실매매 모의 경로에 넣지도 마라.

## 동작

1. 20:20 한국 모의는 `python bin/paper_daily.py --config=config/paper-kr.json` 이다. 연구 래퍼(`paper_research_daily.py`, `paper_entry_daily.py --config=paper-research-kr-v1.json`)로 바꿔 쓰지 마라.
2. `config/paper-kr.json`은 `universe: kr_amount_scan`, `scan_limit: 100`, `symbols: {}` 를 유지한다. 삼성전자·하이닉스 같은 심볼을 config에 다시 넣지 마라.
3. 스캔 1회 후 `entry_recommend`(확인·`order_ready`) 또는 `buy_now`인 종목만 신규 후보로 계좌에 넣는다. 이미 구현은 `src/PaperScanUniverse.php` + `bin/paper_scan_universe.php` 다. 빠져 있으면 이 경로로 맞춰라.
4. 이미 보유 중인 종목은 오늘 스캔에 없어도 평가·매도 대상으로 남긴다.
5. 추천 0건이고 보유가 없으면 빈 유니버스가 정상이다. 그때 고정 10종목으로 폴백하지 마라.
6. 매수·매도 가격/손절/목표/한도/수수료 규칙은 기존 `paper-kr`과 같다. 진입 조건(거래량 85→95, 확인대기 매수 등)을 이 작업에서 완화하지 마라.

미국 `paper-us` 고정 심볼, PR16 거래량 실험, 스캔 화면 «어제 스캔 → 오늘» 위젯은 이 작업이 아니다.

## 확인할 것

- 예약 작업/문서/README가 한국 모의를 `paper-kr` 스캔으로 안내하는지. `research-kr-v1`로 안내하면 `paper-kr`로 고친다.
- `tests/v14.php`, `tests/test_paper_daily.py` 통과. 스캔 유니버스가 추천만 고르고, 설정 해시가 일별 심볼에 안 묶이는지.
- 가능하면 스캔 캐시로 `paper_scan_universe.php --config=config/paper-kr.json --cache` 한 번 돌려 후보 목록이 고정 10종이 아닌지 본다.

## 하지 말 것

- 연구용 10종목을 모의 매수 유니버스로 쓰지 말 것
- 전체 KOSPI/KOSDAQ 전종목 스캔하지 말 것 (거래대금 TOP100)
- 커밋·푸시·PR 생성하지 말 것
