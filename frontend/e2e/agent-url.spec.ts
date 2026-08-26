import { test, expect, type Page, type Route } from '@playwright/test';

/**
 * 代理地址隔离 e2e（#TP-AOT-021 修复验证）
 * 真实模式（非演示）：连接页填写 MySQL 主机/端口(127.0.0.1:3306) 与
 * 独立的代理地址(127.0.0.1:8080)，点击「测试连接」后，发出的 /connect 请求
 * 必须打到代理地址的 8080 端口，绝不能污染为数据库端口 3306。
 * 用 page.route 拦截代理请求并返回 mock，避免依赖真实 binlog-agent 服务。
 */

const AGENT_URL = 'http://127.0.0.1:8080';

async function mockAgentConnect(route: Route): Promise<void> {
  await route.fulfill({
    status: 200,
    contentType: 'application/json',
    body: JSON.stringify({
      v: 2,
      id: 'mock',
      type: 'connected',
      ts: Date.now(),
      payload: {
        ok: true,
        serverVersion: '8.0.0',
        binlogFile: 'mysql-bin.000001',
        binlogPos: 4,
        binlogFormat: 'ROW',
        binlogRowImage: 'FULL',
        hasBinlog: true,
        serverId: 1,
        userPrivileges: ['SELECT', 'REPLICATION SLAVE', 'REPLICATION CLIENT'],
        session: 'mock-session',
      },
    }),
  });
}

test.describe('代理地址不被 MySQL 端口污染', () => {
  test('填写 MySQL:3306 + 代理:8080，/connect 请求打到代理端口', async ({ page }: { page: Page }) => {
    // 拦截发往代理地址的请求（跨端口）
    const requests: string[] = [];
    await page.route(`${AGENT_URL}/**`, async (route: Route) => {
      requests.push(route.request().url());
      await mockAgentConnect(route);
    });

    await page.goto('/');
    await expect(page.getByRole('heading', { name: '新建连接' })).toBeVisible();

    // 默认 MySQL 主机 127.0.0.1、端口 3306；代理地址默认 127.0.0.1:8080
    await page.getByLabel('主机').fill('127.0.0.1');
    await page.getByLabel('端口').fill('3306');
    await page.getByLabel('用户').fill('root');
    const agentInput = page.getByLabel('代理地址');
    await expect(agentInput).toHaveValue('http://127.0.0.1:8080');
    await agentInput.fill(AGENT_URL);

    // 不要演示模式（否则走 MockAgent 不发真实 fetch）
    const demoCb = page.getByRole('checkbox', { name: '演示模式（无代理）' });
    if (await demoCb.isChecked()) {
      await demoCb.uncheck();
    }

    await page.getByRole('button', { name: '测试连接' }).click();

    // 等待至少一条发往代理的请求
    await expect
      .poll(() => requests.some((u) => u.startsWith(`${AGENT_URL}/connect`)), { timeout: 10_000 })
      .toBe(true);

    // 核心断言：请求命中代理端口 8080，且绝不出现数据库端口 3306
    const connectReq = requests.find((u) => u.includes('/connect'));
    expect(connectReq).toBeDefined();
    expect(connectReq).toContain(':8080');
    expect(connectReq).not.toContain(':3306');
    expect(connectReq).not.toContain('127.0.0.1:3306');
  });

  test('自定义代理地址被原样使用', async ({ page }: { page: Page }) => {
    const CUSTOM = 'http://proxy.local:9000';
    const requests: string[] = [];
    await page.route(`${CUSTOM}/**`, async (route: Route) => {
      requests.push(route.request().url());
      await mockAgentConnect(route);
    });

    await page.goto('/');
    await expect(page.getByRole('heading', { name: '新建连接' })).toBeVisible();

    await page.getByLabel('主机').fill('db.internal');
    await page.getByLabel('端口').fill('3306');
    await page.getByLabel('用户').fill('root');
    await page.getByLabel('代理地址').fill(CUSTOM);

    await page.getByRole('button', { name: '测试连接' }).click();

    await expect
      .poll(() => requests.some((u) => u.startsWith(`${CUSTOM}/connect`)), { timeout: 10_000 })
      .toBe(true);
    const connectReq = requests.find((u) => u.includes('/connect'));
    expect(connectReq).toBe(`${CUSTOM}/connect`);
    expect(connectReq).not.toContain('db.internal');
    expect(connectReq).not.toContain(':3306');
  });
});
