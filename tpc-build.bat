@echo off
REM tpc-build.bat -- run tpc under MSVC vcvars64 (fixes C1083 when INCLUDE is missing)
REM usage: tpc-build.bat <project-dir> [tpc args...]
REM note: no parenthesized blocks -- args contain (x86) which tears blocks apart
call "d:\Program Files (x86)\Microsoft Visual Studio\2022\BuildTools\VC\Auxiliary\Build\vcvars64.bat"
if "%INCLUDE%"=="" goto novars
REM keep project php.ini (meant for the compiled binary) away from tpc's own PHP
set "PHPRC=zzz-no-ini"
set "PROJ=%~1"
shift
set ARGS=%1 %2 %3 %4 %5 %6 %7
REM build from a php.ini-free cwd: a project php.ini (for the compiled binary)
REM in the project dir breaks tpc's own PHP when loaded from cwd
cd /d "%TEMP%"
tpc %ARGS%
exit /b %ERRORLEVEL%
:novars
echo INCLUDE_EMPTY_AFTER_VCVARS
exit /b 2
