<?php
/**
 * ════════════════════════════════════════════════════════════════
 * MULTI-PROVIDER AI LAYER — one interface, many providers
 * ════════════════════════════════════════════════════════════════
 * The rest of the system calls ONE function:
 *
 *     ai_chat($conn, $messages, $options)   // provider-agnostic
 *
 * $messages: [ ['role'=>'system|user|assistant','content'=>'...'], ... ]
 * Returns:   ['ok'=>true,'text'=>'...']  OR  ['ok'=>false,'error'=>'clean message','code'=>...]
 *
 * Providers plug in as thin adapters that normalise request/response:
 *   - openai adapter   → OpenAI, Groq, OpenRouter, and any OpenAI-compatible URL
 *   - gemini adapter   → Google Gemini
 *   - anthropic adapter→ Anthropic Claude
 * Swapping the active provider never changes calling code.
 *
 * SECURITY: API keys are ENCRYPTED AT REST (AES-256-CBC) in ai_provider_configs.
 * The encryption secret is derived from the .fkss_env.php secrets that live
 * ABOVE the web root (DB_PASS / JWT_SECRET), so a database dump alone cannot
 * reveal a key. Keys are never returned to the browser in full — only masked.
 * ════════════════════════════════════════════════════════════════
 */

if (!function_exists('ai_provider_registry')) {

/** Provider catalogue: label, adapter kind, default base URL + model, sign-up link. */
function ai_provider_registry() {
    return [
        'gemini' => [
            'label'  => 'Google Gemini (free tier)',
            'kind'   => 'gemini',
            'base'   => 'https://generativelanguage.googleapis.com/v1beta',
            'model'  => 'gemini-2.0-flash',
            'keyhint'=> 'AIza…',
            'free'   => true,
            'signup' => 'https://aistudio.google.com/apikey',
        ],
        'groq' => [
            'label'  => 'Groq (free, very fast)',
            'kind'   => 'openai',
            'base'   => 'https://api.groq.com/openai/v1',
            'model'  => 'llama-3.3-70b-versatile',
            'keyhint'=> 'gsk_…',
            'free'   => true,
            'signup' => 'https://console.groq.com/keys',
        ],
        'openrouter' => [
            'label'  => 'OpenRouter (free + paid models)',
            'kind'   => 'openai',
            'base'   => 'https://openrouter.ai/api/v1',
            'model'  => 'meta-llama/llama-3.3-70b-instruct:free',
            'keyhint'=> 'sk-or-…',
            'free'   => true,
            'signup' => 'https://openrouter.ai/keys',
        ],
        'openai' => [
            'label'  => 'OpenAI (paid)',
            'kind'   => 'openai',
            'base'   => 'https://api.openai.com/v1',
            'model'  => 'gpt-4o-mini',
            'keyhint'=> 'sk-…',
            'free'   => false,
            'signup' => 'https://platform.openai.com/api-keys',
        ],
        'anthropic' => [
            'label'  => 'Anthropic Claude (paid)',
            'kind'   => 'anthropic',
            'base'   => 'https://api.anthropic.com/v1',
            'model'  => 'claude-3-5-haiku-latest',
            'keyhint'=> 'sk-ant-…',
            'free'   => false,
            'signup' => 'https://console.anthropic.com/settings/keys',
        ],
        'compatible' => [
            'label'  => 'Custom (any OpenAI-compatible API)',
            'kind'   => 'openai',
            'base'   => '',   // the admin supplies the base URL
            'model'  => '',
            'keyhint'=> 'your provider key',
            'free'   => false,
            'signup' => '',
        ],
    ];
}

// ── Encryption (keys at rest) ──────────────────────────────────────────────
function ai_enc_secret() {
    $s = (defined('AI_ENC_KEY') && AI_ENC_KEY)
        ? AI_ENC_KEY
        : ((defined('DB_PASS') ? DB_PASS : '') . '|' . (defined('JWT_SECRET') ? JWT_SECRET : '') . '|' . (defined('DB_NAME') ? DB_NAME : '') . '|fkss-ai-v1');
    return hash('sha256', $s, true); // 32 raw bytes for AES-256
}
function ai_encrypt($plain) {
    if ($plain === '' || $plain === null) return '';
    if (!function_exists('openssl_encrypt')) return 'plain:' . $plain; // extreme fallback
    $iv = random_bytes(16);
    $ct = openssl_encrypt($plain, 'aes-256-cbc', ai_enc_secret(), OPENSSL_RAW_DATA, $iv);
    if ($ct === false) return '';
    return 'v1:' . base64_encode($iv . $ct);
}
function ai_decrypt($cipher) {
    if (!$cipher) return '';
    if (strpos($cipher, 'v1:') === 0) {
        $raw = base64_decode(substr($cipher, 3), true);
        if ($raw === false || strlen($raw) < 17) return '';
        $iv = substr($raw, 0, 16);
        $ct = substr($raw, 16);
        $pt = openssl_decrypt($ct, 'aes-256-cbc', ai_enc_secret(), OPENSSL_RAW_DATA, $iv);
        return $pt === false ? '' : $pt;
    }
    if (strpos($cipher, 'plain:') === 0) return substr($cipher, 6);
    // Legacy value stored before encryption existed — return as-is.
    return $cipher;
}
/** Mask a key for display: keep only the last 4 chars. */
function ai_mask_key($key) {
    $key = (string)$key;
    $n = strlen($key);
    if ($n === 0) return '';
    if ($n <= 4) return str_repeat('•', $n);
    return str_repeat('•', 8) . substr($key, -4);
}

// ── Storage ────────────────────────────────────────────────────────────────
function ai_ensure_schema($conn) {
    static $done = false;
    if ($done) return;
    $done = true;
    if (!$conn || (isset($conn->connect_error) && $conn->connect_error)) return;
    try {
        $conn->query("CREATE TABLE IF NOT EXISTS `ai_provider_configs` (
            `provider` VARCHAR(40) NOT NULL,
            `api_key_enc` TEXT DEFAULT NULL,
            `model` VARCHAR(160) DEFAULT NULL,
            `base_url` VARCHAR(255) DEFAULT NULL,
            `is_active` TINYINT(1) NOT NULL DEFAULT 0,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`provider`),
            KEY `is_active` (`is_active`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {}

    // One-time migration: import a legacy plaintext Gemini key (system_settings)
    // into the new encrypted store, and make it active if nothing else is.
    try {
        $r = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key='gemini_api_key' LIMIT 1");
        if ($r && ($row = $r->fetch_assoc()) && trim((string)$row['setting_value']) !== '') {
            $c = $conn->query("SELECT provider FROM ai_provider_configs WHERE provider='gemini' LIMIT 1");
            if ($c && $c->num_rows === 0) {
                $reg = ai_provider_registry()['gemini'];
                $enc = ai_encrypt(trim($row['setting_value']));
                $hasActive = false;
                $ac = $conn->query("SELECT 1 FROM ai_provider_configs WHERE is_active=1 LIMIT 1");
                if ($ac && $ac->num_rows > 0) $hasActive = true;
                $active = $hasActive ? 0 : 1;
                $stmt = $conn->prepare("INSERT INTO ai_provider_configs (provider,api_key_enc,model,base_url,is_active) VALUES ('gemini',?,?,?,?)");
                if ($stmt) { $stmt->bind_param('sssi', $enc, $reg['model'], $reg['base'], $active); $stmt->execute(); $stmt->close(); }
            }
        }
    } catch (Throwable $e) {}
}

function ai_all_configs($conn) {
    ai_ensure_schema($conn);
    $out = [];
    try {
        $r = $conn->query("SELECT provider,api_key_enc,model,base_url,is_active FROM ai_provider_configs");
        if ($r) while ($row = $r->fetch_assoc()) $out[$row['provider']] = $row;
    } catch (Throwable $e) {}
    return $out;
}
function ai_get_config($conn, $provider) {
    $all = ai_all_configs($conn);
    return $all[$provider] ?? null;
}
function ai_active_config($conn) {
    foreach (ai_all_configs($conn) as $p => $c) {
        if ((int)$c['is_active'] === 1) { $c['provider'] = $p; return $c; }
    }
    return null;
}
function ai_save_config($conn, $provider, $key, $model, $baseUrl, $makeActive) {
    ai_ensure_schema($conn);
    $reg = ai_provider_registry();
    if (!isset($reg[$provider])) return ['ok' => false, 'error' => 'Unknown provider.'];

    $existing = ai_get_config($conn, $provider);
    // Blank key on an existing provider = keep the stored key (model-only edit).
    $enc     = ($key !== '') ? ai_encrypt($key) : ($existing['api_key_enc'] ?? '');
    $model   = $model   !== '' ? $model            : ((($existing['model'] ?? '') !== '') ? $existing['model'] : $reg[$provider]['model']);
    $baseUrl = $baseUrl !== '' ? rtrim($baseUrl, '/') : (($existing['base_url'] ?? '') ?: $reg[$provider]['base']);

    if ($provider === 'compatible' && $baseUrl === '') return ['ok' => false, 'error' => 'A base URL is required for a custom OpenAI-compatible provider.'];

    try {
        $stmt = $conn->prepare("INSERT INTO ai_provider_configs (provider,api_key_enc,model,base_url,is_active) VALUES (?,?,?,?,0)
            ON DUPLICATE KEY UPDATE api_key_enc=VALUES(api_key_enc), model=VALUES(model), base_url=VALUES(base_url)");
        if (!$stmt) return ['ok' => false, 'error' => 'Database error.'];
        $stmt->bind_param('ssss', $provider, $enc, $model, $baseUrl);
        $stmt->execute();
        $stmt->close();
        if ($makeActive) ai_set_active($conn, $provider);
        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not save the configuration.'];
    }
}
function ai_set_active($conn, $provider) {
    ai_ensure_schema($conn);
    if (!ai_get_config($conn, $provider)) return ['ok' => false, 'error' => 'Configure that provider first.'];
    try {
        $conn->query("UPDATE ai_provider_configs SET is_active=0");
        $stmt = $conn->prepare("UPDATE ai_provider_configs SET is_active=1 WHERE provider=?");
        if ($stmt) { $stmt->bind_param('s', $provider); $stmt->execute(); $stmt->close(); }
        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not switch provider.'];
    }
}

// ── HTTP + error helpers ────────────────────────────────────────────────────
function ai_http($url, $headers, $body, $timeout = 30) {
    if (!function_exists('curl_init')) return ['resp' => '', 'code' => 0, 'err' => 'cURL is not available on this server'];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return ['resp' => $resp, 'code' => $code, 'err' => $err];
}
function ai_net_msg($err) {
    return 'Could not reach the AI provider (network blocked or timed out). ' .
           'On shared hosting, make sure outbound HTTPS is allowed.';
}
function ai_provider_err($code, $msg) {
    $msg = $msg ? ': ' . substr(trim($msg), 0, 160) : '.';
    if ($code === 401 || $code === 403) return 'The API key was rejected (invalid or not authorised).';
    if ($code === 429) return 'Rate limit reached for this key. Wait a moment, or switch to another provider.';
    if ($code === 404) return 'Model or endpoint not found — check the model name / base URL' . $msg;
    if ($code === 400) return 'The provider rejected the request' . $msg;
    if ($code >= 500) return 'The AI provider had a server error. Please try again shortly.';
    if ($code === 0)  return 'Could not connect to the AI provider (blocked or timed out).';
    return 'AI request failed (HTTP ' . $code . ')' . $msg;
}

// ── Adapters (return normalised ['ok','text'] | ['ok'=>false,'error','code']) ──
function ai_adapter_openai($cfg, $messages, $opts) {
    $base = $cfg['base_url'] !== '' ? $cfg['base_url'] : 'https://api.openai.com/v1';
    $url  = rtrim($base, '/') . '/chat/completions';
    $payload = ['model' => $cfg['model'], 'messages' => $messages, 'temperature' => $opts['temperature'] ?? 0.7];
    if (!empty($opts['max_tokens'])) $payload['max_tokens'] = (int)$opts['max_tokens'];
    $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $cfg['key']];
    if (strpos($base, 'openrouter.ai') !== false) {
        $headers[] = 'HTTP-Referer: ' . (defined('SITE_URL') ? SITE_URL : 'https://fkss.local');
        $headers[] = 'X-Title: FKSS Admin';
    }
    $r = ai_http($url, $headers, json_encode($payload), $opts['timeout'] ?? 30);
    if ($r['err']) return ['ok' => false, 'error' => ai_net_msg($r['err']), 'code' => 'network'];
    $d = json_decode($r['resp'], true);
    if ($r['code'] >= 200 && $r['code'] < 300 && isset($d['choices'][0]['message']['content'])) {
        return ['ok' => true, 'text' => $d['choices'][0]['message']['content']];
    }
    return ['ok' => false, 'error' => ai_provider_err($r['code'], $d['error']['message'] ?? null), 'code' => $r['code']];
}
function ai_adapter_gemini($cfg, $messages, $opts) {
    $base  = $cfg['base_url'] !== '' ? $cfg['base_url'] : 'https://generativelanguage.googleapis.com/v1beta';
    $model = $cfg['model'] !== '' ? $cfg['model'] : 'gemini-2.0-flash';
    $url   = rtrim($base, '/') . "/models/{$model}:generateContent?key=" . urlencode($cfg['key']);
    $contents = []; $sys = '';
    foreach ($messages as $m) {
        if (($m['role'] ?? '') === 'system') { $sys .= ($sys ? "\n" : '') . $m['content']; continue; }
        $contents[] = ['role' => (($m['role'] ?? '') === 'assistant' ? 'model' : 'user'), 'parts' => [['text' => $m['content']]]];
    }
    $payload = ['contents' => $contents, 'generationConfig' => ['temperature' => $opts['temperature'] ?? 0.7]];
    if (!empty($opts['max_tokens'])) $payload['generationConfig']['maxOutputTokens'] = (int)$opts['max_tokens'];
    if ($sys !== '') $payload['systemInstruction'] = ['parts' => [['text' => $sys]]];
    $r = ai_http($url, ['Content-Type: application/json'], json_encode($payload), $opts['timeout'] ?? 30);
    if ($r['err']) return ['ok' => false, 'error' => ai_net_msg($r['err']), 'code' => 'network'];
    $d = json_decode($r['resp'], true);
    if ($r['code'] >= 200 && $r['code'] < 300 && isset($d['candidates'][0]['content']['parts'][0]['text'])) {
        return ['ok' => true, 'text' => $d['candidates'][0]['content']['parts'][0]['text']];
    }
    // Gemini sometimes 200s with a blocked/empty candidate.
    if ($r['code'] >= 200 && $r['code'] < 300) {
        return ['ok' => false, 'error' => 'The AI returned no answer (the content may have been filtered). Try rephrasing.', 'code' => 'empty'];
    }
    return ['ok' => false, 'error' => ai_provider_err($r['code'], $d['error']['message'] ?? null), 'code' => $r['code']];
}
function ai_adapter_anthropic($cfg, $messages, $opts) {
    $base = $cfg['base_url'] !== '' ? $cfg['base_url'] : 'https://api.anthropic.com/v1';
    $url  = rtrim($base, '/') . '/messages';
    $sys = ''; $msgs = [];
    foreach ($messages as $m) {
        if (($m['role'] ?? '') === 'system') { $sys .= ($sys ? "\n" : '') . $m['content']; continue; }
        $msgs[] = ['role' => (($m['role'] ?? '') === 'assistant' ? 'assistant' : 'user'), 'content' => $m['content']];
    }
    $payload = ['model' => $cfg['model'], 'max_tokens' => (int)($opts['max_tokens'] ?? 1024), 'messages' => $msgs, 'temperature' => $opts['temperature'] ?? 0.7];
    if ($sys !== '') $payload['system'] = $sys;
    $headers = ['Content-Type: application/json', 'x-api-key: ' . $cfg['key'], 'anthropic-version: 2023-06-01'];
    $r = ai_http($url, $headers, json_encode($payload), $opts['timeout'] ?? 30);
    if ($r['err']) return ['ok' => false, 'error' => ai_net_msg($r['err']), 'code' => 'network'];
    $d = json_decode($r['resp'], true);
    if ($r['code'] >= 200 && $r['code'] < 300 && isset($d['content'][0]['text'])) {
        return ['ok' => true, 'text' => $d['content'][0]['text']];
    }
    return ['ok' => false, 'error' => ai_provider_err($r['code'], $d['error']['message'] ?? null), 'code' => $r['code']];
}

/** Route a fully-resolved config (with plaintext key) to the right adapter. */
function ai_dispatch($cfg, $messages, $opts) {
    $reg  = ai_provider_registry();
    $kind = $reg[$cfg['provider']]['kind'] ?? 'openai';
    if ($kind === 'gemini')    return ai_adapter_gemini($cfg, $messages, $opts);
    if ($kind === 'anthropic') return ai_adapter_anthropic($cfg, $messages, $opts);
    return ai_adapter_openai($cfg, $messages, $opts);
}

// ── THE public interface everything else calls ──────────────────────────────
function ai_chat($conn, $messages, $opts = []) {
    $active = ai_active_config($conn);
    if (!$active) return ['ok' => false, 'error' => 'No AI provider is set up yet. A Super Admin can add one in AI Assistant → AI Setup.', 'code' => 'no_provider'];
    $key = ai_decrypt($active['api_key_enc'] ?? '');
    if ($key === '') return ['ok' => false, 'error' => 'The active AI provider has no API key. Add one in AI Assistant → AI Setup.', 'code' => 'no_key'];
    $cfg = ['provider' => $active['provider'], 'key' => $key, 'model' => (string)($active['model'] ?? ''), 'base_url' => (string)($active['base_url'] ?? '')];
    return ai_dispatch($cfg, $messages, $opts);
}

/** Send a tiny request to verify a key/model works. $key may be '' to use the stored key. */
function ai_test_connection($conn, $provider, $key, $model, $baseUrl) {
    $reg = ai_provider_registry();
    if (!isset($reg[$provider])) return ['ok' => false, 'error' => 'Unknown provider.'];
    if ($key === '') {
        $c = ai_get_config($conn, $provider);
        $key = ai_decrypt($c['api_key_enc'] ?? '');
        if ($key === '') return ['ok' => false, 'error' => 'Enter an API key first, then test.'];
    }
    $cfg = [
        'provider' => $provider,
        'key'      => $key,
        'model'    => $model !== '' ? $model : $reg[$provider]['model'],
        'base_url' => $baseUrl !== '' ? rtrim($baseUrl, '/') : $reg[$provider]['base'],
    ];
    if ($reg[$provider]['kind'] === 'openai' && $cfg['base_url'] === '') return ['ok' => false, 'error' => 'A base URL is required for a custom OpenAI-compatible provider.'];
    if ($cfg['model'] === '') return ['ok' => false, 'error' => 'Enter a model name to test.'];
    $res = ai_dispatch($cfg, [['role' => 'user', 'content' => 'Reply with just the word: OK']], ['temperature' => 0, 'max_tokens' => 8, 'timeout' => 20]);
    if ($res['ok']) return ['ok' => true, 'text' => trim(mb_substr($res['text'], 0, 40))];
    return ['ok' => false, 'error' => $res['error']];
}

} // end function guard
