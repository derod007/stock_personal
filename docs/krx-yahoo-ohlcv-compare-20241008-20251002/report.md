# 이전 기간 KRX·Yahoo 원본 OHLCV 읽기 전용 대조

2024-10-08~2025-10-02, 합집합 1248종. 저장 KRX 양 시장 원본과 v2 Yahoo 5년 원응답만 읽었다. API 요청·봉 삽입·계좌 실행·병합은 없다. 복구 방식과 어느 공급자가 맞는지는 정하지 않는다.

집계: `summary.json`. 재현: `docs/research/krx_yahoo_ohlcv_compare.py`. 원본 JSON은 Git에 없다.

## 식별

| 축 | 규칙 | 결과 |
|---|---|---|
| 요청일 | KRX 파일명 `YYYYMMDD` | 243일 × 2시장 = 486파일 |
| KRX 날짜 | 각 행 `BAS_DD` | 요청일과 불일치 **0** |
| Yahoo 날짜 | `timestamp` → Asia/Seoul | 초점 4종 18·19·22 모두 같은 한국 날짜 |
| 종목 | 우주 `code`+접미사 vs `ISU_CD` vs Yahoo `meta.symbol` | 로드 1234종 `meta` 일치 실패 0. 인덱스 없음 14. 원본 SHA 불일치 0 |
| KRX 해시 | `file-hashes.json`의 `raw` | **486/486 일치** |

우주 1248행 중 `included` 1247, `45014K.KS`만 `provider_symbol_unmapped`. 비교에는 1248 모두 넣었다. 가격은 TOP100 거래대금으로 만들지 않았다.

## 전체 243일 집계

쌍 후보 1248×243 = 303264.

| 구분 | 건수 |
|---|---|
| 비교 가능 (양쪽 숫자 OHLC) | 287673 |
| OHLCV 완전 일치 (`close` 기준) | 243294 |
| 가격 같고 거래량만 다름 | 66 |
| 가격 다름 (`open/high/low/close` 중 하나, Yahoo `close`) | 44313 |
| Yahoo 시각 있음·OHLCV null | 1228 |
| Yahoo 날짜 없음 | 3921 |
| KRX 행 없음 | 361 |
| 양쪽 없음 | 10081 |

Yahoo `close`와 `adjclose`는 나눴다. 비교 가능 쌍 중 `close`가 KRX 종가와 같고 `adjclose`도 같은 경우 112375, `close`만 같고 `adjclose`는 다른 경우 132317. `adjclose`가 KRX 종가와 같은 건수는 112375로, `close`와 같을 때와 같다.

## 9/18·19에 모이나

**Yahoo null은 2025-09-19에만 있다.** 1228건. 나머지 242일 0건. 9/18은 null이 아니다.

가격 차이는 기간 전체에 있다. 9/18이 하루 최다(1156)이고, 그다음 2025-10-02(806), 2024-11-07(481) 등이다. 9/18·19 밖에도 43156건이다.

| 날 | 비교 가능 | 완전 일치 | 거래량만 | 가격 차이 | Yahoo null | Yahoo 없음 |
|---|---|---|---|---|---|---|
| 2025-09-18 | 1230 | 73 | 1 | **1156** | 0 | 15 |
| 2025-09-19 | 2 | 1 | 0 | 1 | **1228** | 16 |
| 2025-09-22 | 1231 | 1063 | 0 | 168 | 0 | 15 |

9/19에 숫자 Yahoo가 있는 우주 종목은 2개뿐이다.

## 날짜 이동·배율 진단 (적용하지 않음)

가격이 다른 쌍 44313 가운데 Yahoo 종가 = 전일 KRX 종가 743, 익일 KRX 43, KRX 종가 = 전일 Yahoo 67, 익일 Yahoo 23. 소수다. 날짜를 옮기지 않았다.

9/19에 Yahoo 당일 종가는 거의 없고, **다음날 Yahoo 종가 = 당일 KRX 종가**가 46건이다. 값을 바꾸거나 채우지 않았다.

종목별 Yahoo `close`/KRX `TDD_CLSPRC` (20일 이상): 1 근처 433, 거의 일정한 비 1이 아님 28, 날이 섞임 768. 전 종목 공통 배율은 아니다. 28종 예: 2.0, 0.2, 10.0, 5.0, 0.1. 보정하지 않았다.

## 초점 4종 (원본 필드)

식별은 네 종·사흘 모두 같다. 요청일 = `BAS_DD` = Yahoo 한국 날짜. `ISU_CD` = 우주 코드. Yahoo `meta.symbol` = 우주 심볼.

### 298380.KQ 에이비엘바이오 · KOSDAQ · Yahoo SHA `9082ae75…32ed3`

