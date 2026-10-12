# 신규 주문 감사 보완 — 기존 장부 보존

기존 auditNewOrders는 날짜별 TOP100 포함 여부와 주문의 pattern 존재만 검사했습니다. 이는 최종 추천·품질 통과를 증명하지 못합니다. 기존 evidence.json과 장부는 실행 당시 기록으로 그대로 보존하며, 그 안의 최종 추천 검증 통과 표시는 이 문서로 범위를 정정합니다.

## 새 검증

- 신호일 TOP100 포함, 동일 종목·세션의 중복 주문 없음.
- 저장된 day_log가 정확히 하나이며 신호도 정확히 하나, outcome=order.
- 저장된 신호와 주문의 pattern, entry, stop, target, signal_at, order_valid_bars, reward_risk 일치.
- 고정 데이터셋을 읽어 해당 세션까지의 봉만 분리하고 완료봉으로 ChartPlanEngine을 재실행.
- 같은 시점의 PaperQuality.can_simulate=true 및 최종 plan.ready=true 필수. 패턴 자체 ready가 공통 위험 차단을 대신하지 않음.
- 재계산한 계획의 위 7개 필드와 주문 일치. 원본이 없거나 재계산이 실패하면 통과하지 않음.

이는 저장 로그 대조와 **동일 엔진 재계산**입니다. 독립적인 전략 구현이나 실제 시장 체결 검증은 아닙니다. 검증 코드 LF 해시는 출력에 별도로 기록합니다. 기존 전략 지문·실행 provenance·거래·계좌 저널은 갱신하지 않습니다.

## 확인한 것

Codex 환경에서 새 변조·엔진 연결 검사 32개 및 기존 날짜별 재현 검사 32개 통과. 주문 가격/패턴/신호일/유효기간/손익비 변경, 주문과 로그 동시 변경, 근거 누락·중복, 추천 또는 품질 실패, 공통 위험 차단, 미래봉 추가를 검사했습니다. Git에 있는 주문 68건과 날짜별 TOP100·주문 처리 로그 대조는 앞선 검토에서 일치했습니다.

**68건의 새 엔진 재계산 검증은 대기 중입니다.** Codex 환경에는 해당 v2 원본 전체와 계좌 저널이 없어 수행하지 않았습니다. 기존 실현손익·총자산을 새 검사 통과 결과로 해석하지 마세요.

## 로컬에서 실행할 읽기 전용 명령

PR 코드를 반영한 프로젝트 폴더에서 PowerShell:

```powershell
php -d memory_limit=3G bin/paper_dated_top100_replay.php verify --profile=year-20251008-20261008
$LASTEXITCODE
```

정상 기준: 종료 코드 0, 장부 pass=true, candidate_git_link.pass=true, new_orders.pass=true, orders=68, stored_log_matches=68, engine_recomputed=68, mismatches=[] 입니다. 출력 결과를 새 파일로 보존하세요. 기존 account/reconcile.json·provenance.json은 덮어쓰지 않습니다.

run 또는 export를 다시 실행할 필요는 없습니다. 이번 보완으로 연구 코드 해시가 달라져 기존 계좌의 이어 하기는 거부될 수 있으며, 그 보호를 해제하거나 저널의 해시를 바꾸지 마세요. verify는 계좌를 수정하지 않습니다. 불일치가 있으면 원본과 장부를 보존한 채 해당 사례만 조사합니다.
