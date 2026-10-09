# 패턴 과거 검증용 일봉 준비

이 문서는 다음 단계의 과거 검증에 쓸 **데이터 준비**만 다룹니다. 전략·점수·손절/목표·계좌 전략 지문은 바꾸지 않았고, 수익성이나 매수 가능 여부는 판단하지 않습니다.

## 무엇을 준비했나

- 종목군: 저장소의 저장 스캔 원본(`docs/paper-kr-5d-source/rr-audit/paper-kr`, 9월 22·23·28·29·30일 5개 파일)에 나온 한국 종목을 종목코드 기준으로 중복 제거한 **고정 종목군**입니다. 당시 전체 시장도, 당시 거래대금 TOP100도 아닙니다. 현재 상품 제외 함수(`KrAmountLeadersClient::excludedFromAmountRank`)를 그대로 재사용했습니다. 성과나 패턴 출현 여부로 고르지 않았습니다.
- 보류: 코드·이름·분석 대상 식별이 충돌하면 수집하지 않고 `identity_hold`에 이유와 함께 남깁니다.
- 일봉: 기존 `YahooChartClient`(Yahoo chart + 기존 네이버 정규장 보정 + 검증된 종가 복구) 경로를 별도 연구 폴더 캐시로 실행합니다. 먼저 `2y`를 받고(두 번째 데이터셋은 처음부터 `5y`), 12개월 평가 시작일까지의 완료봉이 240개에 못 미치고 공급자가 더 오래된 자료를 가진 경우에만 `5y`를 한 번 더 받습니다. `5y` 응답은 통째로 쓰며 `2y` 응답과 이어 붙이지 않습니다.
- 분석 기준일: 첫 수집 때 삼성전자(`005930.KS`)의 마지막 완료 정규장으로 정해 `dataset.json`에 한 번만 기록하고, 재개 실행에서도 바꾸지 않습니다. 평가 시작일은 기준일의 12개월 전입니다.

## 저장 위치 (Git 아님)

`<PAPER_STATE_DIR 또는 ../stock-personal-paper>/history-research/<dataset>/`

| 경로 | 내용 |
|---|---|
| `dataset.json` | 데이터셋 ID, 고정된 기준일·근거, `universe.json` 해시 |
| `universe.json` | 종목군 전체: 코드·이름·출처(파일·세션·순위)·제외/보류 사유 |
| `raw/yahoo/<sha256>.json` | 공급자 원응답 바이트. `raw/yahoo/index/`에 종목·범위·수집 시각·URL |
| `cache/` | 기존 클라이언트의 이 연구 전용 캐시(운영 `data/ohlcv`와 별개) |
| `bars/<종목>.json` | 클라이언트가 만든 일봉 전체(시간 필드, 완료 여부, 종가 출처, 종가 복구 원본 포함)와 품질 기록 |
| `status/<종목>.json` | 시도 횟수, 요청 목록, 원응답 해시, 봉 파일 해시, 품질 |
| `manifest.json` | 종목별 봉 수·최초/최종 완료일·보류 사유와 전체 건수 요약 |

대용량 원본은 Git에 넣지 않습니다. Git에는 코드·문서·요약 manifest(`docs/history-data-20261008/`)만 있습니다. 공유가 필요하면 이 데이터셋 폴더만 압축하세요. 계좌·`.env`·운영 폴더는 같은 상위 폴더에 있어도 포함하지 않습니다.

```powershell
Compress-Archive -Path "$env:PAPER_STATE_DIR\history-research\kr-saved-scan-20261009\*" -DestinationPath history-kr-saved-scan-20261009.zip
```

## 실행

```powershell
# 1) 종목군 고정(수집 전까지만 다시 추출 가능, 수집이 시작되면 새 --dataset 이 필요)
php bin/paper_history_prepare.php universe --dataset=kr-saved-scan-20261009
# 2) 수집. 다시 실행하면 이미 받은 종목은 받지 않고 실패한 종목만 이어서 시도(종목당 최대 3회, 2차 패스 1회)
php -d memory_limit=512M bin/paper_history_prepare.php collect --dataset=kr-saved-scan-20261009
#    시도 한도를 넘긴 실패 종목을 명시적으로 다시 시도하려면 --retry-failed
# 3) 요약 manifest 생성(저장소용 사본은 --copy-to)
php bin/paper_history_prepare.php report --dataset=kr-saved-scan-20261009 --copy-to=docs/history-data-20261008
# 4) 해시와 품질을 파일에서 다시 계산해 변조·손상을 확인
php bin/paper_history_prepare.php verify --dataset=kr-saved-scan-20261009
```

