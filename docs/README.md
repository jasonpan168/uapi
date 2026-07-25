# UAPI 文档索引

欢迎来到 UAPI 文档中心。本文档包含完整的用户指南、开发者教程、API 参考和管理员手册。

## 📚 文档导航

### 新手入门

| 文档 | 说明 | 预计时间 |
|------|------|----------|
| [快速开始](./QUICKSTART.md) | 5 分钟完成首次支付集成 | 5 分钟 |
| [用户指南](./USER.md) | 商家控制台使用手册 | 15 分钟 |
| [常见问题](#常见问题) | 常见问题解答 | - |

### 开发者

| 文档 | 说明 | 预计时间 |
|------|------|----------|
| [开发者指南](./DEVELOPER.md) | 完整集成教程和代码示例 | 30 分钟 |
| [API 参考](./API.md) | 完整的 API 端点文档 | - |
| [SDK 示例](./DEVELOPER.md#代码示例) | PHP/Node.js/Python示例 | - |

### 运维部署

| 文档 | 说明 | 预计时间 |
|------|------|----------|
| [部署指南](./DEPLOYMENT.md) | 生产环境部署完整教程 | 60 分钟 |
| [配置说明](./DEPLOYMENT.md#配置说明) | 环境和数据库配置 | - |
| [定时任务](./DEPLOYMENT.md#定时任务) | Cron 任务配置 | - |

### 管理员

| 文档 | 说明 | 预计时间 |
|------|------|----------|
| [管理员手册](./ADMIN.md) | 后台管理系统使用手册 | 20 分钟 |
| [系统设置](./ADMIN.md#系统设置) | 系统配置项说明 | - |
| [财务报表](./ADMIN.md#财务报表) | 对账和报表功能 | - |

---

## 🚀 快速链接

### API 端点

```
你的实例：https://your-domain.com/api/v1
```

> UAPI 是自部署系统，下文所有 `https://your-domain.com` 都请替换为你自己部署的域名。

### 常用 API

| 端点 | 方法 | 鉴权 | 说明 |
|---|---|---|---|
| `/api/v1/order/create.php` | POST | X-API-KEY | 创建订单（**唯一对外的核心写接口**） |
| `/api/v1/order/status.php` | GET | per-order token | 查询订单状态（用订单的 `pay_access_token`，不用 API Key） |
| `/api/v1/order/dispute.php` | POST | X-API-KEY | 提交订单争议 |
| `/api/v1/binance/webhook.php` | POST | 币安签名 | 币安回调入口（你不会主动调） |
| `/api/v1/stripe/webhook.php` | POST | Stripe 签名 | Stripe 回调入口（你不会主动调） |

> 旧 README 曾列 `/order/query.php` / `/order/cancel.php` / `/user/info.php` / `/chain/list.php` —
> **这些端点目前不存在**。需要订单详情请走 Webhook 或 `/order/status.php`；取消订单
> 目前只能后台手动或等 TTL 自动过期。

### 控制台入口

| 功能 | URL |
|------|-----|
| 登录 | https://your-domain.com/login.php |
| 注册 | https://your-domain.com/register.php |
| 仪表板 | https://your-domain.com/dashboard.php |
| 订单管理 | https://your-domain.com/orders.php |
| 设置 | https://your-domain.com/settings.php |
| 管理后台 | https://your-domain.com/admin/ |

---

## 📖 阅读指南

### 我是商家，想接收加密货币支付

1. 阅读 [快速开始](./QUICKSTART.md)
2. 注册账户并获取 API Key
3. 调用 API 创建订单
4. 配置收款钱包地址

### 我是开发者，需要集成支付

1. 阅读 [开发者指南](./DEVELOPER.md)
2. 查看 [API 参考](./API.md)
3. 使用 SDK 示例代码
4. 小额真实订单端到端验证（系统没有测试沙箱）

### 我是运维，需要部署系统

1. 阅读 [部署指南](./DEPLOYMENT.md)
2. 准备服务器环境
3. 配置数据库和 Web 服务器
4. 设置定时任务和监控

### 我是管理员，需要管理后台

1. 阅读 [管理员手册](./ADMIN.md)
2. 学习用户管理
3. 配置系统设置
4. 查看财务报表

---

## 🔧 支持的区块链

| 链 | 代币 | 合约地址 | 精度 |
|----|------|----------|------|
| **TRC20** | USDT | `TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t` | 6 |
| | USDC | `TEkxiTehnzSmSe2XqrBj4w32RUN966rdz8` | 6 |
| **BSC** | USDT | `0x55d398326f99059fF775485246999027B3197955` | 18 |
| | USDC | `0x8ac76a51cc950d9822d68b83fe1ad97b32cd580d` | 18 |
| **Ethereum** | USDT | `0xdac17f958d2ee523a2206206994597c13d831ec7` | 6 |
| **Polygon** | USDT | `0xc2132d05d31c914a87c6611c10748aeb04b58e8f` | 6 |
| **Optimism** | USDT | `0x94b008aa00579c1307b0ef2c499ad98a8ce58e58` | 6 |
| **Arbitrum** | USDT | `0xfd086bc7cd5c481dcc9c85ebe478a1c0b69fcbb9` | 6 |
| | USDC | `0xaf88d065e77c8cc2239327c5edb3a432268e5831` | 6 |
| **Base** | USDT | `0xfde4c96c8593536e31f229ea8f37b2ada2699bb2` | 6 |
| **Avalanche** | USDT | `0x9702230a8ea53601f5cd2dc00fdbc13d4df4a8c7` | 6 |

---

## 💡 代码示例

### 创建订单（cURL）

```bash
curl -X POST https://your-domain.com/api/v1/order/create.php \
  -H "X-API-KEY: sk_live_your_api_key" \
  -H "Content-Type: application/json" \
  -d '{
    "amount": 100.00,
    "chain": "trc20",
    "merchant_order_id": "ORDER-001",
    "notify_url": "https://yoursite.com/webhook",
    "domain": "yoursite.com"
  }'
```

### 创建订单（PHP）

```php
<?php
$ch = curl_init('https://your-domain.com/api/v1/order/create.php');
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'X-API-KEY: sk_live_your_api_key',
    'Content-Type: application/json'
]);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'amount' => 100.00,
    'chain' => 'trc20',
    'merchant_order_id' => 'ORDER-001',
    'notify_url' => 'https://yoursite.com/webhook',
    'domain' => 'yoursite.com'
]));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
$result = json_decode($response, true);

if ($result['status'] === 'success') {
    header('Location: ' . $result['data']['payment_url']);
    exit;
}
```

### 查询订单状态

> 注意：`/order/query.php` 不存在。状态查询走 `/order/status.php`，**鉴权用订单的
> `pay_access_token`**（从创建订单返回的 `payment_url` 里取），不用 X-API-KEY。
> 服务端集成请优先使用 Webhook 而非轮询。

```bash
curl -G https://your-domain.com/api/v1/order/status.php \
  -d "order_no=UAPI20260408001" \
  -d "token=ACCESS_TOKEN_FROM_PAYMENT_URL"
# 响应分支：{status:"paid", tx_hash:"..."} / {status:"pending"} / {status:"expired"}
```

### Webhook 回调

```php
<?php
// webhook.php
$rawBody    = file_get_contents('php://input');
$payload    = json_decode($rawBody, true);
$signature  = $_SERVER['HTTP_X_UAPI_SIGNATURE']  ?? '';
$timestamp  = $_SERVER['HTTP_X_UAPI_TIMESTAMP']  ?? '';
$apiKey     = 'sk_live_your_api_key'; // 密钥就是你的 API Key，没有独立 webhook_secret

// 防重放
if (abs(time() - (int)$timestamp) > 300) { http_response_code(401); exit('Stale'); }

// 验签：HMAC-SHA256 over order_no + amount + merchant_order_id + timestamp
$signInput = $payload['order_no'] . $payload['amount']
           . $payload['merchant_order_id'] . $timestamp;
$expected  = hash_hmac('sha256', $signInput, $apiKey);
if (!hash_equals($expected, $signature)) {
    http_response_code(401);
    exit('Invalid signature');
}

// body 是扁平的（没有 event/data 嵌套）：status / order_no / merchant_order_id /
// amount / chain / currency / tx_hash / paid_at
if (($payload['status'] ?? '') === 'paid') {
    $orderId = $payload['merchant_order_id']; // 你的订单号
    $uapiOrderNo = $payload['order_no'];      // UAPI 订单号
    $txHash = $payload['tx_hash'];            // 注意：tx_hash 不是 txid
    // 处理支付成功
}

http_response_code(200);
echo 'success';
```

---

## ❓ 常见问题

### 一般问题

**Q: UAPI 是什么？**

A: UAPI 是一个自托管的加密货币支付网关，允许商家接收 USDT/USDC 等稳定币支付。

**Q: 支持哪些加密货币？**

A: 主要支持 USDT 和 USDC，运行在 TRC20、BSC、Ethereum、Polygon 等多条链上。

**Q: 手续费是多少？**

A: 查看官网最新费率。不同套餐有不同的手续费率。

**Q: 资金安全吗？**

A: 资金直接进入你的个人钱包，平台不托管资金。采用非托管模式。

---

### 集成问题

**Q: 如何测试集成？**

A: 系统没有测试沙箱端点。建议在低手续费链（TRC20 / BSC）上创建一笔小额真实订单（如 1 USDT）并实际支付，端到端验证创建订单 → 收银台 → Webhook 全流程。

**Q: API Key 在哪里获取？**

A: 登录控制台 → 设置 → API 设置 → 生成 API Key。

**Q: Webhook 收不到怎么办？**

A: 检查：
1. `notify_url` 是否可公网访问
2. 防火墙是否放行
3. 查看 Webhook 日志
4. 使用定时任务作为备用

---

### 支付问题

**Q: 用户支付后订单未更新？**

A: 
1. 等待 1-3 分钟系统确认
2. 检查链上交易是否成功
3. 如超过 10 分钟，提交工单提供 TXID

**Q: 支付金额转错了怎么办？**

A: 
- 少转：创建新订单补足差额
- 多转：记录为余额或联系客服退款

**Q: 订单多久过期？**

A: 以创建订单响应里的 `expire_in`（秒）为准，默认 600 秒（10 分钟）。超时未支付自动作废。

---

### 账户问题

**Q: 如何修改邮箱？**

A: 设置 → 账户信息 → 修改邮箱 → 验证新邮箱。

**Q: 忘记密码怎么办？**

A: 登录页点击「忘记密码」→ 输入邮箱 → 查收重置链接。

**Q: 如何启用双因素认证？**

A: 设置 → 安全设置 → 启用 2FA → 扫描 QR 码 → 输入验证码。

---

## 📞 获取帮助

### 文档资源

- 📚 [快速开始](./QUICKSTART.md)
- 💻 [开发者指南](./DEVELOPER.md)
- 📡 [API 参考](./API.md)
- 🔧 [部署指南](./DEPLOYMENT.md)
- 👨‍💼 [管理员手册](./ADMIN.md)
- 👤 [用户指南](./USER.md)

### 联系方式

| 方式 | 说明 |
|------|------|
| 📧 工单系统 | 登录控制台 → 工单 → 新建工单 |
| 📱 Telegram | 配置 Telegram 通知接收系统消息 |
| 📧 邮件 | 支持工单回复通知 |

### 响应时间

- 普通问题：24 小时内
- 支付问题：优先处理
- 紧急问题：立即响应

---

## 📝 更新日志

### 2026-04-08

- 创建完整文档体系
- 新增 Base 链支持
- 优化 Webhook 签名验证
- 改进订单查询性能

### 2026-03-07

- 新增 Arbitrum USDC 支持
- 优化 Tailwind CSS 构建
- 改进数据库迁移机制

---

## 🔗 其他资源

### 项目文件

| 文件 | 说明 |
|------|------|
| [README.md](../README.md) | 项目概述（英文） |
| [README.zh-CN.md](../README.zh-CN.md) | 项目概述（中文） |
| [LICENSE](../LICENSE) | 开源许可证（AGPLv3 + 商业授权附加条款） |
| [.env.example](../.env.example) | 环境变量模板 |

### 外部链接

- [GitHub 仓库](https://github.com/jasonpan168/uapi)
- [Tailwind CSS](https://tailwindcss.com)
- [PHP 文档](https://www.php.net)
- [MySQL 文档](https://dev.mysql.com/doc/)

---

*最后更新：2026 年 4 月 8 日*
