<?php

declare(strict_types=1);

/**
 * dump_worker.php — binlog 解析子进程业务逻辑（普通 PHP，随包分发，不经 TypePHP 编译）
 *
 * 由两种方式执行：
 *   1. 编译二进制：binlog-agent __dump_worker ...（embed SAPI 内经 ZendVM require 本文件，
 *      由 src/DumpWorker.php 预先 require vendor/autoload.php）
 *   2. 开发模式：php bin/dump_worker.php ...（本文件自带 vendor require）
 *
 * 用 krowinski/php-mysql-replication 解析 binlog，行事件以 JSON 行输出到 stdout
 * （每行一个结构化变更），由 agent 主进程经 SSE 转发给前端。
 *
 * 用法：
 *   ... --host 127.0.0.1 --port 3306 --user root --file binlog.000014 --pos 4 \
 *       [--db test] [--start-ts 1787400000] [--end-ts 1787410000]
 *   密码经环境变量 DMS_MYSQL_PASSWORD 传入（--password 可选，缺省读 ENV）。
 *
 * 时间窗口（epoch 秒，0 = 不限）：
 *   --start-ts  早于该时刻的行事件跳过；并自动定位起点文件（SHOW BINARY LOGS 从新到旧探测
 *               各文件首个行事件 ts，取首事件 ts < startTs 的最新文件，从 pos 4 起读，
 *               经 ROTATE 续读更新文件，窗口前事件由 --start-ts 过滤）。
 *   --end-ts    行事件越过该时刻即退出；无新行事件时用心跳（本地墙钟）越过 endTs+5s 兜底退出。
 *
 * 输出行（stdout，每行一个 JSON）：
 *   {"type":"change","kind":"insert|update|delete","schema","table","columns","primaryKeys",
 *    "before","after","xid","timestamp","binlogFile","binlogPos"}
 *   {"type":"heartbeat","ts":...}（仅心跳/兜底退出用，前端忽略）
 * 错误写 stderr。exit 0 表示正常结束；非 0 表示异常。
 *
 * 注意：本文件为普通 PHP（非 TypePHP 编译目标），允许全局作用域、匿名类、闭包绑定等
 * 原生语义；不要把本文件加入 project.yml 的 sources。
 */

use MySQLReplication\Config\ConfigBuilder;
use MySQLReplication\Definitions\ConstEventType;
use MySQLReplication\Event\DTO\DeleteRowsDTO;
use MySQLReplication\Event\DTO\RotateDTO;
use MySQLReplication\Event\DTO\UpdateRowsDTO;
use MySQLReplication\Event\DTO\WriteRowsDTO;
use MySQLReplication\Event\DTO\XidDTO;
use MySQLReplication\Event\EventSubscribers;
use MySQLReplication\MySQLReplicationFactory;

function dms_parse_args(array $args): array
{
    $out = [];
    $n = count($args);
    for ($i = 0; $i < $n; $i++) {
        $a = (string) $args[$i];
        if (strpos($a, '--') !== 0) {
            continue;
        }
        $key = substr($a, 2);
        $eq = strpos($key, '=');
        if ($eq !== false) {
            $out[substr($key, 0, $eq)] = substr($key, $eq + 1);
            continue;
        }
        if ($i + 1 < $n) {
            $out[$key] = $args[++$i];
        } else {
            $out[$key] = true;
        }
    }
    return $out;
}

function dms_emit(array $row): void
{
    static $startTime = null;
    if ($startTime === null) {
        $startTime = microtime(true);
    }
    $elapsed = (microtime(true) - $startTime) * 1000; // ms since first emit
    $json = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        fwrite(STDERR, 'dump_worker: json_encode 失败: ' . json_last_error_msg() . "\n");
        return;
    }
    fwrite(STDOUT, $json . "\n");
    flush();
    fwrite(STDERR, 'dump_worker: emit #' . ($row['xid'] ?? 0) . ' kind=' . ($row['kind'] ?? '?') . ' ts=' . ($row['timestamp'] ?? 0) . ' elapsed_ms=' . round($elapsed, 1) . "\n");
}

