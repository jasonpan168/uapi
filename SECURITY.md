# Security Policy

UAPI 涉及支付、钱包地址、Webhook 和第三方支付凭据。请不要在公开 Issue、讨论区、截图或日志中披露漏洞细节、真实密钥、助记词、私钥、用户数据或可利用的生产环境信息。

## 报告安全问题

优先使用 GitHub 仓库的 **Security → Report a vulnerability** 私下报告。如果该入口不可用，请发邮件至 admin@uapi.io，主题注明 `UAPI Security`。

报告建议包括：

- 受影响的版本或提交号；
- 受影响的接口、页面或组件；
- 可复现的最小步骤；
- 潜在影响和利用前提；
- 已采取的临时缓解措施（如有）。

请使用测试账户和虚构数据复现，不要访问、修改或下载不属于你的数据，也不要进行拒绝服务、资金转移或社会工程测试。

## 响应方式

维护者会尽力确认收到报告、评估影响并协调修复与披露时间。响应时间不作保证。修复公开前，请给维护者合理的处理时间。

## 密钥泄露

如果发现密钥已经进入提交历史，仅从最新版本删除是不够的：应立即在对应服务商处撤销并轮换密钥，再评估是否需要清理 Git 历史。任何真实助记词或私钥一旦暴露，都应视为永久失效并转移相关资产。

## 支持范围

一般优先处理默认分支当前版本中的可复现问题。过期分支、未经维护者发布的第三方修改版以及已停止支持的运行环境，可能不会获得修复。

## 致谢 / Acknowledgements

感谢以下安全研究者按本策略私下报告问题，并在修复发布前主动不公开披露。
We thank the security researchers below for reporting privately and for withholding
public disclosure until fixes shipped.

| 日期 / Date | 研究者 / Researcher | 报告内容 / Findings |
| --- | --- | --- |
| 2026-08 | _(pending credit preference)_ | Stripe webhook 在密钥未配置时跳过签名验证；`notify_url` 存储型 SSRF；优惠券折扣可将应付金额归零并绕过支付 — Stripe webhook signature verification skipped when the secret is unset; stored SSRF via `notify_url`; coupon discount able to zero out the payable amount and bypass payment |

报告者附带提交了可复现的校验脚本与逐条「已证实 / 未证实」区分，且未对任何线上系统发起测试。
The report included a reproducible verifier and an explicit proven / not-proven split,
and no live system was tested.

如果你私下报告了一个我们采纳的问题，欢迎告诉我们希望以何种方式署名（姓名、代号、主页链接，或匿名）。我们不会公开你的邮箱地址。
If you privately report an issue we act on, tell us how you would like to be credited —
name, handle, a link, or anonymously. We will never publish your email address.
