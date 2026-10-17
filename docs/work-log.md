## 2026-10-18 00:50 · Cursor · 진행
- 한 일: PR #101이 들어 있는 main 위에 남은 연구 PR(#87, #88–#98, #99–#100)을 병합. 운영 보호청산·지문은 101 유지
- 파일: docs/work-log.md
- 커밋: 안 함
- 롤백: 없음

## 2026-10-18 · Codex · 끝
- 저장 연구 inventory 읽기 전용 조회 통과(writes=false). 실행 버전 차단·고정 입력 해시 검사는 유지

## 2026-10-18 · Codex · 진행
- PR #101 저장 연구 inventory 조회를 실행 지문 제한과 분리. 실제 연구 CLI의 기존 버전 제한 유지

## 2026-10-18 · Codex · 끝
- PR #101 CI 후속: 연구의 과거 지문 상수/저장 결과 유지. 확인된 새 실행 지문만 별도 허용하며 새 연구 장부는 실제 지문 기록, 기존 장부 이어쓰기 차단
- 합성 account replay·dated TOP100·최근/이전 recovery·opportunity·evidence 및 신규 보호/forward 검사 통과. 사용자 계좌 재실행·예약 실행·병합 없음

## 2026-10-18 · Codex · 진행
- PR #101 CI의 과거 운영 지문 고정 검사 보완. 과거 연구 지문/장부는 유지하고 새 코드의 합성 연구 회귀를 별도 버전으로 검증

## 2026-10-18 · Codex · 끝
- 한국 모의 신규 체결에 1R 종가 확인 후 다음 거래일부터 0.5R 손절 보호를 구현. 기존 보유/신호 plan·과거 장부/진입/예약 설정 유지
- 정확한 이전 지문→새 지문의 forward 전환 기록, 읽기 전용 사전검사, 예약 상태 처리, 보호 상태·청산사유 화면 추가
- PHP 8.3.6: 신규 보호·forward 전환/사전검사, 원래56건/보호안 56건 일치, account_rollout·v6(54)·v7(79)·v9(22)·기존 본전 연구 통과. 버전 통합 및 daily Python13개 통과. 변경 PHP 문법·diff 검사 통과
- 별도 main 기반 브랜치에서 PR 제출. 사용자 운영 계좌/예약 실행 및 병합 없음

## 2026-10-18 · Codex · 진행
- 전일 보호청산 구현 후속: 56개 저장 가격 경로와 운영 계산 일치, 기존 보유 유지·다음 세션 작동·갭·중복 기록 검사 통과. 사전검사/예약/화면 회귀 및 PR 제출 진행

## 2026-10-17 · Codex · 진행
- 한국 모의 신규 체결에 1R 종가 확인 후 다음 세션부터 초기 손절폭 절반 보호청산 적용
- 기존 보유/과거 장부/진입 조건 보존, 확인된 버전의 forward 전환 및 감사 이벤트·화면·회귀 검증. PR만 제출, 병합 안 함

## 2026-10-17 · Codex · 끝
- 계좌 호출 없는 과거 TOP100 진단 CLI·이어하기·원본/코드 해시·기준 추천 대조·상승/하락 집계 도구 구현
- PHP 8.3.6 신규 8항목 및 decision_evidence/rr_audit/rr_scan 회귀 통과. Python 빈 PHP 배열·고가 상승과 종가 손실 중복 사례 회귀 통과
- 두 첨부 ZIP 내부 검증 통과. 최근 244일·24,400관찰·추천103건 기준 기록 전부 일치. 이전 243일 전체 계산은 실행 중이며 완료 결과는 PR/첨부 보고서에 기록
- PR #100 (#99 기반). 운영 조건·설정·예약·장부 변경, 계좌 재실행·병합 없음

## 2026-10-17 · Codex · 진행
- 첨부 두 기간 원본으로 계좌 호출 없는 과거 종목별 진단 도구·검증·집계 개발
- PR #99 기반. 운영 조건·설정·예약·장부 변경 및 계좌 재실행 없음

## 2026-10-17 · Codex · 끝
- 기존 RR 감사 저장에 종목별 조건 pass/fail/unknown·원본 수치·최종 상태·코드 해시 추가, 과거 감사 파일 읽기 전용 내보내기 도구와 2년 진단 절차 문서화
- 파일: bin/paper/DecisionEvidence.php, bin/paper/RrAudit.php, bin/paper_decision_evidence.php, tests/decision_evidence.php, .github/workflows/php-tests.yml, docs/decision-evidence/README.md
- 검증: git diff --check 통과. 로컬 PHP 없음으로 PHP 검증은 PR CI에서 확인 예정
- 운영 조건·설정·예약·장부 변경, 계좌 재실행 및 main 병합 없음

## 2026-10-17 · Codex · 진행
- 종목별 추천 판단 근거를 기존 RR 감사 기록에 추가하고 저장 감사 파일을 읽는 진단 내보내기 도구 개발
- 추천·주문 조건, 계좌 장부, 설정·예약 변경 및 계좌 재실행 없음

## 2026-10-15 · Codex · 끝
- 한 일: 최근 244세션 원조건 대 상승 눌림 제외 비교 도구. PR76 summary·거래·일별 장부와 기준 일치 후 대안 허용, PR97 선택 엔진 재사용, 최근 Yahoo 데이터·기존 안전 규칙 유지. 새 계좌/경로 격리.
- 검증: 통합 91개(LF/CRLF 사본 각각), dated_top100_replay, 새 PHP 문법 및 diff 검사 통과. 기준 변조·입력 변경·누락 데이터 거부, 분할/일괄·반복 무쓰기·기존 봉 누락 정지 확인. 운영 src/config·원본·기존 결과 변경 없음.
- 범위: 전체 최근 데이터셋은 사용자 PC에 있어 실제 244세션 계좌/수익률은 아직 실행하지 않음. docs/recent-no-pullback/README.md에 순서 제공. PR97 위 별도 PR, 병합 없음.

## 2026-10-15 · Codex · 진행
- 한 일: 최근 244세션 원조건 재현 후 상승 눌림 제외 계좌 비교. PR97 선택 개입 재사용, 최근 Yahoo 데이터·PR76 장부와 별도 출력, 이전 KRX 결과와 혼합하지 않음.

## 2026-10-15 · Codex · 끝
- 한 일: PR97 CRLF 테스트 사본 생성 시 CRCRLF가 생기는 오류 수정. 입력 LF 정규화 후 CRLF 변환. 실제 비교기는 완화하지 않음.
- 검증: LF 작업 폴더 64개, 모든 PHP를 CRLF로 변환한 별도 작업 폴더 64개 통과. LF/CRLF 입력과 내용 변조 거부 확인. 엔진·계좌·고정 해시 변경 없음.

## 2026-10-15 · Codex · 진행
- 한 일: PR97 테스트의 CRLF 입력 중복 변환 수정. 기존 CRLF를 LF로 정규화 후 CRLF 사본 생성, LF/CRLF 원본 각각 검증. 운영·연구 엔진·고정 해시 보존.

## 2026-10-15 · Codex · 끝
- 한 일: PR96 원조건 계좌를 기준으로 상승 눌림을 선택 단계에서 제외하는 별도 연구 도구. 새 회복·재지지 선택과 동일 단절/품질 정지, 기준 저널 고정 해시 및 날짜별 이벤트 대조.
- 검증: 통합 63개(PR96 안전 검사 31개 포함), 연속성 21개 및 dated_top100_replay 통과. 눌림에 가려진 회복 재선택·재지지 우선·공통 위험 차단, 분할/일괄·무쓰기·변조 거부 확인. 운영 src/config 변경 없음.
- 범위: 전체 1248종 원본이 로컬에 없어 실데이터 계좌 실행·수익률 계산 안 함. PR96 위 별도 PR, 병합 없음. 실행 안내 docs/krx-no-pullback/README.md.

## 2026-10-15 · Codex · 진행
- 한 일: PR96 기준 재현 후 상승 눌림을 패턴 선택에서 제외하는 연구 계좌 비교. 새 경로·계좌, 새 추천 as-of 단절 검사, 운영·원본·기존 결과 보존.

## 2026-10-15 · Codex · 끝
- 한 일: PR96 생성 헤더 nowdoc의 CRLF를 LF로 정규화. 기존 어댑터 본문·계좌 규칙·고정 원본 해시 미변경.
- 검증: 계좌 연구 31개 통과. 실제 CRLF 클래스 로딩 및 LF 혼합 통과, 코드 내용 변조 거부 회귀 3개 추가. 실데이터 계좌 실행·병합 없음.

## 2026-10-15 · Codex · 진행
- 한 일: PR96 Windows CRLF nowdoc 헤더 비교 실패 수정. 헤더 LF 정규화와 실제 CRLF 소스 로딩 회귀 검사. 운영·계좌·원본 보존.

## 2026-10-15 · Codex · 끝
- 한 일: 순수 KRX 기준/대안 연구 계좌 도구. PR95 동일 근거 보류와 체결 전 보유·대기 안전 검사. 문제 세션 전체 미처리, 양쪽 8세션 일괄 대조 후 전체 진행, 243세션 완료 뒤 비교 생성.
- 검증: 새 28개, 기존 연속성 21개 및 dated_top100_replay 통과. 실제 엔진 주문/장부·주문 대사/기존 replay 일치, 분할·일괄/무쓰기/단절·누락·0가격/이탈/부분 체결 방지/미래 무영향 확인. 부족한 실자료는 출력 전 거부.
- 범위: 로컬 전체 원본이 없어 실데이터 8·243세션 실행 안 함. 운영 src/config/지문/계좌와 기존 연구 변경 없음. PR95 위 별도 PR, 병합 없음.

## 2026-10-15 · Codex · 진행
- 한 일: PR #95 동일 보류 규칙을 적용한 순수 KRX 기준/대안 연구 계좌 비교 개발. 체결 전 보유·대기 단절/품질 검사, 정지 시 그날 전체 미처리. 운영·이전 연구 보존.

## 2026-10-15 · Codex · 끝
- 한 일: 연구 전용 가격 단절 근거 추적 도구 추가. 기준/대안 같은 보류 검사, 기존 ready·가격·우선순위 유지. 원가격 보정·계좌 실행 없음.
- 검증: 새 시험 21개, 기존 krx_signal_compare 37개 및 recovery_close_account 통과. 8종목 저장 331쌍 해시·날짜 확인, 추천 합집합 9쌍 as-of 재현. 회복형 2건은 두 팔 모두 단절 이전 목표 근거로 보류. 입력/결과 변조 거부, 재실행 무쓰기, 원본 17파일 해시 유지.
- 한계: 전체 추천 201쌍 실행은 로컬 자료 필요. 공통 위험/추세 전체 의존성·기업행사·계좌 안전성 검증 아님. PR #94 위 별도 PR, 병합하지 않음.

## 2026-10-15 · Codex · 진행
- 한 일: PR #94 보존 후 연구용 가격 단절 근거 추적·보류 검사 개발. 기준/대안 동일 규칙, 미래 사건 차단. 원가격·운영 전략·계좌 미변경.

## 2026-10-14 13:41 · Cursor · 끝
- 한 일: 2025-09-19 Yahoo 짧은 구간·5년 재조회와 기존 KRX 양 시장 원본 OHLCV 대조. 4종 모두 KRX에만 있음. 복구·계좌 실행·병합 없음
- 파일: docs/yahoo-20250919-gap-probe/comparison.json, docs/yahoo-20250919-gap-probe/report.md, docs/work-log.md
- 커밋: 안 함
- 롤백: 없음

## 2026-10-14 13:28 · Cursor · 진행
- 한 일: PR #86 정지 원인 2025-09-19 Yahoo 일봉 공백을 짧은 구간 재조회와 기존 KRX 원본으로 대조. 복구·계좌 실행 없음
- 파일: docs/work-log.md
- 커밋: 안 함
- 롤백: 없음

## 2026-10-14 · Codex · 끝
- 이전 243세션 전용 실행 도구 추가. 최근 기준 장부는 읽기 전용 PR #76 대조, 이전 기준/대안은 별도 경로에서 전체 분할·일괄 실행 후 이벤트와 최종 상태 동일성 확인
- #84·#85 의존성을 작업 브랜치에서 통합. 운영 src/config와 최근 실행 코드·고정 결과 변경 없음. main 병합 없음
- 검증: PHP 8.3.6 최근 회귀 80 + 이전 신규 30 통과(경고 0), dated TOP100 준비/재현, Python 관련 30테스트 통과
- 실제 1248종목 자료는 사용자 로컬에 있으므로 전체 실행·수익 계산 미실시. 커서 안내: docs/recovery-close-prior-account/README.md

## 2026-10-14 · Codex · 진행
- 이전 243세션 recovery_close 연구 계좌 비교 추가. #84·#85는 작업 브랜치에서 통합하고 main 병합은 하지 않음
- 최근 실행 도구·결과는 유지. 이전 기준/대안의 분할·일괄 대사와 최근 기준 장부 읽기 전용 확인 추가

## 2026-10-14 12:15 · Cursor · 끝
- 한 일: 2024-10-08~2025-10-02 KRX 일별 거래대금 TOP100 243일·합집합 1248 자료 준비. 계좌 재현·병합 없음
- 파일: docs/historical-top100-20241008-20251002/, docs/research/historical-amount-top100-pilot.py, docs/research/dated-amount-top100-range.py, bin/paper/DatedTop100Prep.php, bin/paper_dated_top100_prep.php, tests/dated_top100_prep.php, tests/test_dated_amount_top100_range.py, tests/test_historical_amount_top100_pilot.py, docs/work-log.md
- 커밋: 새 PR(병합 안 함)
- 롤백: 없음

