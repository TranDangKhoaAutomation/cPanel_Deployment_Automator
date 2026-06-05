<?php

/**
 * Hàm gửi yêu cầu cURL, hỗ trợ cả data thường và upload file.
 */
function sendCurlRequest(string $url, string $cookieFile, string $method = 'GET', array $headers = [], $data = null)
{
    $ch = curl_init();
    if ($ch === false) {
        error_log('Lỗi: Không thể khởi tạo cURL.');
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

    $postFields = $isUpload ? $data : (is_array($data) ? http_build_query($data) : $data);

    if ($isUpload) {
        foreach ($headers as $i => $header) {
            if (stripos($header, 'Content-Type:') === 0) {
                unset($headers[$i]);
            }
        }
    }

    $options = [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_HTTPHEADER     => array_values($headers),
        CURLOPT_POSTFIELDS     => (strtoupper($method) === 'POST') ? $postFields : null,
        CURLOPT_TIMEOUT        => 600,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_COOKIEJAR      => $cookieFile,
        CURLOPT_COOKIEFILE     => $cookieFile,
    ];

    curl_setopt_array($ch, $options);
    $response = curl_exec($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    if (curl_errno($ch)) {
        error_log('Lỗi cURL: ' . curl_error($ch));
        curl_close($ch);
        return false;
    }
    curl_close($ch);
    return ['response' => $response, 'header_size' => $headerSize];
}

// --- Thông tin đăng nhập cPanel ---
$cpanel_host = 'your_cpanel_host.com:2083'; // VD: yourdomain.com:2083
$cpanel_user = 'your_cpanel_username';
$cpanel_pass = 'your_cpanel_password';

$targetDirectory = '/home/' . $cpanel_user . '/public_html';
$localFileToUpload = 'C:/path/to/your/source-code.zip';

// --- CẤU HÌNH CHỈNH SỬA FILE ---
// ✅ MỚI: Chỉ định file cần sửa, ví dụ: '/.env' hoặc '/config/app.env'
$fileToEdit = '/.env'; 

// Các giá trị mới sẽ được ghi vào file .env
$new_db_host = 'localhost';
$new_db_user = $cpanel_user . '_dbname';
$new_db_pass = $cpanel_user . '_dbuser'; // Thay bằng mật khẩu database mới
$new_db_name = 'VeryStrongPassword123';


$loginUrl = "https://{$cpanel_host}/login/?login_only=1";
$cookieFile = __DIR__ . '/cookie.txt';

// --- BƯỚC 1: ĐĂNG NHẬP ---
echo "🚀 Bước 1: Đang đăng nhập...\n";
$loginResult = sendCurlRequest($loginUrl, $cookieFile, 'POST', [], ['user' => $cpanel_user, 'pass' => $cpanel_pass]);
if ($loginResult === false) die("❌ Đăng nhập thất bại (request không thành công).");
$loginResponseBody = substr($loginResult['response'], $loginResult['header_size']);
$loginBodyJson = json_decode($loginResponseBody, true);
if (!isset($loginBodyJson['status']) || $loginBodyJson['status'] != 1) die("❌ Logic đăng nhập thất bại. Phản hồi từ server: " . $loginResponseBody);
$securityToken = $loginBodyJson['security_token'];
echo "✅ Đăng nhập thành công!\n\n";

// --- BƯỚC 2 & 3: DỌN DẸP THƯ MỤC CŨ ---
echo "🚀 Bước 2 & 3: Đang dọn dẹp thư mục {$targetDirectory}...\n";
$listParams = ['cpanel_jsonapi_module' => 'Fileman', 'cpanel_jsonapi_func' => 'listfiles', 'cpanel_jsonapi_apiversion' => '2', 'dir' => $targetDirectory, 'showdotfiles' => '1'];
$apiUrlWithParams = "https://{$cpanel_host}{$securityToken}/json-api/cpanel?" . http_build_query($listParams);
$listResult = sendCurlRequest($apiUrlWithParams, $cookieFile, 'GET');
if ($listResult === false) die("❌ Không thể lấy danh sách file (request không thành công).");
$listBodyJson = json_decode(substr($listResult['response'], $listResult['header_size']), true);
$itemsToDelete = [];
if (!empty($listBodyJson['cpanelresult']['data'])) {
    foreach ($listBodyJson['cpanelresult']['data'] as $item) {
        if ($item['file'] !== '.' && $item['file'] !== '..') $itemsToDelete[] = $item['fullpath'];
    }
}

if (empty($itemsToDelete)) {
    echo "✅ Thư mục đã trống, không có gì để xóa.\n\n";
} else {
    echo "🔎 Tìm thấy " . count($itemsToDelete) . " mục cần xóa. Bắt đầu xóa...\n";
    $apiUrl = "https://{$cpanel_host}{$securityToken}/json-api/cpanel";
    $deletePostData = ['cpanel_jsonapi_module' => 'Fileman', 'cpanel_jsonapi_func' => 'fileop', 'cpanel_jsonapi_apiversion' => '2', 'op' => 'unlink', 'doubledecode' => '0'];
    $deleteDataString = http_build_query($deletePostData);
    foreach ($itemsToDelete as $itemPath) $deleteDataString .= '&sourcefiles=' . urlencode($itemPath);
    $deleteResult = sendCurlRequest($apiUrl, $cookieFile, 'POST', [], $deleteDataString);
    if ($deleteResult !== false) {
        $deleteBodyJson = json_decode(substr($deleteResult['response'], $deleteResult['header_size']), true);
        if (!empty($deleteBodyJson['cpanelresult']['error'])) die("❌ Lỗi khi thực hiện xóa: " . $deleteBodyJson['cpanelresult']['error']);
        echo "✅ Dọn dẹp thành công.\n\n";
    } else {
        die("❌ Gửi yêu cầu xóa tới server thất bại.\n");
    }
}

// --- BƯỚC 4: UPLOAD FILE MỚI LÊN ---
if (!file_exists($localFileToUpload)) die("❌ Lỗi: Không tìm thấy file '{$localFileToUpload}' để upload.\n");
echo "🚀 Bước 4: Đang upload file '{$localFileToUpload}'...\n";
$uploadUrl = "https://{$cpanel_host}{$securityToken}/execute/Fileman/upload_files";
$uploadHeaders = ['Referer: https://' . $cpanel_host . $securityToken . '/frontend/jupiter/filemanager/index.html', 'User-Agent: Mozilla/5.0', 'Origin: https://' . $cpanel_host];
$uploadPostData = ['get_disk_info' => '1', 'dir' => $targetDirectory, 'file-0' => new CURLFile(realpath($localFileToUpload)), 'overwrite' => '1'];
$uploadResult = sendCurlRequest($uploadUrl, $cookieFile, 'POST', $uploadHeaders, $uploadPostData);
$uploadSuccess = false;
if ($uploadResult !== false) {
    $uploadBody = substr($uploadResult['response'], $uploadResult['header_size']);
    $uploadData = json_decode($uploadBody, true);
    if ($uploadData && empty($uploadData['errors']) && isset($uploadData['data']['succeeded']) && $uploadData['data']['succeeded'] > 0) {
        echo "✅ Upload file thành công!\n\n";
        $uploadSuccess = true;
    } else {
        echo "❌ Upload thất bại. Phản hồi từ server:\n";
        echo $uploadBody;
        die();
    }
} else {
    die("❌ Gửi yêu cầu upload thất bại.\n");
}

// --- CÁC BƯỚC CUỐI CHỈ CHẠY KHI UPLOAD THÀNH CÔNG ---
if ($uploadSuccess) {
    $apiUrl = "https://{$cpanel_host}{$securityToken}/json-api/cpanel";
    $serverFilePath = $targetDirectory . '/' . basename($localFileToUpload);

    // --- BƯỚC 5: GIẢI NÉN FILE VỪA UPLOAD ---
    echo "🚀 Bước 5: Đang giải nén file trên server...\n";
    $extractPostData = ['cpanel_jsonapi_module' => 'Fileman', 'cpanel_jsonapi_func' => 'fileop', 'cpanel_jsonapi_apiversion' => '2', 'op' => 'extract', 'sourcefiles' => $serverFilePath, 'destfiles' => $targetDirectory];
    $extractResult = sendCurlRequest($apiUrl, $cookieFile, 'POST', [], $extractPostData);
    if ($extractResult !== false) {
        $extractBodyJson = json_decode(substr($extractResult['response'], $extractResult['header_size']), true);
        if (!empty($extractBodyJson['cpanelresult']['error'])) die("❌ Lỗi khi giải nén file: " . $extractBodyJson['cpanelresult']['error'] . "\n");
        echo "✅ Giải nén file thành công!\n\n";

        // --- BƯỚC 5.5: CHỈNH SỬA FILE .ENV ---
        $fullPathToFile = $targetDirectory . $fileToEdit;
        $fileDir = dirname($fullPathToFile);
        $fileName = basename($fullPathToFile);

        echo "🚀 Bước 5.5: Bắt đầu chỉnh sửa file {$fileName}...\n";

        // 5.5.1 Lấy nội dung file hiện tại
        echo "    (1/3) Đang đọc nội dung file...\n";
        $getContentUrl = "https://{$cpanel_host}{$securityToken}/frontend/jupiter/filemanager/editit.html?file=" . urlencode($fileName) . "&dir=" . urlencode($fileDir) . "&charset=utf-8&edit=1";
        $getContentResult = sendCurlRequest($getContentUrl, $cookieFile, 'GET');
        if ($getContentResult === false) die("    ❌ Lỗi: Không thể gửi yêu cầu đọc file.\n");
        $htmlBody = substr($getContentResult['response'], $getContentResult['header_size']);
        if (!preg_match('/var file_content = "(.*?)";/s', $htmlBody, $matches)) die("    ❌ Lỗi: Không tìm thấy nội dung file trong phản hồi từ server.\n");
        
        $currentContentEncoded = $matches[1];
        $tempContent = preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', function ($match) { return mb_convert_encoding(pack('H*', $match[1]), 'UTF-8', 'UCS-2BE'); }, $currentContentEncoded);
        $currentContent = stripcslashes($tempContent);
        if ($currentContent === false || $currentContent === null) die("    ❌ Lỗi: Không thể giải mã nội dung file.\n");
        echo "    ✅ Đọc và giải mã file thành công.\n";

        // 5.5.2 Cập nhật nội dung theo định dạng .env
        echo "    (2/3) Đang cập nhật thông tin database...\n";
        $replacements = [
            'DB_HOST'     => $new_db_host,
            'DB_DATABASE' => $new_db_name,
            'DB_USERNAME' => $new_db_user,
            'DB_PASSWORD' => $new_db_pass,
        ];
        
        $lines = explode("\n", $currentContent);
        $newLines = [];
        $updatedKeys = [];

        foreach ($lines as $line) {
            $found = false;
            foreach ($replacements as $key => $value) {
                if (preg_match('/^\s*' . preg_quote($key, '/') . '\s*=/i', $line)) {
                    $newLines[] = "{$key}={$value}";
                    $updatedKeys[$key] = true;
                    $found = true;
                    break;
                }
            }
            if (!$found) $newLines[] = $line;
        }

        foreach ($replacements as $key => $value) {
            if (!isset($updatedKeys[$key])) {
                $newLines[] = "{$key}={$value}";
                echo "        ✓ Đã thêm mới khóa {$key}.\n";
            } else {
                 echo "        ✓ Đã cập nhật khóa {$key}.\n";
            }
        }
        
        $newContent = implode("\n", $newLines);
        
        // 5.5.3 Lưu lại file
        echo "    (3/3) Đang lưu file đã chỉnh sửa...\n";
        $savePostData = ['cpanel_jsonapi_apiversion' => '2', 'cpanel_jsonapi_module' => 'Fileman', 'cpanel_jsonapi_func' => 'savefile', 'dir' => $fileDir, 'filename' => $fileName, 'content' => $newContent, 'charset' => 'utf-8'];
        $saveResult = sendCurlRequest($apiUrl, $cookieFile, 'POST', [], $savePostData);
        if ($saveResult !== false) {
             $saveBodyJson = json_decode(substr($saveResult['response'], $saveResult['header_size']), true);
             if (!empty($saveBodyJson['cpanelresult']['error'])) die("    ❌ Lỗi khi lưu file: " . $saveBodyJson['cpanelresult']['error'] . "\n");
             echo "✅ Chỉnh sửa và lưu file {$fileName} thành công!\n\n";
        } else {
             die("    ❌ Gửi yêu cầu lưu file tới server thất bại.\n");
        }

        // --- BƯỚC 6: DỌN DẸP (XÓA FILE .ZIP) ---
        echo "🚀 Bước 6: Đang dọn dẹp (xóa file .zip)...\n";
        $cleanupPostData = ['cpanel_jsonapi_module' => 'Fileman', 'cpanel_jsonapi_func' => 'fileop', 'cpanel_jsonapi_apiversion' => '2', 'op' => 'unlink', 'sourcefiles' => $serverFilePath];
        $cleanupResult = sendCurlRequest($apiUrl, $cookieFile, 'POST', [], $cleanupPostData);
        if ($cleanupResult !== false && empty(json_decode(substr($cleanupResult['response'], $cleanupResult['header_size']), true)['cpanelresult']['error'])) {
            echo "✅ Đã xóa file zip dọn dẹp thành công.\n";
        } else {
            echo "⚠️ Cảnh báo: Không thể tự động xóa file zip.\n";
        }
    } else {
        die("❌ Gửi yêu cầu giải nén tới server thất bại.\n");
    }
}

echo "\n🎉 Kịch bản đã hoàn thành!\n";
?>