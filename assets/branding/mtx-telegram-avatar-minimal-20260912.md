# 满天星客服：简约头像

- 用户要求：用于客服头像，简约、清楚，文字为 MTX / 满天星；去掉星空和光效。
- 最终 PNG：`mtx-telegram-avatar-minimal-20260912.png`，1254 × 1254。
- Telegram 上传文件：`mtx-telegram-avatar-minimal-20260912.jpg`，仅转换为官方要求的 JPEG 格式，保留原构图。
- 生成方式：内置 ImageGen。先生成简约文字稿，再用内置编辑补齐不透明浅蓝底色。未使用 CLI 图像生成。
- 设置范围：仅 `@iosmtx_bot` 的头像，不变更账号、名称、自动回复和 Webhook。

## 简约版生成提示词

Use case: logo-brand. Create one extremely simple, understated customer-service Telegram avatar for MTX 满天星, square bitmap. This is a calm service identity, NOT a gaming logo or promotional poster. Solid very pale cool off-white background, absolutely uniform. Centered clean custom rounded bold sans-serif text "MTX" in a single solid deep navy-blue color, upright, friendly and easy to read. Under it, the exact Chinese characters "满天星" in a simple clean medium-weight Chinese sans-serif, smaller but still clearly legible. Exact text only: "MTX" and "满天星". Generous negative space. All typography inside the central 60% of the square and centered as a compact balanced group suitable for a circular profile crop. Pure flat two-color graphic, refined spacing, simple and practical at small sizes. No star, no galaxy, no gradients, no glows, no shadows, no 3D, no metallic finish, no bevel, no sparkles, no orbit, no border, no phone or headset illustration, no icon, no mockup, no watermark, no additional text. The entire result should feel like a quiet professional support avatar, not a sci-fi or esports brand.

## 最终编辑提示词

Use case: precise-object-edit. This is a Telegram customer-support avatar. Preserve the centered rounded navy MTX lettering and the exact Chinese text 满天星, their sizes and spacing. Change ONLY the background and clean the typography edges: fill every background pixel with a fully OPAQUE flat very light pale blue (#EAF1FA), including the holes within the letters; no alpha/transparency anywhere. The previous output has a transparent background and looks black in the preview: fix this. It must look like dark navy lettering on a clearly visible solid pale blue square. No gradient or shading in the lettering, no outlines, no glow, no stars, no ornaments, no texture, no additional text. Crisp flat professional customer-support avatar, generous negative space, full square pale-blue background edge to edge. OPAQUE BACKGROUND REQUIRED, not a transparent cutout.

## 头像接口

使用 Telegram 官方 [setMyProfilePhoto](https://core.telegram.org/bots/api#setmyprofilephoto)，静态头像以 multipart 上传 JPEG。Token 沿用服务器私有配置，不写入素材或 Git。
