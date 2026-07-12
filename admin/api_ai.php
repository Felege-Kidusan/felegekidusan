<?php
/**
 * ============================================================
 * AI Assistant API — Powered by Google Gemini (Free Tier)
 * ============================================================
 * Provides AI-powered data analysis, reports, and chat
 * Uses: gemini-2.0-flash (free: 15 req/min, 1M tokens/day)
 * 
 * Security: Auth via $_SESSION, CSRF via validateCsrf(), SQL via prepare()
 * ============================================================
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/backend/ai_providers.php';

/**
 * Provider-agnostic generation used by chat / insights / reports.
 * Routes through the multi-provider layer (ai_chat) and falls back to the
 * legacy Gemini path only if the new layer is unavailable.
 * Returns ['text'=>..., 'tokens'=>0] on success, or ['error'=>clean message].
 */
function aiGenerate($conn, $userPrompt, $systemPrompt, $temperature) {
    if (function_exists('ai_chat')) {
        $msgs = [];
        if ($systemPrompt !== '') $msgs[] = ['role' => 'system', 'content' => $systemPrompt];
        $msgs[] = ['role' => 'user', 'content' => $userPrompt];
        $res = ai_chat($conn, $msgs, ['temperature' => $temperature, 'max_tokens' => 2048, 'timeout' => 45]);
        if (!empty($res['ok'])) return ['text' => $res['text'], 'tokens' => 0];
        return ['error' => $res['error'] ?? 'AI request failed.'];
    }
    $apiKey = getGeminiApiKey($conn);
    if (!$apiKey) return ['error' => 'AI is not configured.'];
    return callGemini($apiKey, $userPrompt, $systemPrompt, $temperature);
}

