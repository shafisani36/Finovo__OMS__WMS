@echo off
echo Starting Laravel Backend on port 8001...
start "Backend (Laravel)" cmd /k "cd /d %~dp0backend && php artisan serve --host=127.0.0.1 --port=8001"

echo Starting React Frontend on port 3000...
start "Frontend (React Vite)" cmd /k "cd /d %~dp0Frontend && npm run dev"

echo Both servers started!
echo Frontend: http://localhost:3000
echo Backend:  http://127.0.0.1:8001
