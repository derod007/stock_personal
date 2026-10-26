# 60봉 상승률·거래량 비 3분위 탐색 v3

PR #104의 비중첩 관찰 구간을 유지한 채, 판단일까지 60봉 종가 상승률과 거래량 비가 신규 후보를 구별하는지 보는 탐색 연구다. 3분위 경계는 이전 기간 유효 표본에서 라벨 없이 정하고 최근 기간에 그대로 적용한다. 조합은 상위 1/3 AND 하나뿐이다. 부분 커버 구간에서 추천을 못 찾으면 unknown이다. 이 조합은 현재 거래 모형에서 채택 근거 부족으로 탐색 종료한다.

```powershell
.\.venv\Scripts\python.exe -m unittest tests.test_top100_pre_rise_tertiles
.\.venv\Scripts\python.exe docs/research/top100_pre_rise_tertiles.py --output "C:\Users\acdun\Desktop\dev\stock-personal-paper\history-research\top100-pre-rise-v3" --report docs/top100-pre-rise-v3/report.md
```
