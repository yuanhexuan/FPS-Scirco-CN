#!/usr/bin/env bash
# 一键启动 FPS 服务器（宝塔 Linux / 任何 Linux 服务器通用）
# 用法：把代码上传到 /www/wwwroot/fps.scirco.cn 后
#      cd /www/wwwroot/fps.scirco.cn
#      chmod +x start.sh
#      ./start.sh

set -e

# 切到脚本所在目录（无论从哪里调用都对）
cd "$(dirname "$0")"

echo "=========================================="
echo "  FPS · scirco.cn 一键启动 (PHP + Workerman)"
echo "=========================================="

# 1) 检查 PHP
if ! command -v php >/dev/null 2>&1; then
  echo "[!] 未检测到 php，请先在宝塔安装 PHP 8.1+（需启用 pcntl 扩展）"
  exit 1
fi
echo "[1/6] PHP 版本: $(php -v | head -n1)"

# 2) Composer
if ! command -v composer >/dev/null 2>&1; then
  echo "[2/6] 未安装 composer，正在安装..."
  curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
fi
echo "[2/6] Composer 已就绪"

# 3) 环境变量
if [ ! -f .env ]; then
  echo "[3/6] 未找到 .env，已从 .env.example 复制（请修改 JWT_SECRET）"
  cp .env.example .env
else
  echo "[3/6] .env 已存在"
fi

# 4) 后端依赖
if [ ! -d server/vendor ]; then
  echo "[4/6] 安装后端依赖（Workerman + JWT）..."
  (cd server && composer install --no-dev --optimize-autoloader --no-interaction)
else
  echo "[4/6] 后端依赖已就绪"
fi

# 5) 前端构建
if [ ! -d client/dist ] || [ ! -f client/dist/index.html ]; then
  echo "[5/6] 构建前端..."
  (cd client && npm install --no-audit --no-fund && npm run build)
else
  echo "[5/6] 前端产物已就绪（client/dist/）"
fi

# 6) 启动
echo "[6/6] 启动 Workerman 服务..."
cd server
# 停止旧的
php start.php stop 2>/dev/null || true
sleep 1
# 守护模式启动
php start.php start -d

echo ""
echo "=========================================="
echo "  ✅ 启动完成！"
echo "  - 健康检查: curl http://127.0.0.1:3000/health"
echo "  - 进程查看: ps aux | grep start.php"
echo "  - 重启: cd server && php start.php restart"
echo "  - 停止: cd server && php start.php stop"
echo "  - 实时日志: tail -f server/data/workerman.log"
echo "=========================================="
