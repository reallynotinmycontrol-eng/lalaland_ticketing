@echo off
REM Run the ticketing app locally on Windows (SQLite, no MySQL needed).
cd /d "%~dp0"
where php >nul 2>nul || (echo PHP is not installed. Install XAMPP or PHP from windows.php.net & pause & exit /b 1)
if not exist app\config.php (
  > app\config.php echo ^<?php
  >> app\config.php echo $c = require __DIR__ . '/config.sample.php';
  >> app\config.php echo $c['base_url'] = 'http://localhost:8080';
  >> app\config.php echo $c['db']['driver'] = 'sqlite';
  >> app\config.php echo $c['debug'] = true;
  >> app\config.php echo return $c;
  echo Created app\config.php for local use.
)
echo.
echo   Ticketing app running at http://localhost:8080
echo   First time? Open http://localhost:8080/install.php
echo   Press Ctrl+C to stop.
echo.
php -S localhost:8080
