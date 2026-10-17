# 종목별 추천 판단 근거

이 기능은 추천 조건을 바꾸지 않는다. 기존 `rr-audit/<account>/<run>.json` 저장 시 각 종목에 `decision_evidence`를 추가한다. 추천 0건인 날도 스캔 감사 저장 경로가 실행되므로 정상적으로 평가한 비추천 종목을 기록한다. 기존 파일과 계좌 저널은 수정하지 않는다.

## 읽는 법

- `final`: 엔진이 최종 선택한 상태·사유·패턴·추세 맥락.
- `patterns`: 선택되지 않은 패턴을 포함한 원래 상태와 사유, 엔진이 제공한 수치·근거.
- `gates.*.state`: `pass`는 명시적 true, `fail`은 명시적 false, `unknown`은 미기록/미평가를 구분할 근거 부족. unknown을 실패로 세면 안 된다.
- 재지지 등 엔진이 상세 조건을 제공하지 않는 패턴은 `detector_details_not_provided`로 남는다. 해당 없음과 미평가도 엔진 근거 없이 구분하지 않는다.
- `rr_evaluation`: ready/rejected_rr의 확정 단계에서만 상태를 관찰했다고 표시한다. 아직 확인되지 않은 후보의 RR을 탈락 원인으로 추정하지 않는다.
- `quality`, `risk_observations`, `measurements`: 저장 근거 그대로. 개별 실패가 최종 비추천의 단독 원인이라는 뜻은 아니다.
- `join_key`: 종목@완료봉 session. 세션이 없으면 null이다. 같은 봉을 여러 번 스캔한 경우 run 파일·입력 해시·기록 시각도 함께 비교한다.
- `account_outcome`: `not_observed_here`. 주문·현금·한도 차단은 별도 계좌 이벤트와 연결해야 한다.
- `decision_code_hashes`: 감사 파일 작성 시 코드 해시. 수집된 분석의 `evidence_origin`과 기존 입력 근거를 함께 확인하며, 이전 캐시 생성 당시 코드라고 간주하지 않는다.

## 기존 감사 파일 내보내기

```bash
php bin/paper_decision_evidence.php --input=/path/to/rr-audit/run.json --output=/new/path/evidence.json
```

출력 부모 폴더는 미리 만든다. 기존 출력은 덮어쓰지 않는다. 입력은 읽기 전용이며 네트워크·전략 엔진·계좌를 호출하지 않는다. 예전 감사 파일에 코드 해시가 없으면 `unknown_not_backfilled`로 남기고, 현재 내보내기 코드 해시를 과거 엔진 버전으로 대체하지 않는다. 이 도구는 day_log 합계만 있는 계좌 저널에서 세부 사유를 복원하지 못한다.

## 이전 2년 진단 순서

1. 최근 2025-10~2026-10, 이전 2024-10~2025-10을 별도 집계한다. 정확한 거래일은 각 후보 파일을 따른다.
2. 저장 `rr-audit` 등 종목별 분석 원본이 있으면 위 도구로 추출한다. 이미 충분한 근거가 있는 날짜는 다시 계산하지 않는다.
3. 없는 날짜는 일별 TOP100 원본·입력 가격·고정 코드가 있어야 과거 진단을 새로 생성할 수 있다. 당시까지 완료된 봉만 전달하고 미래 가격·최신 TOP100으로 대체하지 않는다.
4. 새 진단은 `당시 저장 기록`과 구분해 `고정 버전 과거 재계산`으로 명시한다. 엔진/설정/후보/가격 해시, 진단 시각, 기준 거래일을 보존한다. 실제 과거 판단의 복원이라고 주장하지 않는다.
5. 알려진 추천의 종목·가격·패턴을 기존 장부와 대조한 작은 표본을 먼저 검증한다. 불일치하면 전체 실행을 중지하고 사유를 확인한다. 계좌 재실행은 필요 없으며 별도 승인 없이 수행하지 않는다.
6. 검증 후 같은 형식으로 두 기간의 추천·비추천 모두를 처리한다. 특정 상승 종목만 진단하지 않는다. 이미 연구에 사용된 두 기간을 독립적인 새 검증 기간이라고 부르지 않는다.

