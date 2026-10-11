# 연구 계좌 체결 가정 검토

기존 장부 대사와 TradeSimulator 재호출은 이미 통과한 상태입니다. 이번 검사는 저장된 주문과 원본 일봉을 직접 대조합니다. 시뮬레이터를 다시 돌려 같은 숫자를 확인하는 검사가 아닙니다.

## 확인된 오류와 일봉으로 확정할 수 없는 가정

**확인된 오류: 0건.** 저장 결과와 독립 경로(`independent_path`)의 체결일·청산일·가격·사유·취소일·취소 사유가 123건 모두 같았습니다. 가격과 손익을 함께 바꾼 사본은 `model_match`에서 걸립니다. 기존 full-v2는 덮어쓰지 않았습니다.

**일봉으로 확정할 수 없는 가정: 주문 9건.** 모형과 저장 값은 같으나 그 가격이 당일 [저가, 고가] 밖입니다. 고가 밖 진입 3건, 저가 밖 청산 6건. 운영 엔진을 바꿔 이 가격을 없애지 않았습니다.

아래 날짜는 **청산일 또는 체결일**입니다. 신호일이 아닙니다.

고가 밖 진입 (체결일):
- recent `005935.KS` 체결일 2025-10-28 (신호일 2025-10-27): 저장 79539.75, 당일 H 79500.0, 모형도 범위 밖=True
- recent `203650.KQ` 체결일 2026-05-20 (신호일 2026-05-19): 저장 3261.6299999999997, 당일 H 3260.0, 모형도 범위 밖=True
- recent `028050.KS` 체결일 2026-09-04 (신호일 2026-09-03): 저장 46073.024999999994, 당일 H 46050.0, 모형도 범위 밖=True

저가 밖 청산 (청산일):
- prior `079550.KS` 청산일 2025-02-12 사유 target (신호일 2025-01-24): 저장 268500.0, 당일 L 271000.0, 모형도 범위 밖=True
- prior `393210.KQ` 청산일 2025-06-13 사유 stop (신호일 2025-06-09): 저장 6676.66, 당일 L 6680.0, 모형도 범위 밖=True
- recent `068270.KS` 청산일 2025-12-26 사유 stop (신호일 2025-12-08): 저장 171373.2705, 당일 L 171428.578125, 모형도 범위 밖=True
- recent `036540.KQ` 청산일 2026-01-29 사유 target (신호일 2026-01-26): 저장 6530.0, 당일 L 6580.0, 모형도 범위 밖=True
- recent `005490.KS` 청산일 2026-04-28 사유 target (신호일 2026-04-08): 저장 427500.0, 당일 L 441000.0, 모형도 범위 밖=True
- recent `000500.KS` 청산일 2026-06-08 사유 stop (신호일 2026-06-01): 저장 145760.411461, 당일 L 145833.328125, 모형도 범위 밖=True

- 손절·시간 청산의 5bp 슬리피지는 시가 또는 종가보다 낮아질 수 있고, 그 값이 당일 저가보다 낮을 수 있습니다.
- 목표가 갭(다음 세션 시가≥목표)은 목표 지정가로 나가므로 당일 저가보다 낮을 수 있습니다.
- 시가×1.0005 진입은 당일 고가보다 높을 수 있습니다.
- 같은 봉 손절·목표 순서는 일봉으로 확정하지 않습니다.
- 미래봉 항목은 날짜를 신호일 이전/이후로 나눈 확인입니다. ChartPlanEngine 입력 배열을 재구성하지 않았습니다.
- 데이터셋 가격 기준은 `yahoo_chart_quote_ohlcv_daily_adjustment_unverified`입니다.
- 자료 부족: 주문 0건, 검사 항목 0건.

주문별 최악 판정: 통과 114, 오류 0, 가정 의존 9, 자료 부족 0. 검사 항목 합계 1599 (통과 1590 / 오류 0 / 가정 의존 9 / 자료 부족 0).

