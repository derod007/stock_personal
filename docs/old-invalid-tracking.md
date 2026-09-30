# 오래된 원본 제외 봉과 후속 품질 검사 일치

`frozen_signal_price_tracking_v3`는 v2가 원본에서도 이미 제외했던 오래된 오류 봉 때문에 추적을 다시 차단하는 문제를 수정합니다.

허용 범위는 다음을 모두 만족하는 봉뿐입니다.

- 원본 일봉 해시 검증을 통과함.
- 원본 전체가 기존 PaperQuality 검사에서 분석 가능함.
- 원본 원시 봉을 재검사했을 때 오류로 확인되고, CandleClock의 완료봉 배열에서도 실제로 제외됨.
- 판정일보다 90일을 초과해 오래됨.
- 추적 입력의 같은 시각 봉도 잘못된 OHLC로 완료봉에서 제외됨.

저장된 quality.invalid_bars 목록만으로 허용하지 않습니다. `reconciliation.excluded_old_invalid_bars`에 날짜, 원본 봉과 추적 봉의 값, 제외 근거를 남깁니다. 해시·원본 데이터·후속 가격 evidence는 수정하지 않습니다. 새로 잘못된 과거 봉, 최근/후속 오류, 중복, 가격 변경, 종목 불일치는 계속 차단합니다. OHLC는 정상이지만 거래량만 잘못된 봉은 완료봉에서 제외됐다고 간주하지 않습니다.

v2로 완료한 추적 캐시도 정책 버전 차이로 한 번 재검증합니다. 적용 후 같은 프로젝트/상태 폴더에서 실행하세요.

```sh
php bin/paper_followup.php --account=paper-kr --saved-evidence
php bin/paper_rediagnose.php --account=paper-kr
```

첫 명령은 기존 가격 evidence의 기준시각까지만 다시 계산합니다. 새로운 시장 자료를 내려받지 않습니다. 실제 복구 건수는 재실행 결과로 확인하며, 원본 일봉 부족·종목 불일치 등의 별도 차단은 유지됩니다.
