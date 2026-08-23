# AGENTS.md — TypePHP DMS (PHP AOT Compiler + MySQL Data Trace Tool)

## What this is

A PHP Ahead-of-Time (AOT) compiler ecosystem: two PHP subsystems compiled with TypePHP (`parser/`, `agent/`) plus a React frontend (`frontend/`). Root `src/` is empty — the TypePHP compiler core lives in a separate repo.

## Repo layout

| Path | What |
|------|------|
| `parser/` | Binlog parser — PHP → WASM (browser) via TypePHP. Runs in a Web Worker. |
| `agent/` | Binlog agent — PHP → native binary via TypePHP. WebSocket↔MySQL TCP proxy. |
| `agent-workerman/` | Alternative agent in **pure PHP on Workerman 5** — runs with `php start.php` (default port 8080, `--port` to override), **no TypePHP compiler needed**. Own `composer.json` (workerman + krowinski) and self-tests in `tests/` (see Commands below). **Real-mode binlog parsing happens here** via `krowinski/php-mysql-replication` (see Contracts). |
| `agentRaw/` | Minimal single-file raw-PHP WS proxy (`stream_select` fallback; `php agentRaw/run.php`, smoke test `php agentRaw/test_ws.php`). Also compiler-free. |
| `frontend/` | React 18 + Vite 7 + TypeScript SPA. Consumes `parser` WASM (demo) and the agent WS. |
| `docs/` | TypePHP compiler design docs. |
| `vendor/` | Composer autoload only — no runtime deps. |

## TypePHP build — the core gotcha

PHP in `parser/` and `agent/` is **not run with `php`** — it must be compiled by `bin/tpc.php` (the TypePHP compiler, installed separately, NOT in this repo):

```bash
php bin/tpc.php parser/project.yml          # → parser WASM (browser)
php bin/tpc.php agent/ -o binlog-agent      # → native binary
php bin/tpc.php parser/project.yml --dry    # C++ generation only
```

Without the compiler, the only verification is lint: `find parser/src agent/src -name '*.php' -exec php -l {} +`. The pure-PHP agents (`agent-workerman/`, `agentRaw/`) exist precisely so the WS proxy can be developed/tested without it.

## Commands — pure-PHP agents (no compiler needed)

Run from `agent-workerman/`:

```bash
composer install                 # independent vendor/ (workerman + krowinski/php-mysql-replication)
php start.php start              # default port 8080; --port to override; stop / restart subcommands
```

Pick the test that matches what you have available:

```bash
php tests/ws_smoke_test.php      # smoke test, no MySQL (agent must already be running)
node tests/node_ws_check.mjs     # native Node WS handshake check (agent must already be running)
php tests/pdo_check.php          # verify a MySQL account via PDO
php tests/integration_test.php   # needs real MySQL 127.0.0.1:3306 root/root, db `shengyibao`
php tests/fake_mysql_server.php  # fake MySQL server capturing caching_sha2 auth bytes (debug harness)
```

`agentRaw/` — same wire protocol, single-file fallback: `php agentRaw/run.php` + `php agentRaw/test_ws.php`.

## agent-workerman — dual-connection model

- One `WsHandler` per browser WS connection (held in `$conn->context`) → multiple browsers can trace in parallel; the TypePHP `agent/` is single-connection blocking.
- Dump holds **one long-lived** MySQL connection (`AsyncClient` in `binlog` state, ST_BINLOG event stream). Queries use a **separate lazy** connection (`queryMysql`, serialized queue) so meta/schema lookups keep working while a dump is active.
- `src/Mysql/AsyncClient.php` is a hand-written MySQL wire-protocol state machine (`auth → idle → result/binlog`, callback style). It fixes two protocol bugs present in the TypePHP `agent/src/MySQL/Client.php` (auth-plugin-data-part-2 offset; CLIENT_CONNECT_WITH_DB NUL-terminated database) — don't port blind from `agent/`.
- Krowinski worker spawn + Windows pipe gotchas: see Contracts below.

### TypePHP PHP subset — hard constraints (every file in `parser/` and `agent/`)

- `declare(strict_types=1);` required at top of every file
- **Forbidden**: closures, arrow functions, `yield`/generators, fibers, dynamic dispatch (`$obj->$method()`)
- WASM exports: `#[WasmExport(name: 'kebab-name')]`, **string in → string out only** (no arrays/objects/references across the boundary)
- `agent/` is `mode: bin` — must define `function main(int $argc, array $argv): void`
- Sort with inline insertion sort (no closure `uksort`); helper methods instead of `array_map` closures
- Authoritative: `docs/INCOMPATIBLE_PHP_FEATURES.md`, `docs/COMPILATION_MODES.md`, `findings.md`

