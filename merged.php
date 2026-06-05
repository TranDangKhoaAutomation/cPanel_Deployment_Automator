<?php

// ==== CONFIG (Gom tu file.php, sql.php, all.php) ====
$cpanel_host = 'your_cpanel_host.com:2083';
$cpanel_user = 'your_cpanel_username';
$cpanel_pass = 'your_cpanel_password';

$config = [
    // Source code deployment
    'targetDirectory' => '/home/' . $cpanel_user . '/public_html',
    'localFileToUpload' => 'C:/path/to/your/source-code.zip',
    'autoZip' => [
        'enabled' => true,
        'sourcePath' => 'C:/path/to/your/project-folder',
        'outputPath' => null,
        'excludes' => ['.git', 'node_modules', 'vendor', 'storage/logs', 'bootstrap/cache', '*.log', '*.zip'],
    ],
    'removeLocalZipAfterUpload' => true,
    'fileToEdit' => '/.env',

    // Database
    'database_name' => $cpanel_user . '_dbname',
    'db_user' => $cpanel_user . '_dbuser',
    'db_pass' => 'YourStrongDBPassword123!',
    'db_host' => 'localhost',
    'localSqlFile' => 'C:/path/to/your/database-backup.sql',
];

error_reporting(E_ALL);
ini_set('display_errors', '1');
set_time_limit(900);

// ==== FROM all.php + file.php + sql.php (helpers/functions) ====
function merged_send_api_request(string $url, string $cookieFile, string $method = 'GET', array $headers = [], $data = null, bool $isUapi = false)
{
    $ch = curl_init();
    if ($ch === false) {
        error_log('Cannot init cURL.');
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
    } elseif (is_array($data) && $isUapi) {
        $separator = (strpos($url, '?') === false) ? '?' : '&';
        $url .= $separator . http_build_query($data);
    }

    if ($isUpload) {
        foreach ($headers as $i => $header) {
            if (stripos($header, 'Content-Type:') === 0) {
                unset($headers[$i]);
            }
        }
    }

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => array_values($headers),
        CURLOPT_POSTFIELDS => $postFields,
        CURLOPT_TIMEOUT => 600,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);

    $response = curl_exec($ch);
    if ($response === false) {
        error_log('cURL API error: ' . curl_error($ch));
        curl_close($ch);
        return false;
    }

    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $body = substr($response, $headerSize);
    return json_decode($body, true);
}

function merged_send_browser_request(string $url, string $cookieFile, string $method = 'GET', array $headers = [], $data = null)
{
    $ch = curl_init();
    if ($ch === false) {
        return false;
    }

    $defaultHeaders = [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36',
    ];
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
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => false,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $finalHeaders,
        CURLOPT_POSTFIELDS => $postFields,
        CURLOPT_TIMEOUT => 900,
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);

    $response = curl_exec($ch);
    curl_close($ch);
    return $response;
}

function merged_starts_with(string $haystack, string $prefix): bool
{
    return $prefix === '' || strpos($haystack, $prefix) === 0;
}

function merged_should_exclude_from_zip(string $relativePath, array $excludes): bool
{
    $relativePath = str_replace('\\', '/', $relativePath);

    foreach ($excludes as $pattern) {
        $pattern = trim(str_replace('\\', '/', $pattern));
        if ($pattern === '') {
            continue;
        }

        $prefix = rtrim($pattern, '/');
        if (merged_starts_with($relativePath, $prefix . '/')) {
            return true;
        }

        if ($relativePath === $prefix) {
            return true;
        }

        if (fnmatch($pattern, $relativePath)) {
            return true;
        }
    }

    return false;
}

