# KOICA 기술지원(전산) 직무수행능력평가 — 최종 암기노트

> 범위: 자료구조론 · 데이터베이스론 · 소프트웨어공학 · 운영체제론  
> 사용법: 각 과목 **암기 필수** → 표/비교 → 헷갈리는 한 줄만 다시 보기

---

## 0. 시험 전날 체크리스트 (30분)

| 과목 | 무조건 외울 것 |
|------|----------------|
| 자료구조 | Stack=LIFO / Queue=FIFO / BST 중위=오름차순 / DFS=Stack / BFS=Queue / 정렬 복잡도·Stable / 해시 O(1) |
| DB | ACID / 정규화 1~BCNF / 키 종류 / 이상 3종 / 격리수준 Dirty·Phantom / SQL 실행순서 / JOIN |
| OS | 프로세스 vs 스레드 / Deadlock 4조건 / Banker=회피 / Paging=내부 / Segmentation=외부 / LRU·Belady / RR Time Quantum |
| SE | SDLC / Waterfall vs Agile / SOLID / 결합↓ 응집↑ / Black vs White / 유지보수 4종 / UML 자주 나오는 5개 |

**비교 함정 (자주 틀림)**  
- SJF 기아 ↔ HRN/Aging으로 보완  
- Mutex(1개) ↔ Semaphore(여러 개, P/V)  
- Dirty Read = Read Uncommitted / Phantom = Repeatable Read까지 가능  
- B-Tree(모든 노드 데이터) ↔ B+Tree(Leaf만 데이터, 범위검색)  
- 결합도 **낮을수록** / 응집도 **높을수록**

---

# 1. 자료구조론

## 1.1 구조 분류

| 구분 | 의미 | 예 |
|------|------|----|
| 선형(Linear) | 한 줄로 연결 | 배열, 연결리스트, 스택, 큐, 덱 |
| 비선형(Non-Linear) | 계층·네트워크 | 트리, 그래프 |

## 1.2 배열 vs 연결리스트

| | 배열(Array) | 연결리스트(Linked List) |
|--|-------------|-------------------------|
| 저장 | 연속 메모리 | 비연속 + 포인터 |
| 접근 | 인덱스 **O(1)** | 순차 탐색 **O(n)** |
| 중간 삽입/삭제 | **O(n)** (밀기) | 위치만 알면 **O(1)** |
| 특징 | 캐시 친화적 | 크기 유연 |

- 단일 연결리스트: next만  
- 이중 연결리스트: prev + next  
- 원형 연결리스트: 마지막이 처음을 가리킴

## 1.3 스택 · 큐 · 덱

| 구조 | 규칙 | 연산 | 사용 예 |
|------|------|------|---------|
| **Stack** | **LIFO** | push / pop / peek | 함수 호출(Call Stack), DFS, 괄호 검사, 브라우저 뒤로가기, 되돌리기(Undo) |
| **Queue** | **FIFO** | enqueue / dequeue | 프린터, 대기열, **BFS**, CPU 준비 큐 |
| **Deque** | 양끝 삽입·삭제 | 앞/뒤 push·pop | 슬라이딩 윈도우, 양방향 대기 |
| **원형 큐** | 배열 끝↔처음 연결 | front/rear 모듈로 | 배열 큐의 공간 낭비 해결 |

스택·큐 기본 연산: 평균 **O(1)**

**우선순위 큐(Priority Queue)**  
- 우선순위 높은 것 먼저 / 보통 **힙(Heap)**으로 구현 / 삽입·삭제 **O(log n)**

## 1.4 트리

### 용어
- **Root** 루트 / **Parent·Child** / **Leaf**(단말, 자식 없음)  
- **Depth**: 루트→해당 노드 거리  
- **Height**: 트리(또는 노드)의 높이  
- **차수(Degree)**: 자식 수  
- **레벨**: 루트를 1(또는 0)로 두고 아래로 증가

### 이진 트리 종류
- **완전 이진 트리**: 마지막 레벨 제외 꽉 차고, 마지막은 왼쪽부터  
- **포화 이진 트리**: 모든 레벨이 가득  
- **이진 힙**: 완전 이진 트리 + 힙 성질 (최대/최소)