## 2026-10-14 04:25 · Cursor · 진행
- 한 일: 2024-10-08~2025-10-02 KRX 일별 거래대금 TOP100 연구 자료 준비. 계좌 재현·병합 없음
- 파일: docs/work-log.md
- 커밋: 안 함
- 롤백: 없음

## 2026-10-14 · Codex · 끝
- 상승 눌림 `recovery_close` 하나만 제외하는 연구 계좌 비교 도구 및 실행 안내 추가. 기준 244세션 장부·일별 로그·거래가 PR #76과 일치해야 대안 실행 허용
- 추천 추가/누락, 패턴 교체, 수량 및 기존 이익 거래 변화 기록. 운영 src/config/지문/계좌/원본/기존 결과 변경 없음
- 검증: PHP 8.3.6 새 테스트 80, dated TOP100 32, opportunity 41 통과. 실제 연구 판정 연결·이어하기·멱등·장부·미래봉 차단 확인
- 한계: 1년 원본은 사용자 로컬에 있어 전체 실행 및 수익 비교 미실시. 새 PR 제출, 병합하지 않음

## 2026-10-14 · Codex · 진행
- `recovery_close` 한 조건만 제외하는 연구 전용 계좌 비교 개발. PR #76 기준 장부 재현을 대안 실행의 선행 조건으로 고정
- 운영 전략·설정·계좌·원본·기존 연구 결과는 변경하지 않음. 전체 데이터 실행은 사용자 로컬에서 진행 예정

## 2026-10-13 23:58 · Cursor · 끝
- 한 일: Python 바이트코드와 상승 눌림 A-input JSON을 gitignore. 추적 중이던 pyc는 인덱스만 제거
- 파일: .gitignore, docs/work-log.md
- 커밋: PR #83에 추가(병합 안 함)
- 롤백: 없음

## 2026-10-13 23:58 · Cursor · 진행
- 한 일: Python 바이트코드와 상승 눌림 A-input JSON을 gitignore. 추적 중이던 pyc는 인덱스만 제거
- 파일: docs/work-log.md
- 커밋: 안 함
- 롤백: 없음

## 2026-10-13 23:55 · Cursor · 끝
- 한 일: PR #76 1년 KRX 일별 TOP100 연구 계좌 체결 59건 조회 화면. 저장 trades/summary만 읽고 56건 실현손익 합계 대조. 운영·계좌·원본·병합 없음
- 파일: bin/paper/DatedTop100Trades.php, paper_top100_trades.php, tests/dated_top100_trades.php, bin/paper/Chrome.php, paper.php, paper_trades.php, assets/app.css, .github/workflows/pattern-replay.yml, docs/work-log.md
- 커밋: 새 PR(병합 안 함)
- 롤백: 없음

## 2026-10-13 23:50 · Cursor · 진행
- 한 일: PR #76 1년 KRX 일별 TOP100 연구 계좌 체결 59건 조회 화면. 저장 결과만 읽음. 운영·계좌·원본·병합 없음
- 파일: docs/work-log.md
- 커밋: 안 함
- 롤백: 없음

## 2026-10-13 · Codex · 끝
- 한 일: 첨부 원본 검토 5건의 봉/체결/손익 대조 및 차트 확인. 연구 점프 경고의 정확한 ±30% 경계 오탐만 수정, 회귀 사례 6개 추가
- 근거: 13,535 OHLCV 필드 일치, Python 별도 경로 5건 일치. 삼표시멘트 오래된 OHLC 이상 2봉은 최근 240봉·체결 구간 밖으로 기록
- 검증: git diff 검사. 로컬 PHP 런타임이 없어 PHP 검증은 PR CI로 수행. 운영·원본·기존 결과 미변경, 계좌 재현·병합 안 함

## 2026-10-13 · Codex · 진행
- 한 일: 첨부 recovery-close-evidence-v1.json 5건 검토. 삼표시멘트 정확한 +30%가 초과로 표시되는 연구 도구 부동소수점 경계 오류 수정

## 2026-10-13 · Codex · 끝
- 한 일: 사용자 승인으로 열린 연구 PR #77~#81 통합 검토. #77 main 병합, #79·#81은 상위 PR에 병합. #78·#80 main 반영을 위해 작업 기록·CI 충돌을 양쪽 보존으로 해결
- 검증: 각 원본 PR CI 15개 성공. 통합 후 연구·검토 자료 관련 테스트와 운영 지문 재확인. 조건 완화·계좌 재실행 없음. 최종 병합 SHA는 GitHub PR 기록 참조

## 2026-10-13 · Codex · 진행
- 한 일: 미병합 연구 PR #77~#81 검토·의존 순서 병합. 운영 코드와 고정 연구 입력 보존

## 2026-10-13 · Codex · 완료
- 한 일: PR #78 가격 ZIP의 42건·28,068 사례별 봉 검증, A 84장/B 42장 좌표·눌림 산식 독립 계산, 전체 판정일 차트 메모와 성과 비교. MA20 아래 신호 22건·깊은 이격 관찰 28건을 확인하되 새 필터 채택 안 함
- 검증: 가격/MA/미래 시각/SVG/고정 지표 변조 실패 포함 6개 시험 통과. 운영·원본·기존 검토 자료 보존. 공통 위험 전체 독립 구현·청산 재감사는 아님. 병합하지 않음

## 2026-10-13 · Codex · 진행
- 한 일: 첨부 가격 ZIP으로 PR #78의 42건 가격·차트·눌림 코드 독립 계산 및 판정일 구조 검토. 기존 성과 노출 사실을 명시하고 운영·원본 보존

## 2026-10-13 11:34 · Cursor · 끝
- 한 일: PR #76 저장 장부의 상승 눌림 종료 42건 전체 A/B/C 검토 화면·차트 검증. PR #77은 미병합 참고만. 계좌 재실행·병합 없음
- 파일: bin/paper/PullbackYearReview.php, bin/paper_pullback_year_review.php, tests/pullback_year_review.php, docs/pullback-year-review/, .github/workflows/pattern-replay.yml, docs/work-log.md
- 커밋: 안 함
- 롤백: 없음

## 2026-10-13 11:24 · Cursor · 진행
- 한 일: PR #76 1년 연구 trend_pullback_v1 종료 42건 검토 자료 준비. PR #77은 참고만. 운영·장부·병합 없음
- 파일: docs/work-log.md
- 커밋: 안 함
- 롤백: 없음

## 2026-10-13 · Codex · 완료
- 한 일: PR #76 저장 장부의 거래 빈도·패턴 손익·집중도·최대낙폭 분석과 재현 코드 추가. 추천 103 → 주문 68 → 체결 59, 추천 없는 날 170. 상승 눌림 종료 42건의 손실 31건과 낙폭 기여를 구분
- 검증: 244일 현금·평가·실현 및 낙폭 기여 대조, 주문 68건 저장 추천 대조, 변조 사본 실패·입력 불변 시험 통과. 운영·계좌·원본 변경 및 재실행 없음

## 2026-10-13 · Codex · 진행
- 한 일: PR #76 저장 장부의 거래 빈도·패턴 손익·수익 집중도·최대낙폭 분석. 운영 변경·계좌 재실행 없음

## 2026-10-13 · Codex · 끝
- 한 일: recovery_close 상위 이익 5건(4종목) 고정, 원본 봉·Yahoo 원응답 부분 추출 및 별도 체결 경로 검증 도구와 실행 안내 추가
- 검증: 신규 32개·기존 연구 41개 통과. 첨부 보고서 SHA256·원래 실행 코드 해시·고정 5건 목록 일치. 운영 지문 유지
- 한계: 실제 v2 원본은 사용자 PC에 있어 추출·원본 대조·차트 검토는 실행 대기. 계좌 비교·조건 변경·병합하지 않음. PR #80에 의존하는 후속 PR

## 2026-10-13 · Codex · 진행
- 한 일: PR #80 결과의 recovery_close 상위 이익 5건 원본 근거 추출·검증 도구 개발. 운영 조건 변경과 계좌 재현 없이 별도 PR 준비

## 2026-10-13 · Codex · 끝
- 한 일: 보유 한도 차단 28건 목록 및 단일 조건 누락 진단 도구·이어하기·로컬 실행 안내 추가. 상승 눌림 10개 원자 조건, 재지지/회복형은 구조 완성 후 RR만 진단
- 검증: 신규 41개, 기존 주문 감사 32개·TOP100 재현 32개 통과. 지문 유지. 운영·계좌·원본 변경 없음
- 한계: 전체 v2 데이터와 244세션 성과는 사용자 PC 실행 대기. 독립 단위 거래이며 계좌 한도 확대 효과가 아님. PR만 제출, 병합하지 않음

## 2026-10-13 · Codex · 진행
- 한 일: 보유 한도 28건과 단일 조건 미충족 진단 개발. 운영·계좌 보존, 사용자 로컬 전체 데이터 실행용 연구 PR 준비

## 2026-10-13 · Codex · 끝
- 한 일: PR #76 주문 감사 보완. 저장 로그 7개 필드 대조 및 완료봉 엔진·품질 재계산, 근거 누락 시 실패. 변조 검사 32개와 기존 재현 검사 32개 통과. 기존 장부·provenance·운영 소스 보존
- 한계: v2 원본·저널은 사용자 PC에 있어 68건 엔진 재검증 대기. 읽기 전용 verify 명령을 order-audit-review.md에 기록
- 파일: DatedOrderAudit.php, DatedTop100Replay.php, paper_dated_top100_replay.php, dated_order_audit.php, CI, 보고서
- 병합: 하지 않음

## 2026-10-13 · Codex · 진행
- 한 일: PR #76 주문 감사의 패턴명 존재 검사 교체. 당일까지의 완료봉으로 최종 추천·품질 재계산, 저장 로그 대조, 변조 테스트 추가. 기존 장부 보존
- 병합: 하지 않음

## 2026-10-13 03:58 · Cursor · 끝
- 한 일: 1년 v2 TOP100 연구 계좌 244세션 재현·검증. 10월 도구를 프로필로 확장하고 기존 10월 장부 회귀 확인. 운영 계좌·병합 없음
- 파일: bin/paper/DatedTop100Replay.php, bin/paper_dated_top100_replay.php, tests/dated_top100_replay.php, config/paper-history-kr-dated-top100-20251008-20261008.json, docs/historical-top100-replay-20251008-20261008/, docs/work-log.md
- 커밋: 안 함
- 롤백: 없음

## 2026-10-13 03:41 · Cursor · 진행
- 한 일: main(ab88384)에서 1년 v2 TOP100 연구 계좌 재현. 기존 10월 도구를 기간·데이터셋 지정 가능하게 확장. 운영 계좌·병합 없음
- 파일: docs/work-log.md
- 커밋: 안 함
- 롤백: 없음

## 2026-10-13 03:27 · Cursor · 끝
- 한 일: PR #75 운영 NaverDailyQuotes를 main과 같게 복원, 영문 종목코드는 연구 krCode로 분리, 933 전체 섹터 맵 고정. 재수집·계좌 재현·병합 없음
- 파일: src/NaverDailyQuotes.php, bin/paper/HistoryResearch.php, bin/paper/DatedTop100Prep.php, bin/paper_dated_top100_prep.php, bin/paper/SectorFreeze.php, bin/paper/DatedTop100Replay.php, bin/paper_sector_collect.php, tests/dated_top100_prep.php, tests/dated_top100_replay.php, tests/sector_freeze.php, docs/historical-top100-20251008-20261008/, docs/work-log.md
- 커밋: PR #75에 추가(병합 안 함)
- 롤백: 없음

## 2026-10-13 03:21 · Cursor · 진행
- 한 일: PR #75 운영 NaverDailyQuotes 복원·연구 전용 코드 분리, 933 전체 섹터 맵. 재수집·계좌 재현·병합 없음
- 파일: docs/work-log.md
- 커밋: 안 함
- 롤백: 없음

## 2026-10-13 03:03 · Cursor · 끝
- 한 일: PR #75 계좌 실행 전 데이터 준비. Yahoo 31종 v2 편입(5y 31회), 895 해시 복사, 24400 고유 분할, 중간·최종 이탈 잠재 누락 집계, 편입 섹터 맵. 계좌 재현·병합 없음
- 파일: bin/paper/DatedTop100Prep.php, bin/paper_dated_top100_prep.php, bin/paper/HistoryResearch.php, src/NaverDailyQuotes.php, tests/dated_top100_prep.php, docs/historical-top100-20251008-20261008/, docs/work-log.md
- 커밋: PR #75에 추가(병합 안 함)
- 롤백: 없음

## 2026-10-13 02:50 · Cursor · 진행
- 한 일: PR #75 계좌 실행 전 데이터 준비 마무리. Yahoo 31종 v2 편입, 24400 분할 집계, 중간 이탈 봉 검사. 계좌 재현·병합 없음
- 파일: docs/work-log.md
- 커밋: 안 함
- 롤백: 없음

## 2026-10-13 02:40 · Cursor · 끝
- 한 일: PR #75 해시 검증을 거래일 목록 기준으로 분리, 미매핑/404 프로브, 날짜별 품질 집계, Yahoo 요청 수 정정. 원본·고정 해시 보존. 계좌 재현·병합 없음
- 파일: docs/research/dated-amount-top100-range.py, docs/research/dated-amount-top100-provider-review.py, bin/paper/DatedTop100Prep.php, bin/paper_dated_top100_prep.php, bin/paper/HistoryResearch.php, tests/test_dated_amount_top100_range.py, tests/test_dated_amount_top100_provider_review.py, tests/dated_top100_prep.php, docs/historical-top100-20251008-20261008/, .github/workflows/pattern-replay.yml, docs/work-log.md
- 커밋: PR #75에 추가(병합 안 함)
- 롤백: 없음

