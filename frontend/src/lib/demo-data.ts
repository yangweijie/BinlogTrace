// demo-data.ts — 演示模式静态库/表元数据（与 useSchemaMeta 共享，避免 Worker 与 Hook 互相依赖）
export const DEMO_DBS = ['shop', 'blog', 'hr'];

export const DEMO_TABLES: Record<string, string[]> = {
  shop: ['orders', 'payments', 'users'],
  blog: ['posts', 'comments'],
  hr: ['employees', 'departments'],
};

/** 每个演示表各自的真实列（不同表列不同，避免看起来都像订单表） */
export const DEMO_TABLE_COLUMNS: Record<string, string[]> = {
  orders: ['id', 'order_no', 'user_id', 'status', 'pay_amount', 'created_at', 'updated_at'],
  payments: ['id', 'order_id', 'channel', 'amount', 'paid_at', 'refunded'],
  users: ['id', 'username', 'email', 'phone', 'gender', 'created_at'],
  posts: ['id', 'title', 'author', 'content', 'views', 'published', 'created_at'],
  comments: ['id', 'post_id', 'user_id', 'content', 'likes', 'created_at'],
  employees: ['id', 'name', 'department_id', 'title', 'salary', 'hire_date'],
  departments: ['id', 'name', 'manager_id', 'location'],
};

