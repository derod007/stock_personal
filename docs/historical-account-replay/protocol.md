# 고정 종목군 과거 모의계좌 재현 — 실행 순서와 규칙 대응

표기: **저장 스캔에서 확보한 고정 종목군 계좌 재현**. 당시 매일의 TOP100을 복원한 것이 아니다.

이 문서는 구현 준비와 소규모 검증 범위만 다룬다. 전체 두 기간 실행, 수익성 결론, 전략 조정, 운영 계좌 반영은 포함하지 않는다. 소규모 검증 결과는 [report.md](report.md).

## 1. 무엇을 재사용했나

| 구분 | 내용 |
|---|---|
| 계좌 규칙 | `src/PaperPortfolio.php`의 `advance()`를 수정 없이 `mode=replay`로 호출한다. 주문·체결·청산·현금·한도 계산은 운영과 같은 코드다. |
| 체결·청산 | `src/TradeSimulator.php`를 수정 없이 쓴다 (수수료 10bp, 슬리피지 5bp, 주문 유효 3봉, 보유 20봉). |
| 추천 | `ChartPlanEngine::analyze()`를 운영 계좌(`bin/paper_account.php`)와 같은 입력(그날 종가까지 완성된 봉)으로 호출한다. |
| 품질 | `PaperQuality::inspect()` 그대로. |
| 저장 | `PaperJournal` (해시 사슬 이벤트 + 상태). |
| 설정 | `config/paper-kr-recovery-v1.json`을 기반으로 계좌 ID만 다르게 한 `config/paper-history-kr-recovery-v1.json`. 다른 값이 하나라도 다르면 실행을 거부한다. |
| 전략 지문 | 운영과 같은 값. `config/paper-strategy-files.json`에 있는 파일과 전략 코드는 수정하지 않았다. 새 코드는 지문에 들어가지 않는다. |
| 새 코드 | `bin/paper/AccountReplay.php`, `bin/paper_account_replay.php`, `tests/account_replay.php`, 연구용 설정 1개 |

계좌 값 (운영과 동일): 초기 현금 100,000,000원, 최대 4종목, 종목당 위험 1%, 종목 비중 20%, 섹터 40%, 총 위험 3%.

## 2. 하루 처리 순서

세션 D를 처리하는 순서이며, 코드에서 순서가 고정되어 있다 (`PaperPortfolio::advance`).

| 순서 | 처리 | 코드 |
|---|---|---|
| 0 | D까지 완성된 봉만 잘라서 쓴다. 미래 봉은 이 하루에 들어오지 않는다. | `AccountReplay::day()` — `CandleClock::completed`, 봉 슬라이스 |
| 1 | 품질 검사와 추천 계산 (유효 봉 40개 이상이면 `ChartPlanEngine`, 추천 확정은 60개 이상). | `day()` |
| 2 | 전날까지 남은 주문과 보유를 먼저 처리한다. 각 건에 D의 봉을 붙여 `TradeSimulator`로 다시 계산한다. 체결, 청산, 만료, 진입 전 취소가 여기서 정해진다. | `advance()` 기존 `active` 루프 |
| 3 | 청산 대금은 이 단계에서 바로 현금에 더해진다. 같은 날 새 주문이 쓸 수 있다. | `advance()` — `cash += proceeds` |
| 4 | D 종가로 보유 평가가 갱신된다. | `advance()` — `marks` |
| 5 | 새 신호를 종목 코드 문자열 순서로 하나씩 본다 (`ksort`). 앞선 종목의 주문이 뒤 종목의 한도에 반영된다. | `advance()` 신호 루프 |
| 6 | 평가금액·낙폭을 기록하고 `last_session`을 D로 올린다. | `advance()` |
| 7 | 그날의 추천, 제외 사유, 주문, 체결, 청산, 현금, 보유를 `day_log` 이벤트로 남긴다. | `AccountReplay::day()` 로거 |

연구 계좌는 한 세션씩, 날짜 순서로만 진행한다. 이미 처리한 세션은 다시 처리하지 않는다 (`session <= last_session`이면 무시).

## 3. 규칙 대응표

