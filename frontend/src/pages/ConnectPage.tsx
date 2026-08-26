// ConnectPage.tsx — 连接页 `/`：连接表单 + 已存连接列表 + 测试连接 + 前置检查结果区（AC-01/02/13）
import { useState } from 'react';
import { Check, Eye, EyeOff, PlugZap, Trash2, Inbox } from 'lucide-react';
import TopBar from '../components/TopBar';
import Card from '../components/Card';
import Input from '../components/Input';
import Button from '../components/Button';
import Checkbox from '../components/Checkbox';
import EmptyState from '../components/EmptyState';
import CheckResultPanel from '../components/CheckResultPanel';
import { useAppDispatch, useAppState } from '../context/AppContext';
import { createSession } from '../lib/session';
import { checkConfig } from '../lib/parser-client';
import { loadConnections, upsertConnection, removeConnection, newId } from '../lib/storage';
import { navigate } from '../lib/route';
import { toCheckMeta } from '../lib/check-meta';
import type { SavedConnection, ConnectionForm } from '../types/api';
import type { DemoSimulateOptions } from '../lib/mock-agent';

const DEFAULT_FORM: ConnectionForm = {
  name: '',
  host: '127.0.0.1',
  port: '3306',
  user: '',
  password: '',
  database: '',
  saveLocally: true,
  useDemo: false,
};

function validate(form: ConnectionForm): Record<string, string> {
  const errs: Record<string, string> = {};
  if (!form.host.trim()) errs.host = '主机名必填';
  if (!form.user.trim()) errs.user = '用户名必填';
  const port = Number(form.port);
  if (!Number.isInteger(port) || port < 1 || port > 65535) errs.port = '端口需为 1–65535 的整数';
  return errs;
}