`collect`는 실패나 미시도가 남으면 종료 코드 2를 돌려줍니다. 연속 8회 실패하면 서버를 두드리지 않도록 중단하고 manifest에 상태를 남깁니다.

## 두 번째 데이터셋: 과거 1년 구간 (`kr-saved-scan-20251008`)

같은 종목군으로 평가 구간을 1년 앞으로 옮긴 데이터셋입니다. 첫 번째 데이터셋(`kr-saved-scan-20261009`)의 평가는 2025-10-08~2026-10-08이고, 이 데이터셋은 준비 2023-10-08~2024-10-07, 평가 2024-10-08~2025-10-08입니다. 두 평가 구간은 겹치지 않고 이어집니다.

- 종목군: 첫 데이터셋의 `universe.json`을 **바이트 그대로 복사**했습니다(`universe --from-dataset`). 다시 추출하지 않았고 SHA256이 같습니다. 수집 대상 156개, 제외 35개, 보류 1개도 같고, 첫 데이터셋에서 Yahoo 404였던 5개도 그대로 다시 시도했습니다.
- 분석 기준일: 요청일 2025-10-08은 추석 대체공휴일이라 거래일이 아닙니다(전후 거래일은 10-02, 10-10). 그래서 기준일은 요청일 이전 마지막 완료 거래일 **2025-10-02**로 정해졌습니다. 평가 시작일은 요청일에서 12개월 전인 2024-10-08, 준비 시작일은 2023-10-08로 그대로 유지했습니다. 평가 봉은 2024-10-08~2025-10-02입니다.
- 범위: `5y`를 한 번에 받아 응답을 통째로 씁니다. 응답에 2021-10~2023-10 봉(준비 시작일보다 앞)이 있고, 기준일 이후 봉(2025-10-10~2026-10-08)도 있습니다. 앞쪽은 이동평균 준비용으로 쓸 수 있고, 뒤쪽은 **결과 측정 전용**입니다. 종목별 `preparation_window_bars`(2023-10-08~2024-10-07 완료봉 수)와 `bars_before_preparation_window`가 manifest에 있어, 다음 단계에서 준비 구간만 쓸지 더 앞의 봉도 쓸지 선택할 수 있습니다.
- 가격 기준 확인(`compare`): 두 데이터셋이 공유하는 날짜의 OHLCV를 읽기 전용으로 비교합니다. 이번 실행에서 151개 종목, 69,888개 종목·날짜가 모두 일치했습니다(불일치 0). 두 데이터셋의 봉을 서로 이어 붙이지는 않았고, 일치 확인은 공급자 가격 기준이 두 요청 사이에 변하지 않았다는 점만 보여 줍니다.

- 공급자 봉 오류: 준비·평가 구간에서 OHLC 관계가 맞지 않는 봉이 있는 종목이 많습니다(예: 종가가 고가보다 높고 거래량이 평소의 일부인 봉). 특정 날짜를 빼거나 고치지 않았고, 날짜는 종목별 invalid_ohlcv_days에 남겼습니다. 다음 단계에서 판정일마다 기존 PaperQuality::inspect가 오류 봉 이후 90일을 차단합니다. 평가 구간(2024-10-08 이후)에 오류 봉이 있는 종목·날짜 수는 manifest 상태 파일에서 집계하세요.
```powershell
php bin/paper_history_prepare.php universe --from-dataset=kr-saved-scan-20261009 --dataset=kr-saved-scan-20251008
php -d memory_limit=768M bin/paper_history_prepare.php collect --dataset=kr-saved-scan-20251008 --as-of=2025-10-08 --primary-range=5y
php bin/paper_history_prepare.php report  --dataset=kr-saved-scan-20251008 --copy-to=docs/history-data-20251008
php bin/paper_history_prepare.php verify  --dataset=kr-saved-scan-20251008
php bin/paper_history_prepare.php compare --dataset=kr-saved-scan-20251008 --against=kr-saved-scan-20261009
```

`--as-of`와 `--primary-range`는 첫 수집에서만 받아들이고, 이후에는 같은 값이 아니면 거부합니다. 기준일 이후 봉에 분할 등으로 보이는 가격 급변이 있으면 `price_jump_in_outcome_bars` 경고와 `outcome_price_jumps` 목록이 남습니다(결과 측정 때 확인할 것).
## 품질 검사와 보류

