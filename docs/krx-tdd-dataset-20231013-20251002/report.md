# KRX TDD 연구 데이터셋 (PR #91 후속)

PR #91 재집계 보고(`docs/krx-prep-ohlcv-recount-20241008/`)는 그대로 둔다. 운영 코드·전략 지문·계좌·기존 원본은 보존. 추가 수집·Yahoo 혼합·보간·배율 보정·계좌 실행·병합은 없다.

가격 기준: **KRX TDD 원본, 수정 여부 미확정**

## 1. 분류 세분과 숫자 비교

`classify_row`가 거래량 **null / 0 / 음수 / 양수**와 **정상 OHLC / 정상 OHLCV(거래량>0)**를 나눈다. 0가격 행은 정상 봉이 아니다.

지정 suffix만 (PR #91과 같은 조회):

| 준비 240일 | PR #91 `normal` | 이번 정상 OHLC | 이번 정상 OHLCV |
|---|---|---|---|
| 건수 | 264,680 | 264,680 | 264,680 |

지정 suffix에서 정상 OHLC와 정상 OHLCV가 **같다**. 거래량 null·음수인 정상 OHLC 행은 없었고, 거래량 0은 OHL=0 행(1,870)에만 붙었다. PR #91 지정 suffix 숫자는 바뀌지 않았다.

연결 시계열(아래 3종)을 쓰면 준비 정상 OHLCV는 **264,977** (+297). 행 없음 32,970 → 32,673.

정상 OHLCV 240종: 지정 suffix 1,027 → 연결 후 **1,030**. MA240 준비 완료로 단정하지 않는다.

## 2. 연구 데이터셋

대상: 기존 1248종 × 준비 240일 × 평가 243일. 원값 보존. 0가격 행을 지워 연속 정상봉처럼 만들지 않았다. 이후 이상이 있어도 그 종목의 과거 정상 구간을 통째로 빼지 않았다.

로컬(Git 무시): `data/raw/research/krx-tdd-dataset-20231013-20251002/bars/{symbol}.jsonl`  
각 줄에 날짜·종목·당시 시장·lookup·status·원 OHLCV·원본 파일명·SHA256.

status: `normal_ohlcv` | `normal_ohlc_not_ohlcv` | `ohl_zero` | `no_row` | `other_abnormal`. 가격 단절은 별도 목록.

문서: `summary.json`, `symbol-index.json`, `manifest.json`.

## 3. 이전상장 3종 (ISU_CD만 연결)

이름 문자열은 조인 키가 아니다. 공식 근거가 있는 3종만, **이전 첫 거래일 전** 반대 시장 행을 잇는다.

| 심볼 | ISU_CD | 근거 | 첫 유가증권 일 | 연결 전 지정행 | 연결한 반대시장 | 연결 후 |
|---|---|---|---|---|---|---|
| 022100.KS | 022100 | KIND 사업보고서 유가증권시장 상장 2024-01-02 | 2024-01-02 | 429 | 54 | 483 |
| 034230.KS | 034230 | 회사 IR: 종목코드 034230, 2024-06-24 이전상장 | 2024-06-24 | 313 | 170 | 483 |
| 066970.KS | 066970 | KIND 분기보고서 2024-01-29 이전상장 | 2024-01-29 | 410 | 73 | 483 |

연결 후 세 종 모두 달력 483일에 행이 있다(정상 OHLCV). 상세: `transfers.json`.

## 4. 가격 단절 96건

탐지 기준은 PR #91과 같다. 배율 보정·임의 제외 규칙은 없다. 목록: `price-breaks.json` (전후 OHLCV, 관측 사이 달력 공백, 평가 TOP100 여부).

| 구분 | 건수 |
|---|---|
| 지정 suffix | 96 (PR #91과 같음) |
| 연결 시계열 | 96 |
| 평가일 TOP100 | 6 / 비TOP100 37 / 평가 TOP100 파일 밖(준비 구간 등) 53 |
| 공식 확인 기업행사 | 2 |
| 미확정 | 94 |

확인된 2건(보정하지 않음):

- 083450.KQ 2024-06-25→06-26 비 0.4988. KIND [무상증자결정](https://kind.krx.co.kr/common/disclsviewer.do?acptno=20240611000204) 1주당 1주, 권리락 2024-06-26.
- 086280.KS 2024-07-11→07-12 비 0.4947. KIND [무상증자결정](https://kind.krx.co.kr/common/disclsviewer.do?acptno=20240628000276) 1주당 1주, 배정기준일 2024-07-15.

나머지 94건은 미확정. 0-OHL 다음날 점프도 정지로 단정하지 않는다.

## 재현

```
python -m unittest tests.test_krx_prep_ohlcv_recount tests.test_krx_tdd_dataset
python docs/research/krx_tdd_dataset.py --prep-raw <준비_raw> --eval-raw <평가_raw> --eval-selected <평가_selected>
```
