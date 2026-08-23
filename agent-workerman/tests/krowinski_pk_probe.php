<?php
// 实地验证：用 krowinski 拉取最近的 update 事件，检查 primaryKeys 是否正确提取
require __DIR__ . '/../vendor/autoload.php';

use MySQLReplication\Config\ConfigBuilder;
use MySQLReplication\Definitions\ConstEventType;
use MySQLReplication\Event\DTO\UpdateRowsDTO;
use MySQLReplication\Event\DTO\WriteRowsDTO;
use MySQLReplication\Event\EventSubscribers;
use MySQLReplication\MySQLReplicationFactory;

$factory = new MySQLReplicationFactory(
    (new ConfigBuilder())
        ->withUser('root')
        ->withHost('127.0.0.1')
        ->withPassword('root')
        ->withPort(3306)
        ->withBinLogFileName('binlog.000042')
        ->withBinLogPosition('4')
        ->withHeartbeatPeriod(1)
        ->withEventsOnly([
            ConstEventType::WRITE_ROWS_EVENT_V2->value,
            ConstEventType::UPDATE_ROWS_EVENT_V2->value,
            ConstEventType::DELETE_ROWS_EVENT_V2->value,
            ConstEventType::HEARTBEAT_LOG_EVENT->value,
        ])
        ->build()
);

$shown = 0;
$state = new class { public int $shown = 0; };

$factory->registerSubscriber(new class($state) extends EventSubscribers {
    public function __construct(private object $st) {}

    public function inspect($tableMap, array $rows, string $kind): void
    {
        $pks = [];
        foreach ($tableMap->columnDTOCollection as $colDTO) {
            if ($colDTO->isPrimary()) {
                $pks[] = $colDTO->getName();
            }
        }
        $schema = (string) ($tableMap->database ?? '');
        $table = (string) ($tableMap->table ?? '');
        echo "[{$kind}] {$schema}.{$table} primaryKeys=" . json_encode($pks, JSON_UNESCAPED_UNICODE) . " columns=" . json_encode($tableMap->columnDTOCollection->toArray() ? array_map(fn($c) => $c->getName() . ($c->isPrimary() ? '(PK)' : ''), $tableMap->columnDTOCollection->toArray()) : []) . PHP_EOL;
        $this->st->shown++;
    }

    public function onWrite(WriteRowsDTO $event): void { $this->inspect($event->tableMap, $event->values, 'write'); }
    public function onUpdate(UpdateRowsDTO $event): void { $this->inspect($event->tableMap, $event->values, 'update'); }
    public function onDelete($event): void { $this->inspect($event->tableMap, is_array($event->values ?? null) ? $event->values : [], 'delete'); }
});

$t0 = microtime(true);
try {
    $factory->runWithStopCheck(function () use ($state, $t0): bool {
        return $state->shown >= 10 || (microtime(true) - $t0) > 15;
    });
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . PHP_EOL;
}
echo "完成，共捕获 {$state->shown} 个行事件\n";
