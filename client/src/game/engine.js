// FPS 游戏引擎 - Three.js 第一人称渲染 + 物理 + 粒子
import * as THREE from 'three';

// 武器参数（客户端渲染 & 开火节奏用）
const WEAPONS_INFO = {
  usp:     { name: 'USP-S',  magSize: 12, fireRate: 300, damage: 35,  spread: 0.04, bulletSpeed: 1100, recoil: { pitch: 0.03, yaw: 0.01 } },
  glock:   { name: 'Glock-18', magSize: 20, fireRate: 250, damage: 26, spread: 0.045, bulletSpeed: 1100, recoil: { pitch: 0.025, yaw: 0.01 } },
  deagle:  { name: 'Desert Eagle', magSize: 7, fireRate: 350, damage: 63, spread: 0.05, bulletSpeed: 1400, recoil: { pitch: 0.08, yaw: 0.02 } },
  ak47:    { name: 'AK-47',  magSize: 30, fireRate: 100, damage: 36,  spread: 0.03, bulletSpeed: 1200, recoil: { pitch: 0.05, yaw: 0.015 } },
  m4a4:    { name: 'M4A4',   magSize: 30, fireRate: 90,  damage: 33,  spread: 0.025, bulletSpeed: 1250, recoil: { pitch: 0.04, yaw: 0.012 } },
  awp:     { name: 'AWP',    magSize: 10, fireRate: 1500, damage: 115, spread: 0.008, bulletSpeed: 1500, recoil: { pitch: 0.12, yaw: 0.03 }, scope: true },
  zeus:    { name: 'Zeus',   magSize: 1,  fireRate: 800, damage: 9999, spread: 0.0,  bulletSpeed: 1000, recoil: { pitch: 0, yaw: 0 }, melee: true },
  knife:   { name: 'Knife',  magSize: 999, fireRate: 400, damage: 55,  spread: 0,    bulletSpeed: 0,    recoil: { pitch: 0, yaw: 0 }, melee: true },
};

// 掩体（与服务器保持一致）
const OBSTACLES = [
  { x: 0, y: 0, z: 0, w: 8, h: 3, d: 8 },
  { x: -25, y: 0, z: 0, w: 10, h: 3, d: 4 },
  { x: 25, y: 0, z: 0, w: 10, h: 3, d: 4 },
  { x: 0, y: 0, z: -25, w: 4, h: 3, d: 10 },
  { x: 0, y: 0, z: 25, w: 4, h: 3, d: 10 },
  { x: -12, y: 0, z: 15, w: 6, h: 2.5, d: 6 },
  { x: 12, y: 0, z: -15, w: 6, h: 2.5, d: 6 },
];

const BOMB_SITES = [
  { id: 'A', x: -12, z: 15, radius: 5 },
  { id: 'B', x: 12, z: -15, radius: 5 },
];

