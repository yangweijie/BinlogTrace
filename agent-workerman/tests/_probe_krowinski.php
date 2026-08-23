<?php
// 临时探针：用 krowinski/php-mysql-replication 库解析 binlog，验证能否正确解出
// test.order 的 UPDATE 行值（NEWDECIMAL/DATETIME2 等 WASM 手写解码失败的列）
// 用法：php tests/_probe_krowinski.php [binlogFile] [pos]
declare(strict_types=1);

$base = dirname(__DIR__);
require $base . '/vendor/autoload.php';

use MySQLReplication\Config\ConfigBuilder;
use MySQLReplication\Definitions\ConstEventType;
use MySQLReplication\Event\DTO\EventDTO;
use MySQLReplication\Event\EventSubscribers;
use MySQLReplication\MySQLReplicationFactory;

$file = $argv[1] ?? 'binlog.000042';
$pos = isset($argv[2]) ? (int) $argv[2] : 4;

$binLogStream = new MySQLReplicationFactory(
    (new ConfigBuilder())
        ->withUser('root')
        ->withHost('127.0.0.1')
        ->withPassword('root')
        ->withPort(3306)
        ->withBinLogFileName($file)
        ->withBinLogPosition((string) $pos)
        ->withHeartbeatPeriod(60)
        ->withEventsOnly([
            ConstEventType::WRITE_ROWS_EVENT_V2->value,
            ConstEventType::UPDATE_ROWS_EVENT_V2->value,
            ConstEventType::DELETE_ROWS_EVENT_V2->value,
            ConstEventType::TABLE_MAP_EVENT->value,
            ConstEventType::XID_EVENT->value,
        ])
        ->build()
);

$count = 0;
$binLogStream->registerSubscriber(
    new class($count) extends EventSubscribers {
        public int $n;

        public function __construct(int $n)
        {
            $this->n = &$n;
        }

        public function allEvents(EventDTO $event): void
        {
            $type = $event->getType();
            if ($type === 'write' || $type === 'update' || $type === 'delete') {
                $this->n++;
                echo '[ROW] ' . $type . ' ' . $event->tableMap->database . '.' . $event->tableMap->table
                    . ' changed=' . $event->changedRows . "\n";
                $values = $event->values;
                foreach ($values as $row) {
                    if (is_array($row)) {
                        echo '  row: ' . json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) . "\n";
                    } else {
                        echo '  row: ' . print_r($row, true) . "\n";
                    }
                }
                echo "---\n";
            } elseif ($type === 'table_map') {
                echo '[MAP] ' . $event->tableMap->database . '.' . $event->tableMap->table . "\n";
            }
            if ($this->n >= 6) {
                exit(0);
            }
        }
    }
);

// 非阻塞跑一段时间（库的 run() 是死循环，这里用流超时实现限时）
$binLogStream->runWithStopCheck(function (): bool {
    static $start = null;
    if ($start === null) {
        $start = microtime(true);
    }
    return microtime(true) - $start > 20;
});
echo "[done] collected {$count} rows\n";
