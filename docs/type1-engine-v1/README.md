# 유형 1 운영 주문 엔진 재생 연구

## 실행 전에 고정한 차이

군집과 후보 집합은 PR #107의 입력 준비 함수를 그대로 사용한다. 재학습·추가 진입 조건·손절 최적화를 하지 않는다. 아래 변경은 운영 지정가 엔진을 사용하는 데 필요한 고정 매핑이며, 같은 시가 전략의 완전한 복제라고 주장하지 않는다.

| 항목 | PR #107 | 이번 연구 |
|---|---|---|
| 매수가·수량 결정 | T+1 시가 | T 종가를 지정가로, T 종가 계좌에서 수량·예약금 결정 |
| 진입 | T+1 시가 무조건 매수 | 다음 1봉 안 지정가 도달 시 운영 엔진 체결 |
| 손절·목표 | T+1 시가 ×0.95 / ×1.15 | T 종가 계획가 ×0.95 / ×1.15 |
| 보유 만기 | T+20 | 다음 봉에만 체결 가능, 체결 후 20봉이므로 T+20 |
| 진입 전 목표·손절 갭 | 시가 모형 규칙 | 운영 엔진의 진입 취소 규칙 |
| 품질 검사 | 유효 당일 OHLCV | 원본 과거 봉을 PaperQuality에 전달: 60봉·최근90일 오류·중복·당일 봉 검사 |
| 누락 대기 주문 | 계좌 중단 | 운영 규칙대로 주문 취소 |
| 누락 보유 종목 | 계좌 중단 | 운영 규칙대로 unpriced_position 중단 |
| 비용·갭·동일봉 | v2 연구 모형 | 수정 없는 TradeSimulator·PaperPortfolio의 비용·우선순위 |

실제 체결가가 지정가보다 낮으면 실제 체결가 대비 손절·목표 비율은 달라진다. 거래 기록에 actual_stop_pct/actual_target_pct를 남긴다. 목표가 이전 시가 갭 우선 처리 등도 기존 엔진을 그대로 따른다. 차이는 실행 규칙 묶음의 효과로 해석하며 한 요인만의 인과 효과로 해석하지 않는다.

별도 `research-type1-engine-*` 계좌 ID와 새 출력 폴더만 사용한다. 운영 설정은 읽고 ID만 바꾼다. 계좌 모드는 replay이며, 운영 한국 forward에만 적용되는 half-risk 보호청산은 추가하지 않는다. 이는 고정 5%/15% 연구의 주문 엔진 검증이지 전체 운영 전략 추천 엔진이나 새 보호청산 정책의 검증이 아니다.

## 실행

```bash
python docs/research/top100_type1_engine_input.py --centers docs/type1-portfolio-v1/centers.json --candidates /path/to/candidates.json --dataset /path/to/dataset --sector-map /path/to/sector-map.json --output /new/path/input.json
php -d memory_limit=1G docs/research/top100_type1_engine.php --input /new/path/input.json --output /new/path/results
php tests/type1_engine_replay.php
```

Python은 T일까지 분류와 계획 가격만 만든다. PHP는 수정하지 않은 PaperQuality, PaperPortfolio, TradeSimulator, CandleClock을 직접 호출한다. 전체 유형/유형1을 독립 계좌로 실행한다. 원본 봉 해시가 바뀌면 실행을 거절한다. 출력 경로가 이미 존재하면 거절하며 기존 계좌를 이어 실행하지 않는다.

매 주문 직전 보유·대기·현금·예약금·위험·섹터 사용량, 취소/차단 원인, 체결·청산·일별 평가액·미완료 계좌 상태를 저장한다. 한도와 현금 보존을 이벤트로 대사한다. 하나라도 미완료이면 종료 코드 2이며, 그 계좌의 전체 기간 수익률은 null이다. 중단일의 부분 실행을 정상 완료일로 세지 않는다.

기존 2년은 이미 사용한 연구 구간이고 유형 1은 사후 선택됐다. 이 검증은 독립 예측력 검증이 아니다. 별도 과거 미사용 기간이나 앞으로의 관찰이 필요하다. 원본 누락·오류는 채우거나 삭제하지 않는다.

## 기록 읽기

전체 기록은 JSON을 XZ로 압축했다. Python `lzma.open(path, "rt")`로 읽을 수 있다. artifacts.json은 압축 해제한 원문 SHA256이다. 입력 파일의 dataset 경로만 현재 위치로 바꾸면 입력 해시는 달라지므로 새 연구 실행으로 기록한다. 원본 입력을 덮어쓰지 않는다.

같은 신호 체결 대조 재현:

```bash
python docs/research/type1_engine_compare.py --old docs/type1-portfolio-v1/recent-results.json.gz --new docs/type1-engine-v1/recent-type1.json.xz --output /new/path/matched.json
```
