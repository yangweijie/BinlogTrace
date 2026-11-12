# TP-AOT-021: TypePHP AOT 原生二进制中 pdo_mysql 扩展无法加载

Status: **open**, discovered and reproduced on 2026-08-26.

Severity: high runtime compatibility issue (阻断 binlog-agent 连接 MySQL 的全部功能)。

## 现象

通过 TypePHP AOT 编译出的原生二进制（`binlog-agent` / 本复现产物 `tp-aot-021-pdo-mysql-load`）运行时，
`extension_loaded('pdo_mysql')` 返回 `false`，`new \PDO('mysql:host=...')` 抛
`could not find driver`（或 `Class "PDO" not found`，取决于驱动注册时机）。

同一份源码在普通 Zend PHP（扩展以 `.dll` / `.so` 动态加载）下 `pdo_mysql` 正常可用。

## Reproduction

```powershell
# 1) Zend 对照（应正常）
php examples\aot-torture\bugs\TP-AOT-021-pdo-mysql-load\run-zend.php

# 2) 编译 AOT 二进制
examples\aot-torture\build-windows.cmd examples\aot-torture\bugs\TP-AOT-021-pdo-mysql-load\project.yml tp_aot_021_pdo_mysql_load

# 3) 运行 AOT 产物（复现失败）
build\artifacts\tp-aot-021-pdo-mysql-load.exe
```

## Expected (Zend PHP 8.x)

```text
=== TP-AOT-021 pdo_mysql load diagnostic ===
  pdo          = true
  pdo_mysql    = true
  mysqlnd      = true
  php_version  = 8.4.x
  zts          = true
  sapi         = cli
--- try new \PDO('mysql:host=127.0.0.1;port=3306') ---
PDO mysql constructed OK (driver present)
```

## Actual (current TypePHP AOT build)

```text
=== TP-AOT-021 pdo_mysql load diagnostic ===
  pdo          = true
  pdo_mysql    = false
  mysqlnd      = false
  php_version  = 8.4.x
  zts          = true
  sapi         = cli
--- try new \PDO('mysql:host=127.0.0.1;port=3306') ---
PDO construct FAILED: could not find driver
exception: PDOException
```

AOT 二进制虽链接了 `php8ts.lib`，但其中的 `pdo_mysql` 驱动是**动态扩展**，
并未静态内建进当前 `php8ts.lib`，因此编译期无报错、运行期驱动缺失。

## 根因（调查结论）

TypePHP AOT 运行时静态链接 `php8ts.lib`。该 lib 若在编译时未把 pdo_mysql
以 `--with-pdo-mysql=static`（及 `--with-mysqlnd=static`）内建，
则 AOT 二进制内不含 mysql 驱动注册表项，`extension_loaded('pdo_mysql')`
永远为 `false`，PDO 找不到 `mysql:` 驱动。

对照 `agent/build_php_pdo_mysql.bat`：其用途正是重新编译 PHP-src，
抓取现有 `php -i` 的 Configure Command 并追加 `--with-pdo-mysql=static
--with-mysqlnd=static`，产出含静态 pdo_mysql 的 `php8ts.lib`，再覆盖到
PHP_HOME/SDK/lib 并放到 `binlog-agent.exe` 同目录的 `php8ts.dll`。

## 影响

- `binlog-agent` 的 `/connect`、`/query`、`/dump` 全部走 `DmsAgent\Mysql\PdoConnection`，
  扩展缺失即连接失败；`AgentHandler::diagnosticInfo()` 已能暴露
  `pdo_mysql => false`，可作为排障入口。
- 凡声明 `ext-deps: [pdo_mysql]` 的 AOT 项目，在动态扩展 lib 下均会复现。

## 建议修复方向（供作者参考）

1. **编译期**：AOT 工具链在检测到 `ext-deps` 含 `pdo_mysql` 时，
   应要求链接的 `php8ts.lib` 已静态内建该驱动；
   缺失时给出明确告警（而非运行期静默失败）。
2. **或运行期**：AOT 二进制支持按 `extension_dir` 动态加载 `php_pdo_mysql.dll`
   （同 Zend 行为），在 `new \PDO` 前 `dl('php_pdo_mysql')` 或自动 require。
3. **文档/排障**：在 agent README 固化 `build_php_pdo_mysql.bat` 步骤，
   并将 `diagnosticInfo().pdo_mysql` 纳入启动自检，为 `false` 时打印修复指引。
