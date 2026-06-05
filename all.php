<?php

// ================================= //
// --- PHẦN CẤU HÌNH DỰ ÁN ---
// ================================= //
// Đổi tên file này thành config.php và điền thông tin thật của bạn.
// File config.php sẽ không được đưa lên Git để đảm bảo an toàn.

// --- Thông tin đăng nhập cPanel ---
$cpanel_host = 'your_cpanel_host.com:2083'; // VD: yourdomain.com:2083
$cpanel_user = 'your_cpanel_username';
$cpanel_pass = 'your_cpanel_password';

// --- Cấu hình chi tiết cho kịch bản ---
$config = [
    // --- Cấu hình Source Code ---
    // Thư mục trên server, sẽ tự động lấy theo username cPanel
    'targetDirectory'   => '/home/' . $cpanel_user . '/public_html', 
    
    // Đường dẫn tuyệt đối đến file source code .zip trên máy của bạn
    'localFileToUpload' => 'C:/path/to/your/source-code.zip',

    // Tự động nén source code nếu không muốn chuẩn bị sẵn file .zip
    'autoZip' => [
        'enabled'    => true,
        'sourcePath' => 'C:/path/to/your/project-folder', // Thư mục cần đóng gói
        'outputPath' => null, // Để null sẽ tạo file .zip tại thư mục tạm
        'excludes'   => ['.git', 'node_modules', 'storage/logs', 'bootstrap/cache', '*.log', '*.zip', 'backup', 'logs', 'cache', '*.sql', '*.apk', 'error_log'],
    ],

    // Xóa file .zip tạm trên máy local sau khi upload xong
    'removeLocalZipAfterUpload' => true,

    // File môi trường cần chỉnh sửa sau khi giải nén
    'fileToEdit'        => '/.env',

    // --- Cấu hình Database ---
    // Tên database, user, và mật khẩu sẽ tự động tạo dựa trên username cPanel
    'database_name'     => $cpanel_user . '_dbname',
    'db_user'           => $cpanel_user . '_dbuser',
    'db_pass'           => 'YourStrongDBPassword123!', // 🔑 Đặt một mật khẩu database thật mạnh ở đây
    'db_host'           => 'localhost',
    
    // Đường dẫn tuyệt đối đến file backup .sql trên máy của bạn
    'localSqlFile'      => 'C:/path/to/your/database-backup.sql',
];


// =================================================================================
// === CÔNG CỤ TỰ ĐỘNG HÓA TRIỂN KHAI CPANEL TOÀN DIỆN =============================
// =================================================================================
// Tác giả: Trần Đăng Khoa & Gemini
// Phiên bản logic DB: 16/08/2025 (sử dụng DOMDocument)
// Chức năng:
// 1. Tự động upload và cấu hình source code (.env).
// 2. Tự động tạo Database và User.
// 3. Tự động xóa sạch bảng và import file .sql mới (logic mới, mạnh mẽ hơn).
// 4. Chế độ triển khai đầy đủ (làm cả 3 việc trên).
// =================================================================================

error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(900); // Tăng thời gian chạy lên 15 phút cho các tác vụ lớn
if (ob_get_level()) ob_end_clean(); // Tắt output buffering cho progress bar chạy mượt

/**
 * Gửi yêu cầu upload với thanh tiến trình (cURL progress callback).
 */
function sendApiRequestWithProgress(string $url, string $cookieFile, array $postData, int $totalSize): array|false
{
    $ch = curl_init();
    if ($ch === false) {
        error_log('Lỗi: Không thể khởi tạo cURL cho upload progress.');
        return false;
    }

    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $postData,
        CURLOPT_TIMEOUT        => 600,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_COOKIEJAR      => $cookieFile,
        CURLOPT_COOKIEFILE     => $cookieFile,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER     => [
            'User-Agent: Mozilla/5.0',
            'Referer: ' . $url,
        ],
        CURLOPT_NOPROGRESS     => false,
        CURLOPT_PROGRESSFUNCTION => function($client, $dlTotal, $dlNow, $ulTotal, $ulNow) use ($totalSize) {
            $uploaded = $ulNow > 0 ? $ulNow : 0;
            $target = $ulTotal > 0 ? $ulTotal : $totalSize;
            if ($target > 0) {
                progressBar($uploaded, $target, 40, formatBytes($uploaded) . ' / ' . formatBytes($target));
            }
        },
    ]);

    $response = curl_exec($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        error_log('Lỗi cURL upload: ' . $error);
        return false;
    }
    curl_close($ch);

    $body = substr($response, $headerSize);
    return json_decode($body, true);
}
function sendApiRequest(string $url, string $cookieFile, string $method = 'GET', array $headers = [], $data = null, bool $isUAPI = false)
{
    $ch = curl_init();
    if ($ch === false) {
        error_log('Lỗi: Không thể khởi tạo cURL cho API.');
        return false;
    }

    $isUpload = false;
    if (is_array($data)) {
        foreach ($data as $value) {
            if ($value instanceof CURLFile) {
                $isUpload = true;
                break;
            }
        }
    }

    $postFields = null;
    if (strtoupper($method) !== 'GET') {
        $postFields = $isUpload ? $data : (is_array($data) ? http_build_query($data) : $data);
    } elseif (is_array($data) && $isUAPI) {
        $url .= '?' . http_build_query($data);
    }
    
    if ($isUpload) {
        foreach ($headers as $i => $header) {
            if (stripos($header, 'Content-Type:') === 0) unset($headers[$i]);
        }
    }

    $options = [
        CURLOPT_URL            => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER         => true,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method), CURLOPT_HTTPHEADER     => array_values($headers),
        CURLOPT_POSTFIELDS     => $postFields, CURLOPT_TIMEOUT        => 600,
        CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5, CURLOPT_COOKIEJAR      => $cookieFile,
        CURLOPT_COOKIEFILE     => $cookieFile, CURLOPT_SSL_VERIFYPEER => false,
    ];

    curl_setopt_array($ch, $options);
    $response = curl_exec($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    if (curl_errno($ch)) {
        error_log('Lỗi cURL API: ' . curl_error($ch));
        curl_close($ch);
        return false;
    }
    curl_close($ch);

    $body = substr($response, $headerSize);
    return json_decode($body, true);
}

