# 满天星更新中心 · PHP

**所有游戏共用一个更新地址，安装器通过游戏 ID 绑定对应游戏。密码登录后台上传、校验、发布新包。**

纯 PHP + 服务器目录存储，**没有数据库、Redis 或前端构建服务**。版本及审计写入 JSON，包文件按 SHA-256 保存；密码校验摘要和发布密钥在服务器私有 PHP 配置中。

## 已实现

- 后台默认 `/admin/`，直接重命名 public 下的后台文件夹即可变更入口；无用户名，仅密码登录。
- 安装器 API 保留固定随机前缀，主页和旧后台路径 404，不泄露新后台地址。
- 单管理员密码登录、CSRF、8 小时会话、错误密码限流、退出登录。
- 游戏 ID 自动分配、统一接口地址、预期 Bundle ID、启用/停用。
- TIPA 上传进度、XML/binary plist 元数据识别、ZIP 路径/大小/CRC/身份检查。
- 原始 TIPA 或准备产物 TAR 两种分发模式。
- 草稿 → 准备完成 → 发布；下架、历史内容复制为新草稿、发布序号递增。
- 文件锁 + 原子替换 JSON，重复发布幂等，陈旧页面发布冲突检查。
- Ed25519 + P-256 签名更新清单、短期下载凭证、HEAD、ETag、单范围断点续传。
- 登录及发布审计，保留最近 1,000 条。
- Telegram 双向客服：密码后台配置、用户私聊转交、管理员回复回传、屏蔽/限流/去重。
- 机器人公告：格式化文案、双链接按钮、实时预览、管理员测试、定时投递、发送后自动删除。

**客户端已接入：定制版巨魔 0.8.3 / build 19，先下载 TIPA（字节进度条）→ 下载匹配的 TAR → 校验 → 准备缓存 → 安装。** 下载错误只显示重试提示，安装阶段异常才显示诊断日志。已完成本地自动测试和 iOS 构建，真机安装/线上部署尚待验证。

默认三角洲配置仍需要 TIPA + 对应 `MTXPayload.tar`。PHP 校验结构与摘要，不执行上传程序，不自动生成 TAR。固定游戏客户端的具体接入、CDN 规则和密钥配置见 [远程安装器与 CDN](docs/cdn-and-installer.md)。

## Telegram 客服机器人

后台新增「Telegram 客服」入口。用户私聊机器人，你在 Telegram 直接回复对应消息即可回信；不公开你的私人账号，不需要数据库。部署到公网 HTTPS 后，在密码后台填写 Bot Token 和个人数字 ID，启用 Webhook。服务器额外需要 PHP curl 扩展。

详细配置、数字 ID 获取、CDN 规则与测试见 [Telegram 客服说明](docs/telegram-support.md)。机器人尚未填真实凭据，本机只做了模拟转发测试；本轮无需重新打包 iOS 安装器。

公告卡片管理见 [公告与定时删除](docs/telegram-announcements.md)：支持单次 / 重复定时、独立自动删除开关，需要服务器每分钟运行 `bin/telegram-tick.php`。现有 iOS 客户端无需改动。

## 环境

- PHP 8.3–8.5（使用仍受支持分支的最新补丁），扩展 zip、sodium、openssl、mbstring、dom、libxml。
- Composer 2（仅首次安装依赖时需要）。部署压缩包已包含 vendor，无需服务器再次安装依赖。
- 本地可用 PHP 内置服务器；生产使用 HTTPS + Nginx/PHP-FPM。
- 存储要求：本机持久文件系统，支持 flock 与同目录原子 rename。首版适合单服务器/单管理员，不使用无锁 NFS 或多实例共享目录。

## 本地启动

在项目根目录执行：

```sh
composer install --no-dev --prefer-dist
php bin/setup.php --url http://127.0.0.1:8787 --local-http
sh bin/serve.sh
```

setup 会在终端交互读取密码，不写入 Git、不使用默认密码。setup 会输出默认后台入口：`http://127.0.0.1:8787/admin/`。直接重命名 `public/admin` 即可更改后台地址，保留其中 `.mtx-admin` 文件；详见 [后台目录说明](docs/admin-directory.md)。安装器 API 的随机前缀独立保留。

