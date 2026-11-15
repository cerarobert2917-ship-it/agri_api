<?php
/** Mga update sa presyo/stock para sa viewer (farmer). */
class NotificationController
{
    private PDO $db;
    public function __construct() { $this->db = Database::conn(); }

    /** Viewer account (role is 'customer' or 'viewer' depending on your database). */
    private function viewer(): array
    {
        return Auth::user(['customer', 'viewer']);
    }

    /** GET /notifications/changes - pinakabagong pagbabago ng presyo at stock (wala ang mga binura ng viewer). */
    public function changes(): void
    {
        $u = $this->viewer();

        $st = $this->db->prepare(
            "SELECT pc.id, pc.product_id, p.name AS product_name, p.brand, p.unit,
                    p.price AS current_price, p.stock_quantity AS current_stock,
                    pc.store_id, s.name AS store_name,
                    pc.old_price, pc.new_price, pc.old_stock, pc.new_stock, pc.created_at
             FROM product_changes pc
             JOIN products p ON p.id = pc.product_id
             JOIN stores s ON s.id = pc.store_id
             WHERE s.status = 'approved' AND s.owner_id IS NOT NULL AND s.is_active = 1
               AND p.is_hidden = 0 AND p.is_available = 1
               AND pc.created_at >= (NOW() - INTERVAL 30 DAY)
               AND NOT EXISTS (SELECT 1 FROM user_notification_hidden h WHERE h.user_id = ? AND h.change_id = pc.id)
             ORDER BY pc.id DESC LIMIT 60");
        $st->execute([(int) $u['id']]);
        $items = $st->fetchAll();

        $seen = $this->db->prepare('SELECT last_seen_id FROM user_notification_state WHERE user_id = ?');
        $seen->execute([$u['id']]);
        $lastSeen = (int) ($seen->fetchColumn() ?: 0);

        $unread = 0;
        foreach ($items as $it) if ((int) $it['id'] > $lastSeen) $unread++;

        respond(true, 'OK', [
            'items' => $items,
            'latest_id' => $items ? (int) $items[0]['id'] : 0,
            'last_seen_id' => $lastSeen,
            'unread_count' => $unread,
        ]);
    }

    /** POST /notifications/seen { last_seen_id }  (Mark all as read) */
    public function seen(): void
    {
        $u = $this->viewer();
        $id = (int) (body()['last_seen_id'] ?? 0);
        if ($id < 0) respond(false, 'Maling request.', null, 422);
        $this->db->prepare(
            'INSERT INTO user_notification_state (user_id, last_seen_id) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE last_seen_id = GREATEST(last_seen_id, VALUES(last_seen_id))')
            ->execute([$u['id'], $id]);
        respond(true, 'OK');
    }

    /** POST /notifications/delete { id }  (itinatago lang para sa viewer na ito) */
    public function delete(): void
    {
        $u = $this->viewer();
        $id = (int) (body()['id'] ?? 0);
        if ($id <= 0) respond(false, 'Maling request.', null, 422);
        $this->db->prepare(
            'INSERT IGNORE INTO user_notification_hidden (user_id, change_id)
             SELECT ?, id FROM product_changes WHERE id = ?')
            ->execute([(int) $u['id'], $id]);
        respond(true, 'Notification deleted.');
    }

    /** POST /notifications/clear  (burahin lahat para sa viewer na ito) */
    public function clear(): void
    {
        $u = $this->viewer();
        $this->db->prepare(
            'INSERT IGNORE INTO user_notification_hidden (user_id, change_id)
             SELECT ?, id FROM product_changes WHERE created_at >= (NOW() - INTERVAL 30 DAY)')
            ->execute([(int) $u['id']]);
        respond(true, 'All notifications cleared.');
    }
}
