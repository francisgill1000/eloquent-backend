@echo off
REM ============================================================
REM  Business Lens - start the ADMIN frontend (dev server)
REM  Backend = STAGING (no local PHP on this machine).
REM  Exposes on your Wi-Fi so a tablet can reach it too.
REM ============================================================

cd /d "d:\Francis\projects\2026\Eloquent\Solutions\Business-Lens\admin"

REM Point the app at the staging API
set "VITE_API_URL=https://staging-api.eloquentservice.com/api"

echo.
echo ============================================================
echo   Starting Business Lens admin (frontend)
echo   API  : %VITE_API_URL%
echo.
echo   When it starts, use the URLs it prints:
echo     Local   -> on this PC (http://localhost:PORT)
echo     Network -> on your tablet, same Wi-Fi (http://192.168.x.x:PORT)
echo.
echo   Logins (staging):  Master 800001 / 8888   Shop 390676 / 5648
echo   NOTE: microphone only works on the PC (localhost) or over
echo         HTTPS - not over the plain http Network URL.
echo.
echo   Press Ctrl+C to stop the server.
echo ============================================================
echo.

call npm run dev -- --host

echo.
echo Server stopped.
pause
