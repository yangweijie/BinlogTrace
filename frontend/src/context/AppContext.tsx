// AppContext.tsx — 全局状态（useReducer；连接/前置检查/变更/回滚）

import { createContext, useContext, useMemo, useReducer, type Dispatch, type ReactNode } from 'react';
import { getStoredAgentUrl } from '../components/AgentConfig';
import type { SavedConnection, ConnectedPayload, CheckResult, TraceConfig } from '../types/api';
import type { Change, RollbackResult } from '../types/binlog';

export interface AppState {
  wsStatus: 'idle' | 'connecting' | 'connected' | 'error';
  wsError: string | null;
  connection: SavedConnection | null;
  wsMeta: ConnectedPayload | null;
  checkResult: CheckResult | null;
  traceConfig: TraceConfig | null;
  changes: Change[] | null;
  parseStatus: 'idle' | 'parsing' | 'done' | 'error';
  parseError: string | null;
  rollback: RollbackResult | null;
  demoMode: boolean;
  /** binlog-agent 代理服务地址（全局，独立于连接） */
  agentUrl: string;
  /** 代理服务 ping 可达性：true=在线 false=不可达 null=尚未检测 */
  agentReachable: boolean | null;
}

export type AppAction =
  | { type: 'setStatus'; status: AppState['wsStatus']; error?: string | null }
  | { type: 'setConnection'; connection: SavedConnection | null; meta?: ConnectedPayload | null; demoMode?: boolean }
  | { type: 'setCheck'; result: CheckResult | null }
  | { type: 'setTraceConfig'; config: TraceConfig | null }
  | { type: 'setParse'; status: AppState['parseStatus']; changes?: Change[] | null; error?: string | null }
  | { type: 'setRollback'; rollback: RollbackResult | null }
  | { type: 'resetSession' }
  | { type: 'setAgentUrl'; url: string }
  | { type: 'setAgentReachable'; ok: boolean };

const initialState: AppState = {
  wsStatus: 'idle',
  wsError: null,
  connection: null,
  wsMeta: null,
  checkResult: null,
  traceConfig: null,
  changes: null,
  parseStatus: 'idle',
  parseError: null,
  rollback: null,
  demoMode: false,
  agentUrl: getStoredAgentUrl(),
  agentReachable: null,
};

function reducer(state: AppState, action: AppAction): AppState {
  switch (action.type) {
    case 'setStatus':
      return { ...state, wsStatus: action.status, wsError: action.error ?? null };
    case 'setConnection':
      return {
        ...state,
        connection: action.connection,
        wsMeta: action.meta ?? null,
        demoMode: action.demoMode ?? state.demoMode,
        checkResult: null,
        changes: null,
        rollback: null,
        parseStatus: 'idle',
        parseError: null,
      };
    case 'setCheck':
      return { ...state, checkResult: action.result };
    case 'setTraceConfig':
      return { ...state, traceConfig: action.config };
    case 'setParse':
      return {
        ...state,
        parseStatus: action.status,
        changes: action.changes ?? state.changes,
        parseError: action.error ?? null,
      };
    case 'setRollback':
      return { ...state, rollback: action.rollback };
    case 'resetSession':
      return { ...initialState, agentUrl: state.agentUrl };
    case 'setAgentUrl':
      return { ...state, agentUrl: action.url };
    case 'setAgentReachable':
      // 仅记录代理 ping 可达性，由顶栏状态派生函数统一展示，避免污染 wsStatus 语义
      return { ...state, agentReachable: action.ok };
    default:
      return state;
  }
}

const StateContext = createContext<AppState | null>(null);
const DispatchContext = createContext<Dispatch<AppAction> | null>(null);

export function AppProvider({ children }: { children: ReactNode }) {
  const [state, dispatch] = useReducer(reducer, initialState);
  const value = useMemo(() => state, [state]);
  return (
    <StateContext.Provider value={value}>
      <DispatchContext.Provider value={dispatch}>{children}</DispatchContext.Provider>
    </StateContext.Provider>
  );
}

export function useAppState(): AppState {
  const state = useContext(StateContext);
  if (state === null) throw new Error('useAppState 必须在 AppProvider 内使用');
  return state;
}

/**
 * 顶栏状态派生源：综合 demo / WS 追踪连接 / 代理 ping 可达性。
 * - demo：演示模式
 * - connected：WS 已连接，或代理 ping 成功（代理在线）
 * - error：代理 ping 失败（不可达）
 * - idle：尚未连接且代理可达性未确认
 */
export function deriveTopStatus(state: AppState): 'connected' | 'idle' | 'error' | 'demo' {
  if (state.demoMode) return 'demo';
  if (state.wsStatus === 'connected' || state.wsMeta) return 'connected';
  if (state.agentReachable === true) return 'connected';
  if (state.agentReachable === false) return 'error';
  if (state.wsStatus === 'error') return 'error';
  return 'idle';
}

export function useAppDispatch(): Dispatch<AppAction> {
  const dispatch = useContext(DispatchContext);
  if (dispatch === null) throw new Error('useAppDispatch 必须在 AppProvider 内使用');
  return dispatch;
}