| 일 | 요청/파일 | 분류 | KRX TDD O/H/L/C · VOL | Yahoo O/H/L/C · VOL · adj |
|---|---|---|---|---|
| 18 | `20250918_kosdaq_equity.json` `BAS_DD=20250918` · ts 1758153600 | 가격 차이 (종가만) | 101500 / 101900 / 98500 / **100300** · 496294 | 101500 / 101900 / 98500 / **98600** · 496294 · 98600 |
| 19 | `20250919_kosdaq_equity.json` `BAS_DD=20250919` · ts 1758240000 | Yahoo 시각·null | 99400 / 109700 / 99200 / **108300** · 1602856 | 전부 null |
| 22 | `20250922_kosdaq_equity.json` `BAS_DD=20250922` · ts 1758499200 | OHLCV 일치 | 108600 / 108900 / 105400 / **108300** · 557103 | 동일 · adj 108300 |

KRX 19일 `CMPPREVDD_PRC=8000`(18일 100300+8000=108300). 22일 `CMPPREVDD_PRC=0`. Yahoo 22일 종가 108300은 KRX 19·22 종가와 같다. 이동·채움은 하지 않았다.

### 326030.KS SK바이오팜 · KOSPI · Yahoo SHA `f3c2a47b…0f2170`

| 일 | 분류 | KRX O/H/L/C · VOL | Yahoo O/H/L/C · VOL · adj |
|---|---|---|---|
| 18 | 가격 차이 (종가만) | 107700 / 109000 / 107300 / **107900** · 123126 | 같음 / **107400** · 123126 · 107400 |
| 19 | Yahoo 시각·null | 107700 / 114000 / 107100 / **113500** · 492094 | 전부 null |
| 22 | OHLCV 일치 | 114900 / 114900 / 110700 / **111300** · 167640 | 동일 · adj 111300 |

### 005930.KS 삼성전자 · KOSPI · Yahoo SHA `e7c00264…c3faaa`

| 일 | 분류 | KRX O/H/L/C · VOL | Yahoo O/H/L/C · VOL · adj |
|---|---|---|---|
| 18 | 가격 차이 (종가·adj) | 79100 / 80500 / 78600 / **80500** · 25619035 | 같음 / **80300** · 25619035 · **79304.80** |
| 19 | Yahoo 시각·null | 81100 / 81200 / 79600 / **79700** · 20898386 | 전부 null |
| 22 | OHLCV(`close`) 일치, adj≠KRX | 81500 / 84000 / 81100 / **83500** · 27529656 | 동일 · adj **82465.14** |

22일은 `close` 기준 OHLCV가 KRX와 같고 `adjclose`는 다르다.

### 000660.KS SK하이닉스 · KOSPI

| 일 | 분류 | KRX O/H/L/C · VOL | Yahoo O/H/L/C · VOL · adj |
|---|---|---|---|
| 18 | 가격 차이 (종가·adj) | 338500 / 357000 / 336500 / **353000** · 4526286 | 같음 / **355500** · 4526286 · **354455.16** |
| 19 | Yahoo 시각·null | 351500 / 360000 / 348000 / **353000** · 4385543 | 전부 null |
| 22 | OHLCV(`close`) 일치, adj≠KRX | 349000 / 359000 / 345250 / **351000** · 4377563 | 동일 · adj **349968.34** |

## 입력 해시와 재현

원본은 Git에 넣지 않는다.

| 입력 | SHA256 |
|---|---|
| `universe-v2.json` | `c9e8325209dfb40198692f15090f99a418b7ed09d6f7e36c5bbfe5216d4bcdfc` |
| `candidates.json` | `f3759695ba120216545474251bf607d2a05d6cd46997b98b5aae9cfc307f3fe9` |
| `file-hashes.json` | `bdfe0190a53e20ceb1f54616129c7f2fb8ff5aaf46ce06b0d239bbdbf4e1f0b2` |
| v2 `dataset.json` | `7f0e00fff9ac97e0e3568ad07c648b4e404105e122bb59731741a7693fe633b1` |
| v2 `universe.json` | `f2f96d25c0e1eeba3f31909fb977083cfe5d6f02a77b8c901362fa09eb309a38` |
| KRX 원본 486파일 목록 해시 | `b2868fc0d8e950e4700b458341c4fc4ecf2a9946bc5412a0a43c86d15c290995` |

```
python docs/research/krx_yahoo_ohlcv_compare.py ^
  --universe docs/historical-top100-20241008-20251002/universe-v2.json ^
  --candidates docs/historical-top100-20241008-20251002/candidates.json ^
  --file-hashes docs/historical-top100-20241008-20251002/file-hashes.json ^
  --krx-raw <GIT무시_KRX_raw> ^
  --yahoo-v2 <로컬_v2_데이터셋> ^
  --out docs/krx-yahoo-ohlcv-compare-20241008-20251002
```

HTTP 없음. 기존 v1/v2·정지 계좌·운영 코드·캐시는 읽기만 했다.
