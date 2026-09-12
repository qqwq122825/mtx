# Telegram 双向客服（PHP，无数据库）

本功能独立于游戏 ID / 包发布，只用于用户主动联系后的客服回信，不包含群发或自动发布安装包。

## 工作方式

用户私聊机器人 → 管理员收到带用户数字 ID 的会话卡片和消息副本 → 管理员在 Telegram 长按卡片或消息选择“回复” → 机器人将回信复制给对应用户。支持文字、图片、文件、语音、视频、视频留言、动画和贴纸；媒体由 Telegram 直接复制，PHP 不下载附件。相册逐条处理，不保留相册分组。

使用 Telegram 的 `copyMessage`，回信副本没有原消息的转发来源链接；Webhook 采用独立的 `secret_token` 请求头校验。接口要求来自 [Telegram Bot API 官方文档](https://core.telegram.org/bots/api#copymessage)及其 [Webhook 说明](https://core.telegram.org/bots/api#setwebhook)。服务消息、支付内容等不在本版支持范围，失败会给出提示或记录。

## 首次接入

1. 在 Telegram 官方 [BotFather](https://t.me/BotFather) 创建一个新机器人，取得 Bot Token；不要发到公开群聊或放入 Git。
2. 获得你自己的个人 Telegram 数字 ID（不是 `@用户名`，也不是群 ID）。若未知，在项目终端运行 `php bin/telegram-id.php`：按隐藏输入提示填 Token，使用本人账号私聊机器人发送脚本生成的 `/id 随机码`，返回终端按回车。脚本只读取匹配的私聊 ID，不删除 Webhook、不确认/清空待处理消息，不保存 Token；仅用于尚未接入的新机器人。
3. 登录现有密码后台，点击「Telegram 客服」，填写 Token 和个人数字 ID 并保存。你需要先打开机器人点 Start，建立私聊。
4. 将本版 PHP 代码部署到 `https://mtx.jk92.cc`，保留原随机入口、私有配置和 storage。PHP 需启用 **curl**，服务器应能通过 HTTPS 访问 `api.telegram.org:443`，保留 TLS 证书校验。
5. 在生产后台点击「启用 Webhook」。服务先检查机器人身份，再给管理员发送测试消息，最后注册本站 Webhook；失败不标记启用。可以点击「检查连接」查看地址匹配与待处理数量。
6. 将页面显示的 `https://t.me/机器人用户名` 给用户；用户先给机器人发消息，你再通过机器人回复。

本地 `127.0.0.1` 可查看/保存设置，但不会注册 Webhook。当前本机尚未填写真实 Token，因此没有连接真实 Telegram 或发出消息。创建机器人的官方流程见 [BotFather 文档](https://core.telegram.org/bots/features#botfather)。Telegram 对账号和机器人的限制仍以实际平台提示为准，本功能不改变账号限制。

## 命令

- 用户和管理员：`/start`、`/help` 查看说明；`/id` 查询自己的数字 ID。
- 管理员回复某条用户消息或卡片：普通文字/附件直接回信；`/who` 查看对象；`/block` 屏蔽该用户。
- 管理员发送 `/unblock 数字ID` 解除屏蔽。
- 无有效回复对象的管理员消息仅返回使用说明，不猜测收件人、不群发。只接受配置的管理员本人私聊操作，不以显示名称或用户名判定权限。

## Webhook / CDN / Nginx

- 地址：`/<随机入口>/api/telegram-webhook.php`，**POST JSON**，最大 256 KiB。
- Telegram 使用 `X-Telegram-Bot-Api-Secret-Token` 头，服务端恒定时间比较；Webhook URL 不包含 Token 或 secret。
- Nginx 使用最新 `deploy/nginx.conf.example`；新增后台 `telegram.php` 和独立的 Webhook location。已有 Nginx 配置也要同步，否则会返回 404。
- CDN 对 Webhook 禁止缓存、浏览器挑战、HTML 注入和重定向；保留 POST 正文及该校验头。后台依旧按原管理规则保护。
- 注册仅订阅 `message`，并将 `max_connections` 设为 1；应用层继续忽略群组、频道、编辑、机器人自身消息和过期消息。
- 不需要 cron、常驻进程、数据库或开放新的服务端口。端到端 HTTPS 建议使用标准 443。

## 存储和可靠性

`storage/telegram/state.json` 保存配置（含 Token/secret）、数字 ID 关联、去重状态、屏蔽和限流信息；权限 0600，目录 0700，在 public 之外。机器人使用单独的锁和原子 JSON，与包发布锁分离。页面不回显 Token 或 secret，异常不打印 Telegram 原始响应、正文或 API URL。**本服务器不保存聊天正文/媒体；双方的 Telegram 聊天记录仍由 Telegram 保存。**

- 回复关联有效 30 天，最多 10,000 条；收到消息时清理过期关联。管理员回复过期记录时提示重新获取会话，不转发给猜测的用户。
- 去重 ID 保留 3 天，最多 20,000 条；达到上限时暂缓接收，不提前删除近期去重 ID。
- 普通用户每分钟最多 10 条；屏蔽列表最多 10,000 个 ID；最近处理事件只保留 100 条，不存正文。
- 每个发送步骤先持久记录意图，再调用 Telegram；重复 Webhook 不重做已经确认的步骤。
- 确定的 429 按 retry_after 等待 Telegram 重投，保留已经完成的卡片/回信步骤。
- 网络超时、5xx 或进程在发送中中断，可能出现“已送达但未确认”。此时标记 **结果待确认**，不盲目重发；管理员先检查 Telegram，再决定是否手工重发。外部 API 没有本服务可用的发送幂等键，本方案不承诺网络故障下恰好一次送达。
- 用户屏蔽机器人、消息受平台限制等失败记录在后台，给管理员尽力发送提示；失败通知自身也可能发送失败。

修改 Token 或管理员需先暂停。更换身份会清空旧会话/屏蔽/去重关联并更换 secret，避免旧消息 ID 串到新机器人。暂停先停止本站发送，再移除远端 Webhook；若网络异常，页面依旧显示暂停，恢复后检查远端连接。

## 部署包与备份

公开代码包与 PRIVATE 首次部署包都不携带机器人会话/Token；机器人配置在生产后台单独填写。后续更新只替换代码，保留 `storage/telegram/`。备份该目录时应和其他私有配置一样限制访问并加密保存；请勿把它放进公开站点或仓库。

本轮不修改 iOS 安装器；此前的 0.8.3 / build 19 仍为当前包更新客户端。

## 本地测试

```sh
php tests/telegram.php
PHP_BINARY=php python3 tests/telegram-http.py
PHP_BINARY=php python3 tests/integration.py
node tests/upload-ui.test.cjs
```

前两项使用注入的假 Telegram API 或仅本机 HTTP，不访问真实 Telegram。覆盖权限、双向路由、多用户隔离、附件、屏蔽、限流、重复/并发更新、429 恢复、不确定发送、Token 不回显、CSRF、随机路径和请求体上限。真实 BotFather Token、管理员测试消息、生产 HTTPS/CDN 及实聊仍待接入后验收。