/**
 * Hàm gửi yêu cầu cURL giả lập trình duyệt (cho phpMyAdmin).
 * Trả về kết quả thô (HTML/text).
 */
function sendBrowserRequest(string $url, string $cookieFile, string $method = 'GET', array $headers = [], $data = null)
{
    $ch = curl_init();
    $defaultHeaders = ['User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36'];
    $finalHeaders = array_merge($defaultHeaders, $headers);
    $postFields = null;

    if ($data !== null && strtoupper($method) === 'POST') {
        $isMultipart = false;
        if (is_array($data)) {
            foreach ($data as $value) {
                if ($value instanceof CURLFile) {
                    $isMultipart = true;
                    break;
                }
            }
        }
        $postFields = $isMultipart ? $data : (is_array($data) ? http_build_query($data) : $data);
    }
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER         => false,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method), CURLOPT_HTTPHEADER     => $finalHeaders,
        CURLOPT_POSTFIELDS     => $postFields, CURLOPT_TIMEOUT        => 900,
        CURLOPT_COOKIEJAR      => $cookieFile, CURLOPT_COOKIEFILE     => $cookieFile,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return $response;
}

/**
 * Helper: Kiem tra response upload co thanh cong hay khong.
 */
function isUploadSuccess($uploadData): bool
{
    if (!is_array($uploadData)) {
        return false;
    }

    if (
        isset($uploadData['status']) &&
        (int) $uploadData['status'] === 1 &&
        empty($uploadData['errors'])
    ) {
        return true;
    }

    if (
        isset($uploadData['data']['succeeded']) &&
        (int) $uploadData['data']['succeeded'] > 0 &&
        empty($uploadData['errors'])
    ) {
        return true;
    }

    if (
        isset($uploadData['result']['status']) &&
        (int) $uploadData['result']['status'] === 1 &&
        empty($uploadData['result']['errors'])
    ) {
        return true;
    }

    return false;
}

/**
 * Helper: Rut gon message loi upload.
 */
function getUploadErrorMessage($uploadData): string
{
    if (!is_array($uploadData)) {
        return 'Phản hồi upload không hợp lệ.';
    }

    if (!empty($uploadData['errors'][0])) {
        return (string) $uploadData['errors'][0];
    }

    if (!empty($uploadData['result']['errors'][0])) {
        return (string) $uploadData['result']['errors'][0];
    }

    if (!empty($uploadData['messages'][0])) {
        return (string) $uploadData['messages'][0];
    }

    if (!empty($uploadData['statusmsg'])) {
        return (string) $uploadData['statusmsg'];
    }

    return 'Unknown upload error.';
}

/**
 * Helper: Upload file len cPanel voi retry nhieu kieu payload.
 */
function uploadFileToCpanel(string $cpanel_host, string $securityToken, string $cookieFile, string $targetDirectory, string $localFileToUpload): array
{
    $realPath = realpath($localFileToUpload);
    if ($realPath === false || !is_file($realPath) || !is_readable($realPath)) {
        die("Lỗi: Không thể đọc file upload '{$localFileToUpload}'.\n");
    }

    $size = filesize($realPath);
    if ($size === false || $size <= 0) {
        die("Lỗi: File upload rỗng hoặc không đọc được dung lượng: '{$realPath}'.\n");
    }
    echo "  📦 Dung lượng: \033[1m" . formatBytes($size) . "\033[0m\n";

    $mimeType = function_exists('mime_content_type') ? mime_content_type($realPath) : 'application/octet-stream';
    if (!is_string($mimeType) || $mimeType === '') {
        $mimeType = 'application/octet-stream';
    }

    $uploadUrl = "https://{$cpanel_host}{$securityToken}/execute/Fileman/upload_files";
    $uploadHeaders = [
        'Referer: https://' . $cpanel_host . $securityToken . '/frontend/jupiter/filemanager/index.html',
        'User-Agent: Mozilla/5.0',
        'Origin: https://' . $cpanel_host,
    ];

    $attempts = [
        ['label' => 'UAPI file-1', 'transport' => 'api', 'field' => 'file-1'],
        ['label' => 'UAPI file-0', 'transport' => 'api', 'field' => 'file-0'],
        ['label' => 'Browser file-1', 'transport' => 'browser', 'field' => 'file-1'],
        ['label' => 'Browser file-0', 'transport' => 'browser', 'field' => 'file-0'],
    ];

    $attemptErrors = [];
    foreach ($attempts as $attempt) {
        $postData = [
            'get_disk_info' => '1',
            'dir' => $targetDirectory,
            'overwrite' => '1',
            $attempt['field'] => new CURLFile($realPath, $mimeType, basename($realPath)),
        ];

        if ($attempt['transport'] === 'api') {
            echo "  ☁️  Đang upload ({$attempt['label']})...\n";
            $uploadData = sendApiRequestWithProgress($uploadUrl, $cookieFile, $postData, $size);
        } else {
            echo "  ☁️  Đang upload ({$attempt['label']})...\n";
            $raw = sendBrowserRequest($uploadUrl, $cookieFile, 'POST', $uploadHeaders, $postData);
            $uploadData = json_decode((string) $raw, true);
        }

        if (isUploadSuccess($uploadData)) {
            echo "  ✅ Upload thành công!\n";
            return $uploadData;
        }

        $attemptErrors[] = $attempt['label'] . ': ' . getUploadErrorMessage($uploadData);
    }

    die("Upload thất bại sau nhiều lần thử. " . implode(' | ', $attemptErrors) . "\n");
}

