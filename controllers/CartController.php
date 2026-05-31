<?php
class CartController
{
    private function hasColumn(string $table, string $column): bool
    {
        global $conn;
        $t = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        $c = preg_replace('/[^a-zA-Z0-9_]/', '', $column);
        if ($t === '' || $c === '') { return false; }
        $rs = $conn->query("SHOW COLUMNS FROM `$t` LIKE '$c'");
        if (!$rs) { return false; }
        return (bool)$rs->fetch_assoc();
    }

    public function create(): void
    {
        global $conn;
        $d = getJsonInput();
        $session = sanitize($d['session_id'] ?? '');
        if ($session === '') { jsonResponse(false, 'session_id required', null, 422); return; }
        $st = $conn->prepare('INSERT INTO carts (session_id) VALUES (?)');
        $st->bind_param('s', $session);
        if (!$st->execute()) {
            if ($conn->errno !== 1062) {
                jsonResponse(false, 'Error', null, 500); return;
            }
        }
        jsonResponse(true, 'OK', ['session_id' => $session]);
    }

    public function get($sessionId): void
    {
        global $conn;
        $sid = sanitize($sessionId);
        $cart = $conn->prepare('SELECT id, session_id FROM carts WHERE session_id = ?');
        $cart->bind_param('s', $sid);
        $cart->execute();
        $c = $cart->get_result()->fetch_assoc();
        if (!$c) { jsonResponse(false, 'Not Found', null, 404); return; }
        $hasImgId = $this->hasColumn('cart_items', 'variant_image_id');
        if ($hasImgId) {
            $items = $conn->prepare("SELECT ci.id, ci.product_id, ci.product_spec_id, ci.quantity,
                                            ci.variant_image_id,
                                            p.name AS product_name,
                                            COALESCE(
                                              (SELECT psi.image FROM product_spec_images psi WHERE psi.id = ci.variant_image_id LIMIT 1),
                                              (SELECT psi2.image
                                                 FROM product_spec_images psi2
                                                WHERE psi2.spec_id = ci.product_spec_id
                                                ORDER BY psi2.is_primary DESC, psi2.sort_order ASC, psi2.id ASC
                                                LIMIT 1)
                                            ) AS variant_image
                                       FROM cart_items ci
                                       INNER JOIN products p ON p.id = ci.product_id
                                       WHERE ci.cart_id = ?
                                       ORDER BY ci.id");
        } else {
            $items = $conn->prepare("SELECT ci.id, ci.product_id, ci.product_spec_id, ci.quantity,
                                            p.name AS product_name,
                                            (SELECT psi.image
                                               FROM product_spec_images psi
                                              WHERE psi.spec_id = ci.product_spec_id
                                              ORDER BY psi.is_primary DESC, psi.sort_order ASC, psi.id ASC
                                              LIMIT 1) AS variant_image
                                       FROM cart_items ci
                                       INNER JOIN products p ON p.id = ci.product_id
                                       WHERE ci.cart_id = ?
                                       ORDER BY ci.id");
        }
        $id = (int)$c['id'];
        $items->bind_param('i', $id);
        $items->execute();
        $rows = [];
        $res = $items->get_result();
        while ($r = $res->fetch_assoc()) { $rows[] = $r; }
        jsonResponse(true, 'OK', ['cart' => $c, 'items' => $rows]);
    }

    public function addItem(): void
    {
        global $conn;
        $d = getJsonInput();
        $session = sanitize($d['session_id'] ?? '');
        $specId = (int)($d['product_spec_id'] ?? 0);
        $variantImageId = isset($d['variant_image_id']) ? (int)$d['variant_image_id'] : null;
        $qty = max(1, (int)($d['quantity'] ?? 1));
        if ($session === '' || $specId <= 0) { jsonResponse(false, 'session_id and product_spec_id required', null, 422); return; }
        $c = $conn->prepare('SELECT id FROM carts WHERE session_id = ?');
        $c->bind_param('s', $session);
        $c->execute();
        $res = $c->get_result()->fetch_assoc();
        if (!$res) {
            $ins = $conn->prepare('INSERT INTO carts (session_id) VALUES (?)');
            $ins->bind_param('s', $session);
            $ins->execute();
            $cartId = (int)$conn->insert_id;
        } else { $cartId = (int)$res['id']; }
        $ps = $conn->prepare('SELECT product_id FROM product_specifications WHERE id = ?');
        $ps->bind_param('i', $specId);
        $ps->execute();
        $row = $ps->get_result()->fetch_assoc();
        if (!$row) { jsonResponse(false, 'Invalid product_spec_id', null, 422); return; }
        $productId = (int)$row['product_id'];

        $hasImgId = $this->hasColumn('cart_items', 'variant_image_id');
        if ($hasImgId && $variantImageId !== null && $variantImageId > 0) {
            $chk = $conn->prepare('SELECT id FROM product_spec_images WHERE id = ? AND spec_id = ? LIMIT 1');
            $chk->bind_param('ii', $variantImageId, $specId);
            $chk->execute();
            $ok = $chk->get_result()->fetch_assoc();
            if (!$ok) { $variantImageId = null; }
        } else {
            $variantImageId = null;
        }

        if ($hasImgId) {
            $ci = $conn->prepare('INSERT INTO cart_items (cart_id, product_id, product_spec_id, quantity, variant_image_id) VALUES (?, ?, ?, ?, NULLIF(?, 0))');
            $imgId = $variantImageId !== null ? (int)$variantImageId : 0;
            $ci->bind_param('iiiii', $cartId, $productId, $specId, $qty, $imgId);
        } else {
            $ci = $conn->prepare('INSERT INTO cart_items (cart_id, product_id, product_spec_id, quantity) VALUES (?, ?, ?, ?)');
            $ci->bind_param('iiii', $cartId, $productId, $specId, $qty);
        }
        if (!$ci->execute()) {
            jsonResponse(false, 'Error', null, 500); return;
        }
        jsonResponse(true, 'Added', ['added' => true], 201);
    }

    public function updateItem($id): void
    {
        global $conn;
        $cid = (int)$id;
        $d = getJsonInput();
        $qty = max(1, (int)($d['quantity'] ?? 1));
        $st = $conn->prepare('UPDATE cart_items SET quantity = ? WHERE id = ?');
        $st->bind_param('ii', $qty, $cid);
        $st->execute();
        jsonResponse(true, 'Updated', ['updated' => true]);
    }

    public function removeItem($id): void
    {
        global $conn;
        $cid = (int)$id;
        $st = $conn->prepare('DELETE FROM cart_items WHERE id = ?');
        $st->bind_param('i', $cid);
        $st->execute();
        jsonResponse(true, 'Deleted', ['deleted' => true]);
    }
}
