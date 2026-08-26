// useAgentPing.ts — 页面挂载/代理地址变化时自动 ping 一次，同步代理状态
import { useEffect } from 'react';
import { useAppState, useAppDispatch } from '../context/AppContext';
import { pingAgent } from '../components/AgentConfig';

/**
 * 在页面组件中调用一次即可：每次挂载或 agentUrl 变化时，
 * 自动对代理地址发起 /ping，并通过 setAgentReachable 更新全局状态，
 * 顶栏据此显示"代理已连接 / 代理异常"。
 */
export function useAgentPing(): void {
  const { agentUrl } = useAppState();
  const dispatch = useAppDispatch();
  useEffect(() => {
    let alive = true;
    void pingAgent(agentUrl).then((ok) => {
      if (alive) dispatch({ type: 'setAgentReachable', ok });
    });
    return () => {
      alive = false;
    };
  }, [agentUrl, dispatch]);
}
