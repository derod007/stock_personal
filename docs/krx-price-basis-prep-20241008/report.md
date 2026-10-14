# KRX 가격 기준·MA240 준비 구간 접근 확인

PR #88 후속. 평가 기간 2024-10-08~2025-10-02. 운영 코드·캐시·원본·v1/v2·정지 계좌는 읽기만 했다. 가격 보정·봉 삽입·계좌 실행·전체 수집·병합은 없다. **최종 가격 조정 방식은 정하지 않는다.**

집계: `summary.json`(기존 원본 재사용). 접근 확인: `probe-summary.json`(본호출 4회). 도구: `docs/research/krx_yahoo_ohlcv_compare.py`, `docs/research/krx_price_basis_prep_probe.py`. 인증키와 원본 JSON은 Git에 없다.

## TDD OHLC 원주가·수정주가 — 미확정

KRX Open API 일별 주권 안내에 **원주가/수정주가 문구가 없다.** 필드명 `TDD_*`만으로 기준을 확정하지 않는다. 개발 명세서는 페이지에 다운로드 링크만 있고, 이번 조사에서 파일 본문을 확보하지 못했다.

| 출처 | 확인 문구 | 링크 |
|---|---|---|
| 서비스 목록 | 유가증권 일별매매정보: 「유가증권시장에 상장되어 있는 주권의 매매정보 제공 ('10년01월04일 데이터부터 제공)」. 목록에 수정주가·수정계수·기업행사 API 없음 | [OPPINFO004](https://openapi.krx.co.kr/contents/OPP/INFO/service/OPPINFO004.cmd) |
| 유가증권 일별매매정보 | 동일 설명. API ID `stk_bydd_trd`. 웹 표의 항목명/출력명 본문은 비어 있었고, 「개발 명세서 다운로드」만 표시 | [OPPUSES002_S2 `stk_bydd_trd`](https://openapi.krx.co.kr/contents/OPP/USES/service/OPPUSES002_S2.cmd?BO_ID=JvJFzlAENzZlPBDNGAWC) |
| 코스닥 일별매매정보 | 「코스닥시장에 상장되어 있는 주권의 매매정보 제공 ('10년01월04일 데이터부터 제공)」. API ID `ksq_bydd_trd` | [OPPUSES002_S2 `ksq_bydd_trd`](https://openapi.krx.co.kr/contents/OPP/USES/service/OPPUSES002_S2.cmd?BO_ID=hZjGpkllgCBCWqeTsYFj) |

Open API와 **다른 웹 화면**인 정보데이터시스템 개별종목 시세추이에는 수정주가 선택 안내가 있다. 이 문구는 Open API TDD 기준을 말해 주지 않는다.

> 수정주가란 유무상증자, 주식배당, 액면변경 등으로 주가가 연속성을 잃고 단층현상이 생기게 되는 경우, 주가 비교의 연속성을 위해 기준시점(권리락일 등)을 기준으로 이전 시점의 주가를 조정한 가격입니다. 이용자께서는 필요에 따라 수정주가 사용여부를 선택하시어 주가등락률 정보를 활용하시기 바랍니다.

- [MDCSTAT017 개별종목 시세추이](https://data.krx.co.kr/contents/MDC/STAT/standard/MDCSTAT017.jsp)

## 기업행사·수정계수 공식 제공

| 경로 | 결과 |
|---|---|
| Open API 공식 31종 목록 | 수정주가·수정계수·분할·병합·무상증자·배당 전용 API **없음** |
| KIND·DART | 공시 문서는 있다. 수정계수 시계열 API는 Open API 목록에 **없음** |
| data.krx 개별종목 시세추이 | 웹에서 수정주가 적용 여부를 고르는 별도 상품. Open API가 아님. 이전 수집에서 비회원 `getJsonData`는 LOGOUT/400 이었다 |

인증·한도([이용약관](https://openapi.krx.co.kr/contents/OPP/INFO/OPPINFO002.jsp), [이용방법](https://openapi.krx.co.kr/contents/OPP/INFO/OPPINFO003.jsp)):

- 인증키: 마켓플레이스 로그인 후 신청, 관리자 승인, API별 활용신청
- 이용기간: 제공일부터 1년, 연장 가능
- 「하나의 키당 1일(매일 0시~24시) 10,000회 이하의 요청으로 제한하며, 이를 초과할 경우 서비스가 중지될 수 있다.」
- 12개월 미사용 시 삭제될 수 있음. 비상업. 제3자 제공 금지

임의 배율 보정은 하지 않았다.

## MA240 준비 구간

`PaperHistoryResearch::PREP_BARS=240`, `preparation_start_day = evalStartDay(2024-10-08) = 2023-10-08`.

기존 KRX 원본(평가 기간): 2024-10-08~2025-10-02, 243일 × 2시장. **2023-10-10·2024-10-07 파일 없음.** Yahoo v2 5년 원본은 재사용만 했고 재조회하지 않았다.

접근 확인 4회(전체 수집 아님). HTTP 200, `OutBlock_1` 비어 있지 않음, `BAS_DD` = 요청일.

| 요청일 | 시장 | n |
|---|---|---|
| 2023-10-10 | KOSPI | 952 |
| 2023-10-10 | KOSDAQ | 1679 |
| 2024-10-07 | KOSPI | 959 |
| 2024-10-07 | KOSDAQ | 1754 |

공식 제공 시작은 2010-01-04이므로 준비 구간은 **원칙적으로 호출 가능**하다. 기존 평가 원본만으로는 240거래일이 부족하다.

추가 거래일 추정: 2023-10-10~2024-10-07 평일에서 목록 휴일 15일을 뺀 **245일**(휴일 목록 완전하지 않음. 빈 API로 휴장 확정하지 않음). 전체 수집 시 **약 490회**(245×2). 한도 10,000회/키/일 안. 이번엔 4회만.

## PR #88 불일치 추가 집계

기존 원본만. 가격 차이 44313은 PR #88과 같다. 이를 **종가만 다름 2305 / OHL도 다름 42008**으로 나눴다.

| 구분 | 전체 | 9/18·19 | 그 밖 |
|---|---|---|---|
| 가격 다름 | 44313 | 1157 | 43156 |
| 종가만 다름 (OHL 같음) | 2305 | 990 | 1315 |
| OHL도 다름 | 42008 | 167 | 41841 |

KRX 해시 486/486 일치.

## 배율 사례와 공식 공시 (원인 미확정)

Yahoo `close` / KRX `TDD_CLSPRC`가 20일 이상 거의 일정하고 1이 아닌 종목 28. 보정하지 않았다. 아래는 대표 5건이다. 공시가 있어도 **배율의 원인으로 확정하지 않는다.**

| 심볼 | 종목 | 중앙값 | 공식 공시 (평가 기간 2024-10-08~2025-10-02과 날짜만 대조) |
|---|---|---|---|
| 001380.KS | SG글로벌 | 2.0 | 분할·무상 제목 공시 미발견. [2024-11-14 분기보고서](https://kind.krx.co.kr/external/2024/11/14/000408/20241114000821/11013.htm) 보통주 액면 500원, 발행주식 2022~2024말 44,964,143주로 동일 |
| 001510.KS | SK증권 | 2.0 | 평가 기간 **밖**. KIND [변경상장(액면병합)](https://kind.krx.co.kr/external/2026/04/22/000186/20260422000598/68155.htm) 변경상장일 2026-04-27, 1주 금액 500원→1,000원 |
| 059120.KQ | 아진엑스텍 | 0.5 | 평가 기간 **밖**. KIND [무상증자결정](https://kind.krx.co.kr/common/disclsviewer.do?acptNo=20260114000158&docno=&method=searchInitInfo) 2026-01-14. [2025-03-20 사업보고서](https://kind.krx.co.kr/external/2025/03/20/000753/20250320003336/11011.htm) 액면 500원, 2022~2024말 발행주식 9,748,596주 |
| 003350.KS | 한국화장품제조 | 0.2 | 평가 기간 **밖**. KIND [액면분할 기준가격 안내](https://kind.krx.co.kr/external/2026/04/17/000505/20260417001307/99322.htm) 적용일 2026-04-20, 사유 액면분할, 보통주 기준가격 9,640원 |
| 006050.KQ | 국영지앤엠 | 10.0 | 분할·무상 제목 공시 미발견. [2025-03-17 사업보고서](https://kind.krx.co.kr/external/2025/03/17/001215/20250317005153/11011.htm) 액면 500원, 발행주식 2022~2024말 34,895,243주로 동일 |

참고(같은 조건, 원인 미확정): 024850.KQ HLB이노베이션 중앙값 5.0 — 액면분할 기준가격 안내 미발견, KIND [정정 유상증자결정](https://kind.krx.co.kr/common/disclsviewer.do?acptno=20240927000501&langTpCd=0&method=search&orgid=F&rcpno=20240927000660&tran=Y) 2024-09-27. 058430.KS 포스코스틸리온 중앙값 0.1 — KIND [공정공시 주식 액면분할 추진 계획](https://kind.krx.co.kr/external/2026/02/02/001255/20251212000544/99621.htm) 2026-02-02(이사회 전 사전공시, 평가 기간 밖).

## 짧은 정리

- **추가 거래일:** 평가 시작 전 약 245일(추정). 기존 원본 0일.
- **예상 요청:** 전체 수집 시 약 490회. 이번 접근 확인 4회.
- **접근 제한:** AUTH_KEY, 1일 10,000회, 1년, 관리자 승인·API 활용신청, 비상업·제3자 제공 금지.
- **미확정:** Open API TDD가 원주가인지 수정주가인지. 개발 명세서 본문. 수정계수 공식 API. 28종 배율의 원인. 휴일 목록의 완전성.

```
python docs/research/krx_yahoo_ohlcv_compare.py --universe ... --krx-raw <GIT무시_KRX_raw> --yahoo-v2 <로컬_v2> --out docs/krx-price-basis-prep-20241008
python docs/research/krx_price_basis_prep_probe.py --krx-raw <GIT무시_KRX_raw>
python -m unittest tests.test_krx_yahoo_ohlcv_compare tests.test_krx_price_basis_prep_probe
```
