# UAPI 部署指南

生产环境部署完整教程，包含服务器配置、安全加固、性能优化和监控告警。

## 目录

- [系统要求](#系统要求)
- [服务器准备](#服务器准备)
- [环境安装](#环境安装)
- [项目部署](#项目部署)
- [Nginx 配置](#nginx-配置)
- [SSL 证书](#ssl-证书)
- [数据库配置](#数据库配置)
- [定时任务](#定时任务)
- [安全加固](#安全加固)
- [性能优化](#性能优化)
- [监控告警](#监控告警)
- [备份策略](#备份策略)

---

## 系统要求

### 最低配置

| 组件 | 要求 |
|------|------|
| CPU | 2 核心 |
| 内存 | 4 GB |
| 磁盘 | 40 GB SSD |
| 带宽 | 10 Mbps |

### 推荐配置

| 组件 | 要求 |
|------|------|
| CPU | 4 核心 |
| 内存 | 8 GB |
| 磁盘 | 80 GB SSD |
| 带宽 | 100 Mbps |

### 软件要求

| 软件 | 版本 |
|------|------|
| PHP | 8.0+ |
| MySQL | 5.7+ / MariaDB 10.3+ |
| Nginx | 1.20+ |
| Node.js | 16+ |
| OpenSSL | 1.1+ |

---

## 服务器准备

### 选择云服务商

推荐云服务商：

| 服务商 | 特点 |
|--------|------|
| AWS | 全球覆盖，功能齐全 |
| DigitalOcean | 性价比高，简单易用 |
| Vultr | 按小时计费，灵活 |
| 阿里云 | 中国大陆优化 |
|腾讯云 | 中国大陆优化 |

### 操作系统

推荐 Ubuntu 22.04 LTS 或 Debian 11。

```bash
# 更新系统
apt update && apt upgrade -y

# 安装基础工具
apt install -y curl git unzip vim wget
```

---

## 环境安装

### 安装 PHP 8.0

```bash
# 添加 PHP 仓库
apt install -y software-properties-common
add-apt-repository -y ppa:ondrej/php

# 安装 PHP 和扩展
apt install -y php8.0-fpm php8.0-mysql php8.0-curl \
    php8.0-gd php8.0-mbstring php8.0-xml php8.0-zip \
    php8.0-bcmath php8.0-intl php8.0-soap
```

#### PHP 配置优化

编辑 `/etc/php/8.0/fpm/php.ini`：

```ini
[PHP]
memory_limit = 256M
max_execution_time = 60
upload_max_filesize = 10M
post_max_size = 10M

[Date]
date.timezone = UTC

[opcache]
opcache.enable = 1
opcache.memory_consumption = 128
opcache.interned_strings_buffer = 8
opcache.max_accelerated_files = 4000
opcache.revalidate_freq = 60
```

重启 PHP-FPM：

```bash
systemctl restart php8.0-fpm
```

---

### 安装 MySQL

```bash
# 安装 MySQL
apt install -y mysql-server

# 安全初始化
mysql_secure_installation
```

#### 创建数据库

```sql
-- 登录 MySQL
mysql -u root -p

-- 创建数据库
CREATE DATABASE uapi_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 创建用户
CREATE USER 'uapi'@'localhost' IDENTIFIED BY 'strong_password_here';

-- 授权
GRANT ALL PRIVILEGES ON uapi_db.* TO 'uapi'@'localhost';
FLUSH PRIVILEGES;

-- 退出
EXIT;
```

#### MySQL 配置优化

编辑 `/etc/mysql/mysql.conf.d/mysqld.cnf`：

```ini
[mysqld]
character-set-server = utf8mb4
collation-server = utf8mb4_unicode_ci

# 性能优化
innodb_buffer_pool_size = 2G
innodb_log_file_size = 256M
innodb_flush_log_at_trx_commit = 2
max_connections = 200

# 慢查询日志
slow_query_log = 1
slow_query_log_file = /var/log/mysql/mysql-slow.log
long_query_time = 2
```

重启 MySQL：

```bash
systemctl restart mysql
```

---

### 安装 Nginx

```bash
apt install -y nginx
```

#### Nginx 配置

编辑 `/etc/nginx/sites-available/uapi`：

```nginx
server {
    listen 80;
    server_name your-domain.com;
    
    # 重定向到 HTTPS
    return 301 https://$server_name$request_uri;
}

server {
    listen 443 ssl http2;
    server_name your-domain.com;
    
    root /var/www/uapi/public;
    index index.php;
    
    # SSL 证书
    ssl_certificate /etc/letsencrypt/live/your-domain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/your-domain.com/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;
    ssl_prefer_server_ciphers on;
    
    # 安全头
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    
    # 日志
    access_log /var/log/nginx/uapi_access.log;
    error_log /var/log/nginx/uapi_error.log;
    
    # Gzip 压缩
    gzip on;
    gzip_vary on;
    gzip_proxied any;
    gzip_comp_level 6;
    gzip_types text/plain text/css text/xml text/javascript application/json application/javascript application/xml+rss application/rss+xml font/truetype font/opentype application/vnd.ms-fontobject image/svg+xml;
    
    # 静态文件缓存
    location ~* \.(jpg|jpeg|png|gif|ico|css|js|pdf|txt|woff|woff2|ttf|eot|svg)$ {
        expires 30d;
        add_header Cache-Control "public, immutable";
    }
    
    # PHP 处理
    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.0-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param PATH_INFO $fastcgi_path_info;
        fastcgi_param PATH_TRANSLATED $document_root$fastcgi_path_info;
        
        # 隐藏敏感信息
        fastcgi_hide_header X-Powered-By;
        fastcgi_hide_header Server;
    }
    
    # 禁止访问敏感文件
    location ~ /\. {
        deny all;
    }
    
    location ~* /(config|src|cron|lang|\.git|\.venv) {
        deny all;
    }
    
    # API 特殊处理
    location /api/ {
        # API 限流
        limit_req zone=api burst=20 nodelay;
        
        # CORS 配置（根据需要调整）
        add_header Access-Control-Allow-Origin *;
        add_header Access-Control-Allow-Methods "GET, POST, OPTIONS";
        add_header Access-Control-Allow-Headers "X-API-KEY,Content-Type";
        
        if ($request_method = OPTIONS) {
            return 204;
        }
        
        try_files $uri $uri/ /index.php?$query_string;
    }
    
    # 主入口
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
}

# 限流配置
limit_req_zone $binary_remote_addr zone=api:10m rate=10r/s;
```

启用站点：

```bash
ln -s /etc/nginx/sites-available/uapi /etc/nginx/sites-enabled/
nginx -t
systemctl reload nginx
```

---

### 安装 Node.js

```bash
# 安装 Node.js 18
curl -fsSL https://deb.nodesource.com/setup_18.x | bash -
apt install -y nodejs

# 验证
node -v
npm -v
```

---

## 项目部署

### 克隆代码

```bash
# 创建目录
mkdir -p /var/www/uapi
cd /var/www/uapi

# 克隆代码（或上传源码）
git clone <repository-url> .
```

### 安装依赖

```bash
# 安装 Node.js 依赖
npm install

# 构建 Tailwind CSS
npx tailwindcss -i ./input.css -o ./public/output.css --minify
```

### 配置环境变量

```bash
# 复制环境文件
cp .env.example .env

# 编辑配置
vim .env
```

编辑 `.env`：

```env
DB_HOST=127.0.0.1
DB_NAME=uapi_db
DB_USER=uapi
DB_PASS=strong_password_here

# 派生地址服务（如使用）
DERIVED_ADDR_SERVICE_URL=http://127.0.0.1:8787
DERIVED_ADDR_SERVICE_TOKEN=your_secure_token
```

### 设置权限

```bash
# 设置所有者
chown -R www-data:www-data /var/www/uapi

# 设置目录权限
chmod -R 755 /var/www/uapi

# 敏感文件权限
chmod 600 /var/www/uapi/.env
chmod 600 /var/www/uapi/config/db.php
```

### 初始化数据库

数据表由内置 Migrator 自动创建，两种方式二选一：

**方式 A（推荐）：CLI 安装器**

```bash
cd /var/www/uapi
php scripts/install.php
```

`scripts/install.php` 读取 `.env` → 建表 → 播种默认管理员。脚本幂等，可重复执行。

**方式 B：首次 HTTP 访问自动迁移**

不执行 CLI 安装器时，首次访问站点任意页面也会自动完成建表和播种。

> 宝塔面板用户也可以直接用一键脚本 `bash scripts/bt_init.sh`：自动创建数据库和随机密码的数据库用户、写好 `.env`，并在内部调用 `scripts/install.php`。

**首次登录（重要）**

初始化会播种默认管理员：邮箱 `admin@example.com`，密码 `admin123`。在 `https://your-domain.com/login.php` 用邮箱+密码登录，登录后进入 `/admin/` 后台。

> 🚨 **安全警告**：`admin@example.com` / `admin123` 是公开的默认凭据，**首次登录后必须立即修改密码**，并建议开启 2FA。

验证部署：

```bash
# 测试 PHP
curl http://localhost/index.php

# 检查数据库连接
php -r "require '/var/www/uapi/config/config.php'; echo DB_HOST . PHP_EOL;"
```

---

## SSL 证书

### 使用 Let's Encrypt

```bash
# 安装 Certbot
apt install -y certbot python3-certbot-nginx

# 获取证书
certbot --nginx -d your-domain.com

# 自动续期测试
certbot renew --dry-run
```

### 配置自动续期

Certbot 会自动配置定时任务。验证：

```bash
# 查看定时任务
crontab -l | grep certbot
```

---

## 定时任务

### 配置 Crontab

```bash
# 编辑 crontab
crontab -e

# 添加以下任务
```

#### 完整配置

```bash
# UAPI 定时任务

# 每分钟：支付监控
* * * * * cd /var/www/uapi && php cron/monitor.php >> /var/log/uapi/monitor.log 2>&1

# 每小时：数据清理
0 * * * * cd /var/www/uapi && php cron/cleanup.php >> /var/log/uapi/cleanup.log 2>&1

# 每天 2:00：数据库备份
0 2 * * * mysqldump -u uapi -p'password' uapi_db > /backup/uapi_$(date +\%Y\%m\%d).sql

# 每天 3:00：清理 7 天前的备份
0 3 * * * find /backup -name "*.sql" -mtime +7 -delete

# 每周日 4:00：日志轮转
0 4 * * 0 find /var/log/uapi -name "*.log" -mtime +7 -exec gzip {} \;
```

### 创建日志目录

```bash
mkdir -p /var/log/uapi
chown www-data:www-data /var/log/uapi
```

---

## 安全加固

### 防火墙配置

```bash
# 安装 UFW
apt install -y ufw

# 配置规则
ufw default deny incoming
ufw default allow outgoing
ufw allow 22/tcp      # SSH
ufw allow 80/tcp      # HTTP
ufw allow 443/tcp     # HTTPS

# 启用防火墙
ufw enable
ufw status
```

### SSH 安全

编辑 `/etc/ssh/sshd_config`：

```bash
# 禁止 root 登录
PermitRootLogin no

# 禁用密码登录（使用密钥）
PasswordAuthentication no

# 更改 SSH 端口（可选）
Port 2222

# 限制用户
AllowUsers deploy

# 禁用空密码
PermitEmptyPasswords no

# 限制尝试次数
MaxAuthTries 3
```

重启 SSH：

```bash
systemctl restart sshd
```

### 文件权限

```bash
# 敏感目录禁止访问
chmod -R 700 /var/www/uapi/config
chmod -R 700 /var/www/uapi/src
chmod -R 700 /var/www/uapi/cron

# .env 文件
chmod 600 /var/www/uapi/.env
```

### 数据库安全

```sql
-- 限制远程连接
UPDATE mysql.user SET Host='localhost' WHERE User='uapi';
FLUSH PRIVILEGES;

-- 删除测试数据库
DROP DATABASE IF EXISTS test;

-- 删除匿名用户
DELETE FROM mysql.user WHERE User='';
```

---

## 性能优化

### PHP OPcache

已在 PHP 配置中启用，验证状态：

```bash
php -v | grep -i opcache
```

### Redis 缓存（可选）

```bash
# 安装 Redis
apt install -y redis-server

# 配置 Redis
vim /etc/redis/redis.conf
```

```ini
bind 127.0.0.1
port 6379
maxmemory 256mb
maxmemory-policy allkeys-lru
```

重启 Redis：

```bash
systemctl restart redis
```

### 数据库优化

```sql
-- 添加索引
ALTER TABLE orders ADD INDEX idx_status_created (status, created_at);
ALTER TABLE orders ADD INDEX idx_merchant_order (merchant_order_id);
ALTER TABLE users ADD INDEX idx_api_key (api_key);
ALTER TABLE users ADD INDEX idx_email (email);

-- 分析表
ANALYZE TABLE orders;
ANALYZE TABLE users;

-- 优化表
OPTIMIZE TABLE orders;
OPTIMIZE TABLE users;
```

### CDN 加速

配置 Cloudflare 或其他 CDN 服务：

1. 添加域名到 CDN
2. 更新 DNS 记录
3. 配置 SSL/TLS
4. 启用缓存规则

---

## 监控告警

### 系统监控

#### 安装 Netdata（实时监控系统）

```bash
bash <(curl -Ss https://my-netdata.io/kickstart.sh)
```

访问：`http://your-server-ip:19999`

### 应用监控

#### 创建健康检查端点

```php
<?php
// public/health.php
require_once __DIR__ . '/inc/bootstrap.php';

header('Content-Type: application/json');

$checks = [
    'database' => false,
    'disk' => false,
    'memory' => false
];

// 数据库检查
try {
    $db = Database::getInstance();
    $db->query("SELECT 1");
    $checks['database'] = true;
} catch (Exception $e) {
    $checks['database_error'] = $e->getMessage();
}

// 磁盘检查
$diskFree = disk_free_space('/');
$checks['disk'] = $diskFree > 1024 * 1024 * 1024; // > 1GB
$checks['disk_free_gb'] = round($diskFree / 1024 / 1024 / 1024, 2);

// 内存检查
$memoryFree = shell_exec("free -m | awk '/^Mem:/{print $7}'");
$checks['memory'] = (int)$memoryFree > 512; // > 512MB
$checks['memory_free_mb'] = (int)$memoryFree;

// 总体状态
$status = in_array(false, $checks, true) ? 'unhealthy' : 'healthy';

http_response_code($status === 'healthy' ? 200 : 503);
echo json_encode([
    'status' => $status,
    'checks' => $checks,
    'timestamp' => date('c')
]);
```

### Uptime 监控

使用外部监控服务：

| 服务 | 特点 |
|------|------|
| UptimeRobot | 免费 50 个监控点 |
| Pingdom | 专业级监控 |
| StatusCake | 免费基础监控 |

配置告警通知：
- 网站不可用
- 响应时间过长
- SSL 证书即将过期

---

## 备份策略

### 数据库备份

#### 自动备份脚本

```bash
#!/bin/bash
# /usr/local/bin/backup-uapi.sh

BACKUP_DIR="/backup/uapi"
DATE=$(date +%Y%m%d_%H%M%S)
DB_NAME="uapi_db"
DB_USER="uapi"
DB_PASS="your_password"

# 创建备份目录
mkdir -p $BACKUP_DIR

# 备份数据库
mysqldump -u $DB_USER -p$DB_PASS \
    --single-transaction \
    --quick \
    --lock-tables=false \
    $DB_NAME > $BACKUP_DIR/db_$DATE.sql

# 压缩备份
gzip $BACKUP_DIR/db_$DATE.sql

# 备份文件（可选，排除敏感文件）
tar -czf $BACKUP_DIR/files_$DATE.tar.gz \
    --exclude='.env' \
    --exclude='config/db.php' \
    /var/www/uapi

# 删除 7 天前的备份
find $BACKUP_DIR -name "*.sql.gz" -mtime +7 -delete
find $BACKUP_DIR -name "*.tar.gz" -mtime +7 -delete

echo "Backup completed: $DATE"
```

#### 远程备份

```bash
# 同步到远程服务器
rsync -avz /backup/uapi/ user@backup-server:/backup/uapi/

# 或使用 AWS S3
aws s3 sync /backup/uapi/ s3://your-bucket/uapi-backup/
```

### 备份验证

定期测试备份恢复：

```bash
# 恢复测试
gunzip < db_20260408.sql.gz | mysql -u uapi -p uapi_db_test
```

---

## 部署检查清单

部署完成后，逐项检查：

### 基础环境

- [ ] PHP 8.0+ 已安装
- [ ] MySQL 已安装并配置
- [ ] Nginx 已安装并配置
- [ ] Node.js 已安装
- [ ] SSL 证书已配置

### 应用配置

- [ ] 代码已部署
- [ ] 依赖已安装
- [ ] .env 已配置
- [ ] 数据库已初始化（`php scripts/install.php` 或首次访问自动迁移）
- [ ] **默认管理员密码已修改**（admin / admin123 → 强密码）
- [ ] 文件权限已设置

### 定时任务

- [ ] monitor.php 已配置
- [ ] cleanup.php 已配置
- [ ] 备份任务已配置

### 安全加固

- [ ] 防火墙已启用
- [ ] SSH 已加固
- [ ] 敏感目录已保护
- [ ] 数据库已限制远程访问

### 监控告警

- [ ] 健康检查端点可用
- [ ] 外部监控已配置
- [ ] 告警通知已设置

### 性能优化

- [ ] OPcache 已启用
- [ ] Gzip 压缩已启用
- [ ] 静态资源缓存已配置
- [ ] 数据库索引已添加

---

## 故障排查

### 常见问题

#### 502 Bad Gateway

**原因：** PHP-FPM 未运行

```bash
systemctl status php8.0-fpm
systemctl restart php8.0-fpm
```

#### 数据库连接失败

**检查：**

```bash
# 测试连接
mysql -u uapi -p -e "SELECT 1"

# 检查 MySQL 状态
systemctl status mysql
```

#### 权限错误

```bash
# 修复权限
chown -R www-data:www-data /var/www/uapi
chmod -R 755 /var/www/uapi
```

#### 定时任务不执行

**检查：**

```bash
# 查看 cron 日志
grep CRON /var/log/syslog

# 验证 crontab
crontab -l

# 手动执行测试
php /var/www/uapi/cron/monitor.php
```

---

*最后更新：2026 年 4 月 8 日*
