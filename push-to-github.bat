@echo off
echo =======================================================
echo Pushing Finovo OMS / WMS to GitHub main branch...
echo Repository: https://github.com/shafisani36/Finovo__OMS__WMS.git
echo =======================================================
echo.
cd /d "%~dp0"
git push -u origin main
echo.
if %ERRORLEVEL% EQU 0 (
    echo [SUCCESS] Code pushed successfully to GitHub main branch!
) else (
    echo [ERROR] Push failed. If prompted, please authenticate via your GitHub account or Personal Access Token (PAT).
)
echo.
pause
