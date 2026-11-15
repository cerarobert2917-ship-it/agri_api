<?php
class OwnerController
{
    private PDO $db;
    public function __construct() { $this->db = Database::conn(); }

    /** Approved na store lang ng owner (para sa products at pag-edit). */
    private function myStoreRow(int $uid): ?array
    {
        $st = $this->db->prepare("SELECT * FROM stores WHERE owner_id = ? AND status = 'approved' LIMIT 1");
        $st->execute([$uid]);
        return $st->fetch() ?: null;
    }

    private function requireStore(int $uid): array
    {
        $s = $this->myStoreRow($uid);
        if (!$s) respond(false, 'Wala ka pang approved na tindahan.', null, 403);
        return $s;
    }

    /** GET /owner/stores/available */
    public function availableStores(): void
    {
        Auth::user(['store_owner']);
        $rows = $this->db->query(
            "SELECT s.id, s.name, s.address, s.barangay FROM stores s
             WHERE s.owner_id IS NULL
               AND NOT EXISTS (SELECT 1 FROM store_claims c WHERE c.store_id = s.id AND c.status = 'pending')
             ORDER BY s.name")->fetchAll();
        respond(true, 'OK', $rows);
    }

    private function saveImage(string $b64, string $label): string
    {
        $b64 = preg_replace('#^data:image/\w+;base64,#', '', $b64);
        $bin = base64_decode($b64, true);
        if ($bin === false || $bin === '') respond(false, "Sira ang larawan ($label).", null, 422);
        if (strlen($bin) > 3 * 1024 * 1024) respond(false, "Masyadong malaki ang larawan ($label). Hanggang 3 MB lang.", null, 422);
        $info = @getimagesizefromstring($bin);
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png'][$info['mime'] ?? ''] ?? null;
        if (!$ext) respond(false, "JPEG o PNG lang ang tinatanggap ($label).", null, 422);

        $dir = dirname(__DIR__) . '/uploads/claims';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) respond(false, 'Hindi ma-save ang larawan.', null, 500);
        $name = bin2hex(random_bytes(16)) . '.' . $ext;
        if (file_put_contents("$dir/$name", $bin) === false) respond(false, 'Hindi ma-save ang larawan.', null, 500);
        return $name;
    }

