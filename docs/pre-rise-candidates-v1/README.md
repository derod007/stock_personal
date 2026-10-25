# 급등 전날 후보 연구

운영 코드와 무관한 Python/numpy 연구 CLI. 일별 TOP100·과거20세션 합집합은 당시까지 명단만 사용한다. 미래 상승 여부는 후보 선택 뒤 평가한다. 종목·가격·실제 결과를 Git에 올리지 않는다.

1. 기존 PR119 원본 observation/manifest/archive, PR131 observation, 제공 지수ZIP을 준비한다.
2. 가격 캐시가 없으면 `pre_rise_price_cache.py --previous ORIGINAL_FOLDER --archives ARCHIVE_FOLDER --index INDEX_ZIP --protocol PROTOCOL --output CACHE_FOLDER` 실행한다. 원본 종목 가격의 canonical hash를 기존manifest와 대조한다. 기존 검증한 캐시도 사용할 수 있다.
3. `pre_rise_candidates.py --observations PR131_OBSERVATIONS --manifest ORIGINAL_MANIFEST --prices CACHE_FOLDER --index INDEX_ZIP --protocol PROTOCOL --output NEW_OUTPUT_FOLDER`.
4. `python -m unittest discover -s tests -p test_pre_rise_candidates.py`.

결과: summary.json, observations.jsonl, event_coverage.jsonl, validation.json. `jump10`은 100/0/unknown으로 저장되어 평균 단위가 %이다. `net1/5/20`은 다음 시가부터 각 만기까지 비용 반영 수익률(%). event_coverage는 다음날TOP100 급등/PR131돌파에 대한 사후 포착률 감사이며 후보 생성에 사용되지 않는다.

rolling20 첫19세션은 명단 이력 부족으로 주집계에서 제외한다. 소스 구간을 연결하지 않는다. 결과가 좋았던 종목의 전날만 선택하지 않는다. 운영 적용 또는 독립검증 완료를 뜻하지 않는다.