## 2026-10-13 02:15 · Cursor · 진행
- 한 일: PR #75 해시 검증·미매핑/404 확인·날짜별 누락 집계·Yahoo 요청 수 정정. 기존 원본·고정 해시 보존. 계좌 재현·병합 없음
- 파일: docs/work-log.md
- 커밋: 안 함
- 롤백: 없음

## 2026-10-13 01:04 · Cursor · 끝
- 한 일: 2025-10-08~2026-10-08 날짜별 KRX 거래대금 TOP100 244일 수집·검증, 합집합 933 일봉 준비. 계좌 재현·병합 없음
- 파일: docs/research/historical-amount-top100-pilot.py, docs/research/dated-amount-top100-range.py, bin/paper/DatedTop100Prep.php, bin/paper_dated_top100_prep.php, tests/dated_top100_prep.php, tests/test_dated_amount_top100_range.py, tests/test_historical_amount_top100_pilot.py, docs/historical-top100-20251008-20261008/, .github/workflows/pattern-replay.yml, docs/work-log.md
- 커밋: 새 PR(병합 안 함)
- 롤백: 없음

## 2026-10-13 00:24 · Cursor · 진행
- 한 일: 최신 main에서 2025-10-08~2026-10-08 날짜별 KRX 거래대금 TOP100·일봉 준비. 계좌 재현·병합 없음
- 파일: docs/work-log.md
- 커밋: 안 함
- 롤백: 없음

## 2026-10-13 00:01 · Cursor · 끝
- 한 일: PR #74 재현 근거·검증 빈틈 보완. 후보 LF/CRLF 해시 연결, PR #73 선정·원본 해시 필수 비교, 순위 이탈 대기주문 체결·만료 테스트, 보고서 문구 정정
- 파일: bin/paper/DatedTop100Replay.php, bin/paper_dated_top100_replay.php, tests/dated_top100_replay.php, tests/test_dated_amount_top100_replay.py, docs/research/dated-amount-top100-replay.py, docs/historical-top100-replay-202510/report.md, docs/historical-top100-replay-202510/evidence.json, docs/historical-top100-replay-202510/input-validation.json, docs/historical-top100-replay-202510/account/input-hash-link.json, .github/workflows/pattern-replay.yml, docs/work-log.md
- 커밋: PR #74에 추가(병합 안 함)
- 롤백: 없음

## 2026-10-12 23:57 · Cursor · 진행
- 한 일: PR #74 재현 근거·검증 빈틈 보완 (후보 LF/CRLF 해시 연결, PR #73 원본·선정 해시 필수 비교, 순위 이탈 대기주문 체결·만료 테스트, 보고서 문구)
- 파일: docs/work-log.md
- 커밋: 안 함
- 롤백: 없음

## 2026-10-12 22:29 · Cursor · 끝
- 한 일: PR #73 날짜별 KRX 거래대금 TOP100을 기존 재현 도구에 연결. 별도 연구 계좌 18세션 실행. 키·원본·운영 캐시·전략·full-v2 안 건드림. 병합 없음
- 파일: bin/paper/DatedTop100Replay.php, bin/paper_dated_top100_replay.php, tests/dated_top100_replay.php, config/paper-history-kr-dated-top100-202510.json, docs/research/dated-amount-top100-replay.py, docs/historical-top100-replay-202510/, docs/work-log.md
- 커밋: 새 PR(병합 안 함)
- 롤백: 없음

## 2026-10-12 22:12 · Cursor · 진행
- 한 일: PR #73 2025-10 날짜별 KRX 거래대금 TOP100을 기존 재현 도구에 연결하고 별도 연구 계좌로 18세션만 실행. 운영·전략·full-v2·원본 보호. 병합 없음
- 파일: 작업 중
- 커밋: 안 함
- 롤백: 없음

## 2026-10-12 22:03 · Cursor · 끝
- 한 일: API 활용 승인 후 2025-10 18거래일 KOSPI·KOSDAQ 거래대금 TOP100 수집·검증. 합집합 305종목. 키·원본은 Git에 안 넣음. 운영 변경·병합 없음
- 파일: docs/research/historical-amount-top100-pilot.py, docs/historical-top100-pilot/report.md, docs/historical-top100-pilot/evidence.json, docs/historical-top100-pilot/spot-top10.json, docs/work-log.md
- 커밋: PR #72에 추가(병합 안 함)
- 롤백: 없음

## 2026-10-12 21:57 · Cursor · 진행
- 한 일: API 활용 신청 후 인증키로 2025-10 거래대금 수집 재시도. 키는 Git에 안 넣음. 운영 변경·병합 없음
- 파일: 작업 중
- 커밋: 안 함
- 롤백: 없음

## 2026-10-12 21:54 · Cursor · 끝
- 한 일: 인증키를 gitignore 경로에만 저장하고 2025-10 수집 시도. 본호출 401 Unauthorized API Call이라 첫날에서 중단. TOP100 없음. 키는 Git에 안 넣음. 병합 없음
- 파일: docs/research/historical-amount-top100-pilot.py, docs/historical-top100-pilot/report.md, docs/historical-top100-pilot/evidence.json, docs/work-log.md
- 커밋: PR #72에 추가(병합 안 함)
- 롤백: 없음

## 2026-10-12 21:51 · Cursor · 진행
- 한 일: Git 밖 인증키로 2025-10 거래대금 수집 실행. 키는 gitignore 경로에만 저장. 운영 캐시·병합 없음
- 파일: 작업 중
- 커밋: 안 함
- 롤백: 없음

## 2026-10-12 21:47 · Cursor · 끝
- 한 일: PR #72 수집 가드. 양쪽 시장 검증 통과일만 TOP100. HTTP·JSON·권한·빈 응답 구분, 빈 응답은 휴장 아님. 재개 시 원본 해시·구조·기준일 확인. 키 없으면 skipped_no_auth_key. 전체 수집·병합·운영 변경 없음
- 파일: docs/research/historical-amount-top100-pilot.py, tests/test_historical_amount_top100_pilot.py, docs/historical-top100-pilot/report.md, docs/historical-top100-pilot/evidence.json, docs/work-log.md
- 커밋: PR #72에 추가(병합 안 함)
- 롤백: 없음

## 2026-10-12 21:43 · Cursor · 진행
- 한 일: PR #72 수집 검증 보완. 양쪽 시장 정상일만 TOP100 저장. 오류 종류 구분, 빈 응답을 휴장으로 보지 않음. 재개 시 원본 검증. 운영 변경·병합·전체 수집 없음
- 파일: 작업 중
- 커밋: 안 함
- 롤백: 없음

## 2026-10-12 21:28 · Cursor · 끝
- 한 일: 2025-10 거래대금 TOP100 복원 파일럿. 운영 스캐너 규칙 정리. KRX 전종목 JSON은 로그인 필요, Open API 본호출은 인증키 필요. 샘플 10행만 스키마 확인, 근사 TOP100 안 만듦. 계좌 재현·운영 변경·병합 안 함
- 파일: docs/research/historical-amount-top100-pilot.py, tests/test_historical_amount_top100_pilot.py, docs/historical-top100-pilot/report.md, docs/historical-top100-pilot/evidence.json, docs/work-log.md
- 커밋: 새 PR(병합 안 함)
- 롤백: 없음

## 2026-10-12 21:20 · Cursor · 진행
- 한 일: 과거 일별 거래대금 TOP100 복원 1개월 파일럿. 2025년 10월 거래일. 운영 스캐너 규칙 확인 후 원천 조사·수집 정확도·부하만 검증. 계좌 재현·수익률·운영 변경 없음
- 파일: 작업 중
- 커밋: 안 함
- 롤백: 없음

## 2026-10-12 21:05 · Cursor · 끝
- 한 일: 2026-10-12 예약 실행 읽기 전용 점검. 운영 스캐너는 data/raw/cache/sector만 사용. 당시 스캔 100종목 중 유효 업종 99, 자리표시자 기타 1(200000.KS). 엔벨로프 quality_blocked 12관찰=11종목은 2026-03-27 close>high. 10월 8일 11건 유지, 추가 1관찰은 한화솔루션 당일 세션. 운영 수정·재수집·계좌 재실행·병합 안 함
- 파일: docs/research/daily-run-20261012-audit.py, docs/daily-run-20261012/report.md, docs/daily-run-20261012/evidence.json, docs/work-log.md
- 커밋: 새 PR(병합 안 함)
- 롤백: 없음

## 2026-10-12 20:56 · Cursor · 진행
- 한 일: 2026-10-12 예약 실행 읽기 전용 점검. 업종 캐시와 엔벨로프 품질 보류 12건 원인만 확인. 운영 수정·재수집·계좌 재실행 없음
- 파일: 작업 중
- 커밋: 안 함
- 롤백: 없음

## 2026-10-11 23:04 · Cursor · 끝
- 한 일: PR #70 운영 전략 지문을 main과 같게 복원. 비용 변경은 ResearchCashFee로 분리. 새 상태 경로에서 6계좌 재실행. 기준 10bp는 full-v2와 일치, 이전 실험과 경제 숫자 동일. 병합 안 함
- 파일: src/PaperPortfolio.php, bin/paper/ResearchCashFee.php, bin/paper/AccountReplay.php, tests/account_replay.php, docs/research/account-replay-fee-sensitivity.py, docs/historical-account-replay/fee-sensitivity-v2/, docs/work-log.md
- 커밋: 브랜치 cursor/fee-sensitivity, PR #70에 추가(병합 안 함)
- 롤백: 없음

## 2026-10-11 22:40 · Cursor · 진행
- 한 일: PR #70 운영 전략 지문 복원. PaperPortfolio는 main과 같게 두고 비용 변경은 연구 코드로 분리. 새 상태 경로에서 6계좌 재실행
- 파일: 작업 중
- 커밋: 안 함
- 롤백: 없음

## 2026-10-11 22:26 · Cursor · 끝
- 한 일: 현금 거래비용만 각 10·20·30bp로 두 기간 연구 계좌를 처음부터 재실행. 기준 10bp는 full-v2와 주문·현금·평가 일치. 20·30bp는 총자산이 줄고 이전 30bp만 주문 구성이 바뀜. 슬리피지·운영·full-v2 변경 없음. 병합 안 함
- 파일: src/PaperPortfolio.php, bin/paper/AccountReplay.php, bin/paper_account_replay.php, tests/account_replay.php, docs/research/account-replay-fee-sensitivity.py, docs/historical-account-replay/fee-sensitivity/, docs/work-log.md
- 커밋: 브랜치 cursor/fee-sensitivity, 새 PR(병합 안 함)
- 롤백: 없음

## 2026-10-11 21:36 · Cursor · 끝
- 한 일: PR #69 보완. 청산가·진입가를 일봉 범위와 따로 대조, 독립 경로의 청산일·가격·사유·취소일·취소 사유를 저장 결과와 대조, 가격·날짜·사유·가격+손익 변조 사본이 실패하는지 확인. 123건 재검증(오류 0, 가정 의존 9=고가 밖 진입 3+저가 밖 청산 6). 운영 엔진·full-v2 변경 없음. 비용 실험·병합 안 함
- 파일: docs/research/account-replay-fill-audit.py, tests/test_fill_audit.py, docs/historical-account-replay/fill-audit/, docs/work-log.md
- 커밋: 브랜치 cursor/fill-assumption-audit, PR #69에 추가(병합 안 함)
- 롤백: 없음

## 2026-10-11 21:35 · Cursor · 끝
- 한 일: 과거 연구 계좌 123건 주문을 원본 일봉으로 독립 검증(오류 0, 가정 의존 3=시가 슬리피지가 고가 초과, 같은 봉 충돌 0). 상세 사례 47건과 차트, 비용 실험 설계만 작성. 엔진 수정·재실행·기존 결과 변경 없음
- 파일: docs/research/account-replay-fill-audit.py, docs/historical-account-replay/fill-audit/, docs/work-log.md
- 커밋: 브랜치 cursor/fill-assumption-audit, 새 PR(병합 안 함)
- 롤백: 없음

## 2026-10-11 · Codex · 끝
- PR #68 원본 종료 105건을 Decimal로 독립 대조: 상위 거래·종목·패턴·청산 월 집계 및 실현손익 일치. 기존 해시의 CRLF 차이 확인 후 Git LF 기준 산출물 재생성, 검토 기록 추가. 한글 PR 제목·설명 및 병합 메시지 정리. 사용자 승인에 따라 검증한 커밋 병합 진행. 운영·기존 full-v2 불변.

## 2026-10-11 · Codex · 진행
- PR #68 원본 거래 독립 대조·재현 검토 후 한글 제목/설명 정리 및 병합 검토.

## 2026-10-11 21:08 · Cursor · 끝
- 한 일: 과거 연구 계좌 두 기간 수익 집중도 집계. 종료 거래 net_pnl만 사용(이전 49·최근 56, 실현손익 원본과 일치). 상위 1·3·5건 비중, 종목·패턴·청산 월 집계, 보고서. 계좌 재실행·운영 파일·기존 full-v2 결과 변경 없음
- 파일: docs/research/account-replay-concentration.py, docs/historical-account-replay/concentration/(concentration.json, report.md), docs/work-log.md
- 커밋: 브랜치 cursor/profit-concentration, 새 PR(병합 안 함)
- 롤백: 없음