export default function ConnectPage() {
  const dispatch = useAppDispatch();
  const { wsStatus, wsError, checkResult } = useAppState();
  const [form, setForm] = useState<ConnectionForm>(DEFAULT_FORM);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saved, setSaved] = useState<SavedConnection[]>(() => loadConnections());
  const [testing, setTesting] = useState(false);
  const [testOk, setTestOk] = useState(false);
  const [showPwd, setShowPwd] = useState(false);
  const [demoSim, setDemoSim] = useState<DemoSimulateOptions>({});

  const set = (key: keyof ConnectionForm, value: string | boolean): void => {
    setForm((f) => ({ ...f, [key]: value }));
  };

  const fieldError = (key: string): string => errors[key] ?? '';

  const doConnect = async (opts: { save: boolean; thenNavigate: boolean }): Promise<void> => {
    // 演示模式无需真实凭证，跳过必填校验
    if (!form.useDemo) {
      const errs = validate(form);
      setErrors(errs);
      if (Object.keys(errs).length > 0) {
        dispatch({ type: 'setStatus', status: 'error', error: '请修正表单中的必填项。' });
        return;
      }
    }
    setTesting(true);
    setTestOk(false);
    dispatch({ type: 'setStatus', status: 'connecting', error: null });
    try {
      const session = createSession(form.useDemo, demoSim);
      const meta = await session.agent.connect({
        host: form.host.trim(),
        port: Number(form.port),
        user: form.user.trim(),
        password: form.password,
        database: form.database.trim() || undefined,
      });
      // 同名本地连接覆盖而非追加：先按 name 查找已存条目的 id，有则复用
      const connName = form.name.trim() || `${form.host.trim()}:${form.port}`;
      const existingId = saved.find((c) => c.name === connName)?.id;
      const connection: SavedConnection = {
        id: existingId ?? newId(),
        name: connName,
        host: form.host.trim(),
        port: Number(form.port),
        user: form.user.trim(),
        database: form.database.trim() || undefined,
      };
      dispatch({ type: 'setStatus', status: 'connected', error: null });
      dispatch({ type: 'setConnection', connection, meta, demoMode: form.useDemo });
      const result = await checkConfig(toCheckMeta(meta));
      dispatch({ type: 'setCheck', result });
      setTestOk(true);
      if (opts.save && form.saveLocally) {
        setSaved(upsertConnection({ ...connection, password: form.password || undefined }));
      }
      if (opts.thenNavigate) navigate('/trace');
    } catch (err) {
      dispatch({ type: 'setStatus', status: 'error', error: err instanceof Error ? err.message : String(err) });
      setTestOk(false);
    } finally {
      setTesting(false);
    }
  };

  const runSaved = (conn: SavedConnection): void => {
    setForm({
      name: conn.name,
      host: conn.host,
      port: String(conn.port),
      user: conn.user,
      password: conn.password ?? '',
      database: conn.database ?? '',
      saveLocally: false,
      useDemo: false,
    });
    void doConnect({ save: false, thenNavigate: true });
  };

  return (
    <div>
      <TopBar status={wsStatus === 'connected' ? 'connected' : wsStatus === 'error' ? 'error' : 'idle'} />
      <main className="page">
        <div className="connect-grid">
          <Card title="新建连接">
            <div className="form-row">
              <Input label="连接名称" value={form.name} onChange={(e) => set('name', e.target.value)} placeholder="例如 prod-db" />
              <Input label="数据库（可选）" value={form.database} onChange={(e) => set('database', e.target.value)} placeholder="留空则追踪前选择" />
            </div>
            <div className="form-row">
              <Input
                label="主机"
                value={form.host}
                invalid={Boolean(fieldError('host'))}
                error={fieldError('host')}
                onChange={(e) => set('host', e.target.value)}
                placeholder="127.0.0.1"
              />
              <Input
                label="端口"
                value={form.port}
                invalid={Boolean(fieldError('port'))}
                error={fieldError('port')}
                onChange={(e) => set('port', e.target.value)}
                className="num"
                inputMode="numeric"
              />
            </div>
            <div className="form-row">
              <Input
                label="用户"
                value={form.user}
                invalid={Boolean(fieldError('user'))}
                error={fieldError('user')}
                onChange={(e) => set('user', e.target.value)}
                placeholder="root"
              />
              <Input
                label="密码"
                type={showPwd ? 'text' : 'password'}
                value={form.password}
                onChange={(e) => set('password', e.target.value)}
                rightSlot={
                  <button type="button" className="input-toggle" aria-label={showPwd ? '隐藏密码' : '显示密码'} onClick={() => setShowPwd((v) => !v)}>
                    {showPwd ? <EyeOff size={16} aria-hidden="true" /> : <Eye size={16} aria-hidden="true" />}
                  </button>
                }
              />
            </div>
            <div style={{ display: 'flex', gap: 'var(--spacing-md)', alignItems: 'center', flexWrap: 'wrap', marginBottom: 'var(--spacing-md)' }}>
              <Checkbox label="保存到本地" checked={form.saveLocally} onChange={(v) => set('saveLocally', v)} />
              <Checkbox label="演示模式（无代理）" checked={form.useDemo} onChange={(v) => set('useDemo', v)} />
            </div>
            {form.useDemo ? (
              <div style={{ display: 'flex', gap: 'var(--spacing-sm)', flexWrap: 'wrap', marginBottom: 'var(--spacing-md)' }}>
                <Checkbox label="模拟权限缺失" checked={Boolean(demoSim.simulatePermMissing)} onChange={(v) => setDemoSim((s) => ({ ...s, simulatePermMissing: v }))} />
                <Checkbox label="binlog_format=MIXED" checked={Boolean(demoSim.simulateFormatMixed)} onChange={(v) => setDemoSim((s) => ({ ...s, simulateFormatMixed: v }))} />
                <Checkbox label="row_image=MINIMAL" checked={Boolean(demoSim.simulateRowImageMinimal)} onChange={(v) => setDemoSim((s) => ({ ...s, simulateRowImageMinimal: v }))} />
                <Checkbox label="未开启 Binlog" checked={Boolean(demoSim.simulateNoBinlog)} onChange={(v) => setDemoSim((s) => ({ ...s, simulateNoBinlog: v }))} />
              </div>
            ) : null}
            <div className="form-actions">
              <Button variant="secondary" loading={testing} onClick={() => void doConnect({ save: false, thenNavigate: false })}>
                {testOk ? <Check size={16} aria-hidden="true" /> : null}
                {testOk ? '连接正常' : '测试连接'}
              </Button>
              <Button loading={testing} onClick={() => void doConnect({ save: true, thenNavigate: true })}>
                <PlugZap size={16} aria-hidden="true" />
                连接并追踪
              </Button>
            </div>
            {wsError ? <p className="field-error" style={{ marginTop: 'var(--spacing-sm)' }}>{wsError}</p> : null}
          </Card>

          <Card title="已存连接">
            {saved.length === 0 ? (
              <EmptyState
                icon={Inbox}
                title="还没有保存的连接"
                body="左侧填写表单并勾选「保存到本地」后，下次从这里一键进入。"
              />
            ) : (
              saved.map((conn) => (
                <div key={conn.id} className="saved-item">
                  <div>
                    <div className="saved-item-name">{conn.name}</div>
                    <div className="saved-item-sub">
                      {conn.host}:{conn.port}
                      {conn.database ? ` / ${conn.database}` : ''}
                    </div>
                  </div>
                  <div className="saved-item-actions">
                    <button type="button" className="link-btn" onClick={() => runSaved(conn)}>
                      连接
                    </button>
                    <button
                      type="button"
                      className="btn btn-danger-ghost"
                      style={{ padding: 'var(--spacing-xs)' }}
                      aria-label={`删除 ${conn.name}`}
                      onClick={() => setSaved(removeConnection(conn.id))}
                    >
                      <Trash2 size={16} aria-hidden="true" />
                    </button>
                  </div>
                </div>
              ))
            )}
          </Card>
        </div>

        {checkResult ? (
          <div style={{ marginTop: 'var(--spacing-lg)' }}>
            <CheckResultPanel result={checkResult} />
          </div>
        ) : null}
      </main>
    </div>
  );
}
