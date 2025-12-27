<?php
class ReportsController
{
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
        $sql = "SELECT order_id, customer_name, phone_number, order_status, order_source, order_date, order_item_id, product_name, product_code, quantity, original_price, discount_amount, sale_price, purchase_cost, profit_per_unit, total_profit, total_sale_amount, promotion_used FROM vw_sales_report $where ORDER BY order_date DESC, order_id DESC LIMIT ? OFFSET ?";
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
        jsonResponse(true, 'OK', [
            'website_profit' => $website,
            'whatsapp_profit' => $whatsapp,
            'instagram_profit' => $instagram,
            'total_profit' => $total,
        ]);
    }
}