/**
 * Helper: Tim file .sql theo thu muc source (uu tien file moi nhat).
 */
function resolveLocalSqlFilePath(array $config): string
{
    $configuredSql = trim((string)($config['localSqlFile'] ?? ''));
    if ($configuredSql !== '' && is_file($configuredSql)) {
        $resolved = realpath($configuredSql);
        return $resolved !== false ? $resolved : $configuredSql;
    }

    $sourcePath = trim((string)($config['autoZip']['sourcePath'] ?? ''));
    if ($sourcePath === '' || !is_dir($sourcePath)) {
        return $configuredSql;
    }

    echo "📁 Đang tìm file SQL theo thư mục source: {$sourcePath}\n";

    $candidates = [];

    // Tim o cap thu muc goc source truoc
    $rootPattern = rtrim($sourcePath, '/\\') . DIRECTORY_SEPARATOR . '*.sql';
    $rootSqlFiles = glob($rootPattern) ?: [];
    foreach ($rootSqlFiles as $file) {
        if (is_file($file)) {
            $candidates[] = $file;
        }
    }

    // Neu khong co o goc, tim de quy
    if (empty($candidates)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourcePath, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $fileInfo) {
            /** @var SplFileInfo $fileInfo */
            if ($fileInfo->isFile() && strtolower($fileInfo->getExtension()) === 'sql') {
                $candidates[] = $fileInfo->getPathname();
            }
        }
    }

    if (empty($candidates)) {
        return $configuredSql;
    }

    usort($candidates, static function (string $a, string $b): int {
        $timeA = filemtime($a) ?: 0;
        $timeB = filemtime($b) ?: 0;
        return $timeB <=> $timeA;
    });

    $selected = $candidates[0];
    $resolved = realpath($selected);
    $selectedPath = $resolved !== false ? $resolved : $selected;
    echo "✅ Đã chọn file SQL: {$selectedPath}\n";

    return $selectedPath;
}



/**
 * Helper: bo qua cac file/thu muc khong muon dua vao goi zip.
 */
function shouldExcludeFromZip(string $relativePath, array $excludes): bool
{
    $relativePath = str_replace('\\', '/', $relativePath);
    foreach ($excludes as $pattern) {
        $pattern = trim(str_replace('\\', '/', $pattern));
        if ($pattern === '') continue;
        $prefix = rtrim($pattern, '/');
        if (str_starts_with($relativePath, $prefix . '/')) return true;
        if ($relativePath === $prefix) return true;
        if (fnmatch($pattern, $relativePath)) return true;
    }
    return false;
}

/**
 * In mot thanh tien trinh (progress bar) trong console.
 * Cap nhat toi da 60 lan/giay de tranh spam console.
 */
function progressBar(float $current, float $total, int $width = 50, string $suffix = ''): void
{
    static $lastTime = 0;
    $now = hrtime(true);
    if ($total <= 0) return;
    $percent = min($current / $total, 1);
    // Chi in neu da qua 50ms hoac da hoan thanh
    if ($percent < 1 && $now - $lastTime < 50_000_000) return;
    $lastTime = $now;

    $filled = (int) round($percent * $width);
    $bar = str_repeat('█', $filled) . str_repeat('░', $width - $filled);
    $pct = str_pad(number_format($percent * 100, 1) . '%', 7, ' ', STR_PAD_LEFT);
    echo "\r  \033[32m{$bar}\033[0m {$pct}" . ($suffix ? " \033[90m{$suffix}\033[0m" : '');
    if ($percent >= 1) echo "\n";

    // Flush ngay de \r hoat dong tren Windows
    if (ob_get_level()) ob_flush();
    flush();
}

/**
 * Tinh toan kich thuoc thu muc de hien thi tien trinh.
 */
function countAndSizeRecursive(string $dir, array $excludes = []): array
{
    $totalFiles = 0;
    $totalSize = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    $sourceDir = realpath($dir);
    foreach ($iterator as $file) {
        $filePath = $file->getRealPath();
        $relativePath = ltrim(str_replace('\\', '/', substr($filePath, strlen($sourceDir))), '/');
        if ($relativePath === '' || shouldExcludeFromZip($relativePath, $excludes)) continue;
        if ($file->isFile()) {
            $totalFiles++;
            $totalSize += $file->getSize();
        }
    }
    return [$totalFiles, $totalSize];
}

/**
 * Format byte sang don vi de doc.
 */
function formatBytes(int $bytes): string
{
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 1) . ' GB';
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}

/**
 * Tu dong nen thu muc thanh file .zip de upload (co progress bar).
 */
