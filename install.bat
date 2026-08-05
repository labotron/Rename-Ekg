@echo off
color 0A
echo ==========================================
echo       PHP Service Installer
echo ==========================================

:: 1. อ่าน Path ปัจจุบันที่โฟลเดอร์นี้วางอยู่ (ทำให้เป็น Portable)
cd /d "%~dp0"
set "APP_DIR=%cd%"

:: 2. ตั้งชื่อ Service (เปลี่ยนชื่อได้ตามต้องการ)
set SERVICE_NAME=rename_service_ekg

:: 3. ตรวจสอบสิทธิ์ Administrator
net session >nul 2>&1
if %errorLevel% NEQ 0 (
    color 0C
    echo [ERROR] Please right-click and select "Run as Administrator"
    echo กรุณาคลิกขวาที่ไฟล์นี้ แล้วเลือก Run as Administrator
    pause
    exit /b
)

:: 4. สร้างโฟลเดอร์ logs เตรียมไว้ (ถ้ายังไม่มี)
if not exist "%APP_DIR%\logs" mkdir "%APP_DIR%\logs"

:: 5. เคลียร์ Service เดิมออกก่อน (ถ้ามีค้างอยู่) เพื่อความชัวร์
echo [1/4] Cleaning up old service...
nssm stop %SERVICE_NAME% >nul 2>&1
nssm remove %SERVICE_NAME% confirm >nul 2>&1

:: 6. ติดตั้ง Service ใหม่ โดยชี้ไปที่ php.exe ของ XAMPP เนื่องจากตัวที่มากับโฟลเดอร์เสีย
echo [2/4] Installing new service...
nssm install %SERVICE_NAME% "%APP_DIR%\php\php.exe"

:: 7. ตั้งค่า Parameter ต่างๆ ให้ NSSM
echo [3/4] Configuring service paths...
nssm set %SERVICE_NAME% AppDirectory "%APP_DIR%"
nssm set %SERVICE_NAME% AppParameters "main.php"
nssm set %SERVICE_NAME% AppStdout "%APP_DIR%\logs\out.log"
nssm set %SERVICE_NAME% AppStderr "%APP_DIR%\logs\error.log"

:: 8. เริ่มทำงาน Service
echo [4/4] Starting service...
nssm start %SERVICE_NAME%

echo ==========================================
echo [SUCCESS] Install and Start Complete!
echo สามารถเช็คสถานะในหน้าต่าง services.msc ได้เลย
echo ==========================================
pause
