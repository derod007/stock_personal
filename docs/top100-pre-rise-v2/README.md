# 비중첩 TOP100 관찰 구간 v2

PR #103의 일별 표본 결과는 유지한다. 조건·손절·목표를 최적화하지 않고, 종목별 비중첩 TOP100 관찰 구간(T부터 이후 20세션)을 본다. 인덱스 ≥20을 최초 진입 확인으로 쓰지 않는다. 초안 숫자는 `report-before-rev1.md`에 있다.

프로토콜 `protocol.md`, 숫자 `report.md`, Git 밖 `history-research/top100-pre-rise-v2/summary.json`.

```powershell
.\.venv\Scripts\python.exe -m unittest tests.test_top100_pre_rise_episode
.\.venv\Scripts\python.exe docs/research/top100_pre_rise_episode.py --period both --output "C:\Users\acdun\Desktop\dev\stock-personal-paper\history-research\top100-pre-rise-v2" --report docs/top100-pre-rise-v2/report.md
```
