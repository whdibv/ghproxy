<p align="center">
  <h1 align="center">ghproxy</h1>
  <p align="center">一个单文件的 GitHub 文件加速代理，部署在虚拟主机上即可使用。</p>
</p>

## 这是什么？

国内访问 GitHub 的 release、raw 文件、仓库 tarball 经常很慢甚至超时。这个项目是一个 **PHP 单文件代理**，部署在你的服务器（虚拟主机即可）上，把 GitHub 文件请求转发并缓存，实现加速下载。

- **零依赖**：单文件 PHP，不需要 Go / Node / 数据库
- **虚拟主机可跑**：只要支持 PHP + curl 就能部署
- **本地缓存**：文件首次下载后缓存到服务器，之后秒回

## 特性

- 🚀 单文件，即传即用
- ⚡ 本地文件缓存，二次访问秒开
- 📦 流式下载，大文件不占内存
- 🎯 自动识别 MIME 类型，正确的 Content-Disposition
- 🛡 白名单域名，防止 SSRF
- 🌐 支持跨域，浏览器可直接调用
- 📱 自带使用说明首页 + 在线转换工具

## 快速使用

假设你的代理部署在 `https://ghproxy.example.com`：

**完整链接写法**（在 GitHub 链接前加代理域名）：

```
原始：https://github.com/user/repo/releases/download/v1.0/app.zip
加速：https://ghproxy.example.com/https://github.com/user/repo/releases/download/v1.0/app.zip
```

**省略 scheme 写法**：

```
https://ghproxy.example.com/raw.githubusercontent.com/user/repo/main/file.txt
```

**仓库 tarball 下载**：

```
https://ghproxy.example.com/https://codeload.github.com/user/repo/tar.gz/refs/heads/main
```

## 支持域名

| 域名 | 说明 |
|---|---|
| `github.com` | 主站、release 下载 |
| `raw.githubusercontent.com` | Raw 文件 |
| `gist.githubusercontent.com` | Gist |
| `codeload.github.com` | 仓库 tarball/zip |
| `objects.githubusercontent.com` | 大文件 / LFS |
| `github.githubassets.com` | 静态资源 |
| `api.github.com` | API |

其他域名一律拒绝（403），避免被用作任意 URL 代理。

## 部署

### 方式一：虚拟主机（推荐）

1. 下载 `index.php`，上传到站点目录
2. 访问 `http://你的域名/`，看到使用说明页即部署成功
3. `cache/` 目录会在首次下载时自动创建，无需手动处理

> 注意：需要 PHP 开启 `curl` 扩展（绝大多数虚拟主机默认开启）。

### 方式二：宝塔面板

1. 新建站点，绑定域名
2. 上传 `index.php` 到站点根目录
3. 设置伪静态（Nginx）：

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

4. （可选）申请 SSL 证书，开启 HTTPS

### 方式三：本地 PHP 内置服务器（测试用）

```bash
php -S 0.0.0.0:8080 index.php
```

访问 `http://localhost:8080/` 测试。

## 缓存机制

- 缓存文件存放于 `cache/` 目录，按目标 URL 的 MD5 命名
- 首次访问：从 GitHub 下载 → 写入缓存 → 返回
- 再次访问：直接读取缓存，不请求 GitHub
- 清理缓存：删除 `cache/` 目录下文件即可

```bash
rm -rf cache/*
```

## 常见问题

**Q：支持哪些文件类型？**
A：所有类型。会根据扩展名返回正确的 Content-Type，未知类型默认 `application/octet-stream`。

**Q：大文件会占内存吗？**
A：不会。使用 curl 流式写入，不把整个文件读进内存。

**Q：能代理非 GitHub 的链接吗？**
A：不能。只允许白名单内的 GitHub 域名，这是为了安全（防止被当作通用代理滥用）。

**Q：缓存多久过期？**
A：默认永久缓存。如需更新，手动删除对应缓存文件即可。

## 安全说明

- 白名单限制域名，仅可代理 GitHub 相关域名
- 代码中无任何硬编码凭证
- 建议部署后开启 HTTPS（免费证书可用 Let's Encrypt）

## License

[MIT](LICENSE) — 自由使用、修改、分发。

## 致谢

灵感来自各类 GitHub 加速代理项目（gh-proxy 等），本项目提供一个**免编译、虚拟主机友好**的 PHP 实现。
