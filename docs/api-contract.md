# 固定游戏更新接口 v1（已实现）

服务端：纯 PHP + 目录/JSON，无数据库。每个安装器只绑定一个 app_key。下文 `/api`、`/admin` 均相对于配置的随机 mount_path；实际请求为 `/<随机入口>/api/...`，根路径无接口。

## 固定版本接口

`GET /api/update.php?app=mtx-dfm-cn`

必须附加：

| 参数 | 说明 |
| --- | --- |
| nonce | 每次新生成 32 字节随机值，Base64URL、去掉末尾等号，共 43 字符 |
| installer_version | 例如 0.8.0，1–3 段数字 |
| os_version | 例如 16.1.2，1–3 段数字 |
| profile | 构建时固定的产物契约，如 mtx-dfm-remote-v1 |
| installed_sequence（可选） | 可靠安装回执中的发布序号；未知时省略，不把下载记录当安装记录 |

所有正常结果使用 HTTP 200，响应结构：

```json
{
  "key_id": "mtx-release-1",
  "payload_base64": "BASE64_OF_ORIGINAL_UTF8_JSON",
  "signature_base64": "BASE64_OF_ED25519_SIGNATURE",
  "native_signature_base64": "BASE64_OF_DER_ECDSA_P256_SHA256_SIGNATURE"
}
```

这些占位值不是可验证签名。公钥在后台「查看安装器公钥配置」中获取，构建时固定到客户端。Ed25519 字段保留；原生安装器及助手验证 P-256/SHA-256 的 native_signature_base64（DER X9.62），公钥为 X9.63。先验签原始 payload 字节，再解析 JSON，不重新序列化后验签。

payload：schema_version=1、app_key、原请求 nonce、issued_at、expires_at、status、message、release。

状态：

- no_release：游戏停用或暂无激活发布，release=null。
- client_upgrade_required：客户端版本低于最低要求，或 profile 不匹配，release=null。
- incompatible：系统不在声明范围内，release=null；机型和系统组合最终由客户端核对。
- version_unknown：已安装序号缺失或高于当前服务端版本，附候选 release；用户确认后安装。
- up_to_date：已安装序号等于当前发布，附 release。
- update_available：已安装序号低于当前发布，附 release。

release 字段：release_id、sequence、display_version、bundle_version、bundle_id、profile_id、artifact_format、artifact_id（64 位 SHA-256，与 artifact_sha256 相同）、artifact_bytes、artifact_sha256、source_sha256、source_bytes、manifest_sha256（prepared 格式的 Manifest.plist 原始字节摘要）、min_installer_version、min_ios、max_ios、changelog、published_at。

响应有效期 10 分钟，Cache-Control=no-store。客户端必须核对 nonce、有效时间、固定 app_key/Bundle ID/profile/允许格式、大小和摘要；本地维护每个 app 的最高已验证 sequence 防止旧响应被接纳。0.8.0 原生安装器已接入签名、nonce、时间和游戏契约校验；UserDefaults 已观察序号不是抵抗容器删除的硬件防回滚。

## 下载凭证

`POST /api/download-ticket.php`，Content-Type=application/json。

```json
{"app_key":"mtx-dfm-cn","release_id":"RELEASE_ID","artifact_id":"SHA256"}
```

返回 app_key、release_id、artifact_id、url、expires_at。默认 15 分钟有效。只给启用游戏的当前已发布版本发票据，artifact_id 接受该发布的 source_sha256（原始 TIPA）或 artifact_sha256（安装资源 TAR），验证产物绑定；旧版本、草稿、下架版本没有新票据。

第一版分发是公开的：任何知道固定 app 的客户端可查询已发布版本和申请票据；管理员身份只控制上传/发布，不代表购买/卡密权限。

## 下载文件

票据 url 指向 `GET /api/download.php`；同样支持 HEAD。

- 完整文件：200，application/octet-stream、Content-Length、ETag、Accept-Ranges。
- 合法单范围：206，Content-Range；支持起止、开放结束及尾部范围。
- 越界或多范围：416。
- If-Range 与当前强 ETag 不匹配：返回完整 200。
- 凭证过期/错误：403。
- 被替换的旧发布、下架、停用：404。下载请求开始时重新检查。

当前 PHP 分块发送文件，不是静态目录公开下载。客户端刷新短期链接时仍核对相同 artifact_id。正式环境只接受 HTTPS 和构建内允许的下载域。

## 错误响应

```json
{"error":{"code":"invalid_request","message":"字段格式异常"},"request_id":"REQUEST_ID"}
```

常用状态：400 请求错误、403 CSRF/凭证失败、404 记录不存在、405 方法错误、409 状态/并发冲突、413 大小超限、415 类型错误、422 字段/归档检查失败、429 登录限流、503 暂时不可用。错误不回传磁盘路径和堆栈。

## 后台接口

GET `/admin/` 管理页，GET/POST `/admin/login.php` 登录；所有写操作统一为 POST `/admin/action.php`，必须带登录会话、CSRF 和 action：

- create：新增游戏；app_key、name、bundle_id、format、profile_id。
- upload：multipart file=.tipa、app_key、changelog、max_ios、min_installer_version。
- attach：multipart file=.tar、app_key、release_id。
- publish：app_key、release_id、revision、confirmed=1；版本 ID 作为幂等操作目标。
- withdraw：app_key、release_id、revision。
- republish：app_key、release_id，将完整历史内容复制为新的 ready 草稿。
- toggle：app_key、revision，启用/停用。
- logout：退出会话。

普通表单成功后 303 返回管理页；上传控制器使用 X-MTX-Upload:1 获得 JSON {redirect,message} 或结构化错误。自动 Mac 准备任务、独立 worker 接口、进度任务 API 未实施。