function createZipFromDirectory(string $sourceDir, ?string $customZipPath = null, array $excludes = []): string
{
    if (!extension_loaded('zip')) {
        die("ERROR: PHP extension 'zip' is not enabled.\n");
    }
    if (!is_dir($sourceDir)) {
        die("ERROR: Source directory not found: {$sourceDir}\n");
    }

    $sourceDir = realpath($sourceDir);
    $zipPath = $customZipPath ?: (sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cpanel_upload_' . date('Ymd_His') . '.zip');

    // Dem truoc file va kich thuoc
    [$totalFiles, $totalSize] = countAndSizeRecursive($sourceDir, $excludes);
    echo "  📊 Đang nén: \033[1m{$totalFiles}\033[0m tập tin (\033[1m" . formatBytes($totalSize) . "\033[0m)\n";

    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        die("ERROR: Cannot create zip at {$zipPath}\n");
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    $processed = 0;
    $processedSize = 0;
    foreach ($iterator as $file) {
        $filePath = $file->getRealPath();
        $relativePath = ltrim(str_replace('\\', '/', substr($filePath, strlen($sourceDir))), '/');
        if ($relativePath === '') continue;
        if (shouldExcludeFromZip($relativePath, $excludes)) continue;

        if ($file->isDir()) {
            $zip->addEmptyDir($relativePath);
        } else {
            $zip->addFile($filePath, $relativePath);
            $processedSize += $file->getSize();
        }
        $processed++;
        progressBar($processed, $totalFiles, 40, "{$processed}/{$totalFiles} files");
    }

    $zip->close();
    $zipSize = filesize($zipPath);
    echo "  ✅ Nén xong: \033[1m" . formatBytes($zipSize) . "\033[0m\n";
    return $zipPath;
}

/**
 * Helper: Doc input tu console (co ho tro fallback neu khong co readline).
 */
function readConsoleInput(string $prompt): string
{
    if (function_exists('readline')) {
        $line = readline($prompt);
        return is_string($line) ? trim($line) : '';
    }

    if (defined('STDIN')) {
        echo $prompt;
        $line = fgets(STDIN);
        return $line === false ? '' : trim($line);
    }

    return '';
}

/**
 * Helper: Nap duong dan source da luu tu lan chay truoc.
 */
function loadSavedSourceDirectory(array &$config, string $runtimeConfigFile): void
{
    if (!file_exists($runtimeConfigFile)) {
        return;
    }

    $raw = @file_get_contents($runtimeConfigFile);
    if ($raw === false || trim($raw) === '') {
        return;
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return;
    }

    $savedPath = $data['sourcePath'] ?? ($data['autoZip']['sourcePath'] ?? null);
    if (!is_string($savedPath) || trim($savedPath) === '') {
        return;
    }

    $savedPath = str_replace('\\', '/', trim($savedPath));
    if (!is_dir($savedPath)) {
        echo "⚠️  Đường dẫn source đã lưu không còn tồn tại: {$savedPath}\n";
        return;
    }

    if (!isset($config['autoZip']) || !is_array($config['autoZip'])) {
        $config['autoZip'] = [];
    }

    $config['autoZip']['enabled'] = true;
    $config['autoZip']['sourcePath'] = $savedPath;
    echo "✅ Đã nạp đường dẫn source đã lưu: {$savedPath}\n";
}

/**
 * Helper: Luu duong dan source de tai su dung cho lan chay sau.
 */
function saveSourceDirectory(string $sourcePath, string $runtimeConfigFile): void
{
    $payload = [
        'sourcePath' => $sourcePath,
        'updatedAt' => date('c'),
    ];

    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        echo "⚠️  Không thể mã hóa JSON để lưu đường dẫn.\n";
        return;
    }

    if (@file_put_contents($runtimeConfigFile, $json) === false) {
        echo "⚠️  Không thể lưu đường dẫn vào: {$runtimeConfigFile}\n";
        return;
    }

    echo "💾 Đã lưu đường dẫn cho lần chạy sau.\n";
}

/**
 * Helper: Cho phep nhap lai duong dan thu muc source ngay khi chay script.
 */
function promptSourceDirectory(array &$config, string $runtimeConfigFile): void
{
    $currentSourcePath = $config['autoZip']['sourcePath'] ?? '';
    $autoZipEnabled = !empty($config['autoZip']['enabled']);

    echo "\n📁 Cấu hình đường dẫn thư mục source local\n";
    echo "   Đường dẫn hiện tại: {$currentSourcePath}\n";

    if ($autoZipEnabled) {
        $confirm = strtolower(readConsoleInput("Bạn có muốn nhập lại đường dẫn thư mục source? (y/N): "));
        if (!in_array($confirm, ['y', 'yes'], true)) {
            return;
        }
    } else {
        $confirm = strtolower(readConsoleInput("AutoZip đang tắt. Bật AutoZip và nhập đường dẫn thư mục? (y/N): "));
        if (!in_array($confirm, ['y', 'yes'], true)) {
            return;
        }
        $config['autoZip']['enabled'] = true;
    }

    while (true) {
        $inputPath = readConsoleInput("Nhập đường dẫn thư mục source: ");
        $inputPath = trim($inputPath, " \t\n\r\0\x0B\"'");

        if ($inputPath === '') {
            echo "❌ Đường dẫn trống, vui lòng nhập lại.\n";
            continue;
        }

        if (!is_dir($inputPath)) {
            echo "❌ Không tìm thấy thư mục: {$inputPath}\n";
            continue;
        }

        $resolvedPath = realpath($inputPath) ?: $inputPath;
        $config['autoZip']['sourcePath'] = str_replace('\\', '/', $resolvedPath);
        echo "✅ Đã cập nhật sourcePath: {$config['autoZip']['sourcePath']}\n";
        saveSourceDirectory($config['autoZip']['sourcePath'], $runtimeConfigFile);
        break;
    }
}

