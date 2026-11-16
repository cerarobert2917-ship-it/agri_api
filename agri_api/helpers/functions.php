<?php
function loadEnv(string $path): void
{
    if (!is_file($path)) return;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        [$k, $v] = explode('=', $line, 2);
        $_ENV[trim($k)] = trim($v);
    }
}

function env(string $key, $default = null)
{
    return $_ENV[$key] ?? $default;
}

function respond(bool $success, string $message, $data = null, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => $success, 'message' => $message, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

function body(): array
{
    $json = json_decode(file_get_contents('php://input') ?: '', true);
    return is_array($json) ? $json : [];
}

function b64url(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

function jwtEncode(array $payload): string
{
    $head = b64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $pl = b64url(json_encode($payload));
    $sig = b64url(hash_hmac('sha256', "$head.$pl", env('JWT_SECRET'), true));
    return "$head.$pl.$sig";
}

function jwtDecode(string $token): ?array
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    $check = b64url(hash_hmac('sha256', "$parts[0].$parts[1]", env('JWT_SECRET'), true));
    if (!hash_equals($check, $parts[2])) return null;
    $data = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
    if (!$data || ($data['exp'] ?? 0) < time()) return null;
    return $data;
}

function validPhone(string $p): bool
{
    return (bool) preg_match('/^(09\d{9}|\+639\d{9})$/', $p);
}

function validPassword(string $p): bool
{
    return strlen($p) >= 8 && preg_match('/[A-Za-z]/', $p) && preg_match('/\d/', $p);
}
