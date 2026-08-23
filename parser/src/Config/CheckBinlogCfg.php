<?php

declare(strict_types=1);

namespace Typephp\BinlogParser\Config;

/** binlog 前置配置校验（AC-13；Spec v1.1 §13.1）
 *
 * 检测项：
 *   - hasBinlog=false            → error 1003（binlog 未开启）
 *   - binlogFormat != ROW        → error 1003
 *   - binlogRowImage = MINIMAL   → warning 1004（WHERE 全列精度降级）
 *   - binlogRowImage = NO        → error 1004（不记录行镜像）
 *   - 缺 SELECT                  → error 1004
 *   - 缺 REPLICATION SLAVE       → error 1004
 *   - 缺 REPLICATION CLIENT      → warning 1004
 *
 * 每项 error/warning 附带 fix 引导（kind: mycnf/grant/dynamic/tip + lines）。
 */
final class CheckBinlogCfg
{
    public static function check(array $meta): array
    {
        $errors = [];
        $warnings = [];

        $hasBinlog = (bool)($meta['hasBinlog'] ?? false);
        $binlogFormat = (string)($meta['binlogFormat'] ?? '');
        $binlogRowImage = (string)($meta['binlogRowImage'] ?? '');
        $privileges = (array)($meta['userPrivileges'] ?? []);

        // 1. binlog 是否开启
        if (!$hasBinlog) {
            $errors[] = [
                'code' => 1003,
                'message' => 'Binlog 未开启（log_bin=OFF 或无可用 binlog 文件）。',
                'fix' => [
                    'kind' => 'mycnf',
                    'title' => '在 my.cnf 开启 binlog',
                    'lines' => [
                        '[mysqld]',
                        'log_bin = mysql-bin',
                        'binlog_format = ROW',
                        'binlog_row_image = FULL',
                    ],
                    'note' => '修改后重启 MySQL 服务生效。',
                ],
            ];
        }

        // 2. binlog_format 必须为 ROW
        if ($binlogFormat !== 'ROW' && $binlogFormat !== '') {
            $errors[] = [
                'code' => 1003,
                'message' => 'binlog_format 当前为 ' . $binlogFormat . '，必须为 ROW。',
                'fix' => [
                    'kind' => 'dynamic',
                    'title' => '设置 binlog_format 为 ROW',
                    'lines' => [
                        'SET GLOBAL binlog_format = ROW;',
                    ],
                    'note' => '临时生效；持久化请写入 my.cnf 并重启。',
                ],
            ];
        }

        // 3. binlog_row_image
        if ($binlogRowImage === 'MINIMAL') {
            $warnings[] = [
                'code' => 1004,
                'message' => 'binlog_row_image=MINIMAL，WHERE 全列精度可能降级（部分列缺值）。',
                'fix' => [
                    'kind' => 'dynamic',
                    'title' => '设置 binlog_row_image 为 FULL',
                    'lines' => [
                        'SET GLOBAL binlog_row_image = FULL;',
                    ],
                    'note' => '推荐 FULL 以保证 WHERE 全列等值精度。',
                ],
            ];
        } elseif ($binlogRowImage === 'NO') {
            $errors[] = [
                'code' => 1004,
                'message' => 'binlog_row_image=NO，不记录行镜像，无法生成回滚 SQL。',
                'fix' => [
                    'kind' => 'dynamic',
                    'title' => '设置 binlog_row_image 为 FULL',
                    'lines' => [
                        'SET GLOBAL binlog_row_image = FULL;',
                    ],
                ],
            ];
        }

        // 4. 权限
        $hasSelect = self::hasPriv($privileges, 'SELECT');
        $hasReplicaSlave = self::hasPriv($privileges, 'REPLICATION SLAVE');
        $hasReplicaClient = self::hasPriv($privileges, 'REPLICATION CLIENT');

        if (!$hasSelect) {
            $errors[] = [
                'code' => 1004,
                'message' => '缺少 SELECT 权限，无法查询 INFORMATION_SCHEMA 补齐列元数据。',
                'fix' => [
                    'kind' => 'grant',
                    'title' => '授予 SELECT 权限',
                    'lines' => [
                        'GRANT SELECT ON *.* TO CURRENT_USER;',
                    ],
                ],
            ];
        }
        if (!$hasReplicaSlave) {
            $errors[] = [
                'code' => 1004,
                'message' => '缺少 REPLICATION SLAVE 权限，无法发起 binlog dump。',
                'fix' => [
                    'kind' => 'grant',
                    'title' => '授予 REPLICATION SLAVE 权限',
                    'lines' => [
                        'GRANT REPLICATION SLAVE ON *.* TO CURRENT_USER;',
                    ],
                ],
            ];
        }
        if (!$hasReplicaClient) {
            $warnings[] = [
                'code' => 1004,
                'message' => '缺少 REPLICATION CLIENT 权限，部分 MySQL 版本无法查看 binlog 状态。',
                'fix' => [
                    'kind' => 'grant',
                    'title' => '授予 REPLICATION CLIENT 权限',
                    'lines' => [
                        'GRANT REPLICATION CLIENT ON *.* TO CURRENT_USER;',
                    ],
                ],
            ];
        }

        return [
            'ok' => count($errors) === 0,
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    private static function hasPriv(array $privileges, string $name): bool
    {
        foreach ($privileges as $p) {
            if ((string)$p === $name) {
                return true;
            }
        }
        return false;
    }
}