/**
 * Chuc nang 1: Don dep, upload, giai nen va cau hinh source code.
 */
function uploadAndConfigure(string $cpanel_host, string $securityToken, string $cookieFile, array $config)
{
    echo "\n==================================================\n";
    echo " BAT DAU TAC VU 1: UPLOAD VA CAU HINH SOURCE CODE \n";
    echo "==================================================\n";
    
    $targetDirectory = $config['targetDirectory'];
    $fileToEdit = $config['fileToEdit'];

    $autoZip = $config['autoZip'] ?? [];
    $localFileToUpload = $config['localFileToUpload'];
    $createdLocalZip = false;

    if (!empty($autoZip['enabled'])) {
        $sourcePath = $autoZip['sourcePath'] ?? '';
        $zipOutput = $autoZip['outputPath'] ?? null;
        $excludes = $autoZip['excludes'] ?? [];
        echo "📂 Bắt đầu nén thư mục source...\n";
        $localFileToUpload = createZipFromDirectory($sourcePath, $zipOutput, $excludes);
        $createdLocalZip = true;
        echo "📁 File zip: \033[1m{$localFileToUpload}\033[0m\n";
        if ($config['removeLocalZipAfterUpload'] ?? true) {
            register_shutdown_function(function () use ($localFileToUpload) {
                if (file_exists($localFileToUpload)) @unlink($localFileToUpload);
            });
        }
    }

    // --- DON DEP THU MUC CU ---
    echo "Dang don dep thu muc {$targetDirectory}...\n";
    $apiUrlJson = "https://{$cpanel_host}{$securityToken}/json-api/cpanel";
    $listParams = ['cpanel_jsonapi_module' => 'Fileman', 'cpanel_jsonapi_func' => 'listfiles', 'cpanel_jsonapi_apiversion' => '2', 'dir' => $targetDirectory, 'showdotfiles' => '1'];
    $listResult = sendApiRequest($apiUrlJson . '?' . http_build_query($listParams), $cookieFile, 'GET');

    $itemsToDelete = [];
    if (!empty($listResult['cpanelresult']['data'])) {
        foreach ($listResult['cpanelresult']['data'] as $item) {
            if (!is_array($item)) continue;
            $f = $item['file'] ?? '';
            $fp = $item['fullpath'] ?? '';
            if ($f !== '' && $f !== '.' && $f !== '..' && $fp !== '') $itemsToDelete[] = $fp;
        }
    }

    if (empty($itemsToDelete)) {
        echo "  📭 Thư mục đã trống.\n";
    } else {
        $delCount = count($itemsToDelete);
        echo "  🗑️  Đang xóa {$delCount} mục cũ...\n";

        // Xóa hàng loạt bằng API fileop với op=trash (nhanh hơn unlink từng cái)
        $batchSize = 100;
        for ($i = 0; $i < $delCount; $i += $batchSize) {
            $batch = array_slice($itemsToDelete, $i, $batchSize);
            $deletePostData = ['cpanel_jsonapi_module' => 'Fileman', 'cpanel_jsonapi_func' => 'fileop', 'cpanel_jsonapi_apiversion' => '2', 'op' => 'unlink', 'doubledecode' => '0'];
            $deleteDataString = http_build_query($deletePostData);
            foreach ($batch as $itemPath) {
                $deleteDataString .= '&sourcefiles=' . urlencode($itemPath);
            }
            $deleteResult = sendApiRequest($apiUrlJson, $cookieFile, 'POST', [], $deleteDataString);
            $done = min($i + $batchSize, $delCount);
            progressBar($done, $delCount, 40, "đã xóa {$done}/{$delCount}");
        }
        echo "\n  ✅ Dọn dẹp hoàn tất (đã xóa {$delCount} mục).\n";
    }

    // --- UPLOAD, GIẢI NÉN, CẤU HÌNH ---
    if (!file_exists($localFileToUpload)) die("❌ Lỗi: Không tìm thấy file '{$localFileToUpload}'.\n");
    $uploadData = uploadFileToCpanel($cpanel_host, $securityToken, $cookieFile, $targetDirectory, $localFileToUpload);

    echo "  📦 Đang giải nén trên server...\n";
    $serverFilePath = $targetDirectory . '/' . basename($localFileToUpload);
    $extractPostData = ['cpanel_jsonapi_module' => 'Fileman', 'cpanel_jsonapi_func' => 'fileop', 'cpanel_jsonapi_apiversion' => '2', 'op' => 'extract', 'sourcefiles' => $serverFilePath, 'destfiles' => $targetDirectory];
    $extractResult = sendApiRequest($apiUrlJson, $cookieFile, 'POST', [], $extractPostData);
    if (!$extractResult || !empty($extractResult['cpanelresult']['error'])) die("❌ Lỗi khi giải nén: " . ($extractResult['cpanelresult']['error'] ?? 'Unknown error'));
    echo "  ✅ Giải nén thành công!\n";

    echo "  ⚙️  Đang cấu hình {$fileToEdit}...\n";
    $uapi_url = "https://{$cpanel_host}{$securityToken}/execute/Fileman/get_file_content";
    $getContentResult = sendApiRequest($uapi_url, $cookieFile, 'GET', [], ['dir' => dirname($targetDirectory . $fileToEdit), 'file' => basename($fileToEdit)], true);
    if (!$getContentResult || !$getContentResult['status']) die("Lỗi đọc file .env: " . ($getContentResult['errors'][0] ?? 'Unknown error'));
    
    $currentContent = $getContentResult['data']['content'];
    $replacements = [
        'DB_HOST'     => $config['db_host'],
        'DB_DATABASE' => $config['database_name'],
        'DB_USERNAME' => $config['db_user'],
        'DB_PASSWORD' => $config['db_pass'],
    ];
    $lines = explode("\n", $currentContent);
    $newLines = [];
    $updatedKeys = [];
    foreach ($lines as $line) {
        $found = false;
        foreach ($replacements as $key => $value) {
            if (preg_match('/^\s*' . preg_quote($key, '/') . '\s*=/i', $line)) {
                $newLines[] = "{$key}={$value}"; $updatedKeys[$key] = true; $found = true; break;
            }
        }
        if (!$found) $newLines[] = $line;
    }
    foreach ($replacements as $key => $value) {
        if (!isset($updatedKeys[$key])) $newLines[] = "{$key}={$value}";
    }
    $newContent = implode("\n", $newLines);
    $savePostData = ['cpanel_jsonapi_apiversion' => '2', 'cpanel_jsonapi_module' => 'Fileman', 'cpanel_jsonapi_func' => 'savefile', 'dir' => dirname($targetDirectory . $fileToEdit), 'filename' => basename($fileToEdit), 'content' => $newContent];
    $saveResult = sendApiRequest($apiUrlJson, $cookieFile, 'POST', [], $savePostData);
    if (!$saveResult || !empty($saveResult['cpanelresult']['error'])) die("Lỗi lưu file .env: " . ($saveResult['cpanelresult']['error'] ?? 'Unknown error'));
    echo "  ✅ Cấu hình .env thành công!\n";

    echo "  🧹 Đang dọn dẹp file zip trên server...\n";
    $cleanupPostData = ['cpanel_jsonapi_module' => 'Fileman', 'cpanel_jsonapi_func' => 'fileop', 'cpanel_jsonapi_apiversion' => '2', 'op' => 'unlink', 'sourcefiles' => $serverFilePath];
    sendApiRequest($apiUrlJson, $cookieFile, 'POST', [], $cleanupPostData);
    echo "  ✅ Đã dọn dẹp.\n";

    if ($createdLocalZip && ($config['removeLocalZipAfterUpload'] ?? true) && file_exists($localFileToUpload)) {
        unlink($localFileToUpload);
        echo "  🗑️  Đã xóa file zip tạm local.\n";
    }
    echo "\n  \033[1;32m✔ HOÀN TẤT BƯỚC 1\033[0m\n";
}

