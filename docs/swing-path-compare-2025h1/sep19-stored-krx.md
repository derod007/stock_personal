# 2025-09-19 다섯 종목, 저장된 KRX 원본

20거래일 관찰이 2025-09-19 봉 없음으로 `미확정`인 다섯 종목만 본다. 대상은 `067310.KQ`, `104620.KQ`, `128940.KS`, `089890.KQ`, `095610.KQ`이다.

이미 끝난 조사는 반복하지 않는다. 시장이 열린 근거는 `undetermined-20.md`, 저장 일봉에 그날 봉이 없다는 세기는 `sep19-bar-presence.md`에 있다. 네 종목(298380.KQ, 326030.KS, 005930.KS, 000660.KS) Yahoo·KRX 대조는 `docs/yahoo-20250919-gap-probe/`에 있고, 그 결과를 이 다섯 종목이나 봉이 없는 329종목에 옮기지 않는다.

가격 수집, 봉 보충, 운영 CLI, 실험 재계산은 하지 않았다. 운영 일봉, 원본, 달력, 계좌, 예약, 매매 규칙, `summary.json`, 보존한 묶음 보고서는 바꾸지 않았다.

## 저장된 KRX 원본

이전 네 종목 조사가 적은 원본 폴더는 없다.

`C:\Users\acdun\Desktop\dev\noramu-work-top100-202410\data\raw\research\historical-amount-top100-20241008-20251002\raw`

같은 조사의 Yahoo 응답 작업 폴더도 없다.

`C:\Users\acdun\Desktop\dev\noramu-work-yahoo-20250919-gap`

`20250919_kospi_equity.json`과 `20250919_kosdaq_equity.json`을 `C:\Users\acdun\Desktop`, `C:\Users\acdun\Desktop\dev`, `C:\Users\acdun\Documents`, `C:\Users\acdun\Downloads`, `data\raw\research`에서 찾았고 없었다. 이번 작업에서 새로 받지 않았다.

지금 레포에 남아 있는 시장 전체 equity 덤프는 그 날짜가 아니다.

| 폴더 | 가장 이른 파일 |
|---|---|
| `data/raw/research/historical-amount-top100-202510/raw` | `20251001_kospi_equity.json`, `20251001_kosdaq_equity.json` |
| `data/raw/research/historical-amount-top100-20251008-20261008/raw` | `20251010_kospi_equity.json`, `20251010_kosdaq_equity.json` |

2025-09-18·19·22 원본이 없으므로 다섯 종목코드를 열어 시가·고가·저가·종가·거래량을 적지 않았다. 행이 있는지와 유효한 가격·거래량이 있는지는 둘 다 미확정이다. 경로, 필드, sha256도 이번 다섯 종목에 대해 적을 파일이 없다.

## 저장된 Yahoo 자료

이 다섯 종목의 Yahoo chart 응답 본문(timestamp와 open/high/low/close/volume/adjclose 배열)은 찾지 못했다. 이전 조사의 저장 5년 원응답은 위 네 종목 것이고, 그 작업 폴더도 없다.

있는 파일은 완성 봉만 남긴 캐시다. `YahooChartClient`는 응답 본문을 저장하지 않고 `data/ohlcv/{종목}_{구간}_{간격}_closed_v2.json`에 가공한 봉을 쓴다. 그 캐시에 2025-09-19 봉이 없다는 사실만으로, 응답에 날짜가 없었는지 날짜는 있고 OHLCV가 null이었는지 구분하지 않는다.

| 종목 | 2025-09-19를 덮는 가공 일봉 | 그 밖 일봉 캐시의 첫 봉 |
|---|---|---|
| 067310.KQ | `067310.KQ_2y_1d_closed_v2.json`에 봉 없음 (`sep19-bar-presence.md`) | 3mo `2026-06-10`, 6mo `2026-02-10` |
| 104620.KQ | `104620.KQ_2y_1d_closed_v2.json`에 봉 없음 | 2y 가공 캐시 외 일봉 파일 없음 |
| 128940.KS | `128940.KS_2y_1d_closed_v2.json`에 봉 없음 | 3mo `2026-06-15` |
| 089890.KQ | `089890.KQ_2y_1d_closed_v2.json`에 봉 없음 | 2y 가공 캐시 외 일봉 파일 없음 |
| 095610.KQ | `095610.KQ_2y_1d_closed_v2.json`에 봉 없음 | 3mo `2026-06-11` |

3mo·6mo 첫 봉은 2025-09-19 이후다. 시간봉 캐시는 이 날짜 대조에 쓰지 않았다.

## 9월 18·19·22일

KRX 원본이 없어 다섯 종목의 18·19·22일을 Yahoo와 대조하지 않았다. Yahoo와 KRX의 수정주가 여부와 가격 기준이 같은지는 확인할 근거가 없어 미확정이다. 네 종목 조사에서 9월 18일 종가가 달랐고 9월 22일 종가가 같았던 사실을 여기로 가져오지 않는다.

KRX 봉이 있더라도 기존 Yahoo 일봉에 바로 섞을 수 있다고 보지 않는다. 이번 조사에서는 그 봉 자체를 확인하지 못했다.

## 종목별

다섯 종목의 확인된 사실과 남은 불확실성은 같다. 종목코드만 다르다.

| 종목 | 확인된 사실 | 남은 불확실성 | 근거 |
|---|---|---|---|
| 067310.KQ | 기록된 2025-09-19 KRX 원본과 이 종목의 Yahoo chart 원본이 로컬에 없다. 가공 2년 일봉에는 그날 봉이 없다. | 원본 행 유무, 유효 가격·거래량, 응답의 날짜 부재와 null OHLCV, 18·22일 가격 기준 | 이 문서의 경로 확인, `sep19-bar-presence.md`, `docs/yahoo-20250919-gap-probe/comparison.json`의 `krx_original_dir` |
| 104620.KQ | 같음 | 같음 | 같음 |
| 128940.KS | 같음. 코스피 원본 파일명으로 찾아야 하나 그 파일도 없다. | 같음 | 같음 |
| 089890.KQ | 같음 | 같음 | 같음 |
| 095610.KQ | 같음 | 같음 | 같음 |

## 다음 연구용 자료를 만들 조건

운영 일봉이나 이번 실험 입력에 넣지 않는 별도 자료로만, 아래가 갖춰진 다음에 검토한다.

1. 2025-09-18·19·22 KRX 원본 파일을 다시 찾거나, 새 수집은 그 파일만 두는 별도 작업으로 한다.
2. 다섯 종목의 Yahoo chart 응답 본문을 따로 두어, 날짜가 없는지와 OHLCV가 null인지를 그 본문에서 구분한다.
3. 수정주가 기준을 정하기 전에는 KRX 가격을 Yahoo 일봉과 한 시계열로 잇지 않는다.
4. 네 종목 대조나 329종목 봉 없음을 이 다섯 종목의 거래 여부·가격 근거로 쓰지 않는다.
