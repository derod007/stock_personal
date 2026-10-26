# 유형 1 미사용 역사 구간: 자료 수집 및 두 모형 검증

2026-10-18 시작/완료. PR #109 입력 ZIP을 독립 검증한 뒤 2023-07-03~2024-06-28 고정 기간의 Yahoo 일봉을 수집하고, 기존 PR #107/#108 모형을 새 연구 출력에 각각 한 번 실행했다. 결과는 [report.md](report.md), 세부 집계는 [summary.json](summary.json)이다.

## 결과

네 계좌 모두 2024-01-15 가격 품질 문제로 중단. 마지막 정상일은 2024-01-12. 연간 수익률은 산출하지 않는다. 이 정상 구간의 평가수익률은 시가 전체 -18.59% / 유형1 +0.45%, 지정가 전체 -19.78% / 유형1 +2.55%다. 두 유형1 계좌가 상대적으로 좋았으나 부분 구간이며 운영 반영 근거로 확정하지 않는다.

Yahoo 해당일 601개 봉의 OHLC 모순을 저장 원본에서 확인했다. KRX는 비교에만 쓰고 가격에 섞지 않았다. 공급자 원인 및 조정 기준 호환은 미확정이다. 운영/원본/기존 계좌를 수정하지 않았고 연구 계좌도 재실행하지 않았다.

## 입력과 보존

- 사용자 TOP100 ZIP SHA256: `6c2c58a5f94ffe4c6c15c50317da21d7e8da767e0da4613e94db185536bc9afe`.
- 243일·486시장 원본의 재정렬/해시 검증 통과. 후보 1,405시장별 심볼 / 1,403코드. 022100·066970은 날짜별 시장을 보존한다.
- Yahoo 2023-01-01부터 2024-06-29 미만 요청. 1,364파일 저장 / 41미확보. HTTP404 29, HTTP400 5, 상품유형 불일치 7. 미확보를 실제 상장폐지로 단정하지 않는다.
- 신규 일봉은 quote OHLCV를 보존한다. adjclose·KRX 대체·Naver 보정 미적용. 이전 연구 데이터의 보정 파이프라인과 차이가 있다.
- 업종은 기존 두 연구의 현재 업종 맵과 신규 Naver 응답을 사용. 신규392확인/20미확인, 기존미분류9 포함 최종 미분류29. 기존 SectorMap 규칙을 그대로 호출했다. 현재 맥쿼리인프라의 etf 버킷 분류도 임의 수정/제외하지 않았다.
- 중단된 수집은 기록 해시를 확인해 이어받았다. 첫 수집기와 이어받기 실행의 중복 요청 가능성을 고려해, 계좌 실행 직전 원본·봉·기록의 해시가 맞는 별도 사본을 고정했다. 계좌는 이 사본만 사용한다. 계좌 실행 후에도 사본 해시가 유지됨을 확인했다.
- 원본 응답은 GitHub에 올리지 않는다. 코드·요약·대표 3종목 진단만 게시한다. 전체 실행 기록·가격 원본·전체 601종목 대조는 별도 재현 ZIP에 보관한다. 공개 저장소의 압축 실행 기록 업로드는 자동 승인 검토에서 거절되어 포함하지 않았다.
- 고정 중심은 PR107의 centers.json. 미래에 학습한 중심을 과거에 적용했으므로 전진 검증이 아니다. PR107 원시 결과의 `limitations`에는 이전 실험용 고정 문구가 남아 있다. 이번 평가 기간은 미사용 구간이나 연구 전체의 사후 선택 문제는 여전히 남는다.

## 재현 순서

각 명령은 독립 경로에서 실행한다. `<INPUT>`은 업로드 ZIP 해제 폴더, `<COLLECT>`, `<SECTOR>`, `<FROZEN>`, `<RUN>`은 별도 연구 폴더다. 계좌 출력은 새 폴더만 허용한다. 재수집은 공급자 데이터 개정으로 동일 결과를 보장하지 않으므로 저장된 사본과 해시를 우선한다.

```text
python docs/research/type1_holdout_bars.py --candidates <INPUT>/docs/candidates.json --output <COLLECT>
python docs/research/type1_holdout_sectors.py --candidates <INPUT>/docs/candidates.json --output <SECTOR>
php docs/research/type1_holdout_sector_map.php --input=<SECTOR>/evidence.json --output=<SECTOR>/sector-map.json
python docs/research/type1_holdout_freeze.py --source <COLLECT> --output <FROZEN>
python docs/research/top100_type1_engine_input.py --centers docs/type1-portfolio-v1/centers.json --candidates <INPUT>/docs/candidates.json --dataset <FROZEN> --sector-map <SECTOR>/sector-map.json --output <RUN>/engine-input.json
python docs/research/top100_type1_portfolio.py --centers docs/type1-portfolio-v1/centers.json --candidates <INPUT>/docs/candidates.json --dataset <FROZEN> --sector-map <SECTOR>/sector-map.json --output <RUN>/opening
php docs/research/top100_type1_engine.php --input=<RUN>/engine-input.json --output=<RUN>/limit
python docs/research/type1_holdout_price_audit.py --dataset <FROZEN> --krx <INPUT> --output docs/type1-holdout-evaluation-v1/price-audit.json
python docs/research/type1_holdout_report.py --dataset <FROZEN> --sectors <SECTOR>/sector-map.json --run <RUN> --report docs/type1-holdout-evaluation-v1
```

## 검사

Python 17테스트·PHP 21checks 통과. quote/adjclose 구분, 잘못된 OHLC 및 null 보존, 심볼 불일치 거절, 기간 밖 봉 제외, 원본 훼손 시 사본 생성 실패, 사본 독립성, 기존 시가/지정가 실행 규칙을 검사했다. 지정가 두 계좌의 현금/주문한도 자체 대조도 통과했다.

```text
python -m unittest tests.test_type1_holdout_bars tests.test_type1_holdout_freeze tests.test_top100_type1_portfolio
php tests/type1_engine_replay.php
```
