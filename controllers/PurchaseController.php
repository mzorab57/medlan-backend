<?php
class PurchaseController
{
    private function ensureTables(): void
    {
        global $conn;
        $t1 = $conn->query("SHOW TABLES LIKE 'purchase_orders'");
        $t2 = $conn->query("SHOW TABLES LIKE 'purchase_order_items'");
        if (!($t1 && $t1->fetch_assoc() && $t2 && $t2->fetch_assoc())) {
            jsonResponse(false, 'Missing DB tables purchase_orders/purchase_order_items', null, 500);
        }
    }

    public function list(): void
    {
        AuthMiddleware::requireAuth();
        global $conn;
        $this->ensureTables();
        $status = isset($_GET['status']) ? sanitize($_GET['status']) : null;
        $sql = "SELECT
                    po.id,
                    po.supplier_name,
                    po.status,
                    po.total_cost,
                    po.created_at,
                    COALESCE(SUM(poi.quantity),0) AS items_qty,
                    COALESCE(SUM(poi.received_quantity),0) AS received_qty
                FROM purchase_orders po
                LEFT JOIN purchase_order_items poi ON poi.purchase_order_id = po.id";
        $types = '';
        $params = [];
        if ($status) {
            $sql .= " WHERE po.status = ?";
            $types = 's';
            $params[] = $status;
        }
        $sql .= " GROUP BY po.id ORDER BY po.id DESC LIMIT 200";
        $st = $conn->prepare($sql);
        if ($types !== '') { $st->bind_param($types, ...$params); }
        $st->execute();
        $res = $st->get_result();
        $rows = [];
        while ($r = $res->fetch_assoc()) { $rows[] = $r; }
        jsonResponse(true, 'OK', ['data' => $rows]);
    }