function createDatabaseAndUser(string $cpanel_host, string $securityToken, string $cookieFile, array $config)
{
    echo "\n=================================================\n";
    echo " BẮT ĐẦU TÁC VỤ 2: TẠO DATABASE VÀ USER \n";
    echo "=================================================\n";
    $apiUrl = "https://{$cpanel_host}{$securityToken}/execute/";

    echo "🚀 Đang tạo database '{$config['database_name']}'...\n";
    $createDbResult = sendApiRequest($apiUrl . 'Mysql/create_database', $cookieFile, 'GET', [], ['name' => $config['database_name']], true);
    if ($createDbResult && $createDbResult['status']) echo "✅ Tạo database thành công.\n";
    elseif (strpos($createDbResult['errors'][0] ?? '', 'already exists') !== false) echo "⚠️  Database đã tồn tại, bỏ qua.\n";
    else die("❌ Lỗi tạo database: " . ($createDbResult['errors'][0] ?? 'Unknown error'));

    echo "🚀 Đang tạo user '{$config['db_user']}'...\n";
    $createUserResult = sendApiRequest($apiUrl . 'Mysql/create_user', $cookieFile, 'GET', [], ['name' => $config['db_user'], 'password' => $config['db_pass']], true);
    if ($createUserResult && $createUserResult['status']) echo "✅ Tạo user thành công.\n";
    elseif (strpos($createUserResult['errors'][0] ?? '', 'already exists') !== false) echo "⚠️  User đã tồn tại, bỏ qua.\n";
    else die("❌ Lỗi tạo user: " . ($createUserResult['errors'][0] ?? 'Unknown error'));

    echo "🚀 Đang gán quyền (dùng phpMyAdmin SQL)...\n";

    // Lấy phpMyAdmin token (giống ở bước 3)
    $pmaBaseUrl = "https://{$cpanel_host}{$securityToken}/3rdparty/phpMyAdmin/index.php?route=/database/sql&db={$config['database_name']}";
    $initialPageContent = sendBrowserRequest($pmaBaseUrl, $cookieFile);
    $pmaToken = null;

    $initialData = json_decode($initialPageContent, true);
    if (json_last_error() === JSON_ERROR_NONE && !empty($initialData['params']['token'])) {
        $pmaToken = $initialData['params']['token'];
    } else {
        preg_match('/<input type="hidden" name="token" value="([a-f0-9]{32})">/', $initialPageContent, $matches);
        if (!empty($matches[1])) $pmaToken = $matches[1];
    }
    if (empty($pmaToken)) {
        echo "⚠️  Không lấy được token phpMyAdmin, thử dùng raw SQL grant...\n";
        goto pma_fallback;
    }
    echo "   -> Lấy token phpMyAdmin thành công: {$pmaToken}\n";

    // Chạy câu lệnh GRANT qua phpMyAdmin SQL
    $grantQueries = [
        "GRANT ALL PRIVILEGES ON `{$config['database_name']}`.* TO `{$config['db_user']}`@`localhost`",
        "FLUSH PRIVILEGES",
    ];

    $pmaSqlUrl = "https://{$cpanel_host}{$securityToken}/3rdparty/phpMyAdmin/index.php?route=/import";
    foreach ($grantQueries as $sql) {
        echo "   Đang thực thi: {$sql}\n";
        $postData = [
            'db' => $config['database_name'],
            'token' => $pmaToken,
            'sql_query' => $sql,
            'ajax_request' => 'true',
        ];
        $resp = sendBrowserRequest($pmaSqlUrl, $cookieFile, 'POST', [
            'X-Requested-With: XMLHttpRequest',
            'Referer: ' . $pmaBaseUrl,
            'Content-Type: application/x-www-form-urlencoded',
        ], http_build_query($postData));
    }

    echo "✅ Gán quyền hoàn tất (GRANT đã được thực thi qua phpMyAdmin).\n";
    return;

    pma_fallback:
    // Fallback: thử UAPI lần cuối
    echo "🚀 Thử UAPI set_privileges lần cuối...\n";
    $result = sendApiRequest($apiUrl . 'Mysql/set_privileges_on_database', $cookieFile, 'GET', [], [
        'user' => $config['db_user'],
        'database' => $config['database_name'],
        'privileges' => 'ALL PRIVILEGES',
    ], true);
    if ($result && !empty($result['status'])) {
        echo "✅ Gán quyền thành công.\n";
    } else {
        echo "⚠️  Không thể gán quyền tự động. Vui lòng vào cPanel > MySQL Databases để gán thủ công.\n";
        echo "   Database: {$config['database_name']}, User: {$config['db_user']}\n";
    }
}

