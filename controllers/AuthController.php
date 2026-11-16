<?php
class AuthController
{
    private PDO $db;
    public function __construct() { $this->db = Database::conn(); }

    private function storeStatus(int $userId): ?string
    {
        // galing sa pinakabagong claim ng owner
        $st = $this->db->prepare('SELECT status FROM store_claims WHERE owner_id = ? ORDER BY id DESC LIMIT 1');
        $st->execute([$userId]);
        if ($r = $st->fetch()) return $r['status'];
        // lumang data: may approved store na walang claim record
        $st = $this->db->prepare("SELECT status FROM stores WHERE owner_id = ? LIMIT 1");
        $st->execute([$userId]);
        $r = $st->fetch();
        return $r ? $r['status'] : null;
    }

    private function issueTokens(array $u): array
    {
        $access = jwtEncode(['sub' => (int) $u['id'], 'role' => $u['role'], 'exp' => time() + 30 * 60]);
        $refresh = bin2hex(random_bytes(32));
        $this->db->prepare('INSERT INTO refresh_tokens (user_id, token_hash, expires_at) VALUES (?,?,?)')
            ->execute([$u['id'], hash('sha256', $refresh), date('Y-m-d H:i:s', time() + 30 * 86400)]);
        return [
            'access_token' => $access,
            'refresh_token' => $refresh,
            'user' => [
                'id' => (int) $u['id'],
                'full_name' => $u['full_name'],
                'role' => $u['role'],
                'store_status' => $u['role'] === 'store_owner' ? $this->storeStatus($u['id']) : null,
            ],
        ];
    }

    public function register(): void
    {
        $d = body();
        $name = trim($d['full_name'] ?? '');
        $email = strtolower(trim($d['email'] ?? ''));
        $phone = trim($d['phone'] ?? '');
        $pass = $d['password'] ?? '';
        $role = $d['role'] ?? 'customer';

        if ($name === '') respond(false, 'Please enter your full name.', null, 422);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) respond(false, 'Please enter a valid email address.', null, 422);
        if (!validPassword($pass)) respond(false, 'Password must be at least 8 characters and include a letter and a number.', null, 422);
        if (!in_array($role, ['customer', 'store_owner'], true)) respond(false, 'Invalid role.', null, 422);
        // phone: kailangan lang ng store owner; optional sa viewer (customer)
        if ($role === 'store_owner' ? !validPhone($phone) : ($phone !== '' && !validPhone($phone))) {
            respond(false, 'Invalid phone number. Use the format 09XXXXXXXXX.', null, 422);
        }

        $st = $this->db->prepare('SELECT id FROM users WHERE email = ?');
        $st->execute([$email]);
        if ($st->fetch()) respond(false, 'An account with this email already exists.', null, 409);