## 건수

원본 `trades.json` 합계: 주문 123, 체결 110 (종료 105 + 보유 5), 취소 12, 대기 1. 기대 123건과 같습니다.

기대 123건은 이전 56 + 최근 67입니다. summary의 order/fill/exit/cancel 칸과 같습니다.

## 규칙과 확인 범위

| 항목 | 코드 | 독립 확인 | 범위 |
|---|---|---|---|
| 기존 모형과 일치 | `independent_path` | 체결일·가격, 청산일·가격·사유, 취소일·사유 | 저장된 값이 모형과 같은지. 일봉 안에 있는지는 아님 |
| 일봉 범위 | 해당 세션 OHLC | 저장 가격과 모형 가격이 [저가, 고가] 안인지 | 모형도 범위 밖이면 `model_also_outside` |
| 신호 날짜 구분 | `AccountReplay.php` 214–222행은 종가 이하 봉만 계획에 씀 | 신호일 봉 존재, 체결·청산일이 신호일 이후 | **날짜 구분만.** 엔진 입력을 검증한 것이 아님 |
| 날짜 순서·최초 체결 | TTL 3 | 지정가에 닿는 첫 세션 | 장중 시각은 모름 |
| 주문 유형 | 매수 지정가 | 체결가 ≤ 지정가 | 지정가 위 매수는 오류 |
| 갭 | 진입 `min(limit, open×1.0005)`, 손절 `min(stop,open)×0.9995`, 이후 시가≥목표는 목표가 | 모형 가격 | 5bp와 목표 지정가는 가정 |
| 진입 당일 청산 | 체결 봉에서도 손절·목표 | 체결일과 청산일 | 같은 봉 경로 불명 |
| 같은 봉 충돌 | 이후 시가≥목표면 목표, 아니면 손절 | OHLC가 둘 다 닿았는지 | 장중 순서는 가정 |
| 수수료 | 현금 10bp×2, 슬리피지는 가격 안 | `fee`와 `net_pnl` | 중복 차감 여부 |
| 마지막 보유·대기 | 강제 청산 없음 | 종료 105, 보유 5, 대기 1 | 마지막 날 이후 봉은 쓰지 않음 |

## 상세 사례

합집합 49건 (기간별 순이익 상위 5 + 갭 진입/손절 + 범위 밖 체결 + 당일 청산 + 검증 예외, 중복 제거). 차트는 원본 봉입니다. 파란 구간은 신호일 종가까지, 주황 구간은 그 이후 체결입니다. 날짜 칸을 신호일·체결일·청산일(또는 취소일)로 나눠 적습니다. 신호일은 종가 판정일입니다.