- 보류(`hold`): 공급자 응답 없음, 종목 식별 불일치(메타 심볼), 통화가 KRW 아님, 원응답 날짜가 증가하지 않음(역순·중복), 같은 날짜 봉 중복, 음수 거래량, 완료봉 60개 미만, 마지막 완료일이 기준일과 다름, 연속 두 봉 사이 종가 변화가 ±31% 초과(가격 제한폭 30%를 넘어 수정 안 된 분할/액면 변경 가능성), 평가 구간 완료봉 없음.
- 경고(`ok_with_warnings`): 12개월 시작일까지 완료봉 240개 미만(`history_short_for_240`), 상장 이후만 평가 가능, 과거 OHLC 오류 봉 존재, 종가 복구 적용, 미완료/기준일 이후 봉 존재, 10일 넘는 달력 간격, 거래량 0, 종가 없는 공급자 행.
- 하지 않는 것: 거래량 0이나 날짜 간격만으로 봉을 지우기, 주말·휴일을 누락으로 단정하기, 부족한 과거 봉을 만들거나 채우기, 특정 날짜 예외.
- 가격 기준: 공급자 가격의 수정주가·배당 조정은 확인할 수 없어 가정하지 않았고 `corporate_action_adjustment_unverified`를 데이터셋 전체 주의사항으로 기록했습니다. 지금 받은 과거 가격은 당시 스케줄러가 저장한 원본과 같다는 보장이 없습니다.
- 기준일 이후 봉이나 장중 미완료봉은 평가 봉이 아닙니다. 봉마다 `is_complete`가 남아 있고, 품질 기록에 건수가 있습니다.

## 다음 단계에서 지켜야 할 조건 (이번에는 적용하지 않음)

1. 각 과거 판정일 D에는 D까지의 완료봉만 엔진에 넘깁니다(`CandleClock::completed($rows, $symbol, D의 마감 시각)`). 이후 봉은 결과 측정에만 씁니다.
2. 세 추천 패턴(돌파 후 재지지, 상승 눌림, 추세 이탈 후 회복)은 각각 독립 평가하고, 패턴 자체 판정과 공통 위험 차단을 분리합니다. 최종 판정으로 합치지 않습니다.
3. 손익비 보완 지정가 연구(`PaperRrAudit` 연구 규칙)는 운영 추천과 따로 집계합니다. 돌파 전 높은 저점형의 watch와 breakout도 따로 셉니다.
4. 같은 구조가 며칠 반복되는 것은 독립 신호로 부풀리지 않습니다. 기존 `PrebreakHigherLow`/`HigherLowResearch`의 동일 구조·단계 최초 관측 방식과 `PaperFollowup`의 종목·세션 키(`symbol@session`)를 먼저 확인해 재사용합니다.
5. 후속 1/3/5/10/20봉이 모자란 사례는 `대기`이며 수익률 0이 아닙니다(`PaperFollowup::HORIZONS`).
6. 평가 가능일은 종목별 `eligible_from_60`, `eligible_from_240`(그날까지 완료봉이 60/240개가 되는 첫 날)을 참고합니다. 오래된 OHLC 오류 봉은 해당 날짜 이후 90일 동안 기존 `PaperQuality`가 차단하므로, 판정일마다 `PaperQuality::inspect`를 그대로 호출합니다.
7. 이 종목군은 저장 스캔에서 모은 고정 종목군이어서 생존·선택 편향이 있습니다. 결과는 패턴 자체의 과거 움직임 관찰이며 실제 계좌 수익률이나 당시 TOP100 복원이 아닙니다.
8. 조건 완화, 점수 변경, 손절/목표 변경, 계좌 전략 버전 변경은 이 데이터와 함께 하지 않습니다.

## 검증

`php tests/history_research.php` — 네트워크 없이 종목군 중복 제거·제외·충돌 보류, 연구 경로 보호, 2y 충분/5y 확장/신규 상장 미확장, 원응답 해시 보존, 재개 시 재다운로드 없음, 실패 한도·명시적 재시도, 품질 보류 사유, 미완료·기준일 이후 봉 분리, 종가 복구 증거 보존, manifest 건수, `verify` 변조 검출, 연구 폴더 밖에 쓰지 않음, 전략 파일 목록 불변을 검사합니다.
