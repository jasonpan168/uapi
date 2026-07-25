# UAPI 快速开始

5 分钟内完成支付集成并接收第一笔加密货币支付。

## 步骤概览

```
1. 注册账户 (1 分钟)
2. 获取 API Key (30 秒)
3. 创建订单 API 调用 (2 分钟)
4. 测试支付 (1 分钟)
5. 接收款项 (即时)
```

---

## 1. 注册账户

访问 [https://your-domain.com/register.php](https://your-domain.com/register.php) 注册商家账户。

**需要信息：**
- 邮箱地址
- 密码（至少 8 位）
- 验证码

注册后登录控制台。

---

## 2. 获取 API Key

### 2.1 进入 API 设置

登录后，点击右上角头像 → 「设置」→ 「API 设置」

### 2.2 生成密钥

1. 点击「生成 API Key」
2. 系统显示生成的密钥（`sk_live_xxxxx` 格式）
3. **立即复制并保存**（密钥仅显示一次）

### 2.3 绑定域名

在「域名绑定」中添加你的业务域名：

```
yoursite.com
www.yoursite.com
```

> **提示：** 如果是服务器到服务器调用（无浏览器），在 API 请求中传递 `domain` 参数即可。

---

## 3. 创建订单

### 3.1 选择集成方式

根据你的技术栈选择：

| 方式 | 适用场景 |
|------|----------|
| cURL | 任何支持 HTTP 的语言 |
| PHP | PHP 项目 |
| Node.js | Node.js/JavaScript 项目 |
| Python | Python 项目 |

### 3.2 调用创建订单 API

#### 方式 A：使用 cURL（最简单）

```bash
curl -X POST https://your-domain.com/api/v1/order/create.php \
  -H "X-API-KEY: sk_live_your_api_key" \
  -H "Content-Type: application/json" \
  -d '{
    "amount": 100.00,
    "chain": "trc20",
    "merchant_order_id": "ORDER-001",
    "notify_url": "https://yoursite.com/webhook.php",
    "domain": "yoursite.com"
  }'
```

#### 方式 B：使用 PHP

```php
<?php
$apiKey = 'sk_live_your_api_key';
$data = [
    'amount' => 100.00,
    'chain' => 'trc20',
    'merchant_order_id' => 'ORDER-001',
    'notify_url' => 'https://yoursite.com/webhook.php',
    'domain' => 'yoursite.com'
];

$ch = curl_init('https://your-domain.com/api/v1/order/create.php');
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'X-API-KEY: ' . $apiKey,
    'Content-Type: application/json'
]);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
$result = json_decode($response, true);

if ($result['status'] === 'success') {
    // 跳转到支付页面
    header('Location: ' . $result['data']['payment_url']);
    exit;
} else {
    echo 'Error: ' . $result['error'];
}
```

#### 方式 C：使用 Node.js

```javascript
const axios = require('axios');

const createOrder = async () => {
  try {
    const response = await axios.post(
      'https://your-domain.com/api/v1/order/create.php',
      {
        amount: 100.00,
        chain: 'trc20',
        merchant_order_id: 'ORDER-001',
        notify_url: 'https://yoursite.com/webhook.php',
        domain: 'yoursite.com'
      },
      {
        headers: {
          'X-API-KEY': 'sk_live_your_api_key',
          'Content-Type': 'application/json'
        }
      }
    );
    
    // 重定向到支付页面
    res.redirect(response.data.data.payment_url);
  } catch (error) {
    console.error('Error:', error.response.data);
  }
};
```

### 3.3 请求参数说明

| 参数 | 必填 | 说明 | 示例 |
|------|------|------|------|
| amount | ✅ | 订单金额（USDT） | `100.00` |
| chain | ✅ | 支付链 | `trc20` / `bsc` / `eth` |
| merchant_order_id | ✅ | 你的订单号（唯一） | `ORDER-001` |
| notify_url | ❌ | 支付成功回调 URL | `https://yoursite.com/webhook.php` |
| domain | ❌ | 域名（服务器调用时必需） | `yoursite.com` |

### 3.4 支持的支付链

| 链 | 代币 | 最低金额 |
|----|------|----------|
| TRC20 | USDT, USDC | 1 USDT |
| BSC | USDT, USDC | 0.1 USDT |
| Ethereum | USDT | 10 USDT |
| Polygon | USDT | 0.1 USDT |
| Optimism | USDT | 0.1 USDT |
| Arbitrum | USDT, USDC | 0.1 USDT |
| Base | USDT | 0.1 USDT |
| Avalanche | USDT | 1 USDT |

---

## 4. 测试支付

### 4.1 访问支付页面

API 调用成功后，会返回 `payment_url`，例如：

```
https://your-domain.com/pay.php?order=UAPI20260408001
```

### 4.2 完成支付

1. 打开支付页面
2. 查看支付地址和金额
3. 使用钱包（如 TronLink、MetaMask）扫码或复制地址
4. 转账指定金额

### 4.3 等待确认

- 系统自动检测链上交易
- 通常 1-3 分钟内确认
- 确认后自动跳转成功页面

---

## 5. 接收款项

### 5.1 配置收款钱包

在控制台配置你的收款钱包地址：

1. 进入「设置」→ 「钱包设置」
2. 选择对应链（如 TRC20）
3. 输入你的钱包地址
4. 保存

### 5.2 款项到账

支付确认后：
- 资金直接进入你的钱包
- 控制台订单状态更新为「已支付」
- 收到 Webhook 回调通知（如配置）

### 5.3 查看订单

在控制台「订单管理」查看所有订单：

- 待支付（pending）
- 已支付（paid）
- 已取消（cancelled）
- 已过期（expired）

---

## 下一步

完成首次集成后，你可以：

### 完善集成

- [ ] 实现 Webhook 回调处理
- [ ] 添加订单查询功能
- [ ] 实现订单取消逻辑
- [ ] 集成到购物车流程

### 高级功能

- [ ] 创建支付链接（无需 API）
- [ ] 生成收款码
- [ ] 开设在线商店
- [ ] 配置 Binance Pay
- [ ] 配置 Stripe 法币支付

### 自定义设置

- [ ] 配置通知方式（邮件/Telegram）
- [ ] 启用双因素认证
- [ ] 自定义品牌 Logo
- [ ] 设置手续费承担方

---

## 常见问题

### Q: 有测试沙箱吗？

A: 没有。系统不提供测试下单接口，所有订单都是主网真实订单。建议用低手续费链（TRC20 / BSC）创建小额订单（如 1 USDT）实际支付一次，验证完整流程。

### Q: 如何知道用户已支付？

A: 有三种方式：
1. 用户支付后跳转回你的网站
2. Webhook 回调通知（推荐）
3. 主动查询订单状态 API

### Q: 用户转错账怎么办？

A: 联系平台客服，提供交易哈希（TXID），协助处理。

### Q: 可以自定义支付页面吗？

A: 可以。使用 API 创建订单后，自行设计支付页面展示支付地址和二维码。

---

## 获取帮助

- 📚 [完整 API 文档](./API.md)
- 💻 [开发者指南](./DEVELOPER.md)
- 📧 工单支持（控制台 → 工单）
- 📱 Telegram 通知配置

---

*预计完成时间：5 分钟 | 实际开始收款*
