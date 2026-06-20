import React from 'react';
import { Routes, Route, Link, useNavigate, useLocation } from 'react-router-dom';
import LobbyPage from './pages/LobbyPage.jsx';
import RoomPage from './pages/RoomPage.jsx';
import GamePage from './pages/GamePage.jsx';

export default function App() {
  const navigate = useNavigate();
  const location = useLocation();
  const user = (() => {
    const raw = localStorage.getItem('fps_user');
    return raw ? JSON.parse(raw) : null;
  })();

  function logout() {
    localStorage.removeItem('fps_token');
    localStorage.removeItem('fps_user');
    navigate('/login');
  }

  return (
    <div style={{ minHeight: '100%', display: 'flex', flexDirection: 'column' }}>
      <header style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '12px 24px', background: '#111', borderBottom: '1px solid #222' }}>
        <Link to="/" style={{ color: '#fff', textDecoration: 'none', fontSize: 20, fontWeight: 700, letterSpacing: 2 }}>FPS · scirco</Link>
        <nav style={{ display: 'flex', gap: 18, alignItems: 'center' }}>
          <Link to="/" style={navStyle(location.pathname === '/')}>大厅</Link>
          <span style={{ color: '#888' }}>|</span>
          <span style={{ color: '#aaa' }}>{user?.username}</span>
          <button onClick={logout} style={btnStyle()}>退出</button>
        </nav>
      </header>
      <main style={{ flex: 1, padding: 20 }}>
        <Routes>
          <Route index element={<LobbyPage />} />
          <Route path="room/:roomId" element={<RoomPage />} />
          <Route path="play/:roomId" element={<GamePage />} />
        </Routes>
      </main>
    </div>
  );
}

function navStyle(active) {
  return { color: active ? '#fff' : '#aaa', textDecoration: active ? 'underline' : 'none', fontWeight: active ? 600 : 400 };
}
function btnStyle() {
  return { background: '#2a2a2a', border: '1px solid #444', color: '#eee', padding: '6px 14px', borderRadius: 4, cursor: 'pointer', fontSize: 13 };
}
