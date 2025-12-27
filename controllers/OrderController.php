<?php
class OrderController
{
    public function create(): void
    {
        global $conn;
        $d = getJsonInput();
        foreach (['customer_name','phone_number','items'] as $k) {
            if (!isset($d[$k])) {
                jsonResponse(false, 'missing field ' . $k, null, 422);
                return;
            }
        }
        $items = $d['items'];
        if (!is_array($items) || count($items) === 0) {
            jsonResponse(false, 'items required', null, 422);
            return;
        }
        $customer_name = sanitize($d['customer_name']);
        $phone_number = sanitize($d['phone_number']);
        $address = isset($d['address']) ? sanitize($d['address']) : null;
        $order_source = isset($d['order_source']) ? sanitize($d['order_source']) : 'website';
        $order_source = strtolower($order_source);
        $groups = [
            'instagram' => ['instagram','insta','ig'],
            'whatsapp' => ['whatsapp','wa','whats'],
            'website' => ['website','web'],
        ];
        $desired = 'website';
        foreach ($groups as $g => $syn) {
            if (in_array($order_source, $syn, true)) { $desired = $g; break; }
        }
        $allowed = [];
        try {
            $col = $conn->query("SHOW COLUMNS FROM orders LIKE 'order_source'")->fetch_assoc();
            if ($col && isset($col['Type']) && str_starts_with($col['Type'], 'enum(')) {
                $m = [];
                if (preg_match('/^enum\((.+)\)$/', $col['Type'], $m)) {
                    $vals = explode(',', $m[1]);
                    foreach ($vals as $v) { $allowed[] = trim($v, " '\""); }
                }
            }
        } catch (Throwable $e) { /* ignore */ }
        if (!$allowed) {
            try {
                $rs = $conn->query("SELECT DISTINCT order_source FROM orders LIMIT 20");
                while ($r = $rs->fetch_assoc()) { if (isset($r['order_source'])) $allowed[] = $r['order_source']; }
            } catch (Throwable $e) { /* ignore */ }
        }
        if ($allowed) {
            // prefer allowed synonym for desired group
            $syn = $groups[$desired];
            $chosen = null;
            foreach ($syn as $s) {
                if (in_array($s, $allowed, true)) { $chosen = $s; break; }
            }
            if ($chosen === null) {
                // fallback preference: website -> whatsapp -> instagram (any allowed synonym)
                foreach (['website','whatsapp','instagram'] as $g) {
                    foreach ($groups[$g] as $s) {
                        if (in_array($s, $allowed, true)) { $chosen = $s; break 2; }
                    }
                }
                if ($chosen === null) { $chosen = $allowed[0]; }
            }
            $order_source = $chosen;
        } else {
            // if no introspection, use compact codes to minimize truncation risk
            if ($desired === 'instagram') $order_source = 'insta';
            elseif ($desired === 'whatsapp') $order_source = 'wa';
            else $order_source = 'web';
        }
        $conn->begin_transaction();
        try {
            $zero = 0.0;
            $status = 'pending';
            $ins = $conn->prepare('INSERT INTO orders (customer_name, phone_number, address, total_price, status, order_source) VALUES (?, ?, ?, ?, ?, ?)');
            $ins->bind_param('sssdss', $customer_name, $phone_number, $address, $zero, $status, $order_source);
            $ins->execute();
            $orderId = (int)$conn->insert_id;
            $total = 0.0;
            foreach ($items as $it) {
                $specId = (int)($it['product_spec_id'] ?? 0);
                $qty = max(1, (int)($it['quantity'] ?? 1));
                if ($specId <= 0) {
                    throw new Exception('invalid spec id');
                }
                $row = $conn->prepare('SELECT ps.price AS spec_price, ps.product_id, ps.stock AS stock, p.purchase_price AS cost FROM product_specifications ps INNER JOIN products p ON p.id = ps.product_id WHERE ps.id = ?');
                $row->bind_param('i', $specId);
                $row->execute();
                $r = $row->get_result()->fetch_assoc();
                if (!$r) {
                    throw new Exception('spec not found');
                }
                if ((int)$r['stock'] < $qty) {
                    throw new Exception('insufficient stock');
                }
                $view = $conn->prepare('SELECT promotion_id, discount_type, discount_value, final_price, discount_amount FROM vw_product_prices WHERE spec_id = ?');
                $view->bind_param('i', $specId);
                $view->execute();
                $v = $view->get_result()->fetch_assoc();
                $finalPrice = isset($v['final_price']) ? (float)$v['final_price'] : (float)$r['spec_price'];
                $origPrice = (float)$r['spec_price'];
                $discountAmount = isset($v['discount_amount']) ? (float)$v['discount_amount'] : 0.0;
                $cost = (float)$r['cost'];
                $promoId = isset($v['promotion_id']) ? (int)$v['promotion_id'] : null;
                $productId = (int)$r['product_id'];
                if ($promoId === null) {
                    $oi = $conn->prepare('INSERT INTO order_items (order_id, product_id, product_spec_id, quantity, price, original_price, cost, discount_amount, promotion_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL)');
                    $oi->bind_param('iiiidddd', $orderId, $productId, $specId, $qty, $finalPrice, $origPrice, $cost, $discountAmount);
                } else {
                    $oi = $conn->prepare('INSERT INTO order_items (order_id, product_id, product_spec_id, quantity, price, original_price, cost, discount_amount, promotion_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                    $oi->bind_param('iiiiddddi', $orderId, $productId, $specId, $qty, $finalPrice, $origPrice, $cost, $discountAmount, $promoId);
                }
                $oi->execute();
                $total += $finalPrice * $qty;
                $orderItemId = (int)$conn->insert_id;
                $sm = $conn->prepare('INSERT INTO stock_movements (product_spec_id, type, quantity, order_item_id, description) VALUES (?, ?, ?, ?, ?)');
                $type = 'sale';
                $desc = 'order sale';
                $negQty = -$qty;
                $sm->bind_param('isiss', $specId, $type, $negQty, $orderItemId, $desc);
                $sm->execute();
                $upd = $conn->prepare('UPDATE product_specifications SET stock = stock - ? WHERE id = ?');
                $upd->bind_param('ii', $qty, $specId);
                $upd->execute();
            }
            $uord = $conn->prepare('UPDATE orders SET total_price = ? WHERE id = ?');
            $uord->bind_param('di', $total, $orderId);
            $uord->execute();
            $conn->commit();
            jsonResponse(true, 'Created', ['id' => $orderId, 'total_price' => $total], 201);
        } catch (Throwable $e) {
            $conn->rollback();
            jsonResponse(false, 'Order failed', ['detail' => $e->getMessage()], 400);
        }
    }
    
    public function list(): void
    {
        global $conn;
        $status = $_GET['status'] ?? null;
        $source = $_GET['source'] ?? null;
        $where = [];
        $types = '';
        $params = [];
        if ($status !== null) { $where[] = 'status = ?'; $types .= 's'; $params[] = sanitize($status); }
        if ($source !== null) { $where[] = 'order_source = ?'; $types .= 's'; $params[] = sanitize($source); }
        $w = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        $sql = "SELECT id, customer_name, phone_number, total_price, status, order_source, created_at FROM orders $w ORDER BY id DESC LIMIT 100";
        $st = $conn->prepare($sql);
        if ($types !== '') {
            $st->bind_param($types, ...$params);
        }
        $st->execute();
        $res = $st->get_result();
        $rows = [];
        while ($r = $res->fetch_assoc()) { $rows[] = $r; }
        jsonResponse(true, 'OK', ['data' => $rows]);
    }
    
    public function show($id): void
    {
        global $conn;
        $oid = (int)$id;
        $ord = $conn->prepare('SELECT * FROM orders WHERE id = ?');
        $ord->bind_param('i', $oid);
        $ord->execute();
        $o = $ord->get_result()->fetch_assoc();
        if (!$o) {
            jsonResponse(false, 'Not Found', null, 404);
            return;
        }
        $items = $conn->prepare('SELECT * FROM order_items WHERE order_id = ?');
        $items->bind_param('i', $oid);
        $items->execute();
        $rows = [];
        $ri = $items->get_result();
        while ($r = $ri->fetch_assoc()) { $rows[] = $r; }
        jsonResponse(true, 'OK', ['order' => $o, 'items' => $rows]);
    }
    
    public function updateStatus($id): void
    {
        global $conn;
        $oid = (int)$id;
        $oldRow = $conn->prepare('SELECT status FROM orders WHERE id = ?');
        $oldRow->bind_param('i', $oid);
        $oldRow->execute();
        $old = $oldRow->get_result()->fetch_assoc();
        $d = getJsonInput();
        $status = isset($d['status']) ? sanitize($d['status']) : null;
        if (!$status) {
            jsonResponse(false, 'status required', null, 422);
            return;
        }
        $st = $conn->prepare('UPDATE orders SET status = ? WHERE id = ?');
        $st->bind_param('si', $status, $oid);
        $st->execute();
        if ($old && !in_array($old['status'], ['cancelled','returned'], true) && in_array($status, ['cancelled','returned'], true)) {
            $it = $conn->prepare('SELECT id, product_spec_id, quantity FROM order_items WHERE order_id = ?');
            $it->bind_param('i', $oid);
            $it->execute();
            $res = $it->get_result();
            while ($r = $res->fetch_assoc()) {
                $specId = (int)$r['product_spec_id'];
                $qty = (int)$r['quantity'];
                $type = 'adjustment';
                $desc = $status === 'returned' ? 'order return' : 'order cancel';
                $sm = $conn->prepare('INSERT INTO stock_movements (product_spec_id, type, quantity, order_item_id, description) VALUES (?, ?, ?, ?, ?)');
                $orderItemIdRef = (int)$r['id'];
                $sm->bind_param('isiss', $specId, $type, $qty, $orderItemIdRef, $desc);
                $sm->execute();
                $upd = $conn->prepare('UPDATE product_specifications SET stock = stock + ? WHERE id = ?');
                $upd->bind_param('ii', $qty, $specId);
                $upd->execute();
            }
        }
        jsonResponse(true, 'Updated', ['updated' => true]);
    }
}
