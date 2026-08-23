// route.ts — 极简 hash 路由（React useSyncExternalStore，不引重型库）

import { useSyncExternalStore } from 'react';

export interface Route {
  path: string;
  params: URLSearchParams;
}

function readHash(): string {
  const h = window.location.hash.replace(/^#/, '');
  return h === '' ? '/' : h;
}

function parse(hash: string): Route {
  const qIndex = hash.indexOf('?');
  const path = qIndex === -1 ? hash : hash.slice(0, qIndex);
  const query = qIndex === -1 ? '' : hash.slice(qIndex + 1);
  return { path: path || '/', params: new URLSearchParams(query) };
}

let current: Route = parse(readHash());
const listeners = new Set<() => void>();

function emit(): void {
  current = parse(readHash());
  listeners.forEach((fn) => fn());
}

if (typeof window !== 'undefined') {
  window.addEventListener('hashchange', emit);
}

function subscribe(fn: () => void): () => void {
  listeners.add(fn);
  return () => {
    listeners.delete(fn);
  };
}

function getSnapshot(): Route {
  return current;
}

export function useRoute(): Route {
  return useSyncExternalStore(subscribe, getSnapshot);
}

export function navigate(to: string): void {
  if (readHash() === to) {
    emit();
    return;
  }
  window.location.hash = to;
}

/** 序列化查询参数为字符串（去除空值） */
export function buildQuery(entries: Record<string, string | undefined>): string {
  const params = new URLSearchParams();
  Object.entries(entries).forEach(([key, value]) => {
    if (value !== undefined && value !== '') {
      params.set(key, value);
    }
  });
  const qs = params.toString();
  return qs === '' ? '' : `?${qs}`;
}
