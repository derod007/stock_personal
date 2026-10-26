# 계좌 한도와 분리한 개별 거래 진단

유형별 신호의 거래 성과·월별 기회·종목 집중도를 살펴보는 읽기 전용 도구다. 포트폴리오/운영 계좌를 실행하지 않는다. 기업행사 장부 기능을 개발하거나 적용하지 않는다.

```sh
php tests/independent_trade_diagnostic.php
python -m unittest tests.test_independent_summary
php docs/research/type1_independent_trades.php --input=FROZEN_INPUT_JSON --dataset=FROZEN_DATASET --output=NEW_TRADES_JSON
python docs/research/type1_independent_summary.py --input NEW_TRADES_JSON --output NEW_SUMMARY_JSON
```

입력은 기존 분류 신호와 원본 봉 해시를 가진 KRX 단일출처 데이터다. dataset 인수는 같은 바이트를 다른 경로에 복원할 수 있도록 경로만 바꾼다. 심볼별 봉 해시와 가격기준은 검사한다. 출력은 신규 파일만 허용한다.

체결 계산은 기존 TradeSimulator를 사용한다. 날짜별로 한 봉씩 처리하고 결과가 완료되면 즉시 종료한다. 그 전에 자료 문제가 나오면 미확정 손익(null)으로 기록한다. 월별 표는 신호일 기준이고, 평균은 종료한 거래에 한정된다. 미확정/미체결/진입전취소는 분모를 별도 표시한다. 시뮬레이션 비용은 기존 엔진의 수수료10bp/편도 및 슬리피지5bp 규칙이다.

동일 금액으로 각 거래에 참여한다고 가정한 수익률 통계일 뿐 복리·한도·동시 보유·현금·업종 제약이 있는 계좌 성과가 아니다. 분류·진입 전 품질에서 제외된 신호까지 대표하지 않는다. 종료거래 평균을 전체 수익률로 발표하지 않는다.

공개 변경은 일반 코드·가상 테스트·방법 문서만 포함한다. 실제 개별 거래·기업행사 목록·파생 결과는 포함하지 않는다.