// Authentication
if (empty($_SESSION['admin_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

// CSRF for POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!validateCsrf($csrfToken)) {
        echo json_encode(['status' => 'error', 'message' => 'Security token expired. Please refresh.']);
        exit;
    }
}

$action = $_REQUEST['action'] ?? '';

// ══════════════════════════════════════════════════════════════
// GEMINI API CONFIGURATION
// ══════════════════════════════════════════════════════════════

// Load API key from database settings or fallback
function getGeminiApiKey($conn) {
    // Try from settings table first
    try {
        $r = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'gemini_api_key' LIMIT 1");
        if ($r && $row = $r->fetch_assoc()) {
            $key = trim($row['setting_value']);
            if (!empty($key)) return $key;
        }
    } catch (Exception $e) {}
    
    // Fallback: check if defined as constant
    if (defined('GEMINI_API_KEY') && !empty(GEMINI_API_KEY)) {
        return GEMINI_API_KEY;
    }
    
    return null;
}

// Ensure system_settings table exists
try {
    $conn->query("CREATE TABLE IF NOT EXISTS `system_settings` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `setting_key` VARCHAR(100) NOT NULL,
        `setting_value` TEXT DEFAULT NULL,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_key` (`setting_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Exception $e) {}

// Ensure ai_chat_history table exists (for context)
try {
    $conn->query("CREATE TABLE IF NOT EXISTS `ai_chat_history` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id` INT UNSIGNED NOT NULL,
        `role` ENUM('user','assistant') NOT NULL,
        `message` TEXT NOT NULL,
        `data_context` TEXT DEFAULT NULL COMMENT 'JSON snapshot of data sent to AI',
        `tokens_used` INT UNSIGNED DEFAULT 0,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `user_id` (`user_id`),
        KEY `created_at` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Exception $e) {}


// ══════════════════════════════════════════════════════════════
// CALL GEMINI API — with automatic model fallback
// ══════════════════════════════════════════════════════════════
function callGemini($apiKey, $prompt, $systemPrompt = '', $temperature = 0.7) {
    // Model priority (free tier quota as of 2026):
    // 1. gemini-2.5-flash      — 500 req/day, 10 req/min (best quality)
    // 2. gemini-2.5-flash-lite — 1000 req/day, 30 req/min (fastest)
    // 3. gemini-2.0-flash-lite — fallback if above fail
    // NOTE: gemini-2.0-flash has 0 free quota since Dec 2025
    $models = ['gemini-2.5-flash', 'gemini-2.5-flash-lite', 'gemini-2.0-flash-lite'];
    
    $lastError = 'No models available';
    
    foreach ($models as $model) {
        $result = callGeminiModel($apiKey, $model, $prompt, $systemPrompt, $temperature);
        
        if (!isset($result['error'])) {
            $result['model_used'] = $model;
            return $result;
        }
        
        // If it's a quota/rate limit error, try next model
        $err = strtolower($result['error']);
        if (strpos($err, 'quota') !== false || strpos($err, 'rate') !== false || strpos($err, 'limit') !== false || strpos($err, '429') !== false) {
            $lastError = $result['error'] . " (model: $model)";
            continue;
        }
        
        // For other errors (invalid key, bad request, etc), don't retry
        return $result;
    }
    
    return ['error' => "All models exceeded quota. $lastError\n\nTip: Quota resets daily at midnight Pacific Time. Try again later, or create a new Google Cloud project with a fresh API key at aistudio.google.com/apikey"];
}

function callGeminiModel($apiKey, $model, $prompt, $systemPrompt, $temperature) {
    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . urlencode($apiKey);
    
    $contents = [];
    
    // System instruction
    $systemInstruction = null;
    if (!empty($systemPrompt)) {
        $systemInstruction = ['parts' => [['text' => $systemPrompt]]];
    }
    
    // User message
    $contents[] = [
        'role' => 'user',
        'parts' => [['text' => $prompt]]
    ];
    
    $payload = [
        'contents' => $contents,
        'generationConfig' => [
            'temperature' => $temperature,
            'maxOutputTokens' => 4096,
        ]
    ];
    
    if ($systemInstruction) {
        $payload['systemInstruction'] = $systemInstruction;
    }
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        return ['error' => "Connection failed: $curlError"];
    }
    
    $data = json_decode($response, true);
    
    if ($httpCode !== 200) {
        $errMsg = $data['error']['message'] ?? "API returned HTTP $httpCode";
        return ['error' => $errMsg];
    }
    
    // Extract text from response
    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
    $tokens = $data['usageMetadata']['totalTokenCount'] ?? 0;
    
    if (!$text) {
        return ['error' => 'No response generated'];
    }
    
    return ['text' => $text, 'tokens' => $tokens];
}


// ══════════════════════════════════════════════════════════════
// COLLECT DATA SNAPSHOTS FROM DATABASE
// ══════════════════════════════════════════════════════════════
function collectDataSnapshot($conn, $scope = 'overview') {
    $data = [];
    $role = $_SESSION['admin_role'] ?? '';
    
    // Role-based scope restrictions
    $blockedScopes = [
        'teacher' => ['finance'],
        'attendance_taker' => ['finance', 'education', 'groups'],
        'info_dept' => ['finance'],
        'edu_dept' => ['finance'],
        'material_dept' => ['finance', 'education'],
    ];
    
    $blocked = $blockedScopes[$role] ?? [];
    if (in_array($scope, $blocked)) {
        return ['access_denied' => 'Your role does not have access to this data category.'];
    }
    
    // ── Always include overview stats ──
    try {
        $r = $conn->query("SELECT 
            COUNT(*) as total_members,
            SUM(status='active') as active,
            SUM(status='inactive') as inactive,
            SUM(status='warning') as warning,
            SUM(gender='male') as male,
            SUM(gender='female') as female,
            SUM(member_type='regular') as regular,
            SUM(member_type='special_regular') as special,
            SUM(registration_type='waiting') as waiting,
            SUM(registration_type='transfer') as transfer,
            SUM(registration_type='direct') as direct,
            SUM(phone_number IS NOT NULL AND phone_number!='') as has_phone,
            SUM(student_photo_path IS NOT NULL AND student_photo_path!='') as has_photo,
            SUM(age_group='under6') as age_under6,
            SUM(age_group='7_13') as age_7_13,
            SUM(age_group='14_17') as age_14_17,
            SUM(age_group='18_plus' OR age_group='18+') as age_18plus
        FROM members WHERE status != 'archived'");
        if ($r) $data['members_overview'] = $r->fetch_assoc();
    } catch (Exception $e) { $data['members_overview'] = ['error' => 'Query failed']; }
    
    // ── Registration trends (last 12 months) ──
    if (in_array($scope, ['overview', 'members', 'trends'])) {
        try {
            $r = $conn->query("SELECT DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as count 
                FROM members WHERE status != 'archived' AND created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                GROUP BY month ORDER BY month");
            $data['registration_trends'] = [];
            if ($r) while ($row = $r->fetch_assoc()) $data['registration_trends'][] = $row;
        } catch (Exception $e) {}
    }
    
    // ── Attendance data ──
    if (in_array($scope, ['overview', 'attendance'])) {
        try {
            $r = $conn->query("SELECT 
                COUNT(DISTINCT attendance_date) as total_days,
                COUNT(*) as total_records,
                SUM(status='present') as present_count,
                SUM(status='absent') as absent_count,
                SUM(status='late') as late_count,
                SUM(status='excused') as excused_count
            FROM attendance WHERE attendance_date >= DATE_SUB(NOW(), INTERVAL 3 MONTH)");
            if ($r) $data['attendance_summary'] = $r->fetch_assoc();
        } catch (Exception $e) { $data['attendance_summary'] = null; }
        
        // Weekly attendance pattern
        try {
            $r = $conn->query("SELECT DAYNAME(attendance_date) as day_name, 
                COUNT(*) as total, SUM(status='present') as present_cnt
                FROM attendance WHERE attendance_date >= DATE_SUB(NOW(), INTERVAL 3 MONTH)
                GROUP BY day_name ORDER BY FIELD(day_name, 'Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday')");
            $data['attendance_by_day'] = [];
            if ($r) while ($row = $r->fetch_assoc()) $data['attendance_by_day'][] = $row;
        } catch (Exception $e) {}
    }
    
    // ── Education / Classes data ──
    if (in_array($scope, ['overview', 'education'])) {
        try {
            $r = $conn->query("SELECT c.class_name, c.level_order,
                (SELECT COUNT(*) FROM class_enrollments ce WHERE ce.class_id=c.id AND ce.status='active') as students
                FROM classes c WHERE c.is_active=1 ORDER BY c.level_order");
            $data['classes'] = [];
            if ($r) while ($row = $r->fetch_assoc()) $data['classes'][] = $row;
        } catch (Exception $e) {}
        
        try {
            $r = $conn->query("SELECT COUNT(*) as cnt FROM users WHERE role='teacher' AND is_active=1");
            if ($r) $data['active_teachers'] = (int)$r->fetch_assoc()['cnt'];
        } catch (Exception $e) {}
        
        try {
            $r = $conn->query("SELECT COUNT(*) as cnt FROM subjects WHERE is_active=1");
            if ($r) $data['active_subjects'] = (int)$r->fetch_assoc()['cnt'];
        } catch (Exception $e) {}
    }
    
    // ── Finance data (only for finance/admin roles) ──
    $financeRoles = ['super_admin', 'school_admin', 'finance_dept'];
    if (in_array($scope, ['overview', 'finance']) && in_array($role, $financeRoles)) {
        try {
            $r = $conn->query("SELECT 
                COALESCE(SUM(CASE WHEN type='income' AND status='confirmed' THEN amount END), 0) as total_income,
                COALESCE(SUM(CASE WHEN type='expense' AND status='confirmed' THEN amount END), 0) as total_expense,
                COALESCE(SUM(CASE WHEN status='pending' THEN amount END), 0) as pending_amount,
                COUNT(CASE WHEN status='pending' THEN 1 END) as pending_count
            FROM finance_transactions");
            if ($r) $data['finance_summary'] = $r->fetch_assoc();
        } catch (Exception $e) { $data['finance_summary'] = null; }
    }
    
    // ── Groups data ──
    if (in_array($scope, ['overview', 'groups'])) {
        try {
            $r = $conn->query("SELECT g.group_name, g.current_male, g.current_female, 
                (g.current_male + g.current_female) as total_members
                FROM wbws_groups g ORDER BY total_members DESC LIMIT 15");
            $data['groups'] = [];
            if ($r) while ($row = $r->fetch_assoc()) $data['groups'][] = $row;
        } catch (Exception $e) {}
    }
    
    // ── Users / System ──
    try {
        $r = $conn->query("SELECT role, COUNT(*) as cnt, SUM(is_active=1) as active_cnt 
            FROM users GROUP BY role");
        $data['users_by_role'] = [];
        if ($r) while ($row = $r->fetch_assoc()) $data['users_by_role'][] = $row;
    } catch (Exception $e) {}
    
    return $data;
}


// ══════════════════════════════════════════════════════════════
// SYSTEM PROMPT — Role-aware, correct school identity
// ══════════════════════════════════════════════════════════════
function getSystemPrompt($conn = null) {
    // Get school name from config constants (set in config.php)
    $schoolName = defined('SITE_NAME') ? SITE_NAME : SCHOOL_NAME;
    $schoolNameAm = defined('SITE_NAME_AMHARIC') ? SITE_NAME_AMHARIC : '';
    
    // Also try to get from branding table
    if ($conn) {
        try {
            $r = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'school_name' LIMIT 1");
            if ($r && $row = $r->fetch_assoc()) {
                $name = trim($row['setting_value']);
                if (!empty($name)) $schoolName = $name;
            }
        } catch (Exception $e) {}
    }
    
    $role = $_SESSION['admin_role'] ?? 'unknown';
    $roleName = str_replace('_', ' ', ucfirst($role));
    
    // Role-based data access rules
    $roleRules = [
        'super_admin' => 'You have full access to all school data including members, attendance, finance, education, and system health.',
        'school_admin' => 'You have access to all school data including members, attendance, finance, and education.',
        'info_dept' => 'You have access to member data, registrations, and demographics. You do NOT have access to financial data.',
        'edu_dept' => 'You have access to education data: classes, enrollments, teachers, subjects, and grades. You do NOT have access to financial data.',
        'finance_dept' => 'You have access to financial data: income, expenses, and budgets. You do NOT have access to individual member details.',
        'material_dept' => 'You have access to material/resource inventory data. You have limited access to other data.',
        'teacher' => 'You have access to your class data, student attendance, and grades. You do NOT have access to finance or system settings. Do NOT reveal other teachers\' data or admin information.',
        'attendance_taker' => 'You have access to attendance records only. You do NOT have access to finance, grades, or system settings.',
    ];
    
    $accessRule = $roleRules[$role] ?? 'You have limited access. Do NOT reveal sensitive system data.';
    
    return "You are the AI Assistant for {$schoolName}" . ($schoolNameAm ? " ({$schoolNameAm})" : '') . ".
IMPORTANT: The school name is \"{$schoolName}\". ALWAYS use this exact name. Never guess or make up a different name.
" . ($schoolNameAm ? "In Amharic, the school is called: {$schoolNameAm}\n" : '') . "

CURRENT USER: {$roleName}
DATA ACCESS: {$accessRule}

CRITICAL RULES:
- NEVER reveal user passwords, API keys, or system configuration
- NEVER show individual user login details or personal emails
- NEVER expose database structure or technical internals
- Use the EXACT school name above — do not expand abbreviations differently
- If the user asks for data you should not share based on their role, politely decline

YOUR CAPABILITIES:
- Analyze member demographics, attendance patterns, registration trends
- Generate insights about student engagement and retention
- Summarize data based on what is provided to you
- Identify data quality issues and give recommendations
- Provide actionable advice for school improvement

CONTEXT:
- This is an ' . AI_SCHOOL_CONTEXT . '
- Members are students of various age groups (under 6, 7-13, 14-17, 18+)
- The school uses the Ethiopian Calendar alongside Gregorian
- Currency is Ethiopian Birr (ETB)

RESPONSE RULES:
- Be concise — users see this in a small chat panel
- Use actual numbers from the data, never invent statistics
- Format with short markdown sections
- If data is empty or null, say so honestly
- Keep responses focused and actionable";
}


// ══════════════════════════════════════════════════════════════
// ACTION HANDLERS
// ══════════════════════════════════════════════════════════════
switch ($action) {

    // ──────────────────────────────────────────────────────────
    // CHECK IF API KEY IS SET
    // ──────────────────────────────────────────────────────────
    case 'check_status':
        $active = function_exists('ai_active_config') ? ai_active_config($conn) : null;
        $reg    = function_exists('ai_provider_registry') ? ai_provider_registry() : [];
        $hasKey = false; $provLabel = 'Not configured'; $modelName = '';
        if ($active) {
            $hasKey    = (ai_decrypt($active['api_key_enc'] ?? '') !== '');
            $provLabel = $reg[$active['provider']]['label'] ?? $active['provider'];
            $modelName = $active['model'] ?? '';
        } else {
            // Legacy fallback: a bare Gemini key in system_settings still counts.
            $hasKey = !empty(getGeminiApiKey($conn));
            if ($hasKey) { $provLabel = 'Google Gemini'; $modelName = 'gemini-2.0-flash'; }
        }
        $chatCount = 0;
        try {
            $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM ai_chat_history WHERE user_id = ?");
            $stmt->bind_param("i", $_SESSION['admin_id']);
            $stmt->execute();
            $chatCount = (int)$stmt->get_result()->fetch_assoc()['cnt'];
            $stmt->close();
        } catch (Exception $e) {}

        echo json_encode([
            'status'      => 'success',
            'has_api_key' => $hasKey,
            'chat_count'  => $chatCount,
            'model'       => $modelName,
            'provider'    => $provLabel,
        ]);
        break;

    // ──────────────────────────────────────────────────────────
    // MULTI-PROVIDER CONFIG (Super Admin only)
    // ──────────────────────────────────────────────────────────
    case 'get_ai_config':
        if (($_SESSION['admin_role'] ?? '') !== 'super_admin') {
            echo json_encode(['status' => 'error', 'message' => 'Only a Super Admin can view AI settings.']); exit;
        }
        $reg  = ai_provider_registry();
        $all  = ai_all_configs($conn);
        $out  = [];
        foreach ($reg as $pid => $meta) {
            $cfg = $all[$pid] ?? null;
            $plain = $cfg ? ai_decrypt($cfg['api_key_enc'] ?? '') : '';
            $out[] = [
                'id'        => $pid,
                'label'     => $meta['label'],
                'free'      => $meta['free'],
                'signup'    => $meta['signup'],
                'keyhint'   => $meta['keyhint'],
                'needs_base'=> ($pid === 'compatible'),
                'default_model' => $meta['model'],
                'model'     => $cfg['model'] ?? $meta['model'],
                'base_url'  => $cfg['base_url'] ?? $meta['base'],
                'has_key'   => ($plain !== ''),
                'key_masked'=> ($plain !== '' ? ai_mask_key($plain) : ''),
                'is_active' => $cfg ? ((int)$cfg['is_active'] === 1) : false,
            ];
        }
        echo json_encode(['status' => 'success', 'providers' => $out]);
        break;

    case 'save_ai_config':
        if (($_SESSION['admin_role'] ?? '') !== 'super_admin') {
            echo json_encode(['status' => 'error', 'message' => 'Only a Super Admin can change AI settings.']); exit;
        }
        $provider = trim($_POST['provider'] ?? '');
        $apiKey   = trim($_POST['api_key'] ?? '');
        $model    = trim($_POST['model'] ?? '');
        $baseUrl  = trim($_POST['base_url'] ?? '');
        $active   = !empty($_POST['make_active']);
        $res = ai_save_config($conn, $provider, $apiKey, $model, $baseUrl, $active);
        echo json_encode($res['ok']
            ? ['status' => 'success', 'message' => 'Saved.' . ($active ? ' This provider is now active.' : '')]
            : ['status' => 'error', 'message' => $res['error'] ?? 'Could not save.']);
        break;

    case 'set_active_provider':
        if (($_SESSION['admin_role'] ?? '') !== 'super_admin') {
            echo json_encode(['status' => 'error', 'message' => 'Only a Super Admin can change AI settings.']); exit;
        }
        $res = ai_set_active($conn, trim($_POST['provider'] ?? ''));
        echo json_encode($res['ok']
            ? ['status' => 'success', 'message' => 'Active provider changed.']
            : ['status' => 'error', 'message' => $res['error'] ?? 'Could not switch.']);
        break;

    case 'test_connection':
        if (($_SESSION['admin_role'] ?? '') !== 'super_admin') {
            echo json_encode(['status' => 'error', 'message' => 'Only a Super Admin can test AI providers.']); exit;
        }
        $res = ai_test_connection(
            $conn,
            trim($_POST['provider'] ?? ''),
            trim($_POST['api_key'] ?? ''),
            trim($_POST['model'] ?? ''),
            trim($_POST['base_url'] ?? '')
        );
        echo json_encode($res['ok']
            ? ['status' => 'success', 'message' => 'Connection OK — the provider replied.', 'reply' => $res['text'] ?? '']
            : ['status' => 'error', 'message' => $res['error'] ?? 'Test failed.']);
        break;

    // ──────────────────────────────────────────────────────────
    // SAVE API KEY
    // ──────────────────────────────────────────────────────────
    case 'save_api_key':
        if (($_SESSION['admin_role'] ?? '') !== 'super_admin') {
            echo json_encode(['status' => 'error', 'message' => 'Only Super Admin can set the API key']);
            exit;
        }
        
        $apiKey = trim($_POST['api_key'] ?? '');
        if (empty($apiKey)) {
            echo json_encode(['status' => 'error', 'message' => 'API key cannot be empty']);
            exit;
        }
        
        // Validate key by listing available models (doesn't burn generation quota)
        $testUrl = "https://generativelanguage.googleapis.com/v1beta/models?key=" . urlencode($apiKey);
        $ch = curl_init($testUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $testResponse = curl_exec($ch);
        $testCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $testCurlErr = curl_error($ch);
        curl_close($ch);
        
        if ($testCurlErr) {
            echo json_encode(['status' => 'error', 'message' => 'Connection failed: ' . $testCurlErr]);
            exit;
        }
        
        if ($testCode === 400 || $testCode === 401 || $testCode === 403) {
            $testData = json_decode($testResponse, true);
            $errMsg = $testData['error']['message'] ?? "Invalid API key (HTTP $testCode)";
            echo json_encode(['status' => 'error', 'message' => $errMsg]);
            exit;
        }
        
        if ($testCode !== 200) {
            echo json_encode(['status' => 'error', 'message' => "API returned HTTP $testCode. Key may be invalid."]);
            exit;
        }
        
        // Save to database
        $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('gemini_api_key', ?) 
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $stmt->bind_param("s", $apiKey);
        
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'API key saved and verified!']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to save key']);
        }
        $stmt->close();
        break;

    // ──────────────────────────────────────────────────────────
    // CHAT — Main AI conversation
    // ──────────────────────────────────────────────────────────
    case 'chat':
        if (function_exists('ai_active_config') && !ai_active_config($conn) && !getGeminiApiKey($conn)) {
            echo json_encode(['status' => 'error', 'message' => 'AI is not set up yet. A Super Admin can add a provider in AI Assistant → AI Setup.']);
            exit;
        }

        $userMessage = trim($_POST['message'] ?? '');
        if (empty($userMessage)) {
            echo json_encode(['status' => 'error', 'message' => 'Message cannot be empty']);
            exit;
        }
        
        // Determine scope from message keywords
        $scope = 'overview';
        $msgLower = mb_strtolower($userMessage);
        if (preg_match('/attend|ያልመጡ|ቅዳሜ|present|absent/', $msgLower)) $scope = 'attendance';
        elseif (preg_match('/financ|money|birr|ገንዘብ|income|expense|budget/', $msgLower)) $scope = 'finance';
        elseif (preg_match('/class|grade|enroll|teacher|subject|ትምህርት/', $msgLower)) $scope = 'education';
        elseif (preg_match('/group|department|ክፍል/', $msgLower)) $scope = 'groups';
        elseif (preg_match('/member|student|ተማሪ|registr|demograph|phone|photo/', $msgLower)) $scope = 'members';
        elseif (preg_match('/trend|growth|month|year|compare|ለውጥ/', $msgLower)) $scope = 'trends';
        
        // Collect data from database
        $dataSnapshot = collectDataSnapshot($conn, $scope);
        
        // Build the prompt with data context
        $dataJson = json_encode($dataSnapshot, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        
        $fullPrompt = "Here is the current data from the ' . SCHOOL_NAME_SHORT . ' school management database:\n\n```json\n{$dataJson}\n```\n\nUser's question: {$userMessage}";
        
        // Get recent chat history for context (last 6 messages)
        $history = [];
        try {
            $stmt = $conn->prepare("SELECT role, message FROM ai_chat_history WHERE user_id = ? ORDER BY id DESC LIMIT 6");
            $stmt->bind_param("i", $_SESSION['admin_id']);
            $stmt->execute();
            $r = $stmt->get_result();
            while ($row = $r->fetch_assoc()) $history[] = $row;
            $stmt->close();
            $history = array_reverse($history);
        } catch (Exception $e) {}
        
        // If there's history, include it in prompt for continuity
        if (!empty($history)) {
            $contextLines = "Recent conversation context:\n";
            foreach ($history as $h) {
                $role = $h['role'] === 'user' ? 'User' : 'Assistant';
                $msg = mb_substr($h['message'], 0, 300);
                $contextLines .= "- {$role}: {$msg}\n";
            }
            $fullPrompt = $contextLines . "\n---\n\n" . $fullPrompt;
        }
        
        // Call Gemini
        $result = aiGenerate($conn, $fullPrompt, getSystemPrompt($conn), 0.7);
        
        if (isset($result['error'])) {
            echo json_encode(['status' => 'error', 'message' => 'AI Error: ' . $result['error']]);
            exit;
        }
        
        // Save to chat history
        try {
            $stmt = $conn->prepare("INSERT INTO ai_chat_history (user_id, role, message, tokens_used) VALUES (?, 'user', ?, 0)");
            $stmt->bind_param("is", $_SESSION['admin_id'], $userMessage);
            $stmt->execute();
            $stmt->close();
            
            $stmt = $conn->prepare("INSERT INTO ai_chat_history (user_id, role, message, data_context, tokens_used) VALUES (?, 'assistant', ?, ?, ?)");
            $stmt->bind_param("issi", $_SESSION['admin_id'], $result['text'], $dataJson, $result['tokens']);
            $stmt->execute();
            $stmt->close();
        } catch (Exception $e) {}
        
        echo json_encode([
            'status' => 'success',
            'response' => $result['text'],
            'tokens' => $result['tokens'],
            'scope' => $scope
        ]);
        break;

    // ──────────────────────────────────────────────────────────
    // QUICK INSIGHTS — Pre-built analysis prompts
    // ──────────────────────────────────────────────────────────
    case 'quick_insight':
        if (function_exists('ai_active_config') && !ai_active_config($conn) && !getGeminiApiKey($conn)) {
            echo json_encode(['status' => 'error', 'message' => 'AI is not set up yet. A Super Admin can add a provider in AI Assistant → AI Setup.']);
            exit;
        }
        
        $insightType = $_POST['type'] ?? 'overview';
        $dataSnapshot = collectDataSnapshot($conn, $insightType);
        $dataJson = json_encode($dataSnapshot, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        
        $prompts = [
            'overview' => "Analyze this school's overall health. Give me: 1) Key statistics summary 2) Top 3 strengths 3) Top 3 concerns 4) Immediate action items. Be specific with numbers.",
            'attendance' => "Analyze attendance patterns. Show: 1) Overall attendance rate 2) Best/worst days 3) Trends over time 4) Students at risk of dropping out. Include percentages.",
            'members' => "Analyze member demographics and data quality. Show: 1) Gender/age distribution 2) Registration trends 3) Data completeness issues 4) Growth rate and projections.",
            'finance' => "Analyze the financial situation. Show: 1) Income vs expenses overview 2) Balance health 3) Pending amounts concern level 4) Budget recommendations in ETB.",
            'education' => "Analyze the education system. Show: 1) Class enrollment distribution 2) Teacher-to-student ratios 3) Under/over-enrolled classes 4) Improvement suggestions.",
        ];
        
        $prompt = ($prompts[$insightType] ?? $prompts['overview']) . "\n\nData:\n```json\n{$dataJson}\n```";
        
        $result = aiGenerate($conn, $prompt, getSystemPrompt($conn), 0.5);
        
        if (isset($result['error'])) {
            echo json_encode(['status' => 'error', 'message' => 'AI Error: ' . $result['error']]);
            exit;
        }
        
        echo json_encode([
            'status' => 'success',
            'response' => $result['text'],
            'type' => $insightType,
            'tokens' => $result['tokens']
        ]);
        break;

    // ──────────────────────────────────────────────────────────
    // GENERATE REPORT — Structured report for export
    // ──────────────────────────────────────────────────────────
    case 'generate_report':
        if (function_exists('ai_active_config') && !ai_active_config($conn) && !getGeminiApiKey($conn)) {
            echo json_encode(['status' => 'error', 'message' => 'AI is not set up yet. A Super Admin can add a provider in AI Assistant → AI Setup.']);
            exit;
        }
        
        $reportType = $_POST['report_type'] ?? 'monthly';
        $customPrompt = trim($_POST['custom_prompt'] ?? '');
        
        // Collect comprehensive data
        $dataSnapshot = collectDataSnapshot($conn, 'overview');
        $dataJson = json_encode($dataSnapshot, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        
        $today = date('F j, Y');
        
        $reportPrompts = [
            'monthly' => "Generate a Monthly Status Report for ' . SCHOOL_NAME_SHORT . ' ' . SCHOOL_TYPE . ', dated {$today}. Include: Executive Summary, Member Statistics (with gender/age breakdown), Attendance Analysis, Financial Summary, Class Enrollment Status, Data Quality Assessment, and Recommendations. Format as a professional report with clear headings.",
            'attendance' => "Generate a detailed Attendance Analysis Report dated {$today}. Include: Overall rates, patterns by day of week, trends over recent months, at-risk analysis, comparison across classes, and specific recommendations to improve attendance.",
            'financial' => "Generate a Financial Status Report dated {$today}. Include: Income/Expense summary, balance status, pending transactions, month-over-month trends, budget concerns, and financial recommendations. All amounts in ETB.",
            'demographic' => "Generate a Member Demographics Report dated {$today}. Include: Total population, gender distribution, age group breakdown, registration types, geographic distribution if available, growth trends, and membership health indicators.",
            'custom' => $customPrompt ?: "Generate a comprehensive school overview report.",
        ];
        
        $prompt = ($reportPrompts[$reportType] ?? $reportPrompts['monthly']) . "\n\nUse this actual data:\n```json\n{$dataJson}\n```\n\nIMPORTANT: Use actual numbers from the data. Structure with proper report headings. This will be exported as a document.";
        
        $result = aiGenerate($conn, $prompt, getSystemPrompt($conn), 0.4);
        
        if (isset($result['error'])) {
            echo json_encode(['status' => 'error', 'message' => 'AI Error: ' . $result['error']]);
            exit;
        }
        
        echo json_encode([
            'status' => 'success',
            'report' => $result['text'],
            'report_type' => $reportType,
            'generated_at' => $today,
            'tokens' => $result['tokens']
        ]);
        break;

    // ──────────────────────────────────────────────────────────
    // GET CHAT HISTORY
    // ──────────────────────────────────────────────────────────
    case 'get_history':
        $limit = min(50, max(1, (int)($_GET['limit'] ?? 30)));
        $messages = [];
        try {
            $stmt = $conn->prepare("SELECT id, role, message, tokens_used, created_at FROM ai_chat_history WHERE user_id = ? ORDER BY id DESC LIMIT ?");
            $stmt->bind_param("ii", $_SESSION['admin_id'], $limit);
            $stmt->execute();
            $r = $stmt->get_result();
            while ($row = $r->fetch_assoc()) $messages[] = $row;
            $stmt->close();
        } catch (Exception $e) {}
        
        echo json_encode(['status' => 'success', 'messages' => array_reverse($messages)]);
        break;

    // ──────────────────────────────────────────────────────────
    // CLEAR CHAT HISTORY
    // ──────────────────────────────────────────────────────────
    case 'clear_history':
        try {
            $stmt = $conn->prepare("DELETE FROM ai_chat_history WHERE user_id = ?");
            $stmt->bind_param("i", $_SESSION['admin_id']);
            $stmt->execute();
            $stmt->close();
            echo json_encode(['status' => 'success', 'message' => 'Chat history cleared']);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'Failed to clear history']);
        }
        break;

    default:
        echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
}

$conn->close();
