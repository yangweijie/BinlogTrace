// useSchemaMeta.ts — 库/表级联元数据（演示静态表 / INFORMATION_SCHEMA 查询）
import { useCallback, useEffect, useRef, useState } from 'react';
import { getSession } from '../lib/session';
import { DEMO_DBS, DEMO_TABLES } from '../lib/demo-data';
import type { QueryResultPayload } from '../types/api';

export interface Option {
  value: string;
  label: string;
}

export function useSchemaMeta(demoMode: boolean) {
  const [dbOptions, setDbOptions] = useState<Option[]>([]);
  const [tableOptions, setTableOptions] = useState<Option[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  /** in-flight 去重：StrictMode 双挂载连发同一查询时只执行一次 */
  const loadingRef = useRef(false);

  const loadDatabases = useCallback(async () => {
    if (loadingRef.current) return;
    loadingRef.current = true;
    setLoading(true);
    setError('');
    if (demoMode) {
      setDbOptions(DEMO_DBS.map((d) => ({ value: d, label: d })));
      setLoading(false);
      return;
    }
    const session = getSession();
    if (!session) {
      setError('连接会话缺失，请返回连接页重新连接。');
      setLoading(false);
      return;
    }
    try {
      const res = await session.query<QueryResultPayload>('SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA ORDER BY SCHEMA_NAME');
      const colName = res.columns[0]?.name ?? 'SCHEMA_NAME';
      const dbs = res.rows
        .map((r) => String(r[colName] ?? ''))
        .filter((n) => n !== '' && !['information_schema', 'performance_schema', 'mysql', 'sys'].includes(n));
      setDbOptions(dbs.map((d) => ({ value: d, label: d })));
      if (dbs.length === 0) {
        setError('当前账号无权限查看数据库列表，请确认已授予 SELECT 权限。');
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : '数据库列表加载失败');
    } finally {
      loadingRef.current = false;
      setLoading(false);
    }
  }, [demoMode]);

  const loadTables = useCallback(async (dbName: string) => {
    setLoading(true);
    setError('');
    if (demoMode) {
      setTableOptions((DEMO_TABLES[dbName] ?? []).map((t) => ({ value: t, label: t })));
      setLoading(false);
      return;
    }
    const session = getSession();
    if (!session) {
      setLoading(false);
      return;
    }
    try {
      const res = await session.query<QueryResultPayload>(
        `SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA='${dbName.replace(/'/g, "''")}' ORDER BY TABLE_NAME`,
        dbName,
      );
      const colName = res.columns[0]?.name ?? 'TABLE_NAME';
      setTableOptions(
        res.rows
          .map((r) => String(r[colName] ?? ''))
          .filter((t) => t !== '')
          .map((t) => ({ value: t, label: t })),
      );
    } catch (err) {
      setError(err instanceof Error ? err.message : '数据表列表加载失败');
    } finally {
      setLoading(false);
    }
  }, [demoMode]);

  useEffect(() => {
    void loadDatabases();
  }, [loadDatabases]);

  return { dbOptions, tableOptions, loading, error, loadDatabases, loadTables };
}