### BST (Binary Search Tree)
- 규칙: **왼쪽 < 부모 < 오른쪽**  
- 평균 탐색 **O(log n)** / 편향 시 **O(n)**  
- 균형 트리: **AVL**, **Red-Black**, **B-Tree / B+Tree**

### B-Tree vs B+Tree (DB 인덱스 단골)

| | B-Tree | B+Tree |
|--|--------|--------|
| 데이터 위치 | 모든 노드에 가능 | **Leaf에만** |
| Leaf 연결 | 보통 없음 | Leaf끼리 연결 |
| 강점 | 일반 탐색 | **범위 검색**에 유리 |

### 순회 (외우기: Root 위치)

| 순회 | 순서 | 팁 |
|------|------|-----|
| 전위 Preorder | **Root** → L → R | 복사·전위표기 |
| 중위 Inorder | L → **Root** → R | **BST 중위 = 오름차순** |
| 후위 Postorder | L → R → **Root** | 삭제·후위표기 |
| 레벨순회 | 위→아래, 좌→우 | **Queue** 사용 |

## 1.5 그래프

| 용어 | 의미 |
|------|------|
| 정점(Vertex) | 노드 |
| 간선(Edge) | 연결선 |
| 차수 | 연결된 간선 수 |
| 방향/무방향 | 화살표 유무 |
| 가중치 | 간선 비용 |

**표현**: 인접 행렬 O(1) 간선확인, 공간 O(V²) / 인접 리스트 희소 그래프에 유리

### 탐색
| | DFS | BFS |
|--|-----|-----|
| 구조 | **Stack** 또는 재귀 | **Queue** |
| 특징 | 깊이 우선 | 너비 우선, 최단경로(비가중) |

### 최단경로 · MST
| 알고리즘 | 용도 | 핵심 |
|----------|------|------|
| Dijkstra | 단일 출발 최단 (음수X) | 탐욕 + 우선순위큐 |
| Bellman-Ford | 음수 간선 가능 | 완화 V-1회 |
| Floyd-Warshall | 모든 쌍 최단 | DP, O(V³) |
| **Kruskal** | MST | **간선** 중심, Union-Find, 탐욕 |
| **Prim** | MST | **정점** 중심, 우선순위큐 |

## 1.6 해시(Hash)
- Key → Hash Function → 버킷 저장  
- 평균 검색 **O(1)** / 최악(충돌 심하면) O(n)  
- **충돌 해결**  
  - **Chaining**: 같은 슬롯에 리스트  
  - **Open Addressing**: 다른 빈 슬롯 탐색 (선형조사, 이차조사, 이중해싱)

## 1.7 정렬 (Stable·복잡도 필수)

| 정렬 | 평균 | 최악 | 최선 | Stable | 비고 |
|------|------|------|------|--------|------|
| Bubble | O(n²) | O(n²) | O(n) | **O** | 인접 교환 |
| Selection | O(n²) | O(n²) | O(n²) | **X** | 최소(최대) 선택 |
| Insertion | O(n²) | O(n²) | O(n) | **O** | 거의 정렬에 강함 |
| Quick | O(n log n) | O(n²) | O(n log n) | **X** | 피벗, 평균 빠름 |
| Merge | O(n log n) | O(n log n) | O(n log n) | **O** | 추가 메모리 |
| Heap | O(n log n) | O(n log n) | O(n log n) | **X** | 힙 이용 |

**Stable**: 같은 키의 상대 순서 유지 (Bubble, Insertion, Merge)

## 1.8 탐색 · Big-O

**이진 탐색(Binary Search)**  
- 조건: **정렬 필수**  
- 시간: **O(log n)**

| 표기 | 의미 | 예 |
|------|------|-----|
| O(1) | 상수 | 배열 인덱스, 해시 평균 |
| O(log n) | 로그 | 이진 탐색, 균형트리 |
| O(n) | 선형 | 순차 탐색 |
| O(n log n) | 로그선형 | 좋은 정렬 |
| O(n²) | 제곱 | 버블·선택 |
| O(2ⁿ) | 지수 | 부분집합 전수 |

빠름 → 느림: **O(1) < O(log n) < O(n) < O(n log n) < O(n²) < O(2ⁿ)**

## 1.9 자료구조 암기 필수

