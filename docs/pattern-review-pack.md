# 패턴 검토 패키지

전략은 바꾸지 않았다. 검토자가 원문 대비 구현(A 정확, B 다른 구조, C 누락·부당 차단)을 나눌 자료를 만든다. 수익 거래를 좋은 구현으로 보지 않는다.

## 실행

최근 기간 전체 replay가 저장소 요약 JSON에는 없다. 그래서 기존 CLI로 한 번 다시 만들었다.

```powershell
php -d memory_limit=768M bin/paper_pattern_replay.php --dataset-dir="C:\Users\acdun\Desktop\dev\stock-personal-paper\history-research\kr-saved-scan-20261009" --profile=account1
```

출력: `history-research/replay-kr-saved-scan-20261009/pattern-replay-account1.json` (Git 밖).

이전 기간 전체 replay: `history-research/replay-kr-saved-scan-20251008/pattern-replay-account1.json`.

```powershell
php -d memory_limit=768M bin/paper_review_pack.php build --out="C:\Users\acdun\Desktop\dev\stock-personal-paper\pattern-review-pack"
```

프로필 `account1`, 상위 추세 적용, 비용은 기존과 같이 매수·매도 10bp와 슬리피지 5bp다. 평가 창은 각 데이터셋의 기록된 시작일부터 기준일까지다. 기준일 이후 봉은 판정과 A/B 차트에 넣지 않는다. 결과 차트만 기준일까지 이후 봉을 쓴다.

## 열람

압축을 풀고 `index.html`을 연다. 첫 화면은 판정일까지의 차트 링크만 있다. 종목과 날짜가 보이므로 완전한 블라인드 실험이 아니다.

- `a/{case_id}.html`: 판정일까지 120거래일, 40거래일, 완료 주봉. 패턴 이름·점수·손절·이후 가격 없음.
- `b/{case_id}.html`: 코드가 저장한 조건과, 그 좌표만 그린 차트. 좌표가 없으면 “좌표 미기록”.
- `c/{case_id}.html`: 최종 선택과 미선택 표본의 이후 결과. 신호 없음·품질 차단 표본에는 없다.
- `form.html`: 이후 결과를 열기 전에 적을 칸.

주봉은 `TrendContext`와 같이, 기준일이 금요일이 아니면 그 주를 빼는 완료 주만 그린다. 이동평균은 화면에 보이는 120봉만으로 다시 계산하지 않고, 판정일까지의 완료봉 전체로 계산한 뒤 화면에 맞춘다. OHLC가 깨진 봉은 고치지 않고 `X`와 원문 수치로 남긴다.

## 표본

규칙은 `PaperReviewPack::rule()`과 패키지 `meta/manifest.json`에 있다. 첫 화면은 이 파일을 링크하지 않는다.

- 최종 선택: 두 기간의 세 패턴 전부. 종료가 아닌 상태도 수익률 0으로 넣지 않는다.
- 미선택: 기간·패턴·상태당 최대 10개 구조. `case_id` 순. 수익률로 고르지 않는다. 같은 구조 키의 반복 날짜는 replay가 이미 한 이벤트로 묶는다.
- 신호 없음: 기간별 60일. 월에 나누고, 월 안에서는 `case_id` 순, 종목은 월당 3개까지. 모자라면 그 제한을 푼다.
- 품질 차단: 기간별 최대 20일. 같은 월 규칙.

신호 없음 표본으로 눈에 보이는 패턴이 있는지 미리 판단하지 않는다. 사후 상승으로 고른 표본은 만들지 않았다.

## 검증

```powershell
php -d memory_limit=768M bin/paper_review_pack.php verify --out="C:\Users\acdun\Desktop\dev\stock-personal-paper\pattern-review-pack"
php tests/review_pack.php
```

건수, 해시, 다시 계산한 결과, 비어 있는 항목은 `docs/pattern-review-verify.md`에 있다. 사례 목록은 `docs/pattern-review-manifest.json`이다. 렌더링 예시는 `docs/pattern-review-examples/`다. `example-a-40.svg`와 `example-a-120.svg`는 사례 `00004611c03e8e1a`의 판정일 차트다. `example-b.svg`는 사례 `f0445c951f5182c2`의 코드 주석 차트라 첫 화면이 아니다. 맞다거나 틀리다는 뜻이 아니다.

이 검토에서 전략 코드를 고치지 않는다.
