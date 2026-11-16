<?php
class Auth
{
    public static function user(?array $roles = null): array
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if (!preg_match('/Bearer\s+(.+)/i', $header, $m)) respond(false, 'Kailangan mag-login.', null, 401);

        $payload = jwtDecode($m[1]);
        if (!$payload) respond(false, 'Expired na ang session. Mag-login ulit.', null, 401);

        $st = Database::conn()->prepare('SELECT id, full_name, role, status FROM users WHERE id = ?');
        $st->execute([$payload['sub']]);
        $u = $st->fetch();
        if (!$u) respond(false, 'Hindi mahanap ang account.', null, 401);
        if ($u['status'] === 'suspended') respond(false, 'Naka-suspend ang account na ito.', null, 403);
        if ($roles && !in_array($u['role'], $roles, true)) respond(false, 'Hindi ka pwede dito.', null, 403);
        return $u;
    }
}