| 질문 | 규칙 | 재현에서의 동작 |
|---|---|---|
| 전날 주문과 보유는 언제 처리? | D의 신호보다 먼저 (순서 2). | 같음. |
| D일 신호는 언제부터 주문·체결 가능? | 신호는 D 종가 후에 생기고, 주문은 D에 만들어지지만 D의 봉으로는 체결되지 않는다. `signal_at`보다 늦게 완성되는 봉만 쓴다. | 처음 체결 가능한 봉은 그 종목의 다음 세션 봉이다. |
| 주문 가격 | 지정가 `entry`. 시가가 더 낮으면 `min(entry, 시가×1.0005)`로 체결. 저가가 `entry`보다 높으면 미체결. | 같음 (`TradeSimulator`). |
| 주문 만료 | 유효 3봉. 3번째 봉까지 체결이 없으면 `unfilled`로 취소하고 예약현금을 푼다. | 같음. 기다리는 동안 그 종목의 봉이 없거나 품질에 막힌 날이 있으면 주문은 `data_quality`로 취소된다. |
| 진입 전 취소 | 체결 전에 시가 ≤ 손절, 시가 ≥ 목표, 고가 ≥ 목표 중 하나면 `cancelled_before_entry`. | 같음. |
| 손절·목표·기간 청산 | 체결 봉부터 판정. 체결 다음 봉부터 시가 ≥ 목표면 목표가에 청산(갭 상승). 저가 ≤ 손절이면 `min(손절, 시가)×0.9995`에 청산(갭 하락 포함). 고가 ≥ 목표면 목표가에 청산. 20번째 봉(체결 봉 포함) 종가×0.9995에 기간 청산. | 같음. |
| 같은 봉에서 손절과 목표가 모두 닿음 | 손절이 이긴다. 청산 이벤트에 `ambiguous_bar=true`. 진입 봉에서는 목표 갭 규칙이 적용되지 않는다. | 같음. |
| 매수 현금 | 주문 시 `수량×entry×1.001`을 **예약**한다 (현금에서 빼지 않음). 체결 시 `수량×체결가×1.001`을 현금에서 빼고 예약은 사라진다. 청산 시 `수량×청산가×0.999`를 더한다. | 같음. 가용 현금 = 현금 − 미체결 주문 예약. |
| 청산 대금 재사용 | 같은 세션 처리 순서 2~3에서 현금에 들어가므로 같은 날 신호(순서 5)가 쓸 수 있다. 같은 날 다시 같은 종목을 살 수도 있다. | 같음. |
| 후보 우선순위 | 종목 코드 문자열 순서. 점수·거래대금 순위는 쓰지 않는다. | 같음 (운영 코드가 그렇다). |
| 수량 | `위험 = min(평가금액×1%, 평가금액×3% − 열린 주문·보유의 계획 위험 합)`. `가용 = min(가용 현금, 평가금액×20%, 평가금액×40% − 같은 섹터 사용액)`. `수량 = floor(min(위험 ÷ 주당 위험, 가용 ÷ (entry×1.001)))`. 주당 위험 = `entry×1.001 − 손절×0.9995×0.999`. | 같음. 수량이 1 미만이면 `cash_or_risk_or_sector_limit`. |
| 보유 한도 | 열린 주문과 보유를 합쳐 4건 이상이면 `position_limit`. | 같음. |
| 같은 종목 중복 | 이미 주문 또는 보유가 있으면 `already_active`. | 같음. |
| 봉이 없거나 품질 차단된 날 | 대기 주문은 `data_quality`로 취소. **보유 중인 종목이면 계좌 전체를 멈춘다** (`unpriced_position`, 이후 세션 처리 없음). 신규 신호는 `data_quality`로 제외. | 같음. 보간하지 않고 하루를 건너뛰지도 않는다. 사유는 `day_log`에 남는다. |
| 거래량 0 봉 | `PaperQuality` 규칙을 따른다. | 같음. |
| 마지막 평가일 | 보유는 청산하지 않는다. | 열린 보유는 평가손익과 함께 보고, 대기 주문도 그대로 보고한다. |
| 시계 | 운영은 실제 시각을 쓴다. | 재현은 세션 날짜를 쓰고 시스템 시계를 판단에 쓰지 않는다. 이벤트의 `recorded_at`만 기록용 벽시계이며 비교에서 뺀다. |

