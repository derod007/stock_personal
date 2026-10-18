@echo off
setlocal
set "TASK_PYTHON=%USERPROFILE%\Desktop\dev\noramu\.venv\Scripts\python.exe"
if exist "%TASK_PYTHON%" goto run_local
where py >nul 2>nul
if not errorlevel 1 goto run_launcher
echo Python not found. Run docs\research\type1_holdout_collect.py --collect with your Python 3.
pause
exit /b 2
:run_local
"%TASK_PYTHON%" "%~dp0docs\research\type1_holdout_collect.py" --collect %*
goto done
:run_launcher
py -3 "%~dp0docs\research\type1_holdout_collect.py" --collect %*
:done
set "TASK_RESULT=%ERRORLEVEL%"
echo.
echo Exit code: %TASK_RESULT%
if "%TASK_RESULT%"=="0" (
  echo Success: upload the ZIP shown after READY_TO_UPLOAD.
) else (
  echo Failure: keep the data folder; use RUN_COLLECT.cmd --resume after fixing the issue.
)
pause
exit /b %TASK_RESULT%
