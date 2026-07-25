# UAPI 开发者指南

详细的开发教程，帮助你快速集成 UAPI 支付网关。

## 目录

- [快速开始](#快速开始)
- [集成教程](#集成教程)
- [代码示例](#代码示例)
- [最佳实践](#最佳实践)
- [故障排查](#故障排查)

---

## 快速开始

### 1. 注册账户

访问你部署的 UAPI 实例（`https://your-domain.com/register.php`）注册商家账户。

### 2. 获取 API Key

1. 登录控制台
2. 进入「设置」→「API 设置」
3. 点击「生成 API Key」
4. **重要：** 立即保存 API Key（仅显示一次）

### 3. 配置域名

在 API 设置中绑定你的业务域名，例如：
```
yoursite.com
www.yoursite.com
```

### 4. 测试集成

系统没有测试沙箱端点。建议用低手续费链（TRC20 / BSC）创建一笔小额真实订单（如 1 USDT）并实际支付，端到端验证集成。

---

## 集成教程

### PHP 集成

#### 步骤 1：安装依赖

```bash
composer require guzzlehttp/guzzle
```

#### 步骤 2：创建支付类

```php
<?php
// UapiPayment.php

require 'vendor/autoload.php';

use GuzzleHttp\Client;

class UapiPayment {
    private $client;
    private $apiKey;
    private $baseUrl = 'https://your-domain.com/api/v1';
    
    public function __construct($apiKey) {
        $this->apiKey = $apiKey;
        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'headers' => [
                'X-API-KEY' => $apiKey,
                'Content-Type' => 'application/json'
            ]
        ]);
    }
    
    /**
     * 创建订单
     */
    public function createOrder($amount, $chain, $merchantOrderId, $notifyUrl) {
        try {
            $response = $this->client->post('/order/create.php', [
                'json' => [
                    'amount' => $amount,
                    'chain' => $chain,
                    'merchant_order_id' => $merchantOrderId,
                    'notify_url' => $notifyUrl,
                    'domain' => $_SERVER['HTTP_HOST']
                ]
            ]);
            
            $result = json_decode($response->getBody(), true);
            
            if ($result['status'] === 'success') {
                return $result['data'];
            } else {
                throw new Exception($result['error'] ?? '创建订单失败');
            }
        } catch (Exception $e) {
            error_log("UAPI Error: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * 查询订单状态（注意：使用订单创建时返回的 pay_access_token，不是 API Key）
     * 该端点设计给收银台轮询用，服务端集成应优先用 Webhook。
     */
    public function queryOrderStatus($orderNo, $payAccessToken) {
        $response = $this->client->get('/order/status.php', [
            'query' => [
                'order_no' => $orderNo,
                'token'    => $payAccessToken,
            ],
        ]);

        $result = json_decode($response->getBody(), true);
        // 状态分支：paid / pending / expired / error
        return $result;
    }
}
```

> ⚠️ **注意**：早先版本的 SDK 草稿示例了 `/order/query.php` 和 `/order/cancel.php` —
> 这两个端点**在当前后端不存在**。订单状态请通过 `/order/status.php`（参数为
> `order_no` + `token`）或 Webhook 获取。取消订单目前只能由商户后台手动操作或
> 等订单 TTL 过期自动作废。

#### 步骤 3：使用示例

```php
<?php
// checkout.php

require 'UapiPayment.php';

$uapi = new UapiPayment('sk_live_your_api_key');

// 创建订单
$orderData = $uapi->createOrder(
    amount: 100.00,
    chain: 'trc20',
    merchantOrderId: 'ORD-' . time(),
    notifyUrl: 'https://yoursite.com/webhook.php'
);

// 跳转到支付页面
header('Location: ' . $orderData['payment_url']);
exit;
```

---

### Node.js 集成

#### 步骤 1：安装依赖

```bash
npm install axios
```

#### 步骤 2：创建支付模块

```javascript
// uapi-payment.js

const axios = require('axios');

class UapiPayment {
    constructor(apiKey) {
        this.apiKey = apiKey;
        this.baseUrl = 'https://your-domain.com/api/v1';
        
        this.client = axios.create({
            baseURL: this.baseUrl,
            headers: {
                'X-API-KEY': apiKey,
                'Content-Type': 'application/json'
            }
        });
    }
    
    /**
     * 创建订单
     */
    async createOrder(amount, chain, merchantOrderId, notifyUrl) {
        try {
            const response = await this.client.post('/order/create.php', {
                amount,
                chain,
                merchant_order_id: merchantOrderId,
                notify_url: notifyUrl,
                domain: process.env.APP_DOMAIN
            });
            
            if (response.data.status === 'success') {
                return response.data.data;
            } else {
                throw new Error(response.data.error || '创建订单失败');
            }
        } catch (error) {
            console.error('UAPI Error:', error.message);
            throw error;
        }
    }
    
    /**
     * 查询订单状态（注意：用订单的 pay_access_token 鉴权，不是 API Key）
     * 服务端集成应优先用 Webhook 而非轮询此端点。
     */
    async queryOrderStatus(orderNo, payAccessToken) {
        const response = await this.client.get('/order/status.php', {
            params: { order_no: orderNo, token: payAccessToken },
        });
        // 状态分支：paid / pending / expired / error
        return response.data;
    }
}

module.exports = UapiPayment;
```

> ⚠️ 早先版本的 SDK 示例了 `/order/query.php` 和 `/order/cancel.php` —
> 这两个端点**在当前后端不存在**。订单状态请通过 `/order/status.php`
> （参数为 `order_no` + `token`，token 从 `payment_url` 里取）或 Webhook 获取。

#### 步骤 3：使用示例

```javascript
// app.js

const UapiPayment = require('./uapi-payment');

const uapi = new UapiPayment('sk_live_your_api_key');

// 创建订单
app.post('/checkout', async (req, res) => {
    try {
        const orderData = await uapi.createOrder(
            100.00,
            'trc20',
            'ORD-' + Date.now(),
            'https://yoursite.com/webhook'
        );
        
        res.redirect(orderData.payment_url);
    } catch (error) {
        res.status(500).json({ error: error.message });
    }
});
```

---

### Python 集成

#### 步骤 1：安装依赖

```bash
pip install requests
```

#### 步骤 2：创建支付模块

```python
# uapi_payment.py

import requests
import os

class UapiPayment:
    def __init__(self, api_key):
        self.api_key = api_key
        self.base_url = 'https://your-domain.com/api/v1'
        self.headers = {
            'X-API-KEY': api_key,
            'Content-Type': 'application/json'
        }
    
    def create_order(self, amount, chain, merchant_order_id, notify_url):
        """创建订单"""
        try:
            response = requests.post(
                f'{self.base_url}/order/create.php',
                headers=self.headers,
                json={
                    'amount': amount,
                    'chain': chain,
                    'merchant_order_id': merchant_order_id,
                    'notify_url': notify_url,
                    'domain': os.getenv('APP_DOMAIN')
                }
            )
            response.raise_for_status()
            result = response.json()
            
            if result['status'] == 'success':
                return result['data']
            else:
                raise Exception(result.get('error', '创建订单失败'))
        except requests.exceptions.RequestException as e:
            print(f'UAPI Error: {e}')
            raise
    
    def query_order_status(self, order_no, pay_access_token):
        """
        查询订单状态。注意：用订单的 pay_access_token 鉴权（不是 API Key）。
        服务端集成应优先用 Webhook 而非轮询。
        """
        response = requests.get(
            f'{self.base_url}/order/status.php',
            params={'order_no': order_no, 'token': pay_access_token}
        )
        response.raise_for_status()
        # 状态分支：paid / pending / expired / error
        return response.json()
```

> ⚠️ 早先版本的 SDK 示例了 `/order/query.php` 和 `/order/cancel.php` —
> 这两个端点**在当前后端不存在**。订单状态走 `/order/status.php`
> （参数为 `order_no` + `token`，token 从 `payment_url` 里取）或 Webhook。

#### 步骤 3：使用示例

```python
# app.py

from flask import Flask, redirect, request
from uapi_payment import UapiPayment

app = Flask(__name__)
uapi = UapiPayment('sk_live_your_api_key')

@app.route('/checkout', methods=['POST'])
def checkout():
    order_data = uapi.create_order(
        amount=100.00,
        chain='trc20',
        merchant_order_id=f'ORD-{int(time.time())}',
        notify_url='https://yoursite.com/webhook'
    )
    return redirect(order_data['payment_url'])
```

---

## 代码示例

### 完整电商结账流程

```php
<?php
// checkout.php - 完整的结账流程

session_start();
require 'vendor/autoload.php';
require 'UapiPayment.php';

use GuzzleHttp\Client;

// 检查登录
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// 获取购物车
$cart = $_SESSION['cart'] ?? [];
if (empty($cart)) {
    header('Location: shop.php');
    exit;
}

// 计算总金额
$total = 0;
foreach ($cart as $item) {
    $total += $item['price'] * $item['quantity'];
}

// 获取用户选择的支付方式
$chain = $_POST['chain'] ?? 'trc20';

// 创建 UAPI 订单
$uapi = new UapiPayment('sk_live_your_api_key');
$merchantOrderId = 'ORD-' . date('YmdHis') . '-' . $_SESSION['user_id'];

try {
    $orderData = $uapi->createOrder(
        amount: $total,
        chain: $chain,
        merchantOrderId: $merchantOrderId,
        notifyUrl: 'https://yoursite.com/webhook/order-paid.php'
    );
    
    // 保存订单到本地数据库
    $pdo = new PDO('mysql:host=localhost;dbname=yourdb', 'user', 'pass');
    $stmt = $pdo->prepare("
        INSERT INTO orders 
        (user_id, merchant_order_id, uapi_order_id, amount, chain, status, created_at) 
        VALUES (?, ?, ?, ?, ?, 'pending', NOW())
    ");
    $stmt->execute([
        $_SESSION['user_id'],
        $merchantOrderId,
        $orderData['order_id'],
        $total,
        $chain
    ]);
    
    // 跳转到支付页面
    header('Location: ' . $orderData['payment_url']);
    exit;
    
} catch (Exception $e) {
    $_SESSION['error'] = '创建订单失败：' . $e->getMessage();
    header('Location: cart.php');
    exit;
}
```

---

### Webhook 处理

> **关键**：签名密钥就是**你账户的 API Key**（没有独立 webhook_secret）；
> 签名输入是 `order_no + amount + merchant_order_id + timestamp` 字符串拼接，
> **不是 JSON payload**；`X-UAPI-Signature` 头是裸 hex，**无 `sha256=` 前缀**；
> Webhook body 是扁平结构，**没有 `event` / `data` 嵌套**。

```php
<?php
// webhook.php - 处理 UAPI 支付回调

$rawBody    = file_get_contents('php://input');
$payload    = json_decode($rawBody, true);
$signature  = $_SERVER['HTTP_X_UAPI_SIGNATURE']  ?? '';
$timestamp  = $_SERVER['HTTP_X_UAPI_TIMESTAMP']  ?? '';
$eventId    = $_SERVER['HTTP_X_UAPI_EVENT_ID']   ?? '';
$apiKey     = 'sk_live_your_api_key'; // 后台「API 设置」里的 Key

// 1. 防重放：拒绝 5 分钟外的旧签名
if (abs(time() - (int)$timestamp) > 300) {
    http_response_code(401);
    exit('Stale timestamp');
}

// 2. 验签：HMAC-SHA256 over (order_no + amount + merchant_order_id + timestamp)
$signInput = $payload['order_no']
           . $payload['amount']
           . $payload['merchant_order_id']
           . $timestamp;
$expected = hash_hmac('sha256', $signInput, $apiKey);

if (!hash_equals($expected, $signature)) {
    http_response_code(401);
    exit('Invalid signature');
}

// 3. 幂等去重：用 X-UAPI-Event-ID 防重复处理（重试会用同一个 event_id）
//    if (already_processed($eventId)) { http_response_code(200); exit('OK'); }
//    mark_processed($eventId);

// 4. 只处理已支付事件
if (($payload['status'] ?? '') !== 'paid') {
    http_response_code(200);
    exit('Ignored');
}

// 5. 业务处理（注意字段名：order_no / tx_hash / currency）
try {
    $pdo = new PDO('mysql:host=localhost;dbname=yourdb', 'user', 'pass');
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        UPDATE orders
        SET status = 'paid', tx_hash = ?, paid_at = NOW()
        WHERE merchant_order_id = ?
    ");
    $stmt->execute([
        $payload['tx_hash'],
        $payload['merchant_order_id'],
    ]);

    // 后续业务：发货 / 加积分 / 发邮件等
    // ...

    $pdo->commit();

    http_response_code(200);
    echo 'success';
} catch (Exception $e) {
    $pdo->rollBack();
    error_log('Webhook Error: ' . $e->getMessage());
    http_response_code(500);
    echo 'Internal error';
    exit;
}
```

**Webhook body 字段对照**（避免照旧版 SDK 误写）：

| 旧版误写 | 真实字段 |
|---|---|
| `order_id` | `order_no` |
| `token` | `currency` |
| `txid` | `tx_hash` |
| `data.{xxx}` | 直接 `{xxx}`（无嵌套） |
| `event: "order.paid"` | （无此字段；用 `status === "paid"` 判断） |

---

### 定时任务检测支付

```php
<?php
// check-payment.php - 定时任务检测支付状态

require 'UapiPayment.php';

$uapi = new UapiPayment('sk_live_your_api_key');
$pdo = new PDO('mysql:host=localhost;dbname=yourdb', 'user', 'pass');

// 获取所有待支付的订单
$stmt = $pdo->query("
    SELECT * FROM orders 
    WHERE status = 'pending' 
    AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
");
$pendingOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($pendingOrders as $order) {
    try {
        // 查询 UAPI 订单状态（用本地保存的 order_no + pay_access_token）
        // 强烈建议：生产环境应优先用 Webhook，而不是这种轮询脚本
        $uapiOrder = $uapi->queryOrderStatus(
            $order['uapi_order_no'],
            $order['uapi_pay_access_token']
        );

        if ($uapiOrder && ($uapiOrder['status'] ?? '') === 'paid') {
            // 更新本地订单状态（注意字段名：tx_hash 不是 txid）
            $updateStmt = $pdo->prepare("
                UPDATE orders
                SET status = 'paid', tx_hash = ?, paid_at = NOW()
                WHERE id = ?
            ");
            $updateStmt->execute([$uapiOrder['tx_hash'] ?? '', $order['id']]);

            // 触发后续逻辑（发邮件、减库存等）
            // ...

            echo "Order {$order['merchant_order_id']} marked as paid.\n";
        }

    } catch (Exception $e) {
        error_log("Check order {$order['merchant_order_id']} failed: " . $e->getMessage());
    }
}

echo "Payment check completed.\n";
```

---

## 最佳实践

### 1. 订单号生成

使用有意义的订单号格式，便于追踪：

```php
// 推荐格式
$merchantOrderId = 'ORD-' . date('YmdHis') . '-' . $userId;
// 例如：ORD-20260408103045-123
```

### 2. 幂等性处理

确保 webhook 处理是幂等的：

```php
// 检查订单是否已处理
$stmt = $pdo->prepare("SELECT status FROM orders WHERE merchant_order_id = ?");
$stmt->execute([$merchantOrderId]);
$order = $stmt->fetch();

if ($order['status'] === 'paid') {
    // 已经处理过，直接返回成功
    echo json_encode(['status' => 'success']);
    exit;
}
```

### 3. 错误重试

实现指数退避重试机制：

```javascript
async function createOrderWithRetry(orderData, maxRetries = 3) {
    for (let i = 0; i < maxRetries; i++) {
        try {
            return await uapi.createOrder(orderData);
        } catch (error) {
            if (i === maxRetries - 1) throw error;
            
            // 指数退避：1s, 2s, 4s
            const delay = Math.pow(2, i) * 1000;
            await new Promise(resolve => setTimeout(resolve, delay));
        }
    }
}
```

### 4. 日志记录

记录所有 API 调用和 webhook：

```php
function logApiCall($endpoint, $request, $response) {
    $logEntry = [
        'timestamp' => date('Y-m-d H:i:s'),
        'endpoint' => $endpoint,
        'request' => $request,
        'response' => $response
    ];
    file_put_contents(
        'uapi_api.log',
        json_encode($logEntry) . PHP_EOL,
        FILE_APPEND
    );
}
```

### 5. 金额精度

使用整数或高精度小数处理金额：

```php
// 推荐：使用整数（分）
$amountInCents = 10000; // 100.00 USDT
$amount = $amountInCents / 100;

// 或使用 BC Math
$amount = bcadd('100.00', '0.01', 2);
```

---

## 故障排查

### 常见问题

#### 1. API Key 无效

**错误：** `INVALID_API_KEY`

**解决：**
- 检查 API Key 是否正确复制（无空格）
- 确认 API Key 已启用
- 联系管理员确认账户状态

#### 2. 域名未绑定

**错误：** `DOMAIN_NOT_BOUND`

**解决：**
- 在控制台绑定请求域名
- 服务器到服务器调用时传递 `domain` 参数

#### 3. 订单号重复

**错误：** `MERCHANT_ORDER_ID_EXISTS`

**解决：**
- 确保商户订单号全局唯一
- 使用时间戳 + 用户 ID 组合

#### 4. Webhook 未收到

**排查步骤：**
1. 检查 `notify_url` 是否可公网访问
2. 查看服务器防火墙/安全组设置
3. 检查 webhook 日志
4. 使用定时任务作为备用方案

#### 5. 支付金额不匹配

**原因：** 链上转账金额与订单金额不一致

**解决：**
- 引导用户准确转账
- 设置可接受的误差范围（如 ±1%）

---

## 测试验证

系统不提供测试沙箱，`/api/v1/test/` 之类的测试端点**不存在**。验证集成的推荐方式：

1. 在低手续费链（TRC20 / BSC）创建一笔小额订单（如 1 USDT）；
2. 用真实钱包完成支付；
3. 确认 Webhook 到达且验签通过、本地订单状态正确更新。

---

*最后更新：2026 年 4 月 8 日*
