@echo off
REM ============================================================
REM  Start all local dev servers in separate windows:
REM    - Laravel backend  http://localhost:8000
REM    - Queue worker     (generates AI chat replies)
REM    - Admin app        http://localhost:5175
REM    - Customer app     http://localhost:5174
REM  Close any window (or Ctrl+C in it) to stop that server.
REM  Self-contained: this launcher lives inside Booking-Manager,
REM  so %~dp0 is the project root (admin/ and customer/ are siblings).
REM ============================================================

set ROOT=%~dp0

echo Starting Laravel backend on http://localhost:8000 ...
start "Backend (Laravel :8000)" cmd /k "cd /d "%ROOT%" && php artisan serve --host=127.0.0.1 --port=8000"

echo Starting queue worker (AI chat replies) ...
start "Queue Worker" cmd /k "cd /d "%ROOT%" && php artisan queue:work database --tries=1 --timeout=120"

echo Starting Admin app on http://localhost:5175 ...
start "Admin (:5175)" cmd /k "cd /d "%ROOT%admin" && npm run dev"

echo Starting Customer app on http://localhost:5174 ...
start "Customer (:5174)" cmd /k "cd /d "%ROOT%customer" && npm run dev"

echo.
echo Servers launching in separate windows:
echo   Backend       http://localhost:8000
echo   Queue Worker  (AI chat replies)
echo   Admin         http://localhost:5175
echo   Customer      http://localhost:5174
echo.
echo This launcher window can be closed.