function merged_create_zip_from_directory(string $sourceDir, ?string $customZipPath = null, array $excludes = []): string
{
    if (!extension_loaded('zip')) {
        die("ERROR: PHP extension 'zip' is not enabled.\n");
    }

    if (!is_dir($sourceDir)) {
        die("ERROR: Source directory not found: {$sourceDir}\n");
    }

    $resolvedSource = realpath($sourceDir);
    if ($resolvedSource === false) {
        die("ERROR: Cannot resolve source directory: {$sourceDir}\n");
    }

    $zipPath = $customZipPath ?: (sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cpanel_upload_' . date('Ymd_His') . '.zip');

    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        die("ERROR: Cannot create zip at {$zipPath}\n");
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($resolvedSource, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $file) {
        $filePath = $file->getRealPath();
        if ($filePath === false) {
            continue;
        }

        $relativePath = ltrim(str_replace('\\', '/', substr($filePath, strlen($resolvedSource))), '/');
        if ($relativePath === '') {
            continue;
        }

        if (merged_should_exclude_from_zip($relativePath, $excludes)) {
            continue;
        }

        if ($file->isDir()) {
            $zip->addEmptyDir($relativePath);
        } else {
            $zip->addFile($filePath, $relativePath);
        }
    }

    $zip->close();
    return $zipPath;
}

function merged_cli_readline(string $prompt): string
{
    if (function_exists('readline')) {
        $line = readline($prompt);
        return is_string($line) ? $line : '';
    }

    if (defined('STDIN')) {
        echo $prompt;
        $line = fgets(STDIN);
        return $line === false ? '' : rtrim($line, "\r\n");
    }

    return '';
}

function merged_login_cpanel(string $cpanelHost, string $cpanelUser, string $cpanelPass, string $cookieFile): string
{
    $loginUrl = "https://{$cpanelHost}/login/?login_only=1";
    $loginResult = merged_send_api_request($loginUrl, $cookieFile, 'POST', [], [
        'user' => $cpanelUser,
        'pass' => $cpanelPass,
    ]);

    if (!$loginResult || !isset($loginResult['status']) || (int) $loginResult['status'] !== 1) {
        die("ERROR: Login failed. Response: " . json_encode($loginResult, JSON_UNESCAPED_SLASHES) . "\n");
    }

    if (empty($loginResult['security_token'])) {
        die("ERROR: Login succeeded but missing security token.\n");
    }

    return $loginResult['security_token'];
}

function merged_upload_and_configure(string $cpanelHost, string $securityToken, string $cookieFile, array $config): void
{
    echo "\n==================================================\n";
    echo " TASK 1: UPLOAD AND CONFIGURE SOURCE CODE\n";
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

        echo "Compressing source directory: {$sourcePath}\n";
        $localFileToUpload = merged_create_zip_from_directory($sourcePath, $zipOutput, $excludes);
        $createdLocalZip = true;
        echo "Created zip: {$localFileToUpload}\n";

        if ($config['removeLocalZipAfterUpload'] ?? true) {
            register_shutdown_function(function () use ($localFileToUpload) {
                if (file_exists($localFileToUpload)) {
                    @unlink($localFileToUpload);
                }
            });
        }
    }

    // Clean target directory
    echo "Cleaning target directory: {$targetDirectory}\n";
    $apiUrlJson = "https://{$cpanelHost}{$securityToken}/json-api/cpanel";
    $listParams = [
        'cpanel_jsonapi_module' => 'Fileman',
        'cpanel_jsonapi_func' => 'listfiles',
        'cpanel_jsonapi_apiversion' => '2',
        'dir' => $targetDirectory,
        'showdotfiles' => '1',
    ];
    $listResult = merged_send_api_request($apiUrlJson . '?' . http_build_query($listParams), $cookieFile, 'GET');

    $itemsToDelete = [];
    if (!empty($listResult['cpanelresult']['data'])) {
        foreach ($listResult['cpanelresult']['data'] as $item) {
            if (($item['file'] ?? '') !== '.' && ($item['file'] ?? '') !== '..') {
                $itemsToDelete[] = $item['fullpath'];
            }
        }
    }

    if (!empty($itemsToDelete)) {
        echo "Found " . count($itemsToDelete) . " item(s), deleting...\n";
        $deletePostData = [
            'cpanel_jsonapi_module' => 'Fileman',
            'cpanel_jsonapi_func' => 'fileop',
            'cpanel_jsonapi_apiversion' => '2',
            'op' => 'unlink',
            'doubledecode' => '0',
        ];
        $deleteDataString = http_build_query($deletePostData);
        foreach ($itemsToDelete as $itemPath) {
            $deleteDataString .= '&sourcefiles=' . urlencode($itemPath);
        }

        $deleteResult = merged_send_api_request($apiUrlJson, $cookieFile, 'POST', [], $deleteDataString);
        if (!$deleteResult || !empty($deleteResult['cpanelresult']['error'])) {
            die("ERROR: Failed cleaning target directory: " . ($deleteResult['cpanelresult']['error'] ?? 'Unknown error') . "\n");
        }
    } else {
        echo "Target directory already empty.\n";
    }

    // Upload zip
    if (!file_exists($localFileToUpload)) {
        die("ERROR: Local upload file not found: {$localFileToUpload}\n");
    }

    echo "Uploading: {$localFileToUpload}\n";
    $uploadUrl = "https://{$cpanelHost}{$securityToken}/execute/Fileman/upload_files";
    $uploadPostData = [
        'dir' => $targetDirectory,
        'file-0' => new CURLFile(realpath($localFileToUpload)),
    ];
    $uploadData = merged_send_api_request($uploadUrl, $cookieFile, 'POST', [], $uploadPostData);
    if (!$uploadData || !empty($uploadData['errors'])) {
        die("ERROR: Upload failed: " . ($uploadData['errors'][0] ?? 'Unknown error') . "\n");
    }
    echo "Upload successful.\n";

    // Extract zip
    echo "Extracting zip on server...\n";
    $serverFilePath = $targetDirectory . '/' . basename($localFileToUpload);
    $extractPostData = [
        'cpanel_jsonapi_module' => 'Fileman',
        'cpanel_jsonapi_func' => 'fileop',
        'cpanel_jsonapi_apiversion' => '2',
        'op' => 'extract',
        'sourcefiles' => $serverFilePath,
        'destfiles' => $targetDirectory,
    ];
    $extractResult = merged_send_api_request($apiUrlJson, $cookieFile, 'POST', [], $extractPostData);
    if (!$extractResult || !empty($extractResult['cpanelresult']['error'])) {
        die("ERROR: Extract failed: " . ($extractResult['cpanelresult']['error'] ?? 'Unknown error') . "\n");
    }
    echo "Extract successful.\n";

    // Update .env
    echo "Updating env file: {$fileToEdit}\n";
    $uapiUrl = "https://{$cpanelHost}{$securityToken}/execute/Fileman/get_file_content";
    $getContentResult = merged_send_api_request(
        $uapiUrl,
        $cookieFile,
        'GET',
        [],
        [
            'dir' => dirname($targetDirectory . $fileToEdit),
            'file' => basename($fileToEdit),
        ],
        true
    );
    if (!$getContentResult || empty($getContentResult['status'])) {
        die("ERROR: Read env failed: " . ($getContentResult['errors'][0] ?? 'Unknown error') . "\n");
    }

    $currentContent = $getContentResult['data']['content'] ?? '';
    $replacements = [
        'DB_HOST' => $config['db_host'],
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
                $newLines[] = "{$key}={$value}";
                $updatedKeys[$key] = true;
                $found = true;
                break;
            }
        }
        if (!$found) {
            $newLines[] = $line;
        }
    }

    foreach ($replacements as $key => $value) {
        if (!isset($updatedKeys[$key])) {
            $newLines[] = "{$key}={$value}";
        }
    }

    $newContent = implode("\n", $newLines);
    $savePostData = [
        'cpanel_jsonapi_apiversion' => '2',
        'cpanel_jsonapi_module' => 'Fileman',
        'cpanel_jsonapi_func' => 'savefile',
        'dir' => dirname($targetDirectory . $fileToEdit),
        'filename' => basename($fileToEdit),
        'content' => $newContent,
    ];
    $saveResult = merged_send_api_request($apiUrlJson, $cookieFile, 'POST', [], $savePostData);
    if (!$saveResult || !empty($saveResult['cpanelresult']['error'])) {
        die("ERROR: Save env failed: " . ($saveResult['cpanelresult']['error'] ?? 'Unknown error') . "\n");
    }
    echo "Env update successful.\n";

    // Remove uploaded zip from server
    echo "Removing uploaded zip on server...\n";
    $cleanupPostData = [
        'cpanel_jsonapi_module' => 'Fileman',
        'cpanel_jsonapi_func' => 'fileop',
        'cpanel_jsonapi_apiversion' => '2',
        'op' => 'unlink',
        'sourcefiles' => $serverFilePath,
    ];
    merged_send_api_request($apiUrlJson, $cookieFile, 'POST', [], $cleanupPostData);
    echo "Server zip cleanup done.\n";

    if ($createdLocalZip && ($config['removeLocalZipAfterUpload'] ?? true) && file_exists($localFileToUpload)) {
        unlink($localFileToUpload);
        echo "Local temporary zip removed.\n";
    }
}

