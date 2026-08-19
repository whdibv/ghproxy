<?php
/**
 * GitHub 文件加速代理
 * 用法：https://ghproxy.wldwz.icu/https://github.com/user/repo/releases/download/v1/file.zip
 *      https://ghproxy.wldwz.icu/raw.githubusercontent.com/user/repo/main/file.txt
 */

define('CACHE_DIR', __DIR__ . '/cache/');
define('CACHE_TTL_DEFAULT', 24 * 3600);     // 普通路径缓存 1 天
define('CACHE_TTL_MAX', 30 * 24 * 3600);    // 最长缓存（自动清理阈值）
define('MAX_CACHE_SIZE', 50 * 1024 * 1024); // 超过 50MB 不缓存，直接流式转发
define('BIG_FILE_THRESHOLD', 200 * 1024 * 1024); // 超过 200MB 不转发，展示第三方加速节点
if (!is_dir(CACHE_DIR)) {
    @mkdir(CACHE_DIR, 0777, true);
}

// 第三方加速节点（大文件分流用：虚拟主机带宽有限，超大文件交给公共 CDN 节点）
$THIRD_PARTY_NODES = [
    ['name' => 'Cloudflare 优选', 'host' => 'https://gh-proxy.org',   'note' => '全球高速分发，国内优选 + IPv6 支持'],
    ['name' => 'Cloudflare v4（推荐）', 'host' => 'https://v4.gh-proxy.org', 'note' => '优选加速，仅 IPv4，智能解析'],
    ['name' => 'Cloudflare v4/v6', 'host' => 'https://v6.gh-proxy.org', 'note' => '优选加速，支持 IPv6 / IPv4'],
    ['name' => 'Fastly CDN',       'host' => 'https://cdn.gh-proxy.org', 'note' => 'Fastly CDN 节点加速（v4）'],
    ['name' => 'AxisNow 三网优选', 'host' => 'https://axisnow.gh-proxy.org', 'note' => '三网优选节点，仅 IPv4'],
];

// 根据 URL 路径智能选择缓存时长（参考 cf-ghproxy-worker 设计）
function cache_ttl($url) {
    $path = parse_url($url, PHP_URL_PATH);
    if (!$path) return CACHE_TTL_DEFAULT;
    // 动态内容（分支名/最新版）：短缓存，保证拿到最新
    if (preg_match('#/(latest|main|master|nightly|dev|canary|head)(/|\.|$)#i', $path)) {
        return 3600; // 1 小时
    }
    // 固定版本（release/标签）：长缓存
    if (preg_match('#/(releases/download|archive/refs/tags|tags)/#i', $path)) {
        return CACHE_TTL_MAX; // 30 天
    }
    return CACHE_TTL_DEFAULT;
}

// 获取目标 URL
$uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';
$path = ltrim($uri, '/');

// 无目标 → 显示使用说明首页
if ($path === '' || $path === 'index.php') {
    show_index();
    exit;
}

// 解析目标 URL（容错处理多种写法）
$target = $path;
if (preg_match('#^https?:/{2,}#', $target)) {
    // https://xxx 或 http:///xxx（斜杠被合并）
    $target = preg_replace('#^https?:/{2,}#', 'https://', $target);
} elseif (preg_match('#^[a-z0-9.-]+\.(com|io|org|net)/#', $target) && !preg_match('#^https?://#', $target)) {
    // github.com/xxx 形式（无 scheme）
    $target = 'https://' . $target;
} elseif (!preg_match('#^https?://#', $target)) {
    show_index();
    exit;
}

// 白名单域名（防止 SSRF）
$allowed_hosts = [
    'github.com',
    'raw.githubusercontent.com',
    'gist.githubusercontent.com',
    'objects.githubusercontent.com',
    'github.githubassets.com',
    'codeload.github.com',
    'api.github.com',
    'release-assets.githubusercontent.com',
];

$host = parse_url($target, PHP_URL_HOST);
if (!$host || !in_array($host, $allowed_hosts)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Forbidden: 仅支持 GitHub 相关域名');
}

