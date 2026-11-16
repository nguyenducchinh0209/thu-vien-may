@echo off
setlocal
set "MAY_PHP=C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe"
if not exist "%MAY_PHP%" (
    echo Khong tim thay PHP 8.3 cua Laragon. Kiem tra duong dan MAY_PHP trong file nay.
    pause
    exit /b 1
)

set "MAY_ZIP="
"%MAY_PHP%" -r "exit(extension_loaded('zip') ? 0 : 1);"
if errorlevel 1 set "MAY_ZIP=-d extension=zip"

echo Thu vien May: http://127.0.0.1:8012/
echo Bat MySQL Laragon theo cong trong .env truoc khi su dung.
echo Giu cua so nay mo trong khi su dung. Nhan Ctrl+C de dung.
pushd "%~dp0public"
"%MAY_PHP%" %MAY_ZIP% -S 127.0.0.1:8012 ..\vendor\laravel\framework\src\Illuminate\Foundation\resources\server.php
popd
pause
