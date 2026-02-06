<?php
/**
 * Cannabis Strain Tracker - Backend API
 * Compatible with basic shared hosting (Hostinger, etc.)
 * Storage: JSON files (no database required)
 */

// ============ CONFIGURATION ============
define('ADMIN_PASSWORD', 'changeme123');       // Change this!
define('OPENROUTER_API_KEY', '');              // Your OpenRouter API key
define('DATA_DIR', __DIR__ . '/data');
define('UPLOAD_DIR', __DIR__ . '/uploads');
define('MAX_UPLOAD_SIZE', 10 * 1024 * 1024);  // 10MB
define('MAX_BACKUPS', 5);
define('TOKEN_EXPIRY', 86400 * 30);           // 30 days
define('ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png', 'gif', 'webp']);

// ============ INIT ============
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Create directories
foreach ([DATA_DIR, UPLOAD_DIR, DATA_DIR . '/users', DATA_DIR . '/backups'] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

// ============ HELPERS ============
function jsonResponse($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function errorResponse($message, $code = 400) {
    jsonResponse(['error' => $message], $code);
}

function getInput() {
    $json = json_decode(file_get_contents('php://input'), true);
    return $json ?: [];
}

function generateToken() {
    return bin2hex(random_bytes(32));
}

function hashPassword($password) {
    return password_hash($password, PASSWORD_BCRYPT);
}

function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

function getUserFile($userId) {
    return DATA_DIR . '/users/' . preg_replace('/[^a-zA-Z0-9_-]/', '', $userId) . '.json';
}

function getTokenFile() {
    return DATA_DIR . '/tokens.json';
}

function loadTokens() {
    $file = getTokenFile();
    if (!file_exists($file)) return [];
    $tokens = json_decode(file_get_contents($file), true);
    return is_array($tokens) ? $tokens : [];
}

function saveTokens($tokens) {
    file_put_contents(getTokenFile(), json_encode($tokens));
}

function loadUser($userId) {
    $file = getUserFile($userId);
    if (!file_exists($file)) return null;
    return json_decode(file_get_contents($file), true);
}

function saveUser($userId, $data) {
    file_put_contents(getUserFile($userId), json_encode($data, JSON_PRETTY_PRINT));
}

function authenticateRequest() {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+(.+)$/', $header, $m)) {
        errorResponse('Authentication required', 401);
    }
    $token = $m[1];
    $tokens = loadTokens();
    if (!isset($tokens[$token])) {
        errorResponse('Invalid token', 401);
    }
    $t = $tokens[$token];
    if (time() > $t['expires']) {
        unset($tokens[$token]);
        saveTokens($tokens);
        errorResponse('Token expired', 401);
    }
    return $t['userId'];
}

function createBackup($userId) {
    $file = getUserFile($userId);
    if (!file_exists($file)) return;
    $backupDir = DATA_DIR . '/backups/' . $userId;
    if (!is_dir($backupDir)) mkdir($backupDir, 0755, true);

    $backupFile = $backupDir . '/backup_' . date('Y-m-d_H-i-s') . '.json';
    copy($file, $backupFile);

    // Keep only last N backups
    $backups = glob($backupDir . '/backup_*.json');
    sort($backups);
    while (count($backups) > MAX_BACKUPS) {
        unlink(array_shift($backups));
    }
}

function sanitizeFilename($name) {
    return preg_replace('/[^a-zA-Z0-9._-]/', '_', $name);
}

function callOpenRouter($messages, $model = 'google/gemini-2.0-flash-001', $maxTokens = 2000) {
    if (empty(OPENROUTER_API_KEY)) {
        return ['error' => 'OpenRouter API key not configured'];
    }

    $payload = [
        'model' => $model,
        'messages' => $messages,
        'max_tokens' => $maxTokens,
        'temperature' => 0.3,
    ];

    $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . OPENROUTER_API_KEY,
            'HTTP-Referer: https://strain-tracker.app',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 60,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        return ['error' => 'OpenRouter API error: ' . $response];
    }

    $data = json_decode($response, true);
    return $data['choices'][0]['message']['content'] ?? ['error' => 'Empty response'];
}

