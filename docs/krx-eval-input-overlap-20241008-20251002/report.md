# 평가 입력과 KRX 단절·비정상 행 겹침 (PR #92 후속)

PR #92 데이터셋·단절 96 보고는 그대로 둔다. 원본 jsonl·전략·계좌는 변경하지 않았다. 추가 수집·보정·봉 삭제·조건 완화·계좌 실행·병합은 없다.

가격 기준: **KRX TDD 원본, 수정 여부 미확정**

이번 집계는 날짜별 TOP100 신규 추천 입력에 대한 것이다. 보유·대기 주문 안전성 검증이 아니다.

1.9배·30.1% 탐지는 일부 급변만 잡는다. 96건 목록에 없다고 기업행사나 가격 기준 문제가 없다고 보지 않는다.

## 1. 엔진이 실제로 쓰는 과거 봉

날짜별 TOP100 재현(`DatedTop100Replay::day`)은 판정 세션 이하 봉을 **240으로 자르지 않고** `$past`에 넣는다. 신규 추천만 ChartPlanEngine에 `$valid`(OHLC>0 완료봉 전체)를 넘긴다. 당일 TOP100이 아니면 계획을 만들지 않지만, 그 이유로 과거 단절이 이후 TOP100 입력에 안 들어간다고 보지 않는다.

| 구성 | 코드 범위 | 신규 추천 경로 |
|---|---|---|
| `replay_past` | 판정일까지 로드된 전 봉 (`bin/paper/DatedTop100Replay.php`) | 예 |
| `chart_valid` | OHLC>0 완료봉 전체, 최소 40 (`src/ChartPlanEngine.php`) | 예 |
| `quality` | 완료 60 + 최근 90력일 비정상 (`src/PaperQuality.php`) | 예 |
| `ma_feature` | SMA5/10/20/60 (240 없음) (`src/FeatureEngine.php`) | 예 |
| `ma_context_daily` | 20+5 (`src/TrendContext.php`) | 예 |
| `ma_context_weekly` | 주봉 20+3 (일봉 전체로 주봉 구성) (`src/TrendContext.php`) | 예 |
| `pullback_stop` | 최근 10저 (`src/TrendPullback.php`) | 예 |
| `pullback_target` | 직전 20고 (`src/TrendPullback.php`) | 예 |
| `retest_box` | 20 (`src/BreakoutRetest.php`) | 예 |
| `retest_overhead` | 확인봉 이전 전체 고가 (`src/BreakoutRetest.php`) | 예 |
| `recovery` | 인덱스 59부터 전체 시계열 (`src/TrendRecovery.php`) | 예 |
| `spike_dump` | 고점 최근 8 + 급등 전 20 (`src/SpikeDump.php`) | 예 |
| `top_pattern` | 최근 65 (미러는 전체 고저) (`src/NoramuTopPattern.php`) | 예 |
| `horizontal` | 120 (`src/HorizontalLevels.php`) | 예 |
| `envelope_ma240` | 240 (`bin/paper/EnvelopeResearch.php`) | 아니오 |

- ChartPlanEngine 최소 완료봉: 40 (240 아님).
- PaperQuality: 완료 60봉, 최근 비정상 90력일. 가격 점프 검사는 없음.
- FeatureEngine 이평: SMA5/10/20/60. MA240 없음.
- 재지지 목표의 상단 고가는 확인봉 **이전 전체**를 본다. 회복형은 인덱스 59부터 **전체 시계열**.
- Envelope MA240은 연구 관찰용이며 ChartPlanEngine에 없다. HistoryResearch PREP_BARS=240은 그 연구를 위한 준비 창이다.

## 2. 평가 243일 당일 TOP100 입력 겹침

연결 건수는 원인×종목×평가세션을 합산한다. 같은 종목·세션에 원인이 둘이면 두 번 센다. 고유 건수는 (symbol, session)을 한 번만 세며 상한은 24300이다.

- 평가 세션: 243일, 일자×종목 상한: 24300.
- 단절 96: 겹침 84 / 겹치지 않음 12 / 판단 불가 0.
- 겹치는 단절: 종목 79 · 세션 242 · 연결 1063 · 고유 (종목, 세션) 1002.
- 당일 TOP100만 본 PR #92 표(6/37/53)는 쓰지 않았다. 같은 날 TOP100은 6건뿐이나, 이후 TOP100 세션까지 보면 겹침이 84건이다.

### OHL=0 행

- 겹친 원인 날짜: 2571 (종목 83, 세션 243).
- 연결 건수: 19253 · 고유 (종목, 세션): 1381 (≤24300).
- PaperQuality 90력일: 연결 2096 · 고유 499.
- 판정일 당일 OHL=0: 연결 0 · 고유 0. TOP100은 당일 거래대금 행에서 뽑히므로 당일 0거래대금 OHL=0이 0인 것은 선정 방식과 맞다. 과거 OHL=0은 별개다.
- OHL=0은 `$valid`에 안 들어가 패턴·MA·손절·목표 계산 봉은 아니다. `$past` 비정상으로 최근 90력일이면 품질 차단.

