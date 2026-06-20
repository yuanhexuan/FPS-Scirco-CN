import React, { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import api from '../api.js';

export default function LoginPage() {
  const navigate = useNavigate();
  const [mode, setMode] = useState('login');
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [err, setErr] = useState('');
  const [loading, setLoading] = useState(false);

  async function submit(e) {
    e.preventDefault();
    setErr(''); setLoading(true);
    try {
      const data = mode === 'login' ? await api.login(username, password) : await api.register(username, password);
      localStorage.setItem('fps_token', data.token);
      localStorage.setItem('fps_user', JSON.stringify(data.user));
      navigate('/');
    } catch (e) {
      setErr(e.message);
    } finally {
      setLoading(false);
    }
  }

  return (
    <div style={{ height: '100vh', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
      <div className="card" style={{ width: 360 }}>
        <h1 style={{ margin: '0 0 12px', fontSize: 22, letterSpacing: 2 }}>FPS · scirco</h1>
        <div className="row" style={{ marginBottom: 14 }}>
          <button onClick={() => setMode('login')} className={'btn ' + (mode === 'login' ? '' : 'secondary')}>登录</button>
          <button onClick={() => setMode('register')} className={'btn ' + (mode === 'register' ? '' : 'secondary')}>注册</button>
        </div>
        <form onSubmit={submit} className="vbox">
          <input className="input" placeholder="用户名" value={username} onChange={e => setUsername(e.target.value)} autoFocus />
          <input className="input" type="password" placeholder="密码" value={password} onChange={e => setPassword(e.target.value)} />
          {err && <div style={{ color: '#ff7377', fontSize: 13 }}>{err}</div>}
          <button className="btn" disabled={loading}>{mode === 'login' ? '登录' : '注册'}</button>
          <div style={{ fontSize: 12, color: '#666', textAlign: 'center' }}>账号和战绩保存在服务器 SQLite</div>
        </form>
      </div>
    </div>
  );
}
