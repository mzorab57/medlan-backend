<?php
class DashboardController
{
    public function summary(): void
    {
        $user = AuthMiddleware::requireAuth();
        global $conn;
        $counts = [];
        $q1 = $conn->query('SELECT COUNT(*) AS c FROM products');
        $counts['products'] = $q1 ? (int)($q1->fetch_assoc()['c'] ?? 0) : 0;
        $q2 = $conn->query('SELECT COUNT(*) AS c FROM categories');
        $counts['categories'] = $q2 ? (int)($q2->fetch_assoc()['c'] ?? 0) : 0;
        $q3 = $conn->query('SELECT COUNT(*) AS c FROM orders');
        $counts['orders'] = $q3 ? (int)($q3->fetch_assoc()['c'] ?? 0) : 0;
        $q4 = $conn->query("SELECT COUNT(*) AS c FROM orders WHERE status = 'pending'");
        $counts['orders_pending'] = $q4 ? (int)($q4->fetch_assoc()['c'] ?? 0) : 0;
        $q5 = $conn->query("SELECT COUNT(*) AS c FROM orders WHERE status = 'completed'");
        $counts['orders_completed'] = $q5 ? (int)($q5->fetch_assoc()['c'] ?? 0) : 0;
        $q7 = $conn->query('SELECT COUNT(*) AS c FROM brands');
        $counts['brands'] = $q7 ? (int)($q7->fetch_assoc()['c'] ?? 0) : 0;
        $q8 = $conn->query('SELECT COUNT(*) AS c FROM users');
        $counts['users'] = $q8 ? (int)($q8->fetch_assoc()['c'] ?? 0) : 0;
        $q9 = $conn->query("SELECT COUNT(*) AS c FROM promotions WHERE is_active = 1 AND CURDATE() BETWEEN start_date AND end_date");
        $counts['promotions_active'] = $q9 ? (int)($q9->fetch_assoc()['c'] ?? 0) : 0;
        $q10 = $conn->query("SELECT COUNT(*) AS c FROM orders WHERE status = 'shipped'");
        $counts['orders_shipped'] = $q10 ? (int)($q10->fetch_assoc()['c'] ?? 0) : 0;
        $q11 = $conn->query("SELECT COUNT(*) AS c FROM orders WHERE status = 'cancelled'");
        $counts['orders_cancelled'] = $q11 ? (int)($q11->fetch_assoc()['c'] ?? 0) : 0;
        $q12 = $conn->query("SELECT COUNT(*) AS c FROM orders WHERE status = 'returned'");
        $counts['orders_returned'] = $q12 ? (int)($q12->fetch_assoc()['c'] ?? 0) : 0;
        if (($user['role'] ?? '') === 'admin') {
            $q6 = $conn->query("SELECT COALESCE(SUM(price * quantity),0) AS r FROM order_items oi INNER JOIN orders o ON o.id = oi.order_id WHERE o.status = 'completed' AND DATE(o.created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
            $counts['revenue_30d'] = $q6 ? (float)($q6->fetch_assoc()['r'] ?? 0) : 0.0;
            $q6a = $conn->query("SELECT COALESCE(SUM(price * quantity),0) AS r FROM order_items oi INNER JOIN orders o ON o.id = oi.order_id WHERE o.status = 'completed' AND DATE(o.created_at) = CURDATE()");
            $counts['revenue_today'] = $q6a ? (float)($q6a->fetch_assoc()['r'] ?? 0) : 0.0;
            $q6b = $conn->query("SELECT COALESCE(SUM(price * quantity),0) AS r FROM order_items oi INNER JOIN orders o ON o.id = oi.order_id WHERE o.status = 'completed' AND DATE(o.created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)");
            $counts['revenue_7d'] = $q6b ? (float)($q6b->fetch_assoc()['r'] ?? 0) : 0.0;

            $q13 = $conn->query("SELECT COALESCE(AVG(total_price),0) AS a FROM orders WHERE status = 'completed' AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
            $counts['aov_30d'] = $q13 ? (float)($q13->fetch_assoc()['a'] ?? 0) : 0.0;
            $e1 = $conn->query("SELECT COALESCE(SUM(amount),0) AS t FROM expenses WHERE DATE(created_at) = CURDATE()");
            $counts['expenses_today'] = $e1 ? (float)($e1->fetch_assoc()['t'] ?? 0) : 0.0;
            $e7 = $conn->query("SELECT COALESCE(SUM(amount),0) AS t FROM expenses WHERE DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)");
            $counts['expenses_7d'] = $e7 ? (float)($e7->fetch_assoc()['t'] ?? 0) : 0.0;
            $e30 = $conn->query("SELECT COALESCE(SUM(amount),0) AS t FROM expenses WHERE DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
            $counts['expenses_30d'] = $e30 ? (float)($e30->fetch_assoc()['t'] ?? 0) : 0.0;
            $p1 = $conn->query("SELECT COALESCE(SUM(total_profit),0) AS p FROM vw_sales_report WHERE order_status = 'completed' AND DATE(order_date) = CURDATE()");
            $p7 = $conn->query("SELECT COALESCE(SUM(total_profit),0) AS p FROM vw_sales_report WHERE order_status = 'completed' AND DATE(order_date) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)");
            $p30 = $conn->query("SELECT COALESCE(SUM(total_profit),0) AS p FROM vw_sales_report WHERE order_status = 'completed' AND DATE(order_date) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
            $net1 = ($p1 ? (float)($p1->fetch_assoc()['p'] ?? 0) : 0.0) - $counts['expenses_today'];
            $net7 = ($p7 ? (float)($p7->fetch_assoc()['p'] ?? 0) : 0.0) - $counts['expenses_7d'];
            $net30 = ($p30 ? (float)($p30->fetch_assoc()['p'] ?? 0) : 0.0) - $counts['expenses_30d'];
            $counts['net_profit_today'] = $net1;
            $counts['net_profit_7d'] = $net7;
            $counts['net_profit_30d'] = $net30;
        }

        $inv = $conn->query("SELECT
            COALESCE(COUNT(DISTINCT ps.product_id),0) AS products,
            COALESCE(SUM(ps.stock),0) AS units" . (($user['role'] ?? '') === 'admin' ? ",
            COALESCE(SUM(ps.stock * COALESCE(ps.purchase_price, p.purchase_price, 0)),0) AS value_cost,
            COALESCE(SUM(ps.stock * COALESCE(CASE WHEN pr.coupon_code IS NULL THEN v.final_price ELSE NULL END, ps.price, p.base_price, 0)),0) AS value_sale" : "") . "
            FROM product_specifications ps
            INNER JOIN products p ON p.id = ps.product_id
            LEFT JOIN vw_product_prices v ON v.spec_id = ps.id
            LEFT JOIN promotions pr ON pr.id = v.promotion_id
            WHERE ps.stock > 0 AND ps.is_active = 1 AND p.is_active = 1");
        if ($inv) {
            $r = $inv->fetch_assoc();
            $counts['stock_products'] = (int)($r['products'] ?? 0);
            $counts['stock_units'] = (int)($r['units'] ?? 0);
            if (($user['role'] ?? '') === 'admin') {
                $counts['stock_value_cost'] = (float)($r['value_cost'] ?? 0);
                $counts['stock_value_sale'] = (float)($r['value_sale'] ?? 0);
            }
        } else {
            $counts['stock_products'] = 0;
            $counts['stock_units'] = 0;
            if (($user['role'] ?? '') === 'admin') {
                $counts['stock_value_cost'] = 0.0;
                $counts['stock_value_sale'] = 0.0;
            }
        }
        jsonResponse(true, 'OK', ['summary' => $counts]);
    }

    public function topProducts(): void
    {
        global $conn;
        $sql = "SELECT product_name, SUM(quantity) AS total_sold FROM vw_sales_report WHERE order_status = 'completed' GROUP BY product_name ORDER BY total_sold DESC LIMIT 10";
        $res = $conn->query($sql);
        $rows = [];
        if ($res) {
            while ($r = $res->fetch_assoc()) { $rows[] = $r; }
        }
        jsonResponse(true, 'OK', ['data' => $rows]);
    }

    public function revenue(): void
    {
        global $conn;
        $from = isset($_GET['from']) ? sanitize($_GET['from']) : null;
        $to = isset($_GET['to']) ? sanitize($_GET['to']) : null;
        if (!$from || !$to) { jsonResponse(false, 'from and to required', null, 422); return; }
        $st = $conn->prepare("SELECT DATE(o.created_at) AS day, SUM(oi.price * oi.quantity) AS amount FROM order_items oi INNER JOIN orders o ON o.id = oi.order_id WHERE o.status = 'completed' AND DATE(o.created_at) BETWEEN ? AND ? GROUP BY DATE(o.created_at) ORDER BY DATE(o.created_at)");
        if ($st) {
            $st->bind_param('ss', $from, $to);
            $st->execute();
            $rows = [];
            $res = $st->get_result();
            if ($res) {
                while ($r = $res->fetch_assoc()) { $rows[] = $r; }
            }
        } else {
            $rows = [];
        }
        jsonResponse(true, 'OK', ['data' => $rows]);
    }

    public function totalProfit(): void
    {
        global $conn;
        $sql = "SELECT COALESCE(SUM(total_profit),0) AS total FROM vw_sales_report WHERE order_status IN ('completed','shipped')";
        $res = $conn->query($sql);
        $total = 0.0;
        if ($res) {
            $row = $res->fetch_assoc();
            $total = isset($row['total']) ? (float)$row['total'] : 0.0;
        }
        jsonResponse(true, 'OK', ['total_profit' => $total]);
    }
}
