# 한국 운영 모의 계좌: 거래대금 TOP100

한국 운영 계좌는 `paper-kr`입니다. 20:20(KST)에 하루 한 번 다음 명령을 예약합니다.

```sh
python bin/paper_daily.py --config=config/paper-kr.json
```

Windows의 기존 실행 파일을 쓰면 `bin/run_paper_daily.cmd --kr`을 예약합니다. 이 파일의 한국 분기는 위 daily 명령을 직접 호출합니다. 예약 작업의 반복 실행은 끄고, 이미 실행 중이면 새 인스턴스를 시작하지 않도록 설정하세요. 시간 검사 범위는 기존처럼 평일 20:20~23:59이며 놓친 작업 재실행을 위한 범위입니다. 사용자 PC의 작업 스케줄러 설정은 저장소 변경으로 자동 수정되지 않습니다.

## 설정과 처리

`config/paper-kr.json`은 다음을 유지합니다.

```json
{"universe": "kr_amount_scan", "scan_limit": 100, "symbols": {}}
```

1. `bin/paper_scan_universe.php`가 거래대금 TOP100을 한 번 스캔합니다.
2. `src/PaperScanUniverse.php`가 `entry_recommend`(확인된 `order_ready`) 또는 `buy_now`인 종목을 신규 후보로 고릅니다. 점수가 높거나 관심 가격만 있는 종목은 추천으로 추가하지 않습니다.
3. 원장의 기존 보유·대기 주문은 오늘 스캔에 없어도 수집·평가 목록에 남깁니다.
4. 당일 목록은 임시 `runtime-symbols.json`으로 계좌 엔진에 전달합니다. `symbols`를 config에 쓰지 않으므로 일별 종목 변경이 설정 해시를 바꾸지 않습니다.
5. 추천·보유·대기 주문이 모두 없으면 `empty_universe: true`로 정상 종료합니다. 과거 3종목이나 고정 10종목으로 대체하지 않습니다.

신규 후보도 기존 계좌 엔진의 확인·품질·위험 한도를 통과해야 주문됩니다. 가격·손절·목표·한도·수수료 및 기존 보유 처리 규칙을 변경하지 않습니다.

`research-kr-v1`, `paper_research_daily.py`, 고정 연구 설정을 쓰는 `paper_entry_daily.py`는 별도 연구용입니다. 한국 운영 예약을 이들로 교체하거나 후보가 없을 때 대체 경로로 사용하지 않습니다. 미국 계좌와 연구 실험은 이 변경의 대상이 아닙니다.

## 점검

```sh
php tests/v14.php
python -m unittest discover -s tests -p test_paper_daily.py
php bin/paper_scan_universe.php --config=config/paper-kr.json --cache
```

마지막 명령은 후보 목록을 출력하며 계좌를 매매·갱신하지 않습니다. `--cache`도 유효 캐시가 없으면 외부 수집할 수 있습니다. `symbols`는 추천과 기존 보유·대기 주문의 합집합이며, 결과가 우연히 3개/10개일 수 있으므로 개수 자체가 아니라 `candidates`·`held`와 실제 종목 목록을 확인합니다.

모의 계좌 화면 `paper.php?account=paper-kr`, 진단 `paper_diagnostics.php?account=paper-kr`, 실행 기록 `runs/paper-kr-forward`를 확인합니다. 스캔 실패와 추천 0건 정상 종료는 구분합니다. 한국 identity 비교가 필요하면 운영 갱신 성공 뒤 기존 `paper_compare.php`로 저장된 원본을 읽으며, 비교용 추가 스캔은 하지 않습니다.
