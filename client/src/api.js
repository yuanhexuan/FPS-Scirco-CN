// HTTP API 客户端
const BASE = (import.meta.env.VITE_API_BASE || '/api');

function headers() {
  const token = localStorage.getItem('fps_token');
  return { 'Content-Type': 'application/json', ...(token ? { Authorization: 'Bearer ' + token } : {}) };
}

async function request(path, options = {}) {
  const res = await fetch(BASE + path, { ...options, headers: { ...headers(), ...(options.headers || {}) } });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(data.error || '请求失败');
  return data;
}

export const api = {
  register: (u, p) => request('/auth/register', { method: 'POST', body: JSON.stringify({ username: u, password: p }) }),
  login: (u, p) => request('/auth/login', { method: 'POST', body: JSON.stringify({ username: u, password: p }) }),
  me: () => request('/auth/me'),
  getRooms: () => request('/lobby/rooms'),
  createRoom: (name, maxPlayers = 16) => request('/lobby/rooms', { method: 'POST', body: JSON.stringify({ name, maxPlayers }) }),
  getOnline: () => request('/lobby/online'),
  getStats: () => request('/stats'),
  getPlayerStats: (id) => request('/stats/' + id),
};
export default api;