    /** POST /owner/store/claim { store_id, permit_image, storefront_image } */
    public function claim(): void
    {
        $u = Auth::user(['store_owner']);
        $d = body();
        $storeId = (int) ($d['store_id'] ?? 0);
        if ($storeId <= 0) respond(false, 'Pumili ng store.', null, 422);
        if (empty($d['permit_image']) || empty($d['storefront_image'])) respond(false, 'Kailangan ang permit/ID at larawan ng tindahan.', null, 422);

        // may pending o approved na ba ang owner?
        $chk = $this->db->prepare("SELECT status FROM store_claims WHERE owner_id = ? AND status IN ('pending','approved') LIMIT 1");
        $chk->execute([$u['id']]);
        if ($row = $chk->fetch()) {
            respond(false, $row['status'] === 'pending' ? 'May pending ka nang claim.' : 'May approved ka nang store.', null, 409);
        }
        if ($this->myStoreRow($u['id'])) respond(false, 'May store ka na.', null, 409);

        $permit = $this->saveImage((string) $d['permit_image'], 'permit/ID');
        $front = $this->saveImage((string) $d['storefront_image'], 'storefront');

        $this->db->beginTransaction();
        try {
            $s = $this->db->prepare('SELECT id, name, owner_id FROM stores WHERE id = ? FOR UPDATE');
            $s->execute([$storeId]);
            $store = $s->fetch();
            if (!$store) throw new RuntimeException('Hindi mahanap ang store.');
            if ($store['owner_id'] !== null) throw new RuntimeException('May owner na ang store na ito.');
            $p = $this->db->prepare("SELECT 1 FROM store_claims WHERE store_id = ? AND status = 'pending' LIMIT 1");
            $p->execute([$storeId]);
            if ($p->fetch()) throw new RuntimeException('May naka-pending nang claim sa store na ito.');

            $this->db->prepare("INSERT INTO store_claims (store_id, owner_id, permit_image, storefront_image) VALUES (?,?,?,?)")
                ->execute([$storeId, $u['id'], $permit, $front]);
            $claimId = (int) $this->db->lastInsertId();

            // abisuhan ang lahat ng admin
            $admins = $this->db->query("SELECT id FROM users WHERE role = 'admin' AND status = 'active'")->fetchAll();
            $ins = $this->db->prepare("INSERT INTO notifications (user_id, title, body, type, reference_id) VALUES (?,?,?,'claim',?)");
            foreach ($admins as $a) {
                $ins->execute([$a['id'], 'Bagong store claim', $u['full_name'] . ' ay nag-claim ng "' . $store['name'] . '".', $claimId]);
            }
            $this->db->commit();
        } catch (RuntimeException $e) {
            $this->db->rollBack();
            @unlink(dirname(__DIR__) . "/uploads/claims/$permit");
            @unlink(dirname(__DIR__) . "/uploads/claims/$front");
            respond(false, $e->getMessage(), null, 409);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
        respond(true, 'Naipadala ang claim. Hintayin ang approval ng admin.', null, 201);
    }

    /** GET /owner/store — pinakabagong claim ng owner (o approved store). null kung wala. */
    public function myStore(): void
    {
        $u = Auth::user(['store_owner']);
        $st = $this->db->prepare(
            'SELECT s.id AS store_id, s.name, s.address, s.barangay, s.latitude, s.longitude, s.description,
                    s.contact_number, s.opening_hours, c.status, c.rejection_reason
             FROM store_claims c JOIN stores s ON s.id = c.store_id
             WHERE c.owner_id = ? ORDER BY c.id DESC LIMIT 1');
        $st->execute([$u['id']]);
        $row = $st->fetch();
        if (!$row) {
            // lumang data: may approved store pero walang claim record
            $s = $this->myStoreRow($u['id']);
            if ($s) {
                $row = ['store_id' => $s['id'], 'name' => $s['name'], 'address' => $s['address'], 'barangay' => $s['barangay'],
                        'latitude' => $s['latitude'], 'longitude' => $s['longitude'], 'description' => $s['description'],
                        'contact_number' => $s['contact_number'], 'opening_hours' => $s['opening_hours'],
                        'status' => 'approved', 'rejection_reason' => null];
            }
        }
        respond(true, 'OK', $row ?: null);
    }

    /** POST /owner/store — description, contact_number, opening_hours lang. */
    public function saveStore(): void
    {
        $u = Auth::user(['store_owner']);
        $store = $this->requireStore($u['id']);
        $d = body();
        $contact = trim((string) ($d['contact_number'] ?? ''));
        if ($contact !== '' && !validPhone($contact)) respond(false, 'Maling contact number.', null, 422);
        $this->db->prepare('UPDATE stores SET description=?, contact_number=?, opening_hours=? WHERE id=?')
            ->execute([trim((string) ($d['description'] ?? '')), $contact, trim((string) ($d['opening_hours'] ?? '')), $store['id']]);
        respond(true, 'Na-save ang tindahan.');
    }

    public function products(): void
    {
        $u = Auth::user(['store_owner']);
        $store = $this->myStoreRow($u['id']);
        if (!$store) respond(true, 'Wala pang approved na tindahan.', []);
        $st = $this->db->prepare('SELECT p.*, c.name AS category_name FROM products p JOIN categories c ON c.id = p.category_id WHERE p.store_id = ? ORDER BY p.name');
        $st->execute([$store['id']]);
        respond(true, 'OK', $st->fetchAll());
    }

    private function productSnapshot(int $id, int $storeId): ?array
    {
        $st = $this->db->prepare('SELECT price, stock_quantity FROM products WHERE id = ? AND store_id = ?');
        $st->execute([$id, $storeId]);
        $r = $st->fetch();
        return $r ? ['price' => round((float) $r['price'], 2), 'stock' => (int) $r['stock_quantity']] : null;
    }

    /**
     * Itala sa feed ng viewer ang pagbabago ng presyo/stock.
     * Kung may update sa parehong produkto sa nakaraang 15 minuto (hal. paulit-ulit na + at −),
     * pinagsasama ito sa isang entry para hindi mapuno ang notifications.
     */
    private function logChange(int $productId, int $storeId, ?array $before, ?array $after): void
    {
        if (!$before || !$after) return;
        $priceChanged = $before['price'] !== $after['price'];
        $stockChanged = $before['stock'] !== $after['stock'];
        if (!$priceChanged && !$stockChanged) return;

        $prev = $this->db->prepare('SELECT * FROM product_changes WHERE product_id = ? AND created_at >= (NOW() - INTERVAL 15 MINUTE) ORDER BY id DESC LIMIT 1');
        $prev->execute([$productId]);
        $p = $prev->fetch() ?: null;

        $oldPrice = $newPrice = $oldStock = $newStock = null;
        if ($priceChanged) { $oldPrice = $before['price']; $newPrice = $after['price']; }
        if ($stockChanged) { $oldStock = $before['stock']; $newStock = $after['stock']; }
        if ($p) {
            if ($p['old_price'] !== null) {
                $oldPrice = round((float) $p['old_price'], 2);
                $newPrice = $priceChanged ? $after['price'] : round((float) $p['new_price'], 2);
            }
            if ($p['old_stock'] !== null) {
                $oldStock = (int) $p['old_stock'];
                $newStock = $stockChanged ? $after['stock'] : (int) $p['new_stock'];
            }
            $this->db->prepare('DELETE FROM product_changes WHERE id = ?')->execute([$p['id']]);
        }
        // bumalik sa dati = walang pagbabago
        if ($oldPrice !== null && abs($oldPrice - $newPrice) < 0.005) $oldPrice = $newPrice = null;
        if ($oldStock !== null && $oldStock === $newStock) $oldStock = $newStock = null;
        if ($oldPrice === null && $oldStock === null) return;

        $this->db->prepare('INSERT INTO product_changes (product_id, store_id, old_price, new_price, old_stock, new_stock) VALUES (?,?,?,?,?,?)')
            ->execute([$productId, $storeId, $oldPrice, $newPrice, $oldStock, $newStock]);
    }

    public function saveProduct(): void
    {
        $u = Auth::user(['store_owner']);
        $store = $this->requireStore($u['id']);
        $d = body();
        if (empty($d['name']) || !isset($d['price']) || $d['price'] < 0) respond(false, 'Ilagay ang pangalan at tamang presyo.', null, 422);

        $vals = [$d['category_id'] ?? 1, $d['name'], $d['brand'] ?? '', $d['unit'] ?? 'piece',
                 (float) $d['price'], (int) ($d['stock_quantity'] ?? 0), (int) ($d['low_stock_threshold'] ?? 5)];
        if (!empty($d['id'])) {
            $pid = (int) $d['id'];
            $before = $this->productSnapshot($pid, (int) $store['id']);
            // siguraduhing sa tindahan niya ang product (IDOR protection)
            $this->db->prepare('UPDATE products SET category_id=?, name=?, brand=?, unit=?, price=?, stock_quantity=?, low_stock_threshold=?
                                WHERE id=? AND store_id=?')->execute([...$vals, $pid, $store['id']]);
            $this->logChange($pid, (int) $store['id'], $before, $this->productSnapshot($pid, (int) $store['id']));
        } else {
            $this->db->prepare('INSERT INTO products (category_id, name, brand, unit, price, stock_quantity, low_stock_threshold, store_id)
                                VALUES (?,?,?,?,?,?,?,?)')->execute([...$vals, $store['id']]);
        }
        respond(true, 'Na-save ang produkto.');
    }

    public function quickUpdate(): void
    {
        $u = Auth::user(['store_owner']);
        $store = $this->requireStore($u['id']);
        $d = body();
        $pid = (int) ($d['id'] ?? 0);
        $before = $this->productSnapshot($pid, (int) $store['id']);
        $this->db->prepare('UPDATE products SET price = COALESCE(?, price), stock_quantity = COALESCE(?, stock_quantity),
                            is_available = COALESCE(?, is_available) WHERE id = ? AND store_id = ?')
            ->execute([$d['price'] ?? null, $d['stock_quantity'] ?? null, $d['is_available'] ?? null, $pid, $store['id']]);
        $this->logChange($pid, (int) $store['id'], $before, $this->productSnapshot($pid, (int) $store['id']));
        respond(true, 'Na-update.');
    }

    public function deleteProduct(): void
    {
        $u = Auth::user(['store_owner']);
        $store = $this->requireStore($u['id']);
        $this->db->prepare('DELETE FROM products WHERE id = ? AND store_id = ?')->execute([body()['id'] ?? 0, $store['id']]);
        respond(true, 'Natanggal ang produkto.');
    }
}