## 2026-10-11 20:58 · Cursor · 끝
- 한 일: PR #67 병합 충돌 해결. main(PR #65·#66 반영)을 PR #67 브랜치에 병합하고 docs/work-log.md는 양쪽 항목을 모두 남김
- 파일: docs/work-log.md
- 커밋: 브랜치 cursor/fix-sector-collection, PR #67에 반영(병합 안 함)
- 롤백: 없음

## 2026-10-11 · Codex · 완료
- PR #67 업종 부분 실패 캐시 방지, 이름 있는 자리표시자 재조회, 유효 캐시 보존. ETF 이름 기준과 실제 업종 other 분류 유지
- 검증: 수집 테스트 17개·rr_scan·amount_exclude 통과, 임시 폴더 실제 3종목 조회 성공. 운영 캐시 변경·병합 없음

## 2026-10-11 · Codex · 진행
- PR #67 업종 부분 실패·자리표시자 재조회·유효 캐시 보존 보완 (직접 병합 없음)

## 2026-10-11 · Codex · 끝
- 업로드한 이전 원본 verify intact. 맵 v2·동일 전략으로 242세션 완료: 총자산 +4.9229%, 종가 최대 낙폭 9.0501%, 종료 49건·보유 2·대기 1. 장부 10항목·56주문 재시뮬레이션·PR #65 75신호 대조 통과, 재실행 0세션·저널 동일.
- 최근 244세션과 별도 비교표 생성. 두 기간 전략·실행 코드·섹터 맵 해시 동일, 실현손익·낙폭 재계산 통과. 데이터셋·운영 상태 불변. PR #66 추가, 병합 없음.

## 2026-10-11 · Codex · 진행
- 업로드된 이전 기간 원본 무결성 확인 및 맵 v2 전체 계좌 재현·최근 기간 비교. PR #66 추가, 병합 없음.

## 2026-10-11 · Codex · 끝
- PR #66: 고정 섹터 맵 v2로 최근 244세션 전체 연구 계좌 실행·내보내기 완료. 종료 56건, 보유 3건, 총자산 +18.1689%, 종가 최대 낙폭 5.3961%. 장부 10항목·67주문 재시뮬레이션·PR #65 최근 신호 82건 대조 통과. 재실행 0세션·저널 동일. 관련 테스트 3개 통과.
- 이전 기간은 원본 kr-saved-scan-20251008 미제공으로 실행하지 않음. 필요한 ZIP과 한계를 full-v2/report.md에 기록. 전략·설정·운영 상태·원본·기존 연구 결과 불변. 병합 없음.

## 2026-10-11 · Codex · 진행
- PR #66 맵 v2 고정 전체 재현 실행 및 원본 가용성 확인 (직접 병합 없음)

## 2026-10-11 02:10 · Cursor · 끝
- 한 일: PR #66 섹터 업종명이 전부 기타인 원인 확인(대표 5종목 임시 폴더 실행: 구 사이트가 새 사이트로 302, 업종 링크 없음). 같은 사이트 JSON에서 현재 업종 144종목 확보(미확인 0)해 연구용 섹터 맵 v2 생성. 새 계좌 va·vb·vc 소규모 재검증과 재개·재실행 확인. 운영 캐시·계좌·전략·기존 결과 그대로. 전체 실행·병합 안 함
- 파일: bin/paper_sector_collect.php, bin/paper_sector_diagnose.php, bin/paper/SectorFreeze.php, bin/paper_account_replay.php, tests/sector_freeze.php, docs/historical-account-replay/(sector-map-v2.json, sector-collection-v2.json, sector-collection-diagnosis.json, smoke-sector-v2/, followup.md, protocol.md), docs/research/account-replay-followup.py, docs/work-log.md
- 커밋: 브랜치 cursor/historical-paper-replay, PR #66에 반영(병합 안 함)
- 롤백: 없음

## 2026-10-11 01:36 · Cursor · 끝
- 한 일: PR #66 섹터 경로 대조. 운영 스캐너 경로 data/raw/cache/sector와 data/cache/sector를 읽기만 확인. 대상 144종목 유효 업종명은 두 경로 모두 0건(자리표시자 144). 보완·충돌 없음. 맵과 소규모 계좌는 새로 만들지 않음
- 파일: bin/paper/SectorFreeze.php, bin/paper_account_replay.php, tests/sector_freeze.php, docs/historical-account-replay/(sector-path-check.json, followup.md, protocol.md), docs/research/account-replay-followup.py, docs/work-log.md
- 커밋: 브랜치 cursor/historical-paper-replay, PR #66에 반영(병합 안 함)
- 롤백: 없음

## 2026-10-11 01:25 · Cursor · 끝
- 한 일: PR #66 보완. 운영 섹터 캐시·저장 스캔을 읽기만 해 연구용 섹터 맵을 고정(확보 0, 미확인 144, 한 버킷), 완료봉에서 plan.ready·스캐너 후보·PaperScanUniverse 대응을 운영 함수로 검증, 고정 맵으로 소규모 3구간을 별도 계좌에서 재실행(장부·섹터 한도·재실행·이어 하기). 전부 미분류 결과는 유지. 전체 실행·병합 없음
- 파일: bin/paper/SectorFreeze.php, bin/paper/AccountReplay.php, bin/paper_account_replay.php, src/KrAmountScanner.php, tests/sector_freeze.php, tests/selection_correspondence.php, tests/account_replay.php, docs/historical-account-replay/(sector-map.json, followup.md, protocol.md, selection-check.json, smoke-sector/), docs/research/account-replay-followup.py, .github/workflows/pattern-replay.yml, docs/work-log.md
- 커밋: 브랜치 cursor/historical-paper-replay, PR #66에 반영(병합 안 함)
- 롤백: 없음

