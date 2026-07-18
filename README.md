# chart-entry-lab

노라무식 **진입 시각·가격·차트 구조**를 데이터화하고, PHP로 피처를 뽑아 나만의 진입 보조 프로그램을 만들기 위한 실험실입니다.

## 목표

1. 커뮤니티 진입 공시(시간, 종목, 가격, 손절/목표가)를 `data/entries.json`에 축적
2. 해당 시각 전후 OHLCV를 수집해 구조 피처(매물대, 이평, 저점 상승, 절반 되돌림 등) 계산
3. 규칙 기반 스코어 → 이후 학습 데이터셋으로 확장
4. **1번 계좌용**으로는 레버리지 단타가 아니라 스윙·비중 조절용 시그널로만 사용

## 요구 사항

- PHP 8.2+
- `ext-json`, `ext-curl`

## 빠른 시작

```bash
cd C:\Users\acdun\Desktop\dev\noramu

# 1) 노라무 글 자동 수집 → data/entries.json 병합
php bin/scrape_noramu.php --pages=5
php bin/scrape_noramu.php --pages=3 --dry-run

# 2) 차트 수집·피처·1번 계좌 스코어
php bin/fetch_yahoo.php SOXS 2mo 1d
php bin/fetch_yahoo.php SNDK 2mo 1d
php bin/analyze_entries.php
php bin/score_symbol.php SNDK
```

에펨코리아가 차단(430/보안페이지)하면:

1. 잠시 기다린 뒤 `php bin/scrape_noramu.php --pages=2` 로 재개 (검색 캐시 재사용)
2. 브라우저로 글을 연 뒤 본문/댓글을 `data/raw/browser_posts.json`에 넣고  
   `php bin/import_json_posts.php data/raw/browser_posts.json`
3. 저장한 HTML 폴더는 `php bin/import_html_dir.php data/raw/browser_posts`

## 디렉터리

```
bin/                 CLI
src/                 PHP 클래스
data/entries.json    수기/수집 진입 이벤트
data/ohlcv/          캔들 캐시
docs/                플레이북·재분석
```

## 주의

- 타인의 공개 글을 학습 데이터로 쓰는 실험이며, 그 사람 프로그램을 복제·판매하는 용도가 아닙니다.
- 레버리지 타점을 1번 계좌에 그대로 적용하지 마세요.
