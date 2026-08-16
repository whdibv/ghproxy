# GitHub 文件加速代理

PHP 单文件的 GitHub 文件下载加速代理，部署在虚拟主机上（`ghproxy.wldwz.icu`）。

## 用法

在 GitHub 链接前加上代理域名即可：

```
原始：https://github.com/user/repo/releases/download/v1.0/app.zip
加速：https://ghproxy.wldwz.icu/https://github.com/user/repo/releases/download/v1.0/app.zip
```

也支持无 scheme 写法：

```
https://ghproxy.wldwz.icu/raw.githubusercontent.com/user/repo/main/file.txt
```

## 特性

- 单文件 PHP，虚拟主机即可部署（无需 Go/Node）
- 本地文件缓存，二次访问秒回
- 流式下载，大文件不占内存
- 自动识别 MIME 类型，正确 Content-Disposition
- 白名单域名，防 SSRF

## 支持域名

`github.com` / `raw.githubusercontent.com` / `gist.githubusercontent.com` / `codeload.github.com` / `objects.githubusercontent.com` / `api.github.com` 等。

## 部署

1. 上传 `index.php` 到站点目录
2. 确保 `cache/` 目录可写（首次运行自动创建）
3. 绑定域名即可

## 缓存

缓存文件存于 `cache/` 目录，按 URL 的 MD5 命名。如需清理，删除 `cache/` 下文件即可。
