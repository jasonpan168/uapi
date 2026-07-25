=== UAPI Payment Gateway ===
Contributors: uapi
Tags: uapi, crypto, paywall, usdt, usdc, payment, payment-link
Requires at least: 5.8
Tested up to: 6.7
Stable tag: 2.3.8
License: GPLv2 or later

UAPI 支付插件。

功能：
- 付费文章（整篇锁定）
- 付费内容短代码
- 付费下载
- 付费商品
- 收款链接创建/管理
- 订单中心（筛选、重试回调、手动补单）
- Webhook 回调入账
- 交易哈希可直接跳转区块浏览器查看（BSC / Arbitrum）

== 安装 ==
1. 上传并启用插件。
2. 进入 WP 后台 -> UAPI 支付，配置 API Base 与 API Key。
3. 在 UAPI 商户后台绑定你的站点域名（未绑定会被后端拒绝）。
4. 保存后即可使用短代码与收款链接。

== 短代码 ==
[uapi_pay amount="1.00"]这里是付费内容[/uapi_pay]
[uapi_pay amount="1.00" dual="0" currency="USDT"]这里是单按钮付费内容[/uapi_pay]
[uapi_download amount="2.00" file_url="https://example.com/file.zip" file_name="下载ZIP"]
[uapi_product id="sku-001" title="会员礼包" amount="9.90" desc="一次性购买"]

说明：
- `uapi_pay` 默认双按钮（USDT / USDC 同时显示）。
- 可用 `dual="0"` 关闭双按钮模式，改为单按钮。
- 可用 `currencies="USDT,USDC"` 自定义双按钮币种顺序。

== 收款链接 ==
插件后台“收款链接”标签中创建。
创建后链接形式：
https://your-site.com/uapi-pay/{slug}/

== 回调 ==
插件回调地址：
/wp-json/uapi-payment/v1/webhook
