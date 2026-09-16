@echo off
setlocal
rem Daily paper compare updater for Task Scheduler.
rem Runs paper_compare_daily (source daily + experiment) for US then KR.
rem KR after KRX close; US after US cash equity close (KST).

set "ROOT=c:\Users\acdun\Desktop\dev\noramu"
set "PYTHON=%ROOT%\.venv\Scripts\python.exe"
set "PHP=E:\xampp\php\php.exe"
set "LOGDIR=%ROOT%\data\paper-logs"
set "PATH=E:\xampp\php;%PATH%"

if not exist "%PYTHON%" (
  echo [%DATE% %TIME%] ERROR: Python not found: %PYTHON%>> "%LOGDIR%\runner.log"
  exit /b 1
)
if not exist "%PHP%" (
  echo [%DATE% %TIME%] ERROR: PHP not found: %PHP%>> "%LOGDIR%\runner.log"
  exit /b 1
)

if not exist "%LOGDIR%" mkdir "%LOGDIR%"
cd /d "%ROOT%" || exit /b 1

set "STAMP=%DATE:/=-%_%TIME::=-%"
set "STAMP=%STAMP: =0%"
set "LOG=%LOGDIR%\paper-%STAMP%.log"

echo ===== paper compare daily start %DATE% %TIME% =====>> "%LOG%"
"%PYTHON%" bin\paper_compare_daily.py --config=config\paper-us.json --experiment=us-identity >> "%LOG%" 2>&1
set "US_EC=%ERRORLEVEL%"
"%PYTHON%" bin\paper_compare_daily.py --config=config\paper-kr.json --experiment=kr-identity >> "%LOG%" 2>&1
set "KR_EC=%ERRORLEVEL%"
echo ===== paper compare daily end US=%US_EC% KR=%KR_EC% %DATE% %TIME% =====>> "%LOG%"

if not "%US_EC%"=="0" exit /b %US_EC%
exit /b %KR_EC%
