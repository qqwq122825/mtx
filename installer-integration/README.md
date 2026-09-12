# 定制版巨魔远程更新接入补丁

本次已直接修改用户提供的 `定制版巨魔/TrollInstallerX` 工作目录，并构建了远程版 0.8.3 / build 19。该目录有既有未提交定制内容，且 origin 指向第三方上游，因此不向该 upstream 推送；本仓库保存此次变更补丁和基线摘要。

`remote-update.patch` 仅包含本次变更，基于用户现有定制工程，不是可独立构建的新 TrollInstallerX 源码发布。`baseline.json` 记录每个相关文件的修改前/后摘要。补丁不包括源 TIPA、准备 TAR、安装助手二进制、签名私钥、密码或真实随机入口。

## 复现

1. 使用本次修改前的定制工程副本；先保留现有修改。
2. `python3 apply.py --installer /工程完整路径/TrollInstallerX`。所有基线摘要匹配才应用；已匹配修改后摘要则幂等跳过。不要直接覆盖不同版本的定制工程。
3. 后台新增游戏会自动分配 ID（现有三角洲为 1）。在 PHP 服务项目执行 `php bin/export-installer.php --url https://mtx.jk92.cc --game-id 1 --out /工程完整路径/TrollInstallerX/TrollInstallerX/Installer/MTXRemote.generated.h`，使用与生产一致的私有配置。`--game-id` 选择此次构建绑定的游戏；工具自动填入 ID、名称、Bundle ID 和契约，各游戏接口地址相同。该命令只输出公钥和固定公开绑定，不导出私钥。
4. 保留原有本地工具依赖、源包和准备流程，运行工程的 `build.sh`；Xcode 重新生成助手/准备资源。产物改名为 `~/Documents/MTXInstaller-remote-unsigned.tipa`，保留旧离线版输出。
5. 给外层安装器签名后做对应真机测试。未进行真机安装验证的版本，不应直接勾选后台兼容确认。

当前仅实现 game_id 协议，不保留旧 app/app_key 或旧三角洲全局序号导入。旧测试安装器不作为兼容目标。

## 正式服务器绑定检查（2026-09-12）

已从配套配置重新导出三角洲国服 `game_id=1` 的公开绑定，并重新构建 0.8.3 / build 19。实际通过 `mtx.jk92.cc` HTTPS 请求，使用工程中与主程序、助手共用的 Apple Security 验签实现验证生产响应：公钥、游戏 ID、nonce 与有效期匹配，无重定向。此时生产状态为 `no_release`，尚未发布游戏包；这表示连接成功，不是下载故障。

可使用以下只读检查重新验证任意已导出绑定的工程：

```sh
python3 tests/installer-production.py --installer /完整路径/TrollInstallerX
```

该检查使用临时编译目录，只读取签名更新信息，不登录后台、不下载包、不发布版本，也不执行安装。响应状态随服务器发布情况变化。检查不读取服务器密码或私钥，生产入口、公钥仍只放在被忽略的生成头文件中。

交付包仍为 `~/Documents/MTXInstaller-remote-unsigned.tipa`，另外在用户指定的「定制版巨魔」文件夹保存了「满天星三角洲国服-远程安装器-0.8.3-正式服.tipa」。主程序与助手内的公钥均已与生成头文件核对，未包含服务器私有凭据。安装器固定绑定游戏 ID，用户无需输入 URL 或选择游戏。保持服务端密钥和接口不变，同游戏后续发布无需重打安装器。

目前完成的是生产接口验签、本地下载流程回归与真机目标构建；完整线上 TIPA/TAR 下载及设备安装仍须在发布对应包后验证。

本次只调整下载、校验、日志显示和资源传递；现有兼容性选择与安装机制沿用。原生跨 PHP 验签和下载测试位于 `tests/installer-update.py`，只使用本机临时签名密钥和合成文件。

修改所基于代码的许可证见 `LICENSE-TrollInstallerX`。