// 缓存文件
$cache_file = CACHE_DIR . md5($target);

// 缓存命中（且未过期），直接返回
$ttl = cache_ttl($target);
if (is_file($cache_file) && filesize($cache_file) > 0 && (time() - filemtime($cache_file)) < $ttl) {
    header('X-Cache-Status: HIT');
    header('X-Cache-TTL: ' . $ttl);
    serve_file($cache_file, $target);
    exit;
}
header('X-Cache-Status: MISS');

// 大文件：探测大小，超过阈值则流式转发（不缓存，避免撑爆磁盘）
$remote_size = remote_size($target);
if ($remote_size !== false && $remote_size > MAX_CACHE_SIZE) {
    // 超大文件（>200MB）：本机带宽有限，展示第三方加速节点选择页
    if ($remote_size > BIG_FILE_THRESHOLD) {
        header('X-Cache-Status: BIG-REDIRECT');
        header('X-Cache-TTL: 0');
        show_third_party($target, $remote_size);
        exit;
    }
    header('X-Cache-Status: BYPASS');
    header('X-Cache-TTL: 0');
    stream_forward($target);
    exit;
}

// 概率性清理过期缓存（约 1% 请求触发，避免缓存目录无限膨胀）
if (mt_rand(1, 100) === 1) {
    clean_expired_cache();
}

// 下载到缓存
$fp = @fopen($cache_file, 'wb');
if (!$fp) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('无法写入缓存目录，请检查权限');
}

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $target,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 10,
    CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_TIMEOUT => 0,
    CURLOPT_FILE => $fp,
    CURLOPT_HEADER => false,
]);
$ok = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_err = curl_error($ch);
curl_close($ch);
fclose($fp);

// 失败处理
if (!$ok || $http_code >= 400) {
    @unlink($cache_file);
    http_response_code($http_code >= 100 ? $http_code : 502);
    header('Content-Type: text/plain; charset=utf-8');
    exit('下载失败：' . ($curl_err ? $curl_err : ('HTTP ' . $http_code)));
}

serve_file($cache_file, $target);
exit;

// 探测远程文件大小（Range 请求，对 release 重定向更可靠）
function remote_size($url) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_RANGE => '0-0', // 只请求第一个字节
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code >= 200 && $code < 300) {
        // 优先从 Content-Range 拿总大小（bytes 0-0/TOTAL）
        if (preg_match('/Content-Range:\s*bytes\s+0-0\/(\d+)/i', $resp, $m)) {
            return (int)$m[1];
        }
        // 退而求其次用 Content-Length
        if (preg_match('/Content-Length:\s*(\d+)/i', $resp, $m)) {
            return (int)$m[1];
        }
    }
    return false;
}

// 大文件流式转发（边下边传，不落盘）
function stream_forward($url) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_HEADERFUNCTION => function($ch, $header) {
            $len = strlen($header);
            $h = trim($header);
            if (stripos($h, 'content-type:') === 0 ||
                stripos($h, 'content-length:') === 0 ||
                stripos($h, 'content-disposition:') === 0 ||
                stripos($h, 'content-range:') === 0 ||
                stripos($h, 'accept-ranges:') === 0) {
                header($h);
            }
            return $len;
        },
        CURLOPT_WRITEFUNCTION => function($ch, $data) {
            echo $data;
            if (ob_get_level() > 0) { ob_flush(); }
            flush();
            return strlen($data);
        },
    ]);
    set_time_limit(0);
    curl_exec($ch);
    curl_close($ch);
}

// 清理过期缓存文件（只清理超过最长 TTL 的，保守避免误删）
function clean_expired_cache() {
    $files = glob(CACHE_DIR . '*');
    if (!$files) return;
    $now = time();
    foreach ($files as $f) {
        if (is_file($f) && ($now - filemtime($f)) > CACHE_TTL_MAX) {
            @unlink($f);
        }
    }
}

