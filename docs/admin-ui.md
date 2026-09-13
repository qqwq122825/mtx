# 后台界面

后台继续使用 PHP 和原有目录数据，不引入数据库、不迁移接口。默认 `/admin/`，实际文件夹改名仍由 `App::path()` 自动识别。

## 页面组织

- **游戏更新**：下拉选择游戏、上传更新包；版本使用 Element Plus 表格搜索和分页，点“详情与操作”展开原有发布表单。接口、公钥及绑定参数默认折叠。
- **主页与安装器**：主页链接与安装器上传分栏，当前游戏的公开下载入口集中显示为表格。
- **客服会话**：保留原有查询、状态筛选及会话详情。
- **机器人设置**：连接设置、双列自动回复；使用说明默认折叠。
- **公告管理**、**活动与卡密**：保持各自表单、记录和确认步骤，统一布局。
- 密码登录页保持无需用户名、记住登录 30 天的原有行为。

手机使用侧边抽屉导航，内容单列，宽表格在区域内横向滚动；不会让整个页面横向溢出。

## 实现

`src/AdminLayout.php` 输出公共导航和顶部栏。`frontend/admin.js` 使用本地打包的 Vue 3 / Element Plus，增强选择框、只读表格及确认弹窗。普通输入、文件上传、CSRF、POST action 和服务端验证保持原样。`frontend/legacy-admin.js` 保留上传进度控制器。

有动作的版本表单保留在原来的 DOM 中；表格只是摘要入口。展示内容通过文本节点渲染；安装器表格仅保留本页已生成的 `/installer?game_id=N` 下载链接，不解释任意 HTML。

`public/assets/admin-ui.css` 通过登录后的 `telegram.php?asset=admin-css` 白名单提供，不新增 Nginx 路由。共用脚本仍走原有 `app.js` 路径。基础 `app.css` 仅追加独立登录页样式。

构建：在 `frontend` 运行 `npm ci && npm run build`。同时提交构建产物；生产环境只需要 PHP，无需 Node。

回归：`node tests/admin-ui.test.cjs`、`node tests/upload-ui.test.cjs`、`node tests/telegram-announcements-ui.test.cjs`；PHP 8.3+ 下运行 HTTP 和业务测试。仅使用临时目录及测试数据，测试过程不触发 Telegram API。
