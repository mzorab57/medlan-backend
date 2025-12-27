<?php
class CartController
{
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
        $items = $conn->prepare('SELECT ci.id, ci.product_id, ci.product_spec_id, ci.quantity, p.name AS product_name FROM cart_items ci INNER JOIN products p ON p.id = ci.product_id WHERE ci.cart_id = ? ORDER BY ci.id');
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
        $ci = $conn->prepare('INSERT INTO cart_items (cart_id, product_id, product_spec_id, quantity) VALUES (?, ?, ?, ?)');
        $ci->bind_param('iiii', $cartId, $productId, $specId, $qty);
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