## 4. 연구용 TradeSimulator 연구와 다른 점

PR #65까지의 연구는 신호마다 독립적으로 시뮬레이션했다. 계좌 재현은 같은 신호 중 일부만 주문한다.

| 항목 | 신호별 연구 | 계좌 재현 |
|---|---|---|
| 자금 | 제한 없음 | 현금·예약·청산 대금 재사용 |
| 동시 보유 | 제한 없음 | 최대 4건 (주문 포함) |
| 같은 종목 | 신호마다 별개 | 주문·보유 중이면 건너뜀 |
| 위험 한도 | 없음 | 종목 1%, 총 3%, 비중 20%, 섹터 40% |
| 수량 | 비율만 | 정수 주식 수 |
| 체결·청산 규칙 | `TradeSimulator` | 같은 클래스 (모든 주문을 독립적으로 다시 계산해 일치 확인) |

따라서 한 건의 체결·청산 결과는 같고, 어떤 신호가 주문이 되는지가 다르다. PR #65 `population.json`의 눌림 신호와 계좌의 신호가 같은 날짜·가격인지는 `crosscheck`로 비교한다 (소규모 3구간 64/64 일치).

## 5. 운영 계좌와 다른 점 (재현이라서 생기는 차이)

1. **종목군**: 운영은 매일 거래대금 스캔과 후보 선별을 거친다. 재현은 저장 스캔에서 확보한 종목 전체에 매일 추천을 계산한다. 완료봉이 같을 때 `plan.ready`, 제안의 주문 가능, 스캐너 후보, `PaperScanUniverse` 선택은 서로 같다. 거래대금 TOP100이 그날 누구였는지, 그리고 그때의 가격 수집은 재현하지 않았다.
2. **품질 보류 종목**: 데이터셋 품질 기준에서 `hold`인 종목은 기간 전체에서 제외한다. 사후 정보다. 제외 목록은 `provenance.json`에 있다.
3. **섹터**: 데이터셋과 저장 스캔에는 업종 칸이 없다. 운영 섹터 캐시를 읽기만 해서 연구용 맵을 만들었다. 이번 종목군의 캐시는 업종명 없이 `기타`로 저장된 자리표시자뿐이라, 추정하지 않고 144종목 모두를 하나의 `unclassified`로 두었다. 종목마다 다른 섹터로 나눠 한도를 피하지 않는다. 이는 현재 캐시를 과거에 적용하려는 시도이고, 당시 업종을 복원한 것이 아니다. 자세한 수는 [followup.md](followup.md).
4. **가격**: 저장된 현재 제공처 가격이며, 당시 가격과 수정주가 처리가 같은지는 확인되지 않았다.
5. **추천 출처**: 운영의 `catchup`(뒤늦은 신호 거부)은 쓰지 않는다. 모든 날을 정상 마감으로 본다. 신호 출처는 `replay`.
6. **준비 구간**: 평가 시작 전의 봉은 지표 계산에만 쓰고 주문을 만들지 않는다. 평가 시작 전 날짜는 거부된다.
7. **운영 장치**: 스케줄러, 알림, 운영 캐시, 운영 저널은 쓰지 않는다. 연구 계좌는 `<state>/history-account/<연구 계좌 ID>/account-replay.json` 한 파일에만 쓴다.

## 6. 미확정 사항 — 전체 실행 보류

전체 실행은 아래가 정해지기 전에는 시작하지 못하도록 막혀 있다 (`--confirm-full-run=after-code-review`와 섹터 선택이 필요하다).

