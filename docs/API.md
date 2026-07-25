# UAPI API 文档

完整的 API 参考文档，包含所有端点、请求/响应格式和错误码。

> ⚠️ **实现状态**
>
> - 当前面向第三方商户、用 `X-API-KEY` 鉴权的对外 API 主体是 `POST /api/v1/order/create.php` 和 `POST /api/v1/order/dispute.php`
> - 订单状态查询 `GET /api/v1/order/status.php` 用**订单级 `pay_access_token`** 鉴权，不用 X-API-KEY
> - 其余以 `/api/v1/...` 开头的端点大多是站内 UI 的 AJAX 入口，使用 `$_SESSION['user_id']` 鉴权
> - 早期文档曾列出的若干端点（query / cancel / list / store/* / chain/list / binance/create / stripe/create 等）**当前后端并未实现**，整理在 [附录 A](#附录-a规划中的端点未实现)
> - 真实存在但本文档主体未细写的内部端点，整理在 [附录 B](#附录已实现但未文档化的端点)

## 目录

- [概述](#概述)
- [认证](#认证)
- [通用响应格式](#通用响应格式)
- [订单 API](#订单-api)
- [Webhook 回调](#webhook-回调)
- [错误码](#错误码)
- [附录 A：规划中的端点（未实现）](#附录-a规划中的端点未实现)
- [附录 B：已实现但未文档化的端点](#附录已实现但未文档化的端点)

---

## 概述

### 基础 URL

```
https://your-domain.com/api/v1
```

> UAPI 是自部署系统，`your-domain.com` 请替换为你自己部署的域名。没有测试沙箱环境，所有订单均为主网真实订单。

### 请求格式

所有 API 请求使用 JSON 格式：

```http
Content-Type: application/json
```

---

## 认证

### API Key 认证

所有 API 端点需要在请求头中携带 API Key：

```http
X-API-KEY: sk_live_your_api_key
```

### 获取 API Key

1. 登录商家控制台
2. 进入「设置」页面
3. 点击「生成 API Key」
4. 保存生成的密钥（仅显示一次）

### 域名绑定

API Key 默认绑定到请求来源域名。如需服务器到服务器调用，在请求体中传递 `domain` 参数：

```json
{
  "domain": "yoursite.com"
}
```

---

## 通用响应格式

### 成功响应

```json
{
  "status": "success",
  "data": {
    // 具体业务数据
  }
}
```

### 错误响应

外部 API 端点（X-API-KEY 入口）目前会以两种形式之一返回错误，调用方需要兼容两者：

**A. 短格式**（绝大多数鉴权 / 限流错误用这种）：

```json
{ "error": "Invalid API Key" }
```

**B. 结构化格式**（一些业务错误用这种）：

```json
{
  "status": "error",
  "error": "Plan expired. Please upgrade/renew.",
  "code": "PLAN_EXPIRED"
}
```

> 💡 解析建议：先判断 HTTP 状态码 ≥ 400 即视为错误；再读 `error` 字段拿到可读消息；`code` 字段只在结构化形式里有。

### HTTP 状态码

| 状态码 | 含义 | 常见触发 |
|---|---|---|
| 200 | 成功 | 订单创建 / 查询返回 |
| 400 | 请求参数错误 | 缺必填字段 / 链不支持 / `USDC` 选了 `trc20` |
| 401 | 未鉴权 | 缺 `X-API-KEY` 头 |
| 403 | 权限不足 | API Key 无效 / 账号停用 / Plan 过期 / IP 黑名单 / 不在 IP 白名单 / 链未在当前 Plan 开放 / Origin 与已绑定域名不符 |
| 429 | 请求过于频繁 | 日额度耗尽 / 同 IP 短时间突发 / 单订单查询超频 |
| 500 | 服务端错误 | 钱包未配置 / RPC 失败 / DB 异常 |

### 常见 error 字符串与解决方法

| error | 修复方法 |
|---|---|
| `Missing API Key` | 在请求头加 `X-API-KEY: sk_live_xxx` |
| `Invalid API Key` | Key 错或已被重置。后台「API 设置」→ 复制最新 Key |
| `IP Blocked: ...` | 多次失败尝试后被自动屏蔽，联系支持解锁 |
| `Account suspended` | 账号被停用，联系支持 |
| `Plan expired. Please upgrade/renew.` | 后台续费或升级当前 Plan |
| `IP not in whitelist. ...` | 后台 API 安全设置加入调用机器 IP；或关闭 IP 白名单功能 |
| `Access Denied: Chain '...' is not enabled for your current plan` | 该链需要更高级 Plan；切链或升级 |
| `Access Denied: Request must include Origin/Referer header or "domain" parameter.` | 服务端调用请在 body 加 `"domain": "yoursite.com"` |
| `Access Denied: Domain '...' is not bound to your account.` | 后台「网站绑定」加入该 domain |
| `Currency '...' is not supported on chain '...'.` | `USDC` 不支持 `trc20`；换链或换币种 |
| `Too many requests, please try again later` | 指数退避后重试；或升级 Plan |

---

## 订单 API

### 创建订单

**端点：** `POST /api/v1/order/create.php`

**请求参数：**

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| amount | decimal | ✅ | 订单金额 |
| chain | string | ✅ | 支付链（trc20/bsc/eth/polygon/optimism/arbitrum/base/avalanche） |
| merchant_order_id | string | ✅ | 商家订单号（唯一） |
| currency | string | ❌ | `USDT`（默认）或 `USDC`，**注意**：旧文档曾误写为 `token`，实际后端只接受 `currency` |
| notify_url | string | ❌ | 支付成功后服务端回调 URL（Webhook） |
| domain | string | ❌ | 服务器对服务器调用时必填，且必须是已绑定的网站域名 |

**请求示例：**

```json
{
  "amount": 100.00,
  "chain": "trc20",
  "currency": "USDT",
  "merchant_order_id": "ORD-20260408-001",
  "notify_url": "https://yoursite.com/webhook/uapi",
  "domain": "yoursite.com"
}
```

**响应示例（成功）：**

```json
{
  "status": "success",
  "data": {
    "order_no": "UAPI20260408001",
    "amount": 100.00,
    "currency": "USDT",
    "chain": "trc20",
    "expire_in": 600,
    "payment_url": "https://your-domain.com/pay.php?order=UAPI20260408001&token=ACCESS_TOKEN",
    "fast_sync_enabled": true
  }
}
```

**响应字段：**

| 字段 | 类型 | 说明 |
|------|------|------|
| order_no | string | UAPI 内部订单号，所有后续接口（查询、回调）都用这个 |
| amount | decimal | 回显订单金额 |
| currency | string | 回显订单币种（USDT / USDC） |
| chain | string | 回显选择的链 |
| expire_in | int | **订单有效期（秒）**，从此刻起计时；不是绝对时间戳。客户超时未支付订单自动作废 |
| payment_url | string | 客户支付页面 URL，可重定向客户到此 URL 完成支付 |
| fast_sync_enabled | bool | 当前订单是否启用了快速同步模式（影响链上确认速度） |

> ⚠️ **历史变更**：旧文档曾使用 `order_id` / `token` / `payment_address` / `qr_code` / `expire_at`（时间戳）等字段名，**这些都不是真实返回的字段名**。请严格按上表字段对接。

---

## 附录 A：规划中的端点（未实现）

以下端点曾在早期文档中列出，但**当前后端并未实现**，直接调用会返回 404 或被 nginx
拦截。整理在这里只为说明「为什么文档/SDK 老版本里有这些路径」。请勿在生产代码里硬编码它们。

| 端点 | 替代方案 |
|---|---|
| `GET /api/v1/order/query.php` | 用 `GET /api/v1/order/status.php`（订单 token 鉴权）或监听 Webhook |
| `POST /api/v1/order/cancel.php` | 暂无 API；商户后台手动取消，或等 TTL 过期自动作废 |
| `GET /api/v1/order/list.php` | 用 `GET /api/v1/user/export_orders.php`（站内 session，导出 CSV） |
| `GET /api/v1/user/info.php` | 暂无；账号信息只能从商户后台界面查看 |
| `GET /api/v1/user/balance.php` | 暂无 |
| `POST /api/v1/user/update_balance.php` | 暂无（管理员操作走后台） |
| `GET /api/v1/store/list.php` | 暂无 |
| `POST /api/v1/store/create.php` | 商店在后台创建，不开放 API |
| `GET /api/v1/store/products.php` | 暂无 |
| `POST /api/v1/store/create_product.php` | 商品在后台创建 |
| `GET /api/v1/chain/list.php` | 链清单见后台「订阅 / Plan」，或文档 [支持的链](#支持的链) |
| `GET /api/v1/chain/detail.php` | 暂无 |
| `POST /api/v1/binance/create.php` | **Binance Pay 目前不对外**，只对站内升级套餐开放（见 [/api/v1/user/upgrade.php](#附录已实现但未文档化的端点)） |
| `POST /api/v1/stripe/create.php` | Stripe 集成只接 webhook，不开放创建接口 |

---

## 错误码

### 通用错误

| 错误码 | HTTP 状态 | 说明 |
|--------|----------|------|
| INVALID_API_KEY | 401 | API Key 无效或不存在 |
| API_KEY_DISABLED | 401 | API Key 已被禁用 |
| DOMAIN_NOT_BOUND | 403 | 请求域名未绑定 |
| RATE_LIMIT_EXCEEDED | 429 | 请求频率超限 |
| INTERNAL_ERROR | 500 | 服务器内部错误 |

### 订单错误

| 错误码 | HTTP 状态 | 说明 |
|--------|----------|------|
| INVALID_AMOUNT | 400 | 金额无效（必须大于 0） |
| INVALID_CHAIN | 400 | 不支持的区块链（或当前 Plan 未开放该链） |
| MERCHANT_ORDER_ID_EXISTS | 400 | 商家订单号已存在（被幂等命中时返回的是该已存在订单） |
| ORDER_NOT_FOUND | 404 | 订单不存在 |
| ORDER_EXPIRED | 400 | 订单已过期 |

> 早先文档列过 `INVALID_TOKEN` / `USER_NOT_FOUND` / `INSUFFICIENT_BALANCE` / `STORE_*` / `PRODUCT_*` 等错误码 —
> 这些对应的接口（user/* 和 store/*）**当前并未对外开放**，相关错误码实际不会从对外 API 返回。

---

## Webhook 回调

### 支付成功回调

当订单支付成功时，UAPI 会向你创建订单时传入的 `notify_url` 发送一个 POST 请求。
回调使用 HMAC-SHA256 签名校验，**密钥就是你账户的 API Key**（**没有独立的 webhook secret**）。

#### 请求头

| Header | 说明 |
|---|---|
| `Content-Type` | 始终为 `application/json` |
| `X-UAPI-Signature` | HMAC-SHA256 签名（hex 字符串，**不带 `sha256=` 前缀**） |
| `X-UAPI-Timestamp` | 服务端签名时的 Unix 时间戳（秒），是签名输入的一部分 |
| `X-UAPI-Event` | 当前固定为 `order.paid`，预留给未来事件类型 |
| `X-UAPI-Event-ID` | 本次事件的唯一 ID（16 hex 字符），可用于幂等去重 |

#### 请求体（扁平结构，不是嵌套）

```json
{
  "status": "paid",
  "order_no": "UAPI20260408001",
  "merchant_order_id": "ORD-001",
  "amount": "100.00",
  "chain": "trc20",
  "currency": "USDT",
  "tx_hash": "0xabcdef1234567890...",
  "paid_at": "2026-04-08T10:05:00+00:00"
}
```

#### 字段说明

| 字段 | 说明 |
|---|---|
| `status` | 当前只会是 `paid` |
| `order_no` | UAPI 订单号（注意是 `order_no`，**不是 `order_id`**） |
| `merchant_order_id` | 创建订单时你传入的商家订单号 |
| `amount` | 订单金额（字符串形式） |
| `chain` | 实际收款链（trc20 / bsc / eth / ...） |
| `currency` | 结算币种 USDT / USDC（**不是 `token`**） |
| `tx_hash` | 链上交易哈希（**不是 `txid`**） |
| `paid_at` | ISO 8601 时间戳（带时区） |

#### 验证签名

签名输入是 4 个字段拼接的字符串：
`order_no + amount + merchant_order_id + timestamp`，密钥用你的 **API Key**。

```php
<?php
$rawBody    = file_get_contents('php://input');
$payload    = json_decode($rawBody, true);
$signature  = $_SERVER['HTTP_X_UAPI_SIGNATURE']  ?? '';
$timestamp  = $_SERVER['HTTP_X_UAPI_TIMESTAMP']  ?? '';
$apiKey     = 'sk_live_your_api_key'; // 你的 API Key（即 UAPI 后台显示的那个）

// 可选：拒绝 5 分钟外的旧签名以防重放
if (abs(time() - (int)$timestamp) > 300) {
    http_response_code(401); exit('Stale timestamp');
}

$signInput = $payload['order_no']
           . $payload['amount']
           . $payload['merchant_order_id']
           . $timestamp;
$expected = hash_hmac('sha256', $signInput, $apiKey);

if (!hash_equals($expected, $signature)) {
    http_response_code(401); exit('Invalid signature');
}

// 校验通过 → 处理订单
// 建议用 X-UAPI-Event-ID 做幂等：见过的 event_id 直接 200 返回，不要重复处理
```

#### 重试

UAPI 会重试最多 3 次，超时 10 秒。你的端点需要在收到回调后**返回 HTTP 2xx** 才算成功送达。
重试期间签名头里的 `X-UAPI-Event-ID` 保持不变，建议据此做幂等。

---

## 附录：已实现但未文档化的端点

以下端点真实存在但尚未补全规范。绝大多数是**站内 UI 的 AJAX 入口**，
使用 `$_SESSION['user_id']` 鉴权（不接受 X-API-KEY），不建议第三方调用。

| 端点 | 鉴权 | 用途 |
|---|---|---|
| `POST /api/v1/order/dispute.php` | **X-API-KEY** | 商户提交订单争议（外部 API） |
| `GET /api/v1/order/status.php` | session | 站内查询订单（取代文档中的 `query.php`） |
| `GET /api/v1/order/check_hash.php` | session | 链上交易哈希校验 |
| `POST /api/v1/order/heartbeat.php` | session | 收款页心跳上报 |
| `POST /api/v1/store/create_order.php` | store_id + customer_email | 商店下单（公开页面用） |
| `POST /api/v1/store/verify_coupon.php` | 同上 | 商店端优惠码校验 |
| `POST /api/v1/user/upgrade.php` | session | 站内套餐升级 AJAX |
| `GET /api/v1/user/export_orders.php` | session | 导出订单 CSV |
| `POST /api/v1/user/verify_coupon.php` | session | 用户面板优惠码校验 |
| `POST /api/v1/user/mark_read.php` | session | 标记站内消息已读 |
| `POST /api/v1/user/mark_announcement_read.php` | session | 标记公告已读 |
| `GET /api/v1/chain/balance.php` | session | 查询钱包余额（面板用） |
| `POST /api/v1/binance/webhook.php` | Binance 签名 | 币安回调入口 |
| `POST /api/v1/stripe/webhook.php` | Stripe 签名 | Stripe 回调入口 |
| `* /api/v1/admin/binance.php` | admin session | 管理员币安操作面板 |

---

*最后更新：2026 年 4 月 8 日*
