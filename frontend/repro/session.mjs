// src/lib/ws.ts
var WS_URL = "ws://127.0.0.1:8080";
var CONNECT_TIMEOUT_MS = 1e4;
var IDLE_TIMEOUT_MS = 45e3;
var WsClient = class {
  ws = null;
  seq = 0;
  pending = /* @__PURE__ */ new Map();
  idleTimer = null;
  closed = false;
  handlers;
  constructor(handlers) {
    this.handlers = handlers;
  }
  get isOpen() {
    return this.ws !== null && this.ws.readyState === WebSocket.OPEN;
  }
  nextId() {
    this.seq += 1;
    return `m-${Date.now().toString(36)}-${this.seq}`;
  }
  sendFrame(type, payload, id) {
    const frame = { v: 2, id, type, ts: Date.now(), payload };
    this.ws?.send(JSON.stringify(frame));
  }
  /** 连接并等待 connected / error 应答 */
  connect(opts) {
    this.closed = false;
    return new Promise((resolve, reject) => {
      let settled = false;
      const ws = new WebSocket(WS_URL);
      this.ws = ws;
      ws.onopen = () => {
        const id = this.nextId();
        this.pending.set(id, {
          resolve: (f) => {
            if (settled) return;
            settled = true;
            if (f.type === "error") {
              reject(new Error(`\u8FDE\u63A5\u5931\u8D25\uFF1A${f.payload.message}`));
            } else {
              resolve(f.payload);
            }
          },
          reject: (e) => {
            if (settled) return;
            settled = true;
            reject(e);
          },
          timer: window.setTimeout(() => {
            this.pending.delete(id);
            if (!settled) {
              settled = true;
              reject(new Error("\u8FDE\u63A5\u8D85\u65F6\uFF1A\u4EE3\u7406\u672A\u5728\u9650\u5B9A\u65F6\u95F4\u5185\u5E94\u7B54"));
            }
          }, CONNECT_TIMEOUT_MS)
        });
        this.sendFrame(
          "connect",
          {
            host: opts.host,
            port: opts.port,
            user: opts.user,
            password: opts.password,
            database: opts.database,
            serverId: opts.serverId,
            connectTimeoutMs: CONNECT_TIMEOUT_MS
          },
          id
        );
      };
      ws.onmessage = (ev) => this.handleMessage(String(ev.data));
      ws.onerror = () => {
        if (!settled) {
          settled = true;
          reject(new Error("\u65E0\u6CD5\u8FDE\u63A5 WS \u4EE3\u7406\uFF08ws://127.0.0.1:8080\uFF09\u3002\u8BF7\u786E\u8BA4\u5DF2\u53CC\u51FB\u8FD0\u884C agent \u5355\u6587\u4EF6\uFF0C\u4E14\u7AEF\u53E3\u672A\u88AB\u5360\u7528\u3002"));
        }
      };
      ws.onclose = () => {
        if (this.idleTimer !== null) {
          window.clearTimeout(this.idleTimer);
          this.idleTimer = null;
        }
        this.handlers.onClose();
      };
    });
  }
  /** 启动 binlog dump（流式；前端通过事件/心跳/空闲判定结束） */
  startDump(payload) {
    this.sendFrame("binlog-dump", { binlogFile: payload.binlogFile, binlogPos: payload.binlogPos, slaveFlags: payload.slaveFlags ?? 0 }, this.nextId());
    this.armIdleTimer();
  }
  /** 发送只读查询（INFORMATION_SCHEMA 补元数据） */
  query(sql, database) {
    return new Promise((resolve, reject) => {
      const id = this.nextId();
      this.pending.set(id, {
        resolve: (f) => {
          if (f.type === "error") {
            reject(new Error(f.payload.message));
          } else {
            resolve(f.payload);
          }
        },
        reject,
        timer: window.setTimeout(() => {
          this.pending.delete(id);
          reject(new Error("query \u8D85\u65F6"));
        }, 1e4)
      });
      this.sendFrame("query", { sql, database }, id);
    });
  }
  /** 主动释放（关闭 dump 线程） */
  close() {
    this.closed = true;
    if (this.ws) {
      try {
        this.sendFrame("close", { reason: "user-stop" }, this.nextId());
      } catch {
      }
      this.ws.close();
    }
  }
  armIdleTimer() {
    if (this.idleTimer !== null) {
      window.clearTimeout(this.idleTimer);
    }
    this.idleTimer = window.setTimeout(() => {
      if (!this.closed) {
        this.handlers.onError({ code: 1007, message: "\u8FDE\u63A5\u7A7A\u95F2\u8D85\u65F6\uFF0845s \u65E0\u6570\u636E\u5E27\uFF09\uFF0C\u5DF2\u5224\u5B9A\u65AD\u7EBF\u3002" });
        this.handlers.onClose();
      }
    }, IDLE_TIMEOUT_MS);
  }
  handleMessage(raw) {
    if (this.idleTimer !== null) {
      window.clearTimeout(this.idleTimer);
      this.idleTimer = null;
    }
    let frame;
    try {
      frame = JSON.parse(raw);
    } catch {
      this.handlers.onError({ code: 1010, message: "\u6536\u5230\u975E JSON \u5E27\uFF0C\u534F\u8BAE\u9519\u8BEF\u3002" });
      return;
    }
    if (frame.id && this.pending.has(frame.id)) {
      const p = this.pending.get(frame.id);
      this.pending.delete(frame.id);
      window.clearTimeout(p.timer);
      if (frame.type === "error") {
        p.reject(new Error(frame.payload.message));
      } else {
        p.resolve(frame);
      }
      return;
    }
    switch (frame.type) {
      case "binlog-event":
        this.armIdleTimer();
        this.handlers.onEvent(frame.payload);
        break;
      case "heartbeat":
        this.armIdleTimer();
        this.handlers.onHeartbeat(frame.payload);
        break;
      case "error":
        this.handlers.onError(frame.payload);
        break;
      case "binlog-end":
        this.handlers.onDumpEnd();
        break;
      default:
        break;
    }
  }
};