PR #99는 미래 감사 기록의 구조화와 기존 감사 파일 읽기 도구까지다. 아래 후속 도구는 별도 PR로 추가한다. 기존 `PaperOpportunityResearch::inspect`는 미래 봉을 자른 읽기 전용 진단 경로가 있으므로 후속 구현에서 재사용 가능성을 검토할 수 있다. 그 클래스의 `shadow`/`day`는 별도 연구 거래 계산을 포함하므로 전체를 그대로 호출하면 안 된다.

## 검사

```bash
php tests/decision_evidence.php
php tests/rr_audit.php
php tests/rr_scan.php
php tests/single_condition.php
```

PHP CI에 새 회귀 검사를 추가했다. 운영 src/config/예약, 포트폴리오 상태 전이에는 변경이 없다.

## 과거 진단 도구 (후속 PR)

`paper_historical_decisions.php`는 계좌를 실행하지 않고 기존 엔진의 판단만 계산한다. 실제 당시 저장된 결과와 구분해 `historical_recomputed_not_contemporaneous`로 표시한다.

최근 기간 예시(먼저 8거래일):

```bash
php -d memory_limit=-1 bin/paper_historical_decisions.php --dataset=/frozen/kr-dated-amount-top100-20251008-20261008-v2 --candidates=docs/historical-top100-20251008-20261008/candidates.json --baseline=docs/historical-top100-replay-20251008-20261008/account/daily-log.jsonl --output=/new/recent-diagnosis --limit-days=8
```

검증 후 같은 인수에서 `--limit-days=8`을 제거하면 전체 244일을 처리한다. 이미 저장한 날짜는 해시·종목군과 기준 추천을 검증한 후 재사용한다. 입력·코드·PHP 버전이 바뀌면 이어하기를 거부한다. 기존 파일을 삭제하거나 해시를 바꿔 이어가지 않는다.

이전 기간은 dataset/candidates를 `20241008-20251002` 것으로 바꾸고 별도 output을 쓴다. 이전 기준 `daily-log.jsonl`이 없으면 `--baseline`을 생략하되 기준 추천 검증이 없었다고 표시한다. 이 경우 당시 실제 판단과 동일하다고 단정하지 않는다.

- 원본 내부 해시를 검증하며 완료시각이 평가시각 이후인 봉은 엔진과 품질 검사 모두에 전달하지 않는다.
- 재현 경로와 같이 40봉부터 엔진을 평가하지만, 품질 검사(60봉·당일 봉 등)는 별도 유지한다. 엔진 ready와 추천 적격은 구분한다.
- 원본 입력 및 기존 장부는 읽기만 한다. 결과 폴더는 원본 밖에 두고 실행 중 잠금을 유지한다.
- 출력: manifest.json(원본·코드·기준 로그 해시), day-YYYY-MM-DD.json(100종목 전체 진단, 내용 해시).
- 미수집 종목도 품질 차단으로 남기며 다음 순위 종목으로 대체하지 않는다.
- 수량·현금·섹터·보유·체결을 재현하지 않는다. 단독 원인·조건 제거 효과를 추정하지 않는다.

전체 완료 후 가격 경로 보조 집계:

```bash
python3 bin/summarize_historical_decisions.py --diagnosis=/new/recent-diagnosis --dataset=/frozen/kr-dated-amount-top100-20251008-20261008-v2 --candidates=docs/historical-top100-20251008-20261008/candidates.json --output=/new/recent-summary.json
```

관찰 기준점은 종목별 첫 TOP100 관찰 후 최소 20거래일 간격이다. 다음 날 시가 대비 20봉 안 +15% 고가 도달과 20봉 종가 손실을 함께 집계한다. 날짜나 상승 소요일을 동일하게 맞추지 않는다. 미래 20봉 가격·거래량이 유효한 경우만 가격 결과를 평가하며, 과거 60봉 모양 분석의 표본 필터와 달라 기존 2,450건과 분모가 다를 수 있다. 미래 결과는 엔진 입력에 들어가지 않는다. 고가 도달은 실제 체결·실현 수익이 아니다.
