<?php

// =================================================================================
// === KỊCH BẢN TỰ ĐỘNG XÓA VÀ IMPORT DATABASE (PHIÊN BẢN CUỐI CÙNG) ==============
// =================================================================================
// Phương pháp: Tạo file SQL tạm thời để tắt khóa ngoại và xóa bảng trong cùng 1 lần upload.
// Đây là giải pháp mạnh mẽ nhất để xử lý các ràng buộc phức tạp.
// =================================================================================

error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(900); // Tăng thời gian chạy lên 15 phút cho các tác vụ lớn

// --- PHẦN CẤU HÌNH ---
$cpanel_host = 'your_cpanel_host.com:2083'; // VD: yourdomain.com:2083
$cpanel_user = 'your_cpanel_username';
$cpanel_pass = 'your_cpanel_password';
$database_name = $cpanel_user . '_dbname';
// Đường dẫn tuyệt đối đến file backup .sql trên máy của bạn
$localSqlFile = 'C:/path/to/your/database-backup.sql';

// --- KHỞI TẠO ---
$loginUrl   = "https://{$cpanel_host}/login/?login_only=1";
$cookieFile = __DIR__ . '/cookie_db.txt';
if (file_exists($cookieFile)) {
    unlink($cookieFile);
}

/**
 * Hàm gửi yêu cầu cURL.
 */
function sendCurlRequest(string $url, string $cookieFile, string $method = 'GET', array $headers = [], $data = null)
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
        if ($isMultipart) {
            $postFields = $data;
        } elseif (is_array($data)) {
            $postFields = http_build_query($data);
        } else {
            $postFields = $data;
        }
    }
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => false,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_HTTPHEADER     => $finalHeaders,
        CURLOPT_POSTFIELDS     => $postFields,
        CURLOPT_TIMEOUT        => 900,
        CURLOPT_COOKIEJAR      => $cookieFile,
        CURLOPT_COOKIEFILE     => $cookieFile,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return $response;
}

// --- BƯỚC 1: ĐĂNG NHẬP ---
echo "🚀 Bước 1: Đang đăng nhập vào cPanel '{$cpanel_host}'...\n";
$loginResponse = sendCurlRequest($loginUrl, $cookieFile, 'POST', [], ['user' => $cpanel_user, 'pass' => $cpanel_pass]);
$loginData = json_decode($loginResponse, true);
if (empty($loginData['status'])) die("❌ Đăng nhập thất bại. Phản hồi: " . $loginResponse);
$securityToken = $loginData['security_token'];
echo "✅ Đăng nhập thành công!\n\n";

// --- BƯỚC 2: LẤY DANH SÁCH BẢNG VÀ TOKEN ---
echo "🚀 Bước 2: Đang lấy danh sách các bảng và token từ phpMyAdmin...\n";
$pmaBaseUrl = "https://{$cpanel_host}{$securityToken}/3rdparty/phpMyAdmin/index.php?route=/database/structure&db={$database_name}";
$initialPageContent = sendCurlRequest($pmaBaseUrl, $cookieFile);
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
    echo "✅ Database không có bảng nào.\n\n";
} else {
    echo "✅ Đã lấy được danh sách " . count($tablesToDelete) . " bảng!\n\n";
}

