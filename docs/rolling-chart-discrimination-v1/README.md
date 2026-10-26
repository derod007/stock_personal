# 매일 이동하는 D−20..D 차트 비교

Python + numpy 연구 전용. 차트 예시는 matplotlib 추가 필요. 운영 모듈을 불러오지 않는다.

입력은 PR132 관찰별 결과와 validation.json, 검증한3개 가격 npz 캐시다. PR132 precursor가 참인 행만 고르는 것이 아니라 rolling20 전체 eligible을 사용한다. 20봉 자료 부족/가격 오류는 미확정으로 보존한다.

```
python docs/research/rolling_chart_features.py --input OBSERVATIONS --input_validation VALIDATION --prices CACHE_FOLDER --protocol PROTOCOL --output NEW_OUTPUT
python docs/research/rolling_chart_examples.py --results NEW_OUTPUT --prices CACHE_FOLDER
python -m unittest discover -s tests -p test_rolling_chart_features.py
```

결과: summary.json, cuts.json, selection.json, observations.jsonl.gz, validation.json. 차트명령은 examples.json과 chart-comparison.html, PNG를 생성한다. 관찰별 파일은 gzip 압축해 쓰고 복원 바이트와 행 수를 검사한다.

특징의 3분위 경계는 첫 기간의 과거 특징만으로 정하고, 미래 결과를 이용한 특징 선택도 첫 기간에서만 한다. 이 선택 편향과 이미 분석한 기간이라는 점을 명시한다. 뒤 기간에서 재선택·재학습하지 않는다. 날짜별 가중·종목별 가중·비중첩 결과가 다를 수 있어 모두 보존한다.

+15% 고가 도달은 이익 실현이 아니다. 예시의 미도달도 반드시 손실인 것은 아니다. 차트 쌍은 결과를 알고 골라 보여주는 사례이며 성과를 증명하는 표본으로 쓰지 않는다. 실제 데이터·성과는 비공개 결과 패키지로 보존한다.
