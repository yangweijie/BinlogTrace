import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import TopBar from '../components/TopBar';

describe('TopBar (#6 demo 模式状态显示)', () => {
  it('demo 状态显示「演示模式」', () => {
    render(<TopBar status="demo" />);
    expect(screen.getByText('演示模式')).toBeInTheDocument();
  });

  it('connected 状态显示「代理已连接」', () => {
    render(<TopBar status="connected" />);
    expect(screen.getByText('代理已连接')).toBeInTheDocument();
  });

  it('idle 状态显示「代理未连接」', () => {
    render(<TopBar status="idle" />);
    expect(screen.getByText('代理未连接')).toBeInTheDocument();
  });

  it('error 状态显示「代理异常」', () => {
    render(<TopBar status="error" />);
    expect(screen.getByText('代理异常')).toBeInTheDocument();
  });
});