function merged_create_database_and_user(string $cpanelHost, string $securityToken, string $cookieFile, array $config): void
{
    echo "\n==================================================\n";
    echo " TASK 2: CREATE DATABASE AND USER\n";
    echo "==================================================\n";

    $apiUrl = "https://{$cpanelHost}{$securityToken}/execute/";

    echo "Creating database '{$config['database_name']}'...\n";
    $createDbResult = merged_send_api_request(
        $apiUrl . 'Mysql/create_database',
        $cookieFile,
        'GET',
        [],
        ['name' => $config['database_name']],
        true
    );
    if ($createDbResult && !empty($createDbResult['status'])) {
        echo "Database created.\n";
    } elseif (strpos($createDbResult['errors'][0] ?? '', 'already exists') !== false) {
        echo "Database already exists, skip.\n";
    } else {
        die("ERROR: Create database failed: " . ($createDbResult['errors'][0] ?? 'Unknown error') . "\n");
    }

    echo "Creating user '{$config['db_user']}'...\n";
    $createUserResult = merged_send_api_request(
        $apiUrl . 'Mysql/create_user',
        $cookieFile,
        'GET',
        [],
        [
            'name' => $config['db_user'],
            'password' => $config['db_pass'],
        ],
        true
    );
    if ($createUserResult && !empty($createUserResult['status'])) {
        echo "User created.\n";
    } elseif (strpos($createUserResult['errors'][0] ?? '', 'already exists') !== false) {
        echo "User already exists, skip.\n";
    } else {
        die("ERROR: Create user failed: " . ($createUserResult['errors'][0] ?? 'Unknown error') . "\n");
    }

    echo "Granting privileges...\n";
    $setPrivilegesResult = merged_send_api_request(
        $apiUrl . 'Mysql/set_privileges_on_database',
        $cookieFile,
        'GET',
        [],
        [
            'user' => $config['db_user'],
            'database' => $config['database_name'],
            'privileges' => 'ALL PRIVILEGES',
        ],
        true
    );
    if ($setPrivilegesResult && !empty($setPrivilegesResult['status'])) {
        echo "Privileges granted.\n";
    } else {
        die("ERROR: Grant privileges failed: " . ($setPrivilegesResult['errors'][0] ?? 'Unknown error') . "\n");
    }
}

