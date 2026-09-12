# 定制版巨魔远程更新接入补丁

本次已直接修改用户提供的 `定制版巨魔/TrollInstallerX` 工作目录，并构建了远程版 0.8.2 / build 18。该目录有既有未提交定制内容，且 origin 指向第三方上游，因此不向该 upstream 推送；本仓库保存此次变更补丁和基线摘要。

`remote-update.patch` 仅包含本次变更，基于用户现有定制工程，不是可独立构建的新 TrollInstallerX 源码发布。`baseline.json` 记录每个相关文件的修改前/后摘要。补丁不包括源 TIPA、准备 TAR、安装助手二进制、签名私钥、密码或真实随机入口。

## 复现

1. 使用本次修改前的定制工程副本；先保留现有修改。
2. `python3 apply.py --installer /工程完整路径/TrollInstallerX`。所有基线摘要匹配才应用；已匹配修改后摘要则幂等跳过。不要直接覆盖不同版本的定制工程。
3. 后台新增游戏会自动分配 ID（现有三角洲为 1）。在 PHP 服务项目执行 `php bin/export-installer.php --url https://mtx.jk92.cc --game-id 1 --out /工程完整路径/TrollInstallerX/TrollInstallerX/Installer/MTXRemote.generated.h`，使用与生产一致的私有配置。`--game-id` 选择此次构建绑定的游戏；工具自动填入 ID、名称、Bundle ID 和契约，各游戏接口地址相同。该命令只输出公钥和固定公开绑定，不导出私钥。
4. 保留原有本地工具依赖、源包和准备流程，运行工程的 `build.sh`；Xcode 重新生成助手/准备资源。产物改名为 `~/Documents/MTXInstaller-remote-unsigned.tipa`，保留旧离线版输出。
5. 给外层安装器签名后做对应真机测试。未进行真机安装验证的版本，不应直接勾选后台兼容确认。

本次只调整下载、校验、日志显示和资源传递；现有兼容性选择与安装机制沿用。原生跨 PHP 验签和下载测试位于 `tests/installer-update.py`，只使用本机临时签名密钥和合成文件。

修改所基于代码的许可证见 `LICENSE-TrollInstallerX`。