1. **섹터 분류를 확인하지 못했다**: 운영 캐시와 저장 스캔을 읽었으나 확보 0건, 미확인 144건이다. 미확인은 한 버킷이라 40% 한도가 계좌 전체 한도와 같다. 분류가 생기면 파일 해시가 바뀌고 기존 연구 계좌는 이어 쓰지 못한다.
2. **보유 종목의 봉 누락·무효 시 계좌 정지**: 기존 규칙을 유지했다. 보간하거나 날짜를 건너뛰지 않는다. 전체 기간 품질 차단 비율은 이전 2.0%, 최근 1.2%라 전체 실행이 중간에 멈출 수 있다. 멈춘 종목·날짜·사유는 저널의 `account_halted`에 남는다. 이번 짧은 구간 3개는 멈추지 않았다.
3. **종목군**: 일별 TOP100이 아니다. 완료봉이 같을 때 `plan.ready`와 스캐너 후보, `PaperScanUniverse` 선택은 서로 같았고, 보유 종목은 추천이 아니어도 평가 대상에 남는다. 거래대금 TOP100 구성과 별도의 가격 수집은 재현하지 않았다. 품질 보류 종목은 처음부터 빠진다.
4. **후보 우선순위**: 코드상 종목 코드 순이다. 운영 코드의 동작이라 그대로 따랐지만, 설계 의도를 적은 문서는 찾지 못했다. 한도가 빡빡한 날 결과가 종목 코드에 좌우된다.
5. **같은 날 재진입**: 청산한 날 같은 종목 신호가 있으면 다시 매수한다. 운영 코드의 동작이며 그대로 두었다.

## 7. 명령

연구 계좌만 쓴다. 상태 폴더는 `PAPER_STATE_DIR` 또는 `--state-dir`, 기본은 저장소 옆 `stock-personal-paper`.

```
# 계획 확인 (아무것도 쓰지 않음)
php bin/paper_account_replay.php plan --period=prior --dataset-dir=<state>/history-research/kr-saved-scan-20251008

# 소규모 (계좌 ID에 smoke 표시가 붙는다. 기간 지정 필수)
php bin/paper_account_replay.php run --period=prior --smoke-label=x --start=2024-10-08 --through=2024-11-29 --dataset-dir=<dir>

# 전체 실행 (코드 리뷰 후, 섹터 방침을 정한 뒤에만)
php -d memory_limit=3G bin/paper_account_replay.php run --period=prior|recent --dataset-dir=<dir> --confirm-full-run=after-code-review --sectors=all-unclassified   # 또는 --sector-map=<파일>

# 이어 하기: 같은 명령을 다시 실행한다. 처리한 세션은 건너뛰고, 입력·설정·코드가 바뀌었으면 거부한다.
# 결과 조회
php bin/paper_account_replay.php status  --period=... [--smoke-label=...]
php bin/paper_account_replay.php verify  --period=... --dataset-dir=<dir>      # 장부 재구성과 독립 재시뮬레이션
php bin/paper_account_replay.php log     --period=... --from=YYYY-MM-DD --to=YYYY-MM-DD
php bin/paper_account_replay.php export  --period=... --dataset-dir=<dir> --out=<폴더>
php bin/paper_account_replay.php crosscheck --period=...
php bin/paper_account_replay.php compare --period=... --other-state-dir=<다른 상태 폴더>
php bin/paper_account_replay.php quality-scan --period=... --dataset-dir=<dir>
php bin/paper_account_replay.php sector-map --prior-dir=<이전 데이터셋> --recent-dir=<최근 데이터셋> --out=docs/historical-account-replay/sector-map.json
```

종료 코드: 0 정상, 2 계좌 정지, 3 데이터셋·운영 파일 변경 감지, 1 검증 실패.

## 8. 안전장치

- 계좌 ID는 `hist-kr-recovery-v1-…` / `hist-smoke-kr-recovery-v1-…` 접두사만 허용한다. 운영 ID(`paper-kr-recovery-v1`)와 이전 계좌 ID는 거부한다.
- 쓰기 경로는 `history-account/` 아래로 제한한다. `history-research/`, `data/ohlcv|raw|cache|paper`는 거부한다.
- 데이터셋 해시(`dataset.json`, `universe.json`, 종목별 봉)를 실행 전에 확인한다. 부족한 부분을 현재 다운로드나 운영 캐시로 채우지 않는다.
- 이어 하기 때 설정, 전략 지문, 데이터셋, 봉 해시, 섹터 방침, 시작·끝 날짜, 재현 코드 해시 중 하나라도 다르면 거부한다. 이 값은 계좌 시작 이벤트에 기록된다.
- 기능시험용으로 주입한 추천은 `plan_source=injected_test`로 표시되고 실제 엔진 실행과 같은 계좌에 섞이지 않는다.
