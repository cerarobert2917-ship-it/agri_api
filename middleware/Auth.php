<?php
class Auth
{
    public static function user(?array $roles = null): array
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if (!preg_match('/Bearer\s+(.+)/i', $header, $m)) respond(false, 'Please log in to continue.', null, 401);

        $payload = jwtDecode($m[1]);
        if (!$payload) respond(false, 'Your session has expired. Please log in again.', null, 401);

        $st = Database::conn()->prepare('SELECT id, full_name, role, status FROM users WHERE id = ?');
        $st->execute([$payload['sub']]);
        $u = $st->fetch();
        if (!$u) respond(false, 'Account not found.', null, 401);
        if ($u['status'] === 'suspended') respond(false, 'This account has been suspended.', null, 403);
        if ($roles && !in_array($u['role'], $roles, true)) respond(false, 'You do not have permission to do this.', null, 403);
        return $u;
    }
}
