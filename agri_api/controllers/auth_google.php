<?php
/**
 * POST /auth/google
 * Body: { "id_token": "...", "access_token": "...", "role": "customer|store_owner" }
 *
 * I-register ang route na ito sa router ng agri_api katabi ng /auth/login.
 * Ang 3 bahaging may "ADAPT" ay kailangang iayon sa existing code mo.
 */

const GOOGLE_WEB_CLIENT_ID = 'PASTE_WEB_CLIENT_ID.apps.googleusercontent.com';
// Mga client ID na tatanggapin (idagdag dito ang Android/iOS client ID kung kailangan)
const GOOGLE_ALLOWED_CLIENT_IDS = [GOOGLE_WEB_CLIENT_ID];

function google_http_get(string $url, array $headers = []): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $code !== 200) return null;
    $json = json_decode($body, true);
    return is_array($json) ? $json : null;
}

/** I-verify ang token sa Google. Ibabalik: sub, email, name, email_verified */
function verify_google_token(?string $idToken, ?string $accessToken): array {
    if ($idToken) {
        // Chine-check ng Google ang signature at expiry
        $info = google_http_get('https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($idToken));
        if (!$info || !in_array($info['aud'] ?? '', GOOGLE_ALLOWED_CLIENT_IDS, true)) {
            throw new RuntimeException('Invalid Google sign-in.');
        }
        return [
            'sub'            => $info['sub'],
            'email'          => strtolower($info['email'] ?? ''),
            'name'           => $info['name'] ?? '',
            'email_verified' => ($info['email_verified'] ?? '') === 'true' || ($info['email_verified'] ?? false) === true,
        ];
    }
    if ($accessToken) { // fallback (Flutter web minsan walang idToken)
        $ti = google_http_get('https://oauth2.googleapis.com/tokeninfo?access_token=' . urlencode($accessToken));
        $aud = $ti['aud'] ?? ($ti['azp'] ?? '');
        if (!$ti || !in_array($aud, GOOGLE_ALLOWED_CLIENT_IDS, true)) {
            throw new RuntimeException('Invalid Google sign-in.');
        }
        $ui = google_http_get('https://www.googleapis.com/oauth2/v3/userinfo', ['Authorization: Bearer ' . $accessToken]);
        if (!$ui || empty($ui['sub'])) throw new RuntimeException('Invalid Google sign-in.');
        return [
            'sub'            => $ui['sub'],
            'email'          => strtolower($ui['email'] ?? ''),
            'name'           => $ui['name'] ?? '',
            'email_verified' => !empty($ui['email_verified']),
        ];
    }
    throw new RuntimeException('Missing Google token.');
}

function handle_google_login(PDO $pdo, array $in): array {
    $g = verify_google_token($in['id_token'] ?? null, $in['access_token'] ?? null);
    if ($g['email'] === '' || !$g['email_verified']) {
        throw new RuntimeException('Your Google email is not verified.');
    }

    // 1) kilala na ang google_id  2) may account na sa email  3) bagong account
    $st = $pdo->prepare('SELECT * FROM users WHERE google_id = ? LIMIT 1');
    $st->execute([$g['sub']]);
    $user = $st->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        $st = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $st->execute([$g['email']]);
        $user = $st->fetch(PDO::FETCH_ASSOC);
        if ($user) { // i-link ang Google sa existing account (verified ang email)
            $pdo->prepare('UPDATE users SET google_id = ? WHERE id = ?')->execute([$g['sub'], $user['id']]);
        }
    }

    if (!$user) {
        // Pwede lang customer o store_owner; hindi kailanman admin
        $role = ($in['role'] ?? 'customer') === 'store_owner' ? 'store_owner' : 'customer';
        $name = $g['name'] !== '' ? $g['name'] : explode('@', $g['email'])[0];
        // ADAPT: pangalan ng columns ng users table mo
        $pdo->prepare('INSERT INTO users (full_name, email, google_id, password_hash, role) VALUES (?,?,?,?,?)')
            ->execute([$name, $g['email'], $g['sub'], password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), $role]);
        $id = (int)$pdo->lastInsertId();
        $st = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $st->execute([$id]);
        $user = $st->fetch(PDO::FETCH_ASSOC);
    }

    // ADAPT: gamitin ang parehong function na ginagamit ng /auth/login para gumawa ng
    // access_token at refresh_token (JWT / DB token). Dapat pareho ang hugis ng sagot.
    $tokens = issue_tokens($pdo, (int)$user['id']); // -> ['access_token'=>..., 'refresh_token'=>...]

    return [
        'access_token'  => $tokens['access_token'],
        'refresh_token' => $tokens['refresh_token'],
        'user' => [
            'id'           => (int)$user['id'],
            'full_name'    => $user['full_name'],
            'role'         => $user['role'],
            'store_status' => null, // ADAPT: kunin ang store status kung store_owner, tulad sa /auth/login
        ],
    ];
}

// Ang router mo ay dapat mag-respond ng: {"success": true, "data": handle_google_login($pdo, $input)}
// at {"success": false, "message": "..."} kapag may RuntimeException.
