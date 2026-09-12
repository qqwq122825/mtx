# 单 TIPA 上传与自动处理

## 使用者看到的流程

选择游戏 → 上传安装器匹配的**游戏辅助 TIPA** → 等待服务器自动处理 → 确认兼容后发布。不是上传远程安装器本身。包的信息自动读取，备注仅后台可见；没有第二份 TAR 上传要求。

服务器每分钟领取任务，单次最多两个；页面显示等待、处理或失败，点击“刷新处理状态”查看。失败记录保留原上传，可点“重新处理”；结构不匹配则检查辅助包后重新上传。服务端检查完成只代表产物满足内部契约，真实设备安装仍需管理员测试确认，处理脚本不自动发布。

安装器 0.8.3 / build 19 的固定游戏 ID、签名接口和下载协议不变：先带进度下载原始 TIPA，再获取同一发布的内部安装资源，校验后准备缓存并安装。同游戏更新无需重新打包安装器。

## 首次配置（Linux）

以下是 Debian/Ubuntu 示例，路径和运行用户按实际部署调整。宝塔本实例项目路径 `/www/wwwroot/mtx.jk92.cc`，PHP `/www/server/php/84/bin/php`，运行用户 `www`。

```sh
apt-get install --no-install-recommends python3 clang libssl-dev libblocksruntime-dev
cd /srv/mtx
sh preparer/native/build.sh
chown -R root:root preparer
find preparer -type d -exec chmod 755 {} +
find preparer -type f -exec chmod 644 {} +
chmod 755 preparer/native/build/mtx-prepare
```

源代码与组件只读，只有 storage 由 PHP 用户写入。保留现有配置、密钥和整个 storage，不要重新 setup。Python 不需要 pip 依赖；运行时不访问 GitHub 或下载编译器。部署包包含固定源代码而非 Mac 可执行文件，更新处理组件后在目标服务器重新编译。

先以 PHP 用户试跑一次：

```sh
cd /srv/mtx
runuser -u www -- /usr/bin/python3 preparer/worker.py --php /usr/bin/php
```

成功日志只包含 release_id 和 ready，不输出密码、发布密钥或原生工具日志。无待处理任务时安静退出。若使用自定义配置，以运行用户可读的绝对 `MTX_CONFIG` 传入；临时目录根据配置中的 storage 自动决定。

复制并调整 `deploy/preparation-cron.example` 为 `/etc/cron.d/mtx-prepare`，root 所有、0644。同时按 `deploy/preparation-logrotate.example` 设置日志轮转。与 Telegram 的定时任务独立，两者可以同时启用。

Mac 本地验证需要 Xcode Clang 与 Homebrew OpenSSL 3（默认 `/opt/homebrew/opt/openssl@3`）。先运行相同 build.sh，再执行 worker，PHP 可指定本机 8.3+ 的绝对路径。本地调试手动运行一次即可，不需要持续开着电脑处理正式包。

## 检查与限制

- 仅处理符合当前定制安装器契约的辅助包：单 arm64 主程序、`MTXMenuIcons.ttf`、匹配 Bundle ID、系统版本及已存在的 SHA-256 签名/所需权限；不是通用 IPA 转换器。其他游戏需要自己的匹配安装器和同结构辅助包，额外资源需扩展两端契约。
- ZIP 路径、总大小、CRC、Mach-O 结构、依赖和源摘要先检查，上传程序永不执行。固定本机组件仅修改工作副本的签名区，前后重新验证程序代码指纹、权限原始字节和代码页摘要。
- Linux 子进程有 100 秒墙钟超时、90 秒 CPU、512 MiB 地址空间和输出大小限制，禁止 core dump。工作目录 0700、原包和中间文件私有，完成或失败后删除本次工作目录。
- 文件系统租约防止重复处理；独立进程锁避免 cron 重叠。进程崩溃后租约 5 分钟过期，最多领取三次再等待管理员重试。强制终止可能遗留工作目录，维护窗口确认没有工作进程后再清理；不要删除活动锁文件。
- 已下架、已替换租约的迟到结果不写回；后台结构校验通过后写入内容寻址对象，只有明确发布操作更新当前发布指针。
- 以前只有源 TIPA 的待准备记录也会排队，原包和记录 ID 不变。已 ready / published / withdrawn 的记录不重新处理。

## 测试

```sh
sh preparer/native/build.sh
PHP_BINARY=/实际/php python3 tests/preparation.py --source /实际/辅助包.tipa
```

测试使用一次性配置与目录，读取辅助包、不执行、不发布、不修改正式状态。覆盖单上传、租约、过期、重试、工作目录约束、自动产物与现有协议校验、原文件保持、下架保护及临时清理。

组件出处、固定版本与许可证见 `preparer/native/README.md`。
