# 私有入口与远程安装器（0.8.3 / build 19）

## 路由与登录

- `config.local.php` 的 `mount_path` 是随机的 `/r-` 加 24 位十六进制字符。该前缀仅用于 API 和共享资源。主页、旧随机前缀后台和未加前缀的 `/api/` 均 404，没有主页跳转或入口列表。
- 后台默认是 `https://mtx.jk92.cc/admin/`，直接重命名 `public/admin` 可改变入口（见 [后台目录说明](admin-directory.md)），只有管理密码，没有用户名。密码 hash 在服务器私有 PHP 配置校验，数据和会话写入服务器目录，不使用数据库。
- 管理 Cookie 仅覆盖当前后台文件夹路径；所有管理动作有 CSRF 校验。首次 setup 直接生成数字游戏 ID、API 随机路径和两套签名密钥。按未上线项目处理，删除旧配置/旧安装器迁移分支。
- 随机路径会出现在安装器中，不是密码，不能防止带宽型 DDoS。上线仍需 CDN/WAF、源站防火墙、限流。

## CDN / Nginx

参照 `deploy/nginx.conf.example`，替换 `RANDOM_ENTRY`、安装目录、证书、PHP-FPM socket。

| 路径 | 缓存 | 规则 |
|---|---|---|
| `/admin/*` 或重命名后的后台目录 | Bypass / no-store | 优先 IP 白名单；可使用浏览器挑战；保留 POST、Cookie 和 CSRF 字段 |
| `/<入口>/api/telegram-webhook.php` | Bypass | POST JSON，保留 X-Telegram-Bot-Api-Secret-Token，256 KiB 上限，无挑战/跳转 |
| `/<入口>/api/update.php` | Bypass | 含 nonce 的动态签名响应；禁止 JS 挑战、HTML 注入、缓存、重定向 |
| `/<入口>/api/download-ticket.php` | Bypass | POST JSON；禁止挑战、缓存、重定向 |
| `/<入口>/api/download.php` | Bypass | 保留查询参数、Range、Content-Length；不修改字节；禁挑战和重定向 |
| `/<入口>/assets/*` | 可缓存 5 分钟 | CSS/JS |
| 其余路径 | 404 | 不暴露入口 |

客户端仅允许同一 HTTPS 域名与 API 目录，CDN 采用该域名代理而不是跳转到另一个下载域名。源站 TLS 也应启用并校验证书，不使用 HTTP 回源假装 HTTPS。若反代在别处终止 TLS，需由受信代理设置服务器参数，PHP 不信任公网请求附带的 forwarded headers。登录限流按 REMOTE_ADDR 计算；代理场景在 Nginx 只信任 CDN 官方地址范围后恢复真实 IP。CDN 上传体积/超时额度须覆盖 256 MiB，或管理端采用受控独立访问策略。

## 安装顺序与错误展示

1. 用户通过巨魔装好安装器，打开后点击“安装”即开始下面流程，不展示更新说明、版本选择或前置下载确认。固定游戏 ID、HTTPS 共用地址、公钥写入构建配置，没有用户输入 URL 或游戏切换；最后选择系统 App 时保留替换确认。
2. 请求带 game_id 和随机 nonce 的签名更新信息；检查身份、有效期、系统/客户端版本、已观察序号。
3. **真实原始 TIPA** 先下载到安装器 `Documents/RemotePackages/<SHA256>.tipa`，按已收字节显示百分比、MiB。摘要/长度通过后再下载匹配的安装资源 TAR；不是把 TAR 改后缀冒充 TIPA。
4. 刷新签名响应、确认发布没有切换；固定三文件 USTAR 解析，校验签名绑定的 Manifest 和内部文件摘要，生成不可变内存快照。
5. 之后才准备/下载缓存。此阶段尚未创建诊断日志会话，失败可直接重试。
6. 完成下载和准备后开始安装会话，沿用现有安装机制；安装失败才显示诊断日志。上次安装中断的诊断依然在重新打开时可导出。

下载失败只显示中文重试提示，不显示请求 URL、票据、堆栈或“导出日志”按钮。TIPA 有明确进度条，安装资源有独立进度；缓存接口暂只有阶段指示，不伪造百分比。已验证文件可复用；部分下载删除后重试。客户端不自动降级到内置旧包。

## 发布约定和签名

后台继续采用“上传 TIPA → 上传对应准备产物 TAR → 确认发布”。原有安装机制需要预处理产物，PHP 不运行上传的程序，也不在服务器执行签名工具。后续同游戏更新仅需这两份文件，无须重打安装器；改变游戏/契约/入口/公钥则重打。

响应保留 Ed25519，并增加 `native_signature_base64`：ECDSA P-256 / SHA-256，签同一份原始 JSON payload，签名 DER X9.62，公钥 X9.63。iOS 主进程和独立助手均使用 Apple Security 验证。实现参考 [Apple 签名与验证](https://developer.apple.com/documentation/security/signing-and-verifying?language=objc)、[PHP openssl_sign](https://www.php.net/openssl-sign)。下载 TIPA 与 TAR 各有签名绑定的 SHA256/长度，TAR 内 Manifest 原始字节摘要也单独签入，助手重新验证而不信任临时目录的自声明哈希。

`source_bytes`、`manifest_sha256` 增补至 release；下载票据 `artifact_id` 可选择当前版本的 `source_sha256` 或 `artifact_sha256`。下架/切换发布后，源包票据与 TAR 票据一起失效。

更新/票据/后台请求只接受 game_id，签名响应、票据和构建配置均没有 app_key。每个游戏只读取自己的序号缓存，不导入旧三角洲的全局序号。

后台新增游戏后自动得到 ID，导出时只需选择 `--game-id`，工具自动查出其内部身份、Bundle ID 和产物契约。所有构建的 update_url 都是相同的不带查询参数的 `/api/update.php`，客户端运行时追加 game_id。不同游戏的包身份和已观察序号独立校验，混用响应或票据会被拦截。

签名私钥只能放服务器；构建只导出公钥。**已经绑定的安装器须与生产服务器保持同一密钥和入口，重新 setup 生成另一套密钥会导致校验失败。** `php bin/export-installer.php --url https://mtx.jk92.cc --game-id 1 --out /完整路径/MTXRemote.generated.h` 导出公开构建绑定；该文件不提交 Git，避免公开部署路径。公钥本身无需保密。生产私有配置请独立备份。

本地 UserDefaults 的已观察序号仅用于减少误降级；不是抵抗容器删除/系统时间修改的硬件防回滚机制。没有把“已下载”误当作“已安装”；每次操作重新确认服务器发布，不依赖可能失真的宿主版本。

上传页不再要求填写最低安装器版本或最高 iOS；这些兼容值由内部契约维护。备注可以不填，已有备注继续仅在后台显示，所有公开签名响应均排除备注。当前内部最低安装器 0.8.0、最高 iOS 16.6.1，实际设备还接受原有兼容性检查；界面简化不改变二进制协议与设备适配条件。