1. Stack=LIFO / Queue=FIFO  
2. 배열 접근 O(1), 중간삽입 O(n)  
3. 리스트 탐색 O(n), 삽입·삭제(위치 알 때) 빠름  
4. BST: 왼<부모<오 / 중위=정렬  
5. DFS=Stack, BFS=Queue  
6. B+Tree = Leaf만 데이터 + 범위검색  
7. 해시 평균 O(1), 충돌=Chaining/Open Addressing  
8. Quick 최악 O(n²), Merge는 항상 O(n log n)+Stable  
9. Kruskal=간선, Prim=정점  
10. 이진 탐색 = 정렬 필수, O(log n)

---

# 2. 데이터베이스론

## 2.1 DB·스키마 기본
- **DBMS**: DB를 관리하는 소프트웨어  
- **3층 스키마**: 외부(사용자 뷰) / 개념(논리 전체) / 내부(물리 저장)  
- **데이터 독립성**: 하위 스키마 변경이 상위에 영향 최소화  
  - 논리 독립성: 개념↔외부  
  - 물리 독립성: 내부↔개념

## 2.2 ER 모델 (자주 출제)
- **개체(Entity)** / **속성(Attribute)** / **관계(Relationship)**  
- 카디널리티: 1:1, 1:N, N:M  
- 기본키·외래키로 관계 표현

## 2.3 키(Key)

| 키 | 의미 |
|----|------|
| Super Key | 유일성만 (최소 아닐 수 있음) |
| Candidate Key | 유일+최소인 후보 |
| **Primary Key** | 후보키 중 선택된 기본키 (NULL·중복 불가) |
| Alternate Key | 후보키 중 PK 아닌 것 |
| **Foreign Key** | 다른 테이블 PK 참조 |

## 2.4 무결성
- **개체 무결성**: PK ≠ NULL, 중복 불가  
- **참조 무결성**: FK는 존재하는 PK 값 **또는 NULL**  
- 도메인 무결성: 속성이 허용 범위 값만  
- 사용자 정의 무결성: CHECK, 트리거 등

## 2.5 함수 종속 · 정규화

**A → B**: A 결정되면 B 결정

| 단계 | 내용 |
|------|------|
| **1NF** | 원자값만 (반복 그룹 제거) |
| **2NF** | 1NF + **부분 함수 종속** 제거 (완전 함수 종속) |
| **3NF** | 2NF + **이행 함수 종속** 제거 (A→B→C에서 A→C) |
| **BCNF** | 모든 **결정자가 후보키** |
| 4NF | 다치 종속 제거 |
| 5NF | 조인 종속 제거 |

실무·시험 핵심 라인: **1NF → 2NF → 3NF → BCNF**

**이상(Anomaly)** — 정규화로 완화  
- 삽입 이상 / 삭제 이상 / 갱신 이상  
**반정규화(Denormalization)**: 성능을 위해 의도적 중복

## 2.6 관계 대수 (개념)
- 선택 σ (WHERE) / 프로젝션 π (컬럼) / 조인 ⋈ / 합집합 ∪ / 차집합 − / 카티션곱 ×

## 2.7 SQL 분류

| 분류 | 역할 | 예 |
|------|------|-----|
| **DDL** | 구조 정의 | CREATE, ALTER, DROP, TRUNCATE |
| **DML** | 데이터 조작 | SELECT, INSERT, UPDATE, DELETE |
| **DCL** | 권한 | GRANT, REVOKE |
| **TCL** | 트랜잭션 | COMMIT, ROLLBACK, SAVEPOINT |

> SELECT를 DML로 보는 분류가 시험에 흔함 (교재마다 약간 차이 있음 → **조작·조회 = DML**로 외우기)

### SQL 논리적 실행 순서 (암기)
**FROM → WHERE → GROUP BY → HAVING → SELECT → ORDER BY**  
(JOIN은 FROM 단계에서 처리)

### JOIN
| JOIN | 결과 |
|------|------|
| INNER | 양쪽에 있는 공통만 |
| LEFT | 왼쪽 전부 + 매칭 오른쪽 |
| RIGHT | 오른쪽 전부 + 매칭 왼쪽 |
| FULL | 양쪽 전부 |

## 2.8 트랜잭션 · ACID

