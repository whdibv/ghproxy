<?php
/**
 * 第三方加速节点配置模板
 *
 * 使用：复制本文件为 nodes.php（已加入 .gitignore，不会提交），
 *       填入你自己的加速节点即可。不配置则超大文件（>200MB）回退为流式转发。
 *
 * 格式：数组，每个节点包含 name（显示名）/ host（代理前缀）/ note（说明文字）
 * 节点链接 = host . '/' . 原始 GitHub 链接
 */
return [
    // ['name' => '我的节点 1', 'host' => 'https://example-proxy.com', 'note' => '示例节点，替换成你自己的'],
];
