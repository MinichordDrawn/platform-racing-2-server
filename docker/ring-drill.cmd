@echo off
rem Break the ring on purpose, then put it back, and watch what happens.
rem
rem     ring-drill break     add a file the web container was not built with
rem     ring-drill fix       take it away again
rem     ring-drill status    is the fault currently injected
rem
rem Run `ring-status` in another window first, then break it from here.
rem
rem What it does and why that one:
rem
rem   Every observer hashes the code in its own container at build time and
rem   compares it every cycle. Adding a single empty file under /pr2/http_server
rem   is enough to fail `code-unchanged`, and deleting it is enough to pass
rem   again. Nothing else is touched: no database, no store, no player data, no
rem   configuration. The file is empty and it is named for what it is.
rem
rem   It exercises the whole path rather than a corner of it. The web observer
rem   raises a fault, writes its halt, delivers it into every other member's
rem   store, every gate reader starts refusing, and HTTP answers 503. Take the
rem   file away and the next cycle finds nothing wrong, the halt clears once
rem   every member agrees, and HTTP answers 200 again. Nobody restarts anything.
rem
rem This is a local drill against a throwaway deployment. Do not point it at
rem anything anyone is using.

setlocal
cd /d "%~dp0"

set COMPOSE=docker compose -f docker-compose.yml -f docker-compose.local.yml -f docker-compose.verify.yml
set MARK=/pr2/http_server/.ring-drill

set SERVICE=
for /f "usebackq delims=" %%i in (`%COMPOSE% ps -q web 2^>nul`) do set SERVICE=%%i
if not defined SERVICE (
    echo.
    echo   The 'web' container is not running, so there is nothing to break.
    echo.
    exit /b 1
)

if /i "%~1"=="break" goto :break
if /i "%~1"=="fix"   goto :fix
if /i "%~1"=="status" goto :status

echo.
echo   ring-drill break     break the ring on purpose
echo   ring-drill fix       put it back
echo   ring-drill status    is it currently broken
echo.
echo   Watch it happen with ring-status in another window.
echo.
exit /b 1

:break
%COMPOSE% exec -T web sh -c "touch %MARK%" >nul 2>nul
if errorlevel 1 (
    echo   Could not write the file.
    exit /b 1
)
echo.
echo   Broken. The web container now holds a file it was not built with.
echo.
echo   Expect, within a cycle or two: web goes to 'fault', the others follow
echo   with 'halt', and HTTP answers 503. Run 'ring-drill fix' to undo it.
echo.
exit /b 0

:fix
%COMPOSE% exec -T web sh -c "rm -f %MARK%" >nul 2>nul
if errorlevel 1 (
    echo   Could not remove the file.
    exit /b 1
)
echo.
echo   Cause removed. Nothing has been restarted.
echo.
echo   The ring clears itself once every member has looked again and agreed,
echo   which takes a few cycles because clearing needs all of them.
echo.
exit /b 0

:status
%COMPOSE% exec -T web sh -c "[ -f %MARK% ] && echo BROKEN || echo clean" 2>nul
exit /b 0
