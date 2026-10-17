# TOP100 최초 진입 구간 관찰 v2

PR #103의 일별 표본 결과는 유지한다. 조건·손절·목표를 최적화하지 않고, 종목별 최초 TOP100 진입부터 이후 20세션을 한 구간으로 본다.

프로토콜 `protocol.md`, 숫자 `report.md`, Git 밖 `history-research/top100-pre-rise-v2/summary.json`.

```powershell
.\.venv\Scripts\python.exe -m unittest tests.test_top100_pre_rise_episode
.\.venv\Scripts\python.exe docs/research/top100_pre_rise_episode.py --period both --output "C:\Users\acdun\Desktop\dev\stock-personal-paper\history-research\top100-pre-rise-v2" --report docs/top100-pre-rise-v2/report.md
```
