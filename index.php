<?php
/**
 * SimpleHashImg Pro V36 - 逻辑完美闭环 & 隐私元数据(EXIF/GPS)自动擦除 & 全网跨域防截断图床版
 */

error_reporting(0);
$upload_dir = 'uploads';
$data_dir   = 'data';

// 1. 精准计算绝对根 URL
$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
$host     = $_SERVER['HTTP_HOST'];
$script   = $_SERVER['SCRIPT_NAME'];
$dir      = rtrim(dirname($script), '/\\');
$base_url = $protocol . "://" . $host . ($dir === '' ? '' : $dir) . "/";

// 2. .htaccess 检查与注入
function autoInjectRootHtaccess() {
    $htPath = __DIR__ . '/.htaccess';
    $content = file_exists($htPath) ? @file_get_contents($htPath) : '';
    if ($content === false) $content = '';

    $updated = false;

    if (stripos($content, 'Options -Indexes') === false) {
        $content = "Options -Indexes\n" . $content;
        $updated = true;
    }

    if (stripos($content, 'image/svg+xml') === false) {
        $content = "AddType image/svg+xml .svg .svgz\nAddType image/webp .webp\n" . $content;
        $updated = true;
    }

    $marker = "# --- SimpleHashImg Rules ---";
    if (strpos($content, $marker) === false) {
        $rulesBlock = "\n" . $marker . "\n" .
        "<IfModule mod_rewrite.c>\n" .
        "    RewriteEngine On\n" .
        "    RewriteRule ^src/([a-f0-9]{32})\\..*$ index.php?v=$1 [L,QSA]\n" .
        "    RewriteRule ^delete/([a-f0-9]{32})/([a-f0-9]{32})$ index.php?action=delete&hash=$1&token=$2 [L,QSA]\n" .
        "    RewriteRule ^delete-batch/([^/]+)/([a-f0-9]{32})$ index.php?action=delete_batch&sess=$1&token=$2 [L,QSA]\n" .
        "</IfModule>\n" .
        "# --- SimpleHashImg Rules End ---\n";

        $content .= $rulesBlock;
        $updated = true;
    }

    if ($updated) {
        @file_put_contents($htPath, trim($content) . "\n");
    }
}
autoInjectRootHtaccess(); 

// 3. 垃圾回收 GC
function cleanOldTempDirs($data_dir) {
    $absDataDir = __DIR__ . '/' . trim($data_dir, '/');
    $dirs = glob($absDataDir . '/tmp_*');
    $now = time();
    if (is_array($dirs)) {
        foreach ($dirs as $dir) {
            if (is_dir($dir) && ($now - filemtime($dir)) > 7200) { 
                $files = glob("$dir/*");
                if (is_array($files)) {
                    foreach ($files as $file) {
                        if (is_file($file)) @unlink($file);
                    }
                }
                @rmdir($dir);
            }
        }
    }
}
if (rand(1, 10) === 1) {
    cleanOldTempDirs($data_dir);
}

// 初始化目录与内部安全保护
if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
if (!is_dir($data_dir)) mkdir($data_dir, 0755, true);
if (!file_exists($data_dir . '/.htaccess')) file_put_contents($data_dir . '/.htaccess', "Deny from all\n");
if (!file_exists($upload_dir . '/.htaccess')) file_put_contents($upload_dir . '/.htaccess', "Options -Indexes\nphp_flag engine off\nSetHandler default-handler\n");

// 自动生成安全密钥
$salt_file = $data_dir . '/secret.php';
if (!file_exists($salt_file)) {
    $rk = bin2hex(openssl_random_pseudo_bytes(16));
    file_put_contents($salt_file, "<?php exit; ?>\n" . $rk);
    $salt = $rk;
} else {
    $salt = trim(explode("\n", file_get_contents($salt_file))[1]);
}

// 擦除图片 EXIF / GPS 坐标 / 设备元数据函数
function stripImageMetadata($filePath, $ext) {
    $ext = strtolower($ext);
    if (!file_exists($filePath)) return;

    if (in_array($ext, ['jpg', 'jpeg'])) {
        $data = @file_get_contents($filePath);
        if ($data === false || strlen($data) < 4) return;
        
        if (ord($data[0]) === 0xFF && ord($data[1]) === 0xD8) {
            $newData = "\xFF\xD8";
            $len = strlen($data);
            $i = 2;
            while ($i < $len) {
                if (ord($data[$i]) === 0xFF) {
                    $marker = ord($data[$i+1]);
                    if ($marker === 0xD9) {
                        $newData .= "\xFF\xD9";
                        break;
                    }
                    if ($marker === 0xD8 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                        $newData .= $data[$i] . $data[$i+1];
                        $i += 2;
                        continue;
                    }
                    if ($i + 3 < $len) {
                        $segLen = (ord($data[$i+2]) << 8) + ord($data[$i+3]);
                        if ($marker === 0xE1 || $marker === 0xED) {
                            $i += 2 + $segLen;
                            continue;
                        } else {
                            $newData .= substr($data, $i, 2 + $segLen);
                            $i += 2 + $segLen;
                            continue;
                        }
                    }
                }
                $newData .= $data[$i];
                $i++;
            }
            @file_put_contents($filePath, $newData);
            return;
        }
    }

    if (extension_loaded('gd') && in_array($ext, ['png', 'webp', 'bmp'])) {
        $img = @imagecreatefromstring(@file_get_contents($filePath));
        if ($img !== false) {
            if ($ext === 'png') {
                imagealphablending($img, false);
                imagesavealpha($img, true);
                @imagepng($img, $filePath, 6);
            } elseif ($ext === 'webp') {
                imagealphablending($img, false);
                imagesavealpha($img, true);
                @imagewebp($img, $filePath, 90);
            } elseif ($ext === 'bmp') {
                @imagebmp($img, $filePath);
            }
            imagedestroy($img);
        }
    }
}

