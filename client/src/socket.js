// 原生 WebSocket 客户端（替代 Socket.IO） - 兼容 emit/on 风格
let instance = null;
let listeners = {};
let url = null;

function fire(event, data) {
  const arr = listeners[event];
  if (!arr) return;
  for (const cb of arr.slice()) {
    try { cb(data); } catch (e) { console.error('listener error', event, e); }
  }
}

function connect() {
  const token = localStorage.getItem('fps_token') || '';
  const origin = (typeof window !== 'undefined' ? window.location.origin : 'http://localhost:3000');
  const wsBase = origin.replace(/^http/, 'ws') + '/';
  url = wsBase + '?token=' + encodeURIComponent(token);

  instance = new WebSocket(url);
  instance.onopen = () => fire('connect', {});
  instance.onclose = () => { fire('disconnect', {}); instance = null; };
  instance.onerror = (e) => fire('error', e);
  instance.onmessage = (e) => {
    try {
      const data = JSON.parse(e.data);
      fire('message', data);
      if (data && data.type) fire(data.type, data);
    } catch (err) {
      console.error('parse error', err, e.data);
    }
  };
}

export function getSocket() {
  if (!instance || instance.readyState === WebSocket.CLOSED) {
    connect();
  }
  return instance;
}

export function resetSocket() {
  if (instance) {
    try { instance.close(); } catch {}
    instance = null;
  }
  listeners = {};
}

export function on(event, cb) {
  if (!listeners[event]) listeners[event] = [];
  listeners[event].push(cb);
  if (!instance) getSocket();
}

export function off(event, cb) {
  if (!listeners[event]) return;
  if (cb) listeners[event] = listeners[event].filter(x => x !== cb);
  else delete listeners[event];
}

export function emit(event, data) {
  if (!instance || instance.readyState !== WebSocket.OPEN) return false;
  const obj = (data && typeof data === 'object') ? { type: event, ...data } : { type: event, value: data };
  instance.send(JSON.stringify(obj));
  return true;
}

export function send(obj) { return emit(obj.type || 'message', obj); }
