# KRX 일별 거래대금 TOP100 연구 — 2025-10-10~2026-10-08

> 주문 감사 보완: 기존 pattern 존재 검사는 최종 추천 증명이 아니었습니다. 새 저장 로그 대조·동일 엔진 재계산 검증 및 로컬 실행 안내는 [order-audit-review.md](order-audit-review.md)를 보세요. 기존 68건의 새 엔진 재검증은 아직 대기 중입니다.

표기: **KRX 일별 거래대금 TOP100 연구**. 운영 다음 실시간 TOP100과 같다고 보지 않습니다. 1년 실행으로 수익성이 검증됐다고 보지 않습니다. 아래 금액은 장부가 맞는지 보기 위한 값이며 성과 해석에 쓰지 않습니다.

운영 계좌 재실행은 하지 않았습니다. 운영 수정·전략 지문 변경·full-v2 덮어쓰기·기존 10월 연구 계좌 덮어쓰기는 하지 않았습니다. 의존: 병합된 [PR #75](https://github.com/derod007/stock_personal/pull/75) (`ab88384`).

## 결론

확보된 **244세션**(2025-10-10~2026-10-08)만, 별도 연구 계좌 `hist-kr-dated-top100-v1-20251008-20261008`에서 기존 `paper-kr-recovery-v1` 규칙으로 돌렸습니다. 신규 진입 후보는 **그날 TOP100만** 썼고, 합집합 933종목을 매일 후보로 쓰지 않았습니다. TOP100에서 빠져도 보유·대기 주문은 기존 규칙으로 남았습니다. 계좌는 멈추지 않았고, 기간 말 보유 3건은 강제 청산하지 않았습니다.

데이터셋 `kr-dated-amount-top100-20251008-20261008-v2`. 섹터 맵 `docs/historical-top100-20251008-20261008/sector-map-full-v2.json`(SHA256 `08afb0b6f0be0106624364406d29a5a33851c65331e431467629f71600da1e8b`). 31종 부분 맵은 거부됩니다.

## 10월 도구 확장

기존 한 달 재현 CLI에 `--profile=oct2025`(기본)와 `--profile=year-20251008-20261008`을 넣었습니다. 10월 입력으로 새 스모크 계좌 `…-octrepro`를 18세션 돌렸을 때 현금·총자산·주문·체결·보유가 기존 10월 장부와 같았습니다. 같은 날짜를 다시 돌리면 처리 세션 0. 기존 10월 계좌 파일은 쓰지 않았습니다.

## 입력 검증

244일 선정 파일, 일자별 100종, 합집합 933. 데이터셋 검증 통과. 누락 심볼 7, 데이터셋 품질 보류 92 — 기간 전체에서 빼지 않고 해당 세션만 분석 불가로 뒀습니다. 전략 지문 `934012285f2ddb3867fff4576dba05fb7df290a36a987c36fc260e8917bbe465`. 전체 섹터 맵이 합집합 933과 키가 같고, 부분 맵은 해시 불일치로 거부됩니다.

## 후보 연결

- 신규 진입: 해당 세션 TOP100만 `ChartPlanEngine`에 넣음.
- 보유·대기: TOP100 밖이어도 봉·품질 검사를 하고, 신규 신호는 `not_in_dated_top100`.
- 신호는 당일 완료봉만. 주문 체결은 기존처럼 다음 세션 봉부터.
- 우선순위·손절·목표·비용·현금·보유·위험·섹터 한도는 `paper-kr-recovery-v1` 그대로.
- 섹터: 현재 네이버 모바일 업종 JSON. **당시 업종을 복원한 것이 아닙니다.** 미확인 7은 하나의 `unclassified`.

## 검증

`php tests/dated_top100_replay.php` 통과. 3세션(2025-10-10·13·14) 나눠 실행과 일괄 실행의 정규화 이벤트가 같습니다. 244세션을 다시 실행하면 처리 세션 0. 장부 대조 통과(현금·수수료·실현·평가·독립 시뮬레이터 68주문). 신규 주문 68건 모두 신호일 TOP100·최종 추천. 운영 상태 상위 파일과 v2 데이터셋 트리는 실행 전후 같습니다. 미래봉은 신호 단계에 넣지 않습니다.

보유 종목 봉 누락이 나오면 기존처럼 계좌를 멈춥니다. 이번 244세션에서는 발생하지 않았습니다.

## 244세션 장부 (성과 해석 금지)

초기 1억원, 기존 보유·주문 없음.

| 항목 | 값 |
|---|---|
| 세션 | 244 / 정지 없음 |
| 일별 TOP100 | 항상 100 |
| 분석 가능 | 88–100 |
| 평가(TOP100+보유·대기) | 100–104 |
| 추천(ready) | 103 (주문 68, 보유한도 28, 이미 보유·대기 5, 현금·위험·섹터 2) |
| 주문 / 체결 / 취소 | 68 / 59 / 9 |
| 청산 | 56 (목표 19, 손절 32, 시간 5) |
| 기간 말 보유 / 대기 | 3 / 0 |
| 현금 | 72,010,239 |
| 총자산 | 112,430,309 |
| 실현손익 | 12,396,872 |
| 미실현(매도비용 후) | -6,983 |
| 종가 기준 최대낙폭 | 9.83% |

품질 차단 종목·세션: `insufficient_history` 793, `missing_session` 83, `invalid_recent_ohlcv` 48. 101위로 보충하지 않았습니다.

장부 대조: 이벤트만으로 현금·예약·실현·수수료·평가를 다시 만들었고, 주문 68건을 `TradeSimulator`로 독립 재계산해 일치했습니다.

기간 말 보유: `108490.KQ`, `217590.KS`, `028050.KS`. 강제 청산하지 않았습니다.

## 재현

인증키·원본 JSON은 Git에 없습니다. 봉은 `PAPER_STATE_DIR/history-research/kr-dated-amount-top100-20251008-20261008-v2`.

```
php tests/dated_top100_replay.php
php -d memory_limit=3G bin/paper_dated_top100_replay.php validate --profile=year-20251008-20261008
php -d memory_limit=3G bin/paper_dated_top100_replay.php run --profile=year-20251008-20261008 --confirm-full-run=after-code-review
php -d memory_limit=3G bin/paper_dated_top100_replay.php verify --profile=year-20251008-20261008
php bin/paper_dated_top100_replay.php status --profile=year-20251008-20261008
```

10월 기본 프로필:

```
php -d memory_limit=3G bin/paper_dated_top100_replay.php run --profile=oct2025 --smoke-label=octrepro --start=2025-10-01 --through=2025-10-31 --sector-map=docs/historical-top100-replay-202510/sector-map.json
```

전략 지문: `934012285f2ddb3867fff4576dba05fb7df290a36a987c36fc260e8917bbe465`. 해시는 `account/provenance.json`.