| | 의미 | 한 줄 |
|--|------|--------|
| **A**tomicity | 원자성 | All or Nothing |
| **C**onsistency | 일관성 | 제약·규칙 유지 |
| **I**solation | 격리성 | 동시 실행해도 독립처럼 |
| **D**urability | 지속성 | 커밋 후 장애에도 유지 |

### 동시성 문제
| 문제 | 의미 |
|------|------|
| Dirty Read | 커밋 안 된 값 읽음 |
| Non-Repeatable Read | 같은 행을 다시 읽었더니 값이 바뀜 |
| Phantom Read | 다시 읽었더니 행이 추가/사라짐 |

### 격리 수준
| 수준 | Dirty | Non-Rep | Phantom | 비고 |
|------|-------|---------|---------|------|
| Read Uncommitted | O | O | O | 가장 느슨 |
| Read Committed | X | O | O | 많이 씀 |
| Repeatable Read | X | X | O | InnoDB 기본(근접) |
| Serializable | X | X | X | 가장 안전·느림 |

### 잠금
- Shared Lock(공유, 읽기) / Exclusive Lock(배타, 쓰기)  
- **2PL**(Two-Phase Locking): 확장 후 축소, 직렬 가능성 보장에 사용

## 2.9 Index · View · 기타 객체
- **Index**: 조회↑ / INSERT·UPDATE·DELETE↓, 저장공간↑  
  - 클러스터드: 데이터 물리적 정렬(테이블당 보통 1)  
  - 넌클러스터드: 별도 구조가 데이터 가리킴  
- **View**: 가상 테이블, 보안·독립성  
- **Stored Procedure**: 저장 프로시저(로직)  
- **Trigger**: 이벤트 시 자동 실행  
- **Transaction Log / Checkpoint**: 회복(Recovery)에 사용

## 2.10 DB 암기 필수

1. PK: NULL·중복 불가 / FK: 존재 PK 또는 NULL  
2. 1NF 원자값 → 2NF 부분종속 제거 → 3NF 이행종속 제거 → BCNF 결정자=후보키  
3. 이상 3종: 삽입·삭제·갱신  
4. ACID  
5. Dirty=Uncommitted / Phantom=RR까지 가능  
6. SQL 순서: FROM→WHERE→GROUP BY→HAVING→SELECT→ORDER BY  
7. INNER=교집합, LEFT=왼쪽 전부  
8. Index=읽기↑ 쓰기↓  
9. View=가상 테이블  
10. DDL/DML/DCL/TCL 구분

---

# 3. 운영체제론

## 3.1 OS 역할
하드웨어·소프트웨어 관리, 자원 할당, 사용자↔컴퓨터 중개  
예: Windows, Linux, macOS, Android, iOS

**이중 모드**: User Mode / Kernel Mode  
**System Call**: 사용자 프로그램이 커널 서비스 요청

## 3.2 프로그램 vs 프로세스 vs 스레드

| | Program | Process | Thread |
|--|---------|---------|--------|
| 정의 | 디스크上 실행파일 | **실행 중** 프로그램 | 프로세스 안 실행 단위 |
| 메모리 | - | 독립 주소공간 | Code·Data·Heap **공유**, **Stack만 독립** |
| 비용 | - | 생성·문맥교환 큼 | 상대적으로 작음 |

## 3.3 프로세스 메모리 구조 (위→아래 자주 그림)

```
높은 주소
  Stack   ← 지역변수, 매개변수, 리턴주소 (LIFO, ↓성장)
  ↓↑
  Heap    ← malloc/new 동적할당 (↑성장)
  Data    ← 전역·정적 변수
  Text    ← 코드(읽기전용)
낮은 주소
```

## 3.4 프로세스 상태
**New → Ready ⇄ Running → Waiting → Ready → … → Terminated**

| 상태 | 의미 |
|------|------|
| Ready | CPU 대기 (준비 큐) |
| Running | CPU 실행 중 |
| Waiting(Blocked) | I/O 등 사건 대기 |

**PCB**: PID, 상태, PC, 레지스터, 메모리정보, 우선순위, 스케줄링 정보 등  
**Context Switching**: PCB 저장·복원, 잦을수록 오버헤드↑

## 3.5 CPU 스케줄링

