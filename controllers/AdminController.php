<?php
/** Admin only: dashboard, store verification, store/user/product management, reports, logs. */
class AdminController
{
    private PDO $db;
    public function __construct() { $this->db = Database::conn(); }

    // ------------------------------------------------------------ helpers
    private function imgUrl(?string $f): ?string
    {
        return $f ? 'uploads/claims/' . $f : null;
    }

    private function log(int $adminId, string $action, ?string $type = null, ?int $id = null, ?string $details = null): void
    {
        $this->db->prepare('INSERT INTO admin_logs (admin_id, action, target_type, target_id, details) VALUES (?,?,?,?,?)')
            ->execute([$adminId, $action, $type, $id, $details === null ? null : mb_substr($details, 0, 255)]);
    }

    private function notify(int $userId, string $title, string $body, int $ref, string $type = 'claim'): void
    {
        $this->db->prepare('INSERT INTO notifications (user_id, title, body, type, reference_id) VALUES (?,?,?,?,?)')
            ->execute([$userId, $title, mb_substr($body, 0, 255), $type, $ref]);
    }

    private function saveStoreImage(string $b64): string
    {
        $b64 = preg_replace('#^data:image/\w+;base64,#', '', $b64);
        $bin = base64_decode($b64, true);
        if ($bin === false || $bin === '') respond(false, 'The store image is corrupted.', null, 422);
        if (strlen($bin) > 3 * 1024 * 1024) respond(false, 'The store image is too large (3 MB max).', null, 422);
        $info = @getimagesizefromstring($bin);
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png'][$info['mime'] ?? ''] ?? null;
        if (!$ext) respond(false, 'Only JPEG or PNG images are accepted.', null, 422);
        $dir = dirname(__DIR__) . '/uploads/stores';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) respond(false, 'Could not save the image.', null, 500);
        $name = bin2hex(random_bytes(16)) . '.' . $ext;
        if (file_put_contents("$dir/$name", $bin) === false) respond(false, 'Could not save the image.', null, 500);
        return 'uploads/stores/' . $name;
    }

    // ------------------------------------------------------------ dashboard
    /** GET /admin/dashboard */
    public function dashboard(): void
    {
        Auth::user(['admin']);
        $one = function (string $sql) { return (int) $this->db->query($sql)->fetchColumn(); };

        $data = [
            'stores_total'     => $one('SELECT COUNT(*) FROM stores'),
            'stores_approved'  => $one('SELECT COUNT(*) FROM stores WHERE owner_id IS NOT NULL'),
            'stores_pending'   => $one("SELECT COUNT(DISTINCT store_id) FROM store_claims WHERE status = 'pending'"),
            'stores_unclaimed' => $one("SELECT COUNT(*) FROM stores s WHERE s.owner_id IS NULL
                                        AND NOT EXISTS (SELECT 1 FROM store_claims c WHERE c.store_id = s.id AND c.status = 'pending')"),
            'claims_rejected'  => $one("SELECT COUNT(*) FROM store_claims WHERE status = 'rejected'"),
            'products_total'   => $one('SELECT COUNT(*) FROM products'),
            'products_hidden'  => $one('SELECT COUNT(*) FROM products WHERE is_hidden = 1'),
            'products_low'     => $one('SELECT COUNT(*) FROM products WHERE stock_quantity > 0 AND stock_quantity <= low_stock_threshold'),
            'products_out'     => $one('SELECT COUNT(*) FROM products WHERE stock_quantity <= 0'),
            'owners_total'     => $one("SELECT COUNT(*) FROM users WHERE role = 'store_owner'"),
            'viewers_total'    => $one("SELECT COUNT(*) FROM users WHERE role = 'customer'"),
            'users_suspended'  => $one("SELECT COUNT(*) FROM users WHERE status = 'suspended' AND role <> 'admin'"),
        ];
        $data['recent_activity'] = $this->db->query(
            'SELECT l.id, l.action, l.target_type, l.target_id, l.details, l.created_at, u.full_name AS admin_name
             FROM admin_logs l JOIN users u ON u.id = l.admin_id ORDER BY l.id DESC LIMIT 8')->fetchAll();
        respond(true, 'OK', $data);
    }

    // ------------------------------------------------------------ claims
    /** GET /admin/claims?status=pending|approved|rejected|all */
    public function claims(): void
    {
        Auth::user(['admin']);
        $status = $_GET['status'] ?? 'pending';
        if (!in_array($status, ['pending', 'approved', 'rejected', 'all'], true)) respond(false, 'Invalid status.', null, 422);

        $sql = 'SELECT c.id, c.store_id, c.status, c.rejection_reason, c.created_at, c.reviewed_at,
                       c.permit_image, c.storefront_image, c.permit_status, c.storefront_status,
                       s.name AS store_name, s.address, s.barangay, s.latitude, s.longitude,
                       u.full_name AS owner_name, u.email AS owner_email, u.phone AS owner_phone,
                       r.full_name AS reviewed_by_name
                FROM store_claims c
                JOIN stores s ON s.id = c.store_id
                JOIN users u ON u.id = c.owner_id
                LEFT JOIN users r ON r.id = c.reviewed_by';
        $args = [];
        if ($status !== 'all') { $sql .= ' WHERE c.status = ?'; $args[] = $status; }
        $sql .= $status === 'pending' ? ' ORDER BY c.created_at ASC' : ' ORDER BY c.created_at DESC';
        $st = $this->db->prepare($sql);
        $st->execute($args);
        $rows = $st->fetchAll();
        foreach ($rows as &$r) {
            $r['permit_url'] = $this->imgUrl($r['permit_image']);
            $r['storefront_url'] = $this->imgUrl($r['storefront_image']);
            unset($r['permit_image'], $r['storefront_image']);
        }
        respond(true, count($rows) . ' claims', $rows);
    }

    /** POST /admin/claims/verify  { claim_id, part: permit|storefront, status: pending|verified|rejected } */
    public function verifyClaim(): void
    {
        $admin = Auth::user(['admin']);
        $d = body();
        $claimId = (int) ($d['claim_id'] ?? 0);
        $part = $d['part'] ?? '';
        $status = $d['status'] ?? '';
        if ($claimId <= 0 || !in_array($part, ['permit', 'storefront'], true) || !in_array($status, ['pending', 'verified', 'rejected'], true)) {
            respond(false, 'Invalid request.', null, 422);
        }
        $col = $part === 'permit' ? 'permit_status' : 'storefront_status';
        $st = $this->db->prepare('SELECT status FROM store_claims WHERE id = ?');
        $st->execute([$claimId]);
        $cur = $st->fetchColumn();
        if ($cur === false) respond(false, 'Claim not found.', null, 404);
        if ($cur !== 'pending') respond(false, 'This claim was already reviewed.', null, 409);
        $this->db->prepare("UPDATE store_claims SET $col = ? WHERE id = ?")->execute([$status, $claimId]);
        $this->log((int) $admin['id'], 'claim_' . $part . '_' . $status, 'claim', $claimId, "Marked $part as $status");
        respond(true, ucfirst($part) . ' marked as ' . $status . '.');
    }

    /** POST /admin/claims/review  { claim_id, action: approve|reject, reason }  (never automatic) */
    public function review(): void
    {
        $admin = Auth::user(['admin']);
        $d = body();
        $claimId = (int) ($d['claim_id'] ?? 0);
        $action = $d['action'] ?? '';
        $reason = trim((string) ($d['reason'] ?? ''));
        if ($claimId <= 0 || !in_array($action, ['approve', 'reject'], true)) respond(false, 'Invalid request.', null, 422);
        if ($action === 'reject' && ($reason === '' || mb_strlen($reason) > 255)) {
            respond(false, 'A rejection reason is required (up to 255 characters).', null, 422);
        }

        $this->db->beginTransaction();
        try {
            $st = $this->db->prepare('SELECT * FROM store_claims WHERE id = ? FOR UPDATE');
            $st->execute([$claimId]);
            $claim = $st->fetch();
            if (!$claim) { $this->db->rollBack(); respond(false, 'Claim not found.', null, 404); }
            if ($claim['status'] !== 'pending') { $this->db->rollBack(); respond(false, 'This claim was already reviewed.', null, 409); }

            $sr = $this->db->prepare('SELECT id, name, owner_id FROM stores WHERE id = ? FOR UPDATE');
            $sr->execute([$claim['store_id']]);
            $store = $sr->fetch();
            if (!$store) { $this->db->rollBack(); respond(false, 'The store no longer exists.', null, 404); }

            if ($action === 'approve') {
                if ($store['owner_id'] !== null) { $this->db->rollBack(); respond(false, 'This store already has an owner.', null, 409); }
                if ($claim['permit_status'] !== 'verified' || $claim['storefront_status'] !== 'verified') {
                    $this->db->rollBack();
                    respond(false, 'Verify both the permit and the storefront photo before approving.', null, 409);
                }

                $this->db->prepare("UPDATE store_claims SET status='approved', reviewed_at=NOW(), reviewed_by=? WHERE id=?")
                    ->execute([$admin['id'], $claimId]);
                $this->db->prepare("UPDATE stores SET owner_id=?, status='approved', rejection_reason=NULL WHERE id=?")
                    ->execute([$claim['owner_id'], $store['id']]);

                // reject other pending claims on this store
                $others = $this->db->prepare("SELECT id, owner_id FROM store_claims WHERE store_id=? AND status='pending' AND id<>?");
                $others->execute([$store['id'], $claimId]);
                foreach ($others->fetchAll() as $o) {
                    $this->db->prepare("UPDATE store_claims SET status='rejected', rejection_reason=?, reviewed_at=NOW(), reviewed_by=? WHERE id=?")
                        ->execute(['Another claim for this store was approved.', $admin['id'], $o['id']]);
                    $this->notify((int) $o['owner_id'], 'Claim rejected', 'Another claim for "' . $store['name'] . '" was approved.', (int) $o['id']);
                }
                $this->notify((int) $claim['owner_id'], 'Claim approved!', 'You can now manage "' . $store['name'] . '".', $claimId);
                $this->log((int) $admin['id'], 'claim_approved', 'claim', $claimId, 'Approved "' . $store['name'] . '"');
            } else {
                $this->db->prepare("UPDATE store_claims SET status='rejected', rejection_reason=?, reviewed_at=NOW(), reviewed_by=? WHERE id=?")
                    ->execute([$reason, $admin['id'], $claimId]);
                $this->notify((int) $claim['owner_id'], 'Claim rejected', 'Reason: ' . $reason, $claimId);
                $this->log((int) $admin['id'], 'claim_rejected', 'claim', $claimId, 'Rejected "' . $store['name'] . '": ' . $reason);
            }
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
        respond(true, $action === 'approve' ? 'Claim approved.' : 'Claim rejected.');
    }

    // ------------------------------------------------------------ stores
    /** GET /admin/stores */
    public function stores(): void
    {
        Auth::user(['admin']);
        $rows = $this->db->query(
            "SELECT s.id, s.name, s.description, s.address, s.barangay, s.latitude, s.longitude, s.photo_url,
                    s.is_active, s.created_at, u.full_name AS owner_name, u.email AS owner_email,
                    (SELECT COUNT(*) FROM products p WHERE p.store_id = s.id) AS product_count,
                    CASE WHEN s.owner_id IS NOT NULL THEN 'approved'
                         WHEN EXISTS (SELECT 1 FROM store_claims c WHERE c.store_id = s.id AND c.status='pending') THEN 'pending'
                         WHEN (SELECT c2.status FROM store_claims c2 WHERE c2.store_id = s.id ORDER BY c2.id DESC LIMIT 1) = 'rejected' THEN 'rejected'
                         ELSE 'unclaimed' END AS status
             FROM stores s LEFT JOIN users u ON u.id = s.owner_id
             ORDER BY s.created_at DESC, s.id DESC")->fetchAll();
        foreach ($rows as &$r) $r['is_active'] = (int) $r['is_active'];
        respond(true, 'OK', $rows);
    }

    /** GET /admin/stores/detail?id= */
    public function storeDetail(): void
    {
        Auth::user(['admin']);
        $id = (int) ($_GET['id'] ?? 0);
        $st = $this->db->prepare(
            "SELECT s.*, u.full_name AS owner_name, u.email AS owner_email, u.phone AS owner_phone, u.status AS owner_status
             FROM stores s LEFT JOIN users u ON u.id = s.owner_id WHERE s.id = ?");
        $st->execute([$id]);
        $store = $st->fetch();
        if (!$store) respond(false, 'Store not found.', null, 404);
        $store['is_active'] = (int) $store['is_active'];

        $p = $this->db->prepare(
            'SELECT p.id, p.name, p.brand, p.unit, p.price, p.stock_quantity, p.low_stock_threshold, p.image_url,
                    p.is_available, p.is_hidden, p.moderation_reason, c.name AS category_name
             FROM products p JOIN categories c ON c.id = p.category_id WHERE p.store_id = ? ORDER BY p.name');
        $p->execute([$id]);
        $store['products'] = $p->fetchAll();

        $c = $this->db->prepare(
            'SELECT c.id, c.status, c.created_at, c.reviewed_at, c.rejection_reason, c.permit_status, c.storefront_status, r.full_name AS reviewed_by_name
             FROM store_claims c LEFT JOIN users r ON r.id = c.reviewed_by WHERE c.store_id = ? ORDER BY c.id DESC LIMIT 1');
        $c->execute([$id]);
        $store['last_claim'] = $c->fetch() ?: null;
        respond(true, 'OK', $store);
    }

    /** POST /admin/stores/active  { store_id, active } */
    public function setStoreActive(): void
    {
        $admin = Auth::user(['admin']);
        $d = body();
        $id = (int) ($d['store_id'] ?? 0);
        $active = !empty($d['active']) ? 1 : 0;
        $st = $this->db->prepare('SELECT name FROM stores WHERE id = ?');
        $st->execute([$id]);
        $name = $st->fetchColumn();
        if ($name === false) respond(false, 'Store not found.', null, 404);
        $this->db->prepare('UPDATE stores SET is_active = ? WHERE id = ?')->execute([$active, $id]);
        $this->log((int) $admin['id'], $active ? 'store_activated' : 'store_deactivated', 'store', $id, ($active ? 'Activated "' : 'Deactivated "') . $name . '"');
        respond(true, $active ? 'Store activated.' : 'Store deactivated. It is now hidden from viewers.');
    }

    /** POST /admin/stores  { name, address, barangay, description, latitude, longitude, image(base64, optional) } */
    public function createStore(): void
    {
        $admin = Auth::user(['admin']);
        $d = body();
        $name = trim((string) ($d['name'] ?? ''));
        $address = trim((string) ($d['address'] ?? ''));
        $barangay = trim((string) ($d['barangay'] ?? ''));
        if ($name === '' || $address === '' || $barangay === '') respond(false, 'Store name, address and barangay are required.', null, 422);
        if (!is_numeric($d['latitude'] ?? null) || !is_numeric($d['longitude'] ?? null)) respond(false, 'Invalid coordinates.', null, 422);
        $lat = (float) $d['latitude'];
        $lng = (float) $d['longitude'];
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) respond(false, 'Coordinates are out of range.', null, 422);
        $photo = !empty($d['image']) ? $this->saveStoreImage((string) $d['image']) : null;

        $this->db->prepare("INSERT INTO stores (owner_id, name, description, address, barangay, latitude, longitude, photo_url, status)
                            VALUES (NULL,?,?,?,?,?,?,?, 'pending')")
            ->execute([mb_substr($name, 0, 120), trim((string) ($d['description'] ?? '')), mb_substr($address, 0, 255),
                       mb_substr($barangay, 0, 80), $lat, $lng, $photo]);
        $id = (int) $this->db->lastInsertId();
        $this->log((int) $admin['id'], 'store_created', 'store', $id, 'Added store "' . $name . '"');
        respond(true, 'Store added.', ['id' => $id], 201);
    }

    // ------------------------------------------------------------ users
    /** GET /admin/users?role=store_owner|customer&status=&q= */
    public function users(): void
    {
        Auth::user(['admin']);
        $role = $_GET['role'] ?? 'customer';
        if (!in_array($role, ['store_owner', 'customer'], true)) respond(false, 'Invalid role.', null, 422);
        $sql = "SELECT u.id, u.full_name, u.email, u.phone, u.role, u.status, u.avatar_url, u.created_at,
                       (SELECT s.name FROM stores s WHERE s.owner_id = u.id LIMIT 1) AS store_name
                FROM users u WHERE u.role = ?";
        $args = [$role];
        $status = $_GET['status'] ?? '';
        if (in_array($status, ['active', 'suspended'], true)) { $sql .= ' AND u.status = ?'; $args[] = $status; }
        $q = trim((string) ($_GET['q'] ?? ''));
        if ($q !== '') { $sql .= ' AND (u.full_name LIKE ? OR u.email LIKE ?)'; $args[] = "%$q%"; $args[] = "%$q%"; }
        $sql .= ' ORDER BY u.created_at DESC, u.id DESC LIMIT 500';
        $st = $this->db->prepare($sql);
        $st->execute($args);
        respond(true, 'OK', $st->fetchAll());
    }

    /** POST /admin/users/status  { user_id, status: active|suspended } */
    public function setUserStatus(): void
    {
        $admin = Auth::user(['admin']);
        $d = body();
        $id = (int) ($d['user_id'] ?? 0);
        $status = $d['status'] ?? '';
        if ($id <= 0 || !in_array($status, ['active', 'suspended'], true)) respond(false, 'Invalid request.', null, 422);
        $st = $this->db->prepare('SELECT full_name, role FROM users WHERE id = ?');
        $st->execute([$id]);
        $u = $st->fetch();
        if (!$u) respond(false, 'User not found.', null, 404);
        if (!in_array($u['role'], ['customer', 'store_owner'], true)) respond(false, 'Admin accounts cannot be changed here.', null, 403);

        $this->db->prepare('UPDATE users SET status = ? WHERE id = ?')->execute([$status, $id]);
        if ($status === 'suspended') $this->db->prepare('DELETE FROM refresh_tokens WHERE user_id = ?')->execute([$id]);
        $this->log((int) $admin['id'], $status === 'suspended' ? 'user_suspended' : 'user_activated', 'user', $id,
            ($status === 'suspended' ? 'Suspended ' : 'Activated ') . $u['full_name']);
        respond(true, $status === 'suspended' ? 'Account suspended.' : 'Account activated.');
    }

    // ------------------------------------------------------------ products (monitoring only)
    /** GET /admin/products?store_id=&category_id=&stock=low|out|in|hidden&q= */
    public function products(): void
    {
        Auth::user(['admin']);
        $sql = 'SELECT p.id, p.name, p.brand, p.unit, p.price, p.stock_quantity, p.low_stock_threshold, p.image_url,
                       p.is_available, p.is_hidden, p.moderation_reason, p.store_id, s.name AS store_name, c.name AS category_name,
                       GREATEST(p.price_updated_at, p.stock_updated_at) AS last_updated
                FROM products p JOIN stores s ON s.id = p.store_id JOIN categories c ON c.id = p.category_id WHERE 1=1';
        $args = [];
        if (!empty($_GET['store_id'])) { $sql .= ' AND p.store_id = ?'; $args[] = (int) $_GET['store_id']; }
        if (!empty($_GET['category_id'])) { $sql .= ' AND p.category_id = ?'; $args[] = (int) $_GET['category_id']; }
        $stock = $_GET['stock'] ?? '';
        if ($stock === 'out') $sql .= ' AND p.stock_quantity <= 0';
        elseif ($stock === 'low') $sql .= ' AND p.stock_quantity > 0 AND p.stock_quantity <= p.low_stock_threshold';
        elseif ($stock === 'in') $sql .= ' AND p.stock_quantity > p.low_stock_threshold';
        elseif ($stock === 'hidden') $sql .= ' AND p.is_hidden = 1';
        $q = trim((string) ($_GET['q'] ?? ''));
        if ($q !== '') { $sql .= ' AND (p.name LIKE ? OR p.brand LIKE ?)'; $args[] = "%$q%"; $args[] = "%$q%"; }
        $sql .= ' ORDER BY s.name, p.name LIMIT 300';
        $st = $this->db->prepare($sql);
        $st->execute($args);
        respond(true, 'OK', $st->fetchAll());
    }

    /** POST /admin/products/moderate  { product_id, hidden, reason }  (hides/restores a listing only) */
    public function moderateProduct(): void
    {
        $admin = Auth::user(['admin']);
        $d = body();
        $id = (int) ($d['product_id'] ?? 0);
        $hidden = !empty($d['hidden']) ? 1 : 0;
        $reason = trim((string) ($d['reason'] ?? ''));
        if ($id <= 0) respond(false, 'Invalid request.', null, 422);
        if ($hidden && ($reason === '' || mb_strlen($reason) > 255)) respond(false, 'A reason is required to hide a listing (up to 255 characters).', null, 422);

        $st = $this->db->prepare('SELECT p.name, s.owner_id FROM products p JOIN stores s ON s.id = p.store_id WHERE p.id = ?');
        $st->execute([$id]);
        $p = $st->fetch();
        if (!$p) respond(false, 'Product not found.', null, 404);

        $this->db->prepare('UPDATE products SET is_hidden = ?, moderation_reason = ? WHERE id = ?')
            ->execute([$hidden, $hidden ? $reason : null, $id]);
        if ($p['owner_id'] !== null) {
            $this->notify((int) $p['owner_id'], $hidden ? 'Listing hidden by admin' : 'Listing restored',
                $hidden ? '"' . $p['name'] . '" was hidden. Reason: ' . $reason : '"' . $p['name'] . '" is visible again.', $id, 'moderation');
        }
        $this->log((int) $admin['id'], $hidden ? 'product_hidden' : 'product_restored', 'product', $id,
            ($hidden ? 'Hid "' : 'Restored "') . $p['name'] . '"' . ($hidden ? ': ' . $reason : ''));
        respond(true, $hidden ? 'Listing hidden from viewers.' : 'Listing restored.');
    }

    // ------------------------------------------------------------ reports
    /** GET /admin/analytics */
    public function analytics(): void
    {
        Auth::user(['admin']);

        // stores added per month (last 6 months), filled with zeros
        $rows = $this->db->query(
            "SELECT DATE_FORMAT(created_at, '%Y-%m') AS m, COUNT(*) AS n FROM stores
             WHERE created_at >= DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 5 MONTH)
             GROUP BY m")->fetchAll();
        $byMonth = [];
        foreach ($rows as $r) $byMonth[$r['m']] = (int) $r['n'];
        $months = [];
        for ($i = 5; $i >= 0; $i--) {
            $key = date('Y-m', strtotime("first day of -$i month"));
            $months[] = ['month' => $key, 'count' => $byMonth[$key] ?? 0];
        }

        $claims = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
        foreach ($this->db->query('SELECT status, COUNT(*) AS n FROM store_claims GROUP BY status')->fetchAll() as $r) {
            $claims[$r['status']] = (int) $r['n'];
        }

        $categories = $this->db->query(
            'SELECT c.name, COUNT(p.id) AS count FROM categories c LEFT JOIN products p ON p.category_id = c.id
             GROUP BY c.id, c.name ORDER BY count DESC, c.name')->fetchAll();

        $users = $this->db->query(
            "SELECT SUM(role = 'store_owner') AS owners, SUM(role = 'customer') AS viewers,
                    SUM(status = 'suspended' AND role <> 'admin') AS suspended,
                    SUM(role <> 'admin' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS new_30d
             FROM users")->fetch();

        $activity = $this->db->query(
            "SELECT s.id, s.name, s.is_active, COUNT(p.id) AS product_count,
                    SUM(p.stock_quantity > 0 AND p.stock_quantity <= p.low_stock_threshold) AS low_stock,
                    SUM(p.stock_quantity <= 0) AS out_of_stock,
                    MAX(GREATEST(p.price_updated_at, p.stock_updated_at)) AS last_update
             FROM stores s LEFT JOIN products p ON p.store_id = s.id
             WHERE s.owner_id IS NOT NULL
             GROUP BY s.id, s.name, s.is_active ORDER BY last_update DESC LIMIT 10")->fetchAll();

        respond(true, 'OK', [
            'stores_by_month'   => $months,
            'claims_by_status'  => $claims,
            'products_by_category' => $categories,
            'users'             => [
                'owners'    => (int) ($users['owners'] ?? 0),
                'viewers'   => (int) ($users['viewers'] ?? 0),
                'suspended' => (int) ($users['suspended'] ?? 0),
                'new_30d'   => (int) ($users['new_30d'] ?? 0),
            ],
            'store_activity'    => $activity,
        ]);
    }

    // ------------------------------------------------------------ logs
    /** GET /admin/logs?limit=100 */
    public function logs(): void
    {
        Auth::user(['admin']);
        $limit = max(1, min(300, (int) ($_GET['limit'] ?? 100)));
        $rows = $this->db->query(
            "SELECT l.id, l.action, l.target_type, l.target_id, l.details, l.created_at, u.full_name AS admin_name
             FROM admin_logs l JOIN users u ON u.id = l.admin_id ORDER BY l.id DESC LIMIT $limit")->fetchAll();
        respond(true, 'OK', $rows);
    }
}
