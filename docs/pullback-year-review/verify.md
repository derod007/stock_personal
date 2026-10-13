# 상승 눌림 종료 42건 검토 검증

기계 검증만 적는다. 패턴의 좋고 나쁨, 새 필터, 손절·목표 변경은 적지 않는다.

## 결과

`php -d memory_limit=3G bin/paper_pullback_year_review.php verify` → **pass**.

- 사례 42 / 누락 0 / 문제 0
- 저장 `trades.json` SHA256 `6399c91be22939312f5c9d70dbd1dbd1fb758a1100475c3ff1c316b44f69d4b5`
- 데이터셋 `dataset.json` SHA256 `2151bc4531ae4d39a1fa60010ee5d9f76727c12a98880759c652bea44c6a5fec`
- 목록은 날짜·종목 순. 이익·손실로 고르거나 그 순으로 정렬하지 않음
- 첫 화면(`index.html`)에 손익 필드 없음. “완전한 블라인드”라는 표현 없음
- A 화면·가격 파일에 패턴명·진입·손절·목표·성과 키 없음
- A 차트 120·240봉을 저장 일봉으로 다시 그려 바이트 일치. 신호일 이후 봉 없음. 날짜 눈금 있음
- 거래량 막대 높이는 최대 대비 비례 (`volumeHeight`)
- B는 같은 `ChartPlanEngine`으로 신호일까지 완료봉만 다시 계산. 저장 진입·손절·목표와 불일치 0건. 독립 전략 구현이 아님
- B는 눌림 시작 고점·저점을 정답처럼 표시하지 않음 (`pullback_interval_start=unrecorded`)
- C는 저장 체결가·청산 손익과 일치. 계좌 재실행 없음
- A→B, A→C 링크 42건

## 저장 로그 대조와 엔진 재계산

| 구분 | 내용 |
|---|---|
| 저장 로그 | `docs/historical-top100-replay-20251008-20261008/account/trades.json`의 종료 42건 |
| 엔진 재계산 | 원래 연구와 같은 전략 지문·v2 저장 봉·신호일까지 완료봉만 `ChartPlanEngine` |
| 독립 구현 | 아님 |

단위 시험: `php tests/pullback_year_review.php`
