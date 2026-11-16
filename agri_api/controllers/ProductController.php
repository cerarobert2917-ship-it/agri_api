<?php
class ProductController
{
    public function categories(): void
    {
        respond(true, 'OK', Database::conn()->query('SELECT id, name, icon FROM categories ORDER BY name')->fetchAll());
    }

    /** Ginagamit din ng AI tool. Haversine para sa distance. */
    public static function searchProducts(string $q = '', ?float $lat = null, ?float $lng = null, ?float $maxPrice = null, ?int $cat = null, int $limit = 30): array
    {
        $lat = $lat ?? 13.2230;   // default: center ng Mamburao
        $lng = $lng ?? 120.5960;
        $sql = "SELECT p.id, p.name, p.brand, p.unit, p.price, p.stock_quantity, p.low_stock_threshold,
                       GREATEST(p.price_updated_at, p.stock_updated_at) AS last_updated,
                       s.id AS store_id, s.name AS store_name, s.barangay, s.latitude, s.longitude, s.contact_number,
                       ROUND(6371 * ACOS(LEAST(1, COS(RADIANS(:lat1)) * COS(RADIANS(s.latitude))
                         * COS(RADIANS(s.longitude) - RADIANS(:lng1)) + SIN(RADIANS(:lat2)) * SIN(RADIANS(s.latitude)))), 2) AS distance_km
                FROM products p JOIN stores s ON s.id = p.store_id
                WHERE s.status = 'approved' AND s.owner_id IS NOT NULL AND s.is_active = 1 AND p.is_hidden = 0 AND p.is_available = 1";
        $args = [':lat1' => $lat, ':lng1' => $lng, ':lat2' => $lat];

        if ($q !== '') {
            $sql .= ' AND (p.name LIKE :q1 OR p.brand LIKE :q2 OR EXISTS (SELECT 1 FROM categories c WHERE c.id = p.category_id AND c.name LIKE :q3))';
            $args[':q1'] = $args[':q2'] = $args[':q3'] = "%$q%";
        }
        if ($maxPrice !== null) { $sql .= ' AND p.price <= :mp'; $args[':mp'] = $maxPrice; }
        if ($cat) { $sql .= ' AND p.category_id = :cat'; $args[':cat'] = $cat; }
        $sql .= ' ORDER BY p.price ASC, distance_km ASC LIMIT ' . (int) $limit;

        $st = Database::conn()->prepare($sql);
        $st->execute($args);
        $rows = $st->fetchAll();
        foreach ($rows as &$r) {
            $r['stock_status'] = $r['stock_quantity'] <= 0 ? 'out' : ($r['stock_quantity'] <= $r['low_stock_threshold'] ? 'low' : 'in');
            $r['is_stale'] = strtotime($r['last_updated']) < strtotime('-7 days');
        }
        return $rows;
    }

    public function search(): void
    {
        $q = trim($_GET['q'] ?? '');
        $rows = self::searchProducts(
            $q,
            isset($_GET['lat']) ? (float) $_GET['lat'] : null,
            isset($_GET['lng']) ? (float) $_GET['lng'] : null,
            isset($_GET['max_price']) ? (float) $_GET['max_price'] : null,
            isset($_GET['category_id']) ? (int) $_GET['category_id'] : null
        );
        if ($q !== '') {
            Database::conn()->prepare('INSERT INTO search_logs (query) VALUES (?)')->execute([mb_substr($q, 0, 200)]);
        }
        respond(true, count($rows) . ' resulta', $rows);
    }

    /** GET /catalog - every visible store with its products (one request for the viewer app). */
    public function catalog(): void
    {
        $db = Database::conn();
        $stores = $db->query(
            "SELECT s.id, s.name, s.description, s.address, s.barangay, s.latitude, s.longitude,
                    s.contact_number, s.opening_hours, s.photo_url
             FROM stores s
             WHERE s.status = 'approved' AND s.owner_id IS NOT NULL AND s.is_active = 1
             ORDER BY s.name")->fetchAll();
        $index = [];
        foreach ($stores as $i => $s) {
            $stores[$i]['products'] = [];
            $index[(int) $s['id']] = $i;
        }
        $rows = $db->query(
            "SELECT p.id, p.store_id, p.name, p.brand, p.unit, p.price, p.stock_quantity, p.low_stock_threshold,
                    c.name AS category_name, GREATEST(p.price_updated_at, p.stock_updated_at) AS last_updated
             FROM products p
             JOIN categories c ON c.id = p.category_id
             JOIN stores s ON s.id = p.store_id
             WHERE s.status = 'approved' AND s.owner_id IS NOT NULL AND s.is_active = 1
               AND p.is_hidden = 0 AND p.is_available = 1
             ORDER BY p.name")->fetchAll();
        foreach ($rows as $r) {
            $sid = (int) $r['store_id'];
            if (isset($index[$sid])) $stores[$index[$sid]]['products'][] = $r;
        }
        respond(true, 'OK', $stores);
    }

    public function stores(): void
    {
        $rows = Database::conn()->query("SELECT id, name, description, address, barangay, latitude, longitude, contact_number, opening_hours, photo_url
                                          FROM stores WHERE status = 'approved' AND owner_id IS NOT NULL AND is_active = 1")->fetchAll();
        respond(true, 'OK', $rows);
    }
}