### 비선점 (실행 중 강제 회수 X)
| 알고리즘 | 요지 | 특징 |
|----------|------|------|
| **FCFS** | 도착 순 | Convoy Effect |
| **SJF** | 짧은 작업 먼저 | 평균대기 최소, **Starvation** |
| **HRN** | (대기+서비스)/서비스 | SJF 기아 보완 |

### 선점 (강제 회수 O)
| 알고리즘 | 요지 | 특징 |
|----------|------|------|
| **RR** | Time Quantum | 크면≈FCFS, 작으면 CS↑ |
| **SRT**(SRTF) | 남은 시간 최단 | 선점형 SJF |
| Priority | 우선순위 | 기아→**Aging** |
| MLFQ | 다단계 피드백 큐 | 대화형에 유리 |

## 3.6 교착상태(Deadlock)

**발생 필요조건 4가지 (모두 만족 시)**  
1. **Mutual Exclusion** 상호배제  
2. **Hold and Wait** 점유와 대기  
3. **No Preemption** 비선점  
4. **Circular Wait** 순환 대기  

| 전략 | 내용 |
|------|------|
| 예방 Prevention | 조건 중 하나 제거 |
| 회피 Avoidance | 안전상태만 허용 → **Banker's Algorithm** |
| 탐지·회복 | 발생 후 프로세스 종료·자원 회수 |

**Starvation**: 낮은 우선순위가 계속 못 씀 → **Aging**으로 해결

## 3.7 동기화
- **Critical Section**: 공유자원 접근 구간  
- **Race Condition**: 실행 순서에 따라 결과 달라짐  
- **Mutex**: 잠금, 한 번에 하나  
- **Semaphore**: 정수 카운터, **P(wait)·V(signal)**, 여러 개 제어 가능  
- **Monitor**: 언어 수준 동기화 (Java synchronized 등)

## 3.8 메모리 관리

| | Paging | Segmentation |
|--|--------|---------------|
| 단위 | 고정 크기 **Page** | 논리 단위(코드·데이터) |
| 단편화 | **내부** 단편화 | **외부** 단편화 |
| 장점 | 외부 단편화 제거 | 사용자 관점 자연스러움 |

- **단편화**: 내부=할당 내부 낭비 / 외부=총량은 충분한데 연속공간 없음  
- **가상 메모리**: RAM보다 큰 주소공간, 디스크(스왑) 활용  
- **Demand Paging**: 필요 페이지만 적재  
- **TLB**: 페이지 테이블 캐시, 주소변환 가속

### 페이지 교체
| 알고리즘 | 규칙 | 비고 |
|----------|------|------|
| FIFO | 가장 오래된 것 | **Belady 모순** 가능 |
| **LRU** | 가장 오래 안 쓴 것 | 실무·시험 단골 |
| LFU | 참조 횟수 최소 | |
| OPT/MIN | 가장 늦게 쓸 것 | 이론 최적, 구현 어려움 |

**Thrashing**: 페이지 폴트 폭주 → CPU 이용률↓  
해결: 메모리 증설, 멀티프로그래밍 수준↓, Working Set

## 3.9 Locality · Cache
- **Temporal**: 최근 사용 → 곧 재사용  
- **Spatial**: 근처 주소도 곧 사용  
- Cache: CPU↔RAM 사이 고속 버퍼

## 3.10 디스크 스케줄링 (출제 종종)
| 알고리즘 | 요지 |
|----------|------|
| FCFS | 요청 순 |
| SSTF | 가장 가까운 실린더 |
| SCAN(엘리베이터) | 한쪽 끝→왕복 |
| C-SCAN | 한쪽만 서비스 후 처음으로 |

## 3.11 인터럽트
- 하드웨어: 키보드, 디스크 I/O  
- 소프트웨어: System Call, Exception

## 3.12 OS 암기 필수 20

1. Process = 실행 중 프로그램  
2. Thread = Stack만 독립  
3. Context Switch = 오버헤드  
4. PCB = 프로세스 정보  
5. FCFS = Convoy  
6. SJF = 기아 가능  
7. RR = Time Quantum  
8. SRT = 남은 시간 최단(선점)  
9. Deadlock 4조건  
10. Banker = 회피  
11. Mutex vs Semaphore  
12. Paging = 내부 단편화  
13. Segmentation = 외부 단편화  
14. Virtual Memory = 디스크를 메모리처럼  
15. FIFO → Belady 가능  
16. LRU = 오래 안 쓴 페이지  
17. Thrashing = 교체 과다  
18. Temporal / Spatial Locality  
19. Aging = 기아 해결  
20. System Call = 커널 서비스 요청

