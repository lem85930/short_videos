<?php
header('Content-type: application/json');
/**
*@Author: JH-Ahua
*@CreateTime: 2025/6/27 上午12:05
*@email: admin@bugpk.com
*@blog: www.jiuhunwl.cn
*@Api: api.bugpk.com
*@tip: 小红书视频图文聚合解析
*/
header("Access-Control-Allow-Origin: *");
// 获取请求参数
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $fullUrl = $_SERVER['REQUEST_URI'];
    // 查找url参数的位置
    $urlParamPos = strpos($fullUrl, 'url=');
    if ($urlParamPos !== false) {
        // 提取url参数后面的所有内容
        $encodedUrl = substr($fullUrl, $urlParamPos + 4);

        // 解码URL
        $url = urldecode($encodedUrl) ?? null;
    }
} else {
    $url = $_POST['url'] ?? null;
}

// 检查必要参数
if (empty($url)) {
    header('Content-Type: application/json');
    echo json_encode(['error' => '必须提供url参数', 'Auther' => 'BugPk', 'website' => 'https://api.bugpk.com/'], 480);
    return;
} else {
    $domain = parse_url($url);
    if ($domain['host'] == "xhs.com") {
        $parts = explode('/', $url);
        $url = 'http://xhslink.com/a/' . $parts[4];
    }
    echo xhs($url);
}
function xhs($url)
{
    $header = [
        'User-Agent: Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1 Edg/122.0.0.0',
    ];
    $cookie = "xhsTrackerId=e6018ab9-6936-4b02-cb65-a7f9f9e22ea0; xhsuid=y2PCwPFU9GCQnJH8; timestamp2=20210607d2293bcc8dcad65834920376; timestamp2.sig=QFn2Zv9pjUr07KDlnh886Yq43bZxOaT6t3WCzZdzcgM; xhsTracker=url=noteDetail&xhsshare=CopyLink; extra_exp_ids=gif_exp1,ques_exp2";
    $domain = parse_url($url);
    if ($domain['host'] == "www.xiaohongshu.com") {
        $id = extractId($url);
    } else {
        $url = get_headers($url, 1)["Location"] ?? $url;
        if (is_array($url)){
            $url = $url[0];
        }
        $id = extractId($url);
    }
    // 发送请求获取视频信息
    $response = get_curl($url, $cookie);
    if (!$response) {
        return output(400, '请求失败');
    }
    // 优化正则表达式
    $pattern = '/<script>\s*window.__INITIAL_STATE__\s*=\s*({[\s\S]*?})<\/script>/is';
    if (preg_match($pattern, $response, $matches)) {
        $jsonData = $matches[1];
        // 将 undefined 替换为 null
        $jsonData = str_replace('undefined', 'null', $jsonData);
        $decoded = json_decode($jsonData, true);
        if ($decoded) {
            // 安全获取视频URL
            $videoH264Url = safeGet($decoded, ['note', 'noteDetailMap', $id, 'note', 'video', 'media', 'stream', 'h264', 0, 'backupUrls', 0]);
            $videoH265Url = safeGet($decoded, ['noteData', 'data', 'noteData', 'video', 'media', 'stream', 'h265', 0, 'masterUrl']);
            $videourl = $videoH265Url ?: $videoH264Url;

            // 获取图片数据（作为备用数据源）
            $imageData = safeGet($decoded, ['note', 'noteDetailMap', $id, 'note']);

            // 获取作者信息
            $author = safeGet($decoded, ['noteData', 'data', 'noteData', 'user', 'nickName']);
            $author = $author ?: safeGet($imageData, ['user', 'nickname'], '');

            $authorID = safeGet($decoded, ['noteData', 'data', 'noteData', 'user', 'userId']);
            $authorID = $authorID ?: safeGet($imageData, ['user', 'userId'], '');

            // 获取标题和描述
            $title = safeGet($decoded, ['noteData', 'data', 'noteData', 'title']);
            $title = $title ?: safeGet($imageData, ['title'], '');

            $desc = safeGet($decoded, ['noteData', 'data', 'noteData', 'desc']);
            $desc = $desc ?: safeGet($imageData, ['desc']);
            $desc = $desc ?: safeGet($decoded, ['note', 'noteDetailMap', $id, 'note'], '');

            // 获取头像和封面
            $avatar = safeGet($decoded, ['noteData', 'data', 'noteData', 'user', 'avatar']);
            $avatar = $avatar ?: safeGet($imageData, ['user', 'avatar'], '');

            $cover = safeGet($decoded, ['noteData', 'data', 'noteData', 'imageList', 0, 'url']);
            $cover = $cover ?: safeGet($decoded, ['note', 'noteDetailMap', $id, 'note', 'imageList', 0, 'urlDefault'], '');
            if (!empty($videourl)) {
                $data = [
                    'author' => $author,
                    'authorID' => $authorID,
                    'title' => $title,
                    'desc' => $desc,
                    'avatar' => $avatar,
                    'cover' => $cover,
                    'url' => $videourl
                ];
                return output(200, '解析成功', $data);
            } elseif (!empty($imageData)) {
                $imgurl = [];
                foreach ($imageData['imageList'] as $item) {
                    // 检查当前元素是否包含 url_list 标签
                    if (isset($item['urlDefault'])) {
                        // 将 url_list 的第一个值添加到 $imgurl 数组中
                        $imgurl[] = $item['urlDefault'];
                    }
                }
                $data = [
                    'author' => $author,
                    'authorID' => $authorID,
                    'title' => $title,
                    'desc' => $desc,
                    'avatar' => $avatar,
                    'cover' => $cover,
                    'imgurl' => $imgurl
                ];
                return output(200, '解析成功', $data);
            } else {
                return output(404, '解析失败，未获取到视频链接');
            }
        } else {
            return output(400, '匹配到的内容不是有效的 JSON 数据');
        }
    } else {
        return output(400, '匹配json数据失败');
    }
}

function extractId($url)
{
    // 定义多个正则表达式模式以匹配不同格式的URL
    $patterns = [
        '/discovery\/item\/([a-zA-Z0-9]+)/',     // 原始模式
        '/explore\/([a-zA-Z0-9]+)/',             // 匹配探索页面链接
        '/item\/([a-zA-Z0-9]+)/',                // 匹配项目详情链接
        '/note\/([a-zA-Z0-9]+)/',                // 匹配笔记链接
    ];

    // 依次尝试每个模式
    $id = null;
    foreach ($patterns as $pattern) {
        preg_match($pattern, $url, $matches);
        if (!empty($matches[1])) {
            $id = $matches[1];
            break;
        }
    }

    return $id;
}

function get_curl($url, $cookie)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_COOKIE, $cookie);
    curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/89.0.4389.128 Safari/537.36");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_ENCODING, 'gzip,deflate');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $output = curl_exec($ch);
    curl_close($ch);
    return $output;
}
function safeGet(array $array, array $keys, $default = null) {
    $current = $array;
    foreach ($keys as $key) {
        if (!isset($current[$key])) {
            return $default;
        }
        $current = $current[$key];
    }
    return $current;
}
// 定义统一的输出函数
function output($code, $msg, $data = [])
{
    return json_encode([
        'code' => $code,
        'msg' => $msg,
        'data' => $data
    ], 480);
}
