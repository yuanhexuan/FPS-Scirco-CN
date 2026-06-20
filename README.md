# FPS · scirco.cn

多人联机 3D FPS 网页游戏，**PHP + Workerman + SQLite** 栈，复刻 CS2 手感。

## 特性

- **账号系统**：注册/登录，SQLite 持久化（`server/data/fps.db`）
- **大厅系统**：房间列表、在线玩家、私聊、邀请组队
- **回合制爆破模式**：CT / T 双阵营，埋弹/拆弹，先达 13 回合胜
- **武器库**：USP-S、Glock-18、AK-47、M4A4、AWP、沙漠之鹰、电击枪、匕首
- **投掷物**：高爆手雷、闪光弹、烟雾弹、燃烧瓶
- **实时同步**：原生 WebSocket，30Hz 状态同步
- **单端口部署**：HTTP 与 WebSocket 共用 3000 端口，仅需宝塔 80/443 反代

## 目录结构

```
fps.scirco/
├── client/                  # React + Three.js 前端
│   └── src/
│       ├── pages/           # LoginPage / LobbyPage / RoomPage / GamePage
│       ├── game/engine.js   # Three.js 第一人称 + 子弹/爆炸/碰撞
│       ├── api.js / socket.js
│       └── styles/index.css
├── server/                  # PHP Workerman 后端
│   ├── start.php            # 入口（HTTP + WebSocket）
│   ├── composer.json
│   ├── src/
│   │   ├── config.php
│   │   ├── Database.php     # PDO SQLite
│   │   ├── Auth.php         # JWT
│   │   └── Game/            # Game / Room / Player / MatchManager / weapons
│   └── data/                # SQLite 数据
├── deploy/                  # Nginx 配置
├── start.sh / start.bat     # 一键启动脚本
└── .env.example
```

## 部署（宝塔 Linux）

### 1. 宝塔环境准备

- 安装 **PHP 8.1+**（必须启用 `pcntl` 扩展，Workerman 需要）
- 安装 **Composer**

### 2. 部署代码

```bash
cd /www/wwwroot/fps.scirco.cn

# 拉代码（首次）
git init
git remote add origin https://github.com/yuanhexuan/FPS-Scirco-CN.git
git pull origin main

# 启动
chmod +x start.sh
./start.sh
```

启动脚本会自动：检查环境 → 安装 composer 依赖 → 构建前端 → `php start.php start -d` 启动。

### 3. 配置 Nginx

1. 宝塔面板 → 网站 → 添加站点 → 域名 `fps.scirco.cn`，目录选上述路径，**PHP 版本选「纯静态」**
2. SSL → Let's Encrypt 申请证书
3. 网站设置 → 配置文件 → 用 `deploy/nginx.fps.scirco.cn.conf` 内容替换
4. 保存后 `nginx -s reload`

### 4. 放行端口

- 宝塔安全组：放行 80、443
- 云服务商安全组：同上
- 服务器内部端口 3000 **不需要对外放行**（只给 Nginx 反代访问）

## 启动 / 停止 / 重启

```bash
cd /www/wwwroot/fps.scirco.cn/server

php start.php start          # 前台启动（看日志）
php start.php start -d       # 守护模式（推荐）
php start.php stop           # 停止
php start.php restart        # 重启
php start.php status         # 查看状态
php start.php reload         # 平滑重载（热更新代码）
```

日志在 `server/data/workerman.log`（可在 start.php 里调整）。

## 手感参数调整

- `server/src/config.php` — 移动速度、回合时间、最大血量等
- `server/src/Game/weapons.php` — 每把枪的伤害、射速、后坐力、散射
- `client/src/game/engine.js` 顶部 `WEAPONS_INFO` — 客户端开火动画节奏
- `client/src/game/engine.js` 内 `sensitivity` / `speed` — 鼠标灵敏度与移速

修改后 `php start.php reload` 即可生效。

## 客户端构建

```bash
cd client
npm install
npm run dev     # 开发模式 http://localhost:5173
npm run build   # 生产构建到 dist/
```

## 资源与版权

3D 模型使用 Three.js 原生几何体（Box/Sphere）组装，**无外部 GLB 依赖**；所有武器/地图为独立实现，不包含任何 CS2 官方资源。
