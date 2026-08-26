import '@testing-library/jest-dom/vitest';
import { afterEach, beforeEach } from 'vitest';
import { cleanup } from '@testing-library/react';

afterEach(() => {
  cleanup();
  // 清理可能直接挂在 body 上的全局节点（如 toast），确保用例间隔离
  while (document.body.firstChild) {
    document.body.removeChild(document.body.firstChild);
  }
});

// jsdom 缺省不实现以下 API，组件（ResizeObserver、matchMedia）会用到，需 polyfill
if (!('ResizeObserver' in globalThis)) {
  class ResizeObserver {
    observe(): void {}
    unobserve(): void {}
    disconnect(): void {}
  }
  (globalThis as unknown as { ResizeObserver: typeof ResizeObserver }).ResizeObserver = ResizeObserver;
}

if (!window.matchMedia) {
  window.matchMedia = (query: string) =>
    ({
      matches: false,
      media: query,
      onchange: null,
      addEventListener: () => {},
      removeEventListener: () => {},
      addListener: () => {},
      removeListener: () => {},
      dispatchEvent: () => false,
    }) as unknown as MediaQueryList;
}

// 阻止测试里意外访问真实 localStorage 残留（各用例相互独立）
beforeEach(() => {
  window.localStorage.clear();
  window.location.hash = '';
});
