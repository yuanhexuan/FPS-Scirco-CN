import React, { useEffect, useRef, useState } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { getSocket, resetSocket } from '../socket.js';
import { initGame, destroyGame, sendInput } from '../game/engine.js';

// 游戏主页面 - Three.js 第一人称 + HUD
export default function GamePage() {
  const { roomId } = useParams();
  const navigate = useNavigate();
  const canvasRef = useRef(null);
  const gameRef = useRef(null);
  const [hud, setHud] = useState({
    health: 100, armor: 0, money: 800, weapon: 'usp', ammo: { magazine: 12, reserve: 120 },
    team: 'CT', state: 'warmup', roundTime: 0, rounds: { CT: 0, T: 0 }, bombPlanted: false, bombTimer: 0,
    kills: 0, headshots: 0, blind: 0, defusing: false, defuseProgress: 0,
  });
  const [killFeed, setKillFeed] = useState([]);
  const [chat, setChat] = useState([]);
  const [chatInput, setChatInput] = useState('');
  const [chatOpen, setChatOpen] = useState(false);
  const [buyMenu, setBuyMenu] = useState(false);
  const [roundEnd, setRoundEnd] = useState(null);
  const [matchEnd, setMatchEnd] = useState(null);
  const chatInputRef = useRef(null);

  useEffect(() => {
    // 先加入房间
    resetSocket();
    const sock = getSocket();
    sock.on('connect', () => { sock.emit('joinRoom', { roomId, roomName: '房间 ' + roomId }); });
    sock.on('joinedRoom', (data) => {
      // 初始化游戏
      gameRef.current = initGame(canvasRef.current, data, sock, (h) => setHud(prev => ({ ...prev, ...h })));
    });
    sock.on('snapshot', (snap) => {
      if (gameRef.current) gameRef.current.onSnapshot(snap);
      setHud(prev => ({
        ...prev,
        state: snap.state, roundTime: snap.roundTime, rounds: snap.rounds,
        bombPlanted: snap.bombPlanted, bombTimer: snap.bombTimer,
        defusing: snap.defusing, defuseProgress: snap.defuseProgress || 0,
      }));
    });
    sock.on('bullet', (b) => { if (gameRef.current) gameRef.current.addBulletEffect(b); });
    sock.on('hit', (h) => { if (gameRef.current) gameRef.current.onHit(h); });
    sock.on('kill', (k) => { setKillFeed(prev => [...prev.slice(-8), k]); });
    sock.on('explode', (e) => { if (gameRef.current) gameRef.current.addExplosion(e); });
    sock.on('bombPlanted', () => setHud(prev => ({ ...prev, bombPlanted: true })));
    sock.on('defusing', (d) => setHud(prev => ({ ...prev, defusing: true })));
    sock.on('defuseStop', () => setHud(prev => ({ ...prev, defusing: false })));
    sock.on('roundEnd', (r) => { setRoundEnd(r); setTimeout(() => setRoundEnd(null), 6000); });
    sock.on('matchEnd', (r) => { setMatchEnd(r); });
    sock.on('chat', (m) => setChat(prev => [...prev.slice(-30), m]));
    sock.on('gameState', (g) => setHud(prev => ({ ...prev, state: g.state, roundTime: g.roundTime, rounds: g.rounds })));

    // 键盘
    const onKeyDown = (e) => {
      if (e.key === 'Escape') { if (gameRef.current) gameRef.current.releasePointer(); }
      if (e.key.toLowerCase() === 'b') { e.preventDefault(); setBuyMenu(v => !v); }
      if (e.key.toLowerCase() === 't' && !chatOpen) { setChatOpen(true); setTimeout(() => chatInputRef.current?.focus(), 30); }
      if (e.key === 'Enter' && chatOpen) {
        if (chatInput.trim()) getSocket().emit('chat', { text: chatInput });
        setChatInput(''); setChatOpen(false);
      }
      if (e.key === 'Escape' && chatOpen) { setChatInput(''); setChatOpen(false); }
    };
    window.addEventListener('keydown', onKeyDown);

    return () => {
      window.removeEventListener('keydown', onKeyDown);
      sock.off('connect'); sock.off('joinedRoom'); sock.off('snapshot'); sock.off('bullet');
      sock.off('hit'); sock.off('kill'); sock.off('explode'); sock.off('bombPlanted');
      sock.off('defusing'); sock.off('defuseStop'); sock.off('roundEnd'); sock.off('matchEnd');
      sock.off('chat'); sock.off('gameState');
      if (gameRef.current) destroyGame(gameRef.current);
    };
  }, [roomId]);

  function buyWeapon(w) {
    getSocket().emit('buy', { weapon: w });
    setTimeout(() => getSocket().emit('selectWeapon', { weapon: w }), 120);
    setBuyMenu(false);
  }

  function selectWeapon(w) {
    getSocket().emit('selectWeapon', { weapon: w });
  }

  function startDefuse() {
    getSocket().emit('startDefuse');
  }
  function plantBomb() {
    getSocket().emit('plantBomb');
  }

  return (
    <div style={{ position: 'relative', width: '100%', height: 'calc(100vh - 60px)', overflow: 'hidden', background: '#000' }}>
      <canvas ref={canvasRef} style={{ display: 'block', width: '100%', height: '100%' }} />

      {/* HUD 左下：血量/护甲 */}
      <div className="hud" style={{ left: 16, bottom: 16 }}>
        <div style={{ fontSize: 22, fontWeight: 700 }}>
          HP <span style={{ color: hud.health > 50 ? '#4caf50' : '#ff7377' }}>{hud.health}</span>
          {hud.armor > 0 && <span style={{ marginLeft: 16 }}>AR {hud.armor}</span>}
        </div>
        <div style={{ fontSize: 14, color: '#ddd' }}>${hud.money}</div>
      </div>

      {/* HUD 右下：弹药 */}
      <div className="hud" style={{ right: 16, bottom: 16, textAlign: 'right' }}>
        <div style={{ fontSize: 28, fontWeight: 700, textTransform: 'uppercase' }}>{hud.weapon}</div>
        <div style={{ fontSize: 18 }}>{hud.ammo.magazine} / {hud.ammo.reserve}</div>
      </div>

      {/* HUD 顶部：回合时间/比分 */}
      <div className="hud" style={{ top: 12, left: 0, right: 0, textAlign: 'center' }}>
        <div style={{ fontSize: 28, fontWeight: 800 }}>
          <span className="team-ct">{hud.rounds?.CT || 0}</span>
          {' : '}
          <span className="team-t">{hud.rounds?.T || 0}</span>
        </div>
        <div style={{ fontSize: 20 }}>{Math.max(0, Math.ceil(hud.roundTime))}s</div>
        {hud.state === 'buying' && <div style={{ color: '#ffd859', fontSize: 14 }}>购买阶段（按 B 打开商店）</div>}
        {hud.bombPlanted && <div style={{ color: '#ff4d4f', fontSize: 14, animation: 'blink 1s infinite' }}>C4 已安放 · 剩余 {Math.max(0, Math.ceil(hud.bombTimer))}s</div>}
      </div>

      {/* HUD 顶部左：阵营 + 战绩 */}
      <div className="hud" style={{ top: 12, left: 16 }}>
        <div className={'team-' + (hud.team || 'ct').toLowerCase()} style={{ fontSize: 22, fontWeight: 700, textTransform: 'uppercase' }}>{hud.team}</div>
        <div style={{ fontSize: 13 }}>击杀 {hud.kills} · 爆头 {hud.headshots}</div>
      </div>

      {/* HUD 顶部右：击杀记录 */}
      <div className="hud" style={{ top: 12, right: 16, textAlign: 'right' }}>
        {killFeed.slice(-6).map((k, i) => (
          <div key={i} style={{ fontSize: 12, lineHeight: 1.5 }}>
            <span className={'team-' + (k.team || 'ct').toLowerCase()}>{k.killer}</span>
            <span style={{ color: '#aaa' }}> [{k.weapon}] </span>
            <span>{k.victim}</span>
            {k.headshot && <span style={{ color: '#ffd859' }}> (爆头)</span>}
          </div>
        ))}
      </div>

      {/* 中心准星 */}
      <div className="hud" style={{ top: '50%', left: '50%', transform: 'translate(-50%, -50%)', width: 20, height: 20 }}>
        <div style={{ position: 'absolute', left: 9, top: 0, width: 2, height: 6, background: '#fff', opacity: 0.85 }} />
        <div style={{ position: 'absolute', left: 9, bottom: 0, width: 2, height: 6, background: '#fff', opacity: 0.85 }} />
        <div style={{ position: 'absolute', left: 0, top: 9, width: 6, height: 2, background: '#fff', opacity: 0.85 }} />
        <div style={{ position: 'absolute', right: 0, top: 9, width: 6, height: 2, background: '#fff', opacity: 0.85 }} />
      </div>

      {/* 拆弹进度 */}
      {hud.defusing && (
        <div className="hud" style={{ bottom: 90, left: '50%', transform: 'translateX(-50%)', width: 300, textAlign: 'center' }}>
          <div style={{ color: '#ffd859', fontSize: 16 }}>正在拆弹...</div>
          <div style={{ background: '#222', height: 12, borderRadius: 6, overflow: 'hidden', marginTop: 8 }}>
            <div style={{ height: '100%', background: '#ffd859', width: Math.min(100, (hud.defuseProgress / 5) * 100) + '%', transition: 'width 0.1s linear' }} />
          </div>
        </div>
      )}

      {/* 左下角快捷武器 */}
      <div className="hud" style={{ bottom: 60, left: 16, fontSize: 13, color: '#ccc' }}>
        <div>1 手枪 · 2 步枪 · 3 狙击 · 4 匕首</div>
        <div>R 换弹 · B 商店 · E 埋/拆弹 · G 手雷 · 鼠标右键 开镜</div>
      </div>

      {/* 聊天框 */}
      <div className="hud" style={{ top: '55%', left: 16, width: 320, pointerEvents: chatOpen ? 'auto' : 'none' }}>
        <div className="chatBox" style={{ background: 'rgba(0,0,0,0.35)', padding: 10, borderRadius: 6 }}>
          {chat.slice(-6).map((m, i) => (
            <div key={i}><span style={{ color: '#7bbfff' }}>{m.from}</span>: {m.text}</div>
          ))}
        </div>
        {chatOpen && (
          <input
            ref={chatInputRef}
            value={chatInput}
            onChange={e => setChatInput(e.target.value)}
            placeholder="按 T 聊天 · Enter 发送 · Esc 取消"
            style={{ marginTop: 8, width: '100%', background: 'rgba(0,0,0,0.6)', border: '1px solid #333', borderRadius: 4, padding: 8, color: '#fff', outline: 'none' }}
          />
        )}
      </div>

      {/* 购买菜单 */}
      {buyMenu && (
        <div className="hud" style={{ top: '50%', left: '50%', transform: 'translate(-50%, -50%)', width: 520, background: 'rgba(0,0,0,0.85)', padding: 24, borderRadius: 8, pointerEvents: 'auto' }}>
          <h2 style={{ margin: '0 0 12px' }}>武器商店 · ${hud.money}</h2>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 10 }}>
            {[
              { key: 'usp', name: 'USP-S (手枪)', price: 200 },
              { key: 'glock', name: 'Glock-18 (手枪)', price: 200 },
              { key: 'ak47', name: 'AK-47 (步枪)', price: 2700 },
              { key: 'm4a4', name: 'M4A4 (步枪)', price: 3100 },
              { key: 'awp', name: 'AWP (狙击)', price: 4750 },
              { key: 'deagle', name: '沙鹰 (手枪)', price: 700 },
              { key: 'smoke', name: '烟雾弹', price: 300 },
              { key: 'flash', name: '闪光弹', price: 200 },
              { key: 'he', name: '高爆手雷', price: 300 },
              { key: 'molotov', name: '燃烧瓶', price: 400 },
              { key: 'zeus', name: '电击枪', price: 200 },
              { key: 'knife', name: '匕首', price: 0 },
            ].map(w => (
              <button key={w.key} onClick={() => buyWeapon(w.key)} style={{ background: '#2a2a2a', border: '1px solid #333', color: '#eee', padding: '10px 12px', borderRadius: 4, cursor: 'pointer', textAlign: 'left', fontSize: 13 }}>
                <div style={{ display: 'flex', justifyContent: 'space-between' }}>
                  <span>{w.name}</span>
                  <span style={{ color: '#ffd859' }}>${w.price}</span>
                </div>
              </button>
            ))}
          </div>
          <button onClick={() => setBuyMenu(false)} style={{ marginTop: 14, background: '#444', color: '#fff', border: 0, padding: '8px 14px', borderRadius: 4, cursor: 'pointer' }}>关闭 (B)</button>
        </div>
      )}

      {/* 回合结束面板 */}
      {roundEnd && (
        <div className="hud" style={{ top: '30%', left: '50%', transform: 'translateX(-50%)', background: 'rgba(0,0,0,0.8)', padding: '30px 60px', borderRadius: 8, textAlign: 'center', pointerEvents: 'none' }}>
          <h2 style={{ margin: 0, color: roundEnd.winner === 'CT' ? '#7bbfff' : '#ffbd59', fontSize: 36 }}>{roundEnd.winner} 胜利！</h2>
          <div style={{ fontSize: 16, color: '#ddd', marginTop: 8 }}>{roundEnd.reason}</div>
          <div style={{ fontSize: 14, color: '#aaa', marginTop: 12 }}>比分 {roundEnd.rounds.CT} : {roundEnd.rounds.T}</div>
        </div>
      )}

      {/* 比赛结束 */}
      {matchEnd && (
        <div className="hud" style={{ top: '50%', left: '50%', transform: 'translate(-50%, -50%)', background: 'rgba(0,0,0,0.92)', padding: 40, borderRadius: 8, textAlign: 'center', pointerEvents: 'auto' }}>
          <h2 style={{ margin: 0, fontSize: 36 }}>{matchEnd.winner} 赢得比赛！</h2>
          <div style={{ fontSize: 22, color: '#ddd', marginTop: 10 }}>{matchEnd.rounds.CT} : {matchEnd.rounds.T}</div>
          <button className="btn" onClick={() => navigate('/')} style={{ marginTop: 20, fontSize: 16, padding: '10px 24px' }}>返回大厅</button>
        </div>
      )}

      {/* 闪光致盲覆盖 */}
      {hud.blind > 0 && (
        <div style={{ position: 'absolute', inset: 0, background: '#fff', opacity: Math.min(1, hud.blind), pointerEvents: 'none', transition: 'opacity 0.2s' }} />
      )}
    </div>
  );
}
