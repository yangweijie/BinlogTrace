<?php

declare(strict_types=1);

/**
 * krowinski_dump.php — 独立子进程：用 krowinski/php-mysql-replication 库解析 binlog，
 * 将行事件以 JSON 行输出到 stdout（每行一个结构化变更），由 agent 转发给前端。
 *
 * 纯 PHP 运行，无需 TypePHP 编译器；krowinski 为同步阻塞库，故以独立进程承载，
 * 不阻塞 Workerman 事件循环。
 *
 * 用法：
 *   php bin/krowinski_dump.php --host 127.0.0.1 --port 3306 --user root \
 *       --file binlog.000042 --pos 4 [--max 100] [--db test] \
 *       [--start-ts 1787400000] [--end-ts 1787410000]
 *
 * 时间窗口（epoch 秒，0 = 不限；「选时间点搜历史变更」语义）：
 *   --start-ts  早于该时刻的行事件跳过不输出；worker 还负责定位起点文件：
 *               PDO 查 SHOW BINARY LOGS 后从新到旧探测——每个候选文件只读
 *               「首个行事件 / 首个 ROTATE」：首行事件时间戳 >= startTs → 取上一
 *               个更旧文件；< startTs（或文件无行事件）→ 该文件即起点（pos 4，
 *               窗口内行由 --start-ts 过滤保证不漏）。
 *               简化：不做文件内二分精确定位（首事件晚于 startTs 时会多读一个
 *               更旧文件，多读部分同样被 start-ts 过滤，无正确性影响）。
 *   --end-ts    行事件越过该时刻即正常退出；无行事件时用心跳（服务器当前时间）
 *               越过 endTs+5s 兜底退出（endTs 已过去的历史窗口不会再有新事件）
 *
 * 输出行（stdout，每行一个 JSON）：
 *   {"type":"change","kind":"insert|update|delete","schema":"test","table":"order",
 *    "columns":["id",...],"primaryKeys":["id"],"before":{...}|null,"after":{...}|null,
 *    "xid":123,"timestamp":1787414464,"binlogFile":"binlog.000042","binlogPos":472}
 *   {"type":"heartbeat","ts":...}
 * 错误写 stderr。
 *
 * 退出条件：越过 endTs / 达到 --max / stdout 管道关闭（看门狗，防 agent 被杀后孤儿）。
 */

$opts = getopt('', [
    'host:', 'port:', 'user:', 'password:', 'file:', 'pos:', 'db:', 'max:', 'start-ts:', 'end-ts:',
]);

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
    fwrite(STDERR, "krowinski_dump: --file 必填（或由 --start-ts 自动定位）\n");
    exit(2);
}

require __DIR__ . '/../vendor/autoload.php';

use MySQLReplication\Config\ConfigBuilder;
use MySQLReplication\Definitions\ConstEventType;
use MySQLReplication\Event\DTO\DeleteRowsDTO;
use MySQLReplication\Event\DTO\RotateDTO;
use MySQLReplication\Event\DTO\UpdateRowsDTO;
use MySQLReplication\Event\DTO\WriteRowsDTO;
use MySQLReplication\Event\DTO\XidDTO;
use MySQLReplication\Event\EventSubscribers;
use MySQLReplication\MySQLReplicationFactory;

function emit(array $row): void
{
    static $startTime = null;
    if ($startTime === null) $startTime = microtime(true);
    $elapsed = (microtime(true) - $startTime) * 1000; // ms since first emit
    $json = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        fwrite(STDERR, 'krowinski_dump: json_encode 失败: ' . json_last_error_msg() . "\n");
        return;
    }
    fwrite(STDOUT, $json . "\n");
    flush();
    fwrite(STDERR, 'krowinski_dump: emit #' . ($row['xid'] ?? 0) . ' kind=' . ($row['kind'] ?? '?') . ' ts=' . ($row['timestamp'] ?? 0) . ' elapsed_ms=' . round($elapsed, 1) . "\n");
}

