<?php
class PromotionController
{
    public function index(): void
    {
        global $conn;
        $active = isset($_GET['active']) ? (int)$_GET['active'] : null;
        $from = isset($_GET['date_from']) ? sanitize($_GET['date_from']) : null;
        $to = isset($_GET['date_to']) ? sanitize($_GET['date_to']) : null;
        $where = [];
        $types = '';
        $params = [];
        if ($active !== null) { $where[] = 'is_active = ?'; $types .= 'i'; $params[] = $active; }
        if ($from !== null && $to !== null) {
            $where[] = '(start_date <= ? AND end_date >= ?)';
            $types .= 'ss';
            $params[] = $to;
            $params[] = $from;
        }
        $w = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        $sql = "SELECT id, name, description, discount_type, discount_value, start_date, end_date, is_active, priority FROM promotions $w ORDER BY priority DESC, id DESC";
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
        global $conn;
        $pid = (int)$id;
        $st = $conn->prepare('SELECT * FROM promotions WHERE id = ?');
        $st->bind_param('i', $pid);
        $st->execute();
        $p = $st->get_result()->fetch_assoc();
        if (!$p) { jsonResponse(false, 'Not Found', null, 404); return; }
        $items = $conn->prepare('SELECT 
                pi.id,
                pi.product_spec_id,
                ps.product_id,
                ps.sku_variant,
                ps.spec_key,
                ps.spec_value,
                ps.price,
                ps.stock,
                ps.weight,
                ps.color_id,
                ps.size_id,
                ps.is_active,
                p.name AS product_name,
                b.name AS brand_name,
                cat.name AS category_name,
                sub.name AS subcategory_name,
                col.name AS color_name,
                siz.name AS size_name
            FROM promotion_items pi
            INNER JOIN product_specifications ps ON ps.id = pi.product_spec_id
            LEFT JOIN products p ON p.id = ps.product_id
            LEFT JOIN brands b ON b.id = p.brand_id
            LEFT JOIN categories cat ON cat.id = p.category_id
            LEFT JOIN subcategories sub ON sub.id = p.subcategory_id
            LEFT JOIN colors col ON col.id = ps.color_id
            LEFT JOIN sizes siz ON siz.id = ps.size_id
            WHERE pi.promotion_id = ?');
        $items->bind_param('i', $pid);
        $items->execute();
        $list = [];
        $ri = $items->get_result();
        while ($r = $ri->fetch_assoc()) { $list[] = $r; }
        jsonResponse(true, 'OK', ['promotion' => $p, 'items' => $list]);
    }

    public function store(): void
    {
        global $conn;
        $d = getJsonInput();
        foreach (['name','discount_type','discount_value','start_date','end_date'] as $k) {
            if (!isset($d[$k])) { jsonResponse(false, 'missing field ' . $k, null, 422); return; }
        }
        $name = sanitize($d['name']);
        $desc = isset($d['description']) ? sanitize($d['description']) : null;
        $type = sanitize($d['discount_type']);
        $value = (float)$d['discount_value'];
        $start = sanitize($d['start_date']);
        $end = sanitize($d['end_date']);
        $active = (int)($d['is_active'] ?? 1);
        $priority = (int)($d['priority'] ?? 0);
        if (!in_array($type, ['percentage','fixed'], true)) { jsonResponse(false, 'invalid discount_type', null, 422); return; }
        if ($value <= 0) { jsonResponse(false, 'invalid discount_value', null, 422); return; }
        $stmt = $conn->prepare('INSERT INTO promotions (name, description, discount_type, discount_value, start_date, end_date, is_active, priority) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('sssdssii', $name, $desc, $type, $value, $start, $end, $active, $priority);
        if (!$stmt->execute()) {
            jsonResponse(false, 'Error', null, 500);
            return;
        }
        jsonResponse(true, 'Created', ['id' => (int)$conn->insert_id], 201);
    }

    public function update($id): void
    {
        global $conn;
        $pid = (int)$id;
        $chk = $conn->prepare('SELECT id FROM promotions WHERE id = ?');
        $chk->bind_param('i', $pid);
        $chk->execute();
        if (!$chk->get_result()->fetch_assoc()) { jsonResponse(false, 'Not Found', null, 404); return; }
        $d = getJsonInput();
        $fields = [];
        $types = '';
        $params = [];
        $map = ['name'=>'s','description'=>'s','discount_type'=>'s','discount_value'=>'d','start_date'=>'s','end_date'=>'s','is_active'=>'i','priority'=>'i'];
        foreach ($map as $key=>$t) {
            if (array_key_exists($key, $d)) {
                $fields[] = "$key = ?";
                $types .= $t;
                if ($t === 's') { $params[] = sanitize((string)$d[$key]); }
                elseif ($t === 'i') { $params[] = (int)$d[$key]; }
                else { $params[] = (float)$d[$key]; }
            }
        }
        if (!$fields) { jsonResponse(true, 'No changes', ['updated'=>false]); return; }
        $sql = 'UPDATE promotions SET ' . implode(', ', $fields) . ' WHERE id = ?';
        $types .= 'i';
        $params[] = $pid;
        $st = $conn->prepare($sql);
        $st->bind_param($types, ...$params);
        $st->execute();
        jsonResponse(true, 'Updated', ['updated'=>true]);
    }

    public function destroy($id): void
    {
        global $conn;
        $pid = (int)$id;
        $st = $conn->prepare('DELETE FROM promotions WHERE id = ?');
        $st->bind_param('i', $pid);
        $st->execute();
        jsonResponse(true, 'Deleted', ['deleted'=>true]);
    }

    public function addItem($id): void
    {
        global $conn;
        $pid = (int)$id;
        $d = getJsonInput();
        $spec = (int)($d['product_spec_id'] ?? 0);
        if ($spec <= 0) { jsonResponse(false, 'product_spec_id required', null, 422); return; }
        $st = $conn->prepare('INSERT INTO promotion_items (promotion_id, product_spec_id) VALUES (?, ?)');
        $st->bind_param('ii', $pid, $spec);
        try {
            $st->execute();
        } catch (\mysqli_sql_exception $ex) {
            if ((int)$ex->getCode() === 1062) { jsonResponse(false, 'Already added', null, 409); return; }
            jsonResponse(false, 'Error', null, 500); return;
        }
        jsonResponse(true, 'Created', ['id' => (int)$conn->insert_id], 201);
    }

    public function removeItem($id, $specId): void
    {
        global $conn;
        $pid = (int)$id;
        $sid = (int)$specId;
        $st = $conn->prepare('DELETE FROM promotion_items WHERE promotion_id = ? AND product_spec_id = ?');
        $st->bind_param('ii', $pid, $sid);
        $st->execute();
        jsonResponse(true, 'Deleted', ['deleted'=>true]);
    }
}
