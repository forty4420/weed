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
    // Check if user is blocked (skip for admin tokens)
    if (empty($t['isAdminToken'])) {
        $u = loadUser($t['userId']);
        if ($u && !empty($u['blocked'])) {
            unset($tokens[$token]);
            saveTokens($tokens);
            errorResponse('Account suspended', 403);
        }
    }
    return $t['userId'];
}

function isAdmin($userId) {
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

    // ---- AUTH ----
    case 'register':
        if ($method !== 'POST') errorResponse('POST required', 405);
        $input = getInput();
        $username = trim($input['username'] ?? '');
        $password = $input['password'] ?? '';
        $email = trim($input['email'] ?? '');
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
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            errorResponse('A valid email address is required');
        }

        $userId = strtolower($username);
        if (loadUser($userId)) {
            errorResponse('Username already taken');
        }

        $user = [
            'id' => $userId,
            'username' => $username,
            'displayName' => $displayName,
            'email' => $email,
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
        if (!empty($user['blocked'])) {
            errorResponse('Your account has been suspended. Contact the administrator.', 403);
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
                'email' => $user['email'] ?? '',
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
            if (isset($input['email'])) {
                $email = trim($input['email']);
                if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    errorResponse('Invalid email address');
                }
                $user['email'] = $email;
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

    // ---- ADMIN ----
    // ---- ADMIN PASSWORD-ONLY LOGIN ----
    case 'admin-login':
        if ($method !== 'POST') errorResponse('POST required', 405);
        $input = getInput();
        $password = $input['password'] ?? '';
        if ($password !== ADMIN_PASSWORD) {
            errorResponse('Invalid admin password', 401);
        }
        $token = generateToken();
        $tokens = loadTokens();
        // Store as admin token mapped to the first admin user
        $adminUser = ADMIN_USERS[0] ?? 'admin';
        $tokens[$token] = ['userId' => $adminUser, 'expires' => time() + TOKEN_EXPIRY, 'isAdminToken' => true];
        saveTokens($tokens);
        jsonResponse(['token' => $token, 'message' => 'Admin authenticated']);
        break;

    // ---- ADMIN BLOCK/UNBLOCK USER ----
    case 'admin-block-user':
        if ($method !== 'POST') errorResponse('POST required', 405);
        $userId = authenticateRequest();
        requireAdmin($userId);
        $input = getInput();
        $targetId = $input['userId'] ?? '';
        $block = $input['block'] ?? true;
        if (empty($targetId)) errorResponse('User ID required');
        if ($targetId === $userId) errorResponse('Cannot block yourself');

        $targetUser = loadUser($targetId);
        if (!$targetUser) errorResponse('User not found', 404);

        $targetUser['blocked'] = (bool)$block;
        $targetUser['blockedAt'] = $block ? date('c') : null;
        saveUser($targetId, $targetUser);

        // If blocking, also invalidate their tokens
        if ($block) {
            $tokens = loadTokens();
            foreach ($tokens as $tk => $td) {
                if ($td['userId'] === $targetId && empty($td['isAdminToken'])) {
                    unset($tokens[$tk]);
                }
            }
            saveTokens($tokens);
        }

        jsonResponse(['success' => true, 'blocked' => (bool)$block]);
        break;

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
                'email' => $u['email'] ?? '',
                'isAdmin' => isAdmin($u['id']),
                'blocked' => !empty($u['blocked']),
                'blockedAt' => $u['blockedAt'] ?? null,
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
            'email' => $targetUser['email'] ?? '',
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
        $yearlyGlobal = [];
        $weeklyGlobal = [];
        $userGrowth = [];
        $strainCounts = [];
        $totalRatings = 0;
        $ratedCount = 0;
        $ratingDist = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
        $allTerpenes = [];
        $allEffects = [];
        $blockedCount = 0;
        $purchasesByUser = [];

        foreach ($allUsers as $u) {
            $month = substr($u['createdAt'] ?? '', 0, 7);
            if ($month) $userGrowth[$month] = ($userGrowth[$month] ?? 0) + 1;
            if (!empty($u['blocked'])) $blockedCount++;

            $userSpent = 0;
            foreach ($u['strains'] ?? [] as $s) {
                $totalStrains++;
                $price = floatval($s['price'] ?? 0);
                $totalSpent += $price;
                $userSpent += $price;
                if (!empty($s['store'])) $allStores[$s['store']] = ($allStores[$s['store']] ?? 0) + 1;
                $type = $s['type'] ?? 'hybrid';
                if (isset($allTypes[$type])) $allTypes[$type]++;
                $date = $s['purchaseDate'] ?? '';
                if ($date) {
                    $m = substr($date, 0, 7);
                    $y = substr($date, 0, 4);
                    $monthlyGlobal[$m] = ($monthlyGlobal[$m] ?? 0) + $price;
                    $yearlyGlobal[$y] = ($yearlyGlobal[$y] ?? 0) + $price;
                    // ISO week
                    $ts = strtotime($date);
                    if ($ts) {
                        $wk = date('o-\WW', $ts);
                        $weeklyGlobal[$wk] = ($weeklyGlobal[$wk] ?? 0) + $price;
                    }
                }
                $name = strtolower(trim($s['name'] ?? ''));
                if ($name) $strainCounts[$name] = ($strainCounts[$name] ?? ['count' => 0, 'name' => $s['name'], 'totalSpent' => 0, 'totalRating' => 0, 'rated' => 0]);
                if ($name) {
                    $strainCounts[$name]['count']++;
                    $strainCounts[$name]['totalSpent'] += $price;
                    $rating = intval($s['rating'] ?? 0);
                    if ($rating >= 1 && $rating <= 5) {
                        $strainCounts[$name]['totalRating'] += $rating;
                        $strainCounts[$name]['rated']++;
                    }
                }
                $rating = intval($s['rating'] ?? 0);
                if ($rating >= 1 && $rating <= 5) {
                    $totalRatings += $rating;
                    $ratedCount++;
                    $ratingDist[$rating]++;
                }
                foreach ($s['terpenes'] ?? [] as $t) {
                    $allTerpenes[$t] = ($allTerpenes[$t] ?? 0) + 1;
                }
                foreach ($s['effects'] ?? [] as $e) {
                    $allEffects[$e] = ($allEffects[$e] ?? 0) + 1;
                }
            }
            $purchasesByUser[$u['username'] ?? $u['id']] = $userSpent;
        }

        ksort($monthlyGlobal);
        ksort($yearlyGlobal);
        ksort($weeklyGlobal);
        ksort($userGrowth);
        arsort($allStores);
        arsort($strainCounts);
        arsort($allTerpenes);
        arsort($allEffects);
        arsort($purchasesByUser);

        // Build top 10 strains
        $topStrains = [];
        $i = 0;
        foreach ($strainCounts as $key => $data) {
            if ($i >= 10) break;
            $topStrains[] = [
                'name' => $data['name'],
                'count' => $data['count'],
                'totalSpent' => round($data['totalSpent'], 2),
                'avgRating' => $data['rated'] > 0 ? round($data['totalRating'] / $data['rated'], 1) : null,
            ];
            $i++;
        }

        jsonResponse([
            'totalUsers' => count($allUsers),
            'totalStrains' => $totalStrains,
            'totalSpent' => round($totalSpent, 2),
            'avgStrainsPerUser' => count($allUsers) ? round($totalStrains / count($allUsers), 1) : 0,
            'avgRating' => $ratedCount > 0 ? round($totalRatings / $ratedCount, 1) : 0,
            'storeCount' => count($allStores),
            'blockedUsers' => $blockedCount,
            'strainsByType' => $allTypes,
            'ratingDistribution' => $ratingDist,
            'topStores' => array_slice($allStores, 0, 10, true),
            'topStrains' => $topStrains,
            'topTerpenes' => array_slice($allTerpenes, 0, 10, true),
            'topEffects' => array_slice($allEffects, 0, 10, true),
            'monthlySpending' => $monthlyGlobal,
            'yearlySpending' => $yearlyGlobal,
            'weeklySpending' => array_slice($weeklyGlobal, -26, null, true), // last 26 weeks
            'spendingByUser' => array_slice($purchasesByUser, 0, 10, true),
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
            'Username', 'Display Name', 'Email', 'Joined', 'Strain Name', 'Type',
            'Store', 'Price', 'Weight', 'THC %', 'CBD %', 'Rating',
            'Purchase Date', 'Terpenes', 'Effects', 'Batch Info', 'Review'
        ]);

        foreach ($allUsers as $u) {
            $username = $u['username'] ?? '';
            $displayName = $u['displayName'] ?? $username;
            $email = $u['email'] ?? '';
            $joined = $u['createdAt'] ?? '';

            $strains = $u['strains'] ?? [];
            if (empty($strains)) {
                fputcsv($out, [$username, $displayName, $email, $joined, '', '', '', '', '', '', '', '', '', '', '', '', '']);
            } else {
                foreach ($strains as $s) {
                    fputcsv($out, [
                        $username,
                        $displayName,
                        $email,
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
            ]
        ]);
}
