import React, { useEffect, useState } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { getSocket, resetSocket } from '../socket.js';

export default function RoomPage() {
  const { roomId } = useParams();
  const navigate = useNavigate();
  const [lobby, setLobby] = useState(null);
  const [team, setTeam] = useState('CT');
  const [chat, setChat] = useState([]);
  const [text, setText] = useState('');
  const [connected, setConnected] = useState(false);

  useEffect(() => {
    resetSocket();
    const sock = getSocket();
    sock.on('connect', () => { setConnected(true); sock.emit('joinRoom', { roomId, roomName: '房间 ' + roomId }); });
    sock.on('disconnect', () => setConnected(false));
    sock.on('lobby', (l) => setLobby(l));
    sock.on('chat', (m) => setChat(prev => [...prev.slice(-50), m]));
    sock.on('pm', (m) => setChat(prev => [...prev.slice(-50), { ...m, pm: true }]));
    return () => { sock.off('connect'); sock.off('disconnect'); sock.off('lobby'); sock.off('chat'); sock.off('pm'); };
  }, [roomId]);

  function changeTeam(t) {
    setTeam(t);
    getSocket().emit('setTeam', { team: t });
  }

  function send(e) {
    e.preventDefault();
    if (!text.trim()) return;
    getSocket().emit('chat', { text });
    setText('');
  }

  function startMatch() {
    getSocket().emit('setTeam', { team });
    getSocket().emit('startMatch');
    setTimeout(() => navigate('/play/' + roomId), 200);
  }

  return (
    <div className="vbox" style={{ gap: 16 }}>
      <div className="row" style={{ justifyContent: 'space-between' }}>
        <h2 style={{ margin: 0 }}>{lobby?.name || '房间 ' + roomId}</h2>
        <div>
          <span style={{ color: connected ? '#4caf50' : '#ff7377', marginRight: 14 }}>{connected ? '已连接' : '连接中...'}</span>
          <button className="btn secondary" onClick={() => navigate('/')}>← 返回大厅</button>
        </div>
      </div>

      <div className="grid">
        <div className="card" style={{ gridColumn: 'span 2' }}>
          <h3 style={{ marginTop: 0 }}>选择阵营</h3>
          <div className="row">
            <button className={'btn ' + (team === 'CT' ? '' : 'secondary')} onClick={() => changeTeam('CT')}>CT 反恐精英 ({lobby?.players?.filter(p => p.team === 'CT').length || 0})</button>
            <button className={'btn ' + (team === 'T' ? '' : 'secondary')} onClick={() => changeTeam('T')}>T 恐怖分子 ({lobby?.players?.filter(p => p.team === 'T').length || 0})</button>
            <button className="btn" onClick={startMatch} style={{ marginLeft: 'auto' }}>开始比赛 →</button>
          </div>
        </div>

        <div className="card">
          <h3 style={{ marginTop: 0 }}>房间玩家</h3>
          <div style={{ fontSize: 13, lineHeight: 1.8 }}>
            {(lobby?.players || []).map(p => (
              <div key={p.id}>
                <span className={'team-' + (p.team || '').toLowerCase()} style={{ textTransform: 'uppercase' }}>[{p.team}]</span>
                {' '}{p.username}{' '}
                <span style={{ color: '#888' }}>K {p.kills || 0} · HS {p.headshots || 0} · A {p.assists || 0}</span>
              </div>
            ))}
            {(!lobby || lobby.players?.length === 0) && <div style={{ color: '#888' }}>暂无玩家</div>}
          </div>
        </div>

        <div className="card">
          <h3 style={{ marginTop: 0 }}>聊天</h3>
          <div className="chatBox" style={{ background: '#0e0e0e', padding: 10, borderRadius: 6, minHeight: 160 }}>
            {chat.map((m, i) => (<div key={i}><span style={{ color: '#7bbfff' }}>{m.from}</span>: {m.text}</div>))}
          </div>
          <form onSubmit={send} style={{ marginTop: 10, display: 'flex', gap: 8 }}>
            <input className="input" value={text} onChange={e => setText(e.target.value)} placeholder="说点什么..." />
            <button className="btn">发送</button>
          </form>
        </div>
      </div>
    </div>
  );
}