function merged_reset_and_import_database(
    string $cpanelHost,
    string $securityToken,
    string $cookieFile,
    array $config,
    bool $confirmBeforeDrop = false
): void {
    echo "\n==================================================\n";
    echo " TASK 3: RESET AND IMPORT DATABASE\n";
    echo "==================================================\n";

    $databaseName = $config['database_name'];
    $localSqlFile = $config['localSqlFile'];

    echo "Fetching table list and phpMyAdmin token...\n";
    $pmaBaseUrl = "https://{$cpanelHost}{$securityToken}/3rdparty/phpMyAdmin/index.php?route=/database/structure&db={$databaseName}";
    $initialPageContent = merged_send_browser_request($pmaBaseUrl, $cookieFile);
    if ($initialPageContent === false || $initialPageContent === null) {
        die("ERROR: Cannot load phpMyAdmin structure page.\n");
    }

    $pmaToken = null;
    $htmlContent = '';

    $initialData = json_decode($initialPageContent, true);
    if (json_last_error() === JSON_ERROR_NONE && !empty($initialData['params']['token'])) {
        $pmaToken = $initialData['params']['token'];
        $htmlContent = $initialData['message'] ?? '';
    } else {
        preg_match('/<input type="hidden" name="token" value="([a-f0-9]{32})">/i', $initialPageContent, $matches);
        if (!empty($matches[1])) {
            $pmaToken = $matches[1];
        }
        $htmlContent = $initialPageContent;
    }

    if (empty($pmaToken)) {
        die("ERROR: Cannot find phpMyAdmin CSRF token.\n");
    }

    echo "Token acquired: {$pmaToken}\n";

    $tablesToDelete = [];
    if ($htmlContent !== '') {
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $htmlContent);
        $xpath = new DOMXPath($dom);
        $rows = $xpath->query("//tr[starts-with(@id, 'row_tbl_')]");
        foreach ($rows as $row) {
            $tableNameNode = $xpath->query("th/a", $row);
            if ($tableNameNode->length > 0) {
                $tablesToDelete[] = '`' . trim($tableNameNode[0]->nodeValue) . '`';
            }
        }
    }

    if (empty($tablesToDelete)) {
        echo "No tables found to drop.\n";
    } else {
        echo "Found " . count($tablesToDelete) . " table(s) to drop.\n";

        if ($confirmBeforeDrop && PHP_SAPI === 'cli') {
            merged_cli_readline("Press Enter to continue dropping tables...");
        }

        $tempSqlFile = __DIR__ . '/temp_drop_tables.sql';
        $dropQuery = "SET FOREIGN_KEY_CHECKS=0;\nDROP TABLE IF EXISTS " . implode(', ', $tablesToDelete) . ";\nSET FOREIGN_KEY_CHECKS=1;";
        file_put_contents($tempSqlFile, $dropQuery);

        $pmaImportUrl = "https://{$cpanelHost}{$securityToken}/3rdparty/phpMyAdmin/index.php?route=/import";
        $importDropPostData = [
            'import_type' => 'database',
            'db' => $databaseName,
            'token' => $pmaToken,
            'MAX_FILE_SIZE' => '536870912',
            'charset_of_file' => 'utf-8',
            'allow_interrupt' => 'yes',
            'skip_queries' => '0',
            'fk_checks' => '0',
            'format' => 'sql',
            'import_file' => new CURLFile(realpath($tempSqlFile), 'application/sql', basename($tempSqlFile)),
        ];
        $dropHeaders = [
            'X-Requested-With: XMLHttpRequest',
            'Referer: ' . $pmaBaseUrl,
        ];
        $dropResponse = merged_send_browser_request($pmaImportUrl, $cookieFile, 'POST', $dropHeaders, $importDropPostData);
        @unlink($tempSqlFile);

        if (strpos((string) $dropResponse, 'Import has been successfully finished') !== false) {
            echo "Drop tables completed.\n";
        } else {
            $dropData = json_decode((string) $dropResponse, true);
            $errorMessage = $dropData['error'] ?? 'Unknown error';
            die("ERROR: Drop tables failed. Response: {$errorMessage}\n");
        }
    }

    if (!file_exists($localSqlFile)) {
        die("ERROR: SQL file not found: {$localSqlFile}\n");
    }

    echo "Importing SQL file: {$localSqlFile}\n";
    $pmaImportUrl = "https://{$cpanelHost}{$securityToken}/3rdparty/phpMyAdmin/index.php?route=/import";
    $importMainPostData = [
        'import_type' => 'database',
        'db' => $databaseName,
        'token' => $pmaToken,
        'MAX_FILE_SIZE' => '536870912',
        'charset_of_file' => 'utf-8',
        'allow_interrupt' => 'yes',
        'skip_queries' => '0',
        'fk_checks' => '0',
        'format' => 'sql',
        'import_file' => new CURLFile(realpath($localSqlFile), 'application/octet-stream', basename($localSqlFile)),
    ];
    $importHeaders = [
        'X-Requested-With: XMLHttpRequest',
        'Referer: https://' . $cpanelHost . $securityToken . '/3rdparty/phpMyAdmin/index.php?route=/database/import&db=' . $databaseName,
    ];
    $importResponse = merged_send_browser_request($pmaImportUrl, $cookieFile, 'POST', $importHeaders, $importMainPostData);

    if (strpos((string) $importResponse, 'Import has been successfully finished') !== false) {
        echo "SQL import completed.\n";
    } else {
        $importData = json_decode((string) $importResponse, true);
        $errorMessage = $importData['error'] ?? 'No success message from phpMyAdmin.';
        if (isset($importData['message'])) {
            $errorMessage .= ' Message: ' . strip_tags((string) $importData['message']);
        }
        die("ERROR: SQL import failed. Response: {$errorMessage}\n");
    }
}

