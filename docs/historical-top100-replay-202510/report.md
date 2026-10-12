# KRX 일별 거래대금 TOP100 연구 — 2025년 10월 18세션

표기: **KRX 일별 거래대금 TOP100 연구**. 운영 다음 실시간 TOP100과 같다고 보지 않습니다. 한 달 실행으로 수익성이 검증됐다고 보지 않습니다. 아래 금액은 장부가 맞는지 보기 위한 값이며 성과 해석에 쓰지 않습니다.

운영 계좌 재실행은 하지 않았습니다. 운영 수정·전략 지문 변경·full-v2 덮어쓰기·1년 확장은 하지 않았습니다. 의존: [PR #73](https://github.com/derod007/stock_personal/pull/73).

## 결론

2025년 10월 확보된 **18세션**만, 별도 연구 계좌 `hist-kr-dated-top100-v1-202510`에서 기존 `paper-kr-recovery-v1` 규칙으로 돌렸습니다. 신규 진입 후보는 **그날 TOP100만** 썼고, 합집합 305종목을 매일 후보로 쓰지 않았습니다. TOP100에서 빠져도 보유·대기 주문은 기존 규칙으로 남았습니다. 계좌는 멈추지 않았고, 월말 보유 4건은 강제 청산하지 않았습니다.

## 입력 검증

18일 선정 파일과 양쪽 시장 원본을 다시 읽었습니다. 일자별 100종·코드 중복 없음·대금 내림차순, 원본 `BAS_DD`=요청일, 합집합 **305**. 이름 변경 0. 누락 날짜를 다른 날 목록이나 고정 종목군으로 채우지 않았습니다. PR #73 `evidence.json`에 고정한 선정·원본 해시와 비교하며, 기준 항목이 없으면 통과시키지 않습니다.

Git 후보 파일은 LF이고 실행 기록 `candidates_sha256`은 CRLF 바이트 해시입니다. 내용은 같고 줄바꿈만 다릅니다. 기존 장부 값은 그대로 두고, LF를 CRLF로 바꾼 해시가 기록과 같은지로 연결합니다.

Yahoo 심볼이 `숫자6.(KS|KQ)`가 아닌 2종(`0008Z0.KQ`, `45014K.KS`)은 수집하지 않고 `identity_hold`로 남겼습니다. TOP100에서 빼 101위로 보충하지 않았습니다.

## 일봉

연구 전용 `history-research/kr-dated-amount-top100-202510`. 기존 `kr-saved-scan-20261009`/`20251008`에서 100종 봉을 복사했고, 부족분 198종은 Yahoo `5y`로 받았습니다. 원본 데이터셋은 덮어쓰지 않았습니다.

| 항목 | 값 |
|---|---|
| 요청 대상 | 303 (identity_hold 2 제외) |
| 기존 연구 봉 재사용 | 100 |
| 신규 수집 | 198 |
| Yahoo 404 | 5 (`008500.KS`, `010620.KS`, `012510.KS`, `042670.KS`, `450140.KS`) |
| 시간 | 289초 |
| 요청 | 198 |
| 봉 파일 | 약 43.7MB |
| 데이터셋 품질 보류 | 28 |
| 경고 포함 통과 | 270 |

준비 구간: 엔진 품질 최소 60봉, 엔벨로프 MA240. 기준일 2025-10-31, 평가 시작 2025-10-01, 범위 `5y`. 봉이 없거나 품질에 막힌 이름은 그날 분석 불가로 세고 101위로 채우지 않습니다. 가격은 지금 받은 공급자 가격이며 당시 수정주가와 같다는 보장은 없습니다.

## 후보 연결

- 신규 진입: 해당 세션 TOP100만 `ChartPlanEngine`에 넣음.
- 보유·대기: TOP100 밖이어도 봉·품질 검사를 하고, 신규 신호는 `not_in_dated_top100`.
- 신호는 당일 완료봉만. 주문 체결은 기존처럼 다음 세션 봉부터.
- 우선순위·손절·목표·비용·현금·보유·위험·섹터 한도는 `paper-kr-recovery-v1` 그대로.
- 섹터: 현재 네이버 모바일 업종 JSON → `SectorMap::bucketOf`. **당시 업종을 복원한 것이 아닙니다.** 확보 298, 미확인 7은 하나의 `unclassified`.

## 검증

`php tests/dated_top100_replay.php` 통과. 대기 주문이 TOP100에서 빠진 뒤 체결되는 경우와, 빠진 뒤 3봉 미체결로 만료되는 경우를 명시적으로 검증했습니다. 실데이터 스모크 3세션(01·02·10) 정지 없음. 같은 3세션을 나눠 실행한 저널은 계좌 ID를 빼면 일괄 실행과 이벤트가 같습니다. 18세션을 다시 실행하면 처리 세션 0, 장부 대조 통과. 운영 상태 폴더 상위 파일과 데이터셋 트리는 실행 전후 같습니다.

보유 종목 봉 누락이 나오면 기존처럼 계좌를 멈춥니다. 이번 18세션에서는 발생하지 않았습니다.

## 18세션 장부 (성과 해석 금지)

초기 1억원, 기존 보유·주문 없음.

| 항목 | 값 |
|---|---|
| 세션 | 18 / 정지 없음 |
| 일별 TOP100 | 항상 100 |
| 분석 가능 | 93–97 |
| 평가(TOP100+보유·대기) | 100–104 |
| 추천(ready) | 16 (주문 10, 보유한도 5, 현금·위험·섹터 1) |
| 주문 / 체결 / 취소 | 10 / 9 / 1 |
| 청산 | 5 (목표 3, 손절 2) |
| 월말 보유 / 대기 | 4 / 0 |
| 현금 | 82,755,490 |
| 총자산 | 101,622,380 |
| 실현손익 | 1,728,576 |
| 미실현(매도비용 후) | -125,063 |
| 종가 기준 최대낙폭 | 1.76% |

품질 차단 종목·세션: `insufficient_history` 82, `missing_session` 28. 101위로 보충하지 않았습니다.

장부 대조: 이벤트만으로 현금·예약·실현·수수료·평가를 다시 만들었고, 주문 10건을 `TradeSimulator`로 독립 재계산해 일치했습니다.

## 재현

인증키·원본 JSON은 Git에 없습니다. 봉은 `PAPER_STATE_DIR/history-research/kr-dated-amount-top100-202510`.

```
python docs/research/dated-amount-top100-replay.py --validate
php tests/dated_top100_replay.php
python -m unittest tests/test_dated_amount_top100_replay.py
php bin/paper_dated_top100_replay.php verify-inputs
php bin/paper_dated_top100_replay.php universe
php -d memory_limit=768M bin/paper_dated_top100_replay.php collect
php -d memory_limit=3G bin/paper_dated_top100_replay.php run --confirm-full-run=after-code-review --sector-map=docs/historical-top100-replay-202510/sector-map.json
php bin/paper_dated_top100_replay.php verify --dataset-dir=<state>/history-research/kr-dated-amount-top100-202510
```

전략 지문: `934012285f2ddb3867fff4576dba05fb7df290a36a987c36fc260e8917bbe465` (운영과 같음). 해시는 `account/provenance.json`. 후보 LF↔CRLF 연결은 `account/input-hash-link.json`.