### 행 없음

- 판정일까지의 달력에 행 없음이 있는 TOP100 입력: 원인 날짜 43034 (종목 174, 세션 243).
- 연결 건수: 692823 · 고유 (종목, 세션): 2975 (≤24300).
- 최근 90력일 공백(연결 집계): 48310 · 고유 1207. 중간 공백은 품질 사유가 아니다.
- 판정일 당일 행 없음: 연결 0 · 고유 0. 당일 TOP100에 있으려면 당일 원본 행이 있어야 하므로 0이 정상이다. 과거 상장 전·이전 시장 공백은 입력 시계열 길이를 줄인다.
- 중간 공백은 PaperQuality가 달력 대조를 하지 않아 품질 사유가 되지 않는다. 시계열이 짧아지거나 인접 완료봉 사이 간격만 남는다.

## 3. 단절 96 분류

분류 키는 단절 `to` ≤ 판정일 이고 그 종목이 그날 TOP100인 세션이 하나라도 있는가이다. 미래 `to`로 과거 세션을 막지 않는다.

| 구분 | 건수 |
|---|---|
| 평가 입력과 겹침 | 84 |
| 겹치지 않음 | 12 |
| 판단 불가 | 0 |

겹치지 않음 12건은 TOP100 등장이 모두 `to`보다 앞이다.

| 종목 | from | to | 평가 TOP100 세션(단절 전) |
|---|---|---|---|
| 000670.KS | 2025-04-24 | 2025-04-25 | 3 |
| 025560.KS | 2025-07-21 | 2025-07-22 | 1 |
| 070300.KQ | 2025-08-19 | 2025-08-20 | 1 |
| 084180.KQ | 2024-12-23 | 2024-12-24 | 2 |
| 086820.KQ | 2025-05-26 | 2025-05-27 | 1 |
| 145170.KQ | 2024-12-17 | 2024-12-18 | 9 |
| 209640.KQ | 2025-09-03 | 2025-09-04 | 26 |
| 214610.KQ | 2025-05-07 | 2025-05-08 | 1 |
| 303810.KQ | 2025-08-06 | 2025-08-07 | 5 |
| 336680.KQ | 2025-05-28 | 2025-05-29 | 2 |
| 419120.KQ | 2025-08-04 | 2025-08-05 | 4 |
| 900110.KQ | 2025-09-24 | 2025-09-25 | 1 |

확인된 무상증자 2건(083450.KQ, 086280.KS)도 이후 TOP100 신규 입력과 겹친다. 보정하지 않았다.

상세: `breaks-overlap.json`.

## 4. 어댑터·막힌 지점

KRX jsonl을 ChartPlanEngine에 넣어 추천을 재현하지는 않았다. 아래는 입력 구성 한계다.

- **field_map** (가능): jsonl의 open/high/low/close/volume과 day→15:30 KST available_at 매핑은 가격 변환 없이 가능하다.
- **ohl_zero_in_past** (가능): OHL=0 원행은 $past에 남겨 PaperQuality가 비정상으로 보게 해야 한다. CandleClock은 OHLC<=0을 $valid에서 뺀다.
- **no_row_not_invented** (엔진 한계): 행 없음은 봉을 만들지 않는다. PaperQuality는 중간 거래일 공백을 달력과 대조하지 않고, 당일 완료봉이 없으면 missing_session만 띄운다.
- **chart_plan_not_run** (이번 범위 밖): 추천·손절·목표 숫자를 엔진으로 재계산하지 않았다. 계좌 실행 금지. 겹침은 입력 범위만 센다.
- **price_basis** (미확정): KRX TDD 원본, 수정 여부 미확정. DatedTop100Replay의 기존 PRICE_BASIS는 Yahoo 표기다. KRX 원본을 넣으려면 출처 표기 어댑터가 필요하고 가격 보정은 하지 않는다.
- **envelope** (추천 경로 아님): MA240은 EnvelopeResearch 전용이다. ChartPlanEngine 신규 추천 입력에 넣지 않는다.
- **holdings** (검증 안 함): 이번 집계는 날짜별 TOP100 신규 추천 입력에 대한 것이다. 보유·대기 주문 안전성 검증이 아니다.
- **detection** (부분): 단절 목록은 종가비 1.9 또는 인접 |r-1|>0.301만 잡는다. 목록에 없다고 기업행사·가격 기준 문제가 없는 것이 아니다.

## 재현

```
python -m unittest tests.test_krx_eval_input_overlap
python docs/research/krx_eval_input_overlap.py --bars <jsonl 디렉터리>
```

