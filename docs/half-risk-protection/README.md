# 한국 모의 신규 체결의 완만한 보호청산

`paper-kr-recovery-v1` forward의 새 체결에만 적용한다. 진입 추천·보유 한도·수량·목표·최대20봉은 바꾸지 않는다.

- R = 실제 매수 체결가 − 초기 손절가.
- 살아남은 완료봉 종가가 실제 매수가 + R 이상이면 조건을 확인한다.
- 다음 거래일부터 손절을 실제 매수가 − 0.5R로 한 번만 올린다. 반복 추적손절·본전 손절이 아니다.
- 예: 100원 매수/88원 손절 → 종가112원 확인 → 다음 거래일 손절94원.
- 확인 봉의 저가에 새 손절을 소급 적용하지 않는다. 손절 아래 갭은 시가에서 불리한 슬리피지를 반영한다.
- 같은 봉에 손절·목표가 함께 닿으면 기존 손절 우선, 다음 봉 시가가 목표 이상이면 기존 목표 청산 우선을 유지한다.

## 기존 계좌와 버전 전환

기존 보유분·신호 snapshot·주문 plan·과거 이벤트·잔액을 다시 만들지 않는다. 실행 주문에만 `exit_policy`와 `protection`을 별도 저장한다.

처음 계좌 CLI가 정상 가격 검사 후 `exit_policy_enabled`를 기록한 시각보다 **장 시작이 늦은 세션의 신규 체결**부터 적용한다. 이미 보유 중인 주문, 전환 전 시점의 catchup 체결에는 붙이지 않는다. 전환 전에 접수됐어도 전환 이후 처음 체결되는 대기 주문은 적용 대상이다. 품질 누락으로 중단된 계좌는 기존처럼 진행하지 않는다.

공용 코드 변경으로 전략 지문이 달라진다. `config/paper-forward-upgrades.json`은 main `d893c343...`의 검증된 지문 `93401228...`에서 이번 정확한 새 지문으로 가는 forward 전환 하나만 선언한다. CLI가 잠금 안에서 `forward_strategy_upgraded` 이벤트와 기존 active 해시를 남기고 새 버전을 고정한다. replay·알 수 없는 버전·서로 다른 version/fingerprint·중단 계좌는 자동 전환하지 않는다. 다른 forward 계좌는 버전 메타데이터만 전환될 수 있지만 보호청산 규칙은 붙지 않는다. 기존 legacy compatibility 목록은 넓히지 않았다.

사전 검사는 `forward_upgrade_pending`을 반환할 수 있으며 쓰기/장부 생성은 하지 않는다. 예약 daily는 이 상태를 허용하고 계좌 CLI가 실제 전환한다. 추천0건이라 계좌 CLI를 호출하지 않는 날에는 전환도 일어나지 않는다. 설정/예약 경로 변경은 없다.

## 기록과 화면

- `trade_exit_policy_assigned`: 신규 체결에 정책 배정, 체결가·초기 손절.
- `protection_armed`: 근거 종가·확인 세션·초기 R·작동 기준·새 손절.
- `protection_activated`: 최초 실제 적용 세션·현재 유효 손절.
- `exit.protection.exit_reason=half_risk_stop`: 보호 손절 청산. 기존 집계를 위해 일반 reason은 `stop` 유지.
- `paper.php`는 원래 계획과 현재 손절/보호 손절/조건확인 시점을 함께 표시한다.
- `paper_trades.php`는 해당 청산을 ‘위험폭 축소 손절’로 표시한다.
- 반복 실행은 배정·확인·적용 이벤트를 중복 생성하지 않는다.

## 검증

```
php tests/half_risk_protection.php
php tests/forward_protection.php
php tests/account_rollout.php
python3 -m unittest discover -s tests -p test_paper_daily.py
```

fixture `tests/fixtures/half-risk-56.json`은 기존 최근 기준 장부 SHA256 `42bc73eac8dde7f51666093790caee43ce31901ae9dca3a2668652169f7c2ae5`의56개 종료거래 가격 경로다. 계좌 재실행 없이 고정 체결/수량의 개별 거래 계산으로 원래 손익 전부와 보호안4건 개선·0건 악화·52건 동일, 합계차이+1,583,408.84원을 대조한다. 다른 기간/새로 확보된 현금으로 생기는 후속 진입의 성과까지 증명하지 않는다.

## 적용과 롤백 주의

PR 적용 전 기존 장부를 백업하고 `php bin/paper_account_preflight.php --config=config/paper-kr-recovery-v1.json`으로 읽기 전용 상태를 확인한다. 본 PR은 코드 제안이며 사용자 Windows 예약 실행/장부를 원격으로 실행하지 않았다.

전환 이후 옛 코드로 되돌리면 새 지문/보호주문을 처리할 수 없어 버전 검사로 중단되는 것이 정상이다. 지문 검사나 장부 해시를 수동으로 덮어쓰지 않는다. 보호주문을 보존하는 후속 변경 또는 별도 계좌로 전환해야 한다. 과거 장부로 복원해 이미 기록한 거래를 지우는 롤백은 하지 않는다.
