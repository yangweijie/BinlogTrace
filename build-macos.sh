#!/usr/bin/env bash
# macOS 一键编译 binlog-agent（TypePHP AOT，Darwin/arm64）
#
# 前置：
#   - TypePHP 编译器源码 v0.10.0（v0.9.5 的 PhpBuilderSource 有 core 伪扩展 404 缺陷）
#     可用 TPC=/path/to/tpc.php 覆盖默认路径
#   - Homebrew 的 gmp / mpfr（静态 PHPX 用 gmpxx.h / mpfr.h）：brew install gmp mpfr
#   - 前端产物 dist/（cd frontend && npm install && npm run build）
#   - composer install（dump worker 用的 vendor/）
#
# 用法：
#   ./build-macos.sh                 # 全量编译 → ./binlog-agent
#   ./build-macos.sh --dry           # 只生成 C++
#   ./build-macos.sh -O2            # 追加其它 tpc 参数
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT"

TPC="${TPC:-/Volumes/data/git/php/typephp-compiler/bin/tpc.php}"
if [ ! -f "$TPC" ]; then
    echo "未找到 tpc.php: $TPC" >&2
    echo "请设置 TPC=/path/to/typephp-compiler/bin/tpc.php" >&2
    exit 1
fi

# 静态 PHPX 由 cmake 子构建编译，它不会继承 tpc 的 Homebrew include 路径，
# 导致 big_int.cc / big_float.cc 找不到 gmpxx.h / mpfr.h。用编译器环境变量补齐。
export CPATH="/opt/homebrew/include${CPATH:+:$CPATH}"
export CPLUS_INCLUDE_PATH="/opt/homebrew/include${CPLUS_INCLUDE_PATH:+:$CPLUS_INCLUDE_PATH}"
export LIBRARY_PATH="/opt/homebrew/lib${LIBRARY_PATH:+:$LIBRARY_PATH}"

# 产物落在项目根，运行时以 $argv[0] 所在目录为基准找 .env / dist / vendor / bin。
# project.macos.yml 里的 php-builder 段会自动启用私有运行时，命令行不要再传 --php-builder。
php "$TPC" project.macos.yml --no-progress "$@"

if [ -x "$ROOT/binlog-agent" ]; then
    echo ""
    echo "编译完成: $ROOT/binlog-agent"
    file "$ROOT/binlog-agent"
else
    echo "编译未产出二进制（传了 --dry？）" >&2
fi
