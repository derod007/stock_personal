# PR #103~#134 통합

기준: #135가 병합된 main `4e3990890968064ef85501721ca3d7625656ba1e`. 32개 PR을 원래 의존 순서대로 통합했습니다. 각 원본 PR의 커밋을 이력에 보존합니다.

## 범위

연구 도구·보고서·테스트를 한 번에 반영하기 위한 통합입니다. 연구 성과를 운영 전략으로 채택하는 변경이 아닙니다. 기존 공개 PR에 있던 압축 자료는 그대로 포함하고 새로운 실데이터는 추가하지 않았습니다.

실제 화면 변경은 #118의 `paper.php` 저점 파동 근거 패널이며 요청할 때 저장 봉만 읽습니다. 점수·주문에는 반영하지 않습니다. #135 유형1 화면은 그대로 유지합니다.

전략 지문 대상 파일·설정·예약은 main과 바이트가 같습니다. 사용자 장부/계좌 실행과 외부 시세 수집은 하지 않았습니다.

## 통합 조정

- 작업 이력은 기존 main 기록과 #103~#106 기록을 보존하여 정리했습니다.
- Python 연구 테스트의 numpy/pandas 버전을 고정하고 CI에 설치합니다.
- PR107 고정 원본 경로를 CI 환경변수로 지정하여 시가모형 검사를 건너뛰지 않습니다.
- 초기 점수 검사는 과거 커밋의 소스를 별도 체크아웃해서 사용합니다. 현재 점수 코드로 대체하지 않습니다.
- 새 PHP 연구 검사와 저점 패널 검사를 CI에 추가합니다.

## 포함 PR