    public function show($id): void
    {
        AuthMiddleware::requireAuth();
        global $conn;
        $this->ensureTables();
        $pid = (int)$id;
        $st = $conn->prepare('SELECT * FROM purchase_orders WHERE id = ?');
        $st->bind_param('i', $pid);
        $st->execute();
        $po = $st->get_result()->fetch_assoc();
        if (!$po) {
            jsonResponse(false, 'Not Found', null, 404);
        }
        $it = $conn->prepare("SELECT
                poi.*,
                p.name AS product_name,
                COALESCE(ps.purchase_price, p.purchase_price) AS purchase_price,
                ps.price AS variant_price,
                c.name AS color_name,
                s.name AS size_name
            FROM purchase_order_items poi
            INNER JOIN product_specifications ps ON ps.id = poi.product_spec_id
            INNER JOIN products p ON p.id = ps.product_id
            LEFT JOIN colors c ON c.id = ps.color_id
            LEFT JOIN sizes s ON s.id = ps.size_id
            WHERE poi.purchase_order_id = ?
            ORDER BY poi.id ASC");
        $it->bind_param('i', $pid);
        $it->execute();
        $rows = [];
        $res = $it->get_result();
        while ($r = $res->fetch_assoc()) { $rows[] = $r; }
        jsonResponse(true, 'OK', ['purchase' => $po, 'items' => $rows]);
    }

    public function create(): void
    {
        $user = AuthMiddleware::requireAuth();
        global $conn;
        $this->ensureTables();
        $d = getJsonInput();
        $supplier = sanitize($d['supplier_name'] ?? '');
        $note = isset($d['note']) ? sanitize((string)$d['note']) : null;
        $status = sanitize($d['status'] ?? 'ordered');
        if (!in_array($status, ['draft', 'ordered'], true)) { $status = 'ordered'; }
        $items = $d['items'] ?? null;
        if ($supplier === '') { jsonResponse(false, 'supplier_name required', null, 422); }
        if (!is_array($items) || count($items) === 0) { jsonResponse(false, 'items required', null, 422); }

        $createdBy = isset($user['user_id']) ? (int)$user['user_id'] : null;
        $total = 0.0;
        $norm = [];
        foreach ($items as $it) {
            $specId = (int)($it['product_spec_id'] ?? 0);
            $qty = max(1, (int)($it['quantity'] ?? 0));
            $unitCost = (float)($it['unit_cost'] ?? 0);
            if ($specId <= 0 || $qty <= 0) { jsonResponse(false, 'Invalid item', null, 422); }
            if ($unitCost < 0) { jsonResponse(false, 'Invalid unit_cost', null, 422); }
            $total += $unitCost * $qty;
            $norm[] = ['spec_id' => $specId, 'qty' => $qty, 'unit_cost' => $unitCost];
        }

        $conn->begin_transaction();
        try {
            $ins = $conn->prepare('INSERT INTO purchase_orders (supplier_name, status, total_cost, note, created_by_user_id) VALUES (?, ?, ?, ?, ?)');
            $ins->bind_param('ssdsi', $supplier, $status, $total, $note, $createdBy);
            $ins->execute();
            $poId = (int)$conn->insert_id;

            foreach ($norm as $it) {
                $specId = $it['spec_id'];
                $qty = $it['qty'];
                $unitCost = $it['unit_cost'];
                $row = $conn->prepare('SELECT product_id FROM product_specifications WHERE id = ?');
                $row->bind_param('i', $specId);
                $row->execute();
                $r = $row->get_result()->fetch_assoc();
                if (!$r) { throw new Exception('spec not found'); }
                $productId = (int)$r['product_id'];
                $pi = $conn->prepare('INSERT INTO purchase_order_items (purchase_order_id, product_id, product_spec_id, quantity, received_quantity, unit_cost) VALUES (?, ?, ?, ?, 0, ?)');
                $pi->bind_param('iiiid', $poId, $productId, $specId, $qty, $unitCost);
                $pi->execute();
            }

            $conn->commit();
            jsonResponse(true, 'Created', ['id' => $poId, 'total_cost' => $total], 201);
        } catch (Throwable $e) {
            $conn->rollback();
            jsonResponse(false, 'Create failed', ['detail' => $e->getMessage()], 400);
        }
    }

    public function receiveAll($id): void
    {
        AuthMiddleware::requireAuth();
        global $conn;
        $this->ensureTables();
        $poId = (int)$id;
        $st = $conn->prepare('SELECT id, status FROM purchase_orders WHERE id = ?');
        $st->bind_param('i', $poId);
        $st->execute();
        $po = $st->get_result()->fetch_assoc();
        if (!$po) { jsonResponse(false, 'Not Found', null, 404); }
        $status = strtolower((string)($po['status'] ?? ''));
        if (!in_array($status, ['ordered', 'partial'], true)) {
            jsonResponse(false, 'Cannot receive in this status', null, 409);
        }

        $it = $conn->prepare('SELECT id, product_spec_id, quantity, received_quantity, unit_cost FROM purchase_order_items WHERE purchase_order_id = ? ORDER BY id ASC');
        $it->bind_param('i', $poId);
        $it->execute();
        $rows = [];
        $res = $it->get_result();
        while ($r = $res->fetch_assoc()) { $rows[] = $r; }
        if (!$rows) { jsonResponse(false, 'No items', null, 422); }

        $conn->begin_transaction();
        try {
            $receivedAny = false;
            foreach ($rows as $row) {
                $itemId = (int)$row['id'];
                $specId = (int)$row['product_spec_id'];
                $qty = (int)$row['quantity'];
                $received = (int)($row['received_quantity'] ?? 0);
                $remaining = $qty - $received;
                if ($remaining <= 0) { continue; }
                $receivedAny = true;
                $unitCost = (float)($row['unit_cost'] ?? 0);

                $upd = $conn->prepare('UPDATE product_specifications SET stock = stock + ?, purchase_price = ? WHERE id = ?');
                $upd->bind_param('idi', $remaining, $unitCost, $specId);
                $upd->execute();

                $mv = $conn->prepare('INSERT INTO stock_movements (product_spec_id, type, quantity, description) VALUES (?, ?, ?, ?)');
                $type = 'purchase';
                $desc = 'purchase_order_id=' . $poId . '; item_id=' . $itemId;
                $mv->bind_param('isis', $specId, $type, $remaining, $desc);
                $mv->execute();

                $upi = $conn->prepare('UPDATE purchase_order_items SET received_quantity = quantity WHERE id = ?');
                $upi->bind_param('i', $itemId);
                $upi->execute();
            }
            if (!$receivedAny) {
                $conn->rollback();
                jsonResponse(false, 'Nothing to receive', null, 409);
            }
            $set = $conn->prepare("UPDATE purchase_orders SET status = 'received' WHERE id = ?");
            $set->bind_param('i', $poId);
            $set->execute();
            $conn->commit();
            jsonResponse(true, 'Received', ['received' => true]);
        } catch (Throwable $e) {
            $conn->rollback();
            jsonResponse(false, 'Receive failed', ['detail' => $e->getMessage()], 400);
        }
    }

    private function recomputeTotalCost(int $purchaseOrderId): float
    {
        global $conn;
        $sum = $conn->prepare('SELECT COALESCE(SUM(quantity * unit_cost),0) AS total FROM purchase_order_items WHERE purchase_order_id = ?');
        $sum->bind_param('i', $purchaseOrderId);
        $sum->execute();
        $r = $sum->get_result()->fetch_assoc();
        $total = isset($r['total']) ? (float)$r['total'] : 0.0;
        $up = $conn->prepare('UPDATE purchase_orders SET total_cost = ? WHERE id = ?');
        $up->bind_param('di', $total, $purchaseOrderId);
        $up->execute();
        return $total;
    }

    public function update($id): void
    {
        AuthMiddleware::requireAuth();
        global $conn;
        $this->ensureTables();
        $poId = (int)$id;
        $st = $conn->prepare('SELECT id, status FROM purchase_orders WHERE id = ?');
        $st->bind_param('i', $poId);
        $st->execute();
        $po = $st->get_result()->fetch_assoc();
        if (!$po) { jsonResponse(false, 'Not Found', null, 404); }
        $current = strtolower((string)($po['status'] ?? ''));
        if (in_array($current, ['received', 'cancelled'], true)) {
            jsonResponse(false, 'Cannot update in this status', null, 409);
        }
        $d = getJsonInput();
        $fields = [];
        $types = '';
        $params = [];
        if (array_key_exists('supplier_name', $d)) {
            $supplier = sanitize((string)$d['supplier_name']);
            if ($supplier === '') { jsonResponse(false, 'supplier_name required', null, 422); }
            $fields[] = 'supplier_name = ?';
            $types .= 's';
            $params[] = $supplier;
        }
        if (array_key_exists('note', $d)) {
            $note = $d['note'] === null ? null : sanitize((string)$d['note']);
            $fields[] = 'note = ?';
            $types .= 's';
            $params[] = $note ?? '';
        }
        if (array_key_exists('status', $d)) {
            $status = strtolower(sanitize((string)$d['status']));
            if (!in_array($status, ['draft','ordered','partial','cancelled'], true)) {
                jsonResponse(false, 'Invalid status', null, 422);
            }
            $fields[] = 'status = ?';
            $types .= 's';
            $params[] = $status;
        }
        if (!$fields) {
            jsonResponse(true, 'OK', ['updated' => 0]);
        }
        $sql = 'UPDATE purchase_orders SET ' . implode(', ', $fields) . ' WHERE id = ?';
        $types .= 'i';
        $params[] = $poId;
        $up = $conn->prepare($sql);
        $up->bind_param($types, ...$params);
        if (!$up->execute()) { jsonResponse(false, 'Update failed', null, 400); }
        jsonResponse(true, 'OK', ['updated' => (int)$up->affected_rows]);
    }

    public function addItem($id): void
    {
        AuthMiddleware::requireAuth();
        global $conn;
        $this->ensureTables();
        $poId = (int)$id;
        $st = $conn->prepare('SELECT id, status FROM purchase_orders WHERE id = ?');
        $st->bind_param('i', $poId);
        $st->execute();
        $po = $st->get_result()->fetch_assoc();
        if (!$po) { jsonResponse(false, 'Not Found', null, 404); }
        $status = strtolower((string)($po['status'] ?? ''));
        if (in_array($status, ['received','cancelled'], true)) {
            jsonResponse(false, 'Cannot add items in this status', null, 409);
        }
        $d = getJsonInput();
        $specId = (int)($d['product_spec_id'] ?? 0);
        $qty = max(1, (int)($d['quantity'] ?? 0));
        $unitCost = isset($d['unit_cost']) ? (float)$d['unit_cost'] : null;
        if ($specId <= 0) { jsonResponse(false, 'product_spec_id required', null, 422); }
        if ($qty <= 0) { jsonResponse(false, 'quantity required', null, 422); }
        if ($unitCost === null || $unitCost < 0) { jsonResponse(false, 'unit_cost required', null, 422); }

        $sp = $conn->prepare('SELECT product_id FROM product_specifications WHERE id = ?');
        $sp->bind_param('i', $specId);
        $sp->execute();
        $sr = $sp->get_result()->fetch_assoc();
        if (!$sr) { jsonResponse(false, 'spec not found', null, 404); }
        $productId = (int)$sr['product_id'];

        $conn->begin_transaction();
        try {
            $ex = $conn->prepare('SELECT id, quantity, received_quantity FROM purchase_order_items WHERE purchase_order_id = ? AND product_spec_id = ? LIMIT 1');
            $ex->bind_param('ii', $poId, $specId);
            $ex->execute();
            $er = $ex->get_result()->fetch_assoc();
            if ($er) {
                $itemId = (int)$er['id'];
                $newQty = (int)$er['quantity'] + $qty;
                $receivedQty = (int)($er['received_quantity'] ?? 0);
                if ($newQty < $receivedQty) { $newQty = $receivedQty; }
                $up = $conn->prepare('UPDATE purchase_order_items SET quantity = ?, unit_cost = ? WHERE id = ?');
                $up->bind_param('idi', $newQty, $unitCost, $itemId);
                $up->execute();
            } else {
                $ins = $conn->prepare('INSERT INTO purchase_order_items (purchase_order_id, product_id, product_spec_id, quantity, received_quantity, unit_cost) VALUES (?, ?, ?, ?, 0, ?)');
                $ins->bind_param('iiiid', $poId, $productId, $specId, $qty, $unitCost);
                $ins->execute();
                $itemId = (int)$conn->insert_id;
            }
            $total = $this->recomputeTotalCost($poId);
            $conn->commit();
            jsonResponse(true, 'Created', ['purchase_order_id' => $poId, 'item_id' => $itemId, 'total_cost' => $total], 201);
        } catch (Throwable $e) {
            $conn->rollback();
            jsonResponse(false, 'Create failed', ['detail' => $e->getMessage()], 400);
        }
    }

    public function updateItem($id): void
    {
        AuthMiddleware::requireAuth();
        global $conn;
        $this->ensureTables();
        $itemId = (int)$id;
        $st = $conn->prepare('SELECT poi.id, poi.purchase_order_id, poi.quantity, poi.received_quantity, po.status FROM purchase_order_items poi INNER JOIN purchase_orders po ON po.id = poi.purchase_order_id WHERE poi.id = ?');
        $st->bind_param('i', $itemId);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        if (!$row) { jsonResponse(false, 'Not Found', null, 404); }
        $status = strtolower((string)($row['status'] ?? ''));
        if (in_array($status, ['received','cancelled'], true)) {
            jsonResponse(false, 'Cannot update items in this status', null, 409);
        }
        $d = getJsonInput();
        $newQty = array_key_exists('quantity', $d) ? (int)$d['quantity'] : null;
        $newUnitCost = array_key_exists('unit_cost', $d) ? (float)$d['unit_cost'] : null;
        if ($newQty === null && $newUnitCost === null) {
            jsonResponse(true, 'OK', ['updated' => 0]);
        }
        $receivedQty = (int)($row['received_quantity'] ?? 0);
        if ($newQty !== null) {
            if ($newQty < $receivedQty) { jsonResponse(false, 'quantity below received', ['min' => $receivedQty], 422); }
            if ($newQty <= 0) { jsonResponse(false, 'Invalid quantity', null, 422); }
        }
        if ($newUnitCost !== null && $newUnitCost < 0) {
            jsonResponse(false, 'Invalid unit_cost', null, 422);
        }
        $qty = $newQty !== null ? $newQty : (int)$row['quantity'];
        $unitCost = $newUnitCost !== null ? $newUnitCost : null;
        $sql = 'UPDATE purchase_order_items SET quantity = ?' . ($newUnitCost !== null ? ', unit_cost = ?' : '') . ' WHERE id = ?';
        $up = $conn->prepare($sql);
        if ($newUnitCost !== null) {
            $up->bind_param('idi', $qty, $unitCost, $itemId);
        } else {
            $up->bind_param('ii', $qty, $itemId);
        }
        $conn->begin_transaction();
        try {
            $up->execute();
            $poId = (int)$row['purchase_order_id'];
            $total = $this->recomputeTotalCost($poId);
            $conn->commit();
            jsonResponse(true, 'OK', ['updated' => 1, 'total_cost' => $total]);
        } catch (Throwable $e) {
            $conn->rollback();
            jsonResponse(false, 'Update failed', ['detail' => $e->getMessage()], 400);
        }
    }

    public function deleteItem($id): void
    {
        AuthMiddleware::requireAuth();
        global $conn;
        $this->ensureTables();
        $itemId = (int)$id;
        $st = $conn->prepare('SELECT poi.id, poi.purchase_order_id, poi.received_quantity, po.status FROM purchase_order_items poi INNER JOIN purchase_orders po ON po.id = poi.purchase_order_id WHERE poi.id = ?');
        $st->bind_param('i', $itemId);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        if (!$row) { jsonResponse(false, 'Not Found', null, 404); }
        $status = strtolower((string)($row['status'] ?? ''));
        if (in_array($status, ['received','cancelled'], true)) {
            jsonResponse(false, 'Cannot delete items in this status', null, 409);
        }
        $receivedQty = (int)($row['received_quantity'] ?? 0);
        if ($receivedQty > 0) {
            jsonResponse(false, 'Cannot delete item with received quantity', null, 409);
        }
        $poId = (int)$row['purchase_order_id'];
        $conn->begin_transaction();
        try {
            $del = $conn->prepare('DELETE FROM purchase_order_items WHERE id = ?');
            $del->bind_param('i', $itemId);
            $del->execute();
            $total = $this->recomputeTotalCost($poId);
            $conn->commit();
            jsonResponse(true, 'Deleted', ['deleted' => true, 'total_cost' => $total]);
        } catch (Throwable $e) {
            $conn->rollback();
            jsonResponse(false, 'Delete failed', ['detail' => $e->getMessage()], 400);
        }
    }
}