---

# 4. 소프트웨어공학

## 4.1 목적
생산성↑ · 품질↑ · 유지보수 용이 · 비용↓

## 4.2 SDLC
**요구사항 분석 → 설계 → 구현 → 테스트 → 유지보수**  
(+ 타당성 조사, 배포 등이 앞에/뒤에 붙는 변형 가능)

## 4.3 개발 모델

| 모델 | 핵심 | 장점 | 단점 |
|------|------|------|------|
| **Waterfall** | 순차, 문서 중심 | 관리 쉬움 | 변경 취약 |
| **Prototype** | 빠른 프로토타입 | 요구 명확화 | 폐기 비용 |
| **Spiral** | **위험 분석** 반복 | 고위험·대규모 | 복잡·비용 |
| **Agile** | 반복·협업·피드백 | 변화 대응 | 문서·예측 약할 수 있음 |
| RAD | 짧은 기간 조립 | 빠름 | 대규모·고위험엔 부적합 |

### Scrum
- 역할: **Product Owner**, **Scrum Master**, **Dev Team**  
- **Sprint** / **Daily Scrum** / **Product Backlog** / **Sprint Backlog**  
- Sprint Review, Retrospective도 자주 언급

### XP 핵심
가치: 의사소통·단순성·피드백·용기·존중  
기법: Pair Programming, **TDD**, Refactoring, CI

## 4.4 요구사항
- **기능**: 무엇을 하는가 (로그인, 결제…)  
- **비기능**: 어떻게 (성능, 보안, 가용성, 응답시간…)

**Verification vs Validation**  
- Verification: 올바르게 만들었는가 (명세 부합)  
- Validation: 올바른 것을 만들었는가 (사용자 필요)

## 4.5 UML 자주 나오는 것
| 다이어그램 | 표현 |
|------------|------|
| Use Case | 사용자↔기능 |
| Class | 클래스·관계 |
| Sequence | 시간순 메시지 |
| Activity | 작업 흐름 |
| State | 상태 전이 |
| Component / Deployment | 컴포넌트·배치 |

구조형: Class, Object, Component, Deployment, Package  
행위형: Use Case, Sequence, Activity, State, Communication 등

## 4.6 OOP 4대 특징
1. **캡슐화** — 묶음 + 정보은닉  
2. **상속** — 재사용  
3. **다형성** — 같은 인터페이스, 다른 동작  
4. **추상화** — 핵심만 표현

## 4.7 SOLID
| | 원칙 | 한 줄 |
|--|------|--------|
| S | Single Responsibility | 클래스 책임 하나 |
| O | Open-Closed | 확장 Open / 수정 Closed |
| L | Liskov Substitution | 자식이 부모 대체 가능 |
| I | Interface Segregation | 안 쓰는 인터페이스 강제 X |
| D | Dependency Inversion | 구현이 아닌 추상화 의존 |

## 4.8 결합도 · 응집도 (순서 암기)

**결합도 (낮을수록 좋음)** — 좋은 쪽 → 나쁜 쪽  
**자료 → 스탬프 → 제어 → 외부 → 공통 → 내용**  
- 자료 결합 최선 / **내용 결합 최악**

**응집도 (높을수록 좋음)** — 좋은 쪽 → 나쁜 쪽  
**기능 → 순차 → 통신 → 절차 → 시간 → 논리 → 우연**  
- 기능 응집 최선 / **우연 응집 최악**

## 4.9 GoF 디자인 패턴 (출제 빈도 높은 것)
| 패턴 | 한 줄 |
|------|--------|
| Singleton | 인스턴스 하나 |
| Factory Method | 생성 위임 |
| Adapter | 인터페이스 맞춤 |
| Decorator | 기능 동적 추가 |
| Proxy | 대리 객체(접근·캐시) |
| Observer | 상태변화 알림 |
| Strategy | 알고리즘 교체 |

분류: 생성(Singleton, Factory…) / 구조(Adapter, Decorator, Proxy…) / 행위(Observer, Strategy…)