// src/lib/mock-agent.ts
function delay(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}
var MockAgent = class {
  handlers;
  timers = [];
  simulate = {};
  constructor(handlers) {
    this.handlers = handlers;
  }
  setSimulate(opts) {
    this.simulate = opts;
  }
  async connect(_opts) {
    await delay(350);
    const privs = ["SELECT", "REPLICATION SLAVE", "REPLICATION CLIENT"];
    if (this.simulate.simulatePermMissing) {
      privs.splice(0, 1);
    }
    const format = this.simulate.simulateFormatMixed ? "MIXED" : "ROW";
    const rowImage = this.simulate.simulateRowImageMinimal ? "MINIMAL" : "FULL";
    const hasBinlog = !this.simulate.simulateNoBinlog;
    return {
      ok: true,
      serverVersion: "5.7.44-log",
      binlogFile: hasBinlog ? "mysql-bin.000003" : null,
      binlogPos: hasBinlog ? 4 : null,
      binlogFormat: format,
      binlogRowImage: rowImage,
      hasBinlog,
      serverId: Math.floor(Math.random() * 1e6) + 1e3,
      userPrivileges: privs
    };
  }
  /** 流式发射演示 binlog-event（时间戳落在 [startMs, endMs] 内，最后发 binlog-end） */
  startDump(payload) {
    const { demoCount, startMs, endMs } = payload;
    const count = Math.max(1, demoCount);
    const span = Math.max(1, endMs - startMs);
    const step = span / count;
    const file = payload.binlogFile;
    const emitAt = (i) => {
      const timer = window.setTimeout(() => {
        const event = {
          raw: btoa(String((i + 1) * 7919)),
          // 演示标记，demo-parse 据此确定性生成变更
          eventType: 30,
          binlogFile: file,
          binlogPos: payload.binlogPos + i * 32,
          timestamp: Math.floor(startMs + i * step),
          serverId: 1
        };
        this.handlers.onEvent(event);
      }, 60 + i * 12);
      this.timers.push(timer);
    };
    for (let i = 0; i < count; i += 1) {
      emitAt(i);
    }
    const endTimer = window.setTimeout(() => {
      this.handlers.onHeartbeat({ ts: Date.now(), binlogPos: payload.binlogPos + count * 32 });
      this.handlers.onDumpEnd();
    }, 90 + count * 12);
    this.timers.push(endTimer);
  }
  async query(_sql) {
    await delay(120);
    return { columns: [], rows: [], affectedRows: 0 };
  }
  close() {
    this.timers.forEach((t) => window.clearTimeout(t));
    this.timers = [];
  }
};

// src/lib/session.ts
var TraceSession = class {
  agent;
  buffer = [];
  ended = false;
  lastError = null;
  listeners = /* @__PURE__ */ new Set();
  constructor(demo, simulate) {
    const handlers = {
      onEvent: (p) => {
        this.buffer.push(p);
        this.emit();
      },
      onHeartbeat: () => {
        this.emit();
      },
      onError: (p) => {
        this.lastError = p;
        this.ended = true;
        this.emit();
      },
      onDumpEnd: () => {
        this.ended = true;
        this.emit();
      },
      onClose: () => {
        this.ended = true;
        this.emit();
      }
    };
    this.agent = demo ? new MockAgent(handlers) : new WsClient(handlers);
    if (demo && this.agent instanceof MockAgent) {
      this.agent.setSimulate(simulate ?? {});
    }
  }
  get events() {
    return this.buffer;
  }
  get isEnded() {
    return this.ended;
  }
  get error() {
    return this.lastError;
  }
  async query(sql, database) {
    return this.agent.query(sql, database);
  }
  subscribe(fn) {
    this.listeners.add(fn);
    return () => {
      this.listeners.delete(fn);
    };
  }
  startDump(payload) {
    this.buffer = [];
    this.ended = false;
    this.lastError = null;
    this.agent.startDump(payload);
  }
  close() {
    this.agent.close();
  }
  emit() {
    this.listeners.forEach((fn) => fn());
  }
};
var current = null;
function createSession(demo, simulate) {
  current = new TraceSession(demo, simulate);
  return current;
}
function getSession() {
  return current;
}
function clearSession() {
  if (current) {
    current.close();
  }
  current = null;
}
export {
  TraceSession,
  clearSession,
  createSession,
  getSession
};