## Frontend

```bash
cd frontend
npm install          # if node_modules missing
npm run dev          # Vite → 127.0.0.1:5173
npm run build        # tsc --noEmit && vite build
npm run type-check   # tsc --noEmit
```

- Entry: `src/main.tsx` → `src/App.tsx` (hash router, 4 pages)
- WASM runs in `src/workers/parser.worker.ts` via `@bytecodealliance/preview2-shim` + `jco`; Jco maps kebab-case to camelCase (`parse-binlog` → `parseBinlog`)
- WASM functions: `parse-binlog` / `generate-rollback` / `check-binlog-cfg`
- `src/lib/mock-agent.ts` — demo mode simulating protocol-v2 without MySQL; use it for UI/QA when no real agent runs
- `src/lib/ws.ts` — protocol v2 WS client (frame `{v:2, id, type, ts, payload}`, error codes aligned to 1001–1013)
- `src/context/AppContext.tsx` + `src/hooks/useTraceRun.ts` — session state; real mode maps `binlog-change` frames → `Change` (no WASM)

## Contracts

- **Parser WASM**: JSON string in → JSON string out (`{ok, changes[], warnings[]}`). BLOB values use `base64:` prefix; BIGINT/DECIMAL stay raw strings. **Real mode no longer calls `parse-binlog`** — it consumes agent-side `binlog-change` frames; the WASM path is demo-only (`mock-agent` + `demoParse`).
- **Agent WS**: protocol v2, frame `{v:2, id, type, ts, payload}`; error codes **1001–1013** defined in `agent/src/Protocol/Frame.php` (e.g. 1012 = binlog transaction compression). Default port 8080, binds `0.0.0.0` — **internal network only**. No built-in auth: put nginx+auth or a firewall in front before any exposure.
- **Real-mode binlog parsing (agent-workerman)**: `binlog-dump` spawns `bin/krowinski_dump.php` as a **separate PHP process** (krowinski is a synchronous/blocking lib — must NOT run in the Workerman event loop). It streams one JSON change per stdout line; the handler forwards them as `binlog-change` frames `{kind, schema, table, columns, before, after, xid, timestamp, binlogFile, binlogPos}`. Frontend `useTraceRun` maps these directly to `Change` (no WASM). MySQL needs `binlog_row_metadata=MINIMAL` fine — krowinski falls back to information_schema for column names.
- **Time-window search (历史点定位)**: `binlog-dump` payload may carry `startMs`/`endMs` (epoch ms). The worker then locates the start binlog file itself: PDO `SHOW BINARY LOGS` + krowinski probe of each file (newest→oldest) reading the first row event's ts; the newest file whose first row ts `< startTs` wins (dump from pos 4; rows before startTs are filtered by `--start-ts`, so over-reading an older file is harmless — no intra-file binary search). Worker exits when a row crosses `endTs`, or via heartbeat fallback `time() > endTs+5` (heartbeat period is 5s; krowinski's heartbeat `eventInfo->timestamp` is always 0, so use local `time()`). Normal exit (code 0) makes the agent send a `binlog-end` frame — the frontend's `session.isEnded` then finalizes; no 45s idle wait.
- **Windows gotchas (agent-workerman)**: (1) `proc_open` env must be `array_merge(getenv(), [...])` — passing only `$_ENV` (empty in CLI) strips PATH/SYSTEMROOT and breaks Winsock (`socket_create` 10106); (2) `stream_set_blocking(false)` on `proc_open` pipes is a **no-op on Windows** — `fread` blocks until child output, which would freeze the event loop; the dump worker's stdout/stderr are therefore redirected to `runtime/krowinski_*.out/.err` files and polled incrementally by a `Timer`.
- **MySQL prereqs**: `log_bin=ON`, `binlog_format=ROW`, user needs `REPLICATION SLAVE` + `REPLICATION CLIENT`. MySQL 8.0.20+ `binlog_transaction_compression=ON` breaks decoding (error 1012).

## Build artifacts — do not hand-edit

`parser/build/`, `parser/component/`, `agent/build/`, `agent/binlog_agent.{exe,lib,exp}`, `frontend/dist/` — all regenerated by the build; never edit by hand.

## Single-threaded constraint

Both compiled targets are single-threaded NTS: no `pcntl_fork`, no threads, no async concurrency in PHP. `agent/` uses non-blocking `stream_select`-style polling via the TypePHP runtime.

## Maintenance rule

When project structure, build/test commands, architecture boundaries, development conventions, or any other facts documented in this file change, update this file in the same change.
