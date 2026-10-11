# 고정 종목군 과거 모의계좌 재현 — 소규모 검증 보고서

범위: 구현 검증용 짧은 구간 3개. 전체 두 기간 실행, 수익성 결론, 전략 조정, 운영 계좌 반영은 하지 않았다. 아래 금액은 장부가 맞는지 보기 위한 값이며 성과 해석에 쓰지 않는다.

표기: **저장 스캔에서 확보한 고정 종목군 계좌 재현** (당시 매일의 TOP100 복원이 아니다).

이 파일은 `docs/research/account-replay-report.py`가 내보낸 JSON에서 만든다. 숫자를 손으로 적지 않았다.

## 1. 구간별 결과

| 구간 | 기간 | 세션 | 주문 | 체결 | 취소(미체결/진입 전) | 청산(손절/목표/기간) | 마감 시 보유 | 마감 시 대기주문 | 현금 | 실현손익 | 평가손익(미청산) | 장부 대조 |
|---|---|--:|--:|--:|--:|--:|--:|--:|--:|--:|--:|---|
| a 이전 기간 시작부 | 2024-10-08 ~ 2024-11-29 | 38 | 5 | 5 | 0 (0/0) | 5 (3/1/1) | 0 | 0 | 99,062,949 | -937,051 | 0 | 통과 |
| b 최근 기간 시작부 | 2025-10-08 ~ 2025-12-12 | 46 | 16 | 16 | 0 (0/0) | 12 (8/4/0) | 4 | 0 | 64,890,675 | 5,739,495 | 3,199,261 | 통과 |
| c 이전 기간 끝부분 (주문·보유가 남은 채 끝남) | 2025-06-23 ~ 2025-10-02 | 72 | 24 | 18 | 5 (4/1) | 16 (12/1/3) | 2 | 1 | 60,417,144 | -2,179,490 | 1,150,542 | 통과 |

마지막 평가일에 남은 보유는 강제로 청산하지 않고 평가손익으로만 적었다.

## 2. 요구한 확인 항목과 근거

| 확인 항목 | 결과 | 근거 |
|---|---|---|
| 주문·체결·미체결·만료·진입 전 취소 | 실데이터 3구간 + 단위시험 | 구간 c에 미체결 만료와 진입 전 취소가 모두 있다. 단위시험은 3번째 봉 만료와 예약현금 해제까지 확인한다. |
| 손절·목표 청산, 갭, 같은 봉 충돌 | 실데이터 청산 33건 (모호한 봉 0건) + 단위시험 | 단위시험이 손절 갭(시가 기준), 목표 갭, 진입 봉 손절, 같은 봉 손절 우선(`ambiguous_bar`), 20봉 기간 청산을 각각 만든다. |
| 현금 부족·보유 한도·같은 종목 중복 | 실데이터 신호 결과 합계 {'order': 35, 'already_active': 3, 'position_limit': 24, 'cash_or_risk_or_sector_limit': 2} | `position_limit`, `cash_or_risk_or_sector_limit`, `already_active`가 모두 실제로 나왔다. 단위시험이 총위험 3%, 섹터 40%, 아주 작은 현금도 만든다. |
| 현금·수량·수수료·실현손익·평가금액 대조 | 3구간 통과 | 이벤트만으로 현금·예약현금·실현손익·수수료·평가금액을 다시 만들어 저장 상태와 비교한다. 모든 주문을 `TradeSimulator`로 독립 재시뮬레이션해 체결·청산이 같음을 확인한다. 종가 표시는 데이터셋 종가와 비교한다. |
| 같은 날짜 재실행 시 중복 없음 | 통과 | 구간 a를 다시 실행하면 처리 세션 0, 저널 파일이 바이트 단위로 같다. 단위시험도 같다. |
| 이어 하기 = 한 번에 실행 | 통과 | 구간 a를 한 번에 돌린 저널과 4세션씩 3번에 나눠 돌린 저널이 같다 (이벤트 92개, 정규화 해시 `b01e1d9a473ce533…`, 상태 해시 동일). 단위시험은 3번 중단 후 재개도 확인한다. |
| 미래 봉 추가·변경이 과거에 영향 없음 | 단위시험 통과 | 봉을 더 붙여도 이전 추천·주문·장부가 그대로이고, 이전 날짜까지만 돌려도 기록이 다시 쓰이지 않는다. 실제 엔진(`ChartPlanEngine`)으로도 확인한다. 이미 쓴 구간의 봉이나 설정이 바뀌면 이어 하기를 거부한다. |
| 연구 계좌에만 기록 | 통과 | 매 실행 전후로 데이터셋 폴더 전체 해시와 운영 계좌 상위 파일 해시를 비교한다. 3구간 모두 변하지 않았다 (`untouched`). 단위시험은 새로 생긴 파일이 연구 계좌 폴더 안뿐임을 확인한다. |
| 주입 추천은 기능시험 전용 표시 | 통과 | 주입한 계획은 `plan_source=injected_test`로 기록되어 실제 엔진 실행과 같은 계좌에 섞을 수 없다. 실데이터 3구간은 모두 `engine`이다. |
| 대조 검사가 오류를 잡는지 | 단위시험 통과 | 체결가·현금·청산을 일부러 바꾸면 대조가 실패하고, 저널을 고치면 해시 사슬이 깨진다. |

