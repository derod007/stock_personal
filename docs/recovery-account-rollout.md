# 회복형 포함 한국 모의계좌 적용

새 계좌 ID: `paper-kr-recovery-v1`. 설정: `config/paper-kr-recovery-v1.json`.
초기 모의자금 1억원, 최대 4종목과 기존 위험 한도 유지. 실제 증권계좌나 주문과 무관하다.

기존 `bin/run_paper_daily.cmd --kr`의 20:20 시간창은 유지하고 설정 파일만 새 계좌로 연결한다. Windows 작업이 이 실행 파일을 호출한다면 PR 적용 후 다음 실행부터 새 계좌를 사용한다. 사용자의 Windows 작업 자체는 원격으로 변경하거나 확인하지 않았다. 다른 명령을 직접 등록한 작업은 해당 설정 경로도 바꿔야 한다.

먼저 원본을 쓰지 않는 사전 검사를 실행할 수 있다.

```powershell
php bin/paper_account_preflight.php --config=config/paper-kr-recovery-v1.json
```

`new_account`는 아직 장부가 없다는 뜻이며 오류가 아니다. `compatible`은 기존 새 계좌의 버전/설정/중단 상태 검사 통과다. 실제 계좌 처리는 데이터 수집 후 다시 검사한다. 추천·보유 대상이 없으면 장부 생성과 계좌 처리는 생략되지만 사전 검사 결과는 실행 로그에 남는다.

바로 스캔·새 기록·후속 연구까지 실행하려면 다음 명령을 사용한다(실행 시점 완료봉 기준이며 과거 20:20 재현이 아님).

```powershell
python bin/paper_daily.py --config=config/paper-kr-recovery-v1.json
```

화면 계좌 선택에서 **한국 모의 · 회복형 포함**을 선택한다. `/paper_diagnostics.php?account=paper-kr-recovery-v1` 및 `/paper_rr.php?account=paper-kr-recovery-v1`에서 새 실행과 감사 기록을 확인한다. 장부는 첫 평가 대상이 생긴 실행에서 생성된다.

기존 `paper-kr`는 **한국 모의 · 이전 기록**으로 계속 조회한다. 기존 잔고·보유·주문·감사 로그·연구 결과는 복사/삭제/재평가하지 않는다. 새 계좌는 기존 보유를 승계하지 않으며 새 시작일의 성과를 별도로 기록한다. 이전 계좌의 자동 예약 실행은 중단되므로 이전 계좌 기록을 현재 운용 결과로 해석하지 않는다. 기존 개별 추천의 관찰 후속 갱신이 필요하면 `php bin/paper_followup.php --account=paper-kr`를 별도로 실행한다. 버전이 다른 고정 연구 거래는 기존 실행 지문 보호를 유지한다.

탈락 표는 종목 최종 판정을 한 번만 표시하고, 각 행에는 패턴 자체 상태와 그 패턴의 근거만 표시한다. 연구 포함 표시는 운영 추천과 구분한다. 계산·원본 감사 파일은 바꾸지 않는다.
