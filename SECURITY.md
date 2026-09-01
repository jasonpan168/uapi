# Security Policy

**English below.**

UAPI 涉及支付、钱包地址、Webhook 和第三方支付凭据。请不要在公开 Issue、讨论区、截图或日志中披露漏洞细节、真实密钥、助记词、私钥、用户数据或可利用的生产环境信息。

## 报告安全问题

**请通过 GitHub 私下报告，不要发邮件。**

👉 **[点此私下提交漏洞报告](https://github.com/jasonpan168/uapi/security/advisories/new)**
（也可从仓库页面进入 **Security → Advisories → Report a vulnerability**）

这个入口是私密的：只有维护者能看到，在我们发布修复之前不会公开。这是本项目唯一的漏洞报告渠道——它可以直接讨论、附代码、留存记录，比邮件更好用，也不会漏掉。

报告建议包括：

- 受影响的版本或提交号；
- 受影响的接口、页面或组件；
- 可复现的最小步骤；
- 潜在影响和利用前提；
- 已采取的临时缓解措施（如有）。

请使用测试账户和虚构数据复现，不要访问、修改或下载不属于你的数据，也不要进行拒绝服务、资金转移或社会工程测试。

## 响应方式

维护者会尽力确认收到报告、评估影响并协调修复与披露时间。响应时间不作保证。修复公开前，请给维护者合理的处理时间。

本项目是个人开源爱好项目，没有安全团队，也没有漏洞赏金。我们能提供的是认真的对待、快速的修复，以及公开致谢。

## 密钥泄露

如果发现密钥已经进入提交历史，仅从最新版本删除是不够的：应立即在对应服务商处撤销并轮换密钥，再评估是否需要清理 Git 历史。任何真实助记词或私钥一旦暴露，都应视为永久失效并转移相关资产。

## 支持范围

一般优先处理默认分支当前版本中的可复现问题。过期分支、未经维护者发布的第三方修改版以及已停止支持的运行环境，可能不会获得修复。

---

# Security Policy (English)

UAPI handles payments, wallet addresses, webhooks and third-party payment credentials.
Please do not post vulnerability details, real keys, seed phrases, private keys, user
data or exploitable production information in public issues, discussions, screenshots
or logs.

## Reporting a vulnerability

**Please report through GitHub. Do not email us.**

👉 **[Report a vulnerability privately](https://github.com/jasonpan168/uapi/security/advisories/new)**
(or from the repository page: **Security → Advisories → Report a vulnerability**)

That form is private — only the maintainer can see it, and nothing is published until a
fix ships. It is the only reporting channel for this project. It keeps the discussion,
the code and the history in one place, which works better than email and means nothing
gets lost in a spam folder.

A useful report usually includes:

- the affected version or commit hash;
- the affected endpoint, page or component;
- minimal steps to reproduce;
- the potential impact and any preconditions for exploitation;
- any temporary mitigation you have already applied.

Please reproduce using test accounts and fictitious data. Do not access, modify or
download data that is not yours, and do not attempt denial of service, fund transfers
or social engineering.

## How we respond

We will do our best to acknowledge the report, assess the impact, and coordinate the fix
and disclosure timing with you. We cannot guarantee a response time. Please give us a
reasonable window before publishing.

This is a personal open-source hobby project. There is no security team and no bug
bounty. What we can offer is that we take reports seriously, fix them quickly, and
credit you publicly.

## Leaked credentials

If a secret has already reached the commit history, removing it from the latest version
is not enough: revoke and rotate it at the provider immediately, then decide whether the
Git history needs rewriting. Any real seed phrase or private key that has been exposed
should be treated as permanently compromised, and the assets moved.

## Scope

We generally prioritise reproducible issues in the current version of the default
branch. Stale branches, third-party modified builds not released by the maintainer, and
end-of-life runtime environments may not receive fixes.

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

如果你私下报告了一个我们采纳的问题，欢迎告诉我们希望以何种方式署名（姓名、GitHub 账号、主页链接，或匿名）。默认我们只写你的 GitHub 用户名。
If you privately report an issue we act on, tell us how you would like to be credited —
name, GitHub handle, a link, or anonymously. By default we credit your GitHub username
and nothing else.
