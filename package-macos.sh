#!/usr/bin/env bash
# package-macos.sh — 组 macOS 自包含发行包（dylib 随包，Apple Silicon/arm64）
#
# 对应 Windows 的 package.bat。macOS 版额外做 dylib 自包含：
#   1) 组包：binlog-agent + php.ini + dist/ + vendor/ + bin/dump_worker.php + .env
#   2) 把 4 个 Homebrew dylib（gmp/gmpxx/mpfr/oniguruma）拷进包内 lib/
#   3) 改写 install_name 为 @rpath，主二进制加 @loader_path/lib rpath，
#      并清掉指向构建机绝对路径的旧 rpath
#      → 解压到任意 arm64 Mac 即可直接运行，无需 Homebrew / PHP / 编译器
#
# 用法：./package-macos.sh [目标目录]     # 默认 release-mac/dms-agent
# 注意：目标目录会被删除重建（指向 release 目录，勿指向需要保留的目录）
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT"
DST="${1:-$ROOT/release-mac/dms-agent}"

[ -x "$ROOT/binlog-agent" ] || { echo "未找到 binlog-agent，先运行 ./build-macos.sh" >&2; exit 1; }
command -v install_name_tool >/dev/null || { echo "缺少 install_name_tool（需 Xcode Command Line Tools）" >&2; exit 1; }

# ── 1) 目录布局（与 Windows 包同构，运行时以 $argv[0] 所在目录为基准） ──
rm -rf "$DST"
mkdir -p "$DST/lib" "$DST/bin"
cp "$ROOT/binlog-agent" "$DST/"
cp "$ROOT/php.ini" "$DST/"
cp -R "$ROOT/dist" "$ROOT/vendor" "$DST/"
cp "$ROOT/bin/dump_worker.php" "$DST/bin/"
if [ -f "$ROOT/.env" ]; then cp "$ROOT/.env" "$DST/"; else cp "$ROOT/.env.example" "$DST/.env"; fi

# ── 2) dylib：Homebrew → 包内 lib/（@rpath 化） ──────────────────────
# 文件名是 brew 公式当前的 ABI 版本号（gmp 6.3 / mpfr 4.2 / oniguruma 6.2）；
# brew 升级后 soversion 变化时需同步更新 DYLIBS。
declare -a DYLIBS=(
  "gmp:libgmp.10.dylib"
  "gmp:libgmpxx.4.dylib"
  "mpfr:libmpfr.6.dylib"
  "oniguruma:libonig.5.dylib"
)
for e in "${DYLIBS[@]}"; do
  formula="${e%%:*}"; lib="${e#*:}"
  src="$(brew --prefix "$formula")/lib/$lib"
  if [ ! -f "$src" ]; then
    echo "缺少 $src（brew install $formula？或更新脚本 DYLIBS 列表）" >&2; exit 1
  fi
  cp "$src" "$DST/lib/$lib"
done

# install_name 一律改为 @rpath/<basename>
for lib in "$DST"/lib/*.dylib; do
  install_name_tool -id "@rpath/$(basename "$lib")" "$lib"
done

# 改写对其它 dylib 的引用。旧路径记录在二进制里（Cellar/opt 路径），动态匹配；
# otool 输出行首是 TAB，必须整行 trim 后取路径，否则 -change 会静默无效：
fix_ref() { # $1=目标文件  $2=dylib basename
  local old
  old="$(otool -L "$1" 2>/dev/null | grep -F "/$2" | grep -Fv '@rpath' | head -1 | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*(compatibility.*$//')"
  if [ -n "$old" ]; then
    install_name_tool -change "$old" "@rpath/$2" "$1" || { echo "install_name_tool -change $2（$1）失败" >&2; return 1; }
  fi
  return 0
}
fix_ref "$DST/lib/libgmpxx.4.dylib" "libgmp.10.dylib"
fix_ref "$DST/lib/libmpfr.6.dylib"  "libgmp.10.dylib"

# ── 3) 主二进制：清构建机 rpath + 指向包内 lib/ ─────────────────────
while IFS= read -r rp; do
  case "$rp" in
    @*) ;; # @loader_path / @executable_path 开头保留
    *)  install_name_tool -delete_rpath "$rp" "$DST/binlog-agent" 2>/dev/null || true ;;
  esac
done < <(otool -l "$DST/binlog-agent" | grep -A2 'LC_RPATH' | grep '^ *path' | awk '{print $2}')
install_name_tool -add_rpath "@loader_path/lib" "$DST/binlog-agent"
for lib in libgmp.10.dylib libgmpxx.4.dylib libmpfr.6.dylib libonig.5.dylib; do
  fix_ref "$DST/binlog-agent" "$lib"
done

# ── 4) 独立验收：启动 /ping（仅健康检查，不连库） ────────────────────
PORT=9821
LOG="$(mktemp -t dmsagent-pkg)"
( cd /tmp && exec "$DST/binlog-agent" serve --port "$PORT" ) >"$LOG" 2>&1 &
PID=$!
ok=''
for _ in $(seq 1 20); do
  sleep 1
  if out="$(curl -sS -m 2 "http://127.0.0.1:$PORT/ping" 2>/dev/null)" && printf '%s' "$out" | grep -q '"ok":true'; then ok=1; break; fi
done
if [ -n "$ok" ]; then
  echo "[accept] /ping OK（独立目录自包含运行）"
else
  echo "[accept] /ping 失败，日志："; cat "$LOG" >&2
fi
kill "$PID" 2>/dev/null || true; wait "$PID" 2>/dev/null || true
[ -n "$ok" ] || exit 1
rm -f "$LOG"

echo ""
echo "[package] 完成: $DST （$(du -sh "$DST" | cut -f1)）"
echo "[package] otool -L 摘要："
otool -L "$DST/binlog-agent" | head -12
echo "[package] 分发：zip -r dms-agent-macos.zip $(basename "$DST")（目标机需 arm64 Mac）"
