# KRX 준비 구간 원본 수집 (평가 시작 전 240거래일)

PR #89 후속. 평가 시작일 2024-10-08 직전 **확인된 240거래일** KOSPI·KOSDAQ 원본만 수집했다. 가격 변환·배율 보정·Yahoo 혼합·봉 삽입·계좌 실행·병합은 없다. **가격 조정 방식은 정하지 않는다.**

가격 기준: **KRX TDD 원본, 수정 여부 미확정**

원본 JSON과 인증키는 Git에 없다. 운영 코드·캐시·기존 평가 원본·v1/v2·정지 계좌는 보존만 했다.

## 거래일 근거

PR #89의 245일 추정은 확정 달력으로 쓰지 않았다. 주말을 빼고, 아래 평일 휴장만 제외한 뒤 평가 시작 직전 240일을 고정했다. 빈 Open API 응답은 휴장이 아니다.

확정 구간: **2023-10-13 ~ 2024-10-07, 240일**. 평가 시작일 2024-10-08은 포함하지 않는다. 목록: `trading-days.json`. 휴장 근거: `trading-calendar.json`.

| 평일 휴장 | 이름 | 근거 |
|---|---|---|
| 2023-10-02 | 임시공휴일 | [정책브리핑 지정안](https://www.korea.kr/news/policyNewsView.do?newsId=148919798), 관공서 공휴일 |
| 2023-10-03 | 개천절 | 관공서의 공휴일에 관한 규정, [코스닥시장 업무규정 시장의 개폐](http://regulation.krx.co.kr/contents/RGL/03/03020401/RGL03020401.jsp) |
| 2023-10-09 | 한글날 | 관공서 공휴일, 업무규정 |
| 2023-12-25 | 기독탄신일 | 관공서 공휴일 |
| 2023-12-29 | 연말 휴장 | 업무규정: 연말 1일(공휴일·토요일 불산입). 12-31 일요일, 12-30 토요일이라 평일은 12-29. [strn.krx 공지](https://strn.krx.co.kr/corebbs5/BHPSTRN0401/view?bbsSeq=98) |
| 2024-01-01 | 신정 | 관공서 공휴일 |
| 2024-02-09 | 설날 연휴 | 관공서 공휴일, [한국천문연구원 2024 월력요항](https://www.kasi.re.kr/kor/publication/post/newsMaterial/29633) |
| 2024-02-12 | 설날 대체공휴일 | 관공서 공휴일, 월력요항 |
| 2024-03-01 | 삼일절 | 관공서 공휴일 |
| 2024-04-10 | 제22대 국회의원선거 | 관공서 공휴일 제2조, 월력요항, 업무규정 |
| 2024-05-01 | 근로자의 날 | 업무규정(노동절), 근로자의 날 제정에 관한 법률 |
| 2024-05-06 | 어린이날 대체공휴일 | 관공서 공휴일(5-05 일요일), 월력요항 |
| 2024-05-15 | 부처님오신날 | 관공서 공휴일 |
| 2024-06-06 | 현충일 | 관공서 공휴일 |
| 2024-08-15 | 광복절 | 관공서 공휴일 |
| 2024-09-16~18 | 추석 연휴 | 관공서 공휴일 |
| 2024-10-01 | 국군의 날 임시공휴일 | [정책브리핑 지정안](https://www.korea.kr/news/policyNewsView.do?newsId=148933400), 관공서 공휴일, [연합뉴스 거래소 휴장 안내](https://www.yna.co.kr/view/AKR20240910053800008) |
| 2024-10-03 | 개천절 | 관공서 공휴일 |

2024-10-01은 수집 1·2차에서 양 시장 HTTP 200·17바이트 빈 응답이었다. 빈 응답을 휴장으로 보지 않고 `empty_unconfirmed`로 남긴 뒤, 국무회의 임시공휴일 지정과 거래소 휴장 안내로 달력에서 제외했다. 그 자리에는 직전 문서화 평일 **2023-10-13**을 넣었다.

## 수집

원본 폴더(Git 무시): `data/raw/research/krx-prep-bars-before-20241008/raw`. 날짜마다 KOSPI·KOSDAQ. HTTP 200, JSON, `OutBlock_1` 비어 있지 않음, `BAS_DD` = 요청일을 통과한 날만 `ok`.

PR #89 탐침 4파일 SHA256은 모두 일치했다. 달력 안의 2024-10-07 2파일만 복사해 재사용했고, 2023-10-10 2파일은 240일 밖이라 복사하지 않았다. `pr89-reuse.json`.

| 회차 | 요청 | 재시도 | 결과 |
|---|---|---|---|
| 1 | 478 | 0 | 239일 ok. 20241001 빈 응답(휴장 처리 안 함). PR #89 20241007 재사용 |
| 2 | 2 | 0 | 빈 캐시 삭제 후 20241001 재요청. 다시 빈 응답 |
| 3 | 2 | 0 | 달력 수정 후 20231013 양 시장 수집. **240일 ok, blocked 없음** |

합계 요청 **482**. 재시도 0. 최종 원본 **480파일**(240×2). 기록: `collect-attempts.json`, `collect.json`.

## 품질 (1248종 합집합)

준비 원본 240일과 기존 평가 원본 243일(2024-10-08~2025-10-02)을 연결해 집계했다. 신규 상장·거래정지 등으로 부족한 종목을 채우지 않았다. 파일 누락 없음. 일자 파일 안 종목코드 중복 0. 준비 마지막(2024-10-07)과 평가 첫날(2024-10-08) 파일 모두 있다.

| 항목 | 값 |
|---|---|
| 합집합 | 1248 |
| 준비봉 240 충족 | 1071 |
| 준비봉 부족 | 177 (이 중 0봉 103) |
| 평가봉 243 충족 | 1143 |
| 평가봉 부족 | 105 |
| 준비 OHLC 이상 종목 | 44 |
| 평가 OHLC 이상 종목 | 69 |
| 중복 행 | 0 |

준비 OHLC 이상 건수: `ohlc_non_positive` 1870, `close_outside_hl` 1870. 평가: 각각 1997. 같은 행이 두 종류로 잡힌 경우가 많다. 0·음수 가격을 다른 날 값으로 바꾸지 않았다.

종목별 전체: `quality.json`. 요약: `quality-summary.json`.

## Open API 개발 명세서

정상 경로 `downloadApiDoc.cmd` **POST**(페이지 숨은 필드 `path`/`BO_ID`/`BO_VER`/`apiTestUrl`. AUTH_KEY 없음)로 유가증권·코스닥 일별매매정보 docx를 받았다. GET은 항목 없는 짧은 stub이었다.

저장된 본문 필드 표기: `TDD_CLSPRC` 종가, `TDD_OPNPRC` 시가, `TDD_HGPRC` 고가, `TDD_LWPRC` 저가. **원주가·수정주가 문구는 없다.** 따라서 수정 여부는 **미확정**. 파일 본문은 `data/raw/research/krx-prep-bars-before-20241008/spec`(Git 무시). 탐침: `spec-probe.json`.

## SHA256

목록: `file-hashes.json` (**480**). 원본 JSON은 Git에 없다. PR #89에서 재사용한 2파일:

| 파일 | SHA256 |
|---|---|
| 20241007_kospi_equity.json | `1e8370851e06fc1b54180bbc287f087c73a5e59bd52e7a9547f4a9867646ab4f` |
| 20241007_kosdaq_equity.json | `58e2818c6907c57834480415ec1173f85b688b56aedea0267a6f3c9564bc1193` |

## 재현

인증키는 환경변수 `KRX_AUTH_KEY` 또는 로컬 `.krx-auth-key`(Git 무시). 원본은 로컬 `data/raw/`에만 둔다.

```
python docs/research/krx_prep_bars_collect.py --freeze-days
python docs/research/krx_prep_bars_collect.py --spec
python docs/research/krx_prep_bars_collect.py --collect --sleep 1.2
python docs/research/krx_prep_bars_collect.py --hashes
python docs/research/krx_prep_bars_quality.py --prep-raw data/raw/research/krx-prep-bars-before-20241008/raw --eval-raw <기존_평가기간_KRX_raw>
python -m unittest tests.test_krx_prep_bars_collect
```

유효 원본이 있으면 재요청하지 않는다. 빈 응답 캐시는 재사용하지 않고 다시 받는다. 그래도 비면 휴장으로 바꾸지 않는다.