        // Walang phone ang viewer (customer)
        if ($role === 'customer') $phone = '';
        $this->db->prepare('INSERT INTO users (full_name,email,password_hash,phone,role) VALUES (?,?,?,?,?)')
            ->execute([$name, $email, password_hash($pass, PASSWORD_DEFAULT), $phone === '' ? null : $phone, $role]);
        $id = (int) $this->db->lastInsertId();
        $st = $this->db->prepare('SELECT * FROM users WHERE id = ?');
        $st->execute([$id]);
        respond(true, 'Account created successfully.', $this->issueTokens($st->fetch()), 201);
    }

    public function login(): void
    {
        $d = body();
        $email = strtolower(trim($d['email'] ?? ''));
        $pass = $d['password'] ?? '';

        $st = $this->db->prepare('SELECT * FROM users WHERE email = ?');
        $st->execute([$email]);
        $u = $st->fetch();
        if (!$u) respond(false, 'Incorrect email or password.', null, 401);

        if ($u['locked_until'] && strtotime($u['locked_until']) > time()) {
            $mins = (int) ceil((strtotime($u['locked_until']) - time()) / 60);
            respond(false, "Your account is locked. Please try again in $mins minute(s).", ['locked' => true], 423);
        }
        if ($u['status'] === 'suspended') respond(false, 'This account has been suspended.', null, 403);

        if (!password_verify($pass, $u['password_hash'])) {
            $fails = $u['failed_logins'] + 1;
            if ($fails >= 5) {
                $this->db->prepare('UPDATE users SET failed_logins = 0, locked_until = ? WHERE id = ?')
                    ->execute([date('Y-m-d H:i:s', time() + 15 * 60), $u['id']]);
                respond(false, 'Too many failed attempts. Your account is locked for 15 minutes.', ['locked' => true], 423);
            }
            $this->db->prepare('UPDATE users SET failed_logins = ? WHERE id = ?')->execute([$fails, $u['id']]);
            respond(false, 'Incorrect email or password. Attempts remaining: ' . (5 - $fails), null, 401);
        }

        $this->db->prepare('UPDATE users SET failed_logins = 0, locked_until = NULL WHERE id = ?')->execute([$u['id']]);
        respond(true, 'Login successful.', $this->issueTokens($u));
    }

    /** GET request sa Google (para i-verify ang token). */
    private function googleGet(string $url, array $headers = []): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => $headers]);
        $out = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($out === false) error_log('Google verify curl error: ' . curl_error($ch));
        curl_close($ch);
        if ($out === false || $code !== 200) return null;
        $j = json_decode($out, true);
        return is_array($j) ? $j : null;
    }

    /** I-verify ang Google token. Ibabalik ang sub, email, name, email_verified. */
    private function verifyGoogle(?string $idToken, ?string $accessToken): array
    {
        $allowed = array_filter(array_map('trim', explode(',', env('GOOGLE_CLIENT_ID', ''))));
        if (!$allowed) respond(false, 'GOOGLE_CLIENT_ID is not set in .env.', null, 500);
        $bad = fn() => respond(false, 'Invalid Google sign-in.', null, 401);

        if ($idToken) {
            $i = $this->googleGet('https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($idToken));
            if (!$i || !in_array($i['aud'] ?? '', $allowed, true)) $bad();
            return [
                'sub' => $i['sub'],
                'email' => strtolower($i['email'] ?? ''),
                'name' => $i['name'] ?? '',
                'verified' => in_array($i['email_verified'] ?? false, [true, 'true'], true),
            ];
        }
        if ($accessToken) { // fallback: minsan walang idToken sa Flutter web
            $t = $this->googleGet('https://oauth2.googleapis.com/tokeninfo?access_token=' . urlencode($accessToken));
            if (!$t || !in_array($t['aud'] ?? ($t['azp'] ?? ''), $allowed, true)) $bad();
            $u = $this->googleGet('https://www.googleapis.com/oauth2/v3/userinfo', ['Authorization: Bearer ' . $accessToken]);
            if (!$u || empty($u['sub'])) $bad();
            return [
                'sub' => $u['sub'],
                'email' => strtolower($u['email'] ?? ''),
                'name' => $u['name'] ?? '',
                'verified' => !empty($u['email_verified']),
            ];
        }
        respond(false, 'Missing Google token.', null, 422);
    }

    /** POST /auth/google - Continue with Google (login o register). */
    public function google(): void
    {
        $d = body();
        $g = $this->verifyGoogle($d['id_token'] ?? null, $d['access_token'] ?? null);
        if ($g['email'] === '' || !$g['verified']) respond(false, 'Your Google email is not verified.', null, 403);

        // 1) kilala na ang google_id  2) may account na sa email (i-link)  3) bagong account
        $st = $this->db->prepare('SELECT * FROM users WHERE google_id = ?');
        $st->execute([$g['sub']]);
        $u = $st->fetch();

        if (!$u) {
            $st = $this->db->prepare('SELECT * FROM users WHERE email = ?');
            $st->execute([$g['email']]);
            $u = $st->fetch();
            if ($u) {
                $this->db->prepare('UPDATE users SET google_id = ? WHERE id = ?')->execute([$g['sub'], $u['id']]);
            }
        }

        if (!$u) {
            // Bagong account: kailangang piliin ng user ang role (hindi pwedeng admin)
            $role = $d['role'] ?? null;
            if (!in_array($role, ['customer', 'store_owner'], true)) {
                respond(true, 'Please choose a role.', ['needs_role' => true]);
            }
            $name = $g['name'] !== '' ? $g['name'] : explode('@', $g['email'])[0];
            $this->db->prepare('INSERT INTO users (full_name,email,password_hash,role,google_id) VALUES (?,?,?,?,?)')
                ->execute([$name, $g['email'], password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), $role, $g['sub']]);
            $st = $this->db->prepare('SELECT * FROM users WHERE id = ?');
            $st->execute([(int) $this->db->lastInsertId()]);
            $u = $st->fetch();
        }

        if ($u['status'] === 'suspended') respond(false, 'This account has been suspended.', null, 403);
        respond(true, 'Login successful.', $this->issueTokens($u));
    }

    public function refresh(): void
    {
        $token = body()['refresh_token'] ?? '';
        $st = $this->db->prepare('SELECT * FROM refresh_tokens WHERE token_hash = ? AND revoked = 0 AND expires_at > NOW()');
        $st->execute([hash('sha256', $token)]);
        $row = $st->fetch();
        if (!$row) respond(false, 'Your session has expired.', null, 401);

        $this->db->prepare('UPDATE refresh_tokens SET revoked = 1 WHERE id = ?')->execute([$row['id']]);
        $st = $this->db->prepare('SELECT * FROM users WHERE id = ? AND status = "active"');
        $st->execute([$row['user_id']]);
        $u = $st->fetch();
        if (!$u) respond(false, 'This account is no longer available.', null, 401);
        respond(true, 'Refreshed.', $this->issueTokens($u));
    }

    public function logout(): void
    {
        $token = body()['refresh_token'] ?? '';
        $this->db->prepare('UPDATE refresh_tokens SET revoked = 1 WHERE token_hash = ?')->execute([hash('sha256', $token)]);
        respond(true, 'Logged out.');
    }

    public function me(): void
    {
        $u = Auth::user();
        $u['store_status'] = $u['role'] === 'store_owner' ? $this->storeStatus($u['id']) : null;
        respond(true, 'OK', $u);
    }

    private function profileRow(int $id): array
    {
        $st = $this->db->prepare('SELECT id, full_name, email, phone, role, (google_id IS NOT NULL) AS google_linked, created_at FROM users WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch();
        $r['id'] = (int) $r['id'];
        if ($r['role'] === 'customer') $r['phone'] = null;
        $r['google_linked'] = (bool) $r['google_linked'];
        return $r;
    }

    /** GET /auth/profile - detalye para sa Profile settings screen. */
    public function profile(): void
    {
        $u = Auth::user();
        respond(true, 'OK', $this->profileRow((int) $u['id']));
    }

    /** POST /auth/profile - palitan ang full_name at/o phone. */
    public function updateProfile(): void
    {
        $u = Auth::user();
        $d = body();
        $sets = [];
        $vals = [];
        if (array_key_exists('full_name', $d)) {
            $n = trim((string) $d['full_name']);
            if (mb_strlen($n) < 2 || mb_strlen($n) > 100) respond(false, 'Name must be 2 to 100 characters.', null, 422);
            $sets[] = 'full_name = ?';
            $vals[] = $n;
        }
        // Hindi tinatanggap ang phone para sa viewer (customer)
        if (array_key_exists('phone', $d) && $u['role'] !== 'customer') {
            $p = trim((string) $d['phone']);
            if ($p !== '' && !validPhone($p)) respond(false, 'Use the format 09XXXXXXXXX.', null, 422);
            $sets[] = 'phone = ?';
            $vals[] = $p === '' ? null : $p;
        }
        if (!$sets) respond(false, 'Nothing to update.', null, 422);
        $vals[] = (int) $u['id'];
        $this->db->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
        respond(true, 'Profile updated.', $this->profileRow((int) $u['id']));
    }

    public function changePassword(): void
    {
        $u = Auth::user();
        $d = body();
        $st = $this->db->prepare('SELECT password_hash FROM users WHERE id = ?');
        $st->execute([$u['id']]);
        if (!password_verify($d['old_password'] ?? '', $st->fetchColumn())) respond(false, 'Your current password is incorrect.', null, 401);
        if (!validPassword($d['new_password'] ?? '')) respond(false, 'Password must be at least 8 characters and include a letter and a number.', null, 422);
        $this->db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($d['new_password'], PASSWORD_DEFAULT), $u['id']]);
        respond(true, 'Password changed successfully.');
    }
}