## 4.10 테스트
| 단계 | 대상 |
|------|------|
| Unit | 함수·메서드·클래스 |
| Integration | 모듈 연동 |
| System | 시스템 전체 |
| Acceptance | 사용자 최종(알파/베타) |

| | Black Box | White Box |
|--|-----------|-----------|
| 관점 | 기능·입출력 | 코드 구조 |
| 기법 | 동등분할, 경계값, 상태전이, 원인결과 | 문장·분기·조건 커버리지, 기본경로 |

## 4.11 유지보수 4종
| 종류 | 내용 |
|------|------|
| Corrective | 오류 수정 |
| Adaptive | 환경 변화 대응 |
| Perfective | 성능·기능 개선 |
| Preventive | 미래 오류 예방 |

## 4.12 품질 · 성숙도 · CASE
- **ISO/IEC 25010** 주요 특성: 기능 적합성, 성능 효율성, 호환성, 사용성, 신뢰성, 보안성, 유지보수성, 이식성  
  (구 ISO 9126: 기능성·신뢰성·사용성·효율성·유지보수성·이식성)  
- **CMMI** 1 Initial → 2 Managed → 3 Defined → 4 Quantitatively Managed → 5 Optimizing  
- **CASE**: 개발 자동화 도구  
- 규모 추정: LOC, **FP(Function Point)** / 비용: COCOMO 언급 정도

## 4.13 SE 암기 필수 · TOP 출제
1. SDLC 단계  
2. Waterfall vs Agile / Spiral=위험  
3. Scrum 역할·Sprint·Backlog  
4. 기능 vs 비기능 요구  
5. UML: UseCase·Class·Sequence·Activity·State  
6. OOP 4특징  
7. SOLID  
8. 결합↓ 응집↑ + 순서  
9. Singleton / Observer / Strategy  
10. Unit→Integration→System→Acceptance  
11. Black vs White  
12. 유지보수 4종  
13. CMMI 5단계  
14. V&V (Verification / Validation)

---

# 5. 과목 가로지르기 한 장 요약

```
[자료구조]  Stack↔LIFO   Queue↔FIFO   DFS↔Stack   BFS↔Queue
            BST 중위순회 = 정렬   해시≈O(1)   B+Tree=범위검색

[DB]        ACID   정규화(1→2→3→BCNF)   이상3종
            FROM→WHERE→GROUP BY→HAVING→SELECT→ORDER BY
            Dirty⊂Uncommitted   Phantom⊂RR

[OS]        Thread=Stack만 독립
            Deadlock=상호배제+점유대기+비선점+순환대기
            Banker=회피   Paging=내부   Seg=외부
            FIFO→Belady   LRU=실무형   Thrashing=폴트폭주

[SE]        결합↓응집↑   SOLID   Waterfall순차 / Agile반복
            Black=기능 / White=코드   유지보수 CAPP(교정·적응·완숙·예방)
```

---

# 6. 빠른 OX · 함정 정리

1. BST를 **후위**순회하면 항상 정렬된다? → **X** (중위가 O)  
2. QuickSort는 항상 O(n log n)? → **X** (최악 n²)  
3. Semaphore는 항상 한 프로세스만? → **X** (Mutex가 그 역할에 가깝)  
4. Paging은 외부 단편화? → **X** (**내부**)  
5. Banker는 Deadlock **예방**? → 시험에선 보통 **회피(Avoidance)**  
6. Repeatable Read에서 Dirty Read? → **X** (불가) / Phantom은 가능  
7. View는 항상 물리 데이터 복사본? → **X** (가상; 구체화 뷰는 예외)  
8. 응집도는 낮을수록 좋다? → **X** (**높을수록**)  
9. SJF는 기아 없음? → **X** (긴 작업 기아 가능)  
10. B-Tree는 Leaf에만 데이터? → **X** (**B+Tree**)

---

끝. 시험장에서는 **정의 한 줄 + 대표 예 하나 + 비교 쌍**만 떠올려도 점수 잘 나옵니다.  
원본 `koica.hwp` 내용을 보강·재구성한 버전입니다. 한글에서 쓰려면 이 파일을 열어 복사해 HWP에 붙여넣으면 됩니다.
