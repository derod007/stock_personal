# 상승 전 60봉 흐름 6유형 v4

이전 기간의 전체 유효 60봉 가격·거래량 경로를 라벨 없이 6유형으로 묶고, 같은 중심을 최근 기간에 배정한다. 상승 라벨은 묶음이 끝난 뒤에만 연다. K·가중치·손절은 바꾸지 않는다.

```powershell
.\.venv\Scripts\python.exe -m unittest tests.test_top100_pre_rise_shapes
.\.venv\Scripts\python.exe docs/research/top100_pre_rise_shapes.py --output "C:\Users\acdun\Desktop\dev\stock-personal-paper\history-research\top100-pre-rise-v4" --report docs/top100-pre-rise-v4/report.md
```
