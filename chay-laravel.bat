@echo off
setlocal
cd /d "%~dp0"
set "MAY_PHP=C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe"
if not exist "%MAY_PHP%" (
    echo Khong tim thay PHP 8.3 cua Laragon.
    echo Mo Laragon Terminal, vao thu muc nay va chay: php artisan serve
    pause
    exit /b 1
)
echo Thu vien May: http://127.0.0.1:8000/
echo Giu cua so nay mo trong khi su dung. Nhan Ctrl+C de dung.
"%MAY_PHP%" artisan serve --host=127.0.0.1 --port=8000
pause
