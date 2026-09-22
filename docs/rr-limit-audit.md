# 확인 후 손익비 탈락: 낮은 지정가 연구·감사 로그

## 매일 자동 기록

기존 예약 명령을 그대로 실행한다.

```bat
python bin/paper_daily.py --config=config/paper-kr.json
```

TOP100 스캔 한 번에서 추천·탈락·차트 실패를 모두 기록한다. 추가 스캔/추가 Yahoo 조회는 없다.

- 기본 저장: 저장소 옆 `stock-personal-paper/rr-audit/paper-kr/<UTC시각>-<고유번호>.json`
- `PAPER_STATE_DIR` 지정 시 그 디렉터리 아래 `rr-audit/paper-kr/`.
- 기존 실행 로그 `runs/paper-kr-forward/*.json`의 `scan.rr_audit`에 저장 경로·요약·오류가 남는다. 추천 0건이어도 기록한다.
- `saved`: 저장 완료. `partial`: 일부 종목 감사 오류. `failed`: 감사 저장 실패. 감사 실패로 기존 매매 판정을 바꾸지는 않는다. 운영 실행의 success와 감사 저장 성공은 별도 확인한다.
- `--cache`로 과거 스캔 결과만 재사용하면 원본 일봉·세부 조건을 복원하지 않는다. `cached_evidence_unavailable`로 남긴다. 평소 daily는 fresh 스캔이다.
- 재실행해도 원본은 덮어쓰지 않는다. 실행별 원본을 저장하므로 일별 집계 시 같은 종목·세션의 여러 실행을 단순 합산하지 않는다.

## 화면

모의 계좌 → **탈락 로그**. 저장된 `rr-audit/<계좌>/*.json`을 종목별로 보여 준다. 미충족 조건, 다른 차단, 확인가 손익비, RR 1.5가 되는 지정가를 한 줄에 둔다. «이후 일봉으로 체결·손익 비교»는 기존 추천과 연구 지정가를 같은 비용으로 따로 시뮬레이트한다. 계좌 수익률이 아니며 운영 매수에 넣지 않는다. 로그 파일이 없으면 오늘 스캔 캐시 미리보기만 가능하고, 그 결과는 저장하지 않는다.

## 기록 항목

`records[]`에서 종목과 `patterns[]`를 찾는다.

| 필드 | 의미 |
|---|---|
| `raw_status`, `raw_reason` | 패턴 자체의 원래 탈락 사유 |
| `final_status`, `final_reason` | 위험/추세 필터 적용 후 최종 상태 |
| `missing_conditions` | 실제 평가했고 미충족인 조건, 한국어 이름 |
| `not_evaluated` | 엔진이 제공하지 않았거나 조기 탈락으로 평가하지 않은 조건 |
| `exclusion_reasons`, `exclusion_reason_labels` | 지정가 연구에서 제외한 모든 사유와 한국어 설명 |
| `addition_reason` | 연구 후보에 추가된 이유 |
| `original` | 확인 가격·원래 손절/목표·손익비 |
| `candidate` | 통과한 연구 지정가, 고정 손절/목표, RR, 다음 3봉 유효기간 |
| `hypothetical_limit` | 차단되어도 산술 계산이 가능한 경우의 참고 가격. 주문 대상이 아님 |
| `measurements` (종목 레벨) | 눌림 거래량 비율, 0.85 기준, 직전봉 고점·현재 종가·부족률 등 |
| `quality`, `input_hash`, `bars`, `analysis` | 당시 원본 입력·품질·전체 엔진 판정 증거 |

`confirmed_rr_symbol_days`는 종목×일, `confirmed_rr_patterns`는 패턴별 수다. `rr_final_statuses` 합계는 종목×일 수와 일치한다. 여러 차단 사유는 중복될 수 있어 사유별 합계를 전체 건수로 해석하지 않는다.

## 추가 기준

기존 엔진이 선택한 패턴이 당일 **확인 완료 후 rejected_rr**인 경우만 검토한다. 이미 추천된 종목, 확인 대기, 다른 패턴, 위험/추세/오래된 데이터/품질 차단은 제외한다. 최종 상태가 덮여도 원래 RR 탈락 사실은 집계한다.

