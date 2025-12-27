<?php
class StockController
{
    public function list(): void
    {
        global $conn;
        $specId = isset($_GET['product_spec_id']) ? (int)$_GET['product_spec_id'] : 0;
        $where = $specId > 0 ? 'WHERE sm.product_spec_id = ?' : '';
        $sql = "SELECT sm.id, sm.product_spec_id, sm.type, sm.quantity, sm.order_item_id, sm.description, sm.created_at FROM stock_movements sm $where ORDER BY sm.id DESC LIMIT 200";
        $st = $conn->prepare($sql);
        if ($specId > 0) {
            $st->bind_param('i', $specId);
        }
        $st->execute();
        $res = $st->get_result();
        $rows = [];
        while ($r = $res->fetch_assoc()) { $rows[] = $r; }
        jsonResponse(true, 'OK', ['data' => $rows]);
    }

    public function adjust(): void
    {
        global $conn;
        $d = getJsonInput();
        $specId = (int)($d['product_spec_id'] ?? 0);
        $qty = (int)($d['quantity'] ?? 0);
        $desc = isset($d['description']) ? sanitize($d['description']) : 'adjustment';
        if ($specId <= 0 || $qty === 0) { jsonResponse(false, 'product_spec_id and quantity required', null, 422); return; }
        $conn->begin_transaction();
        try {
            $ins = $conn->prepare('INSERT INTO stock_movements (product_spec_id, type, quantity, description) VALUES (?, ?, ?, ?)');
            $type = 'adjustment';
            $ins->bind_param('isis', $specId, $type, $qty, $desc);
            $ins->execute();
            $upd = $conn->prepare('UPDATE product_specifications SET stock = stock + ? WHERE id = ?');
            $upd->bind_param('ii', $qty, $specId);
            $upd->execute();
            $conn->commit();
            jsonResponse(true, 'Adjusted', ['adjusted' => true]);
        } catch (Throwable $e) {
            $conn->rollback();
            jsonResponse(false, 'Adjust failed', null, 400);
        }
    }
}
