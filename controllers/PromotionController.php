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
        $sql = "SELECT id, name, coupon_code, usage_limit, used_count, min_order_amount, display_limit, extra_pool_limit, description, discount_type, discount_value, start_date, end_date, is_active, priority FROM promotions $w ORDER BY priority DESC, id DESC";
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
                pi.override_price,
                ps.product_id,
                ps.sku_variant,
                ps.spec_key,
                ps.spec_value,
                ps.price,
                ps.stock,
                ps.gender,
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
            WHERE pi.promotion_id = ?
            ORDER BY pi.id ASC');
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
        foreach (['name','discount_type','start_date','end_date'] as $k) {
            if (!isset($d[$k])) { jsonResponse(false, 'missing field ' . $k, null, 422); return; }
        }
        $name = sanitize($d['name']);
        $coupon = isset($d['coupon_code']) ? strtoupper(preg_replace('/\s+/', '', sanitize((string)$d['coupon_code']))) : null;
        if ($coupon === '') { $coupon = null; }
        $desc = isset($d['description']) ? sanitize($d['description']) : null;
        $type = sanitize($d['discount_type']);
        $value = isset($d['discount_value']) ? (float)$d['discount_value'] : 0.0;
        $usageLimit = array_key_exists('usage_limit', $d) ? ($d['usage_limit'] === null || $d['usage_limit'] === '' ? null : (int)$d['usage_limit']) : null;
        if ($usageLimit !== null && $usageLimit < 0) { jsonResponse(false, 'invalid usage_limit', null, 422); return; }
        $minOrder = array_key_exists('min_order_amount', $d) ? (float)$d['min_order_amount'] : 0.0;
        if ($minOrder < 0) { jsonResponse(false, 'invalid min_order_amount', null, 422); return; }
        $displayLimit = array_key_exists('display_limit', $d) ? ($d['display_limit'] === null || $d['display_limit'] === '' ? null : (int)$d['display_limit']) : null;
        $extraPool = array_key_exists('extra_pool_limit', $d) ? ($d['extra_pool_limit'] === null || $d['extra_pool_limit'] === '' ? null : (int)$d['extra_pool_limit']) : null;
        if ($displayLimit !== null && $displayLimit < 0) { jsonResponse(false, 'invalid display_limit', null, 422); return; }
        if ($extraPool !== null && $extraPool < 0) { jsonResponse(false, 'invalid extra_pool_limit', null, 422); return; }
        $start = sanitize($d['start_date']);
        $end = sanitize($d['end_date']);
        $active = (int)($d['is_active'] ?? 1);
        $priority = (int)($d['priority'] ?? 0);
        if (!in_array($type, ['percentage','fixed','campaign'], true)) { jsonResponse(false, 'invalid discount_type', null, 422); return; }
        if ($coupon !== null && $type === 'campaign') { jsonResponse(false, 'coupon_code not allowed for campaign', null, 422); return; }
        if ($type === 'campaign') { $value = 0.0; }
        if ($type !== 'campaign' && $value <= 0) { jsonResponse(false, 'invalid discount_value', null, 422); return; }
        $used = 0;
        $stmt = $conn->prepare('INSERT INTO promotions (name, coupon_code, description, discount_type, discount_value, usage_limit, used_count, min_order_amount, display_limit, extra_pool_limit, start_date, end_date, is_active, priority) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('ssssdiidiissii', $name, $coupon, $desc, $type, $value, $usageLimit, $used, $minOrder, $displayLimit, $extraPool, $start, $end, $active, $priority);
        try {
            $stmt->execute();
        } catch (\mysqli_sql_exception $e) {
            if ((int)$e->getCode() === 1062) { jsonResponse(false, 'coupon_code already exists', null, 409); return; }
            jsonResponse(false, 'Error', ['detail' => $e->getMessage()], 500);
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
        if (array_key_exists('discount_type', $d)) {
            $dt = sanitize((string)$d['discount_type']);
            if (!in_array($dt, ['percentage','fixed','campaign'], true)) { jsonResponse(false, 'invalid discount_type', null, 422); return; }
            if ($dt === 'campaign') {
                $d['discount_value'] = 0.0;
                if (!array_key_exists('coupon_code', $d)) { $d['coupon_code'] = null; }
            }
        }
        $fields = [];
        $types = '';
        $params = [];
        $map = ['name'=>'s','coupon_code'=>'s','description'=>'s','discount_type'=>'s','discount_value'=>'d','usage_limit'=>'i','min_order_amount'=>'d','display_limit'=>'i','extra_pool_limit'=>'i','start_date'=>'s','end_date'=>'s','is_active'=>'i','priority'=>'i'];
        foreach ($map as $key=>$t) {
            if (array_key_exists($key, $d)) {
                $fields[] = "$key = ?";
                $types .= $t;
                if ($key === 'coupon_code') {
                    $coupon = $d[$key] === null ? null : strtoupper(preg_replace('/\s+/', '', sanitize((string)$d[$key])));
                    if ($coupon === '') { $coupon = null; }
                    $params[] = $coupon;
                } elseif ($key === 'usage_limit') {
                    $params[] = ($d[$key] === null || $d[$key] === '') ? null : (int)$d[$key];
                } elseif ($t === 's') { $params[] = sanitize((string)$d[$key]); }
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
        try {
            $st->execute();
        } catch (\mysqli_sql_exception $e) {
            if ((int)$e->getCode() === 1062) { jsonResponse(false, 'coupon_code already exists', null, 409); return; }
            jsonResponse(false, 'Error', ['detail' => $e->getMessage()], 500);
            return;
        }
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
        $promoRow = $conn->prepare('SELECT discount_type, display_limit, extra_pool_limit FROM promotions WHERE id = ?');
        $promoRow->bind_param('i', $pid);
        $promoRow->execute();
        $promo = $promoRow->get_result()->fetch_assoc();
        if (!$promo) { jsonResponse(false, 'Not Found', null, 404); return; }
        $d = getJsonInput();
        $spec = (int)($d['product_spec_id'] ?? 0);
        if ($spec <= 0) { jsonResponse(false, 'product_spec_id required', null, 422); return; }
        $override = array_key_exists('override_price', $d) ? $d['override_price'] : null;
        $override = ($override === null || $override === '') ? null : (float)$override;
        if ($override !== null && $override <= 0) { jsonResponse(false, 'invalid override_price', null, 422); return; }
        $discountType = (string)($promo['discount_type'] ?? '');
        if ($discountType === 'campaign') {
            if ($override === null) { jsonResponse(false, 'override_price required for campaign', null, 422); return; }
            $displayLimit = $promo['display_limit'] !== null ? (int)$promo['display_limit'] : null;
            $extraPool = $promo['extra_pool_limit'] !== null ? (int)$promo['extra_pool_limit'] : 0;
            if ($displayLimit !== null && $displayLimit > 0) {
                $max = $displayLimit + max(0, $extraPool);
                $cntSt = $conn->prepare('SELECT COUNT(*) AS c FROM promotion_items WHERE promotion_id = ?');
                $cntSt->bind_param('i', $pid);
                $cntSt->execute();
                $cnt = (int)($cntSt->get_result()->fetch_assoc()['c'] ?? 0);
                if ($cnt >= $max) { jsonResponse(false, 'Campaign items limit reached', ['max' => $max], 409); return; }
            }
        } else {
            $override = null;
        }
        if ($override === null) {
            $st = $conn->prepare('INSERT INTO promotion_items (promotion_id, product_spec_id) VALUES (?, ?)');
            $st->bind_param('ii', $pid, $spec);
        } else {
            $st = $conn->prepare('INSERT INTO promotion_items (promotion_id, product_spec_id, override_price) VALUES (?, ?, ?)');
            $st->bind_param('iid', $pid, $spec, $override);
        }
        try {
            $st->execute();
        } catch (\mysqli_sql_exception $ex) {
            if ((int)$ex->getCode() === 1062) { jsonResponse(false, 'Already added', null, 409); return; }
            jsonResponse(false, 'Error', null, 500); return;
        }
        jsonResponse(true, 'Created', ['id' => (int)$conn->insert_id], 201);
    }

    public function updateItem($id, $specId): void
    {
        global $conn;
        $pid = (int)$id;
        $sid = (int)$specId;
        $promoRow = $conn->prepare('SELECT discount_type FROM promotions WHERE id = ?');
        $promoRow->bind_param('i', $pid);
        $promoRow->execute();
        $promo = $promoRow->get_result()->fetch_assoc();
        if (!$promo) { jsonResponse(false, 'Not Found', null, 404); return; }
        $d = getJsonInput();
        if (!array_key_exists('override_price', $d)) { jsonResponse(false, 'override_price required', null, 422); return; }
        $override = $d['override_price'];
        $override = ($override === null || $override === '') ? null : (float)$override;
        if ($override !== null && $override <= 0) { jsonResponse(false, 'invalid override_price', null, 422); return; }
        $discountType = (string)($promo['discount_type'] ?? '');
        if ($discountType === 'campaign' && $override === null) { jsonResponse(false, 'override_price required for campaign', null, 422); return; }
        if ($discountType !== 'campaign') { $override = null; }
        if ($override === null) {
            $st = $conn->prepare('UPDATE promotion_items SET override_price = NULL WHERE promotion_id = ? AND product_spec_id = ?');
            $st->bind_param('ii', $pid, $sid);
        } else {
            $st = $conn->prepare('UPDATE promotion_items SET override_price = ? WHERE promotion_id = ? AND product_spec_id = ?');
            $st->bind_param('dii', $override, $pid, $sid);
        }
        $st->execute();
        jsonResponse(true, 'Updated', ['updated' => true]);
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
