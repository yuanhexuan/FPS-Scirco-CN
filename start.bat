@echo off
REM Windows 本地启动脚本（不依赖 Workerman 守护模式，用 node 替代 - 仅供调试）
chcp 65001 >nul
cd /d "%~dp0"

echo ==========================================
echo   FPS . scirco.cn 本地启动
echo ==========================================

REM 1) 环境变量
if not exist .env (
  echo [1/5] 未找到 .env，从 .env.example 复制
  copy .env.example .env >nul
) else (
  echo [1/5] .env 已存在
)

REM 2) 后端依赖
if not exist server\vendor (
  echo [2/5] 安装后端依赖...
  cd server && composer install --no-dev --no-interaction && cd ..
) else (
  echo [2/5] 后端依赖已就绪
)

REM 3) 前端构建
if not exist client\dist\index.html (
  echo [3/5] 构建前端...
  cd client && npm install --no-audit --no-fund && npm run build && cd ..
) else (
  echo [3/5] 前端产物已就绪
)

REM 4) 启动后端
echo [4/5] 启动后端服务（Workerman 调试模式，窗口 FPS-Server）...
start "FPS-Server" cmd /k "cd /d %~dp0server && php start.php start"

REM 5) 启动前端预览
echo [5/5] 启动前端预览（http://localhost:4173）...
cd client && start "" http://localhost:4173 && npx vite preview --port 4173

pause
