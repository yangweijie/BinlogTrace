// App.tsx — 装配层：仅路由分发，不写业务
import { AppProvider } from './context/AppContext';
import { useRoute } from './lib/route';
import ConnectPage from './pages/ConnectPage';
import TracePage from './pages/TracePage';
import ResultPage from './pages/ResultPage';
import RollbackPage from './pages/RollbackPage';

function Router() {
  const route = useRoute();
  if (route.path === '/trace/result') return <ResultPage />;
  if (route.path === '/trace') return <TracePage />;
  if (route.path === '/rollback') return <RollbackPage />;
  return <ConnectPage />;
}

export default function App() {
  return (
    <AppProvider>
      <Router />
    </AppProvider>
  );
}
