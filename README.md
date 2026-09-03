# 진입 보조

수집한 매매 기록을 데이터화하고, 그 논리로 **관심구간·손절·익절**을 제안하는 개인용 차트 보조.

## 내가 원하는 것

1. **스타일 파악**  
   언제·어느 종목·얼마에 들어가고, 손절·목표가·차트 구조를 어떻게 말하는지 모은다.

2. **데이터화**  
   진입 시각·가격·손절/목표가·차트 구조(쌍봉, 하방 슈팅, 저점 상향, 절반 되돌림 눌림 등)를 `data/entries.json`에 쌓는다.  
   해당 시각 전후 OHLCV로 피처를 뽑아 “말로만 한 구조”인지 검증한다.

3. **진입 보조 프로그램 (PHP)**  
   **진입 후보 가격/구간을 제안**하는 도구를 만든다.  
   처음엔 규칙 기반 점수, 데이터가 쌓이면 학습 데이터로 확장한다.

4. **1번 계좌에만 맞게 변환**  
   레버리지·인버스·단타(SOXS, 코루, 2배 등)는 **직접 따라 사지 않고 본주 방향·구조로 치환**한다.  
   가져올 것은 차트 구조와 눌림·무효화 논리뿐이고, 적용 대상은 스윙·비중 조절이다.

5. **우선 1번계좌고 추후에는 자동화**
   우선 1번계좌로 테스트 할 예정이고, 이 프로젝트가 완료 되면, 내가 원하는 티커나 종목명을 넣을 경우 그 방식대로 차트를 보고 내 계좌를 자유자재로 스위칭 하기 위함임.
   현재 있는 1번계좌의 종목을 유지함이 아님.

6. **최종목표**
   최종목표는 완벽한 프로그램화로 사용자가 원하는 종목명(티커)를 넣을 경우 자동으로 진입가와 손절가, 익절가를 표현해주기위함임.

## 1번 계좌 (적용 대상)

| 구분 | 종목/자산 |
|------|-----------|
| 국장 | SK하이닉스, 삼성전자, 유진테크 |
| 미장 | 마이크론 |
| ISA / 연금 / IRP | S&P500, AI·HBM ETF, 단기채 |

성격: **메모리 집중 중장기**. 레버리지 단타 계좌가 아님.

## 가져올 것 / 버릴 것

| 수집 기록 | 이 프로젝트 / 1번 계좌 |
|--------|------------------------|
| 차트 구조 (슈팅 → 저점 상향 → 절반 눌림) | 사용 |
| 명시 진입가·손절·목표가 | 데이터로 쌓고, 점수로 변환 |
| 레버 / 인버스 / 배수 | 본주 치환 (직접 매매 비권고) |
| 양빵 | 금지 (애매하면 현금) |
| 초단기 손절 단타 | 스윙 저점 이탈 시에만 비중 축소 |
| 애매하면 헤지 | 애매하면 현금·관망 |

자세한 운영 규칙은 `docs/account1-playbook.md`.

## 작업 흐름

```
글 수집 → entries.json → 차트(OHLCV) → 피처 → 점수/진입 제안 → 1번 계좌 종목에만 적용
```

```bash
cd C:\Users\acdun\Desktop\dev\noramu

php bin/scrape_noramu.php --pages=5          # 글 수집·병합
php bin/import_json_posts.php data/raw/...   # 차단 시 브라우저 저장분 수입
php bin/curate_entries.php                   # learning_use·exit_reason 태깅
php bin/fetch_yahoo.php MU 3mo 1d            # 차트
php bin/analyze_entries.php --learning       # 피처 (학습용만, posted_at 이전 봉)
php bin/verify_case_a.php                    # 케이스 A(SNDK) 피처 대조
php bin/seed_spot_full.php                   # 본주 full 시드(MU/삼전/하닉) 병합
php bin/backtest_entries.php                 # full 라벨 N일 백테스트 → docs/backtest-latest.md
php bin/score_symbol.php MU                  # 점수·진입/손절/익절 힌트
php bin/score_symbol.php MU --profile=isa    # 프로필: account1|custom|isa
php bin/score_symbol.php 하이닉스             # 한글 별칭
php bin/score_all.php --profile=custom       # 일괄 점수
php bin/score_history.php --weeks=12         # 임계값 분포 → docs/threshold-review.md
php bin/find_ge70_windows.php --weeks=20     # ≥70 구간 → docs/ge70-label-hunt.md
php bin/prepare_ge70_import.php --limit=8    # 원글 import 템플릿 → data/raw/ge70_import/
php bin/seed_ge70_snapshots.php --limit=12   # 엔진 스냅샷 라벨(원글 아님)
php bin/alert_watch.php --profile=account1   # watch/add 알림(JSON)
# php bin/alert_watch.php --webhook=https://discord.com/api/webhooks/...

php bin/write_weekly_memo.php                # docs/weekly/ 주간 메모
php bin/write_weekly_memo.php --profile=isa  # 프로필별 메모
php bin/list_entries.php full                # 진입 이벤트 (필터 가능)
php bin/ingest_digingonyou.php               # 디깅온유 14 SRL → data/alpha/
php bin/scan_kr_amount.php --limit=30        # 국장 거래대금 TOP 점수 (CLI)

# 로컬 UI (Valet 또는)
php -S 127.0.0.1:8765 -t .
# → http://127.0.0.1:8765/?tab=chart&symbol=MU&profile=account1
# → http://127.0.0.1:8765/?tab=digingonyou
# → http://127.0.0.1:8765/?tab=merged
```

요구: PHP 8.2+, `ext-json`, `ext-curl`.

## 디렉터리

```
bin/                 CLI
src/                 PHP 로직
assets/              UI CSS
index.php            탭 UI (차트 / 기타 / 합침)
data/entries.json    진입 이벤트
data/alpha/          디깅온유 entries·원문 캐시
data/ohlcv/          캔들 캐시
docs/                플레이북·재분석·로드맵
```

## 주의

- 공개 글을 학습 데이터로 쓰는 개인 연구용이다. 타인 프로그램을 복제·판매하지 않는다.
- 글에 나온 타점을 1번 계좌에 숫자 그대로 옮기지 않는다.
- 최종 매매 결정은 본인 책임이다.
