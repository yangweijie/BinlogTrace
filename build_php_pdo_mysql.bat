@echo off
REM ============================================================================
REM  为 TypePHP 原生二进制重编 PHP：在 php8ts.lib 中静态内建 pdo_mysql
REM
REM  前提：
REM   1. 已安装 php-sdk（php-sdk-binary-tools）并下载好 external 依赖库。
REM   2. 已 checkout 与现有 C:\php 同版本的 php-src。
REM   3. 本脚本必须在「php-sdk 开发者命令行」中运行
REM      （先执行 phpsdk-vs17-x64.bat 之类进入 SDK shell，确保 cl/nmake 可用）。
REM
REM  用法：
REM    build_php_pdo_mysql.bat <php-src 目录> [现有 php.exe 路径]
REM
REM  例：
REM    build_php_pdo_mysql.bat C:\php-src C:\php\php.exe
REM
REM  原理：抓取现有 C:\php 的 configure 命令，追加 --with-pdo-mysql=static，
REM        其余扩展配置原样保留，从而得到一个只多了 mysql 驱动的 php8ts.lib。
REM ============================================================================

set "SRC=%~1"
set "OLD=%~2"
if "%SRC%"=="" (
    echo 用法: %0 ^<php-src^> [现有php.exe]
    exit /b 1
)
if "%OLD%"=="" set "OLD=C:\php\php.exe"

if not exist "%OLD%" (
    echo 找不到现有 php.exe: %OLD%
    exit /b 1
)

REM ---- 1. 抓取现有 PHP 的 Configure Command ----
echo [1/5] 抓取 %OLD% 的 configure 命令 ...
set "CONF="
for /f "tokens=3,* delims==/" %%a in ('"%OLD%" -i ^| findstr /i "Configure Command"') do (
    set "CONF=%%b"
)
REM findstr 可能因 php -i 换行截断，做一次完整性检查
echo 抓取到的 configure（若为空或不完整，请手动粘贴后重跑）:
echo %CONF%
if "%CONF%"=="" (
    echo 自动抓取失败，请手动复制 php -i 中的 Configure Command 到本脚本 CONF 变量。
    exit /b 1
)

REM ---- 2. 追加 pdo_mysql / mysqlnd 静态编译 ----
echo %CONF% | findstr /i "pdo-mysql" >nul || set "CONF=%CONF% --with-pdo-mysql=static"
echo %CONF% | findstr /i "mysqlnd"   >nul || set "CONF=%CONF% --with-mysqlnd=static"
echo [2/5] 最终 configure:
echo %CONF%

REM ---- 3. buildconf + configure ----
echo [3/5] buildconf + configure ...
cd /d "%SRC%" || exit /b 1
call buildconf --force || exit /b 1
%CONF% || exit /b 1

REM ---- 4. 编译 ----
echo [4/5] nmake （耗时较长）...
call nmake || exit /b 1

REM ---- 5. 导出产物到 staging ----
echo [5/5] 复制产物到 %SRC%\staging ...
if not exist "%SRC%\staging" mkdir "%SRC%\staging"
copy /Y "x64\Release_TS\php8ts.lib"     "%SRC%\staging\" 2>nul
copy /Y "x64\Release_TS\php8embed.lib"  "%SRC%\staging\" 2>nul
copy /Y "x64\Release_TS\php8ts.dll"     "%SRC%\staging\" 2>nul
copy /Y "Release_TS\php8ts.lib"         "%SRC%\staging\" 2>nul
copy /Y "Release_TS\php8embed.lib"      "%SRC%\staging\" 2>nul
copy /Y "Release_TS\php8ts.dll"         "%SRC%\staging\" 2>nul

echo.
echo 完成。staging 目录内容：
dir "%SRC%\staging"
echo.
echo 下一步部署（二选一让运行时加载这份 php8ts.dll）：
echo   A) 把 staging\php8ts.lib + php8embed.lib 覆盖到 PHP_HOME\SDK\lib（编译期链接用）
echo      把 staging\php8ts.dll 放到 binlog-agent.exe 同目录 或 PATH 中 C:\php 之前（运行期加载用）
echo   B) 或直接把 staging 三个文件复制到 binlog-agent.exe 同目录，优先级最高。
echo 然后重新 tpc 编译 agent 并跑 serve 验证 diagnostic.pdo_mysql == true。
