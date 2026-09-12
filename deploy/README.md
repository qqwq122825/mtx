# 部署与运维（无数据库）

## 1. 安装

1. 将源码或部署压缩包解压至 `/srv/mtx`。
2. 使用 PHP 8.3+ 最新维护补丁，启用 zip、sodium、openssl、mbstring、dom、libxml。
3. 源码部署先执行 Composer 安装；部署压缩包已有 vendor。
4. 在服务器终端执行 `php /srv/mtx/bin/setup.php --url https://你的域名`，交互输入管理密码。setup 自动生成随机入口目录，请保存输出地址；主页保持 404。已绑定安装器部署时，使用配套私有配置，不要重新生成密钥。
5. 设置 Nginx Web 根目录为 `/srv/mtx/public`，配置 PHP-FPM 和 HTTPS；将同目录 nginx.conf.example 的 RANDOM_ENTRY 替换成配置中的入口（去掉前导 /）。
6. 只让 PHP-FPM 用户读私有配置、写 storage；代码目录保持只读。目录权限按实际运行用户设置，避免使用全员可写权限。

setup 不覆盖已有配置或状态，也不会在网页暴露安装入口。

默认使用 `/srv/mtx/storage`。也可以初始化时添加 `--storage /var/lib/mtx-updates`，支持固定本机磁盘目录；多实例部署需另行实现共享存储及锁协调。

## 2. 密码放在 PHP 配置

`config.local.php` 中的 password_hash 用于 `password_verify` 校验。登录只有一个密码，无用户名、无注册功能。私有配置包含本实例的发布密钥和下载票据密钥，不放入 Git。

更改密码可通过 `php /srv/mtx/bin/password.php` 从标准输入传入新密码。建议在终端先隐藏输入保存到临时 shell 变量，再通过管道传入，避免将密码写入历史或命令参数：

```sh
read -s -p 'New password: ' MTX_PASSWORD; printf '\n'
printf '%s\n' "$MTX_PASSWORD" | php /srv/mtx/bin/password.php
unset MTX_PASSWORD
```

以上交互示例使用 Bash。密码更换后既有登录会话在下一请求失效。此操作不更换发布密钥。

## 3. PHP / 反向代理

- 256 MiB 源包上限；post_max_size / client_max_body_size 预留 multipart 开销至 260 MiB。
- 设置 max_execution_time 300 秒及足够磁盘空间。
- HTTP 只用于本机开发，正式配置启用 HTTPS；示例 Nginx 向 PHP 设置 HTTPS=on。
- 如果 TLS 在另一层终结，只接受可信代理传递的 HTTPS 状态，不直接信任来自客户端的 forwarded headers。
- 下载流在当前实现中由 PHP 以 1 MiB 分块读取，支持单范围续传，内存不随包体积增长。大规模分发时再接 Nginx X-Accel 或对象存储，当前不包含此加速适配。
- Nginx 示例限制入口、配置请求限流，并关闭带下载凭证的 API 查询日志。

## 4. 升级与备份

升级仅替换代码和 vendor，保留 config.local.php 与整个 storage。新增版本的一致性由固定 state.lock 与原子 JSON 替换保证。

备份时安排维护窗口，暂停所有写入，再一起备份私有配置、state.json 与 objects。恢复时回到同一时间点，先验证发布对象存在且摘要匹配，再恢复访问。发布私钥保持原值，否则已有安装器公钥将不匹配。备份含密钥，应独立加密并限制访问。

不要只备份 JSON 而遗漏包文件；也不要在活跃写入时依次复制文件并视为一致备份。

失败请求可能留下尚未被版本记录引用的内容寻址对象，这是为了优先保证发布一致性。首版不自动清理对象；维护窗口里对照 state.json 检查后再归档处理。PHP Session 开启概率式过期回收。

## 5. 发布与安装边界

当前服务不执行 TIPA 内程序、不自动调用 Mac 工具、不编译巨魔。源包 → TAR 的准备仍使用原工程独立工作副本完成，回传的 TAR 校验 Manifest.plist 的源摘要、Bundle ID、显示版本和文件摘要。

后台校验通过不等于真机兼容性测试通过；发布确认由管理员在实际测试后完成。最低安装器版本由实际构建决定。

切换当前发布、下架或停用游戏后，旧票据在下载请求开始时也会再次检查状态；已经开始流式发送的请求可能继续完成。服务器没有删除手机已下载文件的功能。

## 6. 配套远程版安装器

详见 [CDN、目录路由与客户端](../docs/cdn-and-installer.md)。当前尚未上线，统一使用 game_id 协议，已移除旧安装器与旧状态自动迁移代码。首次部署使用本轮配套安装器及服务器包。以后代码更新时保留私有配置、数字 ID、计数器及包文件；配套生产私有包只用于新站首次部署，不覆盖已运行站点的 storage。

## 7. 游戏 ID 与首次部署包

所有游戏共用 `/<随机入口>/api/update.php`，通过 game_id 区分。后台创建游戏时自动分配递增 ID，现有三角洲为 1；固定 ID 随 state.json 和 next_game_id 一起备份。不要手动重编号或重建已有站点状态。

`php bin/package-private.php --url https://mtx.jk92.cc` 导出配套 PRIVATE 包，包含当前游戏目录、ID 计数器、对应密码摘要及密钥，但没有版本、包文件或发布指针。仅用于新站首次部署，旧站保留自己的配置与 storage。安装器用 `bin/export-installer.php --game-id ID --url https://mtx.jk92.cc --out /完整路径/MTXRemote.generated.h` 导出公开配置后重新构建。

## 8. Telegram 客服

部署更新后的 `public`、`src` 和 Nginx 示例规则：后台新增 `/<随机入口>/admin/telegram.php`，Webhook 为 `/<随机入口>/api/telegram-webhook.php`。启用 PHP curl，允许出站访问 api.telegram.org:443；Webhook 在 CDN 上不缓存、不挑战，保留 secret 校验头。生产后台填 Token / 个人数字 ID 后启用。

机器人配置和关联记录在 `storage/telegram/`，更新时保留，备份时按密钥级别保护。首次部署压缩包不携带该目录的配置和历史。详见 [Telegram 客服说明](../docs/telegram-support.md)。
