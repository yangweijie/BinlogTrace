<?php
// 实地打印 krowinski DELETE 事件 values 的原始结构
require __DIR__ . '/../vendor/autoload.php';

use MySQLReplication\Config\ConfigBuilder;
use MySQLReplication\Definitions\ConstEventType;
use MySQLReplication\Event\DTO\DeleteRowsDTO;
use MySQLReplication\Event\EventSubscribers;
use MySQLReplication\MySQLReplicationFactory;

$factory = new MySQLReplicationFactory(
    (new ConfigBuilder())
        ->withUser('root')->withHost('127.0.0.1')->withPassword('root')->withPort(3306)
        ->withBinLogFileName('binlog.000042')->withBinLogPosition('4')->withHeartbeatPeriod(1)
        ->withEventsOnly([ConstEventType::WRITE_ROWS_EVENT_V2->value, ConstEventType::UPDATE_ROWS_EVENT_V2->value, ConstEventType::DELETE_ROWS_EVENT_V2->value, ConstEventType::HEARTBEAT_LOG_EVENT->value])
        ->build()
);

$state = new class { public int $n = 0; };
$factory->registerSubscriber(new class($state) extends EventSubscribers {
    public function __construct(private object $st) {}
    public function onDelete(DeleteRowsDTO $event): void {
        $rel = $event->tableMap->database . '.' . $event->tableMap->table;
        echo "[DELETE] {$rel}" . PHP_EOL;
        echo "  values = " . json_encode($event->values, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        echo "  rawRowCount = " . json_encode($event->getRawData() ? ($event->getRawData()['rowCount'] ?? '?') : '?') . PHP_EOL;
        $this->st->n++;
    }
    public function onWrite($event): void { $this->st->n++; $rel=$event->tableMap->database.'.'.$event->tableMap->table; if ($rel==='test.order') { echo "[WRITE test.order] values=".json_encode($event->values, JSON_PARTIAL_OUTPUT_ON_ERROR).PHP_EOL; } }
    public function onUpdate($event): void { $this->st->n++; }
});

$t0 = microtime(true);
try {
    $factory->runWithStopCheck(function () use ($state, $t0): bool {
        return $state->n >= 20 || (microtime(true) - $t0) > 12;
    });
} catch (\Throwable $e) { echo "ERR: " . $e->getMessage() . PHP_EOL; }
echo "n={$state->n}\n";