## 2026-10-11 00:40 · Cursor · 끝
- 한 일: 고정 종목군 과거 모의계좌 재현 준비. 운영 계좌 코드(PaperPortfolio·TradeSimulator·ChartPlanEngine·PaperQuality)를 수정 없이 replay 모드로 호출하는 연구용 CLI, 연구 계좌 설정(운영 설정에서 계좌 ID만 다름), 실행 순서·규칙 대응표, 실데이터 3구간 소규모 검증(장부 대조·독립 재시뮬레이션·이어 하기 동일·PR #65 신호 대조·파일 불변), 단위시험 추가. 전체 두 기간 실행·수익성 결론·전략 조정·운영 반영 없음
- 파일: bin/paper/AccountReplay.php, bin/paper_account_replay.php, config/paper-history-kr-recovery-v1.json, tests/account_replay.php, docs/historical-account-replay/(protocol.md, report.md, quality-scan-prior.json, quality-scan-recent.json, smoke/{a,b,c}/), docs/research/account-replay-report.py, .github/workflows/pattern-replay.yml, docs/work-log.md
- 커밋: 브랜치 cursor/historical-paper-replay, PR 제출(병합 안 함)
- 롤백: 없음

## 2026-10-10 · Codex · 완료
- PR #65 보고서·생성기에서 중앙값 차이(이전 +0.17%p, 최근 +0.71%p)와 거래별 변화량 중앙값(각 0.00%p)을 분리. 반올림 전 계산 명시
- 사례별 재계산과 보고서 재생성 일치 확인. 거래 결과·고정 연구 코드·운영 변경 없음. 사용자 요청으로 수정 PR 병합 진행

## 2026-10-10 · Codex · 진행
- PR #65 중앙값 차이와 거래별 변화량 중앙값을 구분해 보고서·생성기 수정, 재생성 검증 후 사용자 요청으로 병합

## 2026-10-11 00:20 · Cursor · 끝
- 한 일: 상승 눌림 최종 선택 157건(이전 75·최근 82)에서 최근 10봉 저점 손절과 PR #64 H_recent 이후 저점 손절 비교. 프로토콜·코드·사례별 대안 손절을 해시 고정한 뒤 실행, 기준 157건 재현 후 대안 적용, 파이썬 독립 재계산·테스트 통과. 진입·목표·보유·비용 그대로, 체결 뒤 손절만 변경, 운영 반영 없음
- 파일: docs/pullback-recent-redecline-stop/(protocol.md, population.json, alt-stops.json, baseline-stored.json, pr64-consistency.json, leak-check.json, freeze.json, comparison.json, report.md, verify.md, verify-result.json), bin/paper/RedeclineStop.php, bin/paper/RedeclineStudy.php, bin/paper_recent_redecline_stop.php, tests/redecline_stop.php, docs/research/redecline-stop-verify.py, docs/research/redecline-stop-report.py, .github/workflows/pattern-replay.yml, docs/work-log.md
- 커밋: 브랜치 cursor/pullback-recent-redecline-stop, PR 제출(병합 안 함)
- 롤백: 없음

## 2026-10-10 · Codex · 완료
- PR #64 수정: 기존 A/A2 고정값을 보존하며 LF·CRLF 해시 검증, 새 A2 출력은 LF 해시 사용. 9건 고점 관계 설명과 보류 3건 표현 정정
- 검증: 전체 20항목 및 A2 줄바꿈·내용 변경 회귀 검사 통과. 사용자 요청으로 검증된 PR #64 병합 진행. 운영 전략·계좌·원본 변경 없음

## 2026-10-10 · Codex · 진행
- PR #64 마무리: A2 줄바꿈 해시 회귀 수정, 13건 해석·보류 3건 명시, Git 저장본 검증 후 사용자 요청으로 병합

## 2026-10-10 23:30 · Cursor · 끝
- 한 일: PR #63 후속. 고정 해시를 줄바꿈 차이와 내용 변경으로 나눠 검증(기존 고정 파일·해시 그대로, LF 해시 파일 추가), 큰 조정 시작 고점·최근 재하락 시작 고점을 추가 검토 버전으로 표시하고(B 확인 뒤라 블라인드 아님) 손절 기준이 달랐던 13건의 조정 저점을 다시 비교, 고가 돌파·종가 돌파·목표≤진입을 따로 계산하고 잘못된 설명을 고침. 기존 24건만, 전략·원본 변경 없음
- 파일: docs/pullback-segment-pilot/(a-freeze-lf.json, annotations-a2.src.json, annotations-a2.json, highs-review.json, charts-a2/, code-comparison.json, index.html, review.md, verify.md, verify-result.json), bin/paper/PullbackSegmentPilot.php, bin/paper_pullback_segment_pilot.php, tests/pullback_segment_pilot.php, docs/work-log.md
- 커밋: 브랜치 cursor/pullback-segment-followup, PR 제출(병합 안 함)
- 롤백: 없음

## 2026-10-10 20:15 · Cursor · 끝
- 한 일: 상승 눌림 24건(두 기간 각 선택 6·대기 3·탈락 3)을 A 자료만으로 먼저 표시해 고정하고, 그 뒤 코드의 참조 창과 대조한 검토 자료를 만들었다. 전략·점수·원본·계좌 변경 없음
- 파일: docs/pullback-segment-pilot/(protocol.md, sample-manifest.json, annotations-a.json, annotations-a.src.json, a-freeze.json, code-comparison.json, index.html, review.md, verify.md, verify-result.json, charts/), bin/paper/PullbackSegmentPilot.php, bin/paper_pullback_segment_pilot.php, bin/paper/ReviewCharts.php(표식 옵션 추가, 기본 출력 그대로), tests/pullback_segment_pilot.php, .github/workflows/pattern-replay.yml, docs/work-log.md
- 커밋: 브랜치 cursor/pullback-segment-pilot, PR 제출(병합 안 함)
- 롤백: 없음

## 2026-10-10 · Codex · 완료
- 상승 눌림 157건 A 입력 구조 라벨 고정·차트 전수 대조 후 종료 137건 비교. H 58/D 82/U 17, H와 D의 성과 우위는 기간별 반전. 운영 변경 없음
- 산출물: docs/pullback-structure-review-20261010.md 및 기준·사례·JSON·재현 스크립트. 구조 검사 8개와 기존 기간별 합계 검증 통과. PR로만 제출, 병합 안 함

## 2026-10-10 · Codex · 진행
- 작업: 원문별 조건 범위를 고정하고, 판정일까지의 가격으로 상승 눌림 157건 구조 분류 후 두 기간 성과 비교. 운영 변경 없음

## 2026-10-10 16:46 · Cursor · 끝
- 한 일: 검토 패키지의 거래량 높이, 대기·무효 표본, 신호 없음 판정, 판정일 수치, 날짜 눈금, 숏 손절 문구를 고치고 패키지를 다시 만들었다. 전략 변경 없음
- 파일: bin/paper/ReviewCharts.php, bin/paper/ReviewPack.php, tests/review_pack.php, docs/pattern-source-map.md, docs/pattern-review-pack.md, docs/pattern-review-verify.md, docs/pattern-review-manifest.json, docs/pattern-review-examples/, docs/pattern-review-pack.zip, docs/work-log.md
- 커밋: 브랜치 cursor/pattern-review-pack, PR 갱신(병합 안 함)
- 롤백: 없음

## 2026-10-10 15:46 · Cursor · 끝
- 한 일: 세 패턴의 원문·구현 대응표, 두 기간 최종 선택 전수와 미선택·신호 없음·품질 차단 표본, A/B/C 차트 패키지, 본전 이동 연구 마무리를 작성. 전략·손절·목표·계좌 변경 없음
- 파일: bin/paper/ReviewCharts.php, bin/paper/ReviewPack.php, bin/paper_review_pack.php, tests/review_pack.php, docs/pattern-source-map.md, docs/pattern-review-pack.md, docs/pattern-review-verify.md, docs/pattern-review-manifest.json, docs/pattern-review-examples/, docs/pattern-review-pack.zip, docs/breakeven-study-close.md, .github/workflows/pattern-replay.yml, docs/work-log.md
- 커밋: 브랜치 cursor/pattern-review-pack, PR 대기(병합 안 함)
- 롤백: 없음

## 2026-10-10 · Codex · 끝
- 작업: 완료봉 종가가 실제 체결가 +3% 이상이면 다음 세션부터 체결가 손절을 적용하는 연구 CLI 추가. 진입·목표·3봉 주문·20봉 보유·비용은 기존 기준, 재진입 없음
- 검증: 최근 전체 선택 82건의 기존 신호·전체 모의 결과 일치. 요약/전체 replay 입력 결과 일치. 신규 13개 검사·기존 pattern_replay 17개·PHP 구문·diff 통과
- 결과: 공통 종료 70건 평균 +0.89%→+2.01%, 14건 개선·6건 악화·50건 동일. 미완료 1건 별도 종료(-0.2497%). 후반 평균 R -0.221→-0.255로 악화
- 남은 실행: 이전 기간 전체 선택 75건은 로컬 전체 replay와 두 번째 원본이 필요. 이 환경에는 종료 67건 요약만 있어 실행하지 않았으며 문서에 정확한 명령 제공
- 파일: bin/paper/BreakevenResearch.php, bin/paper_breakeven_research.php, tests/breakeven_research.php, docs/pullback-breakeven-20261010.md/json, CI
- 운영: 원본·전략 지문·고정 손절·계좌 변경 없음. 두 기간 검증 완료나 운영 적용을 뜻하지 않음

## 2026-10-10 · Codex · 진행
- 작업: 종가 +3% 확인 다음 세션부터 체결가 손절을 적용하는 독립 연구 CLI·기존 청산 비교. 운영 전략 보존

## 2026-10-10 · Codex · 끝
- 작업: PR #58의 ‘불일치’를 ‘15% 초과 비교 표본 없음·10~15% 표본 부족’으로 정정. 아래 Cursor 완료 기록의 ‘반복되지 않음’도 반증으로 해석하지 않음
- 연구: 저장된 상승 눌림 종료 137건의 중간 완료봉에서 3% 상승 이후 다음 세션 되돌림 비교. 최종 수익 거래도 이전 14/23·최근 7/24건이 이후 진입가 이하를 관측
- 검증: 기존 첨부 일봉 verify·출처 해시 확인, 137건 보유 구간/기존 최대 상승률 일치, 경로 계산 6개 검사·PHP 구문·diff 통과. 두 번째 원본의 공유 OHLCV 전체 비교는 재실행하지 않음
- 판단: 본전 이동은 큰 수익 기회도 바꿀 수 있음. 대체 청산 수익률은 계산하지 않았으며 전략·운영 계좌 변경 없음
- 파일: docs/pattern-compare-20251002.md, docs/pullback-paths-20261010.md/json, docs/research/pullback-paths.php

## 2026-10-10 · Codex · 진행
- 작업: PR #58의 표본 부재 해석 정정 및 두 기간 상승 눌림의 수익/손절 거래 경로 비교. 운영 조건 유지

## 2026-10-10 05:50 · Cursor · 끝
- 한 일: 같은 전략으로 2024-10-08~2025-10-02 재현(평가 137종목·33,058 종목일). 상승 눌림 종료 67건과 PR #56 70건의 손절 폭 구간을 비교. 15% 초과는 0건이라 기존 후반의 넓은 손절 부진은 반복되지 않음. 전략·손절·목표·계좌 변경 없음
- 파일: bin/paper_pattern_replay.php, bin/paper/PullbackCompare.php, docs/research/prior-period-compare.php, tests/pullback_compare.php, tests/history_research.php, docs/pattern-compare-20251002.md, docs/pattern-compare-20251002.json, docs/pattern-history-replay.md, .github/workflows/pattern-replay.yml, docs/work-log.md
- 커밋: 브랜치 cursor/prior-year-replay, PR 대기(병합 안 함)
- 롤백: 없음

## 2026-10-10 06:10 · Cursor · 끝
- 한 일: 같은 고정 종목군(156개, universe.json 바이트 복사)의 과거 1년 구간 일봉을 5y로 확보(요청일 2025-10-08은 휴장이라 기준일 2025-10-02, 평가 시작 2024-10-08, 준비 시작 2023-10-08). 151 성공·5 Yahoo 404, 보류 14. 첫 데이터셋과 공유 69,888 종목·날짜 OHLCV 불일치 0. 기준일 지정·종목군 복사·기준일 이후 가격 급변 경고·데이터셋 비교 기능 추가
- 파일: bin/paper/HistoryResearch.php, bin/paper_history_prepare.php, tests/history_research.php, docs/history-data-prep.md, docs/history-data-20251008/, docs/work-log.md
- 커밋: 브랜치 cursor/history-data-prep-2, PR 대기(병합 안 함)
- 롤백: 없음

## 2026-10-10 · Codex · 끝
- 작업: PR #55 상승 눌림 종료 70건의 전후반·승패·손절 경로·거래량·중복 노출 복기 보고서와 읽기 전용 재현 스크립트 추가
- 관찰: 후반 평균 -4.91%, 신호 손절 폭 중앙값 8.77%→15.26%. 손절 44건 중 갭 7건, 중간 완료봉에서 3% 이상 상승 후 손절 24건(분류 중복 가능)
- 민감도: 같은 종목의 겹친 보유 기간 66묶음에 동일 가중치를 주면 평균 +0.89%→-0.10%. 실제 계좌 수익률이나 중복 금지 시뮬레이션 아님
- 검증: 원본·전략·입력 해시 확인, 기존 70건 신호와 전체 모의 결과 재현 일치, PHP 구문 및 diff 검사 통과
- 판단: 위험 폭/변동성 구간과 상승 후 반전 경로를 새 표본에서 별도 검증할 후보로 남김. 전략 조건·운영 계좌 변경 없음
- 문서: docs/pullback-review-20261010.md, JSON 및 docs/research/pullback-review.php

## 2026-10-10 · Codex · 진행
- 작업: PR #55 상승 눌림 종료 70건의 전후반·이익/손실·갭·진입 후 경로·추세/거래량 복기. 조건 변경 없이 원인 후보와 한계 보고

## 2026-10-10 · Codex · 끝
- 작업: 첨부 데이터셋 verify 통과 후 고정 종목군 12개월 날짜별 재현 CLI·신호 중복 제거·미래봉/품질 차단·단계별 관찰 및 독립 모의 결과 추가
- 실측: 143종목, 34,761개 종목·날짜 중 34,464 평가·297 품질 차단. 최종 추천 113건, 종료 모의 거래 100건(재지지 15·눌림 70·회복 15). 손익비 지정가 연구는 별도 578신호·164종료
- 관찰: 높은 저점형 watch 295·breakout 97, 반복 구조/단계 940건 제외. watch 지연 첫 관측 8건 구분, 후속 품질/미성숙 표본 분리
- 판단: 눌림 종료 평균 +0.89%이나 중앙값 -5.63%·후반기 -4.91%. 회복 평균 +8.55%는 15건으로 잠정. 계좌 수익률/독립 표본으로 해석하지 않음
- 검증: 신규 17개 검사·PHP 구문·diff 검사 및 전체 데이터 512MB 실행 통과. 원본 해시·전략 지문 유지, 운영 파일·계좌 변경 없음
- 문서: docs/pattern-history-replay.md, docs/pattern-replay-20261008.md 및 JSON. 미래 가격은 결과 측정에만 사용, 당시 TOP100/수정주가 완전 복원 아님

## 2026-10-10 · Codex · 진행
- 작업: 첨부 고정 종목군 일봉의 무결성 확인 후 12개월 날짜별 패턴 재현·중복 제거·후속 완료봉 및 독립 모의 결과 연구. 원본·운영 계좌 보존

## 2026-10-10 00:10 · Cursor · 끝
- 한 일: 저장 스캔 한국 종목 고정(192개 코드 중 수집 대상 156, 상품 제외 35, 식별 보류 1)·기준일 2026-10-08 약 2년 일봉 수집(151 성공, 5 Yahoo 404 실패)·품질 점검·해시 manifest·재개 가능 CLI 추가. 원본은 상태 폴더 history-research에만 저장, 전략·점수·계좌 파일 변경 없음
- 파일: bin/paper/HistoryResearch.php, bin/paper_history_prepare.php, tests/history_research.php, docs/history-data-prep.md, docs/history-data-20261008/, .github/workflows/history-research.yml, docs/work-log.md
- 커밋: 브랜치 cursor/history-data-prep, PR 대기(병합 안 함)
- 롤백: 없음

## 2026-10-09 · Codex · 끝
- 작업: 현재 3개 추천 패턴의 저장 원본 재평가·저장 후속 증거 전용 CLI와 별도 연구 보고서 추가. 운영 상태 쓰기·네트워크·계좌 변경 없음
- 실측: 500건 중 입력 없음 33, 상품 제외 25, 종목 식별 불일치 1을 빼고 441건. 최종 추천 0, 손익비 지정가 연구 21(진입 전 취소 12·손절 종료 1·대기 8)
- 관찰: 1봉 344건·3봉 156건, 5/10/20봉 없음. 높은 저점형 현재 제외 규칙 연결 후 관찰 7·돌파 1. 옛 ETF 포함 원시 집계와 구분
- 검증: 미래 정보·증거 시각·옛 계획 미사용·해시/식별/상품 제외·미완료 분모·CLI 원본 보호·증거 손상 검사 통과. 실제 자료 512MB 제한 실행, 전략 지문 유지
- 문서: docs/historical-research-20261009.md 및 JSON. 당시 저장 종목만 사용하며 빠진 차순위 종목은 미복원, 성과 판단/추천 조건 완화 없음

## 2026-10-09 · Codex · 진행
- 작업: 당시 저장 종목군을 현재 3개 패턴으로 재평가하고 저장 후속 증거로 관찰·독립 모의 결과 집계. 돌파 전 높은 저점 연구와 함께 별도 보고, 원본·계좌 보호

## 2026-10-09 · Codex · 끝
- 작업: 돌파 전 높은 저점형을 독립 관찰로 추가. 낮은 저점→반등 상단 고정→높은 저점 확정→상단 종가 돌파를 분리하며 원문의 60분봉을 일봉 연구 가설로 명시
- 추적: 동일 구조·단계별 최초 관측만 1/3/5/10/20 완료봉 추적. 기존 판정/연구 실행 버전별 분리, 동결 원본·가격 증거·실패 시 마지막 성공 결과 보존
- 연결: 한국 예약의 독립 단계, 탈락 이유·연구의 ‘돌파 전 높은 저점형’ 화면. 기존 추천·점수·주문·계좌 전략 지문 유지
- 검증: 신규 PHP 경계·원본/중복/미래·CLI/GET·동시실행·저장증거/가격변경 검사 통과, 예약 Python 27개 통과. 실제 과거 저장 스캔에서 466건 보존 및 종목 식별 불일치 1건 보류 확인(512MB 제한, 최대 약 68MB)
- 범위: 관측 가격 변화이며 매매 수익률 아님. 3:7 분할·숏·선물·SQQQ 거래는 추가하지 않음. 정의/실행 docs/prebreak-higher-low-research.md

## 2026-10-09 · Codex · 진행
- 작업: 돌파 전 높은 저점형 독립 관찰·1/3/5/10/20 완료봉 후속 연구 및 읽기 전용 화면 추가

## 2026-10-09 · Codex · 끝
- 원인: 11종목 모두 Yahoo chart 원응답에서 2026-03-27 close > high 재현. 저장 과정에서 새로 발생한 오류가 아님
- 조치: 국내 완료 일봉의 잘못된 종가만 독립 네이버 O/H/L/V 및 앞뒤 거래일 OHLCV 일치 시 보정. 원응답 SHA256·원래 봉·수집 시각 보존, 실패/불일치 시 품질 차단 유지
- 확인: 실측 발췌 11건 중 10건 보정 가능. 한화솔루션은 가격·거래량 기준 불일치로 보류. 특정 날짜 예외 없음
- 검증: historical_close, yahoo_missing_close, midnight_daily, envelope_research, followup PHP 회귀 통과. 신규/캐시 수집·원본 보존·잘못된 출처/중복/미래/미완료/손상·동결 추적 검사 포함
- 보호: 기존 동결 관측/장부/Yahoo 원본 캐시 덮어쓰기 없음. 과거 엔벨로프 11건 자동 재평가 없음. 전략 지문 934012285f2ddb3867fff4576dba05fb7df290a36a987c36fc260e8917bbe465 유지
- 안내: docs/historical-close-repair.md. 새 수집 입력부터 적용, 한화솔루션의 동일 가격 기준 원본 확인은 미완료

## 2026-10-09 · Codex · 진행
- 작업: 3월 27일 오류 봉의 공급자 원본 대조 및 검증된 데이터 복구 경로 점검

## 2026-10-08 · Codex · 끝
- 점검: main의 회복형 계좌 연결 및 23:35 실행 요약 확인. 예약 실행 자체·후속 완료봉 누적은 로컬 실행 원본이 없어 미확인
- 품질 보류: EnvelopeResearch의 일반 품질 차단 또는 MA240 범위 내 invalid_bars 조건. 실제 11종목·원인은 frozen 원본 필요; latest.json에는 quality 상세를 제거해 저장함
- 동결 원칙: 품질 보류 관측은 이후 refresh에서 재평가하지 않음. 신규 관측과 구분 필요
- 추가 자료: PAPER_STATE_DIR 아래 envelope-research/paper-kr-recovery-v1/latest.json 및 해당 frozen/*.json, 다음 실행 후 runs/paper-kr-recovery-v1-forward의 최신 JSON과 followup/paper-kr-recovery-v1/latest.json
- 검증: 관련 저장·판정·예약 연결 소스 대조, 문서만 변경하여 실행 테스트 생략. 운영 코드·전략·장부 변경 없음

## 2026-10-08 · Codex · 진행
- 작업: 회복형 계좌 실행 자료 및 엔벨로프 품질 보류 조건·추가 확인 자료 점검

## 2026-10-08 23:25 · Codex · 끝
- 작업: 회복형 새 모의계좌·20:20 실행 설정 연결, 무추천 시에도 계좌 버전 사전 검사, 탈락 표의 패턴 자체 판정과 최종 판정 분리
- 기존 계좌/장부/과거 연구 이관 없음. 새 계좌 설정·기존 실행 파일 연결·읽기 전용 사전 검사와 실제 페이지 출력 회귀 추가. Python 회귀 통과, 전체 검증은 PR checks 참조

## 2026-10-08 · Codex · 끝
- 한 일: 추세 이탈 후 회복을 세 번째 독립 추천 패턴으로 연결. 종가 돌파→돌파 후 높은 저점→재상승 확인, 당시 확정 저항·손절·손익비와 3봉 지정가 적용
- 표시: 패턴별 조건·고정 저항/저점/목표 및 확정 시각 보존, 기존 점수 상세 유지. 새 규칙 스캔 캐시 분리
- 검증: 미래봉·확정 전 신호·동률/접촉·손익비·실제 공통 위험 차단·운영 추천 연결·화면 회귀 추가. 동결 과거 연구와 새 규칙 재진단 격리 별도 검사. 최종 결과 PR #48 checks 참조
- 범위: 실제 전략 변경으로 새 계좌 전략 지문 적용. 기존 계좌 자동 이관/장부 수정/예약 설정 변경 없음. 정의·적용 영향 docs/trend-recovery-pattern.md
- PR: #48 (병합 안 함)

## 2026-10-08 14:45 · Codex · 끝
- 한 일: 종목 상세 간단/분석 모드 모두 항목별 점수·가감·근거 표시, 패턴 자체 판정/선택/공통 차단 분리
- 기록: 마감 감사 원본을 읽기 전용으로 표시, 새 수동 관찰 기록에 pattern_evidence 보존. 장중 점수와 마지막 완료봉 패턴의 기준 시각 분리
- 검증: 미평가와 미충족 구분·선택 전후 상태·과거 기록 보존·출력 안전성·장중 구분 검사 추가. 전체 결과 PR checks 참조
- 범위: 기존 추천/주문 규칙·계좌 전략 버전 유지. 새 글의 회복형 추천 조건은 아직 추가하지 않음

## 2026-10-08 09:30 · Codex · 끝
- 한 일: 공통 메뉴 목적별 분리, 화면 제목/용어 안내, 표·폰트·간격·모바일 가독성 개선
- 표시: 진입/손절/목표 개별 라벨, 상태 코드 한글화, 탈락 표 11열→5열과 근거 펼치기, 연구 메뉴 통합
- 검증: 기존 정렬 회귀 통과, 숫자 결측/정밀도·한글 상태·출력 이스케이프·메뉴 계좌 보존 검사 추가. 전체 검증 PR checks 참조
- 범위: 표시 전용. 전략·주문·가격 계산·저장 형식 유지

## 2026-10-07 23:44 · Codex · 끝
- 한 일: 네이버 시세 없음 시 원본 해시·종목·당일 완료 OHLCV·가격 품질을 검증한 Yahoo 입력만 보존, 대체 사용 및 오류 상세 로그/화면 표시
- 표시: 평가 대상 없음과 계좌 거래일 미기록을 새 거래일 없음에서 분리
- 검증: 예약 실패 상세 저장 Python 회귀 통과. PHP 원본 보존·종목/종가 누락·장중/오래된 시세·해시 위조 차단 회귀 추가, 전체 결과 PR checks
- 범위: 과거 주문 소급/계좌 재개 없음. Yahoo가 불완전하거나 계좌 고정 전략/과거 가격이 다르면 기존 차단 유지

## 2026-10-07 20:59 · Codex · 끝
- 한 일: 후속 원본을 실행 묶음별 순차 처리, 종목 가격은 첫 조회 증거 파일로 고정·재사용, 처리한 이전 결과 해제
- 검증: 2,000기록×500봉 512MB 재현·일반/저장증거 실행·결과/해시 동등성·중복/오류·최신 결과 보호 검사 추가. 실행 결과 PR checks
- 범위: 메모리 사용·증거 무결성 검사. 전략·손익 계산·저장 형식·메모리 한도 유지

## 2026-10-07 20:45 · Codex · 끝
- 한 일: 오늘 변화 화면, 새 확인/해제/첫 관측 구분, 기존 추천 모의 결과와 계좌 이벤트 분리, 감사 원본 요약 연결
- 검증: TOP100 이탈·조회 실패·프로필 분리·같은 봉 재실행·종료 중복·이력 손상·페이지 출력 회귀 추가. 실행 결과 PR checks 참조
- 범위: 읽기 전용. 만료/취소는 보존 이력 최초 확인일이며 실제 발생일 아님. 운영 전략·스케줄러 유지

## 2026-10-07 13:12 · Codex · 끝
- 한 일: 진입 확인→결과 화면, 기존 baseline 후속 결과 엄격 연결, 수동 스캔 원본·계획 별도 보존과 장중/마감 판정 비교
- 검증: PHP 회귀에서 캐시 중복·가격 고정·프로필 격리·원본 불일치 차단·실제 페이지 출력 통과. 전체 결과 PR #42 checks 참조
- 범위: 운영 전략·주문 유지. 수동 장중은 잠정 관찰이며 체결 소급 판정 없음; 과거 예약/CLI 구분·전략 버전은 미추정
- PR: #42 (병합 안 함)

## 2026-10-07 12:50 · Codex · 끝
- 한 일: 정렬 초기화 격리, 저장소 오류 방어, 정렬 기준 표시와 클릭 회귀 검사
- 검증: Node DOM 모형에서 저장소 읽기/쓰기 오류 재현, 점수/계획 전환, 동점/결측 점수, 필터 유지·같은 순서 안내 통과. PHP 회귀는 PR checks
- 한계: 사용자 브라우저의 실제 오류는 미확인. 정렬 초기화를 앞선 UI 설정 오류에서 격리

## 2026-10-07 12:34 · Codex · 끝
- 한 일: 패턴 확인/관심 구간 위치/실제 지정가 계획 분리 표시 및 계획 거리 정렬
- 검증: 삼성E&A 원본 지정가 48050/RR 3.994·관심 구간 분리, 계획 누락/잘못된 가격 배제, 실제 지정가 거리 정렬 회귀 추가
- 범위: 표시·수동 정렬만 변경; 전략/주문/예약 선정은 유지. 실행 결과 PR checks 참조

## 2026-10-07 01:49 · Codex · 끝
- 한 일: 레메디 실제 Yahoo 응답의 일봉 close=null 누락 재현 및 마감 시세 검증 보완
- 검증: 실제 응답 필드로 10월 6일 완료봉 복원, 마감 전·다른 날짜·미래·종목 불일치·부분 OHLCV 거절. 실행 결과 PR checks
- 범위: 실제 OHLCV 보존, 같은 거래일 마감 시세만 종가 보완; 전략 규칙 변경 없음

## 2026-10-07 00:50 · Codex · 끝
- 한 일: 마감 후 재수집 증거로 일봉 완료 상태 갱신, 장외 수동 분석 경로 분리 및 자정 회귀 검사
- 검증: 제공 3종목 원본·마감 전 캐시 거절·마감 후 갱신·장중/장외 분리 (실행 결과 PR checks)
- 한계: 당시 로컬 수집 경로는 응답/캐시 부재로 미확정. 없는 봉 생성/전략 규칙 변경 없음

## 2026-10-06 14:48 · Codex · 끝
- 한 일: 급등봉 저가/아래꼬리 오탐 제거, 종가 되돌림과 사전 지지 이탈로 급등후급락 차단 제한
- 검증: 지지 유지·접촉·아래꼬리·회복은 제외, 돌파 가격/확인 저점 종가 붕괴는 유지하는 회귀 검사 추가. PR checks에서 실행
- 운영: 실제 전략 변경. 기존 계좌/호환 목록은 수정하지 않음; 새 규칙 모의 운영은 별도 계좌 ID 필요

## 2026-10-06 13:16 · Codex · 끝
- 한 일: Daum 실제 tradeDate/tradeTime 해석 수정, 수동 진행봉·예약 완료봉 경로 검증
- 검증: 실제 응답 형식·잘못된 거래시각·신선도, 수동 가격/예약 증거 분리, 20:20 완료봉 회귀 검사 추가. 실행 결과는 PR checks 참조

## 2026-10-06 13:00 · Codex · 끝
- 한 일: 수동 스캔 진입 확인/관찰 분리, 관심 구간 거리·목표 초과 표시, 장중 분석 대체 사유 노출
- 범위: 운영 전략 점수·가격 산식·예약 스캔 정렬 유지
- PR: #35 · 자동 검증 결과는 PR checks에서 확인
- 회귀 검사: 하나마이크론 가격 사례, 거리 우선 정렬, 장중·대체 분석 비확정, 공급자 오류 기록

## 2026-10-03 23:19 · Codex · 끝
- 한 일: 엔벨로프 512MB 메모리 초과 수정. 동결 원본 분리·묶음별 등록·장부/증거 순차 읽기·가격 캐시 8종목 제한·행별 직렬화
- 파일: EnvelopeStore, JsonRows, 엔벨로프 CLI/화면·테스트·워크플로·문서
- 커밋: PR #34 (병합 안 함)
- 검증: 512MB 제한 실제 467건 첫 실행/재실행, 2,000건×500봉 처리 최고 46,280,704바이트(약 44.1MiB), 기존 형식 전환·해시/결과 유지·깨진 원본/JSON 차단, 기존 회귀 및 운영 로그 38개 통과
- 범위: 연구 계산 파일/버전 불변. 기존 파일 삭제나 PHP 메모리 설정 변경 불필요
- 롤백: 없음

## 2026-10-03 22:35 · Codex · 끝
- 한 일: 엔벨로프 20/9 전체 평가 종목 관찰·원본 고정·독립 1/3/5/10/20봉 추적·KR 예약·연구 화면 연결
- 파일: EnvelopeResearch 모듈·화면·CLI, paper_daily.py, paper_rr.php, 신규/기존 테스트·워크플로·문서
- 커밋: PR #33 (병합 안 함)
- 검증: 예약 경로 Python 26개, GitHub Actions PHP 문법·신규 경계/동결 자료 통합·기존 회귀 통과. 미래 봉 배제, 연속 체류/재진입, 원본 고정, 오류 보존, 원본 삭제 후 추적, 동시 쓰기 차단 확인
- 범위: 운영 추천·점수·주문 불변. 과다 이격·첫 박스·강한 상승은 확정하지 않으며 대용 조건과 연구 가정을 명시
- 롤백: 없음

## 2026-10-01 22:40 · Codex · 끝
- 한 일: 목표 비교 후보 영구 보존·예약 후속 추적·주간 요약 연결
- 파일: 연구 추적 모듈·CLI·예약 실행·화면·테스트·문서
- 커밋: PR #32 (병합 안 함)
- 검증: 예약 경로 Python 26개 및 GitHub Actions PHP 문법·전체 회귀·운영 로그 통과. 기존 13건 보존·2건 미청산 복구, 원본 삭제 후 갱신, 중복/변경 원본 보류, 20관찰봉 이후 청산, 주간 이전 후보 청산, 동시 쓰기 잠금 검증
- 롤백: 없음

## 2026-10-01 17:54 · Codex · 끝
- 한 일: 종가 돌파 13건의 가장 가까운 상단 저항 목표 모의 비교
- 파일: 목표 비교 모듈·CLI·화면·검사·결과 문서
- 커밋: PR #31 (병합 안 함)
- 검증: GitHub Actions PHP 문법·회귀·운영 로그 검사 통과. 13건 중 비교 9건·위험 차단 3건·상단 없음 1건. 추가 통과 및 체결 2건, 미청산으로 순손익 미확정. 원본 불변 확인
- 롤백: 없음

## 2026-10-01 17:33 · Codex · 끝
- 한 일: 목표가 공식·기존 고점 돌파·상단 저항 근거 진단
- 파일: 목표가 진단 모듈·CLI·화면·테스트·문서
- 커밋: PR #30 (병합 안 함)
- 검증: GitHub Actions PHP 문법·회귀·운영 로그 통과. 동결 42건 진단, 공식·저장 가격 불일치 0건. 확인 완료 13건/가정 2건은 종가가 기존 목표 위. 로컬 PHP 환경 없음
- 롤백: 없음

## 2026-10-01 17:14 · Codex · 끝
- 한 일: 기존 10봉 최저가와 최근 눌림 저점 손절의 분리 비교 연구 및 동결 입력 결과 저장
- 파일: 연구 모듈·CLI·화면·테스트·문서
- 커밋: PR #29 (병합 안 함)
- 검증: GitHub Actions PHP 문법·회귀·운영 로그 검사 통과. 원본 467건·가격 증거 173개 해시 확인. 확인 완료 29건 중 18건, 단일 조건 가정 13건 중 8건 비교. 추가 통과 양쪽 0건. 동일 청산 1건의 순손익 차이 0%p. 로컬 PHP 실행 환경 없음
- 롤백: 없음

## 2026-10-01 11:33 · Codex · 끝
- 한 일: 원본 증거 검증, 집계 수정, 단일 조건 가정 손익비·체결 연구 및 지정가 취소 근거 추가
- 파일: 연구 모듈·CLI·화면·테스트·문서
- 커밋: PR #28 (병합 안 함)
- 검증: 원본 467건·가격 증거 173개 해시 확인. 13건 중 RR 탈락 10, 가격 역전 2, 통과 1(모의 손절 -2.0216%). PHP 회귀 통과
- 롤백: 없음

## 2026-10-01 03:59 · Codex · 끝
- 한 일: 단일 조건 탈락의 증거 충족·미평가 분리, 버전·패턴별 후속 성과와 완료 표본 표시. 기존 예약 후속 수집 경로 확인
- 파일: 최근 기록일 진단 모듈·화면·테스트·문서
- 커밋: PR #27 (병합 안 함)
- 검증: 신규 26개 경계 검사, 기존 PHP 회귀 및 운영 로그 검사 통과
- 롤백: 없음

## 2026-10-01 03:02 · Codex · 끝
- 한 일: 원본에서도 제외된 오래된 오류 봉의 후속 추적 재차단 수정. 새 오류·최근/후속 오류·중복 차단 유지
- 파일: TrackingInput, tracking_input 테스트, 문서
- 커밋: PR #26 (병합 안 함)
- 검증: 추적 검사 38개(신규 10개 포함), 기존 PHP 회귀 통과
- 롤백: 없음

## 2026-10-01 00:47 · Codex · 끝
- 한 일: 후속 추적의 조회 범위 앞쪽 누락·거래량 차이 분리, 가격/종목 불일치 격리, 저장 evidence로 재실행
- 파일: 추적 입력 검증·감사 로그·후속 CLI·화면·테스트·문서
- 커밋: PR #25
- 검증: 신규 28개 및 PR 검사 9개 통과
- 롤백: 없음

## 2026-10-01 00:22 · Codex · 끝
- 한 일: v12의 과거 전략→현재 전략 영구 호환 가정을 제거하고 검증된 대상 버전·비호환 차단·상태 보존 검사로 수정
- 파일: `tests/v12.php`, `tests/version_integration.py`, `docs/work-log.md`
- 커밋: PR #24 추가 수정 (병합 안 함)
- 검증: 기존 실패 4개(push/PR) 및 PR 검사 9개 통과. 실제 전략·호환성 허용표·차단 로직 변경 없음
- 롤백: 없음

## 2026-10-01 00:15 · Codex · 끝
- 한 일: 기존 감사 로그 일괄 재진단, 현재 코드 재평가와 당시 저장 판정 비교, 추적 일봉 불일치 상세 기록
- 파일: 재진단 CLI·모듈·화면·테스트·문서
- 커밋: PR #24 (병합 안 함)
- 검증: 신규 23개 검사 및 PHP 회귀·운영 로그 테스트 통과. 기존 main의 전략 호환성 관련 CI 2개 실패는 별도 명시
- 롤백: 없음

## 2026-09-30 · Codex · 끝
- 한 일: 최근 5개 기록일의 선택 패턴·최종 판정 분리, 거래량 단독 미충족 수치 표시, 기존 손익비 후속 추적 연결. 매매 기준 변경 없음
- 파일: `bin/paper/RejectionReview.php`, `bin/paper/RejectionReviewPanel.php`, `paper_rr.php`, `tests/rejection_review.php`
- 검증: 신규 15개 검사 및 PHP 회귀 통과. main에서도 실패 중인 전략 버전 호환성 관련 CI 2개는 이번 범위에서 변경하지 않음
- PR: #23 (병합 안 함)
- 제한: 운영 상태 파일은 미보유. 실제 후속 연결은 배포 후 확인 필요
- 롤백: PR 커밋 revert

# 작업 이력

Cursor · Astra · 사람이 같은 저장소를 만질 때 겹치지 않게 여기만 본다.  
**작업 시작 전** 맨 위를 읽고, **끝날 때·롤백할 때** 맨 위에 한 줄을 추가한다. 새 항목이 위다.

동시에 하는 일은 거의 없으니 잠금 파일은 없다. 누가 지금 손대는지만 적으면 된다.

## 적는 법

```
## YYYY-MM-DD HH:MM · 누가 · 상태
- 한 일:
- 파일:
- 커밋: 안 함 | (해시 또는 메시지)
- 롤백: 없음 | 무엇을 되돌렸는지
```

- 누구: `Cursor` / `Astra` / `사람`
- 상태: `진행` (아직 만지는 중) · `끝` · `롤백`
- 커밋 전에 `git checkout` / `git restore` / 파일 삭제로 되돌리면 **롤백**으로 남긴다. 남긴 커밋을 `git revert`한 것도 여기 적는다.
- 코드 설명·설계 문서는 여기 쓰지 않는다. 한 일과 시간만.

---

## 2026-10-09 15:40 · Cursor · 끝
- 한 일: 프네푸 SQQQ·복기 글을 HTML로 정리하고 복기 차트를 파일로 넣음
- 파일: docs/fmkorea-10425804725.html, docs/fmkorea-10426879241, docs/work-log.md
- 커밋: 함
- 롤백: 없음

## 2026-10-08 23:45 · Cursor · 끝
- 한 일: paper-kr-recovery-v1 23:35 실행 결과를 HTML로 정리
- 파일: docs/paper-kr-recovery-v1-20261008.html, docs/work-log.md
- 커밋: 함
- 롤백: 없음

## 2026-10-07 00:20 · Cursor · 끝
- 한 일: 10월 6일 20:23 감사 로그와 새 코드 거래대금 스캔의 급등후급락 행을 모아 깃에 올림
- 파일: docs/spike-dump-20261006, docs/work-log.md
- 커밋: 함
- 롤백: 없음

## 2026-10-01 04:40 · Cursor · 끝
- 한 일: 5개 기록일 감사 로그·후속 추적·evidence를 docs/paper-kr-5d-source에 풀어 깃에 올림
- 파일: docs/paper-kr-5d-source, docs/work-log.md
- 커밋: 함
- 롤백: 없음

## 2026-10-01 04:37 · Cursor · 끝
- 한 일: paper-kr 최근 5개 기록일 원본 감사 로그·후속 추적·evidence를 ZIP으로 묶음
- 파일: docs/work-log.md
- 커밋: 안 함
- 롤백: 없음

## 2026-10-01 04:29 · Cursor · 끝
- 한 일: 현재 paper-kr 최근 5개 기록일 진단을 1회 스냅샷으로 저장
- 파일: docs/paper-kr-diagnosis-5d.json, docs/work-log.md
- 커밋: 함
- 롤백: 없음

## 2026-10-01 04:24 · Cursor · 끝
- 한 일: 최근 5개 기록일 진단의 종목과 미확인 조건을 기본으로 펼침
- 파일: bin/paper/SingleConditionPanel.php, docs/work-log.md
- 커밋: 안 함
- 롤백: 없음

## 2026-10-01 04:05 · Cursor · 끝
- 한 일: paper-kr 후속 추적을 가격 갱신으로 실행. 467건, 오류 0, 상태 partial
- 파일: docs/work-log.md
- 커밋: 안 함
- 롤백: 없음

## 2026-10-01 03:42 · Cursor · 끝
- 한 일: paper-kr 후속 추적을 저장 evidence로 다시 계산. 467건, 오류 0, 상태 partial
- 파일: docs/work-log.md
- 커밋: 안 함
- 롤백: 없음

## 2026-10-01 00:59 · Cursor · 끝
- 한 일: paper-kr 후속 추적을 저장 evidence로 재계산. 467건, 오류 0, 상태 partial
- 파일: docs/work-log.md
- 커밋: 안 함
- 롤백: 없음

## 2026-10-01 00:36 · Cursor · 끝
- 한 일: paper-kr 기존 감사 로그 일괄 재진단 실행. 500건, 오류 0
- 파일: docs/work-log.md
- 커밋: 안 함
- 롤백: 없음

## 2026-10-01 00:35 · Cursor · 끝
- 한 일: 로컬과 origin/main이 겹친 paper_rr.php를 합침. 재진단 링크는 앞으로 기록에, 최근 5개 기록일 진단은 과거 재현에도 표시
- 파일: paper_rr.php, docs/work-log.md
- 커밋: 병합 커밋
- 롤백: 없음

## 2026-09-30 23:59 · Cursor · 끝
- 한 일: 과거 재현 탈락 로그에도 최근 5개 기록일 진단 링크를 표시
- 파일: `paper_rr.php`, `docs/work-log.md`
- 커밋: 안 함
- 롤백: 없음

## 2026-09-30 21:36 · Cursor · 끝
- 한 일: 5거래일 탈락 로그에서 일봉을 빼고 요약 파일을 만듦
- 파일: `docs/paper-kr-rejection-5d.json`, `docs/work-log.md`
- 커밋: 안 함
- 롤백: 없음

## 2026-09-30 21:15 · Cursor · 끝
- 한 일: 탈락 로그 표에 패턴 사유와 최종 보류 문장을 표시
- 파일: `paper_rr.php`, `bin/paper/RrView.php`, `tests/rr_view.php`, `docs/work-log.md`
- 커밋: 안 함
- 롤백: 없음

## 2026-09-28 11:41 · Astra · 끝
- 한 일: 수동 조회의 당일 OHLCV 잠정 점수와 예약 실행 완료 일봉 분리. 당일 봉·지연·누락·미래 시세·마감 처리 검사 통과. main부터 존재한 v12 호환성 실패는 PR에 별도 명시
- 파일: ProposalService, KrAmountScanner, CurrentQuoteClient, IntradayAnalysis, index.php, 테스트·문서
- 커밋: PR #22 — feat: calculate provisional manual scores from current-session OHLCV
- 롤백: 없음

## 2026-09-28 11:06 · Cursor · 끝
- 한 일: 신규 추천 보류 기준을 완료 일봉 96시간에서 144시간으로 늘림
- 파일: src/ChartPlanEngine.php, src/LegacyChartPlanEngine.php, bin/paper/RrAudit.php, tests/run.php, docs/retest-upgrade.md, docs/work-log.md
- 커밋: 안 함
- 롤백: 없음

## 2026-09-28 10:50 · Cursor · 끝
- 한 일: 거래대금 스캔 현재가 칸에서 조회 시각을 뺌
- 파일: index.php, docs/current-quote.md, docs/work-log.md
- 커밋: 안 함
- 롤백: 없음

## 2026-09-28 10:36 · Astra · 끝
- 한 일: 수동 스캔·종목 검색 현재가와 완료 일봉 분석가 분리. 수동 조회는 기록을 덮어쓰지 않음. 현재가 경계 검사·기존 회귀 CI 통과
- 파일: index.php, 현재가 클라이언트, 스캐너, 테스트
- 커밋: PR #21 — fix: show current quotes for manual scans and symbol lookup
- 롤백: 없음

## 2026-09-26 14:22 · Cursor · 끝
- 한 일: 작업 이력 병합 충돌을 없애고 14:14·14:13 기록을 시간순으로 다시 둠
- 파일: docs/work-log.md
- 커밋: 안 함
- 롤백: 없음

## 2026-09-26 14:14 · Astra · 끝
- 한 일: 선행·동일일 이탈 분리, L3 구조 검증, 전고점 회복 표시 및 일봉 최신성 분리. 기존 작업 이력 충돌 표식을 제거하고 양쪽 기록 보존. 고점 판독 33개 검사 및 PR 회귀 CI 통과
- 파일: TopWaveReview, TopWavePanel, 테스트, 문서
- 커밋: PR #20 — fix: enforce top-wave breach order and separate data freshness
- 롤백: 없음

## 2026-09-26 14:13 · Cursor · 끝
- 한 일: 작업 이력에 남은 병합 충돌을 없애고 13:52·13:42·12:16 기록을 시간순으로 다시 둠
- 파일: docs/work-log.md
- 커밋: 안 함
- 롤백: 없음

## 2026-09-26 13:52 · Astra · 끝
- 한 일: 고점 재시도 횟수·고정 L2 종가 이탈 기반 보유 축소 후보, 회복 관찰·경고 해제 및 근거 표시. 신규 23개 검사와 전략 지문·주간 요약 회귀 CI 통과. PR #19
- 파일: `src/TopWaveReview.php`, `bin/paper/TopWavePanel.php`, `bin/paper_top_wave.php`, `paper.php`, `tests/top_wave.php`, `.github/workflows/top-wave.yml`, `docs/top-wave-review.md`, `docs/work-log.md`
- 커밋: a2790c5 (구현), PR #19
- 롤백: 없음

## 2026-09-26 13:42 · Cursor · 끝
- 한 일: 에펨코리아 고점판독 글과 댓글, 차트 3장을 HTML로 저장
- 파일: docs/fmkorea-10376812980/index.html, docs/fmkorea-10376812980/chart-1.webp, docs/fmkorea-10376812980/chart-2.webp, docs/fmkorea-10376812980/chart-3.webp
- 커밋: 안 함
- 롤백: 없음

## 2026-09-23 12:16 · Cursor · 끝
- 한 일: 거래대금 스캔 점수순이 어제 스캔 표를 정렬하던 것을 본 목록으로 고침
- 파일: index.php
- 커밋: 안 함
- 롤백: 없음


## 2026-09-23 01:25 · Cursor · 끝
- 한 일: 탈락 후속 추적 화면에 실행 시각, 추적·완료 수, 가격 실패, 기간별 표본, 재실행 안내를 표시
- 파일: `bin/paper/Followup.php`, `bin/paper/FollowupPanel.php`, `paper_rr.php`, `paper_weekly.php`, `tests/followup.php`, `docs/rejection-followup.md`, `docs/work-log.md`
- 커밋: 안 함
- 롤백: 없음

## 2026-09-23 01:04 · Astra · 끝
- 한 일: 탈락 종목 후속 추적·사유별 성과·지정가 자동 재검증·주간 화면 연결 완료. 신규 PHP 21개, Python 전체 37개 및 기존 RR/스캔/주간 회귀 CI 통과
- 파일: Followup 모듈/CLI/화면, daily, Weekly, 테스트, 사용 문서
- 커밋: 95308de974c63ad2dcc2ee6db7f5854ac2d130ef / PR #18
- 롤백: 없음

## 2026-09-23 00:52 · Cursor · 끝
- 한 일: 워뇨띠 자료 폴더를 git 제외로 바꿈
- 파일: `.gitignore`, `docs/work-log.md`
- 커밋: 워뇨띠 자료는 저장소에 올리지 않음
- 롤백: 없음

## 2026-09-23 00:45 · Cursor · 끝
- 한 일: 거래대금 순위에서 스팩, ETF, ETN, 단일종목 레버리지를 빼고 다음 종목으로 채움
- 파일: `src/KrAmountLeadersClient.php`, `index.php`, `tests/amount_exclude.php`, `.github/workflows/php-tests.yml`, `docs/work-log.md`
- 커밋: db769a9
- 롤백: 없음

## 2026-09-22 14:34 · Cursor · 끝
- 한 일: 모의 계좌 화면 간격·표·탭을 정리하고, 상태 코드와 계좌 이름을 한글로 보이게 함
- 파일: `assets/app.css`, `bin/paper/Chrome.php`, `bin/paper/Diagnostics.php`, `paper.php`, `paper_rr.php`, `paper_diagnostics.php`, `paper_trades.php`, `paper_weekly.php`, `paper_compare.php`, `paper_entry.php`, `paper_revisions.php`, `paper_universe.php`, `docs/work-log.md`
- 커밋: 안 함
- 롤백: 없음

## 2026-09-22 10:05 · Cursor · 끝
- 한 일: 모의 계좌에 탈락 로그 화면을 넣고, 지정가 후보와 기존 추천의 이후 체결·손익을 비교하게 함. 운영 매수는 그대로
- 파일: `paper_rr.php`, `bin/paper/RrView.php`, `bin/paper/Chrome.php`, `tests/rr_view.php`, `.github/workflows/php-tests.yml`, `docs/rr-limit-audit.md`, `docs/work-log.md`
- 커밋: 안 함
- 롤백: 없음

## 2026-09-22 03:20 · Astra · 끝
- 한 일: 기존 손익비 지정가·탈락 사유 로그 패치를 최신 main과 대조하고 회귀 검증 후 PR 등록
- 파일: 지정가 감사 기능·테스트·사용 문서 11개
- 커밋: Add confirmed RR limit research and rejection audit logs
- 롤백: 없음

## 2026-09-21 22:00 · Astra · 끝
- 한 일: 확인 후 손익비 탈락 지정가 연구, 스캔 원본·추가/제외/미충족 사유 로그, 과거 재검증 및 회귀 테스트 추가
- 파일: `bin/paper/RrAudit.php`, `bin/paper_rr_audit.php`, `bin/paper_scan_universe.php`, `bin/paper_daily.py`, `src/ProposalService.php`, `src/KrAmountScanner.php`, `tests/rr_audit.php`, `tests/rr_scan.php`, `tests/test_paper_daily.py`, `docs/rr-limit-audit.md`, `docs/work-log.md`

## 2026-09-21 20:18 · Astra · 끝
- 한 일: 한국 운영 예약을 paper-kr daily 직접 실행으로 정리하고 TOP100 추천·보유 유지 안내 및 회귀 검증 추가
- 파일: `README.md`, `bin/run_paper_daily.cmd`, `docs/paper-account-v7.md`, `docs/paper-diagnostics-v8.md`, `docs/experiment-v10.md`, `tests/v14.php`, `tests/test_paper_daily.py`, `docs/work-log.md`, `docs/paper-kr-scan.md`

## 2026-09-21 15:55 · Cursor · 끝
- 한 일: 잘못된 어제스캔 프롬프트 삭제, 모의계좌는 고정종목이 아니라 당일 스캔 추천으로 매매하라는 아스트라 프롬프트로 바꿈
- 파일: `docs/astra-paper-scan-universe-prompt.md`, `docs/astra-yesterday-rescan-prompt.md`, `docs/work-log.md`
- 커밋: 안 함
- 롤백: `docs/astra-yesterday-rescan-prompt.md` 삭제

## 2026-09-21 15:50 · Cursor · 끝
- 한 일: 어제 스캔 밖 재조회 합의를 아스트라 프롬프트로 정리
- 파일: `docs/astra-yesterday-rescan-prompt.md`, `docs/work-log.md`
- 커밋: 안 함
- 롤백: 없음

## 2026-09-21 15:02 · Astra · 끝
- 한 일: 최신 main 확인 후 진입 조건별 통과/탈락/미평가·단독 탈락 집계와 고정 연구 계좌의 거래량 85→95% 비교 실험 추가. 기존 전략/계좌/TTL 실험 유지. 신규 PHP 기능·CLI·원본 보존 및 Python 실행 테스트 통과
- 파일: `paper_entry.php`, `bin/paper_entry_compare.php`, `bin/paper_entry_daily.py`, `tests/test_entry_daily.py`, `tests/entry.php`, `docs/entry-volume-experiment.md`, `bin/paper/EntryRelaxation.php`, `bin/paper/EntryExperiment.php`, `bin/paper/EntryGates.php`, `bin/paper/Chrome.php`, `.github/workflows/entry.yml`, `tests/entry_cli.php`, `docs/work-log.md`
- 커밋: b33cb8a6c764aec7222398beb1d052f2cc3d67d7
- 롤백: 없음

## 2026-09-21 14:43 · Astra · 끝
- 한 일: 고정 10종목·5업종 연구 계좌, 전체 수집 사전 검사·단계별 시간 기록·업종/종목 보고서 추가. 최신 한국 정규장 보정 흐름 반영. 신규 Python 8개/PHP 8개 및 기존 CI 통과, 공개 일봉 US/KR 각 10/10 확보
- 파일: `paper_universe.php`, `config/paper-research-kr-v1.json`, `config/paper-research-us-v1.json`, `bin/paper_research_daily.py`, `tests/test_research_daily.py`, `tests/universe.php`, `docs/fixed-research-universe.md`, `bin/paper/Universe.php`, `.github/workflows/universe.yml`, `paper.php`, `docs/work-log.md`
- 커밋: 66689c647629cb301ff5ff261bf2eb4fe4d4c45e
- 롤백: 없음

## 2026-09-21 13:17 · Cursor · 끝
- 한 일: 어제 스캔 비교를 어제 점수순으로 두고, 오늘 스캔에 없는 종목만 추가 조회해 등락을 채움
- 파일: `src/ScanSnapshot.php`, `index.php`, `tests/v12.php`, `docs/work-log.md`
- 커밋: 안 함
- 롤백: 없음

## 2026-09-21 13:08 · Cursor · 끝
- 한 일: 어제 스캔 비교는 오늘 스캔에 남은 종목을 점수순으로 먼저 보이게 함
- 파일: `src/ScanSnapshot.php`, `index.php`, `tests/v12.php`, `docs/work-log.md`
- 커밋: 안 함
- 롤백: 없음

## 2026-09-21 13:05 · Cursor · 끝
- 한 일: 어제 스캔 비교를 어제 점수 높은 순으로 바꿈
- 파일: `src/ScanSnapshot.php`, `index.php`, `tests/v12.php`, `docs/work-log.md`
- 커밋: 안 함
- 롤백: 없음

## 2026-09-16 21:45 · Cursor · 끝
- 한 일: 추천 없는 날은 비교를 건너뛰고 스캔 한글 로그는 UTF-8로 읽게 함
- 파일: `bin/paper_daily.py`, `bin/paper_compare_daily.py`, `tests/test_paper_daily.py`, `docs/work-log.md`
- 커밋: 안 함
- 롤백: 없음

## 2026-09-16 21:40 · Cursor · 끝
- 한 일: 한국 모의는 거래대금 스캔 추천만 쓰고 일봉은 2년치. 기존 한국 계좌는 보관 후 새로 시작
- 파일: `config/paper-kr.json`, `bin/paper_daily.py`, `bin/paper_scan_universe.php`, `bin/paper_account.php`, `bin/paper/Experiment.php`, `bin/paper/StrategyVersion.php`, `bin/paper_compare_daily.py`, `src/PaperPortfolio.php`, `src/PaperScanUniverse.php`, `config/paper-strategy-files.json`, `config/paper-legacy-versions.json`, `tests/v14.php`, `tests/test_paper_daily.py`, `.github/workflows/php-tests.yml`, `docs/work-log.md`
- 커밋: 안 함
- 롤백: 없음

## 2026-09-16 21:26 · Cursor · 끝
- 한 일: 한국 모의자금 1억, 미국 100만 달러로 올리고 기존 계좌는 설정 변경이라 보관 후 새로 시작
- 파일: `config/paper-kr.json`, `config/paper-us.json`, `docs/work-log.md`
- 커밋: 안 함
- 롤백: 없음

## 2026-09-16 21:18 · Cursor · 끝
- 한 일: 모의 계좌 화면을 기존 차트 CSS(셸·탭·패널·표)에 맞춤
- 파일: `assets/app.css`, `bin/paper/Chrome.php`, `paper.php`, `paper_diagnostics.php`, `paper_trades.php`, `paper_compare.php`, `paper_weekly.php`, `paper_revisions.php`, `docs/work-log.md`
- 커밋: 안 함
- 롤백: 없음

## 2026-09-16 21:05 · Cursor · 끝
- 한 일: 잠긴 paper-kr 치우고 본장은 최근 봉만 덮은 뒤 한국 모의 다시 실행. 이전 실행 기록은 유지
- 파일: `bin/paper/NaverSessionPatch.php`, `tests/v13.php`, `docs/work-log.md`
- 커밋: 안 함
- 롤백: 없음

## 2026-09-16 19:50 · Cursor · 끝
- 한 일: 한국 모의 20:20 본장(네이버)만 적용, 놓치면 자정 전까지 켜면 실행. 잠긴 paper-kr 보관 후 새 계좌 시작
- 파일: `bin/paper_daily.py`, `bin/paper_compare_daily.py`, `bin/run_paper_daily.cmd`, `bin/paper/NaverSessionPatch.php`, `bin/paper_patch_naver_daily.php`, `bin/paper_schedule_window.php`, `tests/test_paper_daily.py`, `tests/v13.php`, `.github/workflows/php-tests.yml`, `docs/work-log.md`
- 커밋: 안 함
- 롤백: 없음

## 2026-09-16 18:57 · Cursor · 끝
- 한 일: 모의 계좌 전략 지문 줄바꿈 정규화, 기존 paper-us/kr 허용, 일일 작업 재실행 성공(US=0 KR=0)
- 파일: `bin/paper/StrategyVersion.php`, `config/paper-legacy-versions.json`, `tests/v12.php`, `docs/work-log.md`
- 커밋: 안 함
- 롤백: 없음

## 2026-09-16 13:25 · Astra · 끝
- 한 일: 주간 실행·추천·거래 성과·비교 상태 요약 화면과 JSON CLI 추가, 주차 경계 및 원본 보존 검증 완료
- 파일: `bin/paper/Weekly.php`, `bin/paper_weekly.php`, `paper_weekly.php`, `paper.php`, `tests/weekly.php`, `.github/workflows/weekly.yml`, `docs/paper-weekly.md`, `docs/work-log.md`
- 커밋: 9bc8eff5031d21644a5cca4cd797aa37d69ba52b (Add read-only weekly paper account operations and performance summary)
- 롤백: 없음

## 2026-09-16 13:10 · Astra · 끝
- 한 일: 모의 계좌 전략 버전 범위 분리, 검증된 기존 버전 호환 처리, 스캔 가격 선택·출처·관측 시각 통일 및 검증 완료
- 파일: `bin/paper/StrategyVersion.php`, `bin/paper_account.php`, `bin/paper_compare.php`, `src/ScanSnapshot.php`, `config/paper-strategy-files.json`, `config/paper-legacy-versions.json`, `tests/v10.php`, `tests/v12.php`, `tests/version_integration.py`, `.github/workflows/strategy-scope.yml`, `docs/strategy-version-scope.md`, `docs/work-log.md`
- 커밋: 9b2111487c28fcb680fd5bca93c46efdf2f53ab6 (Scope paper strategy versions and unify scan price selection)
- 롤백: 없음

## 2026-09-16 13:00 · Cursor · 끝
- 한 일: 작업 이력 파일과 Cursor 규칙을 만듦
- 파일: `docs/work-log.md`, `.cursor/rules/work-log.mdc`
- 커밋: 안 함
- 롤백: 없음

