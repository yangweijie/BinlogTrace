import { test, expect, type Page } from '@playwright/test';

/**
 * 演示模式 e2e（无需真实 MySQL / 代理，仅依赖 Vite dev server）。
 * 路由为 hash 模式：'/#' + 路径。
 */

const BASE = '/';

/** 在连接页勾选「演示模式」并进入追踪页 */
async function enterDemoTrace(page: Page): Promise<void> {
  await page.goto(BASE);
  // hash 路由初始 URL 可能是 '/'，不强制要求 '#/'
  await expect(page.getByRole('heading', { name: '新建连接' })).toBeVisible();

  const demoCb = page.getByRole('checkbox', { name: '演示模式（无代理）' });
  await demoCb.check();
  // 等待 React 提交受控 state（useDemo 更新），避免紧跟 click 读到旧的 false
  await page.waitForTimeout(300);
  await page.getByRole('button', { name: '连接并追踪' }).click();

  // 自动跳到追踪工单页
  await expect(page).toHaveURL(/#\/trace$/);
  // 顶栏应标识为演示模式（#6 修复后）
  await expect(page.getByText('演示模式')).toBeVisible();
}

test.describe('演示模式', () => {
  test('连接页可进入演示模式并到达追踪页', async ({ page }) => {
    await page.goto(BASE);
    await expect(page.getByRole('heading', { name: '新建连接' })).toBeVisible();

    await page.getByRole('checkbox', { name: '演示模式（无代理）' }).check();
    await expect(page.getByRole('checkbox', { name: '演示模式（无代理）' })).toBeChecked();

    await page.getByRole('button', { name: '连接并追踪' }).click();

    await expect(page).toHaveURL(/#\/trace$/);
    await expect(page.getByRole('heading', { name: '新建追踪工单' })).toBeVisible();
    await expect(page.getByText('演示模式')).toBeVisible();
  });

  test('完整追踪 → 结果 → 回滚 happy path', async ({ page }) => {
    await enterDemoTrace(page);

    // 选择数据库（demo 元数据：shop/blog/hr）。表选项随后加载。
    const dbSelect = page.locator('#sel-数据库');
    await dbSelect.selectOption('shop');
    // 等待受控 state 提交（db 字段更新，否则 startTrace 会因 db 为空直接 return）
    await page.waitForTimeout(300);
    // 表下拉应出现 shop 的表
    const tableSelect = page.locator('#sel-数据表');
    await expect(tableSelect).toBeVisible();

    // 默认时间窗为近 1 小时，点击开始追踪
    await page.getByRole('button', { name: '开始追踪' }).click();

    // 采集进行中：应出现「取消拉取」与「已拉取」进度
    await expect(page.getByText('取消拉取')).toBeVisible();
    await expect(page.getByText(/已拉取/)).toBeVisible();

    // 演示数据约 1.4s 后自动跳到结果页
    await expect(page).toHaveURL(/#\/trace\/result/);
    // 结果页以「共 N 条变更 + 演示数据」徽标呈现
    await expect(page.getByText(/条变更/)).toBeVisible();
    await expect(page.getByText('演示数据')).toBeVisible();

    // 结果页以表格渲染，应存在变更行
    const rows = page.locator('table tbody tr');
    await expect(rows.first()).toBeVisible({ timeout: 15_000 });
    const count = await rows.count();
    expect(count).toBeGreaterThan(0);

    // 勾选整页（按钮 aria-label="全选当前页"）
    await page.getByRole('checkbox', { name: '全选当前页' }).check();

    // 生成回滚脚本（按钮文案含动态数量，用正则匹配）
    await page.getByRole('button', { name: /生成回滚脚本/ }).click();

    // 跳到回滚页并生成 SQL
    await expect(page).toHaveURL(/#\/rollback/);
    await expect(page.getByText(/SQL 预览/)).toBeVisible({ timeout: 15_000 });
    await expect(page.getByRole('button', { name: '复制全部' })).toBeVisible();
    // 回滚 SQL 应包含 DML 关键字（多行匹配，取首个）
    await expect(page.getByText(/UPDATE|DELETE|INSERT/).first()).toBeVisible();
  });

  test('降级模拟：warning 级（row_image=MINIMAL）仍放行', async ({ page }) => {
    await page.goto(BASE);
    await page.getByRole('checkbox', { name: '演示模式（无代理）' }).check();
    // 开启「row_image=MINIMAL」模拟（warning 级，非阻断，应放行）
    await page.getByRole('checkbox', { name: 'row_image=MINIMAL' }).check();
    // 等待受控 state 提交
    await page.waitForTimeout(300);

    await page.getByRole('button', { name: '连接并追踪' }).click();
    await expect(page).toHaveURL(/#\/trace$/);
    // warning 级降级不阻断，「开始追踪」应可用（#10 放行行为）
    await expect(page.getByRole('button', { name: '开始追踪' })).toBeEnabled();
  });

  test('采集中途可取消且不残留脏结果', async ({ page }) => {
    await enterDemoTrace(page);

    await page.locator('#sel-数据库').selectOption('shop');
    await page.getByRole('button', { name: '开始追踪' }).click();

    await expect(page.getByText('取消拉取')).toBeVisible();
    await page.getByRole('button', { name: '取消拉取' }).click();

    // 取消后不再是采集态，回到可重新追踪状态
    await expect(page.getByText('取消拉取')).toHaveCount(0);
    await expect(page.getByRole('button', { name: '开始追踪' })).toBeVisible();

    // 取消不会自动跳到结果页（脏数据被清理，#4）
    await expect(page).toHaveURL(/#\/trace$/);
  });
});
