# 客服会话表格

Vue 3.5.42 + Element Plus 2.14.5，esbuild 0.28.2。锁文件固定依赖。

```sh
cd frontend
npm ci --ignore-scripts
npm run build
```

输出 `public/assets/telegram-conversations.js` / `.css`，由已经登录的 `admin/telegram.php?asset=conversations-js` / `conversations-css` 提供，不引入外部 CDN，不改变 Nginx 路由或 CSP。

使用 Vue render functions，无运行时模板编译或 `v-html`；用户正文、姓名、文件名按纯文本渲染。列表/详情用带 CSRF 的同源 POST 获取；不在 URL、浏览器缓存或 localStorage 中保存聊天文本。编译包末尾保留依赖许可声明。

官方文档：[Element Plus 安装](https://element-plus.org/en-US/guide/installation)、[表格](https://element-plus.org/en-US/component/table)。
