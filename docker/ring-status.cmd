@echo off
rem Show the observer ring's current state.
rem
rem Double-click this, or run it from a terminal in this folder. In PowerShell
rem it needs the .\ prefix, which is PowerShell refusing to run things from the
rem current directory rather than anything to do with this script.
rem
rem     ring-status                 watch, redrawing in place once a second
rem     ring-status --once          one frame and stop
rem     ring-status --interval=5    watch, every five seconds
rem     ring-status --no-color      no colour escapes
rem
rem Watching is the default because a ring is a live thing and a single frame
rem of it answers a narrower question than people usually have. --once is there
rem for when the answer is wanted in a scrollback or a paste.
rem
rem It reads the store tree and prints it. It writes nothing, and every path it
rem touches is mounted read-only in the container it runs in.

setlocal
cd /d "%~dp0"

set COMPOSE=docker compose -f docker-compose.yml -f docker-compose.local.yml -f docker-compose.verify.yml

rem Is the ring even up? A clear message beats a compose stack trace.
rem `ps -q` prints the container id or nothing at all, which is a cleaner
rem question than matching a service name in a list whose line endings differ
rem depending on which shell asked.
set SUPER=
for /f "usebackq delims=" %%i in (`%COMPOSE% ps -q super 2^>nul`) do set SUPER=%%i
if not defined SUPER (
    echo.
    echo   The 'super' container is not running, so there is no ring to look at.
    echo.
    echo   Start it with:
    echo     %COMPOSE% up -d
    echo.
    exit /b 1
)

rem Copied in rather than piped, so the terminal keeps its TTY: that is what
rem makes the colours render and --watch redraw properly.
rem Both streams: compose reports copy progress on stderr, and that is noise
rem rather than news.
%COMPOSE% cp ring-status.php super:/tmp/ring-status.php >nul 2>nul
if errorlevel 1 (
    echo   Could not copy the script into the container.
    exit /b 1
)

rem No arguments means watch. Anything passed is handed through untouched, so
rem --once, --interval= and --no-color all still work.
if "%~1"=="" (
    %COMPOSE% exec super php /tmp/ring-status.php --watch
) else (
    %COMPOSE% exec super php /tmp/ring-status.php %*
)

endlocal