/** 返回 [文件, pos, 原因] */
function dms_probe_start_file(string $host, int $port, string $user, string $password, int $startTs): array
{
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname=mysql",
        $user,
        $password,
        [PDO::ATTR_TIMEOUT => 5, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $rows = $pdo->query('SHOW BINARY LOGS')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (count($rows) === 0) {
        return ['', 4, '无可用 binlog 文件'];
    }
    // SHOW BINARY LOGS 按名升序（最后一个为当前文件）。
    // 从新到旧探测：取「首个行事件 ts < startTs」的最新文件（窗口起点落在该文件或更早；
    // dump 从该文件 pos 4 开始会经 ROTATE 自动续读更新的文件，配合 --start-ts 过滤不漏）
    $files = array_reverse($rows);
    foreach ($files as $row) {
        $f = (string) ($row['File_name'] ?? $row['Log_name'] ?? '');
        if ($f === '') {
            continue;
        }
        $verdict = dms_probe_one($host, $port, $user, $password, $f, $startTs);
        if ($verdict === 'pick') {
            return [$f, 4, '探测定位（该文件含窗口起点之前的数据）'];
        }
        // 'older'（首行事件晚于窗口起点 / 文件无行事件 / 探测超时）→ 窗口在更旧的文件，继续
    }
    // 所有文件首行事件都 >= startTs：窗口起点早于全部可用 binlog → 从最旧文件开始（无遗漏）
    $oldest = (string) ($rows[0]['File_name'] ?? $rows[0]['Log_name'] ?? '');
    return [$oldest === '' ? '' : $oldest, 4, '起点早于全部可用 binlog，从最旧文件开始'];
}

/**
 * 探测单个文件：从 pos 4 起消费事件流，找到第一个行事件并比较时间戳。
 * 返回 'pick'（首行事件 ts < startTs，该文件为起点）或 'older'（需取更旧文件）。
 */
function dms_probe_one(string $host, int $port, string $user, string $password, string $file, int $startTs): string
{
    $probe = new class extends EventSubscribers {
        public string $verdict = 'empty';
        public int $startTs = 0;

        // 文件头先发 ROTATE 定位事件，不参与判定
        public function onRotate(RotateDTO $event): void
        {
        }

        public function onWrite(WriteRowsDTO $event): void
        {
            $this->verdictRow($event->getEventInfo());
        }

        public function onUpdate(UpdateRowsDTO $event): void
        {
            $this->verdictRow($event->getEventInfo());
        }

        public function onDelete(DeleteRowsDTO $event): void
        {
            $this->verdictRow($event->getEventInfo());
        }

        private function verdictRow($eventInfo): void
        {
            if ($this->verdict !== 'empty') {
                return;
            }
            $ts = (int) ($eventInfo->timestamp ?? 0);
            $this->verdict = $ts < $this->startTs ? 'pick' : 'older';
        }
    };
    $probe->startTs = $startTs;

    $factory = new MySQLReplicationFactory(
        (new ConfigBuilder())
            ->withUser($user)
            ->withHost($host)
            ->withPassword($password)
            ->withPort($port)
            ->withBinLogFileName($file)
            ->withBinLogPosition('4')
            ->withHeartbeatPeriod(2) // 空文件时快速解除阻塞读
            ->withEventsOnly([
                // V1 行事件必须订阅：log_bin_use_v1_row_events=ON 的实例（如阿里云 RDS）
                // 发 23/24/25，只订 V2 会被 ignoreEvent 静默丢弃 → 永远 0 变更
                ConstEventType::WRITE_ROWS_EVENT_V1->value,
                ConstEventType::UPDATE_ROWS_EVENT_V1->value,
                ConstEventType::DELETE_ROWS_EVENT_V1->value,
                ConstEventType::WRITE_ROWS_EVENT_V2->value,
                ConstEventType::UPDATE_ROWS_EVENT_V2->value,
                ConstEventType::DELETE_ROWS_EVENT_V2->value,
                ConstEventType::ROTATE_EVENT->value,
            ])
            ->build()
    );
    $factory->registerSubscriber($probe);
    $t0 = microtime(true);
    try {
        $factory->runWithStopCheck(function () use ($probe, $t0): bool {
            return $probe->verdict !== 'empty' || (microtime(true) - $t0) > 8;
        });
    } catch (\Throwable $e) {
        fwrite(STDERR, 'dump_worker: 探测 ' . $file . ' 失败: ' . $e->getMessage() . "\n");
        return 'older';
    }
    return $probe->verdict === 'pick' ? 'pick' : 'older';
}

/** 取 binlog 文件第 N 个事件（0 起）的 Pos（事件边界）；N ≥ 总事件数时返回 '' */
function dms_show_event_pos(\PDO $pdo, string $file, int $offset): string
{
    $row = $pdo->query("SHOW BINLOG EVENTS IN '" . $file . "' LIMIT " . $offset . ', 1')->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? (string) ($row['Pos'] ?? '') : '';
}

/**
 * 指数搜索 + 二分：找第一个 Pos >= $targetByte 的事件边界（SHOW BINLOG EVENTS 每次 ~0.4-0.6s）。
 * @return string 边界 Pos（'' = 查询失败）
 */
function dms_binlog_boundary_near(\PDO $pdo, string $file, int $targetByte, float $deadline): string
{
    try {
        // 指数搜索上界：hi 处事件 Pos >= target，或 hi 越过 EOF
        $hi = 0;
        $step = 100000;
        while (microtime(true) < $deadline - 8) {
            $pos = dms_show_event_pos($pdo, $file, $hi);
            if ($pos === '') {
                break; // hi 已越过 EOF（上界 = 最后一个事件）
            }
            if ((int) $pos >= $targetByte) {
                break; // hi 已是上界
            }
            $hi += $step;
            $step *= 2;
        }
        // 二分：[0, hi] 内第一个 Pos >= targetByte
        $lo = 0;
        $up = $hi;
        while ($lo < $up && microtime(true) < $deadline - 3) {
            $mid = intdiv($lo + $up, 2);
            $mpos = dms_show_event_pos($pdo, $file, $mid);
            if ($mpos === '') {
                $up = $mid; // mid 已超 EOF，答案在 [lo, mid)
                continue;
            }
            if ((int) $mpos >= $targetByte) {
                $up = $mid;
            } else {
                $lo = $mid + 1;
            }
        }
        $bpos = dms_show_event_pos($pdo, $file, $lo);
        return $bpos !== '' ? $bpos : '4';
    } catch (\Throwable $e) {
        return '';
    }
}

/**
 * krowinski 轻量读：从 $fromPos 起只订阅 XID/ROTATE（行事件被 ignoreEvent 跳过、不解析，
 * 内存平稳），跳到「第一个提交 ts >= startTs 的 XID」，返回该事务的起点读位
 * （= 上一个 XID 的 next-position）。
 * @return array{0:string,1:string,2:bool,3:bool} [file, pos, hit, expand]；
 *              expand=true 表示命中的是读入范围内首个 XID（其事务可能始于 $fromPos 之前，
 *              调用方应扩大 margin 重试；$fromPos=4 时 expand 恒为 false）。
 */
function dms_krowinski_light_find(string $host, int $port, string $user, string $password, string $file, string $fromPos, int $startTs, int $timeoutSec = 25): array
{
    $probe = new class extends EventSubscribers {
        public string $verdict = 'pending'; // pending | found
        public int $startTs = 0;
        public string $curFile = '';
        public int $prevXidPos = 0;
        public int $xidSeen = 0;
        public int $fromPos = 0;
        public string $foundFile = '';
        public string $foundPos = '';

        public function onXid(XidDTO $event): void
        {
            if ($this->verdict !== 'pending') {
                return;
            }
            $this->xidSeen++;
            $info = $event->getEventInfo();
            if ($info->timestamp >= $this->startTs) {
                // 本事务的行事件位于上个 XID 与当前 XID 之间；从上个 XID 边界读保证不漏。
                $this->foundFile = $this->curFile;
                $this->foundPos = $this->xidSeen === 1 ? (string) $this->fromPos : (string) $this->prevXidPos;
                $this->verdict = 'found';
            }
            $this->prevXidPos = (int) $info->pos;
        }

        public function onRotate(RotateDTO $event): void
        {
            if ($event->nextBinlog !== '') {
                $this->curFile = $event->nextBinlog;
                $this->prevXidPos = 0; // 新文件从 4 开始
            }
        }
    };
    $probe->startTs = $startTs;
    $probe->curFile = $file;
    $probe->fromPos = (int) $fromPos;
    // 首个 XID 命中时，其事务起点 = 读入起点 $fromPos（而非文件头 4）
    $probe->prevXidPos = (int) $fromPos;

    $factory = new MySQLReplicationFactory(
        (new ConfigBuilder())
            ->withUser($user)
            ->withHost($host)
            ->withPassword($password)
            ->withPort($port)
            ->withBinLogFileName($file)
            ->withBinLogPosition($fromPos)
            ->withHeartbeatPeriod(2) // 空闲时心跳到达，给 stop check 机会运行
            ->withEventsOnly([
                ConstEventType::XID_EVENT->value,
                ConstEventType::ROTATE_EVENT->value,
            ])
            ->build()
    );
    $factory->registerSubscriber($probe);
    $t0 = microtime(true);
    try {
        $factory->runWithStopCheck(static function () use ($probe, $t0, $timeoutSec): bool {
            return $probe->verdict !== 'pending' || (microtime(true) - $t0) > $timeoutSec;
        });
    } catch (\Throwable $e) {
        fwrite(STDERR, 'dump_worker: 探针轻读失败: ' . $e->getMessage() . "\n");
        return [$file, '', false, false];
    }
    $hit = $probe->verdict === 'found';
    // 首个 XID 命中（xidSeen==1 且 fromPos>4）：该事务可能始于 fromPos 之前 → 需扩大 margin；
    // fromPos=4（整文件）时 4 即最早起点，无需扩大。
    $expand = $hit && $probe->xidSeen === 1 && $probe->fromPos > 4;
    return [$probe->foundFile, $probe->foundPos, $hit, $expand];
}

/**
 * 提交点轻量探针 v2（两阶段定位·阶段 1）：
 * ① PDO 二分（SHOW BINLOG EVENTS LIMIT n,1）找「≈ P_now - margin」的事件边界（不传行数据）
 * ② krowinski 从该边界轻读（只订 XID/ROTATE，行事件不解析，内存平稳），
 *    跳到「第一个提交 ts >= startTs 的事务」→ 返回其起点读位（阶段 2 从那里完整读，跳过历史段）。
 * margin 逐级扩大（4MB → 32MB → 整文件）；hit=false（失败/窗口无提交点）→ 调用方回退「文件头全扫」。
 */
function dms_probe_window_pos(string $host, int $port, string $user, string $password, string $file, int $startTs, int $timeoutSec = 90): array
{
    // binlog 文件名白名单校验（防 SQL 拼接注入）
    if (preg_match('/^[A-Za-z0-9_.-]+$/', $file) !== 1) {
        return [$file, '', false];
    }
    try {
        $pdo = new PDO(
            "mysql:host={$host};port={$port};dbname=mysql",
            $user,
            $password,
            [PDO::ATTR_TIMEOUT => 5, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    } catch (\Throwable $e) {
        fwrite(STDERR, 'dump_worker: 探针连接失败: ' . $e->getMessage() . "\n");
        return [$file, '', false];
    }
    // 当前文件写入位置（8.0 用 SHOW BINARY LOG STATUS，5.7 回退 SHOW MASTER STATUS；
    // 两条分开 try，避免 5.7 的语法异常吞掉回退）
    $master = [];
    try {
        $master = $pdo->query('SHOW BINARY LOG STATUS')->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        $master = [];
    }
    if ($master === []) {
        try {
            $master = $pdo->query('SHOW MASTER STATUS')->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            fwrite(STDERR, 'dump_worker: 探针取当前位置失败: ' . $e->getMessage() . "\n");
            return [$file, '', false];
        }
    }
    $posNow = (int) ($master['Position'] ?? $master['position'] ?? 0);
    if ($posNow < 8) {
        return [$file, '', false];
    }
    $deadline = microtime(true) + $timeoutSec;
    // margin 起跳尽量小（轻读网络量 = margin）；窗口数据更大时 expand 逐级重试：
    // 256KB（多数场景窗口数据 < 此值，轻读 ~1s）→ 2MB → 16MB → 整文件
    foreach ([262144, 2097152, 16777216, 0] as $margin) {
        if (microtime(true) > $deadline) {
            break;
        }
        $target = $margin > 0 ? max(4, $posNow - $margin) : 4;
        $boundary = dms_binlog_boundary_near($pdo, $file, $target, $deadline);
        if ($boundary === '') {
            continue;
        }
        fwrite(STDERR, 'dump_worker: 探针 margin=' . ($margin > 0 ? (int) ($margin / 1024) . 'KB' : '整文件') . " 边界 pos={$boundary}（P_now={$posNow}）\n");
        [$wFile, $wPos, $hit, $expand] = dms_krowinski_light_find($host, $port, $user, $password, $file, $boundary, $startTs);
        if ($hit && (!$expand || $margin === 0)) {
            return [$wFile, $wPos, true];
        }
        if ($margin === 0) {
            break; // 整文件无窗口内提交点（窗口可能在更旧文件）→ 回退旧文件选择
        }
        // expand：命中的是读入范围内首个 XID，其事务可能始于 fromPos 之前 → 扩大 margin 重试
        if ($expand) {
            fwrite(STDERR, 'dump_worker: 探针 expand（窗口数据量 > 当前 margin，扩大重试）\n');
        }
    }
    return [$file, '', false];
}

/**
 * AOT 二进制的 main() 直接调用本函数，异常会穿过 C++ 调用栈；
 * embed 链路上 set_exception_handler 不生效（仍是 fatal 255），
 * 故在 PHP 函数边界内兜底捕获，保证 stderr 有可读原因、退出码明确。
 */
function dms_dump_worker_main(array $args): void
{
    try {
        dms_dump_worker_run($args);
    } catch (\Throwable $e) {
        fwrite(STDERR, 'dump_worker: 未捕获异常: ' . $e->getMessage()
            . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n");
        exit(2);
    }
}

function dms_dump_worker_run(array $args): void
{
    // 历史窗口会把起点定位到 binlog 文件头（pos=4），顺序解析整段历史；
    // krowinski 的 RowEvent DTO 会在默认 128M 上限处耗尽 → fatal → 子进程 255。
    // 显式放宽，不依赖部署时的 php.ini（Windows 组包 / macOS 均生效）。
    @ini_set('memory_limit', '1024M');

    // 开发模式（php bin/dump_worker.php 直跑）时补 autoload；embed 子命令路径已由
    // src/DumpWorker 预载，重复 require 对 composer autoload 无害
    if (!class_exists('MySQLReplication\\MySQLReplicationFactory')) {
        $autoload = __DIR__ . '/../vendor/autoload.php';
        if (is_file($autoload)) {
            require_once $autoload;
        }
    }

    $opts = dms_parse_args($args);

    $host = (string) ($opts['host'] ?? '127.0.0.1');
    $port = (int) ($opts['port'] ?? 3306);
    $user = (string) ($opts['user'] ?? 'root');
    // 密码走环境变量（避免出现在命令行/进程列表），未显式传 --password 时读取
    $password = (string) ($opts['password'] ?? getenv('DMS_MYSQL_PASSWORD') ?? '');
    $file = (string) ($opts['file'] ?? '');
    $pos = (string) ($opts['pos'] ?? '4');
    $dbFilter = (string) ($opts['db'] ?? '');   // 仅输出该库的事件（空 = 全部）
    $max = (int) ($opts['max'] ?? 0);           // 最多输出 N 行 change（0 = 不限，用于测试）
    $startTs = (int) ($opts['start-ts'] ?? 0);  // 早于该时刻（epoch 秒）的行事件跳过；>0 时自动定位起点文件
    $endTs = (int) ($opts['end-ts'] ?? 0);      // 越过该时刻（epoch 秒）后退出

    if ($file === '') {
        fwrite(STDERR, "dump_worker: --file 必填（或由 --start-ts 自动定位）\n");
        exit(2);
    }

    // ---- 窗口起点定位（仅 startTs>0）：两阶段，先 XID 轻量探针、未命中再回退全扫 ----
    if ($startTs > 0) {
        // ① XID/提交点轻量探针：SHOW BINLOG EVENTS 分页服务端扫描（不转移行数据），
        //    命中 → 阶段 2 直接从该事务起点读，跳过历史段；未命中回退全扫。
        [$wFile, $wPos, $wHit] = dms_probe_window_pos($host, $port, $user, $password, $file, $startTs);
        if ($wHit) {
            if ($wFile !== '') {
                $file = $wFile;
            }
            $pos = $wPos;
            fwrite(STDERR, "dump_worker: XID 探针命中，起点 {$file} pos={$pos}（跳过历史段）\n");
        } else {
            // ② 回退：原文件选择 + 文件头全扫（慢但正确；窗口更旧或探针超时时）
            fwrite(STDERR, 'dump_worker: XID 探针未命中，按 startTs=' . $startTs . " 定位起点文件...\n");
            $located = dms_probe_start_file($host, $port, $user, $password, $startTs);
            $file = (string) $located[0];
            $pos = (string) $located[1];
            $why = (string) $located[2];
            if ($file === '') {
                fwrite(STDERR, "dump_worker: 定位失败（{$why}）\n");
                exit(2);
            }
            fwrite(STDERR, "dump_worker: 起点文件 {$file} pos={$pos}（{$why}，全扫历史段）\n");
        }
    }

    $binLogStream = new MySQLReplicationFactory(
        (new ConfigBuilder())
            ->withUser($user)
            ->withHost($host)
            ->withPassword($password)
            ->withPort($port)
            ->withBinLogFileName($file)
            ->withBinLogPosition((string) $pos)
            // 心跳 5s：历史窗口越过 endTs 后无新行事件时，靠心跳时间戳兜底退出（60s 太久）
            ->withHeartbeatPeriod(5)
            ->withEventsOnly([
                // V1 行事件必须订阅：log_bin_use_v1_row_events=ON 的实例（如阿里云 RDS）
                // 发 23/24/25，只订 V2 会被 ignoreEvent 静默丢弃 → 永远 0 变更
                ConstEventType::WRITE_ROWS_EVENT_V1->value,
                ConstEventType::UPDATE_ROWS_EVENT_V1->value,
                ConstEventType::DELETE_ROWS_EVENT_V1->value,
                ConstEventType::WRITE_ROWS_EVENT_V2->value,
                ConstEventType::UPDATE_ROWS_EVENT_V2->value,
                ConstEventType::DELETE_ROWS_EVENT_V2->value,
                ConstEventType::XID_EVENT->value,
                ConstEventType::ROTATE_EVENT->value,
                ConstEventType::HEARTBEAT_LOG_EVENT->value,
            ])
            ->build()
    );

    $state = new class($dbFilter, $max, $startTs, $endTs, $file) {
        /** 待输出行（当前事务缓冲：XID 事件在行之后，需等 XID 赋号） */
        public array $pending = [];
        public string $binlogFile;

        public function __construct(
            public string $dbFilter,
            public int $max,
            public int $startTs,
            public int $endTs,
            string $initialFile,
            public int $emitted = 0,
        ) {
            $this->binlogFile = $initialFile;
        }
    };

    $binLogStream->registerSubscriber(
        new class($state) extends EventSubscribers {
            public function __construct(private object $st)
            {
            }

            public function onXID(XidDTO $event): void
            {
                // 缓冲的当前事务行在此赋 xid 后输出（含时间窗口过滤）
                $st = $this->st;
                $xid = (int) $event->xid;
                foreach ($st->pending as $row) {
                    // 早于窗口起点：跳过不输出
                    if ($st->startTs > 0 && $row['timestamp'] < $st->startTs) {
                        continue;
                    }
                    // 越过窗口终点：历史窗口不会再有新事件，正常退出
                    if ($st->endTs > 0 && $row['timestamp'] > 0 && $row['timestamp'] > $st->endTs) {
                        exit(0);
                    }
                    $row['xid'] = $xid;
                    dms_emit($row);
                    $st->emitted++;
                    if ($st->max > 0 && $st->emitted >= $st->max) {
                        exit(0);
                    }
                }
                $st->pending = [];
            }

            public function onRotate(RotateDTO $event): void
            {
                $next = (string) $event->nextBinlog;
                if ($next !== '') {
                    $this->st->binlogFile = $next;
                }
            }

            public function onWrite(WriteRowsDTO $event): void
            {
                $this->collectRow('insert', $event->tableMap, $event->values, $event->getEventInfo());
            }

            public function onUpdate(UpdateRowsDTO $event): void
            {
                $this->collectRow('update', $event->tableMap, $event->values, $event->getEventInfo());
            }

            public function onDelete(DeleteRowsDTO $event): void
            {
                $this->collectRow('delete', $event->tableMap, $event->values, $event->getEventInfo());
            }

            private function collectRow(string $kind, $tableMap, array $rows, $eventInfo): void
            {
                $st = $this->st;
                // 窗口起点之前的历史行：直接丢弃，不构造 before/after 镜像、不进 pending。
                // 否则扫描数月历史 binlog 时内存与耗时都随历史量线性增长。
                $ts = (int) ($eventInfo->timestamp ?? 0);
                if ($st->startTs > 0 && $ts > 0 && $ts < $st->startTs) {
                    return;
                }
                $schema = (string) ($tableMap->database ?? '');
                $table = (string) ($tableMap->table ?? '');
                if ($st->dbFilter !== '' && $schema !== $st->dbFilter) {
                    return;
                }
                // 主键列：由 krowinski 通过 INFORMATION_SCHEMA 补全的字段元数据判定（COLUMN_KEY='PRI'）
                $pks = [];
                $colDTOs = $tableMap->columnDTOCollection ?? null;
                if (is_iterable($colDTOs)) {
                    foreach ($colDTOs as $colDTO) {
                        if (is_object($colDTO) && method_exists($colDTO, 'isPrimary') && $colDTO->isPrimary()) {
                            $pks[] = $colDTO->getName();
                        }
                    }
                }
                $columns = [];
                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $sample = isset($row['before']) && is_array($row['before']) ? $row['before'] : $row;
                    foreach (array_keys($sample) as $col) {
                        if (!in_array($col, $columns, true)) {
                            $columns[] = (string) $col;
                        }
                    }
                }
                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $before = isset($row['before']) ? (array) $row['before'] : ($kind === 'delete' ? $row : null);
                    $after = isset($row['after']) ? (array) $row['after'] : ($kind === 'insert' ? $row : null);
                    $st->pending[] = [
                        'type' => 'change',
                        'kind' => $kind,
                        'schema' => $schema,
                        'table' => $table,
                        'columns' => $columns,
                        'primaryKeys' => $pks,
                        'before' => $before,
                        'after' => $after,
                        'xid' => 0, // 由紧随其后的 XID 事件赋号
                        'timestamp' => (int) ($eventInfo->timestamp ?? 0),
                        'binlogFile' => $st->binlogFile,
                        'binlogPos' => (int) ($eventInfo->pos ?? 0),
                    ];
                }
            }

            public function onHeartbeat($event): void
            {
                $st = $this->st;
                dms_emit(['type' => 'heartbeat', 'ts' => (int) (microtime(true) * 1000)]);
                // 兜底退出：心跳 = binlog 空闲（已追平当前文件尾）。krowinski 心跳事件的
                // eventInfo->timestamp 恒为 0（库实现如此），故用本地时间判断：
                // 窗口终点已过去（endTs+5s 缓冲）→ 不会再有窗口内新事件，正常退出
                if ($st->endTs > 0 && time() > $st->endTs + 5) {
                    exit(0);
                }
            }
        }
    );

    // 同步阻塞循环：父进程负责 kill（proc_terminate / taskkill），stdout 关闭/断连时由 agent 侧
    // teardownDump 终止本进程，不在此另设看门狗
    try {
        $binLogStream->run();
    } catch (\Throwable $e) {
        fwrite(STDERR, 'dump_worker: ' . $e->getMessage() . "\n");
        exit(1);
    }
}
