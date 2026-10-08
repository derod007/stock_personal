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

