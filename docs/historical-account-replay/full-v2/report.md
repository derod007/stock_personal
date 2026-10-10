# 맵 v2 전체 기간 연구 계좌 실행

최근 기간은 완료했고, 이전 기간은 원본 데이터셋이 없어 미실행입니다. 운영 적용이나 전략 우열 판단이 아닙니다.

## 최근 기간 결과

- 기준 코드: `608b54e84ba4a57571e7f8f71cfeb2fa3b0d04dc`, PHP 8.3.6.
- 초기 자금 100,000,000원. 실제 처리: 2025-10-10~2026-10-08, 244세션. 요청 시작일은 2025-10-08이며 첫 저장 거래일은 10-10입니다.
- 최종 현금 72,170,042.27원, 보유 종목 종가 평가액 45,998,850.00원, 총자산 118,168,892.27원.
- 총자산 변화율 **+18.1689%**, 종가 기준 최대 낙폭 **5.3961%**. 장중 낙폭은 계산하지 않았습니다.
- 종료 거래 56건(이익 24, 손실 32), 실현손익 18,027,499.41원.
- 주문 67건, 체결 59건, 취소 8건. 종료 사유: 목표 22, 손절 30, 기간 4.
- 마지막 보유 3종목, 대기 주문 0건. 강제 청산하지 않았습니다.
- 미실현손익은 종가 총액 기준 141,392.85원, 예상 매도비용 차감 기준 95,394.00원입니다. 총자산에는 미매도 종목의 예상 매도비용을 차감하지 않으므로 두 정의를 섞어 합산하지 않습니다.
- 주문 승인 시 검사된 최대 섹터 비중은 36.33%, 종가 보유 평가액 기준 최대 비중은 36.11%입니다. 예약 주문을 포함하는 승인 검사와 종가 평가 지표는 다릅니다.
- 품질 차단은 중복 제거 종목·일 428건입니다. 상세 사유와 준비 신호의 주문/제외 결과는 `recent/metrics.json`에 있습니다.

## 검증

- `recent/reconcile.json`: 10개 검사 통과. 244세션 현금·수량·비용·실현손익·평가금액을 이벤트로 재구성했습니다.
- 주문 67건을 TradeSimulator로 재시뮬레이션했으며 생략 0건입니다. 이는 같은 데이터와 규칙을 쓰는 구현 검증입니다.
- PR #65 최근 상승 눌림 82건의 신호 날짜·진입·손절·목표 일치. 계좌 한도 등으로 모든 신호가 체결되는 것은 아닙니다.
- 재실행 처리 0세션, 저널 SHA256 동일(`recent/rerun-check.json`).
- 실행 중 계좌 정지 없음. 실행 전후 데이터셋·운영 계좌 파일 불변 검사 통과.
- account_replay, selection_correspondence, sector_freeze 테스트 통과. 기존 소규모 이어 하기 검증은 유지했고 이번 전체 기간을 분할 재실행하지는 않았습니다.

## 재현

저장소 루트에서 DATASET과 STATE를 실제 경로로 바꾸세요. STATE는 운영 상태와 분리된 새 연구 경로여야 합니다.

```sh
php -d memory_limit=3G bin/paper_account_replay.php run --period=recent --dataset-dir=DATASET --state-dir=STATE --sector-map=docs/historical-account-replay/sector-map-v2.json --confirm-full-run=after-code-review
php bin/paper_account_replay.php export --period=recent --dataset-dir=DATASET --state-dir=STATE --sector-map=docs/historical-account-replay/sector-map-v2.json --out=docs/historical-account-replay/full-v2/recent
python docs/research/account-replay-full-report.py
```

입력·전략·설정·코드 및 저널 해시는 `recent/provenance.json`에 있습니다. 내보내기 위치가 이미 있다면 새 위치를 사용해 기존 결과를 보존하세요.

## 이전 기간 미실행

이 환경에 `kr-saved-scan-20251008` 원본이 없습니다. 필요한 파일은 **history-kr-saved-scan-20251008.zip**입니다. 데이터셋의 dataset.json, universe.json 및 raw/bars/status 등 전체 내용을 포함해야 합니다. 다른 기간이나 차트 자료로 대체하지 않았습니다. 확보 후 verify를 먼저 실행하고 별도 연구 계좌에서 `--period=prior`로 실행합니다.

## 해석 범위

고정된 저장 스캔 종목군이며 당시 일별 TOP100이나 전체 시장이 아닙니다. 현재 업종 맵 v2를 과거에 적용했고, 종목군 선택 편향과 수정주가 기준 미확인 문제가 남습니다. 후보 순서는 기존 코드의 종목코드 순서입니다. 전략·손절·목표·한도를 조정하지 않았습니다. 실제 호가 유동성을 복원한 결과가 아니며, 종목별 거래 평균과 계좌 수익률은 다른 지표입니다. 두 기간 비교는 이전 기간 실행 전까지 보류합니다.
