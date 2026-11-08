@echo off
setlocal EnableExtensions
cd /d "%~dp0"

where php >nul 2>&1
if errorlevel 1 (
    echo PHP 8.3+ is not on PATH.
    echo Use the PHP from your stack ^(XAMPP, WAMP, Laragon^), or: docker compose up --build
    echo See docs\INSTALL.md
    exit /b 1
)

php -r "exit(version_compare(PHP_VERSION, '8.3.0', '>=') ? 0 : 1);"
if errorlevel 1 (
    echo This php is too old. Need 8.3+.
    echo Run the 8.3 php.exe from your stack, or: docker compose up --build
    exit /b 1
)

if /I "%~1"=="doctor" goto :doctor
if /I "%~1"=="--doctor" goto :doctor
if /I "%~1"=="help" goto :help
if /I "%~1"=="--help" goto :help
if "%~1"=="" goto :help

if not exist vendor\autoload.php (
    echo vendor\ is missing. Developers: composer install
    echo Or skip local PHP: docker compose up --build
    exit /b 1
)

if /I "%~1"=="--cli" (
    shift
)
if /I "%~1"=="cli" (
    shift
)

php artisan wento:install %*
exit /b %ERRORLEVEL%

:doctor
php artisan wento:doctor
exit /b %ERRORLEVEL%

:help
php artisan wento:install --help
exit /b %ERRORLEVEL%
