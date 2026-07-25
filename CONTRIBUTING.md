# Contributing to UAPI

感谢你愿意改进 UAPI。提交代码前，请先确认改动范围清晰、没有包含真实账户数据、钱包助记词、私钥、API Key、数据库导出或服务器配置。

## 开始之前

1. 先搜索现有 Issue，避免重复工作。
2. 较大的功能、接口变更或数据库结构变更，请先创建 Issue 说明使用场景和兼容方案。
3. 安全漏洞不要创建公开 Issue，请按 [SECURITY.md](SECURITY.md) 私下报告。
4. 使用本仓库即表示你理解并接受 [LICENSE](LICENSE) 中的授权条款。

## 本地开发

```bash
cp .env.example .env
npm install
npm run build:css
```

Web 根目录应指向 `public/`。数据库、邮件和派生地址服务配置请参阅 [部署指南](docs/DEPLOYMENT.md)。

## 提交要求

- 一个 Pull Request 尽量只解决一个问题。
- 保持现有 PHP 8.0+ 兼容性与代码风格。
- API 行为变化需同步更新 `docs/API.md` 和相关示例。
- 数据库变化需提供可重复执行、兼容已有安装的迁移。
- UI/CSS 变化请执行 `npm run build:css`，并说明验证过的页面。
- 不要提交 `.env`、`config/db.php`、日志、数据库快照、备份或构建依赖目录。

## 提交前自检

```bash
git diff --check
npm run build:css
find src public cron config -name '*.php' -print0 | xargs -0 -n1 php -l
```

当前项目尚未建立完整的自动化测试套件。涉及支付状态、签名、Webhook、权限或金额计算的改动，请在 Pull Request 中写明手工验证步骤和结果。

## Pull Request

请描述：问题、解决方式、风险/兼容性、验证方式，以及是否涉及配置、数据库或文档。提交贡献即表示你有权提交相关代码，并同意该贡献按本仓库许可证分发。