function callOpenRouterVision($imageBase64, $mimeType, $prompt, $model = 'google/gemini-2.0-flash-001') {
    if (empty(OPENROUTER_API_KEY)) {
        return ['error' => 'OpenRouter API key not configured'];
    }

    $messages = [
        [
            'role' => 'user',
            'content' => [
                [
                    'type' => 'text',
                    'text' => $prompt
                ],
                [
                    'type' => 'image_url',
                    'image_url' => [
                        'url' => "data:{$mimeType};base64,{$imageBase64}"
                    ]
                ]
            ]
        ]
    ];

    $payload = [
        'model' => $model,
        'messages' => $messages,
        'max_tokens' => 2000,
        'temperature' => 0.2,
    ];

    $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . OPENROUTER_API_KEY,
            'HTTP-Referer: https://strain-tracker.app',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 90,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        return ['error' => 'Vision API error: ' . $response];
    }

    $data = json_decode($response, true);
    return $data['choices'][0]['message']['content'] ?? ['error' => 'Empty response'];
}

// ============ ROUTING ============
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

switch ($action) {

    // ---- AUTH ----
    case 'register':
        if ($method !== 'POST') errorResponse('POST required', 405);
        $input = getInput();
        $username = trim($input['username'] ?? '');
        $password = $input['password'] ?? '';
        $displayName = trim($input['displayName'] ?? $username);

        if (strlen($username) < 3 || strlen($username) > 30) {
            errorResponse('Username must be 3-30 characters');
        }
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $username)) {
            errorResponse('Username can only contain letters, numbers, hyphens, underscores');
        }
        if (strlen($password) < 6) {
            errorResponse('Password must be at least 6 characters');
        }

        $userId = strtolower($username);
        if (loadUser($userId)) {
            errorResponse('Username already taken');
        }

        $user = [
            'id' => $userId,
            'username' => $username,
            'displayName' => $displayName,
            'passwordHash' => hashPassword($password),
            'createdAt' => date('c'),
            'strains' => [],
            'settings' => [
                'preferredModel' => 'google/gemini-2.0-flash-001',
            ],
        ];
        saveUser($userId, $user);

        $token = generateToken();
        $tokens = loadTokens();
        $tokens[$token] = ['userId' => $userId, 'expires' => time() + TOKEN_EXPIRY];
        saveTokens($tokens);

        jsonResponse([
            'token' => $token,
            'user' => [
                'id' => $userId,
                'username' => $username,
                'displayName' => $displayName,
            ]
        ]);
        break;

    case 'login':
        if ($method !== 'POST') errorResponse('POST required', 405);
        $input = getInput();
        $username = trim($input['username'] ?? '');
        $password = $input['password'] ?? '';
        $userId = strtolower($username);

        $user = loadUser($userId);
        if (!$user || !verifyPassword($password, $user['passwordHash'])) {
            errorResponse('Invalid username or password', 401);
        }

        $token = generateToken();
        $tokens = loadTokens();
        $tokens[$token] = ['userId' => $userId, 'expires' => time() + TOKEN_EXPIRY];
        saveTokens($tokens);

        jsonResponse([
            'token' => $token,
            'user' => [
                'id' => $userId,
                'username' => $user['username'],
                'displayName' => $user['displayName'],
            ]
        ]);
        break;

    case 'logout':
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/^Bearer\s+(.+)$/', $header, $m)) {
            $tokens = loadTokens();
            unset($tokens[$m[1]]);
            saveTokens($tokens);
        }
        jsonResponse(['success' => true]);
        break;

    case 'profile':
        $userId = authenticateRequest();
        $user = loadUser($userId);
        if ($method === 'GET') {
            jsonResponse([
                'id' => $user['id'],
                'username' => $user['username'],
                'displayName' => $user['displayName'],
                'createdAt' => $user['createdAt'],
                'strainCount' => count($user['strains'] ?? []),
                'settings' => $user['settings'] ?? [],
            ]);
        } elseif ($method === 'POST') {
            $input = getInput();
            if (isset($input['displayName'])) {
                $user['displayName'] = trim($input['displayName']);
            }
            if (isset($input['settings'])) {
                $user['settings'] = array_merge($user['settings'] ?? [], $input['settings']);
            }
            if (!empty($input['newPassword'])) {
                if (empty($input['currentPassword']) || !verifyPassword($input['currentPassword'], $user['passwordHash'])) {
                    errorResponse('Current password is incorrect');
                }
                $user['passwordHash'] = hashPassword($input['newPassword']);
            }
            saveUser($userId, $user);
            jsonResponse(['success' => true]);
        }
        break;

    // ---- STRAINS ----
    case 'strains':
        $userId = authenticateRequest();
        $user = loadUser($userId);

        if ($method === 'GET') {
            jsonResponse($user['strains'] ?? []);
        } elseif ($method === 'POST') {
            $input = getInput();
            createBackup($userId);

            $strain = [
                'id' => uniqid('str_', true),
                'name' => trim($input['name'] ?? 'Unknown'),
                'store' => trim($input['store'] ?? ''),
                'price' => floatval($input['price'] ?? 0),
                'weight' => trim($input['weight'] ?? ''),
                'type' => $input['type'] ?? 'hybrid',
                'thc' => trim($input['thc'] ?? ''),
                'cbd' => trim($input['cbd'] ?? ''),
                'terpenes' => $input['terpenes'] ?? [],
                'purchaseDate' => $input['purchaseDate'] ?? date('Y-m-d'),
                'review' => trim($input['review'] ?? ''),
                'rating' => intval($input['rating'] ?? 0),
                'photos' => [],
                'labelPhotos' => [],
                'batchInfo' => trim($input['batchInfo'] ?? ''),
                'effects' => $input['effects'] ?? [],
                'createdAt' => date('c'),
                'updatedAt' => date('c'),
            ];

            $user['strains'][] = $strain;
            saveUser($userId, $user);
            jsonResponse($strain, 201);
        }
        break;

    case 'strain':
        $userId = authenticateRequest();
        $user = loadUser($userId);
        $strainId = $_GET['id'] ?? '';

        $idx = null;
        foreach ($user['strains'] as $i => $s) {
            if ($s['id'] === $strainId) { $idx = $i; break; }
        }
        if ($idx === null) errorResponse('Strain not found', 404);

        if ($method === 'POST') {
            $input = getInput();
            createBackup($userId);
            $fields = ['name','store','price','weight','type','thc','cbd','terpenes',
                       'purchaseDate','review','rating','batchInfo','effects'];
            foreach ($fields as $f) {
                if (isset($input[$f])) {
                    $user['strains'][$idx][$f] = $input[$f];
                }
            }
            $user['strains'][$idx]['updatedAt'] = date('c');
            saveUser($userId, $user);
            jsonResponse($user['strains'][$idx]);
        } elseif ($method === 'DELETE') {
            createBackup($userId);
            // Delete associated photos
            $strain = $user['strains'][$idx];
            $photoDir = UPLOAD_DIR . '/' . $userId . '/' . $strainId;
            if (is_dir($photoDir)) {
                array_map('unlink', glob($photoDir . '/*'));
                rmdir($photoDir);
            }
            array_splice($user['strains'], $idx, 1);
            saveUser($userId, $user);
            jsonResponse(['success' => true]);
        } elseif ($method === 'GET') {
            jsonResponse($user['strains'][$idx]);
        }
        break;

    // ---- PHOTO UPLOAD ----
    case 'upload-photo':
        $userId = authenticateRequest();
        $user = loadUser($userId);
        $strainId = $_POST['strainId'] ?? '';
        $photoType = $_POST['photoType'] ?? 'photo'; // 'photo' or 'label'

        $idx = null;
        foreach ($user['strains'] as $i => $s) {
            if ($s['id'] === $strainId) { $idx = $i; break; }
        }
        if ($idx === null) errorResponse('Strain not found', 404);

        if (empty($_FILES['photo'])) errorResponse('No file uploaded');

        $file = $_FILES['photo'];
        if ($file['error'] !== UPLOAD_ERR_OK) errorResponse('Upload error: ' . $file['error']);
        if ($file['size'] > MAX_UPLOAD_SIZE) errorResponse('File too large (max 10MB)');

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ALLOWED_EXTENSIONS)) errorResponse('Invalid file type');

        // Verify it's actually an image
        $imageInfo = getimagesize($file['tmp_name']);
        if (!$imageInfo) errorResponse('Invalid image file');

        $userUploadDir = UPLOAD_DIR . '/' . $userId . '/' . $strainId;
        if (!is_dir($userUploadDir)) mkdir($userUploadDir, 0755, true);

        $filename = $photoType . '_' . uniqid() . '.' . $ext;
        $filepath = $userUploadDir . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $filepath)) {
            errorResponse('Failed to save file');
        }

        $photoUrl = 'uploads/' . $userId . '/' . $strainId . '/' . $filename;

        createBackup($userId);
        $photoEntry = [
            'id' => uniqid('ph_'),
            'url' => $photoUrl,
            'filename' => $filename,
            'uploadedAt' => date('c'),
        ];

        if ($photoType === 'label') {
            $user['strains'][$idx]['labelPhotos'][] = $photoEntry;
        } else {
            $user['strains'][$idx]['photos'][] = $photoEntry;
        }
        $user['strains'][$idx]['updatedAt'] = date('c');
        saveUser($userId, $user);

        jsonResponse(['photo' => $photoEntry]);
        break;

    case 'delete-photo':
        $userId = authenticateRequest();
        $user = loadUser($userId);
        $input = getInput();
        $strainId = $input['strainId'] ?? '';
        $photoId = $input['photoId'] ?? '';
        $photoType = $input['photoType'] ?? 'photo';

        $idx = null;
        foreach ($user['strains'] as $i => $s) {
            if ($s['id'] === $strainId) { $idx = $i; break; }
        }
        if ($idx === null) errorResponse('Strain not found', 404);

        createBackup($userId);
        $key = $photoType === 'label' ? 'labelPhotos' : 'photos';
        $photos = &$user['strains'][$idx][$key];
        foreach ($photos as $pi => $ph) {
            if ($ph['id'] === $photoId) {
                $filePath = __DIR__ . '/' . $ph['url'];
                if (file_exists($filePath)) unlink($filePath);
                array_splice($photos, $pi, 1);
                break;
            }
        }
        saveUser($userId, $user);
        jsonResponse(['success' => true]);
        break;

    // ---- AI FEATURES ----
    case 'read-label':
        $userId = authenticateRequest();
        if (empty($_FILES['photo'])) errorResponse('No file uploaded');

        $file = $_FILES['photo'];
        if ($file['error'] !== UPLOAD_ERR_OK) errorResponse('Upload error');

        $imageInfo = getimagesize($file['tmp_name']);
        if (!$imageInfo) errorResponse('Invalid image');

        $mimeType = $imageInfo['mime'];
        $imageBase64 = base64_encode(file_get_contents($file['tmp_name']));

        $prompt = "Analyze this cannabis product label/package photo. Extract ALL visible information and return it as JSON with these fields:
{
  \"strain_name\": \"name if visible\",
  \"thc\": \"THC percentage if visible\",
  \"cbd\": \"CBD percentage if visible\",
  \"terpenes\": [\"list of terpenes if visible\"],
  \"batch_info\": \"batch or lot number if visible\",
  \"weight\": \"weight if visible\",
  \"type\": \"indica, sativa, or hybrid if visible\",
  \"producer\": \"producer/brand if visible\",
  \"other_cannabinoids\": {}
}
Only include fields where data is actually visible. Return ONLY valid JSON, no other text.";

        $user = loadUser($userId);
        $model = $user['settings']['preferredModel'] ?? 'google/gemini-2.0-flash-001';
        $result = callOpenRouterVision($imageBase64, $mimeType, $prompt, $model);

        if (is_array($result) && isset($result['error'])) {
            errorResponse($result['error']);
        }

        // Try to parse JSON from response
        $jsonStr = $result;
        if (preg_match('/\{[\s\S]*\}/', $result, $matches)) {
            $jsonStr = $matches[0];
        }
        $parsed = json_decode($jsonStr, true);

        jsonResponse([
            'raw' => $result,
            'parsed' => $parsed ?: [],
        ]);
        break;

    case 'parse-receipt':
        $userId = authenticateRequest();
        $input = getInput();
        $receiptText = $input['text'] ?? '';

        if (empty($receiptText) && !empty($_FILES['photo'])) {
            $file = $_FILES['photo'];
            $imageInfo = getimagesize($file['tmp_name']);
            if ($imageInfo) {
                $mimeType = $imageInfo['mime'];
                $imageBase64 = base64_encode(file_get_contents($file['tmp_name']));

                $prompt = "This is a cannabis dispensary receipt. Extract all items purchased. Return JSON array:
[{
  \"name\": \"strain name\",
  \"price\": 0.00,
  \"weight\": \"weight with unit\",
  \"thc\": \"THC% if shown\",
  \"cbd\": \"CBD% if shown\",
  \"type\": \"indica/sativa/hybrid if shown\"
}]
Also include a \"store\" field with the dispensary name if visible.
Return format: {\"store\": \"name\", \"items\": [...]}
Return ONLY valid JSON.";

                $user = loadUser($userId);
                $model = $user['settings']['preferredModel'] ?? 'google/gemini-2.0-flash-001';
                $result = callOpenRouterVision($imageBase64, $mimeType, $prompt, $model);
            } else {
                errorResponse('Invalid image');
            }
        } else {
            $prompt = "Parse this cannabis dispensary receipt text. Extract all items purchased. Return JSON:
{\"store\": \"dispensary name\", \"items\": [{\"name\": \"strain\", \"price\": 0.00, \"weight\": \"weight\", \"thc\": \"\", \"cbd\": \"\", \"type\": \"\"}]}
Return ONLY valid JSON.

Receipt:
" . $receiptText;

            $user = loadUser($userId);
            $model = $user['settings']['preferredModel'] ?? 'google/gemini-2.0-flash-001';
            $messages = [['role' => 'user', 'content' => $prompt]];
            $result = callOpenRouter($messages, $model);
        }

        if (is_array($result) && isset($result['error'])) {
            errorResponse($result['error']);
        }

        $jsonStr = $result;
        if (preg_match('/\{[\s\S]*\}/', $result, $matches)) {
            $jsonStr = $matches[0];
        }
        $parsed = json_decode($jsonStr, true);

        jsonResponse([
            'raw' => $result,
            'parsed' => $parsed ?: [],
        ]);
        break;

    case 'fetch-reviews':
        $userId = authenticateRequest();
        $input = getInput();
        $strainName = trim($input['strainName'] ?? '');
        if (empty($strainName)) errorResponse('Strain name required');

        $prompt = "Provide a brief summary of the cannabis strain \"$strainName\". Include:
1. Common effects reported by users
2. Typical THC/CBD ranges
3. Common terpenes
4. General user ratings/sentiment
5. Best uses (medical/recreational)

Format as JSON:
{
  \"effects\": [\"list of effects\"],
  \"medicalUses\": [\"list\"],
  \"thcRange\": \"range\",
  \"cbdRange\": \"range\",
  \"commonTerpenes\": [\"list\"],
  \"sentiment\": \"positive/mixed/negative\",
  \"summary\": \"brief 2-3 sentence summary\"
}
Return ONLY valid JSON.";

        $user = loadUser($userId);
        $model = $user['settings']['preferredModel'] ?? 'google/gemini-2.0-flash-001';
        $messages = [['role' => 'user', 'content' => $prompt]];
        $result = callOpenRouter($messages, $model);

        if (is_array($result) && isset($result['error'])) {
            errorResponse($result['error']);
        }

        $jsonStr = $result;
        if (preg_match('/\{[\s\S]*\}/', $result, $matches)) {
            $jsonStr = $matches[0];
        }
        $parsed = json_decode($jsonStr, true);

        jsonResponse([
            'raw' => $result,
            'parsed' => $parsed ?: [],
        ]);
        break;

    // ---- PUBLIC STATS ----
    case 'stats':
        $userFiles = glob(DATA_DIR . '/users/*.json');
        $totalUsers = count($userFiles);
        $totalStrains = 0;
        foreach ($userFiles as $f) {
            $u = json_decode(file_get_contents($f), true);
            $totalStrains += count($u['strains'] ?? []);
        }
        jsonResponse([
            'totalUsers' => $totalUsers,
            'totalStrains' => $totalStrains,
        ]);
        break;

    // ---- BACKUP/RESTORE ----
    case 'backups':
        $userId = authenticateRequest();
        $backupDir = DATA_DIR . '/backups/' . $userId;
        if (!is_dir($backupDir)) jsonResponse([]);
        $files = glob($backupDir . '/backup_*.json');
        $backups = [];
        foreach ($files as $f) {
            $backups[] = [
                'filename' => basename($f),
                'date' => date('c', filemtime($f)),
                'size' => filesize($f),
            ];
        }
        rsort($backups);
        jsonResponse($backups);
        break;

    case 'restore':
        if ($method !== 'POST') errorResponse('POST required', 405);
        $userId = authenticateRequest();
        $input = getInput();
        $filename = sanitizeFilename($input['filename'] ?? '');
        $backupFile = DATA_DIR . '/backups/' . $userId . '/' . $filename;
        if (!file_exists($backupFile)) errorResponse('Backup not found', 404);

        // Backup current before restore
        createBackup($userId);
        copy($backupFile, getUserFile($userId));
        jsonResponse(['success' => true]);
        break;

    case 'export':
        $userId = authenticateRequest();
        $user = loadUser($userId);
        unset($user['passwordHash']);
        header('Content-Disposition: attachment; filename="strains_export_' . date('Y-m-d') . '.json"');
        jsonResponse($user);
        break;

    default:
        jsonResponse([
            'app' => 'Cannabis Strain Tracker',
            'version' => '1.0.0',
            'endpoints' => [
                'POST /api.php?action=register',
                'POST /api.php?action=login',
                'POST /api.php?action=logout',
                'GET|POST /api.php?action=profile',
                'GET|POST /api.php?action=strains',
                'GET|POST|DELETE /api.php?action=strain&id=X',
                'POST /api.php?action=upload-photo',
                'POST /api.php?action=delete-photo',
                'POST /api.php?action=read-label',
                'POST /api.php?action=parse-receipt',
                'POST /api.php?action=fetch-reviews',
                'GET /api.php?action=stats',
                'GET /api.php?action=backups',
                'POST /api.php?action=restore',
                'GET /api.php?action=export',
            ]
        ]);
}