손절·목표는 확인 당시 값으로 고정한다. `지정가 = floor((목표 + 1.5 × 손절) / 2.5)`를 계산하고 `0 < 손절 < 지정가 < 목표`, `지정가 < 확인가`, RR ≥ 1.5를 재검사한다. 기존 가격 정수 절삭과 동일하며 거래소별 호가단위를 새로 모델링한 것은 아니다. RR 1.5는 비용 전이다.

`paper-kr` 매수 후보에 이 지정가를 넣지 않는다. 실제 계좌는 기존 추천만 매수 검토하고 보유는 기존 규칙으로 처리한다. 연구 계좌·미국 계좌·85% 거래량 조건·예약·화면 위젯은 변경하지 않는다.

## 이후 결과 재검증 (수동, 오프라인)

스캔 당시 저장한 원본과 갱신된 일봉을 비교한다. 아래 `원본.json`은 위 자동 기록 파일의 실제 경로로 바꾼다. 출력 파일은 새 이름을 사용한다.

```bat
php bin/paper_rr_audit.php --input=원본.json --prices=data/ohlcv --out=rr-result-20260925.json
```

일봉 파일명은 `005930.KS.json` 또는 기존 Yahoo `005930.KS_2y_1d_closed_v2.json`, 내용은 일봉 배열이다. 봉은 기존 OHLCV 형식과 시간 필드를 포함해야 한다. `--as-of=2026-09-25T20:20:00+09:00`으로 관측 마감을 지정할 수 있다(미래 지정 불가).

- 원본 일봉 수정/누락: `historical_revision_or_missing`, 결과 산출 보류. 원본 해시 불일치도 차단.
- 미래 일봉 없음: `no_future_bars`. 기간 부족은 `pending`/`incomplete`, 3봉 미체결은 `unfilled`.
- 기존 TradeSimulator로 다음 3봉 주문·체결 후 20봉 보유·수수료 편도 10bps·슬리피지 5bps 적용.
- 같은 봉의 진입/손절 접촉: `entry_bar_stop_touch`. 목표/지정가 순서를 알 수 없는 경우 기존 보수적 취소 규칙 적용.
- 일봉 품질 오류는 결과에서 별도 표시. 거래소 휴장/거래정지 달력 기반 누락일 검증은 포함하지 않는다.
- Yahoo 캐시는 스캔 때 합쳐 사용한 네이버 일봉과 다를 수 있다. 이 경우 변경을 숨기지 않고 비교를 보류한다. 동일 가격 기준의 최신 자료로 다시 확인한다.

독립 거래 실험이므로 동시 보유·현금·업종 한도를 적용한 계좌 수익률이 아니다. 지정가 접촉은 실제 체결 보장이 아니다. 후속 결과 갱신은 위 명령으로 수행하며 새 예약 작업은 만들지 않는다.

## 과거 기간 조사

```bat
php bin/paper_rr_audit.php --history=data/ohlcv --from=2026-08-01 --to=2026-09-21 --out=rr-history.json
```

폴더의 한국 종목 전체를 순회한다. 매일 당시까지의 완료 일봉만으로 신호를 계산한다. 일별 TOP100 편입 이력 없이 조사하므로 `exploratory_union_NOT_historical_daily_TOP100`으로 표시된다. 당시 실제 스캔 성과로 해석하지 않는다. 특정 211종목을 조사하려면 해당 종목 파일만 든 별도 폴더를 지정한다.

과거 보고서는 메모리 절약을 위해 종목×일마다 전체 일봉을 중복 저장하지 않는다. 원본 폴더를 보관하고 갱신 시 `--history`로 다시 실행한다. 자동 기록된 원본 스캔 파일만 `--input` 재검증에 사용한다. 사용자가 제시한 104건은 해당 원본 자료를 넣어야 비교할 수 있다.

## 검증

```bat
php tests/rr_audit.php
php tests/rr_scan.php
php tests/v14.php
python -m unittest discover -s tests -p test_paper_daily.py
```