// ==== FROM file.php (entry mode: file) ====
function merged_run_file_mode(string $cpanelHost, string $securityToken, string $cookieFile, array $config): void
{
    merged_upload_and_configure($cpanelHost, $securityToken, $cookieFile, $config);
}

// ==== FROM sql.php (entry mode: sql) ====
function merged_run_sql_mode(string $cpanelHost, string $securityToken, string $cookieFile, array $config): void
{
    merged_reset_and_import_database($cpanelHost, $securityToken, $cookieFile, $config, true);
}

// ==== FROM all.php (entry mode: menu/full/db/file/sql) ====
function merged_run_menu_mode(string $cpanelHost, string $securityToken, string $cookieFile, array $config): void
{
    while (true) {
        echo "================ MENU ================\n";
        echo "  1. Upload and configure source code\n";
        echo "  2. Create database and user\n";
        echo "  3. Reset and import database\n";
        echo "  4. Full deployment (1 + 2 + 3)\n";
        echo "  0. Exit\n";
        echo "======================================\n";
        $choice = merged_cli_readline("Choose action: ");

        switch ($choice) {
            case '1':
                merged_upload_and_configure($cpanelHost, $securityToken, $cookieFile, $config);
                return;
            case '2':
                merged_create_database_and_user($cpanelHost, $securityToken, $cookieFile, $config);
                return;
            case '3':
                merged_reset_and_import_database($cpanelHost, $securityToken, $cookieFile, $config);
                return;
            case '4':
                merged_upload_and_configure($cpanelHost, $securityToken, $cookieFile, $config);
                merged_create_database_and_user($cpanelHost, $securityToken, $cookieFile, $config);
                merged_reset_and_import_database($cpanelHost, $securityToken, $cookieFile, $config);
                return;
            case '0':
                echo "Exit.\n";
                return;
            default:
                echo "Invalid choice. Try again.\n\n";
        }
    }
}