如果系统默认 PHP 过旧，用 PHP 8.3+ 的绝对路径执行 setup；serve 使用 `PHP_BINARY=/实际路径/php sh bin/serve.sh`。端口修改时需同步私有配置中的 base_url。

当前本机已配置使用用户指定密码，配置不随仓库推送。

## 存储布局

```text
config.local.php             # 密码摘要、发布私钥、域名、存储绝对路径；私有
storage/
  state.json                 # 游戏、版本、当前发布指针、审计
  state.lock                 # 固定锁文件，运行时保持不变
  objects/<sha256>            # 原始 TIPA / 准备产物；无公开静态目录
  sessions/                  # PHP 登录会话
  login-attempts.json         # 15 分钟错误密码窗口
  login.lock
public/                      # 唯一 Web 根目录
src/                         # PHP 业务代码
```

默认配置和 storage 都在 public 之外。可通过 `MTX_CONFIG` 环境变量指定另一份私有 PHP 配置。不要移动锁文件或在服务运行中手工覆盖 state.json。

## 使用流程

1. 登录后台，选择游戏；新增游戏只填名称、真实 Bundle ID、分发格式，后台自动分配数字 ID 和内部产物契约。现有三角洲为 ID `1`。
2. 选择 TIPA 上传即可；备注可选、仅后台可见。包信息自动读取，版本兼容参数由程序内部维护。
3. 对当前三角洲模式，补充与该源 TIPA 对应的准备产物 TAR。结构或摘要不匹配时停止。
4. 完成对应安装器兼容测试后勾选确认，再发布。固定接口保持不变。
5. 下架后停止该版本下载，不自动切回旧包。历史版本可复制成新草稿，重新发布后获得更大序号。

本轮远程安装器版本为 `0.8.3`。用户点击“安装”即自动下载和准备，不展示版本说明或前置更新弹窗；最后选择系统 App 时确认替换。原 `0.7.1` 是离线内置包版本。其他游戏应重新构建匹配其身份与 profile 的专用安装器。

## 给另一个游戏打包

后台创建后记下游戏 ID，例如 `2`；从对应服务器私有配置导出公开绑定：

```sh
php bin/export-installer.php --url https://mtx.jk92.cc --game-id 2 --out /工程完整路径/TrollInstallerX/TrollInstallerX/Installer/MTXRemote.generated.h
```

工具自动填写该游戏的 ID、名称、Bundle ID、产物契约、公钥和同一个接口地址，再构建对应游戏的安装器。新建 ID 持久保存在 JSON 中，分配时加锁；停用不回收、不改变已有 ID。同一游戏后续只更新服务端包即可。

## 接口

共用入口：`/<随机入口>/api/update.php`，通过 `game_id=1` 选择游戏。安装器自动追加 nonce、installer_version、os_version、profile；支持可选 installed_sequence。游戏 ID 是路由，不是登录/购买凭证。仅接受 `game_id`；`app/app_key` 请求返回 422。服务尚未上线，不保留旧安装器协议或自动迁移分支。当前已发布版本为公开分发，尚无卡密权限模块。

签名提供 Ed25519 和 P-256，原生安装器及助手使用 P-256；签名覆盖 Base64 解码后的原始 UTF-8 payload 字节，客户端先验签后解析。公钥在登录后的「当前构建绑定」内查看，构建时写入客户端。下载完成仍检查清单中的 SHA-256/长度/包身份。

详见 [当前 API 协议](docs/api-contract.md)。

## 生产部署

参见 [部署说明](deploy/README.md)、[Nginx 配置](deploy/nginx.conf.example)、[PHP 配置](deploy/php.ini.example)。Web 根目录必须是 public。不要将仓库根目录映射为网站根目录。

```sh
php bin/package.php
```

生成 `build/mtx-update-center.zip`，只打包代码、依赖和部署说明，不包含配置、密码、storage、源包或本地记录。

## 测试

```sh
PHP_BINARY=php python3 tests/integration.py
node tests/upload-ui.test.cjs
PHP_BINARY=php python3 tests/installer-update.py --installer /定制版巨魔工程完整路径/TrollInstallerX
```

Python 3 测试使用标准库，自动创建临时 PHP 多进程服务器与合成测试包，结束后清理，不触及正式 storage。Node 仅用于上传控制器回归测试，运行服务不需要 Node。

本轮验证记录见 [测试报告](docs/testing.md)。