export function initGame(canvas, initialData, socket, onHudUpdate) {
  const scene = new THREE.Scene();
  scene.background = new THREE.Color(0x87a5b8);
  scene.fog = new THREE.Fog(0x87a5b8, 20, 160);

  const renderer = new THREE.WebGLRenderer({ canvas, antialias: true });
  renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
  renderer.setSize(canvas.clientWidth, canvas.clientHeight, false);
  renderer.shadowMap.enabled = true;

  const camera = new THREE.PerspectiveCamera(75, canvas.clientWidth / canvas.clientHeight, 0.1, 500);
  camera.position.set(0, 1.7, 0);

  // 光照（写实风格）
  const hemi = new THREE.HemisphereLight(0xcfe5ff, 0x3a3a3a, 0.55);
  scene.add(hemi);
  const sun = new THREE.DirectionalLight(0xfff2d0, 1.0);
  sun.position.set(30, 60, 20);
  sun.castShadow = true;
  sun.shadow.mapSize.set(2048, 2048);
  sun.shadow.camera.left = -60; sun.shadow.camera.right = 60;
  sun.shadow.camera.top = 60; sun.shadow.camera.bottom = -60;
  scene.add(sun);
  scene.add(new THREE.AmbientLight(0xffffff, 0.15));

  // 地面
  const groundGeo = new THREE.PlaneGeometry(200, 200, 50, 50);
  const groundMat = new THREE.MeshStandardMaterial({ color: 0x6b705c, roughness: 0.95 });
  const ground = new THREE.Mesh(groundGeo, groundMat);
  ground.rotation.x = -Math.PI / 2;
  ground.receiveShadow = true;
  scene.add(ground);

  // 地图边界墙壁（美化视觉）
  const wallMat = new THREE.MeshStandardMaterial({ color: 0x8a8a8a, roughness: 0.9 });
  const borderHeight = 4;
  const borders = [
    { x: 0, z: -50, w: 100, d: 1 },
    { x: 0, z: 50, w: 100, d: 1 },
    { x: -50, z: 0, w: 1, d: 100 },
    { x: 50, z: 0, w: 1, d: 100 },
  ];
  borders.forEach(b => {
    const m = new THREE.Mesh(new THREE.BoxGeometry(b.w, borderHeight, b.d), wallMat);
    m.position.set(b.x, borderHeight / 2, b.z);
    m.castShadow = true; m.receiveShadow = true;
    scene.add(m);
  });

  // 掩体
  const obstacleMat = new THREE.MeshStandardMaterial({ color: 0x5b5b5b, roughness: 0.8 });
  const obstacleMeshes = OBSTACLES.map(o => {
    const m = new THREE.Mesh(new THREE.BoxGeometry(o.w, o.h, o.d), obstacleMat);
    m.position.set(o.x, o.h / 2, o.z);
    m.castShadow = true; m.receiveShadow = true;
    scene.add(m);
    return m;
  });

  // 爆破点标记
  BOMB_SITES.forEach(site => {
    const ring = new THREE.Mesh(
      new THREE.RingGeometry(site.radius - 0.3, site.radius, 32),
      new THREE.MeshBasicMaterial({ color: site.id === 'A' ? 0x3aa0ff : 0xff5050, side: THREE.DoubleSide, transparent: true, opacity: 0.6 })
    );
    ring.rotation.x = -Math.PI / 2;
    ring.position.set(site.x, 0.02, site.z);
    scene.add(ring);
  });

  // 玩家模型（其他玩家的“人形”）
  const otherPlayers = new Map(); // playerId -> { group, head, body, label, lastPos, lastTime }

  function buildPlayerMesh(player) {
    const group = new THREE.Group();
    const bodyColor = player.team === 'CT' ? 0x3a6ea5 : 0xa55a3a;
    const bodyMat = new THREE.MeshStandardMaterial({ color: bodyColor, roughness: 0.8 });
    const headMat = new THREE.MeshStandardMaterial({ color: 0xeac39f, roughness: 0.85 });
    const body = new THREE.Mesh(new THREE.BoxGeometry(0.5, 1.0, 0.3), bodyMat);
    body.position.y = 0.85;
    body.castShadow = true;
    const head = new THREE.Mesh(new THREE.SphereGeometry(0.2, 16, 12), headMat);
    head.position.y = 1.55;
    head.castShadow = true;
    const legMat = new THREE.MeshStandardMaterial({ color: 0x222222, roughness: 0.9 });
    const legL = new THREE.Mesh(new THREE.BoxGeometry(0.2, 0.8, 0.2), legMat);
    legL.position.set(-0.12, 0.4, 0);
    const legR = legL.clone(); legR.position.x = 0.12;
    group.add(body, head, legL, legR);

    // 武器
    const gunMat = new THREE.MeshStandardMaterial({ color: 0x1a1a1a, roughness: 0.6, metalness: 0.6 });
    const gun = new THREE.Mesh(new THREE.BoxGeometry(0.08, 0.08, 0.45), gunMat);
    gun.position.set(0.18, 1.15, 0.25);
    group.add(gun);
    group.userData.gun = gun;

    group.position.set(player.pos?.x || 0, 0, player.pos?.z || 0);
    scene.add(group);
    return { group, head, body };
  }

  // 第一人称手臂/枪模型
  const armGroup = new THREE.Group();
  armGroup.position.set(0.3, -0.25, -0.5);
  const armMat = new THREE.MeshStandardMaterial({ color: 0x333333, roughness: 0.85 });
  const arm = new THREE.Mesh(new THREE.BoxGeometry(0.15, 0.15, 0.6), armMat);
  arm.position.set(0, 0, -0.1);
  const gunMatFp = new THREE.MeshStandardMaterial({ color: 0x1a1a1a, roughness: 0.5, metalness: 0.7 });
  const gunFp = new THREE.Mesh(new THREE.BoxGeometry(0.1, 0.12, 0.55), gunMatFp);
  gunFp.position.set(0, 0.05, -0.45);
  armGroup.add(arm, gunFp);
  camera.add(armGroup);
  scene.add(camera);

  // 枪口火花
  const muzzleGeo = new THREE.SphereGeometry(0.12, 8, 8);
  const muzzleMat = new THREE.MeshBasicMaterial({ color: 0xffcc33, transparent: true, opacity: 0.9 });
  const muzzleFlash = new THREE.Mesh(muzzleGeo, muzzleMat);
  muzzleFlash.position.set(0, 0.05, -0.78);
  muzzleFlash.visible = false;
  armGroup.add(muzzleFlash);

  // 子弹痕迹/粒子
  const bulletHoles = [];
  const bulletTrails = [];

  // 爆炸烟雾
  const explosions = [];

  // 自己的状态
  const self = {
    pos: new THREE.Vector3(0, 1.7, 0),
    vel: new THREE.Vector3(),
    yaw: initialData?.player?.yaw ?? 0,
    pitch: 0,
    onGround: true,
    crouching: false,
    scoping: false,
    alive: true,
    weapon: initialData?.player?.currentWeapon || 'usp',
    ammo: { magazine: 12, reserve: 120 },
    lastShotAt: 0,
    reloading: false,
    keys: {},
    flashUntil: 0,
    spawnPos: initialData?.player?.pos || { x: 0, z: 0 },
  };
  self.pos.x = self.spawnPos.x || 0;
  self.pos.z = self.spawnPos.z || 0;
  camera.position.copy(self.pos);

  onHudUpdate({ team: initialData?.team || 'CT', weapon: self.weapon, ammo: self.ammo });

  // 键盘/鼠标控制
  const onKeyDown = (e) => {
    self.keys[e.code] = true;
    self.keys[e.key.toLowerCase()] = true;
    if (e.code === 'Space' && self.onGround && self.alive) {
      self.vel.y = 8.2; self.onGround = false;
    }
    if (e.key.toLowerCase() === 'r') tryReload();
    if (e.key.toLowerCase() === 'e') tryPlantOrDefuse();
    if (e.key.toLowerCase() === 'g') throwGrenade();
    if (['Digit1','Digit2','Digit3','Digit4','Digit5','Digit6'].includes(e.code)) {
      const map = { Digit1: 'usp', Digit2: 'ak47', Digit3: 'awp', Digit4: 'knife', Digit5: 'deagle', Digit6: 'zeus' };
      const w = map[e.code];
      if (w) { self.weapon = w; socket.emit('selectWeapon', { weapon: w }); onHudUpdate({ weapon: w }); }
    }
  };
  const onKeyUp = (e) => {
    self.keys[e.code] = false;
    self.keys[e.key.toLowerCase()] = false;
  };
  window.addEventListener('keydown', onKeyDown);
  window.addEventListener('keyup', onKeyUp);

  // 鼠标锁定
  let pointerLocked = false;
  const onClick = () => { if (!pointerLocked) canvas.requestPointerLock?.(); };
  canvas.addEventListener('click', onClick);
  const onLockChange = () => { pointerLocked = document.pointerLockElement === canvas; };
  document.addEventListener('pointerlockchange', onLockChange);
  canvas.addEventListener('mousedown', (e) => {
    if (!pointerLocked) return;
    if (e.button === 0) fire();
    else if (e.button === 2) { self.scoping = !self.scoping; onHudUpdate({ scoping: self.scoping }); }
  });
  canvas.addEventListener('contextmenu', (e) => e.preventDefault());
  const onMouseMove = (e) => {
    if (!pointerLocked) return;
    const sensitivity = self.scoping ? 0.0008 : 0.0022;
    self.yaw -= e.movementX * sensitivity;
    self.pitch -= e.movementY * sensitivity;
    self.pitch = Math.max(-Math.PI / 2 + 0.01, Math.min(Math.PI / 2 - 0.01, self.pitch));
  };
  document.addEventListener('mousemove', onMouseMove);

  function fire() {
    if (!self.alive) return;
    const info = WEAPONS_INFO[self.weapon];
    if (!info) return;
    if (self.reloading) return;
    if (Date.now() - self.lastShotAt < info.fireRate) return;
    if (self.ammo.magazine <= 0) return;
    self.lastShotAt = Date.now();
    self.ammo.magazine -= 1;
    onHudUpdate({ ammo: { ...self.ammo }, weapon: self.weapon });
    socket.emit('shoot');

    // 后坐力（视觉）
    armGroup.position.z = -0.55;
    setTimeout(() => { armGroup.position.z = -0.5; }, 70);
    muzzleFlash.visible = true;
    setTimeout(() => { muzzleFlash.visible = false; }, 60);

    // 视觉子弹轨迹
    const dir = new THREE.Vector3(Math.sin(self.yaw) * Math.cos(self.pitch), Math.sin(self.pitch), Math.cos(self.yaw) * Math.cos(self.pitch));
    const start = new THREE.Vector3(self.pos.x, self.pos.y + 0.05, self.pos.z);
    const trailGeo = new THREE.BufferGeometry().setFromPoints([start, start.clone().add(dir.multiplyScalar(50))]);
    const trail = new THREE.Line(trailGeo, new THREE.LineBasicMaterial({ color: 0xffeeaa, transparent: true, opacity: 0.85 }));
    scene.add(trail);
    bulletTrails.push({ line: trail, until: Date.now() + 80 });
  }

  function tryReload() {
    const info = WEAPONS_INFO[self.weapon];
    if (!info || info.melee) return;
    if (self.ammo.magazine >= info.magSize) return;
    if (self.ammo.reserve <= 0) return;
    self.reloading = true;
    onHudUpdate({ reloading: true });
    socket.emit('reload');
    setTimeout(() => {
      const need = info.magSize - self.ammo.magazine;
      const take = Math.min(need, self.ammo.reserve);
      self.ammo.magazine += take;
      self.ammo.reserve -= take;
      self.reloading = false;
      onHudUpdate({ ammo: { ...self.ammo }, reloading: false });
    }, info.fireRate > 1000 ? 3200 : 1800);
  }

  function throwGrenade() {
    if (!self.alive) return;
    socket.emit('throwGrenade', { type: 'he' });
  }

  function tryPlantOrDefuse() {
    if (!self.alive) return;
    // 距离最近的埋弹点
    let nearestSite = null;
    let nearestDist = Infinity;
    for (const s of BOMB_SITES) {
      const d = Math.hypot(self.pos.x - s.x, self.pos.z - s.z);
      if (d < nearestDist) { nearestDist = d; nearestSite = s; }
    }
    if (nearestSite && nearestDist < nearestSite.radius + 1) {
      socket.emit('plantBomb');
    } else {
      socket.emit('startDefuse');
    }
  }

  // 快照渲染
  function onSnapshot(snap) {
    const players = snap.players || [];
    const seen = new Set();
    players.forEach(p => {
      seen.add(p.id);
      let entry = otherPlayers.get(p.id);
      if (!entry) {
        entry = buildPlayerMesh(p);
        otherPlayers.set(p.id, entry);
      }
      // 平滑插值（避免瞬移）
      const now = performance.now();
      const target = new THREE.Vector3(p.pos?.x || 0, 0, p.pos?.z || 0);
      entry.group.position.lerp(target, 0.5);
      if (typeof p.yaw === 'number') entry.group.rotation.y = p.yaw;
      entry.group.visible = !!p.alive;
      entry.group.userData.team = p.team;
    });
    // 清理离开玩家
    for (const [id, entry] of otherPlayers) {
      if (!seen.has(id)) {
        scene.remove(entry.group);
        otherPlayers.delete(id);
      }
    }
    // 自身 HUD 同步（来自服务器的权威数据）
    const me = players.find(p => p.username === (localStorage.getItem('fps_user') ? JSON.parse(localStorage.getItem('fps_user')).username : null));
    if (me) {
      self.pos.x = me.pos?.x ?? self.pos.x;
      self.pos.z = me.pos?.z ?? self.pos.z;
      self.ammo = me.ammo || self.ammo;
      self.weapon = me.currentWeapon || self.weapon;
      onHudUpdate({
        health: Math.round(me.health ?? 100),
        armor: Math.round(me.armor ?? 0),
        money: (me.money ?? self.money) || 800,
        weapon: self.weapon,
        ammo: self.ammo,
        team: me.team,
        kills: me.roundKills,
        headshots: me.roundHeadshots,
      });
    }
  }

  function addBulletEffect(b) {
    // 其他玩家子弹火花（简化：在 shooter 位置生成一次短闪）
  }

  function onHit(h) {
    // 击中提示
    const hitEl = document.createElement('div');
    hitEl.textContent = 'HIT';
    hitEl.style.cssText = 'position:absolute;left:50%;top:45%;transform:translate(-50%,-50%);color:#ff4d4f;font-size:22px;font-weight:700;pointer-events:none;opacity:1;transition:opacity 0.5s';
    document.querySelector('.hud')?.parentElement?.appendChild(hitEl);
    setTimeout(() => { if (hitEl.parentElement) hitEl.parentElement.removeChild(hitEl); }, 400);
  }

  function addExplosion(e) {
    const geo = new THREE.IcosahedronGeometry(1.5, 1);
    const mat = new THREE.MeshBasicMaterial({ color: e.type === 'flash' ? 0xffffff : 0xff8833, transparent: true, opacity: 0.9 });
    const mesh = new THREE.Mesh(geo, mat);
    mesh.position.set(e.pos?.x || 0, 1, e.pos?.z || 0);
    scene.add(mesh);
    explosions.push({ mesh, type: e.type, until: Date.now() + 900 });
  }

  // 主循环
  let lastTime = performance.now();
  let running = true;
  function tick() {
    if (!running) return;
    const now = performance.now();
    const dt = Math.min(0.05, (now - lastTime) / 1000);
    lastTime = now;

    // 输入 -> 运动
    if (self.alive) {
      const forward = (self.keys['KeyW'] ? 1 : 0) - (self.keys['KeyS'] ? 1 : 0);
      const strafe = (self.keys['KeyD'] ? 1 : 0) - (self.keys['KeyA'] ? 1 : 0);
      const speed = self.keys['ShiftLeft'] || self.keys['ShiftRight'] ? 2.2 : 6.5;
      const dx = Math.sin(self.yaw);
      const dz = Math.cos(self.yaw);
      let vx = -forward * dx * speed + strafe * dz * speed;
      let vz = -forward * dz * speed - strafe * dx * speed;

      // 重力 + 地面
      self.vel.y -= 22 * dt;
      self.pos.y += self.vel.y * dt;
      if (self.pos.y <= 1.7) { self.pos.y = 1.7; self.vel.y = 0; self.onGround = true; }

      // 水平移动 + 简化的掩体碰撞（AABB 推开）
      let newX = self.pos.x + vx * dt;
      let newZ = self.pos.z + vz * dt;
      const radius = 0.35;
      for (const o of OBSTACLES) {
        // 玩家在箱子外；若进入 AABB 边界则回退
        const hx = o.w / 2 + radius, hz = o.d / 2 + radius;
        if (Math.abs(newX - o.x) < hx && Math.abs(self.pos.z - o.z) < hz) {
          newX = self.pos.x;
        }
        if (Math.abs(self.pos.x - o.x) < hx && Math.abs(newZ - o.z) < hz) {
          newZ = self.pos.z;
        }
      }
      // 地图边界
      newX = Math.max(-49, Math.min(49, newX));
      newZ = Math.max(-49, Math.min(49, newZ));
      self.pos.x = newX; self.pos.z = newZ;

      camera.position.copy(self.pos);
      camera.rotation.order = 'YXZ';
      camera.rotation.y = self.yaw;
      camera.rotation.x = self.pitch;
      camera.fov = self.scoping ? 28 : 75;
      camera.updateProjectionMatrix();

      // 发送到服务器（约 30Hz）
      if (now - (self.lastInputAt || 0) > 33) {
        self.lastInputAt = now;
        socket.emit('input', {
          pos: { x: self.pos.x, y: self.pos.y, z: self.pos.z },
          vel: { x: vx, y: self.vel.y, z: vz },
          yaw: self.yaw, pitch: self.pitch,
          crouching: !!self.keys['ShiftLeft'],
          scoping: self.scoping,
          onGround: self.onGround,
        });
      }
    }

    // 清理子弹轨迹
    const tnow = Date.now();
    for (let i = bulletTrails.length - 1; i >= 0; i--) {
      if (tnow > bulletTrails[i].until) { scene.remove(bulletTrails[i].line); bulletTrails.splice(i, 1); }
    }
    // 爆炸动画
    for (let i = explosions.length - 1; i >= 0; i--) {
      const ex = explosions[i];
      const age = (tnow - (ex.until - 900)) / 900;
      ex.mesh.scale.setScalar(1 + age * 3);
      ex.mesh.material.opacity = Math.max(0, 0.9 * (1 - age));
      if (tnow > ex.until) { scene.remove(ex.mesh); explosions.splice(i, 1); }
    }

    renderer.render(scene, camera);
    requestAnimationFrame(tick);
  }
  requestAnimationFrame(tick);

  // 响应式
  const onResize = () => {
    renderer.setSize(canvas.clientWidth, canvas.clientHeight, false);
    camera.aspect = canvas.clientWidth / canvas.clientHeight;
    camera.updateProjectionMatrix();
  };
  window.addEventListener('resize', onResize);

  return {
    onSnapshot,
    addBulletEffect,
    addExplosion,
    onHit,
    releasePointer: () => document.exitPointerLock?.(),
    destroy: () => {
      running = false;
      window.removeEventListener('keydown', onKeyDown);
      window.removeEventListener('keyup', onKeyUp);
      window.removeEventListener('resize', onResize);
      document.removeEventListener('mousemove', onMouseMove);
      document.removeEventListener('pointerlockchange', onLockChange);
      renderer.dispose();
    },
  };
}

export function destroyGame(g) {
  if (g && g.destroy) g.destroy();
}

export function sendInput() {}
