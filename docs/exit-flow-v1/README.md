# 고정 진입의 유동적 청산 비교

연구 전용 Python/numpy CLI. PR133의 선택 기준과 진입 후보를 그대로 사용한다. 시장데이터·결과는 공개하지 않는다.

```
python docs/research/exit_flow_study.py --source PR133_RESULT_FOLDER --prices VERIFIED_CACHE_FOLDER --manifest ORIGINAL_INPUT_MANIFEST --protocol PROTOCOL --output NEW_OUTPUT
python -m unittest discover -s tests -p test_exit_flow_study.py
```

파일: summary.json, trades.jsonl.gz, validation.json. 가격 캐시는 PR132 연구 준비 도구로 만들 수 있다. 저장캐시 해시는 PR133과 같아야 한다. trades는 압축 바이트를 복원 대조해 저장한다.

common_reference는 네 방식 모두 청산/말일 평가가격이 있고 원래 손절 없는20봉 결과도 있는 공통 진입이다. 방식별 전체 완료율과 unknown은 all에 별도로 있다. 유동 청산은20봉이 지나도 유지될 수 있다. open 값은 실현이익이 아니라 말일 평가손익이며 매도비용을 가정해 차감한다. 종목별 여러 신호의 수익을 독립 관찰로 계산하므로 계좌 손익으로 합치거나 연율화하지 않는다.

종가 확정 신호는 다음 거래일부터 실행한다. 동일봉 손절/목표 동시 도달은 손절 우선 모형이며 실제 순서 확인은 아니다. 파라미터를 이 결과로 최적화하지 않는다.
