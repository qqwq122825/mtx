# 满天星客服 Telegram 头像

- 目标：`@iosmtx_bot`（满天星客服）的头像，不修改账号、自动回复或 Webhook。
- `mtx-telegram-avatar-20260912.png`：内置 ImageGen 生成的原图，1254 × 1254。
- `mtx-telegram-avatar-20260912.jpg`：用于 Telegram 官方 `setMyProfilePhoto` 的 JPEG 格式副本；仅格式转换，未改动构图。
- 使用内置 ImageGen，未使用 CLI / API 图像生成。

## 最终生成提示词

Use case: logo-brand. Asset type: Telegram customer-service bot profile avatar, a finished square bitmap, 1024x1024. Create one polished, distinctive brand avatar for 满天星 (MTX). Deep midnight-blue subtly luminous starry background with a few restrained cyan and violet star accents, sophisticated, welcoming, not busy. Dominant centered large bold custom geometric typography "MTX", crisp white with a very subtle ice-blue highlight; beneath it, exact Chinese text "满天星" in large clean heavy Chinese sans-serif. The three Chinese characters must be correct and highly legible, not decorative pseudo-writing. One tasteful bright four-point star integrated just above the typography. Flat frontal high-contrast logo composition, no photorealistic mockup, no 3D bevel, no tiny details. Text (verbatim): "MTX" and "满天星" only. Keep the entire lettering and star within the central 70% width and 65% height so all survive a circular Telegram crop. Full-bleed square dark background, no external border, no watermark, no additional slogans, no username, no QR code. Designed to remain recognizable at 64 pixels.

## 设置方式

通过 Telegram 官方 [setMyProfilePhoto](https://core.telegram.org/bots/api#setmyprofilephoto) 设置，静态头像使用 JPEG 和 multipart 上传。Token 仅在服务器内读取现有私有配置并提交至 Telegram 官方接口，不写入素材、Git 或公开地址。