// ==== Router / Execution ====
$rawMode = 'menu';
if (PHP_SAPI === 'cli') {
    $rawMode = $argv[1] ?? 'menu';
} else {
    $rawMode = $_GET['mode'] ?? 'menu';
}

$mode = strtolower(trim((string) $rawMode));
$aliases = [
    'all' => 'menu',
    'menu' => 'menu',
    'file' => 'file',
    'sql' => 'sql',
    'db' => 'db',
    'full' => 'full',
    'deploy' => 'full',
];
$mode = $aliases[$mode] ?? $mode;

if (PHP_SAPI !== 'cli' && $mode === 'menu') {
    echo "Use query mode=file|db|sql|full|menu";
    return;
}

if (!in_array($mode, ['menu', 'file', 'sql', 'db', 'full'], true)) {
    echo "Unknown mode: {$mode}\n";
    echo "Valid modes: menu, file, sql, db, full\n";
    return;
}

$cookieFile = __DIR__ . '/cookie_merged_' . $mode . '.txt';
if (file_exists($cookieFile)) {
    @unlink($cookieFile);
}
register_shutdown_function(static function () use ($cookieFile): void {
    if (file_exists($cookieFile)) {
        @unlink($cookieFile);
    }
});

echo "Logging in to cPanel...\n";
$securityToken = merged_login_cpanel($cpanel_host, $cpanel_user, $cpanel_pass, $cookieFile);
echo "Login successful.\n";

switch ($mode) {
    case 'file':
        merged_run_file_mode($cpanel_host, $securityToken, $cookieFile, $config);
        break;

    case 'sql':
        merged_run_sql_mode($cpanel_host, $securityToken, $cookieFile, $config);
        break;

    case 'db':
        merged_create_database_and_user($cpanel_host, $securityToken, $cookieFile, $config);
        break;

    case 'full':
        merged_upload_and_configure($cpanel_host, $securityToken, $cookieFile, $config);
        merged_create_database_and_user($cpanel_host, $securityToken, $cookieFile, $config);
        merged_reset_and_import_database($cpanel_host, $securityToken, $cookieFile, $config);
        break;

    case 'menu':
    default:
        merged_run_menu_mode($cpanel_host, $securityToken, $cookieFile, $config);
        break;
}

echo "\nScript finished.\n";