// 输出文件
function serve_file($file, $url) {
    $size = filesize($file);
    $name = basename(parse_url($url, PHP_URL_PATH));
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $mime = mime_type($ext);
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . $size);
    header('Accept-Ranges: bytes');
    // 可下载文件类型用 attachment，其余 inline
    $inline = ['jpg','jpeg','png','gif','svg','webp','ico','txt','md','js','css','json','html','xml','pdf','mp3','mp4','webm'];
    $disposition = in_array($ext, $inline) ? 'inline' : 'attachment';
    header('Content-Disposition: ' . $disposition . '; filename="' . $name . '"');
    readfile($file);
}

// MIME 映射
function mime_type($ext) {
    $map = [
        'zip' => 'application/zip',
        'gz' => 'application/gzip',
        'tgz' => 'application/gzip',
        'rar' => 'application/x-rar-compressed',
        '7z' => 'application/x-7z-compressed',
        'tar' => 'application/x-tar',
        'bz2' => 'application/x-bzip2',
        'xz' => 'application/x-xz',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
        'bmp' => 'image/bmp',
        'txt' => 'text/plain',
        'md' => 'text/markdown',
        'js' => 'application/javascript',
        'css' => 'text/css',
        'json' => 'application/json',
        'html' => 'text/html',
        'htm' => 'text/html',
        'xml' => 'application/xml',
        'pdf' => 'application/pdf',
        'mp3' => 'audio/mpeg',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'ogg' => 'audio/ogg',
        'wav' => 'audio/wav',
        'exe' => 'application/octet-stream',
        'msi' => 'application/octet-stream',
        'apk' => 'application/vnd.android.package-archive',
        'deb' => 'application/x-debian-package',
        'rpm' => 'application/x-rpm',
        'dmg' => 'application/x-apple-diskimage',
        'appimage' => 'application/octet-stream',
        'sh' => 'text/x-shellscript',
        'py' => 'text/x-python',
        'wasm' => 'application/wasm',
    ];
    return isset($map[$ext]) ? $map[$ext] : 'application/octet-stream';
}

