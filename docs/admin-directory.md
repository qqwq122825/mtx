# 后台文件夹与访问地址

后台现在与安装器 API 分开：**默认后台为 `/admin/`，地址直接跟随 public 下的后台文件夹名称**。没有用户名，仍使用原管理密码。

## 默认地址

- 本地：`http://127.0.0.1:8787/admin/`
- 生产部署后：`https://mtx.jk92.cc/admin/`
- 机器人客服：`/admin/telegram.php`
- 公告管理：`/admin/telegram-announcements.php`

旧 `/<随机入口>/admin/` 返回 404，不跳转到新后台。主页和 API 随机入口根路径也不跳转或展示后台名称。

## 直接改文件夹

例如，在服务器把 `/srv/mtx/public/admin` **重命名**为 `/srv/mtx/public/console_7f92a1`，后台就是：

```text
https://mtx.jk92.cc/console_7f92a1/
```

不用改 PHP 配置、数据库、安装器或机器人 Webhook。表单地址、菜单链接、上传成功跳转、机器人页和 Cookie 路径会自动使用新名字。改名后浏览器通常需要在新路径重新登录。

- 将整个文件夹重命名，保留文件夹内的全部文件，尤其是隐藏标识 `.mtx-admin`。
- 文件夹放在 public 的第一层。名称为 1–64 个英文字母、数字、下划线或短横线，首字符用字母或数字。
- 保留一份后台目录，不要复制出多个带 `.mtx-admin` 的备份目录放在 public。备份放在网站根目录之外。
- 不使用 `api`、`assets` 或当前随机接口入口的名字，不使用符号链接。
- 升级代码时，将发行包中的 admin 内容同步到服务器现有的后台文件夹，保留当前名称；不要再并排解压出第二份 admin。

## Nginx / CDN

首次部署或从旧路由升级时，需要应用新版 `deploy/nginx.conf.example`（PRIVATE 包中同时有替换好 API 随机入口的 `deploy/nginx.configured.conf`），检查证书、目录和 PHP-FPM socket，执行 `nginx -t` 再重载。

新配置按通用目录名接受固定的后台页面文件名，统一交给 `public/index.php`。PHP 再校验真实后台目录和标识，仅分发白名单页面，不执行任意请求路径上的 PHP，也不公开隐藏文件。**应用此新版 Nginx 配置后，日后单纯改后台文件夹名无需重载或修改 Nginx。**

自定义 Nginx 配置如果仍把后台路径写死，则需要先换成上述通用规则。CDN/WAF 的后台专属规则（不缓存、IP 白名单、浏览器挑战）若按具体目录设置，改名时同步更新为新路径。整个站点 Web 根目录继续设为 `/srv/mtx/public`。

改目录只是改变入口，不替代管理密码、限流和 CDN 防护。不要让静态文件回退或另一个通用 PHP location 绕过本项目的前置控制器。

## 不变的部分

`config.local.php` 的 mount_path 现在只用于 **API 和共享静态资源**，不要为了改后台目录去改它：

- 安装器更新、下载票据、下载 API 地址及签名密钥不变。
- Telegram Webhook 地址不变，无需重新注册。
- storage 内的游戏、版本、机器人配置、会话和公告记录不变。
- 共享 CSS / JS 使用原地址，后台文件夹名变化不影响加载。

## 本地验证

```sh
PHP_BINARY=php python3 tests/admin-directory-http.py
```

测试启动真实 PHP HTTP 服务，只在临时项目副本中重命名目录，验证新旧入口、密码登录、Cookie、上传、机器人页面、资源、API 地址、标识隐藏、符号链接拒绝、多个后台目录报错与恢复。不移动正在使用的后台目录，不改真实包或机器人设置。服务器的实际 Nginx / CDN 配置需部署后验证。
