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
