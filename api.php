<?php
/**
 * Cannabis Strain Tracker - Backend API
 * Compatible with basic shared hosting (Hostinger, etc.)
 * Storage: JSON files (no database required)
 */

// ============ CONFIGURATION ============
define('ADMIN_PASSWORD', 'Fuctit4420!');       // Admin password
define('ADMIN_USERS', ['forty4420']);           // Usernames with admin access
define('OPENROUTER_API_KEY', '');              // Your OpenRouter API key
define('DATA_DIR', __DIR__ . '/data');
define('UPLOAD_DIR', __DIR__ . '/uploads');
define('MAX_UPLOAD_SIZE', 10 * 1024 * 1024);  // 10MB
define('MAX_BACKUPS', 5);
define('TOKEN_EXPIRY', 86400 * 30);           // 30 days
define('STRAIN_CACHE_TTL', 86400 * 30);       // 30 days cache for strain info
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
foreach ([DATA_DIR, UPLOAD_DIR, DATA_DIR . '/users', DATA_DIR . '/backups', DATA_DIR . '/strain_cache'] as $dir) {
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

function isAdmin($userId) {
    if ($userId === '__admin__') return true;
    if (in_array($userId, ADMIN_USERS)) return true;
    $user = loadUser($userId);
    return $user && !empty($user['isAdmin']);
}

function requireAdmin($userId) {
    if (!isAdmin($userId)) {
        errorResponse('Admin access required', 403);
    }
}

function getAllUsers() {
    $files = glob(DATA_DIR . '/users/*.json');
    $users = [];
    foreach ($files as $f) {
        $u = json_decode(file_get_contents($f), true);
        if ($u) $users[] = $u;
    }
    return $users;
}

function getStrainCacheKey($strainName) {
    return preg_replace('/[^a-z0-9]/', '_', strtolower(trim($strainName)));
}

function getStrainCache($strainName) {
    $key = getStrainCacheKey($strainName);
    $file = DATA_DIR . '/strain_cache/' . $key . '.json';
    if (!file_exists($file)) return null;
    $data = json_decode(file_get_contents($file), true);
    if (!$data) return null;
    $age = time() - ($data['cachedAt'] ?? 0);
    if ($age > STRAIN_CACHE_TTL) return null; // expired
    return $data;
}

function saveStrainCache($strainName, $parsed, $raw) {
    $key = getStrainCacheKey($strainName);
    $file = DATA_DIR . '/strain_cache/' . $key . '.json';
    $data = [
        'strainName' => $strainName,
        'cachedAt' => time(),
        'expiresAt' => time() + STRAIN_CACHE_TTL,
        'parsed' => $parsed,
        'raw' => $raw,
    ];
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
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

    // ---- ADMIN AUTH (standalone, password only) ----
    case 'admin-login':
        if ($method !== 'POST') errorResponse('POST required', 405);
        $input = getInput();
        $password = $input['password'] ?? '';
        if ($password !== ADMIN_PASSWORD) {
            errorResponse('Invalid admin password', 401);
        }
        $token = generateToken();
        $tokens = loadTokens();
        $tokens[$token] = ['userId' => '__admin__', 'expires' => time() + TOKEN_EXPIRY, 'isAdmin' => true];
        saveTokens($tokens);
        jsonResponse(['token' => $token]);
        break;

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
                'isAdmin' => isAdmin($userId),
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
                'isAdmin' => isAdmin($userId),
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
                'isAdmin' => isAdmin($userId),
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
        $forceRefresh = !empty($input['forceRefresh']);
        if (empty($strainName)) errorResponse('Strain name required');

        // Check cache first
        $cached = $forceRefresh ? null : getStrainCache($strainName);
        if ($cached) {
            jsonResponse([
                'raw' => $cached['raw'],
                'parsed' => $cached['parsed'],
                'source' => 'cache',
                'cachedAt' => date('c', $cached['cachedAt']),
                'expiresAt' => date('c', $cached['expiresAt']),
            ]);
        }

        // Not in cache or expired - fetch from AI
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

        // Save to cache
        if ($parsed) {
            saveStrainCache($strainName, $parsed, $result);
        }

        jsonResponse([
            'raw' => $result,
            'parsed' => $parsed ?: [],
            'source' => 'api',
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

    // ---- ADMIN PANEL (serves HTML) ----
    case 'admin-panel':
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin - Strain Tracker</title>
<script defer src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"><\/script>
<style>
:root {
  --bg: #121212; --surface: #1e1e1e; --card: #252525; --border: #333;
  --text: #e0e0e0; --text2: #999; --green: #4caf50; --green-dark: #388e3c;
  --red: #f44336; --orange: #ff9800; --blue: #2196f3; --purple: #9c27b0;
}
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; background:var(--bg); color:var(--text); min-height:100vh; }
a { color:var(--green); text-decoration:none; }
button { cursor:pointer; border:none; font-family:inherit; font-size:14px; }
input,select { font-family:inherit; font-size:14px; background:var(--surface); color:var(--text); border:1px solid var(--border); border-radius:8px; padding:10px 12px; width:100%; outline:none; }
input:focus { border-color:var(--green); }
.btn { padding:8px 16px; border-radius:8px; font-weight:500; transition:all .2s; display:inline-flex; align-items:center; gap:6px; }
.btn-green { background:var(--green); color:#fff; }
.btn-green:hover { background:var(--green-dark); }
.btn-red { background:var(--red); color:#fff; }
.btn-red:hover { background:#c62828; }
.btn-outline { background:transparent; border:1px solid var(--border); color:var(--text); }
.btn-outline:hover { border-color:var(--green); color:var(--green); }
.btn-sm { padding:5px 10px; font-size:12px; }
.auth-container { max-width:400px; margin:80px auto; padding:16px; }
.auth-card { background:var(--card); border:1px solid var(--border); border-radius:16px; padding:30px; }
.auth-title { text-align:center; font-size:28px; color:var(--red); margin-bottom:6px; }
.auth-subtitle { text-align:center; color:var(--text2); margin-bottom:24px; font-size:14px; }
.form-group { margin-bottom:14px; }
.form-group label { display:block; font-size:13px; color:var(--text2); margin-bottom:5px; }
.admin-container { max-width:1200px; margin:0 auto; padding:16px; }
.admin-header { display:flex; align-items:center; justify-content:space-between; padding:16px 0; border-bottom:1px solid var(--border); margin-bottom:20px; flex-wrap:wrap; gap:10px; }
.admin-header h1 { font-size:22px; color:var(--red); }
.header-actions { display:flex; gap:8px; align-items:center; }
.admin-tabs { display:flex; gap:0; border-bottom:1px solid var(--border); margin-bottom:20px; overflow-x:auto; }
.admin-tab { padding:12px 20px; font-size:14px; color:var(--text2); cursor:pointer; border-bottom:2px solid transparent; background:none; transition:all .2s; white-space:nowrap; }
.admin-tab.active { color:var(--red); border-bottom-color:var(--red); }
.admin-stats-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:12px; margin-bottom:24px; }
.admin-stat-card { background:var(--card); border:1px solid var(--border); border-radius:12px; padding:16px; text-align:center; }
.admin-stat-value { font-size:28px; font-weight:700; color:var(--green); }
.admin-stat-label { font-size:12px; color:var(--text2); margin-top:4px; }
.chart-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(350px,1fr)); gap:16px; margin-bottom:24px; }
.chart-card { background:var(--card); border:1px solid var(--border); border-radius:12px; padding:16px; }
.chart-card h3 { font-size:14px; margin-bottom:12px; color:var(--text); }
.chart-card canvas { max-height:300px; }
.user-table { width:100%; border-collapse:collapse; }
.user-table th { text-align:left; padding:10px 12px; font-size:12px; color:var(--text2); text-transform:uppercase; border-bottom:1px solid var(--border); }
.user-table td { padding:10px 12px; font-size:13px; border-bottom:1px solid var(--border); }
.user-table tr:hover td { background:var(--card); }
.user-detail-header { display:flex; align-items:center; gap:16px; margin-bottom:20px; flex-wrap:wrap; }
.user-avatar { width:64px; height:64px; border-radius:50%; background:var(--card); border:2px solid var(--border); display:flex; align-items:center; justify-content:center; font-size:24px; font-weight:700; color:var(--green); }
.user-detail-info h2 { font-size:20px; }
.user-detail-info p { font-size:13px; color:var(--text2); }
.back-btn { background:none; border:none; color:var(--text2); font-size:13px; cursor:pointer; padding:4px 0; margin-bottom:12px; display:inline-flex; align-items:center; gap:4px; }
.back-btn:hover { color:var(--green); }
.strain-mini-card { background:var(--surface); border:1px solid var(--border); border-radius:8px; padding:10px 12px; margin-bottom:6px; display:flex; justify-content:space-between; align-items:center; }
.strain-mini-name { font-size:13px; font-weight:500; }
.strain-mini-meta { font-size:11px; color:var(--text2); display:flex; gap:8px; margin-top:2px; flex-wrap:wrap; }
.strain-type { font-size:11px; padding:2px 8px; border-radius:10px; font-weight:500; text-transform:uppercase; }
.type-indica { background:#7b1fa2; color:#fff; }
.type-sativa { background:#f57c00; color:#fff; }
.type-hybrid { background:#388e3c; color:#fff; }
.strain-rating { color:var(--orange); font-size:12px; }
.spinner { width:20px; height:20px; border:2px solid var(--border); border-top-color:var(--green); border-radius:50%; animation:spin .6s linear infinite; display:inline-block; }
@keyframes spin { to { transform:rotate(360deg); } }
.toast { position:fixed; bottom:20px; right:20px; background:var(--card); border:1px solid var(--border); border-radius:10px; padding:12px 18px; z-index:300; font-size:14px; animation:slideUp .3s; max-width:350px; }
.toast.success { border-color:var(--green); }
.toast.error { border-color:var(--red); }
@keyframes slideUp { from { transform:translateY(20px); opacity:0; } to { transform:translateY(0); opacity:1; } }
.modal-overlay { position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,.7); z-index:100; display:flex; align-items:flex-start; justify-content:center; padding:20px; overflow-y:auto; }
.modal { background:var(--surface); border:1px solid var(--border); border-radius:16px; width:100%; max-width:500px; margin:40px 0; }
.modal-header { display:flex; align-items:center; justify-content:space-between; padding:16px 20px; border-bottom:1px solid var(--border); }
.modal-header h2 { font-size:18px; }
.modal-body { padding:20px; }
.modal-footer { padding:12px 20px; border-top:1px solid var(--border); display:flex; justify-content:flex-end; gap:8px; }
.hidden { display:none !important; }
@media (max-width:600px) {
  .chart-grid { grid-template-columns:1fr; }
  .admin-stats-grid { grid-template-columns:repeat(2,1fr); }
  .user-table { font-size:11px; }
  .user-table th, .user-table td { padding:6px 8px; }
}
</style>
</head>
<body>
<div id="loginView">
  <div class="auth-container">
    <div class="auth-card">
      <h1 class="auth-title">Admin Panel</h1>
      <p class="auth-subtitle">Enter admin password to continue</p>
      <div class="form-group">
        <label>Admin Password</label>
        <input type="password" id="adminPass" placeholder="Enter admin password" onkeydown="if(event.key===\'Enter\')doAdminLogin()">
      </div>
      <button class="btn btn-red" style="width:100%;justify-content:center;margin-top:8px" onclick="doAdminLogin()">Enter Admin Panel</button>
      <p style="text-align:center;margin-top:14px;font-size:13px"><a href="index.html">Back to app</a></p>
    </div>
  </div>
</div>
<div id="dashView" class="hidden">
  <div class="admin-container">
    <div class="admin-header">
      <h1>Admin Dashboard</h1>
      <div class="header-actions">
        <button class="btn btn-green btn-sm" onclick="downloadCSV()">Download CSV</button>
        <a href="index.html" class="btn btn-outline btn-sm">Back to App</a>
        <button class="btn btn-outline btn-sm" onclick="doAdminLogout()">Logout</button>
      </div>
    </div>
    <div class="admin-tabs">
      <button class="admin-tab active" onclick="switchTab(\'overview\',this)">Overview</button>
      <button class="admin-tab" onclick="switchTab(\'users\',this)">Users</button>
      <button class="admin-tab hidden" id="detailTab" onclick="switchTab(\'detail\',this)">User Detail</button>
    </div>
    <div id="overviewTab"></div>
    <div id="usersTab" class="hidden"></div>
    <div id="detailTab_content" class="hidden"></div>
  </div>
</div>
<div id="modalContainer"></div>
<div id="toastContainer"></div>
<script>
const API_URL = "api.php";
let token = localStorage.getItem("admin_token") || "";
let allUsers = [];
let charts = [];

async function api(action, opts = {}) {
  const { method = "GET", body } = opts;
  const headers = {};
  if (token) headers["Authorization"] = "Bearer " + token;
  if (body) headers["Content-Type"] = "application/json";
  const fetchOpts = { method, headers };
  if (body) fetchOpts.body = JSON.stringify(body);
  const res = await fetch(API_URL + "?action=" + action, fetchOpts);
  if (action === "admin-export-csv" && res.ok) return res;
  const data = await res.json();
  if (!res.ok) throw new Error(data.error || "API error");
  return data;
}

async function doAdminLogin() {
  const pass = document.getElementById("adminPass").value;
  if (!pass) return toast("Enter a password", "error");
  try {
    const data = await api("admin-login", { method: "POST", body: { password: pass } });
    token = data.token;
    localStorage.setItem("admin_token", token);
    showDashboard();
  } catch (e) { toast(e.message, "error"); }
}

function doAdminLogout() {
  token = "";
  localStorage.removeItem("admin_token");
  document.getElementById("dashView").classList.add("hidden");
  document.getElementById("loginView").classList.remove("hidden");
}

async function showDashboard() {
  document.getElementById("loginView").classList.add("hidden");
  document.getElementById("dashView").classList.remove("hidden");
  await Promise.all([loadOverview(), loadUsers()]);
}

function switchTab(tab, btn) {
  document.querySelectorAll(".admin-tab").forEach(function(t){t.classList.remove("active")});
  if (btn) btn.classList.add("active");
  document.getElementById("overviewTab").classList.toggle("hidden", tab !== "overview");
  document.getElementById("usersTab").classList.toggle("hidden", tab !== "users");
  document.getElementById("detailTab_content").classList.toggle("hidden", tab !== "detail");
  if (tab === "detail") document.getElementById("detailTab").classList.remove("hidden");
}

function esc(s) { if (!s) return ""; var d = document.createElement("div"); d.textContent = s; return d.innerHTML; }
function toast(msg, type) {
  type = type || "success";
  var el = document.createElement("div"); el.className = "toast " + type; el.textContent = msg;
  document.getElementById("toastContainer").appendChild(el); setTimeout(function(){el.remove()}, 3500);
}
function showModal(title, body, buttons) {
  var btns = (buttons||[]).map(function(b){return "<button class=\\""+b.cls+"\\" onclick=\\""+b.onclick+"\\">"+b.text+"<\\/button>"}).join("");
  document.getElementById("modalContainer").innerHTML = "<div class=\\"modal-overlay\\" onclick=\\"if(event.target===this)closeModal()\\"><div class=\\"modal\\"><div class=\\"modal-header\\"><h2>"+title+"<\\/h2><button style=\\"font-size:16px;background:none;border:none;color:var(--text);cursor:pointer\\" onclick=\\"closeModal()\\">\\u00d7<\\/button><\\/div><div class=\\"modal-body\\">"+body+"<\\/div>"+(btns?"<div class=\\"modal-footer\\">"+btns+"<\\/div>":"")+"<\\/div><\\/div>";
}
function closeModal() { document.getElementById("modalContainer").innerHTML = ""; }

function createChart(el, cfg) {
  if (typeof Chart === "undefined") return null;
  return new Chart(el, cfg);
}
function destroyCharts() { charts.forEach(function(c){if(c)c.destroy()}); charts = []; }

var CC = {
  green:"#4caf50", red:"#f44336", orange:"#ff9800", blue:"#2196f3", purple:"#9c27b0",
  teal:"#009688", pink:"#e91e63", amber:"#ffc107", indigo:"#3f51b5", lime:"#cddc39",
  greenA:"rgba(76,175,80,.3)", blueA:"rgba(33,150,243,.3)"
};
var darkOpts = { plugins:{legend:{labels:{color:"#e0e0e0"}}}, scales:{x:{ticks:{color:"#999"},grid:{color:"#333"}},y:{ticks:{color:"#999"},grid:{color:"#333"}}} };

async function loadOverview() {
  try {
    var s = await api("admin-stats");
    var el = document.getElementById("overviewTab");
    el.innerHTML = "<div class=\\"admin-stats-grid\\">"+
      "<div class=\\"admin-stat-card\\"><div class=\\"admin-stat-value\\">"+s.totalUsers+"<\\/div><div class=\\"admin-stat-label\\">Total Users<\\/div><\\/div>"+
      "<div class=\\"admin-stat-card\\"><div class=\\"admin-stat-value\\">"+s.totalStrains+"<\\/div><div class=\\"admin-stat-label\\">Total Strains<\\/div><\\/div>"+
      "<div class=\\"admin-stat-card\\"><div class=\\"admin-stat-value\\">$"+s.totalSpent.toFixed(2)+"<\\/div><div class=\\"admin-stat-label\\">Total Spent<\\/div><\\/div>"+
      "<div class=\\"admin-stat-card\\"><div class=\\"admin-stat-value\\">"+s.avgStrainsPerUser+"<\\/div><div class=\\"admin-stat-label\\">Avg Strains/User<\\/div><\\/div>"+
      "<div class=\\"admin-stat-card\\"><div class=\\"admin-stat-value\\">"+s.storeCount+"<\\/div><div class=\\"admin-stat-label\\">Dispensaries<\\/div><\\/div>"+
      "<\\/div>"+
      "<div class=\\"chart-grid\\">"+
      "<div class=\\"chart-card\\"><h3>Monthly Spending<\\/h3><canvas id=\\"cMonthly\\"><\\/canvas><\\/div>"+
      "<div class=\\"chart-card\\"><h3>Strain Types<\\/h3><canvas id=\\"cTypes\\"><\\/canvas><\\/div>"+
      "<div class=\\"chart-card\\"><h3>Top Dispensaries<\\/h3><canvas id=\\"cStores\\"><\\/canvas><\\/div>"+
      "<div class=\\"chart-card\\"><h3>User Growth<\\/h3><canvas id=\\"cGrowth\\"><\\/canvas><\\/div>"+
      "<\\/div>";
    destroyCharts();
    var mL = Object.keys(s.monthlySpending), mV = Object.values(s.monthlySpending);
    if (mL.length) charts.push(createChart(document.getElementById("cMonthly"), { type:"line", data:{labels:mL,datasets:[{label:"$",data:mV,borderColor:CC.green,backgroundColor:CC.greenA,fill:true,tension:.3}]}, options:{responsive:true,plugins:darkOpts.plugins,scales:{x:darkOpts.scales.x,y:{ticks:{color:"#999",callback:function(v){return "$"+v}},grid:{color:"#333"}}}} }));
    var t = s.strainsByType;
    charts.push(createChart(document.getElementById("cTypes"), { type:"doughnut", data:{labels:["Indica","Sativa","Hybrid"],datasets:[{data:[t.indica,t.sativa,t.hybrid],backgroundColor:[CC.purple,CC.orange,CC.green],borderWidth:0}]}, options:{responsive:true,plugins:{legend:{labels:{color:"#e0e0e0"}}}} }));
    var sN = Object.keys(s.topStores), sV = Object.values(s.topStores);
    if (sN.length) charts.push(createChart(document.getElementById("cStores"), { type:"bar", data:{labels:sN,datasets:[{label:"Purchases",data:sV,backgroundColor:CC.blue,borderRadius:4}]}, options:{responsive:true,indexAxis:"y",plugins:{legend:{display:false}},scales:darkOpts.scales} }));
    var gL = Object.keys(s.userGrowth); var cum = 0;
    var gD = Object.values(s.userGrowth).map(function(v) { cum+=v; return cum; });
    if (gL.length) charts.push(createChart(document.getElementById("cGrowth"), { type:"line", data:{labels:gL,datasets:[{label:"Users",data:gD,borderColor:CC.blue,backgroundColor:CC.blueA,fill:true,tension:.3}]}, options:{responsive:true,plugins:darkOpts.plugins,scales:darkOpts.scales} }));
  } catch (e) {
    if (e.message.indexOf("401")!==-1 || e.message.indexOf("token")!==-1 || e.message.indexOf("Admin")!==-1) { doAdminLogout(); toast("Session expired", "error"); }
    else toast(e.message, "error");
  }
}

async function loadUsers() {
  try {
    allUsers = await api("admin-users");
    renderUsers();
  } catch (e) { toast(e.message, "error"); }
}

function renderUsers(search) {
  var q = (search || "").toLowerCase();
  var filtered = allUsers.filter(function(u){return !q || u.username.toLowerCase().indexOf(q)!==-1 || (u.displayName||"").toLowerCase().indexOf(q)!==-1});
  document.getElementById("usersTab").innerHTML =
    "<div style=\\"margin-bottom:16px;display:flex;gap:8px;align-items:center\\">"+
    "<input type=\\"text\\" placeholder=\\"Search users...\\" oninput=\\"renderUsers(this.value)\\" style=\\"max-width:300px\\" value=\\""+esc(search||"")+"\\">"+
    "<span style=\\"font-size:12px;color:var(--text2)\\">"+filtered.length+" user(s)<\\/span><\\/div>"+
    "<div style=\\"overflow-x:auto\\"><table class=\\"user-table\\"><thead><tr>"+
    "<th>User<\\/th><th>Strains<\\/th><th>Spent<\\/th><th>Dispensaries<\\/th><th>Visits<\\/th><th>Joined<\\/th><th>Actions<\\/th>"+
    "<\\/tr><\\/thead><tbody>"+
    filtered.map(function(u){ return "<tr>"+
      "<td><div style=\\"font-weight:500\\">"+esc(u.displayName||u.username)+(u.isAdmin?" <span style=\\"color:var(--red);font-size:11px\\">[ADMIN]<\\/span>":"")+"<\\/div><div style=\\"font-size:11px;color:var(--text2)\\">@"+esc(u.username)+" (ID: "+esc(u.id)+")<\\/div><\\/td>"+
      "<td>"+u.strainCount+"<\\/td>"+
      "<td>$"+u.totalSpent.toFixed(2)+"<\\/td>"+
      "<td>"+u.storeCount+"<\\/td>"+
      "<td>"+u.visitCount+"<\\/td>"+
      "<td style=\\"font-size:11px\\">"+(u.createdAt?new Date(u.createdAt).toLocaleDateString():"-")+"<\\/td>"+
      "<td><div style=\\"display:flex;gap:6px\\">"+
      "<button class=\\"btn btn-outline btn-sm\\" onclick=\\"viewUser(\'"+esc(u.id)+"\')\\">View<\\/button>"+
      (!u.isAdmin?"<button class=\\"btn btn-red btn-sm\\" onclick=\\"confirmDelete(\'"+esc(u.id)+"\',\'"+esc(u.username)+"\')\\\">Delete<\\/button>":"")+
      "<\\/div><\\/td><\\/tr>"; }).join("")+
    "<\\/tbody><\\/table><\\/div>";
}

function confirmDelete(id, name) {
  showModal("Delete User", "<p>Delete <strong>"+name+"<\\/strong>?<\\/p><p style=\\"color:var(--red);font-size:13px;margin-top:8px\\">This permanently deletes their account, strains, photos, and backups.<\\/p>", [
    { text:"Cancel", cls:"btn btn-outline", onclick:"closeModal()" },
    { text:"Delete", cls:"btn btn-red", onclick:"doDelete(\'"+id+"\')" }
  ]);
}
async function doDelete(id) {
  try { await api("admin-delete-user",{method:"POST",body:{userId:id}}); closeModal(); await loadUsers(); toast("User deleted"); }
  catch(e) { toast(e.message,"error"); }
}

var udCharts = [];
async function viewUser(id) {
  switchTab("detail", document.getElementById("detailTab"));
  var el = document.getElementById("detailTab_content");
  el.innerHTML = "<div style=\\"text-align:center;padding:40px\\"><span class=\\"spinner\\"><\\/span> Loading...<\\/div>";
  try {
    var d = await api("admin-user-detail&id="+id);
    var a = d.analytics;
    var init = (d.displayName||d.username||"?")[0].toUpperCase();
    udCharts.forEach(function(c){if(c)c.destroy()}); udCharts = [];
    el.innerHTML =
      "<button class=\\"back-btn\\" onclick=\\"switchTab(\'users\',document.querySelectorAll(\'.admin-tab\')[1])\\">&#8592; Back to Users<\\/button>"+
      "<div class=\\"user-detail-header\\">"+
      "<div class=\\"user-avatar\\">"+init+"<\\/div>"+
      "<div class=\\"user-detail-info\\">"+
      "<h2>"+esc(d.displayName||d.username)+(d.isAdmin?" <span style=\\"color:var(--red);font-size:14px\\">[ADMIN]<\\/span>":"")+"<\\/h2>"+
      "<p>@"+esc(d.username)+" &middot; ID: "+esc(d.id)+" &middot; Joined "+(d.createdAt?new Date(d.createdAt).toLocaleDateString():"N/A")+"<\\/p>"+
      "<\\/div><\\/div>"+
      "<div class=\\"admin-stats-grid\\">"+
      "<div class=\\"admin-stat-card\\"><div class=\\"admin-stat-value\\">"+a.totalStrains+"<\\/div><div class=\\"admin-stat-label\\">Strains<\\/div><\\/div>"+
      "<div class=\\"admin-stat-card\\"><div class=\\"admin-stat-value\\">$"+a.totalSpent.toFixed(2)+"<\\/div><div class=\\"admin-stat-label\\">Total Spent<\\/div><\\/div>"+
      "<div class=\\"admin-stat-card\\"><div class=\\"admin-stat-value\\">$"+a.avgPrice.toFixed(2)+"<\\/div><div class=\\"admin-stat-label\\">Avg Price<\\/div><\\/div>"+
      "<div class=\\"admin-stat-card\\"><div class=\\"admin-stat-value\\">"+(a.avgRating||"-")+"<\\/div><div class=\\"admin-stat-label\\">Avg Rating<\\/div><\\/div>"+
      "<div class=\\"admin-stat-card\\"><div class=\\"admin-stat-value\\">"+a.storeCount+"<\\/div><div class=\\"admin-stat-label\\">Dispensaries<\\/div><\\/div>"+
      "<div class=\\"admin-stat-card\\"><div class=\\"admin-stat-value\\">"+a.uniqueVisitDays+"<\\/div><div class=\\"admin-stat-label\\">Visit Days<\\/div><\\/div>"+
      "<\\/div>"+
      "<div class=\\"chart-grid\\">"+
      "<div class=\\"chart-card\\"><h3>Monthly Spending<\\/h3><canvas id=\\"udM\\"><\\/canvas><\\/div>"+
      "<div class=\\"chart-card\\"><h3>Strain Types<\\/h3><canvas id=\\"udT\\"><\\/canvas><\\/div>"+
      "<div class=\\"chart-card\\"><h3>Ratings<\\/h3><canvas id=\\"udR\\"><\\/canvas><\\/div>"+
      "<div class=\\"chart-card\\"><h3>Dispensary Visits<\\/h3><canvas id=\\"udS\\"><\\/canvas><\\/div>"+
      "<div class=\\"chart-card\\"><h3>Dispensary Spending<\\/h3><canvas id=\\"udSS\\"><\\/canvas><\\/div>"+
      "<div class=\\"chart-card\\"><h3>Day of Week<\\/h3><canvas id=\\"udW\\"><\\/canvas><\\/div>"+
      "<div class=\\"chart-card\\"><h3>Top Terpenes<\\/h3><canvas id=\\"udTP\\"><\\/canvas><\\/div>"+
      "<div class=\\"chart-card\\"><h3>Top Effects<\\/h3><canvas id=\\"udEF\\"><\\/canvas><\\/div>"+
      "<\\/div>"+
      "<h3 style=\\"margin-bottom:12px;font-size:16px\\">All Strains ("+d.strains.length+")<\\/h3>"+
      "<div id=\\"udStrains\\"><\\/div>";

    var mL=Object.keys(a.monthlySpending), mV=Object.values(a.monthlySpending);
    if(mL.length) udCharts.push(createChart(document.getElementById("udM"),{type:"bar",data:{labels:mL,datasets:[{label:"$",data:mV,backgroundColor:CC.green,borderRadius:4}]},options:{responsive:true,plugins:{legend:{display:false}},scales:{x:{ticks:{color:"#999"},grid:{color:"#333"}},y:{ticks:{color:"#999",callback:function(v){return "$"+v}},grid:{color:"#333"}}}}}));
    var ty=a.strainsByType;
    udCharts.push(createChart(document.getElementById("udT"),{type:"pie",data:{labels:["Indica","Sativa","Hybrid"],datasets:[{data:[ty.indica,ty.sativa,ty.hybrid],backgroundColor:["#7b1fa2","#f57c00","#388e3c"],borderWidth:0}]},options:{responsive:true,plugins:{legend:{labels:{color:"#e0e0e0"}}}}}));
    var rV=Object.values(a.ratingDistribution);
    udCharts.push(createChart(document.getElementById("udR"),{type:"bar",data:{labels:["1 Star","2 Stars","3 Stars","4 Stars","5 Stars"],datasets:[{data:rV,backgroundColor:[CC.red,CC.orange,CC.amber,CC.lime,CC.green],borderRadius:4}]},options:{responsive:true,plugins:{legend:{display:false}},scales:{x:{ticks:{color:"#999"},grid:{color:"#333"}},y:{ticks:{color:"#999",stepSize:1},grid:{color:"#333"}}}}}));
    var svL=Object.keys(a.storeVisits),svV=Object.values(a.storeVisits);
    if(svL.length){var sc=svL.map(function(_,i){return [CC.blue,CC.green,CC.orange,CC.purple,CC.red,CC.teal,CC.pink,CC.amber,CC.indigo,CC.lime][i%10]});udCharts.push(createChart(document.getElementById("udS"),{type:"doughnut",data:{labels:svL,datasets:[{data:svV,backgroundColor:sc,borderWidth:0}]},options:{responsive:true,plugins:{legend:{labels:{color:"#e0e0e0"},position:"bottom"}}}}));}
    var ssL=Object.keys(a.storeSpending),ssV=Object.values(a.storeSpending);
    if(ssL.length) udCharts.push(createChart(document.getElementById("udSS"),{type:"bar",data:{labels:ssL,datasets:[{label:"$",data:ssV,backgroundColor:CC.blue,borderRadius:4}]},options:{responsive:true,indexAxis:"y",plugins:{legend:{display:false}},scales:{x:{ticks:{color:"#999",callback:function(v){return "$"+v}},grid:{color:"#333"}},y:{ticks:{color:"#999"},grid:{color:"#333"}}}}}));
    udCharts.push(createChart(document.getElementById("udW"),{type:"radar",data:{labels:["Sun","Mon","Tue","Wed","Thu","Fri","Sat"],datasets:[{label:"Purchases",data:a.weekdayDistribution,borderColor:CC.green,backgroundColor:CC.greenA,pointBackgroundColor:CC.green}]},options:{responsive:true,plugins:{legend:{display:false}},scales:{r:{ticks:{color:"#999",stepSize:1,backdropColor:"transparent"},grid:{color:"#444"},angleLines:{color:"#444"},pointLabels:{color:"#e0e0e0"}}}}}));
    var tpL=Object.keys(a.topTerpenes),tpV=Object.values(a.topTerpenes);
    if(tpL.length){var tc=tpL.map(function(_,i){return ["#4caf50","#009688","#cddc39","#2196f3","#9c27b0","#ff9800","#e91e63","#ffc107","#3f51b5","#f44336"][i%10]});udCharts.push(createChart(document.getElementById("udTP"),{type:"polarArea",data:{labels:tpL,datasets:[{data:tpV,backgroundColor:tc.map(function(c){return c+"99"}),borderWidth:0}]},options:{responsive:true,plugins:{legend:{labels:{color:"#e0e0e0"},position:"bottom"}},scales:{r:{ticks:{color:"#999",backdropColor:"transparent"},grid:{color:"#444"}}}}}));}
    var efL=Object.keys(a.topEffects),efV=Object.values(a.topEffects);
    if(efL.length) udCharts.push(createChart(document.getElementById("udEF"),{type:"bar",data:{labels:efL,datasets:[{label:"Count",data:efV,backgroundColor:CC.purple,borderRadius:4}]},options:{responsive:true,indexAxis:"y",plugins:{legend:{display:false}},scales:{x:{ticks:{color:"#999",stepSize:1},grid:{color:"#333"}},y:{ticks:{color:"#999"},grid:{color:"#333"}}}}}));

    var sl = document.getElementById("udStrains");
    if(d.strains.length) {
      sl.innerHTML = d.strains.map(function(s) {
        var tc2 = s.type?"type-"+s.type:"";
        var stars = s.rating?"&#9733;".repeat(s.rating)+"&#9734;".repeat(5-s.rating):"";
        return "<div class=\\"strain-mini-card\\"><div>"+
          "<div class=\\"strain-mini-name\\">"+esc(s.name)+(s.type?" <span class=\\"strain-type "+tc2+"\\" style=\\"font-size:10px;margin-left:6px\\">"+s.type+"<\\/span>":"")+"<\\/div>"+
          "<div class=\\"strain-mini-meta\\">"+
          (s.store?"<span>"+esc(s.store)+"<\\/span>":"")+
          (s.price?"<span>$"+parseFloat(s.price).toFixed(2)+"<\\/span>":"")+
          (s.thc?"<span>THC:"+esc(s.thc)+"%<\\/span>":"")+
          (s.weight?"<span>"+esc(s.weight)+"<\\/span>":"")+
          (s.purchaseDate?"<span>"+s.purchaseDate+"<\\/span>":"")+
          "<\\/div><\\/div>"+
          "<div style=\\"text-align:right\\">"+(stars?"<div class=\\"strain-rating\\">"+stars+"<\\/div>":"")+"<\\/div>"+
          "<\\/div>";
      }).join("");
    } else { sl.innerHTML = "<div style=\\"color:var(--text2);font-size:13px;padding:16px\\">No strains.<\\/div>"; }
  } catch(e) { el.innerHTML = "<div style=\\"color:var(--red);padding:20px\\">"+e.message+"<\\/div>"; }
}

function downloadCSV() {
  fetch(API_URL+"?action=admin-export-csv", { headers:{"Authorization":"Bearer "+token} })
    .then(function(r) { if(!r.ok) throw new Error("Export failed"); return r.blob(); })
    .then(function(blob) {
      var url = URL.createObjectURL(blob);
      var a = document.createElement("a"); a.href = url;
      a.download = "all_users_"+new Date().toISOString().split("T")[0]+".csv";
      a.click(); URL.revokeObjectURL(url); toast("CSV downloaded");
    }).catch(function(e) { toast(e.message,"error"); });
}

if (token) {
  api("admin-stats").then(function(){showDashboard()}).catch(function() {
    token = ""; localStorage.removeItem("admin_token");
  });
} else {
  document.getElementById("loginView").classList.remove("hidden");
}
<\/script>
</body>
<\/html>';
        exit;

    // ---- ADMIN ----
    case 'admin-users':
        $userId = authenticateRequest();
        requireAdmin($userId);
        $allUsers = getAllUsers();
        $result = [];
        foreach ($allUsers as $u) {
            $strains = $u['strains'] ?? [];
            $totalSpent = 0;
            $stores = [];
            $types = ['indica' => 0, 'sativa' => 0, 'hybrid' => 0];
            $purchaseDates = [];
            foreach ($strains as $s) {
                $totalSpent += floatval($s['price'] ?? 0);
                if (!empty($s['store'])) $stores[$s['store']] = ($stores[$s['store']] ?? 0) + 1;
                if (isset($types[$s['type'] ?? ''])) $types[$s['type']]++;
                if (!empty($s['purchaseDate'])) $purchaseDates[] = $s['purchaseDate'];
            }
            $result[] = [
                'id' => $u['id'],
                'username' => $u['username'],
                'displayName' => $u['displayName'] ?? $u['username'],
                'isAdmin' => isAdmin($u['id']),
                'createdAt' => $u['createdAt'] ?? '',
                'strainCount' => count($strains),
                'totalSpent' => round($totalSpent, 2),
                'storeCount' => count($stores),
                'topStore' => $stores ? array_search(max($stores), $stores) : '',
                'types' => $types,
                'visitCount' => count(array_unique($purchaseDates)),
            ];
        }
        jsonResponse($result);
        break;

    case 'admin-user-detail':
        $userId = authenticateRequest();
        requireAdmin($userId);
        $targetId = $_GET['id'] ?? '';
        if (empty($targetId)) errorResponse('User ID required');
        $targetUser = loadUser($targetId);
        if (!$targetUser) errorResponse('User not found', 404);

        $strains = $targetUser['strains'] ?? [];
        $totalSpent = 0;
        $stores = [];
        $monthlySpending = [];
        $strainsByType = ['indica' => 0, 'sativa' => 0, 'hybrid' => 0];
        $ratingDist = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
        $purchaseDates = [];
        $storeSpending = [];
        $terpFreq = [];
        $effectFreq = [];
        $weekdayCounts = [0,0,0,0,0,0,0]; // Sun-Sat

        foreach ($strains as $s) {
            $price = floatval($s['price'] ?? 0);
            $totalSpent += $price;
            $store = $s['store'] ?? '';
            if ($store) {
                $stores[$store] = ($stores[$store] ?? 0) + 1;
                $storeSpending[$store] = ($storeSpending[$store] ?? 0) + $price;
            }
            $type = $s['type'] ?? 'hybrid';
            if (isset($strainsByType[$type])) $strainsByType[$type]++;

            $rating = intval($s['rating'] ?? 0);
            if ($rating >= 1 && $rating <= 5) $ratingDist[$rating]++;

            $date = $s['purchaseDate'] ?? '';
            if ($date) {
                $purchaseDates[] = $date;
                $month = substr($date, 0, 7);
                $monthlySpending[$month] = ($monthlySpending[$month] ?? 0) + $price;
                $dow = date('w', strtotime($date));
                $weekdayCounts[$dow]++;
            }

            foreach ($s['terpenes'] ?? [] as $t) {
                $terpFreq[$t] = ($terpFreq[$t] ?? 0) + 1;
            }
            foreach ($s['effects'] ?? [] as $e) {
                $effectFreq[$e] = ($effectFreq[$e] ?? 0) + 1;
            }
        }

        ksort($monthlySpending);
        arsort($terpFreq);
        arsort($effectFreq);
        arsort($stores);
        arsort($storeSpending);

        $rated = array_filter($strains, function($s) { return ($s['rating'] ?? 0) > 0; });
        $avgRating = count($rated) ? round(array_sum(array_map(function($s) { return $s['rating']; }, $rated)) / count($rated), 1) : 0;

        jsonResponse([
            'id' => $targetUser['id'],
            'username' => $targetUser['username'],
            'displayName' => $targetUser['displayName'] ?? $targetUser['username'],
            'isAdmin' => isAdmin($targetUser['id']),
            'createdAt' => $targetUser['createdAt'] ?? '',
            'settings' => $targetUser['settings'] ?? [],
            'strains' => $strains,
            'analytics' => [
                'totalStrains' => count($strains),
                'totalSpent' => round($totalSpent, 2),
                'avgPrice' => count($strains) ? round($totalSpent / count($strains), 2) : 0,
                'avgRating' => $avgRating,
                'storeCount' => count($stores),
                'uniqueVisitDays' => count(array_unique($purchaseDates)),
                'strainsByType' => $strainsByType,
                'ratingDistribution' => $ratingDist,
                'monthlySpending' => $monthlySpending,
                'storeVisits' => $stores,
                'storeSpending' => $storeSpending,
                'topTerpenes' => array_slice($terpFreq, 0, 10, true),
                'topEffects' => array_slice($effectFreq, 0, 10, true),
                'weekdayDistribution' => $weekdayCounts,
            ],
        ]);
        break;

    case 'admin-delete-user':
        if ($method !== 'POST') errorResponse('POST required', 405);
        $userId = authenticateRequest();
        requireAdmin($userId);
        $input = getInput();
        $targetId = $input['userId'] ?? '';
        if (empty($targetId)) errorResponse('User ID required');
        if ($targetId === $userId) errorResponse('Cannot delete yourself');

        $targetUser = loadUser($targetId);
        if (!$targetUser) errorResponse('User not found', 404);

        // Delete user file
        $userFile = getUserFile($targetId);
        if (file_exists($userFile)) unlink($userFile);

        // Delete user uploads
        $uploadDir = UPLOAD_DIR . '/' . $targetId;
        if (is_dir($uploadDir)) {
            $it = new RecursiveDirectoryIterator($uploadDir, RecursiveDirectoryIterator::SKIP_DOTS);
            $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $f) {
                if ($f->isDir()) rmdir($f->getRealPath());
                else unlink($f->getRealPath());
            }
            rmdir($uploadDir);
        }

        // Delete user backups
        $backupDir = DATA_DIR . '/backups/' . $targetId;
        if (is_dir($backupDir)) {
            array_map('unlink', glob($backupDir . '/*'));
            rmdir($backupDir);
        }

        // Remove user tokens
        $tokens = loadTokens();
        foreach ($tokens as $tk => $td) {
            if ($td['userId'] === $targetId) unset($tokens[$tk]);
        }
        saveTokens($tokens);

        jsonResponse(['success' => true]);
        break;

    case 'admin-stats':
        $userId = authenticateRequest();
        requireAdmin($userId);
        $allUsers = getAllUsers();
        $totalStrains = 0;
        $totalSpent = 0;
        $allStores = [];
        $allTypes = ['indica' => 0, 'sativa' => 0, 'hybrid' => 0];
        $monthlyGlobal = [];
        $userGrowth = [];

        foreach ($allUsers as $u) {
            $month = substr($u['createdAt'] ?? '', 0, 7);
            if ($month) $userGrowth[$month] = ($userGrowth[$month] ?? 0) + 1;

            foreach ($u['strains'] ?? [] as $s) {
                $totalStrains++;
                $totalSpent += floatval($s['price'] ?? 0);
                if (!empty($s['store'])) $allStores[$s['store']] = ($allStores[$s['store']] ?? 0) + 1;
                $type = $s['type'] ?? 'hybrid';
                if (isset($allTypes[$type])) $allTypes[$type]++;
                $date = $s['purchaseDate'] ?? '';
                if ($date) {
                    $m = substr($date, 0, 7);
                    $monthlyGlobal[$m] = ($monthlyGlobal[$m] ?? 0) + floatval($s['price'] ?? 0);
                }
            }
        }

        ksort($monthlyGlobal);
        ksort($userGrowth);
        arsort($allStores);

        jsonResponse([
            'totalUsers' => count($allUsers),
            'totalStrains' => $totalStrains,
            'totalSpent' => round($totalSpent, 2),
            'avgStrainsPerUser' => count($allUsers) ? round($totalStrains / count($allUsers), 1) : 0,
            'storeCount' => count($allStores),
            'strainsByType' => $allTypes,
            'topStores' => array_slice($allStores, 0, 10, true),
            'monthlySpending' => $monthlyGlobal,
            'userGrowth' => $userGrowth,
        ]);
        break;

    case 'admin-export-csv':
        $userId = authenticateRequest();
        requireAdmin($userId);
        $allUsers = getAllUsers();

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="all_users_export_' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');

        fputcsv($out, [
            'Username', 'Display Name', 'Joined', 'Strain Name', 'Type',
            'Store', 'Price', 'Weight', 'THC %', 'CBD %', 'Rating',
            'Purchase Date', 'Terpenes', 'Effects', 'Batch Info', 'Review'
        ]);

        foreach ($allUsers as $u) {
            $username = $u['username'] ?? '';
            $displayName = $u['displayName'] ?? $username;
            $joined = $u['createdAt'] ?? '';

            $strains = $u['strains'] ?? [];
            if (empty($strains)) {
                fputcsv($out, [$username, $displayName, $joined, '', '', '', '', '', '', '', '', '', '', '', '', '']);
            } else {
                foreach ($strains as $s) {
                    fputcsv($out, [
                        $username,
                        $displayName,
                        $joined,
                        $s['name'] ?? '',
                        $s['type'] ?? '',
                        $s['store'] ?? '',
                        $s['price'] ?? 0,
                        $s['weight'] ?? '',
                        $s['thc'] ?? '',
                        $s['cbd'] ?? '',
                        $s['rating'] ?? 0,
                        $s['purchaseDate'] ?? '',
                        implode(', ', $s['terpenes'] ?? []),
                        implode(', ', $s['effects'] ?? []),
                        $s['batchInfo'] ?? '',
                        $s['review'] ?? '',
                    ]);
                }
            }
        }

        fclose($out);
        exit;

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
                'GET /api.php?action=admin-users',
                'GET /api.php?action=admin-user-detail&id=X',
                'POST /api.php?action=admin-delete-user',
                'GET /api.php?action=admin-stats',
                'GET /api.php?action=admin-export-csv',
                'GET /api.php?action=admin-panel',
            ]
        ]);
}
