// check-cfg.ts — 前置检查（TS 参考实现，WASM 未就绪时回退；规则与 Spec §13.1 一致）
// AC-03（binlog_format=ROW 阻断）/ AC-04（row_image=FULL 警告降级）/ AC-13（权限逐项 GRANT 修复）

import type { CheckMetaInput } from '../types/binlog';
import type { CheckResult, CheckIssue, FixGuide } from '../types/api';

const REQUIRED_PRIVS = ['SELECT', 'REPLICATION SLAVE', 'REPLICATION CLIENT'];

function mycnfFix(title: string, lines: string[], note?: string): FixGuide {
  return { kind: 'mycnf', title, lines, note };
}

function grantFix(missing: string[]): FixGuide {
  return {
    kind: 'grant',
    title: 'GRANT 授权语句（替换「用户」「主机」为实际账号）',
    lines: [
      `GRANT SELECT, REPLICATION SLAVE, REPLICATION CLIENT ON *.* TO '用户'@'主机';`,
      'FLUSH PRIVILEGES;',
    ],
    note: `缺失权限：${missing.join('、')}。授权后需重新连接生效。`,
  };
}

export function checkBinlogCfg(meta: CheckMetaInput): CheckResult {
  const errors: CheckIssue[] = [];
  const warnings: CheckIssue[] = [];

  if (!meta.hasBinlog) {
    errors.push({
      code: 1003,
      message: '未检测到 Binlog，请确认 MySQL 已开启 log_bin 并设置了 server-id。',
      fix: mycnfFix(
        'my.cnf 修复配置',
        ['[mysqld]', 'server-id=1', 'log_bin=/var/log/mysql/mysql-bin.log'],
        '修改后重启 MySQL 服务生效。',
      ),
    });
  } else if (meta.binlogFormat !== 'ROW') {
    errors.push({
      code: 1003,
      message: `Binlog 配置不符合追踪要求：binlog_format 必须为 ROW（当前为 ${meta.binlogFormat}）。请修改 my.cnf 后重启 MySQL 再重试。`,
      fix: mycnfFix('my.cnf 修复配置', ['[mysqld]', 'binlog_format=ROW'], '追加后重启 MySQL 服务生效。'),
    });
  }

  if (meta.hasBinlog && meta.binlogRowImage !== 'FULL') {
    warnings.push({
      code: 1004,
      message: 'binlog_row_image=MINIMAL，回滚 WHERE 条件将缺少部分列，精度降级。建议设为 FULL 以获得完整回滚条件。',
      fix: {
        kind: 'dynamic',
        title: '修复指引',
        lines: ['SET GLOBAL binlog_row_image=FULL;', '# 持久化到 my.cnf：', 'binlog_row_image=FULL'],
        note: '动态设置立即生效；持久化需重启 MySQL。',
      },
    });
  }

  // userPrivileges 是原始 GRANT 语句（如 "GRANT SELECT, REPLICATION SLAVE,
  // REPLICATION CLIENT ON *.* TO 'u'@'%'" 或 "GRANT ALL PRIVILEGES ..."），
  // 不能做整串相等匹配，需逐权限判断其是否出现在任一授权语句中。
  const satisfiesPrivilege = (priv: string): boolean =>
    meta.userPrivileges.some((g) => {
      if (/ALL PRIVILEGES/i.test(g)) return true;
      const escaped = priv.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
      return new RegExp(`\\b${escaped}\\b`, 'i').test(g);
    });
  const missing = REQUIRED_PRIVS.filter((p) => !satisfiesPrivilege(p));
  if (missing.length > 0) {
    errors.push({
      code: 1004,
      message: `连接用户缺少权限：${missing.join('、')}。请逐项授予后重新连接。`,
      fix: grantFix(missing),
    });
  }

  return { ok: errors.length === 0, errors, warnings };
}