`php tests/account_replay.php`: 62개 항목 모두 통과.

### 구간별 대조 항목

| 항목 | a | b | c |
|---|---|---|---|
| `cash_positions_fees_pnl_rebuilt_from_events` | 통과 — cash, reserved cash, realised P&L, fees and equity rebuilt for 38 sessions | 통과 — cash, reserved cash, realised P&L, fees and equity rebuilt for 46 sessions | 통과 — cash, reserved cash, realised P&L, fees and equity rebuilt for 72 sessions |
| `state_matches_rebuild` | 통과 — cash 99062949.13/99062949.13 realised -937050.87/-937050.87 closed 5/5 | 통과 — cash 64890674.93/64890674.93 realised 5739495.25/5739495.25 closed 12/12 | 통과 — cash 60417144.35/60417144.35 realised -2179489.65/-2179489.65 closed 16/16 |
| `open_orders_and_positions_match` | 통과 — 0 open orders/positions | 통과 — 4 open orders/positions | 통과 — 3 open orders/positions |
| `sessions_unique_and_logged` | 통과 — 38 equity points, 38 day logs | 통과 — 46 equity points, 46 day logs | 통과 — 72 equity points, 72 day logs |
| `no_duplicate_orders` | 통과 — 5 orders | 통과 — 16 orders | 통과 — 24 orders |
| `order_size_and_risk_within_limits` | 통과 — 5 orders checked | 통과 — 16 orders checked | 통과 — 24 orders checked |
| `marks_equal_dataset_closes` | 통과 — 52 marks checked | 통과 — 115 marks checked | 통과 — 134 marks checked |
| `orders_and_trades_equal_independent_simulator` | 통과 — 5 orders re-simulated, 0 skipped (data-quality cancel or halted) | 통과 — 16 orders re-simulated, 0 skipped (data-quality cancel or halted) | 통과 — 24 orders re-simulated, 0 skipped (data-quality cancel or halted) |
| `no_forced_liquidation` | 통과 — exit reasons: {"stop":3,"target":1,"time":1} | 통과 — exit reasons: {"target":4,"stop":8} | 통과 — exit reasons: {"stop":12,"time":3,"target":1} |

## 3. PR #65 신호 모집단과의 대조

계좌가 매일 만든 눌림 신호가 PR #65 모집단(`population.json`)의 같은 날짜 신호와 같은 진입·손절·목표인지 본다. 같은 `ChartPlanEngine`이므로 같아야 하며, 달라지면 하루 단위 입력이 다르다는 뜻이다.

| 구간 | 모집단 눌림 | 같은 신호·가격 | 수준 불일치 | 계좌에 없음 | 계좌 신호 결과 |
|---|--:|--:|--:|--:|---|
| a | 4 | 4 | 0 | 0 | {'order': 4} |
| b | 25 | 25 | 0 | 0 | {'already_active': 2, 'order': 12, 'position_limit': 11} |
| c | 35 | 35 | 0 | 0 | {'already_active': 1, 'cash_or_risk_or_sector_limit': 2, 'order': 19, 'position_limit': 13} |

## 4. 품질 규칙에 걸리는 빈도 (전체 기간, 주문 없이 센 값)

| 기간 | 세션 | 종목 | 종목·세션 | 막힌 종목·세션 | 비율 | 막힌 적 있는 종목 | 이유별 (종목·세션) |
|---|--:|--:|--:|--:|--:|--:|---|
| prior (kr-saved-scan-20251008) | 242 | 137 | 33,154 | 664 | 2.0% | 10 | insufficient_history 228, invalid_recent_ohlcv 436, missing_session 104 |
| recent (kr-saved-scan-20261009) | 244 | 143 | 34,892 | 428 | 1.2% | 5 | insufficient_history 367, invalid_recent_ohlcv 61, missing_session 132 |

기존 규칙은 보유 중인 종목의 봉이 없거나 무효이면 계좌를 멈춘다(`unpriced_position`). 대기 주문은 취소한다(`data_quality`). 이 규칙을 그대로 두면 전체 실행이 중간에 멈출 수 있다. 결정이 필요하다 (`protocol.md` 6절).

## 5. 재현 정보