// 超大文件：展示第三方加速节点选择页（本机带宽有限，交给公共 CDN 节点分发）
function show_third_party($url, $size) {
    global $THIRD_PARTY_NODES;
    // 格式化大小
    if ($size >= 1073741824) $size_txt = round($size / 1073741824, 2) . ' GB';
    elseif ($size >= 1048576) $size_txt = round($size / 1048576, 1) . ' MB';
    else $size_txt = round($size / 1024, 1) . ' KB';
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>文件过大 · 请选择加速节点</title>
<style>
body{font-family:-apple-system,"Segoe UI",Roboto,"PingFang SC","Microsoft YaHei",sans-serif;max-width:760px;margin:40px auto;padding:0 20px;color:#1f2937;background:#f8fafc;}
h1{font-size:22px;color:#b45309;}
.card{background:#fff;border-radius:12px;padding:22px;box-shadow:0 2px 10px rgba(0,0,0,.05);margin-bottom:14px;}
code{background:#f1f5f9;padding:2px 6px;border-radius:4px;font-size:12.5px;color:#0f766e;word-break:break-all;display:block;margin:8px 0;}
a.node{display:block;padding:12px 14px;border:1px solid #e2e8f0;border-radius:10px;text-decoration:none;color:#1f2937;margin-bottom:10px;transition:.15s;}
a.node:hover{border-color:#0d9488;background:#f0fdfa;}
.node b{color:#0d9488;font-size:14px;}
.node span{display:block;font-size:12px;color:#94a3b8;margin-top:3px;}
.node .btn{margin-top:8px;display:inline-block;padding:4px 14px;background:#0d9488;color:#fff;border-radius:6px;font-size:12px;}
.origin{font-size:12px;color:#64748b;}
.notice{font-size:12.5px;color:#b45309;background:#fef3c7;border-radius:8px;padding:10px 14px;margin-bottom:14px;line-height:1.7;}
.back{display:inline-block;margin-top:6px;color:#0d9488;text-decoration:none;font-size:13px;}
</style>
</head>
<body>
<h1>📦 文件过大（' . $size_txt . '）</h1>
<div class="notice">本代理服务器带宽有限（10Mbps），超过 200MB 的文件不提供中转，请直接使用下方 <b>第三方加速节点</b> 下载（Cloudflare / Fastly 等公共 CDN，速度远超本机）。若某个节点失败或太慢，换一个节点即可。</div>
<div class="card">
<div class="origin">原始文件：</div>
<code>' . htmlspecialchars($url) . '</code>';
    foreach ($THIRD_PARTY_NODES as $node) {
        $link = $node['host'] . '/' . $url;
        echo '<a class="node" href="' . htmlspecialchars($link) . '" target="_blank" rel="noopener">
<b>' . htmlspecialchars($node['name']) . '</b>
<span>' . htmlspecialchars($node['note']) . '</span>
<code>' . htmlspecialchars($link) . '</code>
<span class="btn">直接打开 ↗</span>
</a>';
    }
    echo '</div>
<p style="text-align:center;margin:10px 0 30px;"><a class="back" href="/">← 返回首页</a></p>
</body>
</html>';
}

// 使用说明首页
function show_index() {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>GitHub 文件加速代理</title>
<style>
body{font-family:-apple-system,"Segoe UI",Roboto,"PingFang SC","Microsoft YaHei",sans-serif;max-width:760px;margin:60px auto;padding:0 20px;color:#1f2937;background:#f8fafc;}
h1{font-size:24px;color:#0d9488;}
.card{background:#fff;border-radius:12px;padding:24px;box-shadow:0 2px 10px rgba(0,0,0,.05);margin-bottom:16px;}
code{background:#f1f5f9;padding:2px 6px;border-radius:4px;font-size:13px;color:#0f766e;word-break:break-all;}
input{width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:8px;font-size:13px;box-sizing:border-box;}
button{margin-top:10px;padding:10px 20px;background:#0d9488;color:#fff;border:0;border-radius:8px;cursor:pointer;font-size:14px;}
button:hover{background:#0f766e;}
.hint{font-size:13px;color:#64748b;line-height:1.8;}
</style>
</head>
<body>
<h1>GitHub 文件加速代理</h1>
<div class="card">
<div class="hint"><b>用法：</b>在 GitHub 链接前加上本代理域名即可。</div>
<br>
<div class="hint"><b>示例：</b></div>
<div class="hint">原始链接：<br><code>https://github.com/user/repo/releases/download/v1.0/app.zip</code></div>
<br>
<div class="hint">加速链接：<br><code>https://' . $_SERVER['HTTP_HOST'] . '/https://github.com/user/repo/releases/download/v1.0/app.zip</code></div>
<br>
<div class="hint"><b>快速转换：</b></div>
<input type="text" id="src" placeholder="粘贴 GitHub 链接">
<button onclick="convert()">转换</button>
<div class="hint" id="result" style="margin-top:10px;"></div>
</div>
<div class="card">
<div class="hint"><b>支持的域名：</b>github.com / raw.githubusercontent.com / gist / codeload / objects.githubusercontent.com 等</div>
<br>
<div class="hint"><b>说明：</b>文件首次访问会缓存到服务器，之后秒开。50MB 以内自动缓存，50-200MB 流式转发，超过 200MB 提供第三方高速节点下载。</div>
</div>
<div style="text-align:center;margin:24px 0 8px;font-size:13px;">
    <a href="https://wldwz.icu" style="color:#0d9488;text-decoration:none;margin:0 12px;">🏠 主站 wldwz.icu</a>
    <a href="https://blog.wldwz.icu" style="color:#0d9488;text-decoration:none;margin:0 12px;">🐟 鱼鱼 Blog</a>
</div>
<script>
function convert(){
    var v = document.getElementById("src").value.trim();
    if(!v) return;
    if(!/^https?:\/\//.test(v)) v = "https://" + v;
    document.getElementById("result").innerHTML = "加速链接：<br><code>" + location.origin + "/" + v + "</code>";
}
</script>
</body>
</html>';
}
