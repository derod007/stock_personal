@echo off
setlocal EnableDelayedExpansion
rem Daily paper updater: KR amount scan, US identity comparison.
rem --kr   20:20-23:59 KST catch-up, Naver regular-session overlay
rem --us   06:20-11:59 KST catch-up, US only
rem --force  ignore the time window

set "ROOT=c:\Users\acdun\Desktop\dev\noramu"
set "PYTHON=%ROOT%\.venv\Scripts\python.exe"
set "PHP=E:\xampp\php\php.exe"
set "LOGDIR=%ROOT%\data\paper-logs"
set "PATH=E:\xampp\php;%PATH%"

set "RUN_US=0"
set "RUN_KR=0"
set "FORCE="
if "%~1"=="" (
  set "RUN_US=1"
  set "RUN_KR=1"
)
:args
if "%~1"=="" goto args_done
if /I "%~1"=="--us" set "RUN_US=1"
if /I "%~1"=="--kr" set "RUN_KR=1"
if /I "%~1"=="--force" set "FORCE=--force"
shift
goto args
:args_done

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

if "!RUN_KR!"=="1" if "!RUN_US!"=="0" (
  "%PHP%" bin\paper_schedule_window.php kr %FORCE% >> "%LOG%" 2>&1
  if errorlevel 10 (
    echo [%DATE% %TIME%] skip KR outside 20:20-23:59 KST>> "%LOG%"
    echo [%DATE% %TIME%] skip KR outside 20:20-23:59 KST>> "%LOGDIR%\runner.log"
    exit /b 0
  )
  if errorlevel 1 (
    echo [%DATE% %TIME%] ERROR: KR window check failed>> "%LOGDIR%\runner.log"
    exit /b 1
  )
)
if "!RUN_US!"=="1" if "!RUN_KR!"=="0" (
  "%PHP%" bin\paper_schedule_window.php us %FORCE% >> "%LOG%" 2>&1
  if errorlevel 10 (
    echo [%DATE% %TIME%] skip US outside 06:20-11:59 KST>> "%LOG%"
    echo [%DATE% %TIME%] skip US outside 06:20-11:59 KST>> "%LOGDIR%\runner.log"
    exit /b 0
  )
  if errorlevel 1 (
    echo [%DATE% %TIME%] ERROR: US window check failed>> "%LOGDIR%\runner.log"
    exit /b 1
  )
)

echo ===== paper compare daily start %DATE% %TIME% US=!RUN_US! KR=!RUN_KR! =====>> "%LOG%"
set "US_EC=0"
set "KR_EC=0"
if "!RUN_US!"=="1" (
  "%PYTHON%" bin\paper_compare_daily.py --config=config\paper-us.json --experiment=us-identity >> "%LOG%" 2>&1
  set "US_EC=!ERRORLEVEL!"
)
if "!RUN_KR!"=="1" (
  "%PYTHON%" bin\paper_daily.py --config=config\paper-kr.json >> "%LOG%" 2>&1
  set "KR_EC=!ERRORLEVEL!"
)
echo ===== paper compare daily end US=!US_EC! KR=!KR_EC! %DATE% %TIME% =====>> "%LOG%"

if not "!US_EC!"=="0" exit /b !US_EC!
exit /b !KR_EC!