- 전략 지문: `934012285f2ddb3867fff4576dba05fb7df290a36a987c36fc260e8917bbe465` (운영과 같은 값. 전략 파일은 수정하지 않았다.)
- 설정 해시: `5cb6e6701ce61b29664b16ad09cc4ed182d415adc49133409a6a6f37abf2eb83`
- 코드·설정 해시 (LF 기준 sha256):
  - `bin/paper/AccountReplay.php` `46c6217325c04f44e430426591eec34cd913bfdbf085486fc53398b6ce0f3f1c`
  - `bin/paper_account_replay.php` `e3d0748ebc02132c835812ef4ba242297f7605c78f13a3d6bc3df05cb5b6fea4`
  - `tests/account_replay.php` `d93e4392442d622a83b7f0e11154ccd45692c130a620d48d4b131bb5e9674338`
  - `config/paper-history-kr-recovery-v1.json` `ebf0893907d05f127abf1345c0261b613bb9c87924d1ad91d3ac53cf347403af`
  - `config/paper-kr-recovery-v1.json` `dc092bc8e3939a5de22eb25ed79a442666928f46b30d672dd48afd0e7ecd7bb4`
  - `config/paper-strategy-files.json` `8ed84ba6ed1e2020f33d2951a7836c1db8ef4cd92457568e47e9af0daa3c8fdd`
  - `bin/paper/HistoryResearch.php` `478f0f919d75dc319a76745649f51cdc3d57404f20187541f12f2108472206da`
  - `bin/paper/StrategyVersion.php` `fcdf4fdbe42edae5dd8031037a66aa966eefbc9ba9ad9d7a6b5667c96d8af858`
- 데이터셋:
  - a `kr-saved-scan-20251008`: dataset.json `5f69e86104b4bd73cd2a4a03ba2ad2ad8275e16563a99e1d528d916600c3e9c8`, universe.json `93f244ad3ee41e8bf571f75bb781ef65df6053b12880939220885b7bf91da09e`, 봉 묶음 `897a32cc43c4587ba406beb27dda457eb1e107b685f6aa306761a9693ab5ff5b`, 사용 137종목, 제외 19종목, 평가 시작 2024-10-08, 마지막 완료 세션 2025-10-02
  - b `kr-saved-scan-20261009`: dataset.json `c683c269d8434ee5b77a382b489cbf8fe8ce5c443cfd45451c306b70ba32faca`, universe.json `93f244ad3ee41e8bf571f75bb781ef65df6053b12880939220885b7bf91da09e`, 봉 묶음 `1770ad7ebec0a78f337bf4c5f22757e59e476210026f63e82119422dacb4e0a5`, 사용 143종목, 제외 13종목, 평가 시작 2025-10-08, 마지막 완료 세션 2026-10-08
  - c `kr-saved-scan-20251008`: dataset.json `5f69e86104b4bd73cd2a4a03ba2ad2ad8275e16563a99e1d528d916600c3e9c8`, universe.json `93f244ad3ee41e8bf571f75bb781ef65df6053b12880939220885b7bf91da09e`, 봉 묶음 `897a32cc43c4587ba406beb27dda457eb1e107b685f6aa306761a9693ab5ff5b`, 사용 137종목, 제외 19종목, 평가 시작 2024-10-08, 마지막 완료 세션 2025-10-02
- 저널 (벽시계 시각을 뺀 정규화 해시, 상태 해시):
  - a `hist-smoke-kr-recovery-v1-20251008-a`: `b01e1d9a473ce533daeee60ab9a4500fd4c5ba06f25b89fbd2f5f831ef469f3d`, `ac9885a534f45ebb507775ab585db189a6c098e560ed937b400b69f4167c3fac`
  - b `hist-smoke-kr-recovery-v1-20261009-b`: `32e55c42396a0eab2ec9159f59e01f08d078e0de862868b3a0d1df7961fe13b8`, `81e112463ee8d88511a97e2391d96fcd2e7b4acd211b476e4aaac81219e36822`
  - c `hist-smoke-kr-recovery-v1-20251008-c`: `360f8144d905bab442389a63c09807661fc61c3412596da1f2d3ae1e506c4508`, `212b317bfaa6327135ce1421498ccedd1941fae315281fb3b6615402954a80f5`

원본 저널 파일 해시(`journal_sha256`)에는 기록 시각이 들어가므로 실행마다 다르다. 같은 입력의 동일성은 정규화 해시로 비교한다.

## 6. 산출물

`docs/historical-account-replay/smoke/{a,b,c}/`: `summary.json`, `reconcile.json`, `trades.json`, `daily-log.jsonl`(날짜별 추천·제외 사유·주문·체결·청산·현금·보유), `provenance.json`, `crosscheck-pr65.json`. 구간 a에는 `resume-check.json`도 있다. 전체 기간 품질 스캔은 `quality-scan-{prior,recent}.json`.
