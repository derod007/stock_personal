# TOP100 판단일 차트 공통점 후보 v1

기존 세 패턴 추천에 잡히지 않은 TOP100 관찰에서, 판단일까지 완료봉만으로 보이는 단순 차트 특징이 이후 상승과 같이 나타나는지 보는 탐색 연구다. 운영 경로·계좌·원본을 바꾸지 않는다.

프로토콜은 `protocol.md`, 숫자는 `report.md`와 Git 밖 `history-research/top100-pre-rise-v1/summary.json`이다.

```powershell
.\.venv\Scripts\python.exe -m unittest tests.test_top100_pre_rise
.\.venv\Scripts\python.exe docs/research/top100_pre_rise.py --period both --output "C:\Users\acdun\Desktop\dev\stock-personal-paper\history-research\top100-pre-rise-v1" --report docs/top100-pre-rise-v1/report.md
```
