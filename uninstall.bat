@echo off
color 0E
echo ==========================================
echo       PHP Service Uninstaller
echo ==========================================

cd /d "%~dp0"
set SERVICE_NAME=rename_service_ekg

:: ตรวจสอบสิทธิ์ Administrator
net session >nul 2>&1
if %errorLevel% NEQ 0 (
    color 0C
    echo [ERROR] Please right-click and select "Run as Administrator"
    pause
    exit /b
)

echo Stopping service...
nssm stop %SERVICE_NAME%

echo Removing service...
nssm remove %SERVICE_NAME% confirm

echo ==========================================
echo [SUCCESS] Uninstall Complete!
echo ==========================================
pause