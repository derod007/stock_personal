# 섹터 고정과 후보 대응 — 추가 검증

현재 운영 섹터 캐시에 저장된 업종명을 SectorMap::bucketOf로 분류해 두 기간에 같이 적용한다. 당시 업종을 복원한 것이 아니다.

저장 스캔 원본에는 업종 칸이 없다. 종목 목록은 그 파일에서 오고, 업종은 운영 캐시 `data/cache/sector/sector_<코드>.json`을 읽기만 해서 `SectorMap::bucketOf`로 다시 매긴다. 캐시 파일은 바꾸지 않았다.

## 섹터 매핑

- 대상 144종목. 확보 0건, 미확인 144건. 캐시 저장 버킷과 현재 규칙이 달랐던 건 0건.
- 미확인 이유: {"cache_placeholder": 144}. 캐시의 업종명이 비어 `기타`로 저장된 자리표시자다. 그 값을 운영 버킷 other로 올리지는 않았다.
- 미확인 종목은 버킷 이름 `unclassified` 하나다. 종목별 버킷으로 40% 한도를 나누지 않는다.
- 매핑 파일 LF 해시 `8e248fe9160cea80dd54ed5da3a4dfe51deb0ecdeade9e3a714b407f79ce003d`.
- 같은 종목은 두 기간에 같은 칸을 쓴다. 분류가 바뀌면 그 연구 계좌는 이어 가지 않는다.

## 후보 선별 대응

완료봉을 운영 ChartPlanEngine, 그 결과의 apply, 스캐너의 candidateFromProposal, PaperScanUniverse::symbols에 같은 순서로 넣었다. 매일 거래대금 TOP100 선별과 별도의 가격 수집은 재현하지 않았다.

| 사례 | 패턴 상태 | 발표된 계획 | 주문 가능 | 스캐너 후보 | 유니버스 선택 | 일치 |
|---|---|---|---|---|---|---|
| breakout ready | ready | ready | true | true | true | true |
| breakout waiting | await_retest | await_retest | false | false | false | true |
| breakout reward/risk rejected | rejected_rr | rejected_rr | false | false | false | true |
| breakout common risk block | invalidated | risk_blocked | false | false | false | true |
| pullback ready | ready | ready | true | true | true | true |
| pullback waiting | await_confirmation | await_confirmation | false | false | false | true |
| pullback reward/risk rejected | rejected_rr | rejected_rr | false | false | false | true |
| pullback common risk block | ready | risk_blocked | false | false | false | true |
| recovery ready | ready | ready | true | true | true | true |
| recovery waiting | await_recovery | context_wait | false | false | false | true |
| recovery reward/risk rejected | rejected_rr | rejected_rr | false | false | false | true |
| recovery common risk block | ready | risk_blocked | false | false | false | true |

보유 종목은 후보가 아니어도, 스캔 목록이 비어도 평가 대상에 남는다.

## 짧은 구간 (고정 맵, 별도 계좌)

이전의 전부 미분류 결과(`smoke/a`, `smoke/b`, `smoke/c`)는 그대로 두었다. 아래는 `smoke-sector/`다.

| 구간 | 세션 | 주문 | 현금 | 실현손익 | 장부 | 섹터 한도 | 정지 | 기존 미분류와 주문·현금 |
|---|--:|--:|--:|--:|---|---|---|---|
| a 이전 시작부 | 38 | 5 | 99,062,949 | -937,051 | 통과 | 5 orders, max sector share 40% | 없음 | 같음 |
| b 최근 시작부 | 46 | 16 | 64,890,675 | 5,739,495 | 통과 | 16 orders, max sector share 39.96% | 없음 | 같음 |
| c 이전 끝부분 | 72 | 24 | 60,417,144 | -2,179,490 | 통과 | 24 orders, max sector share 40% | 없음 | 같음 |

구간 a를 한 번에 실행한 저널과 4세션씩 나눠 이은 저널은 같다 (이벤트 92개, 정규화 해시 `2253b12103d20065293ca296693532c1a135f3f1b4eb5dfc93bdda20c31db238`). 같은 명령을 다시 실행하면 처리 세션 0이고 저널 파일이 바이트 단위로 같다.

세 구간 모두 정지하지 않았다. 보유 종목의 봉이 없거나 무효이면 계좌를 멈추는 기존 규칙은 유지한다. 보간하거나 날짜를 건너뛰지 않는다.

## 섹터 캐시 경로

`paper_scan_universe.php`는 `KrAmountScanner`에 `data/raw/cache`를 넘기고, 스캐너는 그 아래 `sector`에서 업종을 읽는다. `data/cache/sector`는 다른 캐시다. 업종명 `기타`는 분류로 세지 않는다. 두 캐시 모두 쓰지 않았다.

- 운영 스캐너 경로 `data/raw/cache/sector`: 유효 업종명 0건, 자리표시자 144건, 파일 없음 0건.
- 다른 캐시 `data/cache/sector`: 유효 업종명 0건, 자리표시자 144건, 파일 없음 0건.
- 보완으로 채운 종목 0건, 업종명이 서로 다른 종목 0건.
- 유효 업종명이 없어 연구용 섹터 맵은 바꾸지 않았고, 소규모 계좌도 다시 돌리지 않았다. 운영 캐시는 쓰지 않았다.