// --- BƯỚC 3: XÓA BẢNG BẰNG CÁCH UPLOAD FILE SQL TẠM ---
if (!empty($tablesToDelete)) {
    echo "🔎 Tìm thấy " . count($tablesToDelete) . " bảng cần xóa.\n";
    echo "--> Nhấn [Enter] để tạo và upload file SQL xóa bảng...\n";
    fgets(STDIN);

    echo "🚀 Bước 3: Đang xóa bảng...\n";
    
    // 3.1: Tạo nội dung và file SQL tạm
    $tempSqlFile = __DIR__ . '/temp_drop_tables.sql';
    $dropQuery = "SET FOREIGN_KEY_CHECKS=0;\nDROP TABLE IF EXISTS " . implode(', ', $tablesToDelete) . ";\nSET FOREIGN_KEY_CHECKS=1;";
    file_put_contents($tempSqlFile, $dropQuery);
    echo "   -> Đã tạo file SQL tạm: {$tempSqlFile}\n";
    echo "   -> Nội dung:\n{$dropQuery}\n";

    // 3.2: Upload file SQL tạm này
    $pmaImportUrl = "https://{$cpanel_host}{$securityToken}/3rdparty/phpMyAdmin/index.php?route=/import";
    $importPostData = [
        'import_type'     => 'database', 'db' => $database_name, 'token' => $pmaToken,
        'MAX_FILE_SIZE'   => '536870912', 'charset_of_file' => 'utf-8', 
        'allow_interrupt' => 'yes', 'skip_queries' => '0', 
        'fk_checks'       => '0', // Rất quan trọng: Báo cho PMA tắt kiểm tra khóa ngoại khi import
        'format'          => 'sql',
        'import_file'     => new CURLFile(realpath($tempSqlFile), 'application/sql', basename($tempSqlFile)),
    ];
    $importHeaders = [
        'Origin: https://' . $cpanel_host,
        'Referer: ' . $pmaBaseUrl
    ];
    $response = sendCurlRequest($pmaImportUrl, $cookieFile, 'POST', $importHeaders, $importPostData);
    
    // 3.3: Xóa file SQL tạm
    unlink($tempSqlFile);
    echo "   -> Đã xóa file SQL tạm.\n";

    if (strpos($response, 'Import has been successfully finished') !== false) {
        echo "✅✅✅ THÀNH CÔNG! Đã xóa tất cả các bảng.\n\n";
    } else {
        $data = json_decode($response, true);
        $errorMessage = $data['error'] ?? $response;
        die("❌❌❌ THẤT BẠI! Không thể xóa bảng. Phản hồi: " . $errorMessage);
    }
}

// --- BƯỚC 4: IMPORT DỮ LIỆU TỪ FILE .SQL CHÍNH ---
if (!file_exists($localSqlFile)) {
    die("❌ Lỗi: Không tìm thấy file '{$localSqlFile}' để import.\n");
}
echo "🚀 Bước 4: Đang import dữ liệu từ file '{$localSqlFile}'...\n";

$pmaImportUrl = "https://{$cpanel_host}{$securityToken}/3rdparty/phpMyAdmin/index.php?route=/import";
$importPostData = [
    'import_type'     => 'database', 'db' => $database_name, 'token' => $pmaToken,
    'MAX_FILE_SIZE'   => '536870912', 'charset_of_file' => 'utf-8', 'allow_interrupt' => 'yes',
    'skip_queries'    => '0', 'fk_checks' => '0', 'format' => 'sql',
    'import_file'     => new CURLFile(realpath($localSqlFile), 'application/octet-stream', basename($localSqlFile)),
];
$importHeaders = [
    'X-Requested-With: XMLHttpRequest', 'Origin: https://' . $cpanel_host,
    'Referer: https://' . $cpanel_host . $securityToken . '/3rdparty/phpMyAdmin/index.php?route=/database/import&db=' . $database_name,
];
$importResponse = sendCurlRequest($pmaImportUrl, $cookieFile, 'POST', $importHeaders, $importPostData);

if (strpos($importResponse, 'Import has been successfully finished') !== false) {
    echo "✅✅✅ THÀNH CÔNG! Import database hoàn tất.\n";
} else {
    $importData = json_decode($importResponse, true);
    $errorMessage = $importData['error'] ?? 'Không nhận được thông báo thành công từ phpMyAdmin.';
    if(isset($importData['message'])) $errorMessage .= ' Message: ' . $importData['message'];
    die("❌ Lỗi khi import database. Phản hồi: " . $errorMessage);
}
echo "\n🎉 Kịch bản quản lý database đã hoàn thành!\n";

?>