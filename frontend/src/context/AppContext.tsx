// AppContext.tsx — 全局状态（useReducer；连接/前置检查/变更/回滚）

import { createContext, useContext, useMemo, useReducer, type Dispatch, type ReactNode } from 'react';
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
}

export type AppAction =
  | { type: 'setStatus'; status: AppState['wsStatus']; error?: string | null }
  | { type: 'setConnection'; connection: SavedConnection | null; meta?: ConnectedPayload | null; demoMode?: boolean }
  | { type: 'setCheck'; result: CheckResult | null }
  | { type: 'setTraceConfig'; config: TraceConfig | null }
  | { type: 'setParse'; status: AppState['parseStatus']; changes?: Change[] | null; error?: string | null }
  | { type: 'setRollback'; rollback: RollbackResult | null }
  | { type: 'resetSession' };

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
      return { ...initialState };
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

export function useAppDispatch(): Dispatch<AppAction> {
  const dispatch = useContext(DispatchContext);
  if (dispatch === null) throw new Error('useAppDispatch 必须在 AppProvider 内使用');
  return dispatch;
}
