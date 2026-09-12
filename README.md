# 满天星更新中心 · PHP

**一个安装器绑定一个游戏和一个固定地址。密码登录后台上传、校验、发布新包。**

纯 PHP + 服务器目录存储，**没有数据库、Redis 或前端构建服务**。版本及审计写入 JSON，包文件按 SHA-256 保存；密码校验摘要和发布密钥在服务器私有 PHP 配置中。

## 已实现

- 单管理员密码登录、CSRF、8 小时会话、错误密码限流、退出登录。
- 游戏独立配置、固定接口地址、预期 Bundle ID、启用/停用。
- TIPA 上传进度、XML/binary plist 元数据识别、ZIP 路径/大小/CRC/身份检查。
- 原始 TIPA 或准备产物 TAR 两种分发模式。
- 草稿 → 准备完成 → 发布；下架、历史内容复制为新草稿、发布序号递增。
- 文件锁 + 原子替换 JSON，重复发布幂等，陈旧页面发布冲突检查。
- Ed25519 签名更新清单、短期下载凭证、HEAD、ETag、单范围断点续传。
- 登录及发布审计，保留最近 1,000 条。

**边界：这是服务端。尚未修改或重新打包原巨魔安装器，也未做其远程更新真机验证。** 默认三角洲配置读取准备产物 TAR：上传 TIPA 后还需补充匹配的 `MTXPayload.tar`。后台验证结构、输入绑定和内部摘要，不代替客户端签名/机型兼容检查。自动生成 TAR 的 Mac worker 尚未接入。

## 环境

- PHP 8.3–8.5（使用仍受支持分支的最新补丁），扩展 zip、sodium、mbstring、dom、libxml。
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

setup 会在终端交互读取密码，不写入 Git、不使用默认密码。后台入口：`http://127.0.0.1:8787/admin/`。

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

1. 登录后台，选择游戏配置；新游戏填写其真实 Bundle ID 和产物契约。
2. 上传 TIPA，填写更新说明、最低安装器版本及最高 iOS 范围；最低 iOS 从包中读取。
3. 对当前三角洲模式，补充与该源 TIPA 对应的准备产物 TAR。结构或摘要不匹配时停止。
4. 完成对应安装器兼容测试后勾选确认，再发布。固定接口保持不变。
5. 下架后停止该版本下载，不自动切回旧包。历史版本可复制成新草稿，重新发布后获得更大序号。

后台默认的 `0.8.0` 是远程版最低安装器版本的**可编辑建议值**，不是声称现有 `0.7.1` 已接入。构建客户端时须确定正式版本和 profile，并填入相同配置。

## 接口

固定入口：`/api/update.php?app=mtx-dfm-cn`，查询还需 nonce、installer_version、os_version、profile；支持可选 installed_sequence。游戏地址是路由，不是登录/购买凭证。当前已发布版本为公开分发，尚无卡密权限模块。

签名算法 Ed25519；签名覆盖 Base64 解码后的原始 UTF-8 payload 字节，客户端先验签后解析。公钥在登录后的「当前构建绑定」内查看，构建时写入客户端。下载完成仍检查清单中的 SHA-256/长度/包身份。

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
```

Python 3 测试使用标准库，自动创建临时 PHP 多进程服务器与合成测试包，结束后清理，不触及正式 storage。Node 仅用于上传控制器回归测试，运行服务不需要 Node。

本轮验证记录见 [测试报告](docs/testing.md)。