// 精准统计服务器已托管图片总数
function getHostedCount($data_dir) {
    $count = 0;
    $absDataDir = __DIR__ . '/' . trim($data_dir, '/');
    $files = glob($absDataDir . '/idx_*');
    if (is_array($files)) {
        foreach ($files as $f) {
            $json = json_decode(@file_get_contents($f), true);
            if (is_array($json)) $count += count($json);
        }
    }
    return $count;
}
$total_hosted = getHostedCount($data_dir);

// 统一反馈页面模板
function showMsgPage($title, $msg, $is_error = false) {
    global $base_url;
    $color = $is_error ? "#f43f5e" : "#10b981";
    echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta http-equiv="refresh" content="3;url='.$base_url.'"><title>'.$title.'</title><style>
    *{box-sizing:border-box} body{font-family:system-ui,-apple-system,sans-serif;background:#f8fafc;display:flex;justify-content:center;align-items:center;min-height:100vh;margin:0;padding:20px;color:#334155}
    .card{background:white;padding:40px 30px;border-radius:24px;box-shadow:0 20px 40px rgba(0,0,0,0.06);text-align:center;width:100%;max-width:420px;border:1px solid #e2e8f0}
    .icon{font-size:60px;line-height:1;margin-bottom:15px;color:'.$color.'} h2{margin:0 0 10px 0;font-size:22px;color:#1e293b} p{font-size:14px;color:#64748b;margin:10px 0 20px;line-height:1.5}
    #count{font-weight:bold;color:'.$color.'} 
    .btn{display:block;width:100%;padding:12px;background:#6366f1;color:white;text-decoration:none;border-radius:12px;font-weight:bold;font-size:14px;transition:0.2s;text-align:center} 
    .btn:hover{opacity:0.9}
    </style></head><body><div class="card"><div class="icon">'.($is_error?"✕":"✓").'</div><h2>'.$title.'</h2><p>'.$msg.'</p><p><span id="count">3</span> 秒后自动返回首页...</p>
    <a href="'.$base_url.'" class="btn">立即返回首页</a></div>
    <script>
    let c = 3;
    let timer = setInterval(()=>{
        c--;
        if(c <= 0){
            clearInterval(timer);
            window.location.href = "'.$base_url.'";
        } else {
            document.getElementById("count").innerText = c;
        }
    }, 1000);
    </script></body></html>';
    exit;
}

// 图片预览/直链输出（支持 HTTP Range 断点续传 + 全网 CORS + 304 缓存 + SVG 防 XSS 沙箱）
if (isset($_GET['v'])) {
    $h = preg_replace('/\.[^.]+$/', '', $_GET['v']);
    $idxP = "$data_dir/idx_" . substr($h, 0, 2);
    if (file_exists($idxP)) {
        $idx = json_decode(file_get_contents($idxP), true);
        if (isset($idx[$h])) {
            $ext = strtolower($idx[$h]['e'] ?? 'jpg');
            $path = $idx[$h]['p'];
            if (file_exists($path)) {

                $fileSize = filesize($path);
                $mtime = filemtime($path);
                $etag = sprintf('"%x-%x"', $mtime, $fileSize);

                // 1. 彻底清空输出缓冲区，解除内存与超时限制
                @set_time_limit(0);
                @ini_set('memory_limit', '512M');
                while (ob_get_level() > 0) { @ob_end_clean(); }

                // 2. HTTP 304 协商缓存 + 全局 CORS 跨域（确保全网外部网站 <img> 跨域引用）
                header("Last-Modified: " . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
                header("ETag: " . $etag);
                header("Accept-Ranges: bytes"); // 宣告支持图片分片/断点续传
                header("Access-Control-Allow-Origin: *"); // 全网外链引用支持
                header("Cache-Control: public, max-age=86400");
                header("X-Accel-Buffering: no");

                if ((isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) && strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) >= $mtime) ||
                    (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag)) {
                    header('HTTP/1.1 304 Not Modified');
                    exit;
                }

                $mimes = [
                    'jpg'  => 'image/jpeg',
                    'jpeg' => 'image/jpeg',
                    'png'  => 'image/png',
                    'gif'  => 'image/gif',
                    'webp' => 'image/webp',
                    'svg'  => 'image/svg+xml',
                    'ico'  => 'image/x-icon',
                    'bmp'  => 'image/bmp'
                ];
                $mime = $mimes[$ext] ?? 'image/jpeg';
                header("Content-Type: " . $mime);

                // 防范 SVG 图片 XSS 跨站脚本攻击沙箱保护
                if ($ext === 'svg') {
                    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
                }

                // 3. 全路径捕捉 FastCGI / CGI 环境下的 HTTP Range 请求头
                $rawRange = $_SERVER['HTTP_RANGE'] ?? $_SERVER['REDIRECT_HTTP_RANGE'] ?? getenv('HTTP_RANGE') ?? getenv('REDIRECT_HTTP_RANGE') ?? '';

                $start = 0;
                $end = $fileSize - 1;

                // 4. 解析 HTTP Range 请求（如 Safari 或大动图分片加载）
                if (!empty($rawRange) && preg_match('/bytes=(\d*)-(\d*)/i', $rawRange, $matches)) {
                    $c_start = $matches[1];
                    $c_end = $matches[2];

                    if ($c_start === '' && $c_end !== '') {
                        $start = $fileSize - intval($c_end);
                        $end = $fileSize - 1;
                    } elseif ($c_start !== '' && $c_end === '') {
                        $start = intval($c_start);
                        $end = $fileSize - 1;
                    } elseif ($c_start !== '' && $c_end !== '') {
                        $start = intval($c_start);
                        $end = intval($c_end);
                    }

                    $start = max(0, min($start, $fileSize - 1));
                    $end = max($start, min($end, $fileSize - 1));
                    $length = $end - $start + 1;

                    http_response_code(206);
                    header('HTTP/1.1 206 Partial Content');
                    header("Content-Range: bytes $start-$end/$fileSize");
                    header("Content-Length: " . $length);

                    $fp = @fopen($path, 'rb');
                    if ($fp !== false) {
                        fseek($fp, $start);
                        $bufferSize = 32768; // 32KB 冲刷缓存，提升图片渐进式加载体验
                        $bytesLeft = $length;
                        while ($bytesLeft > 0 && !feof($fp) && connection_status() == 0) {
                            $readLen = min($bytesLeft, $bufferSize);
                            $data = fread($fp, $readLen);
                            echo $data;
                            @ob_flush();
                            @flush();
                            $bytesLeft -= strlen($data);
                        }
                        fclose($fp);
                        exit;
                    }
                } else {
                    // 无 Range 请求，吐出完整图片数据
                    header("Content-Length: " . $fileSize);
                    $fp = @fopen($path, 'rb');
                    if ($fp !== false) {
                        while (!feof($fp) && connection_status() == 0) {
                            echo fread($fp, 32768); // 32KB
                            @ob_flush();
                            @flush();
                        }
                        fclose($fp);
                    }
                    exit;
                }
            }
        }
    }
    showMsgPage("404", "图片不存在或已被删除", true);
}

// 删除功能
$act = $_GET['action'] ?? '';
if ($act == 'delete_batch' || $act == 'delete') {
    $token = $_GET['token'];
    
    if ($act == 'delete_batch') {
        $sess = $_GET['sess'];
        if ($token !== md5($sess . $salt)) showMsgPage("验证失败", "访问令牌无效或已过期", true);
        
        $sf = "$data_dir/sess_$sess.json";
        if (!file_exists($sf)) {
            showMsgPage("提示", "该批次图片已被清空，无需重复删除", true);
        }

        $list = json_decode(@file_get_contents($sf), true) ?: [];

        $activeCount = 0;
        foreach ($list as $h) {
            $ip = "$data_dir/idx_" . substr($h, 0, 2);
            if (file_exists($ip)) {
                $idxData = json_decode(@file_get_contents($ip), true) ?: [];
                if (isset($idxData[$h])) {
                    $activeCount++;
                }
            }
        }

        if ($activeCount === 0) {
            @unlink($sf);
            showMsgPage("提示", "该批次的所有图片已被单独删除，无需重复处理", true);
        }

        if (!($_GET['confirm'] ?? 0)) {
            echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>确认删除</title><style>*{box-sizing:border-box}body{font-family:system-ui,sans-serif;background:#fff1f2;display:flex;justify-content:center;align-items:center;min-height:100vh;margin:0;padding:20px}.card{background:white;padding:35px 25px;border-radius:24px;box-shadow:0 15px 35px rgba(225,29,72,0.1);text-align:center;width:100%;max-width:400px;border:1px solid #ffe4e6}h2{color:#e11d48;margin:0 0 10px;font-size:20px}p{color:#64748b;font-size:14px;margin-bottom:25px;line-height:1.5}.btn-group{display:flex;gap:10px}.btn{flex:1;padding:12px;border-radius:12px;text-decoration:none;font-weight:bold;font-size:14px;text-align:center}.yes{background:#e11d48;color:white}.no{background:#f1f5f9;color:#475569}</style></head><body><div class="card"><h2>确认删除该批次所有图片？</h2><p>此操作将永久抹除所有对应的文件，且无法恢复。</p><div class="btn-group"><a href="?action=delete_batch&sess='.$sess.'&token='.$token.'&confirm=1" class="btn yes">确认删除</a><a href="'.$base_url.'" class="btn no">取消</a></div></div></body></html>';
            exit;
        }
        
        if (is_array($list)) {
            foreach ($list as $h) {
                $ip = "$data_dir/idx_" . substr($h, 0, 2);
                $idxData = file_exists($ip) ? json_decode(@file_get_contents($ip), true) : [];
                if (isset($idxData[$h])) { 
                    @unlink($idxData[$h]['p']); 
                    unset($idxData[$h]); 
                    file_put_contents($ip, json_encode($idxData)); 
                }
            }
        }
        @unlink($sf);
        showMsgPage("批次已彻底清空", "所选批次的所有图片已从物理磁盘删除");
    }
    
    if ($act == 'delete') {
        $h = $_GET['hash'];
        if ($token === md5($h . $salt)) {
            $ip = "$data_dir/idx_" . substr($h, 0, 2);
            $idxData = file_exists($ip) ? json_decode(file_get_contents($ip), true) : [];
            
            if (isset($idxData[$h])) {
                @unlink($idxData[$h]['p']); 
                unset($idxData[$h]); 
                file_put_contents($ip, json_encode($idxData));
                showMsgPage("删除成功", "该图片已彻底清除");
            } else {
                showMsgPage("404", "该图片已被删除，请勿重复操作", true);
            }
        }
        showMsgPage("删除失败", "安全令牌无效", true);
    }
}

// API 处理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    if ($_GET['action'] === 'abort') {
        $id = $_POST['id'];
        if (preg_match('/^[a-f0-9]+_[a-f0-9]+$/', $id)) {
            $tmp = "$data_dir/tmp_$id";
            if (is_dir($tmp)) {
                $files = glob("$tmp/*");
                if (is_array($files)) {
                    foreach ($files as $file) {
                        if (is_file($file)) @unlink($file);
                    }
                }
                @rmdir($tmp);
            }
        }
        echo json_encode(['status' => 'cleaned']);
        exit;
    }

    if ($_GET['action'] === 'check') {
        $h = $_POST['hash']; $ext = $_POST['ext']; $sid = $_POST['sid'];
        $ip = "$data_dir/idx_" . substr($h, 0, 2); $hit = false; $res = [];
        if (file_exists($ip)) {
            $idxData = json_decode(file_get_contents($ip), true);
            if (isset($idxData[$h])) { 
                // 【手术修复：防死锁】必须校验物理文件确实存在才触发秒传
                if (file_exists($idxData[$h]['p'])) {
                    $hit = true; 
                    $res = [
                        'url' => getL($h, $ext), 
                        'del' => getD($h, $salt),
                        'batch_del' => getBD($sid, $salt)
                    ]; 
                } else {
                    // 物理文件缺失时，自动清空旧坏索引，解锁秒传
                    unset($idxData[$h]);
                    file_put_contents($ip, json_encode($idxData));
                }
            }
        }
        if ($hit) addToSess($h, $sid);
        echo json_encode(['hit' => $hit, 'hosted_count' => getHostedCount($data_dir)] + $res); exit;
    }

    if ($_GET['action'] === 'up_chunk') {
            $id = $_POST['id']; $idx = intval($_POST['idx']); $tmp = "$data_dir/tmp_$id";
            if (!is_dir($tmp)) mkdir($tmp, 0755, true);
            $cFile = "$tmp/$idx";
            
            // 【断点续传优化】：若该分片已完整存在，直接跳过上传，避免免费主机频控限制
            if (file_exists($cFile) && filesize($cFile) > 0 && isset($_FILES['file']) && filesize($cFile) == $_FILES['file']['size']) {
                echo json_encode(['s' => 1, 'skip' => 1]); exit;
            }
            
            move_uploaded_file($_FILES['file']['tmp_name'], $cFile);
            echo json_encode(['s' => 1]); exit;
        }

    if ($_GET['action'] === 'merge') {
        $h = $_POST['hash']; $id = $_POST['id']; $ext = $_POST['ext']; $sid = $_POST['sid'];
        $tmp = "$data_dir/tmp_$id"; $saveP = "$upload_dir/" . date('Y-m-d');
        if (!is_dir($saveP)) mkdir($saveP, 0755, true);
        $final = "$saveP/$h"; 
        
        // 排他写锁：防并发写死与重试冲突
        $dest = fopen($final, "wb");
        if (!$dest || !flock($dest, LOCK_EX)) {
            echo json_encode(['error' => '服务器繁忙，图片合并中']); exit;
        }

        // 【手术修复：利用底层 stream_copy_to_stream 极速拼接分片】
        $chunks = glob("$tmp/*", GLOB_NOSORT); natsort($chunks);
        foreach ($chunks as $c) { 
            $src = fopen($c, "rb"); 
            if ($src) {
                stream_copy_to_stream($src, $dest);
                fclose($src); 
            }
            @unlink($c); 
        }
        flock($dest, LOCK_UN);
        fclose($dest); 
        @rmdir($tmp);
        
        // 【EXIF擦除前精准防腐校验】
        // 必须在擦除 EXIF 之前校验原始字节大小，丢失分片直接强抹扔掉！
        $expectedSize = isset($_POST['size']) ? intval($_POST['size']) : 0;
        if ($expectedSize > 0 && filesize($final) !== $expectedSize) {
            @unlink($final);
            echo json_encode(['error' => '弱网导致图片分片缺失，已自动丢弃坏图，请重新上传']); exit;
        }

        // 文件完整后，安全执行 EXIF / GPS 坐标清洗
        stripImageMetadata($final, $ext);

        $ip = "$data_dir/idx_" . substr($h, 0, 2);
        $idxData = file_exists($ip) ? json_decode(file_get_contents($ip), true) : [];
        $idxData[$h] = ['p' => $final, 'e' => $ext];
        file_put_contents($ip, json_encode($idxData));
        addToSess($h, $sid);
        echo json_encode(['hit' => true, 'url' => getL($h, $ext), 'del' => getD($h, $salt), 'batch_del' => getBD($sid, $salt), 'hosted_count' => getHostedCount($data_dir)]); exit;
    }
}

function addToSess($h, $sid) {
    global $data_dir; $f = "$data_dir/sess_$sid.json";
    $d = file_exists($f) ? json_decode(file_get_contents($f), true) : [];
    if (!in_array($h, $d)) { $d[] = $h; file_put_contents($f, json_encode($d)); }
}
function getL($h, $e) { global $base_url; return $base_url . "src/$h.$e"; }
function getD($h, $s) { global $base_url; return $base_url . "delete/$h/" . md5($h . $s); }
function getBD($sid, $s) { global $base_url; return $base_url . "delete-batch/$sid/" . md5($sid . $s); }
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>SimpleHashImg Pro V36</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <style>
        :root { --main: #6366f1; --accent: #4f46e5; --danger: #f43f5e; --success: #10b981; }
        * { box-sizing: border-box; }
        body { font-family: -apple-system, system-ui, sans-serif; background: #f1f5f9; margin: 0; padding: 0; height: 100vh; display: flex; align-items: center; justify-content: center; color: #1e293b; }
        
        .app { background: white; width: 100%; max-width: 960px; height: 100vh; display: flex; flex-direction: column; overflow: hidden; position: relative; }
        @media (min-width: 768px) { .app { height: 92vh; border-radius: 28px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.08); border: 1px solid #e2e8f0; } }

        .app-header { padding: 16px 24px; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center; flex-shrink: 0; background: #fff; z-index: 10; gap: 15px; }
        .logo { font-size: 1.25rem; font-weight: 900; letter-spacing: -0.5px; white-space: nowrap; }
        .logo span { color: var(--main); }
        
        .meta-stats { display: flex; align-items: center; gap: 10px; font-size: 0.8rem; color: #64748b; font-weight: 600; flex-wrap: wrap; justify-content: flex-end; }
        .stat-item { display: none; align-items: center; gap: 4px; }
        .badge { background: #f1f5f9; padding: 4px 10px; border-radius: 12px; font-weight: 700; color: #475569; display: inline-flex; align-items: center; }
        .badge-wait { color: var(--main); background: #eef2ff; }
        .badge-success { color: var(--success); background: #ecfdf5; }

        @media (max-width: 640px) {
            .app-header { padding: 12px 16px; flex-direction: column; align-items: stretch; gap: 8px; }
            .meta-stats { 
                justify-content: flex-end; 
                width: calc(100% + 32px); 
                margin-left: -16px;
                margin-right: -16px;
                padding-left: 16px;
                padding-right: 16px;
                border-top: 1px solid #f1f5f9; 
                padding-top: 8px; 
            }
        }

        .app-body { flex: 1; display: flex; flex-direction: column; overflow: hidden; position: relative; }

        .upload-card { padding: 16px 20px; border-bottom: 1px solid #e2e8f0; background: #fff; flex-shrink: 0; z-index: 5; transition: all 0.3s ease; }
        .app-body.empty .upload-card { flex: 1; display: flex; flex-direction: column; justify-content: center; border-bottom: none; background: transparent; }
        .drop-zone { 
            border: 2px dashed #cbd5e1; border-radius: 20px; padding: 46px 20px; min-height: 170px; text-align: center; color: #94a3b8; 
            cursor: pointer; transition: all 0.2s ease; background: #fafafa; display: flex; flex-direction: column; 
            align-items: center; justify-content: center; gap: 10px; width: 100%;
        }
        .app-body.empty .drop-zone { flex: 1; min-height: 240px; padding: 50px 20px; background: #fff; }
        .drop-zone:hover, .drop-zone.active { border-color: var(--main); background: #f5f7ff; color: var(--main); }
        .drop-icon { font-size: 32px; line-height: 1; }

        .controls-toolbar { display: flex; justify-content: space-between; align-items: center; margin-top: 14px; gap: 12px; flex-wrap: wrap; }
        .left-controls { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
        #start-btn { margin-left: auto; }

        .toggle-label { display: flex; align-items: center; gap: 6px; font-size: 0.82rem; font-weight: 600; color: #475569; cursor: pointer; user-select: none; }
        .toggle-label input { width: 16px; height: 16px; accent-color: var(--main); cursor: pointer; }

        .list-section { flex: 1; overflow-y: auto; padding: 16px 20px 20px; display: none; background: #f8fafc; }
        .app-body:not(.empty) .list-section { display: block; }
        .list-section::-webkit-scrollbar { width: 5px; }
        .list-section::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }

        .batch-card { background: #fff; border-radius: 16px; padding: 18px 20px; margin-bottom: 16px; border: 1px solid #e2e8f0; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.02); }
        .batch-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; font-size: 0.85rem; font-weight: 700; color: #475569; }
        .batch-area { width: 100%; height: 90px; padding: 12px; border-radius: 12px; border: 1px solid #cbd5e1; font-size: 0.8rem; font-family: monospace; box-sizing: border-box; background: #fafafa; resize: vertical; line-height: 1.5; color: #334155; }

        .item-card { background: white; border: 1px solid #e2e8f0; border-radius: 16px; margin-bottom: 12px; display: flex; flex-direction: column; overflow: hidden; width: 100%; position: relative; transition: 0.2s; box-shadow: 0 2px 4px rgba(0,0,0,0.02); }
        @media (min-width: 640px) { .item-card { flex-direction: row; min-height: 115px; height: auto; } }
        .item-card:hover { border-color: var(--main); }

        .item-badge { 
            position: absolute; top: 0; left: 0; background: var(--main); color: white; 
            font-size: 0.65rem; font-weight: 800; padding: 2px 8px; border-bottom-right-radius: 10px; 
            z-index: 10; letter-spacing: 0.3px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .item-pre { width: 100%; height: 140px; flex-shrink: 0; background: #f8fafc; border-bottom: 1px solid #f1f5f9; display: flex; align-items: center; justify-content: center; position: relative; }
        @media (min-width: 640px) { .item-pre { width: 140px; height: auto; min-height: 115px; border-bottom: none; border-right: 1px solid #f1f5f9; } }
        .item-pre img { width: 100%; height: 100%; object-fit: cover; }

        .item-content { flex: 1; padding: 12px 16px; min-width: 0; display: flex; flex-direction: column; justify-content: center; width: 100%; gap: 4px; }
        .item-title { font-size: 0.85rem; font-weight: 700; color: #334155; padding-right: 35px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; line-height: 1.4; }

        .tab-group { display: flex; gap: 8px; margin: 4px 0; }
        .tab-btn { padding: 4px 10px; border-radius: 6px; border: 1px solid #e2e8f0; font-size: 0.7rem; background: #fff; cursor: pointer; color: #64748b; font-weight: 700; }
        .tab-btn.active { background: var(--main); color: white; border-color: var(--main); }
        .tab-btn.danger-tab.active { background: var(--danger); border-color: var(--danger); }

        .item-input-wrap { width: 100%; display: none; margin-top: 2px; }
        .item-input-wrap input { width: 100%; padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 0.75rem; font-family: monospace; background: #fafafa; cursor: pointer; }
        .item-input-wrap input.danger-text { color: var(--danger) !important; font-weight: 600; }

        .item-remove-btn { 
            position: absolute; top: 10px; right: 10px; width: 28px; height: 28px; 
            background: rgba(241, 245, 249, 0.95); border: 1px solid #e2e8f0; border-radius: 50%; 
            color: #64748b; display: flex; align-items: center; justify-content: center; 
            cursor: pointer; z-index: 10; transition: all 0.2s ease;
        }
        .item-remove-btn:hover { 
            background: var(--danger); border-color: var(--danger); color: white; transform: scale(1.08); 
        }
        .item-remove-btn svg { width: 14px; height: 14px; fill: currentColor; }

        .app-footer { padding: 12px 20px; border-top: 1px solid #f1f5f9; text-align: center; font-size: 0.75rem; color: #94a3b8; background: #fff; flex-shrink: 0; }

        .btn { padding: 8px 16px; border-radius: 10px; border: none; cursor: pointer; font-size: 0.8rem; font-weight: 700; transition: 0.2s; }
        .btn-main { background: var(--main); color: white; padding: 9px 20px; font-size: 0.85rem; }
        .btn-main:disabled { background: #cbd5e1; cursor: not-allowed; }
        .btn-ghost { background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; }
        .btn-ghost:hover { background: #e2e8f0; }
        
        #toast { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); background: rgba(15,23,42,0.92); color: white; padding: 12px 30px; border-radius: 50px; display: none; z-index: 2000; font-size: 0.85rem; font-weight: 600; box-shadow: 0 10px 25px rgba(0,0,0,0.2); }
    </style>
</head>
<body>

<div id="toast">✔ 已复制</div>

<div class="app">
    <div class="app-header">
        <div class="logo">SimpleHashImg <span>Pro V36</span></div>
        <div class="meta-stats">
            <span class="stat-item" id="wait-box">待上传: <span class="badge badge-wait" id="wait-num">0</span></span>
            <span class="stat-item" id="done-box">已成功: <span class="badge badge-success" id="done-num">0</span></span>
            <span>托管: <span class="badge" id="hosted-count"><?php echo $total_hosted; ?> 张</span></span>
        </div>
    </div>
    
    <div class="app-body empty" id="app-body">
        <div class="upload-card">
            <div class="drop-zone" id="drop-zone">
                <div class="drop-icon">☁</div>
                <div style="font-weight:700; color:#475569">拖拽图片至此 或 点击选择文件</div>
                <div style="font-size:0.75rem; color:#94a3b8">支持多图选择 · 自动去重 · 分片传输 · 隐私擦除</div>
            </div>
            
            <input type="file" id="file-input" style="display:none" accept="image/*" multiple>
            
            <div class="controls-toolbar">
                <div class="left-controls">
                    <label class="toggle-label">
                        <input type="checkbox" id="auto-up">
                        <span>自动上传</span>
                    </label>
                    <button class="btn btn-ghost" id="sort-toggle-btn" onclick="toggleSortOrder()">排序: 倒序</button>
                    <button class="btn btn-ghost" onclick="clearAll()">清空预览</button>
                </div>
                <button class="btn btn-main" id="start-btn" onclick="startUpload()" disabled>开始上传</button>
            </div>
        </div>

        <div class="list-section">
            <div class="batch-card" id="batch-card" style="display:none">
                <div class="batch-header">
                    <span>图片外链汇总 (点击框一键复制)</span>
                    <span id="batch-del-btn" style="color:var(--danger); cursor:pointer" onclick="cp(this.getAttribute('data-url'))">复制批次一键删除</span>
                </div>
                <textarea class="batch-area" id="all-urls" readonly onclick="cp(this.value)"></textarea>
            </div>
            <div id="file-list-content"></div>
        </div>
    </div>

    <div class="app-footer">
        © 2026 Gemini & Nihility. All Rights Reserved.
    </div>
</div>

<script src="https://cdn.bootcdn.net/ajax/libs/spark-md5/3.0.0/spark-md5.min.js"></script>
<script>
let displayOrder = sessionStorage.getItem('SimpleHashImg_SortOrder') || 'desc';
let queue = [];
let globalSeqCounter = 0;
let sessID = Date.now() + Math.random().toString(36).substr(2, 5);

const fi = document.getElementById('file-input');
const lc = document.getElementById('file-list-content');
const dz = document.getElementById('drop-zone');
const appBody = document.getElementById('app-body');

document.getElementById('sort-toggle-btn').innerText = `排序: ${displayOrder === 'desc' ? '倒序' : '正序'}`;

dz.onclick = () => { fi.value = ''; fi.click(); };
fi.onchange = e => handleFiles(e.target.files);

['dragenter', 'dragover', 'dragleave', 'drop'].forEach(ev => {
    window.addEventListener(ev, e => { e.preventDefault(); e.stopPropagation(); }, false);
});

['dragenter', 'dragover'].forEach(ev => {
    dz.addEventListener(ev, e => { e.preventDefault(); e.stopPropagation(); dz.classList.add('active'); }, false);
});

['dragleave', 'drop'].forEach(ev => {
    dz.addEventListener(ev, e => { e.preventDefault(); e.stopPropagation(); dz.classList.remove('active'); }, false);
});

dz.addEventListener('drop', e => {
    if (e.dataTransfer && e.dataTransfer.files.length) handleFiles(e.dataTransfer.files);
}, false);

window.addEventListener('DOMContentLoaded', () => {
    restoreFromCache();
});

function formatSize(bytes) {
    if (!bytes || bytes === 0) return '0 KB';
    if (bytes < 1024 * 1024) {
        return (bytes / 1024).toFixed(1) + ' KB';
    }
    return (bytes / (1024 * 1024)).toFixed(2) + ' MB';
}

function handleFiles(files) {
    if(!files || files.length === 0) return;
    
    const isAuto = document.getElementById('auto-up').checked;
    let addedCount = 0;

    for (let file of files) {
        if (!file.type.startsWith('image/')) continue;

        const fileKey = `${file.name}_${file.size}_${file.lastModified}`;
        const isDuplicate = queue.some(item => item.fileKey === fileKey);
        if (isDuplicate) {
            continue;
        }
        
        globalSeqCounter++;
        addedCount++;
        appBody.classList.remove('empty');

        const qid = "q_" + Date.now() + "_" + Math.random().toString(36).substr(2, 5);
        const item = { 
            id: qid, 
            uid: Date.now() + "_" + Math.random().toString(36).substr(2, 5), 
            seq: globalSeqCounter, 
            file: file, 
            fileKey: fileKey,
            name: file.name,
            status: 'wait', 
            ext: file.name.split('.').pop().toLowerCase() || 'jpg',
            sizeStr: formatSize(file.size),
            url: '',
            del: ''
        };
        queue.push(item);
        renderItemCard(item);
    }
    
    updateStatsAndBatch();
    if (isAuto && addedCount > 0) startUpload();
}

function renderItemCard(item) {
    let div = document.getElementById(`card-${item.id}`);
    if (!div) {
        div = document.createElement('div');
        div.className = 'item-card';
        div.id = `card-${item.id}`;
    }

    div.innerHTML = `
        <div class="item-badge">No.${item.seq}</div>
        <div class="item-pre" id="pre-${item.id}"><small style="color:#aaa">加载中</small></div>
        <div class="item-content">
            <div class="item-title" title="${item.name}">${item.name} ${item.sizeStr ? `<span style="font-size:0.75rem; color:#94a3b8; font-weight:600; margin-left:6px">(${item.ext.toUpperCase()} · ${item.sizeStr})</span>` : ''}</div>
            <div id="st-${item.id}" style="font-size:0.75rem; font-weight:700">等待上传</div>
            <div class="item-input-wrap" id="res-${item.id}">
                <div class="tab-group">
                    <button class="tab-btn active" onclick="switchTab(this, '${item.id}', 'url')">图片外链</button>
                    <button class="tab-btn danger-tab" onclick="switchTab(this, '${item.id}', 'del')">删除链接</button>
                </div>
                <input type="text" readonly id="inp-${item.id}" onclick="cp(this.value)">
            </div>
            <div id="prog-${item.id}" style="height:3px; background:#f1f5f9; margin-top:6px; border-radius:10px; overflow:hidden; display:none">
                <div id="bar-${item.id}" style="width:0; height:100%; background:var(--main); transition:0.2s"></div>
            </div>
        </div>
        <div class="item-remove-btn" id="rm-${item.id}" onclick="removeSingle('${item.id}')" title="移除此项">
            <svg viewBox="0 0 1024 1024"><path d="M563.8 512l262.5-262.5c14.3-14.3 14.3-37.5 0-51.8-14.3-14.3-37.5-14.3-51.8 0L512 460.2 249.5 197.7c-14.3-14.3-37.5-14.3-51.8 0-14.3 14.3-14.3 37.5 0 51.8L460.2 512 197.7 774.5c-14.3 14.3-14.3 37.5 0 51.8 7.2 7.2 16.5 10.7 25.9 10.7s18.7-3.6 25.9-10.7L512 563.8l262.5 262.5c7.2 7.2 16.5 10.7 25.9 10.7s18.7-3.6 25.9-10.7c14.3-14.3 14.3-37.5 0-51.8L563.8 512z"/></svg>
        </div>
    `;

    if (displayOrder === 'desc') {
        lc.insertBefore(div, lc.firstChild);
    } else {
        lc.appendChild(div);
    }

    if (item.file) {
        const blobUrl = URL.createObjectURL(item.file);
        document.getElementById(`pre-${item.id}`).innerHTML = `<img src="${blobUrl}">`;
    } else if (item.previewUrl) {
        document.getElementById(`pre-${item.id}`).innerHTML = `<img src="${item.previewUrl}">`;
    }
}

function applyDOMSort() {
    const cards = Array.from(lc.querySelectorAll('.item-card'));
    if (cards.length === 0) return;

    cards.sort((a, b) => {
        const idA = a.id.replace('card-', '');
        const idB = b.id.replace('card-', '');
        const itemA = queue.find(o => o.id === idA);
        const itemB = queue.find(o => o.id === idB);
        if (!itemA || !itemB) return 0;
        
        return displayOrder === 'desc' ? (itemB.seq - itemA.seq) : (itemA.seq - itemB.seq);
    });

    cards.forEach(card => lc.appendChild(card));
}

function toggleSortOrder() {
    displayOrder = (displayOrder === 'desc') ? 'asc' : 'desc';
    document.getElementById('sort-toggle-btn').innerText = `排序: ${displayOrder === 'desc' ? '倒序' : '正序'}`;
    sessionStorage.setItem('SimpleHashImg_SortOrder', displayOrder);
    applyDOMSort();
}

function removeSingle(qid) {
    const idx = queue.findIndex(o => o.id === qid);
    if (idx > -1) {
        const item = queue[idx];
        if (item.status !== 'done') {
            ajax('?action=abort', { id: item.uid });
        }
        
        queue.splice(idx, 1);
        const card = document.getElementById(`card-${qid}`);
        if(card) card.remove();
        
        if(queue.length === 0) {
            appBody.classList.add('empty');
            globalSeqCounter = 0;
        }
        updateStatsAndBatch();
        saveToCache();
    }
}

async function fetchWithRetry(fn, retries = 3, delay = 1000) {
    for (let i = 0; i < retries; i++) {
        try {
            return await fn();
        } catch (err) {
            if (i === retries - 1) throw err;
            await new Promise(r => setTimeout(r, delay));
        }
    }
}

async function startUpload() {
    const pendingItems = queue.filter(o => o.status === 'wait' || o.status === 'error');
    if (pendingItems.length === 0) return;

    const MAX_CONCURRENT = 3;
    let poolIndex = 0;

    async function worker() {
        while (poolIndex < pendingItems.length) {
            const item = pendingItems[poolIndex++];
            await processUpload(item);
        }
    }

    const workers = [];
    for (let i = 0; i < Math.min(MAX_CONCURRENT, pendingItems.length); i++) {
        workers.push(worker());
    }
    await Promise.all(workers);
}

async function processUpload(item) {
    item.status = 'uploading';
    updateStatsAndBatch();
    
    const st = document.getElementById(`st-${item.id}`), bar = document.getElementById(`bar-${item.id}`), prog = document.getElementById(`prog-${item.id}`);
    if (st) { st.innerText = "准备传输..."; st.style.color = "var(--main)"; }
    if (prog) prog.style.display = "block";

    // 记录开始传输时间
    item.startTime = item.startTime || Date.now();

    try {
        const hash = item.hash || await new Promise(r => {
            const f = new FileReader(); f.readAsArrayBuffer(item.file);
            f.onload = e => r(SparkMD5.ArrayBuffer.hash(e.target.result));
        });
            item.hash = hash;

        const check = await fetchWithRetry(() => ajax('?action=check', { hash: hash, ext: item.ext, sid: sessID }));
        if (check.error) { item.status = 'error'; setErrorUI(item, check.error); updateStatsAndBatch(); return; }
        if (check.hit) return finishItem(item, check);

        const sz = 1024 * 1024, total = Math.ceil(item.file.size / sz), uid = item.uid;
        for (let i = 0; i < total; i++) {
            const fd = new FormData(); fd.append('file', item.file.slice(i * sz, (i + 1) * sz)); fd.append('id', uid); fd.append('idx', i);
            
            await fetchWithRetry(() => fetch('?action=up_chunk', { method: 'POST', body: fd }));
            
            // 动态计算已用时间（秒）与实时网速
            const elapsed = Math.max(1, Math.floor((Date.now() - item.startTime) / 1000));
            const uploadedBytes = Math.min((i + 1) * sz, item.file.size);
            const speedStr = formatSize(uploadedBytes / elapsed) + '/s';
            const percent = Math.round(((i + 1) / total) * 95);

            if (st) st.innerText = `传输中 ${percent}% (用时 ${elapsed}s · ${speedStr})`;
            if (bar) bar.style.width = percent + '%';
        }

        if (st) st.innerText = "校验并合并分片中...";
        const res = await fetchWithRetry(() => ajax('?action=merge', { id: uid, hash: hash, ext: item.ext, sid: sessID, size: item.file.size }));
        if (res.error) { item.status = 'error'; setErrorUI(item, res.error); updateStatsAndBatch(); return; }
        
        finishItem(item, res);

    } catch (error) {
        item.status = 'error';
        setErrorUI(item, "❌ 传输中断 (点击重试)");
        updateStatsAndBatch();
        saveToCache();
    }
}

function setErrorUI(item, msg = "❌ 上传失败 (点击重试)") {
    const st = document.getElementById(`st-${item.id}`);
    const prog = document.getElementById(`prog-${item.id}`);
    if (st) {
        st.innerText = msg;
        st.style.color = "var(--danger)";
        st.style.cursor = "pointer";
        st.onclick = () => processUpload(item);
    }
    if (prog) prog.style.display = "none";
}

function finishItem(item, data) {
    item.status = 'done';
    item.url = data.url;
    item.del = data.del;

    const rm = document.getElementById(`rm-${item.id}`);
    const st = document.getElementById(`st-${item.id}`);
    const prog = document.getElementById(`prog-${item.id}`);

    if (rm) rm.style.display = 'none';
    if (st) {
        st.innerText = "✓ 上传成功";
        st.style.color = "var(--success)";
        st.onclick = null;
        st.style.cursor = "default";
    }
    if (prog) prog.style.display = "none";

    const res = document.getElementById(`res-${item.id}`), inp = document.getElementById(`inp-${item.id}`);
    if (res && inp) {
        res.style.display = 'block';
        inp.value = data.url;
        inp.setAttribute('data-url', data.url);
        inp.setAttribute('data-del', data.del);
    }

    if (data.batch_del) {
        document.getElementById('batch-del-btn').setAttribute('data-url', data.batch_del);
    }

    if (data.hosted_count !== undefined) {
        document.getElementById('hosted-count').innerText = data.hosted_count + " 张";
    }

    updateStatsAndBatch();
    saveToCache();
}

function switchTab(btn, id, type) {
    const parent = btn.parentElement;
    parent.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    const inp = document.getElementById(`inp-${id}`);
    if (type === 'url') {
        inp.value = inp.getAttribute('data-url');
        inp.classList.remove('danger-text');
    } else {
        inp.value = inp.getAttribute('data-del');
        inp.classList.add('danger-text');
    }
}

function updateStatsAndBatch() {
    const waitCount = queue.filter(o => o.status === 'wait' || o.status === 'error').length;
    const doneCount = queue.filter(o => o.status === 'done').length;
    const hasItems = queue.length > 0;

    document.getElementById('wait-box').style.display = hasItems ? 'inline-flex' : 'none';
    document.getElementById('done-box').style.display = hasItems ? 'inline-flex' : 'none';

    document.getElementById('wait-num').innerText = waitCount;
    document.getElementById('done-num').innerText = doneCount;
    document.getElementById('start-btn').disabled = (waitCount === 0);

    const doneUrls = queue.filter(o => o.status === 'done').map(o => o.url);
    if (doneUrls.length > 0) {
        document.getElementById('batch-card').style.display = 'block';
        document.getElementById('all-urls').value = doneUrls.join('\n');
    } else {
        document.getElementById('batch-card').style.display = 'none';
    }
}

function saveToCache() {
    const doneUrls = queue.filter(o => o.status === 'done').map(o => o.url);
    const cacheData = {
        doneCount: doneUrls.length,
        doneUrls: doneUrls,
        batchDelUrl: document.getElementById('batch-del-btn').getAttribute('data-url') || ''
    };
    try { 
        sessionStorage.setItem('SimpleHashImg_Session', JSON.stringify(cacheData)); 
    } catch(e) {}
}

function restoreFromCache() {
    const raw = sessionStorage.getItem('SimpleHashImg_Session');
    if (!raw) return;
    try {
        const cache = JSON.parse(raw);
        if (cache.doneUrls && cache.doneUrls.length > 0) {
            appBody.classList.remove('empty');
            
            document.getElementById('done-box').style.display = 'inline-flex';
            document.getElementById('done-num').innerText = cache.doneCount || cache.doneUrls.length;

            document.getElementById('batch-card').style.display = 'block';
            document.getElementById('all-urls').value = cache.doneUrls.join('\n');
            if (cache.batchDelUrl) {
                document.getElementById('batch-del-btn').setAttribute('data-url', cache.batchDelUrl);
            }
        }
    } catch(e) {
        sessionStorage.removeItem('SimpleHashImg_Session');
    }
}

function clearAll() {
    queue.forEach(item => {
        if (item.status !== 'done') {
            ajax('?action=abort', { id: item.uid });
        }
    });

    queue = [];
    globalSeqCounter = 0;
    lc.innerHTML = '';
    document.getElementById('batch-card').style.display = 'none';
    appBody.classList.add('empty');
    sessionStorage.removeItem('SimpleHashImg_Session');
    sessID = Date.now() + Math.random().toString(36).substr(2, 5);
    updateStatsAndBatch();
}

async function ajax(u, d) { const fd = new FormData(); for (let k in d) fd.append(k, d[k]); return fetch(u, { method: 'POST', body: fd }).then(r => r.json()); }

function showToast(msg) {
    const t = document.getElementById('toast'); 
    t.innerText = msg;
    t.style.display = 'block'; 
    setTimeout(() => t.style.display = 'none', 2500);
}

function cp(txt) {
    if (!txt) return;
    const el = document.createElement('textarea'); el.value = txt; document.body.appendChild(el); el.select(); document.execCommand('copy'); document.body.removeChild(el);
    showToast("✔ 已复制");
}
</script>
</body>
</html>