<?php
class ReportsController
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

    public function sales(): void
    {
        global $conn;
        $page = max(1, (int)($_GET['page'] ?? 1));
        $per = min(200, max(1, (int)($_GET['per_page'] ?? 50)));
        $offset = ($page - 1) * $per;
        $filters = [];
        $types = '';
        $params = [];
        if (isset($_GET['status'])) { $filters[] = 'order_status = ?'; $types .= 's'; $params[] = sanitize($_GET['status']); }
        if (isset($_GET['source'])) { $filters[] = 'order_source = ?'; $types .= 's'; $params[] = sanitize($_GET['source']); }
        if (isset($_GET['from'])) { $filters[] = 'DATE(order_date) >= ?'; $types .= 's'; $params[] = sanitize($_GET['from']); }
        if (isset($_GET['to'])) { $filters[] = 'DATE(order_date) <= ?'; $types .= 's'; $params[] = sanitize($_GET['to']); }
        $where = $filters ? ('WHERE ' . implode(' AND ', $filters)) : '';
        if ($this->hasColumn('orders', 'order_discount')) {
            $sql = "SELECT v.order_id, v.customer_name, v.phone_number, v.order_status, v.order_source, v.order_date, v.order_item_id, v.product_name, v.product_code, v.quantity, v.original_price, v.discount_amount, v.sale_price, v.purchase_cost, v.profit_per_unit, v.total_profit, v.total_sale_amount, v.promotion_used, COALESCE(o.order_discount,0) AS order_discount, ((SELECT COALESCE(SUM(v2.total_profit),0) FROM vw_sales_report v2 WHERE v2.order_id = v.order_id) - COALESCE(o.order_discount,0)) AS order_net_profit FROM vw_sales_report v LEFT JOIN orders o ON o.id = v.order_id $where ORDER BY v.order_date DESC, v.order_id DESC LIMIT ? OFFSET ?";
        } else {
            $sql = "SELECT order_id, customer_name, phone_number, order_status, order_source, order_date, order_item_id, product_name, product_code, quantity, original_price, discount_amount, sale_price, purchase_cost, profit_per_unit, total_profit, total_sale_amount, promotion_used, 0 AS order_discount, total_profit AS order_net_profit FROM vw_sales_report $where ORDER BY order_date DESC, order_id DESC LIMIT ? OFFSET ?";
        }
        $types .= 'ii';
        $params[] = (int)$per;
        $params[] = (int)$offset;
        $st = $conn->prepare($sql);
        if (!$st) {
            jsonResponse(false, 'Query prepare failed', null, 500);
            return;
        }
        $st->bind_param($types, ...$params);
        $st->execute();
        $res = $st->get_result();
        $rows = [];
        if ($res) {
            while ($r = $res->fetch_assoc()) { $rows[] = $r; }
        }
        jsonResponse(true, 'OK', ['data' => $rows, 'page' => $page, 'per_page' => $per]);
    }

    public function salesStats(): void
    {
        global $conn;
        $filters = ["order_status IN ('completed','shipped')"];
        $types = '';
        $params = [];
        if (isset($_GET['status'])) {
            $st = sanitize($_GET['status']);
            if ($st === 'completed' || $st === 'shipped') {
                $filters[] = 'order_status = ?';
                $types .= 's';
                $params[] = $st;
            }
        }
        if (isset($_GET['source'])) { $filters[] = 'order_source = ?'; $types .= 's'; $params[] = sanitize($_GET['source']); }
        if (isset($_GET['from'])) { $filters[] = 'DATE(order_date) >= ?'; $types .= 's'; $params[] = sanitize($_GET['from']); }
        if (isset($_GET['to'])) { $filters[] = 'DATE(order_date) <= ?'; $types .= 's'; $params[] = sanitize($_GET['to']); }
        $where = $filters ? ('WHERE ' . implode(' AND ', $filters)) : '';
        $sql = "SELECT order_source, COALESCE(SUM(total_profit),0) AS total FROM vw_sales_report $where GROUP BY order_source";
        $st = $conn->prepare($sql);
        if (!$st) { jsonResponse(false, 'Query prepare failed', null, 500); return; }
        if ($types !== '') {
            $st->bind_param($types, ...$params);
        }
        $st->execute();
        $res = $st->get_result();
        $website = 0.0;
        $whatsapp = 0.0;
        $instagram = 0.0;
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $src = strtolower($r['order_source'] ?? '');
                $val = (float)($r['total'] ?? 0);
                if ($src === 'website') $website += $val;
                else if ($src === 'whatsapp') $whatsapp += $val;
                else if ($src === 'instagram') $instagram += $val;
            }
        }
        $total = $website + $whatsapp + $instagram;
        if ($this->hasColumn('orders', 'order_discount')) {
            $oFilters = ["status IN ('completed','shipped')"];
            $oTypes = '';
            $oParams = [];
            if (isset($_GET['status'])) {
                $stt = sanitize($_GET['status']);
                if ($stt === 'completed' || $stt === 'shipped') {
                    $oFilters[] = 'status = ?';
                    $oTypes .= 's';
                    $oParams[] = $stt;
                }
            }
            if (isset($_GET['source'])) { $oFilters[] = 'order_source = ?'; $oTypes .= 's'; $oParams[] = sanitize($_GET['source']); }
            if (isset($_GET['from'])) { $oFilters[] = 'DATE(created_at) >= ?'; $oTypes .= 's'; $oParams[] = sanitize($_GET['from']); }
            if (isset($_GET['to'])) { $oFilters[] = 'DATE(created_at) <= ?'; $oTypes .= 's'; $oParams[] = sanitize($_GET['to']); }
            $oWhere = $oFilters ? ('WHERE ' . implode(' AND ', $oFilters)) : '';
            $q2 = "SELECT order_source, COALESCE(SUM(order_discount),0) AS total_discount FROM orders $oWhere GROUP BY order_source";
            $st2 = $conn->prepare($q2);
            if ($st2) {
                if ($oTypes !== '') { $st2->bind_param($oTypes, ...$oParams); }
                $st2->execute();
                $r2 = $st2->get_result();
                $dWebsite = 0.0;
                $dWhatsapp = 0.0;
                $dInstagram = 0.0;
                if ($r2) {
                    while ($r = $r2->fetch_assoc()) {
                        $src = strtolower($r['order_source'] ?? '');
                        $val = (float)($r['total_discount'] ?? 0);
                        if ($src === 'website') $dWebsite += $val;
                        else if ($src === 'whatsapp') $dWhatsapp += $val;
                        else if ($src === 'instagram') $dInstagram += $val;
                    }
                }
                $website -= $dWebsite;
                $whatsapp -= $dWhatsapp;
                $instagram -= $dInstagram;
                $total = $website + $whatsapp + $instagram;
            }
        }
        jsonResponse(true, 'OK', [
            'website_profit' => $website,
            'whatsapp_profit' => $whatsapp,
            'instagram_profit' => $instagram,
            'total_profit' => $total,
        ]);
    }
}
