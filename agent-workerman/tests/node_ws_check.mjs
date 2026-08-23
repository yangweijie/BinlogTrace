// 用 Node 原生 WebSocket（undici，内置 RFC 6455 标准 GUID）测试 workerman agent 握手
const port = process.argv[2] || '8080';
const url = `ws://127.0.0.1:${port}`;
console.log(`connecting to ${url} ...`);

const ws = new WebSocket(url);

const timer = setTimeout(() => {
    console.log('RESULT: TIMEOUT (handshake never completed)');
    process.exit(2);
}, 5000);

ws.onopen = () => {
    clearTimeout(timer);
    console.log('RESULT: OPEN OK');
    ws.send(JSON.stringify({ v: 2, id: 'node-1', type: 'heartbeat', ts: Date.now(), payload: {} }));
};

ws.onmessage = (ev) => {
    console.log('message:', String(ev.data));
    ws.close();
    process.exit(0);
};

ws.onerror = (e) => {
    clearTimeout(timer);
    console.log('RESULT: ERROR', e.message || e);
    process.exit(1);
};

ws.onclose = (e) => {
    console.log('close:', e.code, e.reason);
};
