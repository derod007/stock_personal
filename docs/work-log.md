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
