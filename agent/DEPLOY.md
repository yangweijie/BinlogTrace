# Binlog Agent 部署说明

## 编译

```bash
# Linux 环境（TypePHP 预览版仅支持 Linux）
php bin/tpc.php agent/ -o binlog-agent
```

## 运行

```bash
# 默认端口 8080
./binlog-agent

# 指定端口
./binlog-agent --port 9090
```

## 网络与安全

- **绑定地址**: Agent 默认监听 `0.0.0.0:PORT`，**仅应在内网部署**。
- **无内置认证**: Agent 本身不做用户认证，依赖网络隔离（防火墙/VPN）。暴露到公网前务必加一层反向代理并启用认证（例如 nginx + HTTP Basic Auth 或 mTLS）。
- **建议配置**:
  ```
  # 仅监听内网 IP（推荐）
  # ./binlog-agent --port 8080  # 配合系统防火墙限制来源 IP
  ```

## 前置要求

- MySQL 需开启 binlog（`log_bin=ON`），`binlog_format=ROW`
- MySQL 用户需具备 `REPLICATION SLAVE` + `REPLICATION CLIENT` 权限
- MySQL 8.0.20+ 若启用 `binlog_transaction_compression` 则无法解码

## 前端连接

前端通过 WebSocket 连接 `ws://<agent-ip>:<port>`，遵循协议 v2。