function probeStartFile(string $host, int $port, string $user, string $password, int $startTs): array
{
    // 返回 [文件, pos, 原因]
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
    // SHOW BINARY LOGS 按名升序（binlog.000040 < ... < binlog.000042，最后一个为当前文件）
    // 从新到旧探测：取「首个行事件 ts < startTs」的最新文件（窗口起点落在该文件或更早；
    // dump 从该文件 pos 4 开始会经 ROTATE 自动续读更新的文件，配合 --start-ts 过滤不漏）
    $files = array_reverse($rows);
    foreach ($files as $row) {
        $f = (string) ($row['File_name'] ?? $row['Log_name'] ?? '');
        if ($f === '') {
            continue;
        }
        $verdict = probeOne($host, $port, $user, $password, $f, $startTs);
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
 * 探测单个文件：dump 其 pos 4 起的事件流，找到第一个行事件并比较时间戳。
 * 返回 'pick'（首行事件 ts < startTs，该文件为起点）或 'older'（需取更旧文件）。
 */
function probeOne(string $host, int $port, string $user, string $password, string $file, int $startTs): string
{
    $probe = new class extends EventSubscribers {
        public string $verdict = 'empty';
        public int $startTs = 0;

        // 注意：dump 文件头时服务端先发 ROTATE 定位事件，故 ROTATE 不参与判定
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
            // 首个行事件：ts < startTs → 文件含窗口起点之前的数据 → pick；
            // 否则该文件全部晚于窗口起点 → 更旧文件（继续探测）
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
        fwrite(STDERR, 'krowinski_dump: 探测 ' . $file . ' 失败: ' . $e->getMessage() . "\n");
        return 'older';
    }
    return $probe->verdict === 'pick' ? 'pick' : 'older';
}

// ---- 起点文件定位（仅 startTs>0）----
if ($startTs > 0) {
    fwrite(STDERR, 'krowinski_dump: 按 startTs=' . $startTs . " 定位起点文件...\n");
    [$file, $pos, $why] = probeStartFile($host, $port, $user, $password, $startTs);
    if ($file === '') {
        fwrite(STDERR, "krowinski_dump: 定位失败（{$why}）\n");
        exit(2);
    }
    fwrite(STDERR, "krowinski_dump: 起点文件 {$file} pos={$pos}（{$why}）\n");
}

$binLogStream = new MySQLReplicationFactory(
    (new ConfigBuilder())
        ->withUser($user)
        ->withHost($host)
        ->withPassword($password)
        ->withPort($port)
        ->withBinLogFileName($file)
        ->withBinLogPosition((string) $pos)
        // 心跳 2s：历史窗口越过 endTs 后无新行事件时，靠心跳时间戳兜底退出。
        // 原 5s 导致即使无数据也需等 ~5s 才能结束 dump，用户体验为"至少卡 5 秒"。
        ->withHeartbeatPeriod(2)
        ->withEventsOnly([
            ConstEventType::WRITE_ROWS_EVENT_V2->value,
            ConstEventType::UPDATE_ROWS_EVENT_V2->value,
            ConstEventType::DELETE_ROWS_EVENT_V2->value,
            ConstEventType::XID_EVENT->value,
            ConstEventType::ROTATE_EVENT->value,
            ConstEventType::HEARTBEAT_LOG_EVENT->value,
        ])
        ->build()
);

$state = new class($dbFilter, $max, $startTs, $endTs) {
    /** 待输出行（当前事务缓冲：XID 事件在行之后，需等 XID 赋号） */
    public array $pending = [];
    public string $binlogFile;

    public function __construct(
        public string $dbFilter,
        public int $max,
        public int $startTs,
        public int $endTs,
        public int $emitted = 0,
    ) {
        $this->binlogFile = $GLOBALS['file'];
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
                if ($st->endTs > 0 && $row['timestamp'] > $st->endTs) {
                    exit(0);
                }
                $row['xid'] = $xid;
                emit($row);
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
                $sample = isset($row['before']) && is_array($row['before']) ? $row['before'] : ($kind === 'delete' ? $row : $row);
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
            emit(['type' => 'heartbeat', 'ts' => (int) (microtime(true) * 1000)]);
            // 兜底退出：心跳 = binlog 空闲（已追平当前文件尾）。krowinski 心跳事件
            // 的 eventInfo->timestamp 恒为 0（库实现如此），故用本地时间判断：
            // 窗口终点已过去（endTs+2s 缓冲）→ 不会再有窗口内新事件，正常退出
            if ($st->endTs > 0 && time() > $st->endTs + 2) {
                exit(0);
            }
        }
    }
);

// 同步阻塞循环：父进程负责 kill（proc_terminate / taskkill），stdout 关闭/断连时由 agent 侧
// teardownDumpWorker 终止本进程，不在此另设看门狗
try {
    $binLogStream->run();
} catch (\Throwable $e) {
    fwrite(STDERR, 'krowinski_dump: ' . $e->getMessage() . "\n");
    exit(1);
}