| 사례 | 태그 | 신호일 | 체결일 | 청산일·취소일 | 진입가·청산가 | 모형일치 | 범위 | 차트 |
|---|---|---|---|---|---|---|---|---|
| prior `267260.KS` | gap_entry, top_win | 2024-12-03 | 2024-12-04 | 2025-01-03 | 346,173.00 / 399,800.00 | pass | 진입 inside, 청산 inside | [charts/prior-267260.KS-2024-12-03.svg](charts/prior-267260.KS-2024-12-03.svg) |
| prior `010170.KQ` | gap_entry, top_win | 2025-01-16 | 2025-01-17 | 2025-02-04 | 822.41 / 1,039.00 | pass | 진입 inside, 청산 inside | [charts/prior-010170.KQ-2025-01-16.svg](charts/prior-010170.KQ-2025-01-16.svg) |
| prior `079550.KS` | exit_below_low, model_outside_tape | 2025-01-24 | 2025-01-31 | 2025-02-12 | 230,500.00 / 268,500.00 | pass | 진입 inside, 청산 below_low | [charts/prior-079550.KS-2025-01-24.svg](charts/prior-079550.KS-2025-01-24.svg) |
| prior `203650.KQ` | gap_entry | 2025-01-31 | 2025-02-03 | 2025-02-28 | 1,740.87 / 1,689.15 | pass | 진입 inside, 청산 inside | [charts/prior-203650.KQ-2025-01-31.svg](charts/prior-203650.KQ-2025-01-31.svg) |
| prior `006340.KS` | gap_entry | 2025-02-13 | 2025-02-14 | 2025-02-24 | 3,441.72 / 3,259.37 | pass | 진입 inside, 청산 inside | [charts/prior-006340.KS-2025-02-13.svg](charts/prior-006340.KS-2025-02-13.svg) |
| prior `267270.KS` | gap_entry, gap_stop | 2025-02-26 | 2025-02-27 | 2025-03-04 | 76,238.10 / 69,965.00 | pass | 진입 inside, 청산 inside | [charts/prior-267270.KS-2025-02-26.svg](charts/prior-267270.KS-2025-02-26.svg) |
| prior `092870.KQ` | gap_stop | 2025-03-05 | 2025-03-06 | 2025-03-11 | 12,620.00 / 11,394.30 | pass | 진입 inside, 청산 inside | [charts/prior-092870.KQ-2025-03-05.svg](charts/prior-092870.KQ-2025-03-05.svg) |
| prior `005930.KS` | gap_entry | 2025-03-27 | 2025-03-28 | 2025-03-31 | 60,730.35 / 59,470.25 | pass | 진입 inside, 청산 inside | [charts/prior-005930.KS-2025-03-27.svg](charts/prior-005930.KS-2025-03-27.svg) |
| prior `452190.KQ` | gap_entry, same_day_exit | 2025-03-28 | 2025-03-31 | 2025-03-31 | 5,802.90 / 5,577.21 | pass | 진입 inside, 청산 inside | [charts/prior-452190.KQ-2025-03-28.svg](charts/prior-452190.KQ-2025-03-28.svg) |
| prior `064350.KS` | same_day_exit | 2025-03-31 | 2025-04-02 | 2025-04-02 | 105,000.00 / 97,801.07 | pass | 진입 inside, 청산 inside | [charts/prior-064350.KS-2025-03-31.svg](charts/prior-064350.KS-2025-03-31.svg) |
| prior `272210.KS` | gap_entry | 2025-05-16 | 2025-05-19 | 2025-06-09 | 46,573.27 / 42,224.88 | pass | 진입 inside, 청산 inside | [charts/prior-272210.KS-2025-05-16.svg](charts/prior-272210.KS-2025-05-16.svg) |
| prior `203650.KQ` | gap_entry, top_win | 2025-05-20 | 2025-05-21 | 2025-06-19 | 1,753.38 / 1,994.00 | pass | 진입 inside, 청산 inside | [charts/prior-203650.KQ-2025-05-20.svg](charts/prior-203650.KQ-2025-05-20.svg) |
| prior `393210.KQ` | exit_below_low, model_outside_tape | 2025-06-09 | 2025-06-10 | 2025-06-13 | 7,440.00 / 6,676.66 | pass | 진입 inside, 청산 below_low | [charts/prior-393210.KQ-2025-06-09.svg](charts/prior-393210.KQ-2025-06-09.svg) |
| prior `079550.KS` | gap_entry, same_day_exit | 2025-06-19 | 2025-06-24 | 2025-06-24 | 576,288.00 / 554,814.45 | pass | 진입 inside, 청산 inside | [charts/prior-079550.KS-2025-06-19.svg](charts/prior-079550.KS-2025-06-19.svg) |
| prior `071050.KS` | gap_entry, top_win | 2025-06-20 | 2025-06-23 | 2025-07-11 | 131,865.90 / 159,400.00 | pass | 진입 inside, 청산 inside | [charts/prior-071050.KS-2025-06-20.svg](charts/prior-071050.KS-2025-06-20.svg) |
| prior `419050.KQ` | gap_entry | 2025-06-25 | 2025-06-26 | 2025-07-02 | 1,760.88 / 1,680.16 | pass | 진입 inside, 청산 inside | [charts/prior-419050.KQ-2025-06-25.svg](charts/prior-419050.KQ-2025-06-25.svg) |
| prior `080220.KQ` | gap_entry | 2025-07-08 | 2025-07-09 | 2025-08-01 | 15,687.84 / 14,213.89 | pass | 진입 inside, 청산 inside | [charts/prior-080220.KQ-2025-07-08.svg](charts/prior-080220.KQ-2025-07-08.svg) |
| prior `071050.KS` | gap_entry | 2025-07-11 | 2025-07-14 | 2025-07-18 | 157,378.65 / 147,433.25 | pass | 진입 inside, 청산 inside | [charts/prior-071050.KS-2025-07-11.svg](charts/prior-071050.KS-2025-07-11.svg) |
| prior `005830.KS` | gap_entry | 2025-08-05 | 2025-08-06 | 2025-09-03 | 124,362.15 / 136,531.70 | pass | 진입 inside, 청산 inside | [charts/prior-005830.KS-2025-08-05.svg](charts/prior-005830.KS-2025-08-05.svg) |
| prior `082740.KS` | top_win | 2025-08-08 | 2025-08-11 | 2025-08-29 | 39,900.00 / 48,100.00 | pass | 진입 inside, 청산 inside | [charts/prior-082740.KS-2025-08-08.svg](charts/prior-082740.KS-2025-08-08.svg) |
| recent `356680.KQ` | gap_entry | 2025-10-10 | 2025-10-13 | 2025-10-14 | 7,903.95 / 9,450.00 | pass | 진입 inside, 청산 inside | [charts/recent-356680.KQ-2025-10-10.svg](charts/recent-356680.KQ-2025-10-10.svg) |
| recent `456010.KQ` | gap_entry, top_win | 2025-10-21 | 2025-10-22 | 2025-10-28 | 14,627.31 / 18,950.00 | pass | 진입 inside, 청산 inside | [charts/recent-456010.KQ-2025-10-21.svg](charts/recent-456010.KQ-2025-10-21.svg) |
| recent `005935.KS` | gap_entry, entry_above_high, model_outside_tape | 2025-10-27 | 2025-10-28 | 2025-11-05 | 79,539.75 / 75,620.17 | pass | 진입 above_high, 청산 inside | [charts/recent-005935.KS-2025-10-27.svg](charts/recent-005935.KS-2025-10-27.svg) |
| recent `034730.KS` | gap_entry | 2025-10-29 | 2025-10-30 | 2025-11-05 | 249,624.75 / 238,130.88 | pass | 진입 inside, 청산 inside | [charts/recent-034730.KS-2025-10-29.svg](charts/recent-034730.KS-2025-10-29.svg) |
| recent `240810.KQ` | gap_entry | 2025-10-29 | 2025-10-30 | 2025-11-19 | 64,932.45 / 59,261.35 | pass | 진입 inside, 청산 inside | [charts/recent-240810.KQ-2025-10-29.svg](charts/recent-240810.KQ-2025-10-29.svg) |
| recent `000500.KS` | gap_entry | 2025-11-20 | 2025-11-21 | 2025-12-18 | 36,629.41 / 45,532.78 | pass | 진입 inside, 청산 inside | [charts/recent-000500.KS-2025-11-20.svg](charts/recent-000500.KS-2025-11-20.svg) |
| recent `108490.KQ` | top_win | 2025-11-24 | 2025-11-25 | 2025-12-04 | 206,500.00 / 294,500.00 | pass | 진입 inside, 청산 inside | [charts/recent-108490.KQ-2025-11-24.svg](charts/recent-108490.KQ-2025-11-24.svg) |
| recent `068270.KS` | gap_entry, exit_below_low, model_outside_tape | 2025-12-08 | 2025-12-09 | 2025-12-26 | 177,803.14 / 171,373.27 | pass | 진입 inside, 청산 below_low | [charts/recent-068270.KS-2025-12-08.svg](charts/recent-068270.KS-2025-12-08.svg) |
| recent `319660.KQ` | gap_entry | 2026-01-14 | 2026-01-15 | 2026-01-20 | 41,870.92 / 40,429.78 | pass | 진입 inside, 청산 inside | [charts/recent-319660.KQ-2026-01-14.svg](charts/recent-319660.KQ-2026-01-14.svg) |
| recent `036540.KQ` | gap_entry, exit_below_low, model_outside_tape | 2026-01-26 | 2026-01-27 | 2026-01-29 | 5,452.72 / 6,530.00 | pass | 진입 inside, 청산 below_low | [charts/recent-036540.KQ-2026-01-26.svg](charts/recent-036540.KQ-2026-01-26.svg) |
| recent `072950.KQ` | gap_entry, top_win | 2026-01-30 | 2026-02-02 | 2026-02-10 | 5,572.78 / 6,380.00 | pass | 진입 inside, 청산 inside | [charts/recent-072950.KQ-2026-01-30.svg](charts/recent-072950.KQ-2026-01-30.svg) |
| recent `000270.KS` | gap_entry | 2026-02-04 | 2026-02-05 | 2026-02-25 | 156,778.35 / 177,000.00 | pass | 진입 inside, 청산 inside | [charts/recent-000270.KS-2026-02-04.svg](charts/recent-000270.KS-2026-02-04.svg) |
| recent `071090.KS` | gap_entry | 2026-02-09 | 2026-02-10 | 2026-03-04 | 3,716.86 / 3,435.28 | pass | 진입 inside, 청산 inside | [charts/recent-071090.KS-2026-02-09.svg](charts/recent-071090.KS-2026-02-09.svg) |
| recent `007810.KS` | gap_entry | 2026-02-12 | 2026-02-13 | 2026-03-04 | 57,328.65 / 49,205.39 | pass | 진입 inside, 청산 inside | [charts/recent-007810.KS-2026-02-12.svg](charts/recent-007810.KS-2026-02-12.svg) |
| recent `108490.KQ` | gap_entry, gap_stop | 2026-02-19 | 2026-02-20 | 2026-03-04 | 283,641.75 / 239,880.00 | pass | 진입 inside, 청산 inside | [charts/recent-108490.KQ-2026-02-19.svg](charts/recent-108490.KQ-2026-02-19.svg) |
| recent `000500.KS` | gap_entry | 2026-03-06 | 2026-03-09 | 2026-04-03 | 50,747.58 / 57,859.95 | pass | 진입 inside, 청산 inside | [charts/recent-000500.KS-2026-03-06.svg](charts/recent-000500.KS-2026-03-06.svg) |
| recent `034730.KS` | gap_stop | 2026-03-10 | 2026-03-11 | 2026-03-31 | 351,000.00 / 308,345.75 | pass | 진입 inside, 청산 inside | [charts/recent-034730.KS-2026-03-10.svg](charts/recent-034730.KS-2026-03-10.svg) |
| recent `006400.KS` | gap_entry | 2026-03-18 | 2026-03-19 | 2026-04-09 | 397,198.50 / 473,500.00 | pass | 진입 inside, 청산 inside | [charts/recent-006400.KS-2026-03-18.svg](charts/recent-006400.KS-2026-03-18.svg) |
| recent `005490.KS` | gap_entry, exit_below_low, model_outside_tape | 2026-04-08 | 2026-04-09 | 2026-04-28 | 360,180.00 / 427,500.00 | pass | 진입 inside, 청산 below_low | [charts/recent-005490.KS-2026-04-08.svg](charts/recent-005490.KS-2026-04-08.svg) |
| recent `011070.KS` | gap_entry | 2026-04-15 | 2026-04-16 | 2026-04-22 | 379,689.75 / 469,500.00 | pass | 진입 inside, 청산 inside | [charts/recent-011070.KS-2026-04-15.svg](charts/recent-011070.KS-2026-04-15.svg) |
| recent `036540.KQ` | gap_entry, top_win | 2026-05-14 | 2026-05-18 | 2026-05-22 | 8,004.00 / 10,450.00 | pass | 진입 inside, 청산 inside | [charts/recent-036540.KQ-2026-05-14.svg](charts/recent-036540.KQ-2026-05-14.svg) |
| recent `203650.KQ` | gap_entry, entry_above_high, model_outside_tape | 2026-05-19 | 2026-05-20 | 2026-05-27 | 3,261.63 / 4,675.00 | pass | 진입 above_high, 청산 inside | [charts/recent-203650.KQ-2026-05-19.svg](charts/recent-203650.KQ-2026-05-19.svg) |
| recent `000500.KS` | gap_entry, gap_stop, exit_below_low, model_outside_tape | 2026-06-01 | 2026-06-04 | 2026-06-08 | 203,434.99 / 145,760.41 | pass | 진입 inside, 청산 below_low | [charts/recent-000500.KS-2026-06-01.svg](charts/recent-000500.KS-2026-06-01.svg) |
| recent `011070.KS` | gap_entry, gap_stop | 2026-06-15 | 2026-06-17 | 2026-06-26 | 1,207,603.50 / 922,538.50 | pass | 진입 inside, 청산 inside | [charts/recent-011070.KS-2026-06-15.svg](charts/recent-011070.KS-2026-06-15.svg) |
| recent `000500.KS` | gap_entry | 2026-07-22 | 2026-07-23 | 2026-07-28 | 201,100.50 / 163,306.31 | pass | 진입 inside, 청산 inside | [charts/recent-000500.KS-2026-07-22.svg](charts/recent-000500.KS-2026-07-22.svg) |
| recent `028050.KS` | gap_entry, entry_above_high, model_outside_tape | 2026-09-03 | 2026-09-04 | 2026-09-08 | 46,073.02 / 51,400.00 | pass | 진입 above_high, 청산 inside | [charts/recent-028050.KS-2026-09-03.svg](charts/recent-028050.KS-2026-09-03.svg) |
| recent `115440.KQ` | top_win | 2026-09-09 | 2026-09-10 | 2026-09-23 | 8,200.00 / 11,750.00 | pass | 진입 inside, 청산 inside | [charts/recent-115440.KQ-2026-09-09.svg](charts/recent-115440.KQ-2026-09-09.svg) |
| recent `003230.KS` | gap_entry | 2026-09-14 | 2026-09-15 | 2026-09-23 | 1,308,654.00 / 1,220,846.27 | pass | 진입 inside, 청산 inside | [charts/recent-003230.KS-2026-09-14.svg](charts/recent-003230.KS-2026-09-14.svg) |
| recent `092870.KQ` | gap_entry | 2026-10-01 | 2026-10-02 | open | 53,026.50 / — | pass | 진입 inside, 청산 — | [charts/recent-092870.KQ-2026-10-01.svg](charts/recent-092870.KQ-2026-10-01.svg) |

재현: `python docs/research/account-replay-fill-audit.py`

입력 해시는 `orders.json`의 `hashes`에 있습니다. 원본 데이터셋은 `C:\Users\acdun\Desktop\dev\stock-personal-paper\history-research\kr-saved-scan-20251008` 와 `C:\Users\acdun\Desktop\dev\stock-personal-paper\history-research\kr-saved-scan-20261009` 입니다.

비용 실험은 아직 실행하지 않았습니다. 설계는 `experiment-design.md`를 보세요.

작성 시각 2026-10-11 21:36 +0900.
