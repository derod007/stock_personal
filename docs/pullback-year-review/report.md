# KRX 일별 거래대금 TOP100 연구 — 상승 눌림 종료 42건 검토 자료

표기: **KRX 일별 거래대금 TOP100 연구**. 운영 다음 TOP100과 같다고 보지 않는다. 1년 실행으로 수익성이 검증됐다고 보지 않는다.

목적은 거래 수 확대나 전략 최적화가 아니다. 진입 당시 차트 구조와 코드 판정이 맞았는지 보기 위한 자료다. 이 작업에서 패턴의 좋고 나쁨, 새 필터, 손절·목표 변경을 제안하지 않는다.

성과 요약은 이미 알려져 있으므로 완전한 블라인드가 아니다. 첫 화면에는 손익을 넣지 않았다.

## 의존

- 거래 장부: 병합된 [PR #76](https://github.com/derod007/stock_personal/pull/76)
- [PR #77](https://github.com/derod007/stock_personal/pull/77)은 **미병합**(OPEN). 분석 참고만 했다. 이 자료는 #77을 병합하지 않고 #76 저장 `trades.json`에서 42건을 직접 추출했다.

## 목록

저장 `docs/historical-top100-replay-20251008-20261008/account/trades.json`에서 `pattern=trend_pullback_v1` 이고 `status=closed` 인 행 **42건 전부**. 이익·손실로 고르지 않았다. 순서는 신호일·종목 코드.

- 입력 해시(trades.json): `6399c91be22939312f5c9d70dbd1dbd1fb758a1100475c3ff1c316b44f69d4b5`
- 고정 목록: `list.json` (`18ac0f7f091ad1bf704e864d0890eecf5c1751027be98c36d7f570406c3ff1cf`)
- 데이터셋: `kr-dated-amount-top100-20251008-20261008-v2` (`dataset.json` `2151bc45…`)
- 전략 지문: `934012285f2ddb3867fff4576dba05fb7df290a36a987c36fc260e8917bbe465`

화면: `index.html` → 각 사례 A. A에서만 B(코드 판정)·C(이후 가격·저장 체결)를 연다.

## 화면

- **A:** 신호일까지 완료 일봉 120·240, 거래량, MA20·MA60. 이후 봉·성과·코드 판정 없음. 사람이 고른 눌림 고점·저점 없음.
- **B:** 당시 `ChartPlanEngine`의 상승 구조, 눌림·확인 게이트, 코드가 쓰는 고정 창(추세 20+20, 근접 5, 손절 10, 목표 마지막 봉 제외 20, 거래량 20대 3), 진입·손절·목표·손익비, 공통 위험·품질. 눌림 시작 좌표는 코드가 저장하지 않아 `unrecorded`.
- **C:** 이후 가격과 저장된 주문·체결·청산·비용·손익. 계좌 재실행 없음.

B 재계산은 원래 연구와 같은 전략·설정·v2 저장 봉, 신호일까지 완료봉만 쓴다. 저장 결과와 불일치하면 고치지 않고 `json/b/<id>.json`의 `diffs`에 남긴다. 이번 42건의 diffs는 비어 있다. 이 재계산은 **같은 엔진의 재실행**이며 독립 전략 구현이 아니다.

차트는 저장 일봉으로 `PaperReviewCharts`가 그린다. 추가 가격 수집 없음.

## 가격 ZIP

원본 KRX JSON이 아니다. 검토용으로 잘라 낸 신호일까지 완료 일봉(A-input)이다. Git에 넣지 않았다.

- 경로: `C:\Users\acdun\Desktop\dev\stock-personal-paper\pullback-year-review-prices.zip`
- 바이트: 996494
- SHA256: `870bcd66f231b8cecdc62f0931cc94d91924947c439e3e8a6fad6b1527f1f957`
- 내용: `list.json` + `json/a/<case_id>-input.json` 42개

로컬 데이터셋이 없으면 다음만 필요하고 임의로 대체하지 않는다.

`C:\Users\acdun\Desktop\dev\stock-personal-paper\history-research\kr-dated-amount-top100-20251008-20261008-v2`

## 재현

```
php tests/pullback_year_review.php
php bin/paper_pullback_year_review.php extract
php -d memory_limit=3G bin/paper_pullback_year_review.php build
php -d memory_limit=3G bin/paper_pullback_year_review.php verify
php bin/paper_pullback_year_review.php zip
```

운영 src/config/계좌/캐시/전략 지문/원본/기존 연구 결과는 바꾸지 않았다.
