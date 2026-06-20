import React, { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import api from '../api.js';

export default function LobbyPage() {
  const navigate = useNavigate();
  const [rooms, setRooms] = useState([]);
  const [stats, setStats] = useState([]);
  const [online, setOnline] = useState([]);
  const [newName, setNewName] = useState('我的房间');

  useEffect(() => {
    refresh();
    const t = setInterval(refresh, 5000);
    return () => clearInterval(t);
  }, []);

  async function refresh() {
    try {
      const [r, s, o] = await Promise.all([api.getRooms(), api.getStats(), api.getOnline()]);
      setRooms(r.rooms || []);
      setStats(s.stats || []);
      setOnline(o.players || []);
    } catch {}
  }

  async function create() {
    try {
      const { roomId } = await api.createRoom(newName);
      navigate('/room/' + roomId);
    } catch (e) { alert(e.message); }
  }

  return (
    <div className="vbox" style={{ gap: 18 }}>
      <div className="row" style={{ justifyContent: 'space-between' }}>
        <h2 style={{ margin: 0 }}>游戏大厅</h2>
        <div className="row">
          <input className="input" value={newName} onChange={e => setNewName(e.target.value)} placeholder="房间名" style={{ width: 200 }} />
          <button className="btn" onClick={create}>创建房间</button>
        </div>
      </div>

      <div className="grid">
        <div className="card" style={{ gridColumn: 'span 2' }}>
          <h3 style={{ marginTop: 0 }}>房间列表 ({rooms.length})</h3>
          {rooms.length === 0 && <div style={{ color: '#888' }}>暂无房间，创建一个吧。</div>}
          <div className="grid">
            {rooms.map(r => (
              <div key={r.id} className="card" style={{ background: '#1a1a1a' }}>
                <div style={{ fontWeight: 600, fontSize: 16 }}>{r.name}</div>
                <div style={{ color: '#aaa', fontSize: 13, margin: '4px 0' }}>
                  状态: <span style={{ color: r.state === 'playing' ? '#ff9f43' : '#888' }}>{r.state || '等待中'}</span>
                </div>
                <div style={{ color: '#aaa', fontSize: 13, marginBottom: 10 }}>
                  {r.playersCount || 0} / {r.maxPlayers || 16} 人
                </div>
                <button className="btn" onClick={() => navigate('/room/' + r.id)}>加入</button>
              </div>
            ))}
          </div>
        </div>

        <div className="card">
          <h3 style={{ marginTop: 0 }}>在线玩家 ({online.length})</h3>
          <div className="chatBox" style={{ maxHeight: 300 }}>
            {online.length === 0 && <div style={{ color: '#888' }}>无</div>}
            {online.map(p => (
              <div key={p.id} style={{ padding: '2px 0' }}>
                <span className={'team-' + (p.team || '').toLowerCase()} style={{ textTransform: 'uppercase' }}>[{p.team}]</span> {p.username}
              </div>
            ))}
          </div>
        </div>

        <div className="card" style={{ gridColumn: 'span 2' }}>
          <h3 style={{ marginTop: 0 }}>击杀榜 TOP</h3>
          <div className="grid">
            {stats.slice(0, 6).map((s, i) => (
              <div key={s.username || i} className="card" style={{ background: '#1a1a1a' }}>
                <div style={{ fontSize: 22, fontWeight: 700 }}>#{i + 1}</div>
                <div style={{ fontWeight: 600, marginTop: 4 }}>{s.username}</div>
                <div style={{ color: '#aaa', fontSize: 13 }}>击杀 {s.kills} · 爆头 {s.headshots} · K/D {s.kd}</div>
              </div>
            ))}
          </div>
        </div>
      </div>

      <div className="card" style={{ color: '#888', fontSize: 13 }}>
        操作说明： <span className="kbd">W A S D</span> 移动 · <span className="kbd">空格</span> 跳跃 · <span className="kbd">Shift</span> 静音行走 · <span className="kbd">鼠标</span> 朝向 · <span className="kbd">左键</span> 开火 · <span className="kbd">右键</span> 开镜 · <span className="kbd">R</span> 换弹 · <span className="kbd">1-6</span> 切换武器 · <span className="kbd">B</span> 打开购买菜单 · <span className="kbd">G</span> 扔投掷物 · <span className="kbd">E</span> 埋/拆弹 · <span className="kbd">ESC</span> 释放鼠标
      </div>
    </div>
  );
}
