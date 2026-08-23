// main.tsx — 入口：只装配，零业务
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import App from './App';
import './styles/base.css';
import './styles/components.css';
import './styles/panels.css';
import './styles/table.css';
import './styles/modal.css';
import './styles/sql.css';
import './styles/pages.css';

const rootEl = document.getElementById('root');
if (rootEl === null) {
  throw new Error('缺少 #root 挂载节点');
}

createRoot(rootEl).render(
  <StrictMode>
    <App />
  </StrictMode>,
);