| PR | 내용 | 통합한 커밋 |
|---|---|---|
| [#103](https://github.com/derod007/stock_personal/pull/103) | TOP100 판단일 차트 공통점 후보 탐색 v1 | `d73d7cce582496613580f684d6bf8fed33e11167` |
| [#104](https://github.com/derod007/stock_personal/pull/104) | TOP100 최초 진입 구간 관찰 v2 | `14754cc801af598ab38b0ffd03964969ca73c0c9` |
| [#105](https://github.com/derod007/stock_personal/pull/105) | TOP100 60봉 상승률·거래량 비 3분위 탐색 v3 | `39a134948d5beceda28b36bf33a05e6c70210c3c` |
| [#106](https://github.com/derod007/stock_personal/pull/106) | 상승 전 60봉 가격·거래량 6유형 탐색 v4 | `b478104a5ba9eafa7227ece04c507e305fa22039` |
| [#107](https://github.com/derod007/stock_personal/pull/107) | 연구: 유형 1의 계좌 한도 적용 결과와 검증 도구 | `c7f10e234bf0b0ee449431932121c7dcd2d2052e` |
| [#108](https://github.com/derod007/stock_personal/pull/108) | 연구: 유형 1 운영 주문 엔진 재생과 시가 모형 차이 검증 | `efb19934e0928d0e01cd9171ee8390fcdaacd369` |
| [#109](https://github.com/derod007/stock_personal/pull/109) | 연구: 미사용 243거래일 TOP100 수집 준비와 독립 실행 패키지 | `a4475b9c3153ec125090a08971a20d6ec000d22c` |
| [#110](https://github.com/derod007/stock_personal/pull/110) | 연구: 유형1 미사용 역사 구간 검증 및 2024-01-15 가격 오류 진단 | `96a8ab566a061e85aacccd038f0d0f95cebb0d09` |
| [#111](https://github.com/derod007/stock_personal/pull/111) | 연구: KRX 가격 입력 감사 및 2023년 상반기 준비 이력 수집 | `7cd95075b743a396d748b668e899fae2358719ce` |
| [#112](https://github.com/derod007/stock_personal/pull/112) | Research: audit joined KRX prehistory and evaluation continuity | `719e497e805cb1170014258bab1ac286dcfc1be2` |
| [#113](https://github.com/derod007/stock_personal/pull/113) | Research: KRX 단일 출처 유형1 계좌 연결과 중단 비교 | `e574b2bd3647ae939fd0993a0d936277eb65b59d` |
| [#114](https://github.com/derod007/stock_personal/pull/114) | Research: 가상 이벤트 기반 미체결 주문 취소와 예약금 대사 | `ec73bbf36bcfa978a0175ebf981c07634fd17e87` |
| [#115](https://github.com/derod007/stock_personal/pull/115) | Research: 독립 거래 진단과 미확정 손익 분리 | `a909ecd9ce4c4dbf9071f9470c0c617d6f9a8a30` |
| [#116](https://github.com/derod007/stock_personal/pull/116) | Research: read-only type1 stop recovery audit | `0d3c91d9c270f49eaf060471f2be19bcfad6f643` |
| [#117](https://github.com/derod007/stock_personal/pull/117) | Research: existing inverse-bottom score alone on dated TOP100 | `90eff223f70abf3e5eb49b66fa082b465288cbf2` |
| [#118](https://github.com/derod007/stock_personal/pull/118) | Add bottom-wave structure evidence and read-only main panel | `18c2f4adea760f119498ce40d8bffcbb0a8f9013` |
| [#119](https://github.com/derod007/stock_personal/pull/119) | 연구: 초기 종합점수만 사용하는 오프라인 진단 | `94ed9780441f16a1b81fe1b8ebaff13b484154b6` |
| [#120](https://github.com/derod007/stock_personal/pull/120) | 연구: 초기 종합점수의 같은 날짜 TOP100 대비 선별력 진단 | `de42b063b531254fb055f782c4ff70da552023e1` |
| [#121](https://github.com/derod007/stock_personal/pull/121) | 연구: KRX 전체 원본의 거래대금 순위 구간 비교 | `1cc980011d9f6ccd6056f194b28259062ccc098e` |
| [#122](https://github.com/derod007/stock_personal/pull/122) | 연구: 저장 주문의 진입 전 취소 선후관계 감사 | `6042afcb28475e3b622852c6dae1e009b7d00b83` |
| [#123](https://github.com/derod007/stock_personal/pull/123) | 연구: 보완 KRX 입력에 고정 시가 매수 모형 적용 | `428aaf3534f3b81a5237ee031d2a926dde1fe6f2` |
| [#124](https://github.com/derod007/stock_personal/pull/124) | 연구: 계좌 한도 없는 유형1 시가 독립 거래 검사 | `5b8dd2683a3429c954e43d6b12bf10f98f8f9fe1` |
| [#125](https://github.com/derod007/stock_personal/pull/125) | 연구: 유형1 저장 거래의 피보나치 되돌림 구간 진단 | `647718d89b1bbef325bd916d4046d4d743f394c8` |
| [#126](https://github.com/derod007/stock_personal/pull/126) | 연구: 기존 추천·군집 없는 피보나치와 파동 후보 전략 | `fb7719769afe3a946d83debeab561357c87d027b` |
| [#127](https://github.com/derod007/stock_personal/pull/127) | 연구: 유형1 고정 신호의 1·2거래일 진입 지연 비교 | `e7e0410c806a9ea8475f97a3909668fc122b74ed` |
| [#128](https://github.com/derod007/stock_personal/pull/128) | Research: 유형1 거래별 매수 전후 차트와 수익·손실 비교 | `1fb6252dc7366cfdcac7516154b1cc71112c8f64` |
| [#129](https://github.com/derod007/stock_personal/pull/129) | Research: 유형1 동일 진입의 5%·10% 손절 비교 | `4659a09bd298821ceb862a21276a03ba5855ae75` |
| [#130](https://github.com/derod007/stock_personal/pull/130) | Research: 기존 종합점수 체결의 구조·5%·10% 손절 비교 | `2484d353f6749339e51cf2d76827bf3b22197300` |
| [#131](https://github.com/derod007/stock_personal/pull/131) | Research: 지수 상대강도·변동폭 수축·돌파의 단계별 선정 효과 | `ef7f82ace013b00d283617786860c947f4cfd8f7` |
| [#132](https://github.com/derod007/stock_personal/pull/132) | 연구: 급등 전날 후보의 당시 명단·선별력·시가 진입 성과 분리 | `4ac5fddd893c1677a3a720fba6cbe976e8c74715` |
| [#133](https://github.com/derod007/stock_personal/pull/133) | 연구: 매일 이동하는 과거 일봉 특징의 상승 도달 구별력 비교 | `5e8504478e87974743b8c01c5017be586774fa95` |
| [#134](https://github.com/derod007/stock_personal/pull/134) | 연구: 고정 진입의 목표·추적손절·추세이탈 청산 비교 | `2a91c0d5ce221353d8066cc67b8de6e4114ba40c` |

## 검증 결과

- Python unittest 348개 통과(PR107_SOURCE 지정, 건너뛰기 없음).
- 기존 CI 및 신규 연구 PHP 테스트 스크립트 84개 통과.
- PHP343개 파일 문법 및 전략 버전 통합 검사 통과.
- 원본 PR 변경192경로 대조: 이력/CI 통합 파일 외 최종 원본과 차이 없음.
- 실서버 브라우저 확인과 실제 연구 데이터 재실행은 하지 않았습니다.