/**
 * Chức năng 3: Xóa sạch bảng và import file .sql mới (LOGIC MỚI).
 */
function resetAndImportDatabase(string $cpanel_host, string $securityToken, string $cookieFile, array $config) {
    echo "\n=================================================\n";
    echo " BẮT ĐẦU TÁC VỤ 3: RESET & IMPORT DATABASE \n";
    echo "=================================================\n";
    $database_name = $config['database_name'];
    $localSqlFile = resolveLocalSqlFilePath($config);

    echo "🚀 Đang lấy danh sách các bảng và token từ phpMyAdmin...\n";
    $pmaBaseUrl = "https://{$cpanel_host}{$securityToken}/3rdparty/phpMyAdmin/index.php?route=/database/structure&db={$database_name}";
    $initialPageContent = sendBrowserRequest($pmaBaseUrl, $cookieFile);
    $pmaToken = null;
    $htmlContent = '';
    
    $initialData = json_decode($initialPageContent, true);
    if (json_last_error() === JSON_ERROR_NONE && !empty($initialData['params']['token'])) {
        $pmaToken = $initialData['params']['token'];
        $htmlContent = $initialData['message'] ?? '';
    } else {
        preg_match('/<input type="hidden" name="token" value="([a-f0-9]{32})">/', $initialPageContent, $matches);
        if (!empty($matches[1])) $pmaToken = $matches[1];
        $htmlContent = $initialPageContent;
    }
    if (empty($pmaToken)) die("❌ Lỗi: Không tìm thấy phpMyAdmin CSRF token.");
    echo "   -> Lấy token thành công: {$pmaToken}\n";

    $tablesToDelete = [];
    if (!empty($htmlContent)) {
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $htmlContent);
        $xpath = new DOMXPath($dom);
        $rows = $xpath->query("//tr[starts-with(@id, 'row_tbl_')]");
        foreach ($rows as $row) {
            $tableNameNode = $xpath->query("th/a", $row);
            if ($tableNameNode->length > 0) $tablesToDelete[] = '`' . trim($tableNameNode[0]->nodeValue) . '`';
        }
    }

    if (empty($tablesToDelete)) {
        echo "✅ Database không có bảng nào để xóa.\n\n";
    } else {
        echo "✅ Đã lấy được danh sách " . count($tablesToDelete) . " bảng!\n\n";
        echo "🔎 Tìm thấy " . count($tablesToDelete) . " bảng cần xóa.\n";
    
        echo "🚀 Đang xóa bảng...\n";
        
        $tempSqlFile = __DIR__ . '/temp_drop_tables.sql';
        $dropQuery = "SET FOREIGN_KEY_CHECKS=0;\nDROP TABLE IF EXISTS " . implode(', ', $tablesToDelete) . ";\nSET FOREIGN_KEY_CHECKS=1;";
        file_put_contents($tempSqlFile, $dropQuery);
    
        $pmaImportUrl = "https://{$cpanel_host}{$securityToken}/3rdparty/phpMyAdmin/index.php?route=/import";
        
        // ✅ SỬA LỖI: Bổ sung đầy đủ các trường dữ liệu POST để giả lập form hợp lệ
        $importDropPostData = [
            'import_type'     => 'database', 'db' => $database_name, 'token' => $pmaToken,
            'MAX_FILE_SIZE'   => '536870912', 'charset_of_file' => 'utf-8', 
            'allow_interrupt' => 'yes', 'skip_queries' => '0', 'fk_checks' => '0', 'format' => 'sql',
            'import_file'     => new CURLFile(realpath($tempSqlFile), 'application/sql', basename($tempSqlFile)),
        ];
        
        $dropHeaders = [ 'X-Requested-With: XMLHttpRequest', 'Referer: ' . $pmaBaseUrl ];
        $response = sendBrowserRequest($pmaImportUrl, $cookieFile, 'POST', $dropHeaders, $importDropPostData);
        unlink($tempSqlFile);

        if (strpos($response, 'Import has been successfully finished') !== false) {
            echo "✅ Đã xóa tất cả các bảng thành công.\n\n";
        } else {
            $data = json_decode($response, true);
            $errorMessage = $data['error'] ?? 'Unknown error';
            die("❌ Lỗi khi xóa bảng. Phản hồi: " . $errorMessage . "\n---\nRaw Response (first 1000 chars):\n" . substr($response, 0, 1000));
        }
    }

    if (!file_exists($localSqlFile)) die("❌ Lỗi: Không tìm thấy file SQL. Đường dẫn hiện tại: '{$localSqlFile}'.\n");
    echo "🚀 Đang import dữ liệu từ '{$localSqlFile}'...\n";
    $pmaImportUrl = "https://{$cpanel_host}{$securityToken}/3rdparty/phpMyAdmin/index.php?route=/import";
    
    // ✅ ĐỒNG BỘ: Sử dụng đầy đủ các trường POST cho cả thao tác import chính
    $importMainPostData = [
        'import_type'     => 'database', 'db' => $database_name, 'token' => $pmaToken,
        'MAX_FILE_SIZE'   => '536870912', 'charset_of_file' => 'utf-8', 'allow_interrupt' => 'yes',
        'skip_queries'    => '0', 'fk_checks' => '0', 'format' => 'sql',
        'import_file'     => new CURLFile(realpath($localSqlFile), 'application/octet-stream', basename($localSqlFile)),
    ];
    $importHeaders = [
        'X-Requested-With: XMLHttpRequest',
        'Referer: https://' . $cpanel_host . $securityToken . '/3rdparty/phpMyAdmin/index.php?route=/database/import&db=' . $database_name,
    ];
    $importResponse = sendBrowserRequest($pmaImportUrl, $cookieFile, 'POST', $importHeaders, $importMainPostData);

    if (strpos($importResponse, 'Import has been successfully finished') !== false) {
        echo "✅ Import database hoàn tất.\n";
    } else {
        $importData = json_decode($importResponse, true);
        $errorMessage = $importData['error'] ?? 'Không nhận được thông báo thành công.';
        if(isset($importData['message'])) $errorMessage .= ' Message: ' . strip_tags($importData['message']);
        die("❌ Lỗi khi import database. Phản hồi: " . $errorMessage . "\n---\nRaw Response (first 1000 chars):\n" . substr($importResponse, 0, 1000));
    }
}


