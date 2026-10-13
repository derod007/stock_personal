# 보유 한도·단일 조건 누락 연구 실행

## 준비된 것과 아직 없는 것

main `113e5cb`를 기반으로 연구 코드만 추가했다. PR #77~79 병합 여부와 무관하게 실행 가능하다. 운영 전략·계좌·원본·기존 연구 결과를 바꾸지 않는다. 조건 완화 실험을 운영에 적용하지 않았다.

저장 로그에서 추천 103, 주문 68, 보유 한도 차단 28건을 추출했다. 차단은 고유 27종목(상승 눌림 19, 재지지 6, 회복 3)이며 일별 목록은 `capacity-inventory.json`이다. 이 파일은 저장 신호 목록이지 이후 수익이나 재계산 검증 결과가 아니다.

개발 환경에는 933종목 v2 전체 데이터가 없어 전체 기간을 실행하지 않았다. 아래 로컬 실행에서 날짜별 기준 추천까지 재검산한다. 전체 결과가 없는 상태로 추천 증가나 수익 개선을 주장하지 않는다.

## 이번 범위

- 한도 차단 28건의 독립 단위 거래 관찰. 계좌 한도 상향 재실행이 아니다.
- 상승 눌림의 원자 조건 10개 중 정확히 하나만 미충족인 경우.
- 재지지·회복형은 구조와 확인을 모두 마친 뒤 손익비만 부족한 경우. 다른 조건의 완화는 미구현이며 상태 집계만 제공.
- 공통 위험·품질·유효 가격 순서는 유지. `potential_new`도 실제 신규 주문이나 추천 확정 수가 아니다.
- 조건별 건수는 중복될 수 있다. `potential_new_symbol_days`는 여러 패턴이 겹친 종목·날짜를 한 번만 센다. `common_blocked`와 `already_final_ready`도 서로 겹칠 수 있으므로 단순 합산하지 않는다.

## 실행 (PowerShell, 저장소 폴더)

먼저 이 PR의 코드를 받아 같은 작업 공간에서 실행한다. 운영 예약 작업과 다른 연구 출력 경로를 쓴다. 인증키·네트워크·재수집은 필요 없다.

```powershell
php tests/opportunity_research.php
php bin/paper_opportunity_research.php inventory
```

정상이라면 실제 v2 데이터로 첫 8세션을 실행한다.

```powershell
php -d memory_limit=3G bin/paper_opportunity_research.php run --state-dir="C:\Users\acdun\Desktop\dev\stock-personal-paper" --dataset-dir="C:\Users\acdun\Desktop\dev\stock-personal-paper\history-research\kr-dated-amount-top100-20251008-20261008-v2" --run-id=opportunity-v1 --max-sessions=8
$LASTEXITCODE
```

`$LASTEXITCODE=0`, `completed_sessions=8`, `full_period_complete=false`를 확인한다. 이 시점은 부분 검증이며 성과 결론을 내리지 않는다. 오류·경고가 있으면 아래 전체 명령을 실행하지 말고 출력만 공유한다.

이상이 없으면 **같은 run-id**로 나머지를 이어서 처리한다. `max-sessions`는 이번 호출이 추가 처리할 최대 세션 수다.

```powershell
php -d memory_limit=3G bin/paper_opportunity_research.php run --state-dir="C:\Users\acdun\Desktop\dev\stock-personal-paper" --dataset-dir="C:\Users\acdun\Desktop\dev\stock-personal-paper\history-research\kr-dated-amount-top100-20251008-20261008-v2" --run-id=opportunity-v1 --max-sessions=244
$LASTEXITCODE
```

완료 기준: `completed_sessions=244`, `full_period_complete=true`, 종료 코드 0. 완료 후 같은 명령을 다시 실행하면 `processed_sessions=0`이며 기존 날짜별 파일과 보고서를 덮어쓰지 않는다. 완료 전 같은 명령은 아직 처리하지 않은 날짜를 이어서 계산한다.

## 출력과 공유

```text
C:\Users\acdun\Desktop\dev\stock-personal-paper\history-research\opportunity-v1\
  manifest.json
  day-YYYY-MM-DD.json
  report-8.json
  report-244.json
```

최종 `report-244.json` 하나와 종료 코드를 공유하면 된다. 보고서에 입력·코드·전략 지문·PHP 버전이 들어 있다. 원본 일봉이나 계좌 파일은 공유할 필요 없다. 부분 실행으로 멈추면 마지막 `report-N.json` 및 오류 메시지를 공유한다.

코드/프로토콜/입력이 바뀌었다는 오류는 기존 출력에 섞어 이어가지 않도록 하는 장치다. 파일을 고쳐 통과시키지 말고 먼저 확인한다. 새 실험은 `opportunity-v2` 등 새 run-id를 사용한다. 같은 폴더에서 동시에 실행하면 잠금으로 거부한다.

## 숫자 해석

- `capacity_summary`: 차단 28건 전체. 같은 종목의 중복·겹침이 있을 수 있다.
- `capacity_first_exact_plan_episode`: 연속 거래일의 동일 가격 계획에서 첫 건만 센 민감도 집계. 같은 차트 구조를 완벽히 중복 제거한 값이 아니다.
- `near_miss_groups`: 패턴+실패 조건별 독립 실패, 공통 차단, 기존 추천 여부, 가상 거래 결과.
- `closed`만 평균 수익률 분모다. 미체결·취소·pending·incomplete·자료 보류를 0%로 섞지 않는다.
- 관찰 기간 겹침은 거래가 이미 끝난 시점을 우선 사용하고, 미완료/자료 보류는 최대 22세션 창까지 보수적으로 표시한다.
- 수익률은 비용 포함 독립 단위 거래 모형이다. 계좌 수익률·추가 벌 수 있었던 금액·한도 확대 효과가 아니다.
- 큰 이익이 일부 사례에 몰릴 수 있으므로 집계만으로 운영 조건을 바꾸지 않는다. 후속 계좌 비교가 필요하다.

## 개발 검증

- `tests/opportunity_research.php`: 41개 통과. 실제 엔진의 거래량 단일 실패/복수 실패, 미래봉 차단, 품질·공통 위험 유지, 미완성 구조 제외, 저장 추천 불일치 실패, 달력 누락·중복·미완료 봉, 종료 뒤 누락, 반복·겹침, 이어하기·변조 검증 포함.
- 기존 `tests/dated_order_audit.php` 32개, `tests/dated_top100_replay.php` 32개 통과.
- PHP 구문 검사 및 운영 전략 지문 보존 확인. CI에 새 연구 테스트와 읽기 전용 inventory를 추가했다.
- 전체 v2 재계산·244일 이어하기 일치·28건 성과는 사용자 로컬 실행 대기다. 합성 테스트를 실제 전체 검증으로 표시하지 않는다.