// ================================= //
// --- PHẦN THỰC THI KỊCH BẢN ---
// ================================= //

$runtimeConfigFile = __DIR__ . '/deploy_runtime.json';
loadSavedSourceDirectory($config, $runtimeConfigFile);

$loginUrl = "https://{$cpanel_host}/login/?login_only=1";
$cookieFile = __DIR__ . '/cookie_main.txt';
if (file_exists($cookieFile)) unlink($cookieFile);

echo "🚀 Bước 1: Đang đăng nhập vào cPanel...\n";
$loginResult = sendApiRequest($loginUrl, $cookieFile, 'POST', [], ['user' => $cpanel_user, 'pass' => $cpanel_pass]);
if (!$loginResult || !isset($loginResult['status']) || $loginResult['status'] != 1) {
    die("❌ Đăng nhập thất bại. Phản hồi: " . json_encode($loginResult));
}
$securityToken = $loginResult['security_token'];
echo "✅ Đăng nhập thành công!\n\n";

// --- HIỂN THỊ MENU LỰA CHỌN ---
while (true) {
    echo "================ MENU ================\n";
    echo "  1. Upload & Cấu hình source + DB\n";
    echo "  2. Chỉ Tạo Database & User\n";
    echo "  3. Chỉ Reset & Import Database\n";
    echo "  4. 🔥 TRIỂN KHAI ĐẦY ĐỦ (1 + 2 + 3)\n";
    echo "  5. 📦 Chỉ Upload source (giữ nguyên DB .env)\n";
    echo "  0. Thoát\n";
    echo "======================================\n";
    $choice = readline("Vui lòng chọn chức năng: ");

    switch ($choice) {
        case '1':
            promptSourceDirectory($config, $runtimeConfigFile);
            uploadAndConfigure($cpanel_host, $securityToken, $cookieFile, $config);
            break 2;
        case '2':
            createDatabaseAndUser($cpanel_host, $securityToken, $cookieFile, $config);
            break 2;
        case '3':
            resetAndImportDatabase($cpanel_host, $securityToken, $cookieFile, $config);
            break 2;
        case '4':
            promptSourceDirectory($config, $runtimeConfigFile);
            uploadAndConfigure($cpanel_host, $securityToken, $cookieFile, $config, false);
            createDatabaseAndUser($cpanel_host, $securityToken, $cookieFile, $config);
            resetAndImportDatabase($cpanel_host, $securityToken, $cookieFile, $config);
            break 2;
        case '5':
            promptSourceDirectory($config, $runtimeConfigFile);
            uploadAndConfigure($cpanel_host, $securityToken, $cookieFile, $config, false);
            break 2;
        case '0':
            echo "Đã thoát chương trình.\n";
            exit;
        default:
            echo "\n❌ Lựa chọn không hợp lệ. Vui lòng nhập lại.\n\n";
    }
}

echo "\n🎉 Kịch bản đã hoàn thành!\n";
if (file_exists($cookieFile)) unlink($cookieFile);

?>





