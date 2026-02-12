<?php
class OrderController
{
    private function getDeliveryThreshold(): float
    {
        return 35000.0;
    }

    private function findCityIdFromAddress(string $address): ?int
    {
        global $conn;
        $a = strtolower($address);
        $st = $conn->prepare('SELECT id, city_key, name FROM delivery_cities WHERE is_active = 1');
        $st->execute();
        $res = $st->get_result();
        while ($r = $res->fetch_assoc()) {
            $key = strtolower($r['city_key'] ?? '');
            $name = strtolower($r['name'] ?? '');
            if ($key && strpos($a, $key) !== false) { return (int)$r['id']; }
            if ($name && strpos($a, $name) !== false) { return (int)$r['id']; }
        }
        return null;
    }

    private function ensureDeliveryExpense(int $orderId): void
    {
        global $conn;
        $st = $conn->prepare('SELECT delivery_paid_by, delivery_fee, delivery_city_id FROM orders WHERE id = ?');
        $st->bind_param('i', $orderId);
        $st->execute();
        $o = $st->get_result()->fetch_assoc();
        if (!$o) { return; }
        if (($o['delivery_paid_by'] ?? '') !== 'medlan') { return; }
        $fee = isset($o['delivery_fee']) ? (float)$o['delivery_fee'] : 0.0;
        if ($fee <= 0) { return; }
        $exists = $conn->prepare("SELECT id FROM expenses WHERE category = 'delivery' AND order_id = ? LIMIT 1");
        $exists->bind_param('i', $orderId);
        $exists->execute();
        if ($exists->get_result()->fetch_assoc()) { return; }
        $title = 'Delivery (Order #' . $orderId . ')';
        $cat = 'delivery';
        $note = 'order_id=' . $orderId . '; city_id=' . ($o['delivery_city_id'] ?? '');
        $ins = $conn->prepare('INSERT INTO expenses (order_id, title, amount, category, note) VALUES (?, ?, ?, ?, ?)');
        $ins->bind_param('isdss', $orderId, $title, $fee, $cat, $note);
        $ins->execute();
    }

    private function deleteDeliveryExpense(int $orderId): void
    {
        global $conn;
        $st = $conn->prepare("DELETE FROM expenses WHERE category = 'delivery' AND order_id = ?");
        $st->bind_param('i', $orderId);
        $st->execute();
    }

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

    private function hasTable(string $table): bool
    {
        global $conn;
        $t = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        if ($t === '') { return false; }
        $rs = $conn->query("SHOW TABLES LIKE '$t'");
        if (!$rs) { return false; }
        return (bool)$rs->fetch_assoc();
    }

    private function ensureDiscountExpense(int $orderId, float $amount): void
    {
        global $conn;
        if (!$this->hasColumn('expenses', 'order_id')) { return; }
        $title = 'Discount (Order #' . $orderId . ')';
        $cat = 'discount';
        $note = 'order_id=' . $orderId;
        $exists = $conn->prepare("SELECT id FROM expenses WHERE category = 'discount' AND order_id = ? LIMIT 1");
        $exists->bind_param('i', $orderId);
        $exists->execute();
        $row = $exists->get_result()->fetch_assoc();
        if ($row && isset($row['id'])) {
            $eid = (int)$row['id'];
            $up = $conn->prepare('UPDATE expenses SET title = ?, amount = ?, category = ?, note = ? WHERE id = ?');
            $up->bind_param('sdssi', $title, $amount, $cat, $note, $eid);
            $up->execute();
            return;
        }
        $ins = $conn->prepare('INSERT INTO expenses (order_id, title, amount, category, note) VALUES (?, ?, ?, ?, ?)');
        $ins->bind_param('isdss', $orderId, $title, $amount, $cat, $note);
        $ins->execute();
    }

    private function deleteDiscountExpense(int $orderId): void
    {
        global $conn;
        if (!$this->hasColumn('expenses', 'order_id')) { return; }
        $st = $conn->prepare("DELETE FROM expenses WHERE category = 'discount' AND order_id = ?");
        $st->bind_param('i', $orderId);
        $st->execute();
    }

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
            $createdBy = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
            $campaignBundleId = isset($d['campaign_id']) ? (int)$d['campaign_id'] : null;
            if ($campaignBundleId !== null && $campaignBundleId <= 0) { $campaignBundleId = null; }

            if ($campaignBundleId !== null) {
                $cst = $conn->prepare("SELECT id, display_limit, extra_pool_limit
                    FROM promotions
                    WHERE id = ?
                      AND discount_type = 'campaign'
                      AND is_active = 1
                      AND CURDATE() BETWEEN start_date AND end_date
                    LIMIT 1");
                $cst->bind_param('i', $campaignBundleId);
                $cst->execute();
                $cRow = $cst->get_result()->fetch_assoc();
                if (!$cRow) { throw new Exception('campaign not found'); }
                $displayLimit = $cRow['display_limit'] !== null ? (int)$cRow['display_limit'] : null;
                $extraPool = $cRow['extra_pool_limit'] !== null ? (int)$cRow['extra_pool_limit'] : 0;
                if ($displayLimit === null || $displayLimit <= 0) { throw new Exception('campaign display_limit required'); }
                if ($extraPool < 0) { $extraPool = 0; }
                $limit = $displayLimit + $extraPool;
                $ist = $conn->prepare('SELECT product_spec_id FROM promotion_items WHERE promotion_id = ? ORDER BY id ASC LIMIT ?');
                $ist->bind_param('ii', $campaignBundleId, $limit);
                $ist->execute();
                $ri = $ist->get_result();
                $ordered = [];
                while ($r = $ri->fetch_assoc()) { $ordered[] = (int)($r['product_spec_id'] ?? 0); }
                if (count($ordered) < $displayLimit) { throw new Exception('campaign items incomplete'); }
                $displaySet = [];
                $extraSet = [];
                for ($i = 0; $i < count($ordered); $i++) {
                    $sid = (int)$ordered[$i];
                    if ($sid <= 0) { continue; }
                    if ($i < $displayLimit) $displaySet[$sid] = true;
                    else $extraSet[$sid] = true;
                }
                $allowedSet = $displaySet + $extraSet;

                $uniqueSpecs = [];
                foreach ($items as $it) {
                    $sid = (int)($it['product_spec_id'] ?? 0);
                    if ($sid <= 0) { throw new Exception('invalid spec id'); }
                    if (!isset($allowedSet[$sid])) { throw new Exception('campaign selection contains invalid item'); }
                    $q = max(1, (int)($it['quantity'] ?? 1));
                    if ($q !== 1) { throw new Exception('campaign quantity must be 1'); }
                    $uniqueSpecs[$sid] = true;
                }
                $selectedSpecs = array_keys($uniqueSpecs);
                $selectedCount = count($selectedSpecs);
                $minCount = max(0, $displayLimit - $extraPool);
                if ($selectedCount > $displayLimit) { throw new Exception('campaign selection exceeds display limit'); }
                if ($selectedCount < $minCount) { throw new Exception('campaign selection below minimum'); }
                $extraSelected = 0;
                foreach ($selectedSpecs as $sid) { if (isset($extraSet[(int)$sid])) $extraSelected++; }
                if ($extraSelected > $extraPool) { throw new Exception('campaign selection exceeds extra pool'); }
            }

            if ($this->hasColumn('orders', 'campaign_id')) {
                $ins = $conn->prepare('INSERT INTO orders (customer_name, phone_number, address, total_price, status, order_source, created_by_user_id, campaign_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                $ins->bind_param('sssdssii', $customer_name, $phone_number, $address, $zero, $status, $order_source, $createdBy, $campaignBundleId);
            } else {
                $ins = $conn->prepare('INSERT INTO orders (customer_name, phone_number, address, total_price, status, order_source, created_by_user_id) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $ins->bind_param('sssdssi', $customer_name, $phone_number, $address, $zero, $status, $order_source, $createdBy);
            }
            $ins->execute();
            $orderId = (int)$conn->insert_id;
            $total = 0.0;
            $costTotal = 0.0;
            $lines = [];
            $campaignSelections = [];
            foreach ($items as $it) {
                $specId = (int)($it['product_spec_id'] ?? 0);
                $qty = max(1, (int)($it['quantity'] ?? 1));
                if ($specId <= 0) {
                    throw new Exception('invalid spec id');
                }
                $row = $conn->prepare('SELECT ps.price AS spec_price, ps.product_id, ps.stock AS stock, COALESCE(ps.purchase_price, p.purchase_price) AS cost FROM product_specifications ps INNER JOIN products p ON p.id = ps.product_id WHERE ps.id = ?');
                $row->bind_param('i', $specId);
                $row->execute();
                $r = $row->get_result()->fetch_assoc();
                if (!$r) {
                    throw new Exception('spec not found');
                }
                if ((int)$r['stock'] < $qty) {
                    throw new Exception('insufficient stock');
                }
                $view = $conn->prepare('SELECT v.promotion_id, v.discount_type, v.discount_value, v.final_price, v.discount_amount, p.coupon_code AS coupon_code FROM vw_product_prices v LEFT JOIN promotions p ON p.id = v.promotion_id WHERE v.spec_id = ?');
                $view->bind_param('i', $specId);
                $view->execute();
                $v = $view->get_result()->fetch_assoc();
                $origPrice = (float)$r['spec_price'];
                $cost = (float)$r['cost'];
                $costTotal += $cost * $qty;
                $promoId = isset($v['promotion_id']) ? (int)$v['promotion_id'] : null;
                $productId = (int)$r['product_id'];
                if ($promoId !== null && isset($v['coupon_code']) && $v['coupon_code'] !== null && $v['coupon_code'] !== '') {
                    $promoId = null;
                }
                $campaign = $conn->prepare("SELECT p.id, pi.override_price
                    FROM promotions p
                    INNER JOIN promotion_items pi ON pi.promotion_id = p.id
                    WHERE p.discount_type = 'campaign'
                      AND p.is_active = 1
                      AND CURDATE() BETWEEN p.start_date AND p.end_date
                      AND pi.product_spec_id = ?
                      AND pi.override_price IS NOT NULL
                      AND pi.override_price > 0
                    ORDER BY p.priority DESC, p.id DESC
                    LIMIT 1");
                $campaign->bind_param('i', $specId);
                $campaign->execute();
                $cr = $campaign->get_result()->fetch_assoc();
                $campaignApplied = false;
                if ($cr) {
                    $promoId = (int)$cr['id'];
                    $promoPrice = (float)$cr['override_price'];
                    $campaignApplied = true;
                    if (!isset($campaignSelections[$promoId])) { $campaignSelections[$promoId] = []; }
                    $campaignSelections[$promoId][$specId] = true;
                } else {
                    $promoPrice = ($promoId !== null && isset($v['final_price'])) ? (float)$v['final_price'] : (float)$r['spec_price'];
                }
                $finalPrice = $promoPrice;
                $discountAmount = (!$campaignApplied && $promoId !== null && isset($v['discount_amount'])) ? (float)$v['discount_amount'] : max(0.0, $origPrice - $finalPrice);
                $unitPrice = null;
                if (array_key_exists('unit_price', $it) && $it['unit_price'] !== null && $it['unit_price'] !== '') {
                    $unitPrice = (float)$it['unit_price'];
                }
                if ($createdBy !== null && $unitPrice !== null) {
                    if ($unitPrice < 0) { throw new Exception('invalid unit_price'); }
                    if ($unitPrice < $cost) { throw new Exception('unit_price below purchase price'); }
                    if ($unitPrice > $promoPrice) { throw new Exception('unit_price above promo price'); }
                    $finalPrice = $unitPrice;
                    $discountAmount = max(0.0, $origPrice - $finalPrice);
                }
                if ($promoId === null) {
                    $oi = $conn->prepare('INSERT INTO order_items (order_id, product_id, product_spec_id, quantity, price, original_price, cost, discount_amount, promotion_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL)');
                    $oi->bind_param('iiiidddd', $orderId, $productId, $specId, $qty, $finalPrice, $origPrice, $cost, $discountAmount);
                } else {
                    $oi = $conn->prepare('INSERT INTO order_items (order_id, product_id, product_spec_id, quantity, price, original_price, cost, discount_amount, promotion_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                    $oi->bind_param('iiiiddddi', $orderId, $productId, $specId, $qty, $finalPrice, $origPrice, $cost, $discountAmount, $promoId);
                }
                $oi->execute();
                $total += $finalPrice * $qty;
                $lines[] = [
                    'spec_id' => $specId,
                    'line_total' => $finalPrice * $qty,
                    'line_cost' => $cost * $qty,
                ];
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

            if ($createdBy === null && $campaignSelections) {
                foreach ($campaignSelections as $campaignId => $specSet) {
                    if (!$this->hasColumn('promotions', 'display_limit') || !$this->hasColumn('promotions', 'extra_pool_limit')) { continue; }
                    $cst = $conn->prepare("SELECT display_limit, extra_pool_limit
                        FROM promotions
                        WHERE id = ?
                          AND discount_type = 'campaign'
                          AND is_active = 1
                          AND CURDATE() BETWEEN start_date AND end_date
                        LIMIT 1");
                    $campaignIdInt = (int)$campaignId;
                    $cst->bind_param('i', $campaignIdInt);
                    $cst->execute();
                    $cRow = $cst->get_result()->fetch_assoc();
                    if (!$cRow) { continue; }
                    $displayLimit = $cRow['display_limit'] !== null ? (int)$cRow['display_limit'] : null;
                    if ($displayLimit === null || $displayLimit <= 0) { continue; }
                    $extraPool = $cRow['extra_pool_limit'] !== null ? (int)$cRow['extra_pool_limit'] : 0;
                    if ($extraPool < 0) { $extraPool = 0; }
                    $selectedSpecs = array_keys($specSet);
                    $selectedCount = count($selectedSpecs);
                    $minCount = max(0, $displayLimit - $extraPool);
                    if ($selectedCount > $displayLimit) { throw new Exception('campaign selection exceeds display limit'); }
                    if ($selectedCount < $minCount) { throw new Exception('campaign selection below minimum'); }

                    $limit = $displayLimit + $extraPool;
                    $ist = $conn->prepare('SELECT product_spec_id FROM promotion_items WHERE promotion_id = ? ORDER BY id ASC LIMIT ?');
                    $ist->bind_param('ii', $campaignIdInt, $limit);
                    $ist->execute();
                    $ri = $ist->get_result();
                    $ordered = [];
                    while ($r = $ri->fetch_assoc()) { $ordered[] = (int)($r['product_spec_id'] ?? 0); }
                    $displaySet = [];
                    $extraSet = [];
                    for ($i = 0; $i < count($ordered); $i++) {
                        $sid = (int)$ordered[$i];
                        if ($sid <= 0) { continue; }
                        if ($i < $displayLimit) $displaySet[$sid] = true;
                        else $extraSet[$sid] = true;
                    }
                    $allowedSet = $displaySet + $extraSet;
                    foreach ($selectedSpecs as $sid) {
                        if (!isset($allowedSet[(int)$sid])) { throw new Exception('campaign selection contains invalid item'); }
                    }
                    $extraSelected = 0;
                    foreach ($selectedSpecs as $sid) { if (isset($extraSet[(int)$sid])) $extraSelected++; }
                    if ($extraSelected > $extraPool) { throw new Exception('campaign selection exceeds extra pool'); }
                }
            }

            $orderDiscount = 0.0;
            $couponCode = null;
            if (isset($d['coupon_code'])) { $couponCode = sanitize((string)$d['coupon_code']); }
            elseif (isset($d['coupon'])) { $couponCode = sanitize((string)$d['coupon']); }
            if ($couponCode !== null) {
                $couponCode = strtoupper(preg_replace('/\s+/', '', $couponCode));
                if ($couponCode === '') { $couponCode = null; }
            }
            if ($couponCode !== null) {
                if (!$this->hasColumn('promotions', 'coupon_code')) { throw new Exception('Missing DB columns for coupons'); }
                if (!$this->hasTable('promotion_coupon_redemptions')) { throw new Exception('Missing DB table promotion_coupon_redemptions'); }
                if (!$this->hasColumn('orders', 'order_discount')) { throw new Exception('Missing DB column orders.order_discount'); }

                $promo = $conn->prepare("SELECT id, discount_type, discount_value, usage_limit, used_count, min_order_amount
                    FROM promotions
                    WHERE coupon_code IS NOT NULL
                      AND UPPER(coupon_code) = ?
                      AND is_active = 1
                      AND CURDATE() BETWEEN start_date AND end_date
                    ORDER BY priority DESC, id DESC
                    LIMIT 1
                    FOR UPDATE");
                $promo->bind_param('s', $couponCode);
                $promo->execute();
                $pr = $promo->get_result()->fetch_assoc();
                if (!$pr) { throw new Exception('Invalid coupon'); }
                $couponPromoId = (int)$pr['id'];
                $usageLimit = $pr['usage_limit'] !== null ? (int)$pr['usage_limit'] : null;
                $usedCount = $pr['used_count'] !== null ? (int)$pr['used_count'] : 0;
                if ($usageLimit !== null && $usedCount >= $usageLimit) { throw new Exception('Coupon usage limit reached'); }
                $minOrder = $pr['min_order_amount'] !== null ? (float)$pr['min_order_amount'] : 0.0;
                if ($total < $minOrder) { throw new Exception('Order amount too low for coupon'); }

                $redeem = $conn->prepare('SELECT id FROM promotion_coupon_redemptions WHERE promotion_id = ? AND phone_number = ? LIMIT 1');
                $redeem->bind_param('is', $couponPromoId, $phone_number);
                $redeem->execute();
                if ($redeem->get_result()->fetch_assoc()) { throw new Exception('Coupon already used for this phone'); }

                $restrict = $conn->prepare('SELECT product_spec_id FROM promotion_items WHERE promotion_id = ?');
                $restrict->bind_param('i', $couponPromoId);
                $restrict->execute();
                $rr = $restrict->get_result();
                $eligibleSpecs = [];
                while ($r = $rr->fetch_assoc()) {
                    $sid = (int)($r['product_spec_id'] ?? 0);
                    if ($sid > 0) { $eligibleSpecs[$sid] = true; }
                }
                $hasRestrictions = !empty($eligibleSpecs);

                $eligibleTotal = 0.0;
                $eligibleCost = 0.0;
                foreach ($lines as $ln) {
                    $sid = (int)$ln['spec_id'];
                    if (!$hasRestrictions || isset($eligibleSpecs[$sid])) {
                        $eligibleTotal += (float)$ln['line_total'];
                        $eligibleCost += (float)$ln['line_cost'];
                    }
                }
                if ($hasRestrictions && $eligibleTotal <= 0) { throw new Exception('No eligible items for coupon'); }

                $discountType = (string)($pr['discount_type'] ?? '');
                $discountValue = (float)($pr['discount_value'] ?? 0);
                if ($discountValue <= 0) { throw new Exception('Invalid coupon'); }
                if ($discountType === 'percentage') {
                    $orderDiscount = round($eligibleTotal * ($discountValue / 100), 2);
                } else {
                    $orderDiscount = $discountValue;
                }
                if ($orderDiscount < 0) { $orderDiscount = 0.0; }
                if ($orderDiscount > $eligibleTotal) { $orderDiscount = $eligibleTotal; }

                $grossProfit = $eligibleTotal - $eligibleCost;
                $maxDiscount = $grossProfit > 0 ? $grossProfit : 0.0;
                if ($orderDiscount > $maxDiscount) { throw new Exception('Coupon discount exceeds profit'); }

                $upPromo = $conn->prepare('UPDATE promotions SET used_count = used_count + 1 WHERE id = ?');
                $upPromo->bind_param('i', $couponPromoId);
                $upPromo->execute();

                $insRed = $conn->prepare('INSERT INTO promotion_coupon_redemptions (promotion_id, phone_number, order_id) VALUES (?, ?, ?)');
                $insRed->bind_param('isi', $couponPromoId, $phone_number, $orderId);
                $insRed->execute();

                $total = $total - $orderDiscount;
                if ($total < 0) { $total = 0.0; }
            }

            $threshold = $this->getDeliveryThreshold();
            $reqPaidBy = isset($d['delivery_paid_by']) ? sanitize($d['delivery_paid_by']) : null;
            $reqPaidBy = $reqPaidBy ? strtolower($reqPaidBy) : null;
            if (!in_array($reqPaidBy, ['client','medlan'], true)) { $reqPaidBy = null; }
            $reqCityId = isset($d['delivery_city_id']) ? (int)$d['delivery_city_id'] : null;
            $paidBy = 'client';
            if ($total >= $threshold) {
                if ($createdBy !== null) {
                    $paidBy = ($reqPaidBy === 'client') ? 'client' : 'medlan';
                } else {
                    $paidBy = 'medlan';
                }
            }
            $cityId = $reqCityId ?: $this->findCityIdFromAddress($address);
            $deliveryFee = null;
            if ($paidBy === 'medlan') {
                if (!$cityId) {
                    if ($createdBy !== null) { throw new Exception('delivery_city_id required'); }
                } else {
                    $cst = $conn->prepare('SELECT fee FROM delivery_cities WHERE id = ? AND is_active = 1');
                    $cst->bind_param('i', $cityId);
                    $cst->execute();
                    $cr = $cst->get_result()->fetch_assoc();
                    if (!$cr) { throw new Exception('delivery city not found'); }
                    $deliveryFee = (float)$cr['fee'];
                }
            }
            if ($this->hasColumn('orders', 'order_discount')) {
                $uord = $conn->prepare('UPDATE orders SET total_price = ?, order_discount = ?, delivery_city_id = ?, delivery_fee = ?, delivery_paid_by = ? WHERE id = ?');
                $uord->bind_param('ddidsi', $total, $orderDiscount, $cityId, $deliveryFee, $paidBy, $orderId);
            } else {
                $uord = $conn->prepare('UPDATE orders SET total_price = ?, delivery_city_id = ?, delivery_fee = ?, delivery_paid_by = ? WHERE id = ?');
                $uord->bind_param('didsi', $total, $cityId, $deliveryFee, $paidBy, $orderId);
            }
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
        $hasCampaign = $this->hasColumn('orders', 'campaign_id');
        $status = $_GET['status'] ?? null;
        $source = $_GET['source'] ?? null;
        $where = [];
        $types = '';
        $params = [];
        $prefix = $hasCampaign ? 'o.' : '';
        if ($status !== null) { $where[] = $prefix . 'status = ?'; $types .= 's'; $params[] = sanitize($status); }
        if ($source !== null) { $where[] = $prefix . 'order_source = ?'; $types .= 's'; $params[] = sanitize($source); }
        $w = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        if ($hasCampaign) {
            $sql = "SELECT o.id, o.customer_name, o.phone_number, o.total_price, o.status, o.order_source, o.delivery_city_id, o.delivery_fee, o.delivery_paid_by, o.created_by_user_id, o.created_at,
                           o.campaign_id, cp.name AS campaign_name
                    FROM orders o
                    LEFT JOIN promotions cp ON cp.id = o.campaign_id AND cp.discount_type = 'campaign'
                    $w
                    ORDER BY o.id DESC
                    LIMIT 100";
        } else {
            $sql = "SELECT id, customer_name, phone_number, total_price, status, order_source, delivery_city_id, delivery_fee, delivery_paid_by, created_by_user_id, created_at
                    FROM orders $w
                    ORDER BY id DESC
                    LIMIT 100";
        }
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
        $hasCampaign = $this->hasColumn('orders', 'campaign_id');
        if ($hasCampaign) {
            $ord = $conn->prepare("SELECT o.*, dc.name AS delivery_city_name, dc.city_key AS delivery_city_key,
                                          cp.name AS campaign_name
                                   FROM orders o
                                   LEFT JOIN delivery_cities dc ON dc.id = o.delivery_city_id
                                   LEFT JOIN promotions cp ON cp.id = o.campaign_id AND cp.discount_type = 'campaign'
                                   WHERE o.id = ?");
        } else {
            $ord = $conn->prepare('SELECT o.*, dc.name AS delivery_city_name, dc.city_key AS delivery_city_key FROM orders o LEFT JOIN delivery_cities dc ON dc.id = o.delivery_city_id WHERE o.id = ?');
        }
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

    public function updateDiscount($id): void
    {
        global $conn;
        $oid = (int)$id;
        if (!$this->hasColumn('orders', 'order_discount')) {
            jsonResponse(false, 'Missing DB column orders.order_discount', null, 500);
            return;
        }
        if (!$this->hasColumn('expenses', 'order_id')) {
            jsonResponse(false, 'Missing DB column expenses.order_id', null, 500);
            return;
        }
        $stRow = $conn->prepare('SELECT status FROM orders WHERE id = ?');
        $stRow->bind_param('i', $oid);
        $stRow->execute();
        $old = $stRow->get_result()->fetch_assoc();
        if (!$old) {
            jsonResponse(false, 'Not Found', null, 404);
            return;
        }
        $status = strtolower((string)($old['status'] ?? ''));
        if (!in_array($status, ['pending','processing','shipped'], true)) {
            jsonResponse(false, 'Discount can only be changed for pending/processing/shipped', null, 409);
            return;
        }
        $d = getJsonInput();
        $discount = isset($d['order_discount']) ? (float)$d['order_discount'] : (isset($d['discount']) ? (float)$d['discount'] : 0.0);
        if ($discount < 0) {
            jsonResponse(false, 'Invalid discount', null, 422);
            return;
        }
        $items = $conn->prepare('SELECT quantity, price, cost FROM order_items WHERE order_id = ?');
        $items->bind_param('i', $oid);
        $items->execute();
        $res = $items->get_result();
        $subtotal = 0.0;
        $costTotal = 0.0;
        while ($r = $res->fetch_assoc()) {
            $qty = (int)($r['quantity'] ?? 0);
            $subtotal += (float)($r['price'] ?? 0) * $qty;
            $costTotal += (float)($r['cost'] ?? 0) * $qty;
        }
        if ($subtotal <= 0) {
            jsonResponse(false, 'No items', null, 422);
            return;
        }
        $grossProfit = $subtotal - $costTotal;
        $maxDiscount = $grossProfit > 0 ? $grossProfit : 0.0;
        if ($discount > $maxDiscount) {
            jsonResponse(false, 'Discount exceeds profit', ['max_discount' => $maxDiscount], 422);
            return;
        }
        $newTotal = $subtotal - $discount;
        $conn->begin_transaction();
        try {
            $up = $conn->prepare('UPDATE orders SET order_discount = ?, total_price = ? WHERE id = ?');
            $up->bind_param('ddi', $discount, $newTotal, $oid);
            $up->execute();
            if ($discount > 0) {
                $this->ensureDiscountExpense($oid, $discount);
            } else {
                $this->deleteDiscountExpense($oid);
            }
            $conn->commit();
            jsonResponse(true, 'Updated', [
                'order_id' => $oid,
                'subtotal' => $subtotal,
                'discount' => $discount,
                'total_price' => $newTotal,
                'gross_profit' => $grossProfit,
                'net_profit' => $grossProfit - $discount,
            ]);
        } catch (Throwable $e) {
            $conn->rollback();
            jsonResponse(false, 'Update failed', ['detail' => $e->getMessage()], 400);
        }
    }

    public function updateItemPrice($id): void
    {
        AuthMiddleware::requireAuth();
        global $conn;
        $orderItemId = (int)$id;
        $d = getJsonInput();
        $unitPrice = null;
        if (array_key_exists('unit_price', $d)) { $unitPrice = (float)$d['unit_price']; }
        elseif (array_key_exists('price', $d)) { $unitPrice = (float)$d['price']; }
        if ($unitPrice === null || $unitPrice < 0) {
            jsonResponse(false, 'Invalid unit_price', null, 422);
            return;
        }
        $st = $conn->prepare('SELECT oi.id, oi.order_id, oi.product_spec_id, oi.quantity, oi.price, oi.original_price, oi.cost, o.status, o.created_by_user_id, o.order_discount FROM order_items oi INNER JOIN orders o ON o.id = oi.order_id WHERE oi.id = ?');
        $st->bind_param('i', $orderItemId);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        if (!$row) {
            jsonResponse(false, 'Not Found', null, 404);
            return;
        }
        $status = strtolower((string)($row['status'] ?? ''));
        if (!in_array($status, ['pending','processing','shipped'], true)) {
            jsonResponse(false, 'Item price can only be changed for pending/processing/shipped', null, 409);
            return;
        }
        if (($row['created_by_user_id'] ?? null) === null) {
            jsonResponse(false, 'Not allowed', null, 403);
            return;
        }
        $specId = (int)($row['product_spec_id'] ?? 0);
        if ($specId <= 0) {
            jsonResponse(false, 'Invalid spec', null, 422);
            return;
        }
        $minPrice = (float)($row['cost'] ?? 0);
        $origPrice = (float)($row['original_price'] ?? 0);
        $promoPrice = $origPrice;
        $sp = $conn->prepare('SELECT ps.price AS spec_price FROM product_specifications ps WHERE ps.id = ?');
        $sp->bind_param('i', $specId);
        $sp->execute();
        $spr = $sp->get_result()->fetch_assoc();
        if ($spr && isset($spr['spec_price'])) {
            $promoPrice = (float)$spr['spec_price'];
        }
        $vw = $conn->prepare('SELECT final_price FROM vw_product_prices WHERE spec_id = ?');
        $vw->bind_param('i', $specId);
        $vw->execute();
        $vwr = $vw->get_result()->fetch_assoc();
        if ($vwr && isset($vwr['final_price']) && $vwr['final_price'] !== null && $vwr['final_price'] !== '') {
            $promoPrice = (float)$vwr['final_price'];
        }
        if ($unitPrice < $minPrice) {
            jsonResponse(false, 'unit_price below purchase price', ['min' => $minPrice], 422);
            return;
        }
        if ($unitPrice > $promoPrice) {
            jsonResponse(false, 'unit_price above variant price', ['max' => $promoPrice], 422);
            return;
        }
        $discountAmount = max(0.0, $origPrice - $unitPrice);
        $conn->begin_transaction();
        try {
            $up = $conn->prepare('UPDATE order_items SET price = ?, discount_amount = ? WHERE id = ?');
            $up->bind_param('ddi', $unitPrice, $discountAmount, $orderItemId);
            $up->execute();
            $sum = $conn->prepare('SELECT COALESCE(SUM(price * quantity),0) AS subtotal FROM order_items WHERE order_id = ?');
            $orderId = (int)$row['order_id'];
            $sum->bind_param('i', $orderId);
            $sum->execute();
            $sr = $sum->get_result()->fetch_assoc();
            $subtotal = (float)($sr['subtotal'] ?? 0);
            $orderDiscount = $this->hasColumn('orders', 'order_discount') ? (float)($row['order_discount'] ?? 0) : 0.0;
            $newTotal = $subtotal - $orderDiscount;
            if ($newTotal < 0) { $newTotal = 0.0; }
            $uo = $conn->prepare('UPDATE orders SET total_price = ? WHERE id = ?');
            $uo->bind_param('di', $newTotal, $orderId);
            $uo->execute();
            $conn->commit();
            jsonResponse(true, 'Updated', [
                'order_id' => $orderId,
                'order_item_id' => $orderItemId,
                'unit_price' => $unitPrice,
                'promo_price' => $promoPrice,
                'purchase_price' => $minPrice,
                'subtotal' => $subtotal,
                'total_price' => $newTotal,
            ]);
        } catch (Throwable $e) {
            $conn->rollback();
            jsonResponse(false, 'Update failed', ['detail' => $e->getMessage()], 400);
        }
    }

    public function addItem($id): void
    {
        AuthMiddleware::requireAuth();
        global $conn;
        $orderId = (int)$id;
        $d = getJsonInput();
        $specId = (int)($d['product_spec_id'] ?? 0);
        $qty = max(1, (int)($d['quantity'] ?? 1));
        $unitPrice = null;
        if (array_key_exists('unit_price', $d) && $d['unit_price'] !== null && $d['unit_price'] !== '') {
            $unitPrice = (float)$d['unit_price'];
        }
        if ($specId <= 0) {
            jsonResponse(false, 'product_spec_id required', null, 422);
            return;
        }
        if ($qty <= 0) {
            jsonResponse(false, 'quantity required', null, 422);
            return;
        }
        $o = $conn->prepare('SELECT id, status, created_by_user_id, order_discount FROM orders WHERE id = ?');
        $o->bind_param('i', $orderId);
        $o->execute();
        $ord = $o->get_result()->fetch_assoc();
        if (!$ord) {
            jsonResponse(false, 'Not Found', null, 404);
            return;
        }
        $status = strtolower((string)($ord['status'] ?? ''));
        if (!in_array($status, ['pending','processing','shipped'], true)) {
            jsonResponse(false, 'Items can only be changed for pending/processing/shipped', null, 409);
            return;
        }
        if (($ord['created_by_user_id'] ?? null) === null) {
            jsonResponse(false, 'Not allowed', null, 403);
            return;
        }
        $row = $conn->prepare('SELECT ps.price AS spec_price, ps.product_id, ps.stock AS stock, COALESCE(ps.purchase_price, p.purchase_price) AS cost FROM product_specifications ps INNER JOIN products p ON p.id = ps.product_id WHERE ps.id = ?');
        $row->bind_param('i', $specId);
        $row->execute();
        $r = $row->get_result()->fetch_assoc();
        if (!$r) {
            jsonResponse(false, 'spec not found', null, 404);
            return;
        }
        if ((int)$r['stock'] < $qty) {
            jsonResponse(false, 'insufficient stock', null, 409);
            return;
        }
        $view = $conn->prepare('SELECT promotion_id, final_price, discount_amount FROM vw_product_prices WHERE spec_id = ?');
        $view->bind_param('i', $specId);
        $view->execute();
        $v = $view->get_result()->fetch_assoc();
        $origPrice = (float)$r['spec_price'];
        $cost = (float)$r['cost'];
        $promoId = isset($v['promotion_id']) ? (int)$v['promotion_id'] : null;
        $productId = (int)$r['product_id'];
        $promoPrice = isset($v['final_price']) ? (float)$v['final_price'] : (float)$r['spec_price'];
        $finalPrice = $promoPrice;
        $discountAmount = isset($v['discount_amount']) ? (float)$v['discount_amount'] : max(0.0, $origPrice - $finalPrice);
        if ($unitPrice !== null) {
            if ($unitPrice < 0) { jsonResponse(false, 'invalid unit_price', null, 422); return; }
            if ($unitPrice < $cost) { jsonResponse(false, 'unit_price below purchase price', ['min' => $cost], 422); return; }
            if ($unitPrice > $promoPrice) { jsonResponse(false, 'unit_price above variant price', ['max' => $promoPrice], 422); return; }
            $finalPrice = $unitPrice;
            $discountAmount = max(0.0, $origPrice - $finalPrice);
        }
        $conn->begin_transaction();
        try {
            if ($promoId === null) {
                $oi = $conn->prepare('INSERT INTO order_items (order_id, product_id, product_spec_id, quantity, price, original_price, cost, discount_amount, promotion_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL)');
                $oi->bind_param('iiiidddd', $orderId, $productId, $specId, $qty, $finalPrice, $origPrice, $cost, $discountAmount);
            } else {
                $oi = $conn->prepare('INSERT INTO order_items (order_id, product_id, product_spec_id, quantity, price, original_price, cost, discount_amount, promotion_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $oi->bind_param('iiiiddddi', $orderId, $productId, $specId, $qty, $finalPrice, $origPrice, $cost, $discountAmount, $promoId);
            }
            $oi->execute();
            $orderItemId = (int)$conn->insert_id;
            $sm = $conn->prepare('INSERT INTO stock_movements (product_spec_id, type, quantity, order_item_id, description) VALUES (?, ?, ?, ?, ?)');
            $type = 'sale';
            $desc = 'order item add';
            $negQty = -$qty;
            $sm->bind_param('isiss', $specId, $type, $negQty, $orderItemId, $desc);
            $sm->execute();
            $upd = $conn->prepare('UPDATE product_specifications SET stock = stock - ? WHERE id = ?');
            $upd->bind_param('ii', $qty, $specId);
            $upd->execute();
            $sum = $conn->prepare('SELECT COALESCE(SUM(price * quantity),0) AS subtotal FROM order_items WHERE order_id = ?');
            $sum->bind_param('i', $orderId);
            $sum->execute();
            $sr = $sum->get_result()->fetch_assoc();
            $subtotal = (float)($sr['subtotal'] ?? 0);
            $orderDiscount = $this->hasColumn('orders', 'order_discount') ? (float)($ord['order_discount'] ?? 0) : 0.0;
            $newTotal = $subtotal - $orderDiscount;
            if ($newTotal < 0) { $newTotal = 0.0; }
            $uo = $conn->prepare('UPDATE orders SET total_price = ? WHERE id = ?');
            $uo->bind_param('di', $newTotal, $orderId);
            $uo->execute();
            $conn->commit();
            jsonResponse(true, 'Created', ['order_item_id' => $orderItemId, 'order_id' => $orderId, 'total_price' => $newTotal], 201);
        } catch (Throwable $e) {
            $conn->rollback();
            jsonResponse(false, 'Create failed', ['detail' => $e->getMessage()], 400);
        }
    }

    public function deleteItem($id): void
    {
        AuthMiddleware::requireAuth();
        global $conn;
        $orderItemId = (int)$id;
        $st = $conn->prepare('SELECT oi.id, oi.order_id, oi.product_spec_id, oi.quantity, o.status, o.created_by_user_id, o.order_discount FROM order_items oi INNER JOIN orders o ON o.id = oi.order_id WHERE oi.id = ?');
        $st->bind_param('i', $orderItemId);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        if (!$row) {
            jsonResponse(false, 'Not Found', null, 404);
            return;
        }
        $status = strtolower((string)($row['status'] ?? ''));
        if (!in_array($status, ['pending','processing','shipped'], true)) {
            jsonResponse(false, 'Items can only be changed for pending/processing/shipped', null, 409);
            return;
        }
        if (($row['created_by_user_id'] ?? null) === null) {
            jsonResponse(false, 'Not allowed', null, 403);
            return;
        }
        $orderId = (int)$row['order_id'];
        $cnt = $conn->prepare('SELECT COUNT(*) AS c FROM order_items WHERE order_id = ?');
        $cnt->bind_param('i', $orderId);
        $cnt->execute();
        $cr = $cnt->get_result()->fetch_assoc();
        if ((int)($cr['c'] ?? 0) <= 1) {
            jsonResponse(false, 'Order must have at least one item', null, 409);
            return;
        }
        $specId = (int)$row['product_spec_id'];
        $qty = (int)$row['quantity'];
        $conn->begin_transaction();
        try {
            $del = $conn->prepare('DELETE FROM order_items WHERE id = ?');
            $del->bind_param('i', $orderItemId);
            $del->execute();
            $sm = $conn->prepare('INSERT INTO stock_movements (product_spec_id, type, quantity, description) VALUES (?, ?, ?, ?)');
            $type = 'adjustment';
            $desc = 'order item remove';
            $sm->bind_param('isis', $specId, $type, $qty, $desc);
            $sm->execute();
            $upd = $conn->prepare('UPDATE product_specifications SET stock = stock + ? WHERE id = ?');
            $upd->bind_param('ii', $qty, $specId);
            $upd->execute();
            $sum = $conn->prepare('SELECT COALESCE(SUM(price * quantity),0) AS subtotal FROM order_items WHERE order_id = ?');
            $sum->bind_param('i', $orderId);
            $sum->execute();
            $sr = $sum->get_result()->fetch_assoc();
            $subtotal = (float)($sr['subtotal'] ?? 0);
            $orderDiscount = $this->hasColumn('orders', 'order_discount') ? (float)($row['order_discount'] ?? 0) : 0.0;
            $newTotal = $subtotal - $orderDiscount;
            if ($newTotal < 0) { $newTotal = 0.0; }
            $uo = $conn->prepare('UPDATE orders SET total_price = ? WHERE id = ?');
            $uo->bind_param('di', $newTotal, $orderId);
            $uo->execute();
            $conn->commit();
            jsonResponse(true, 'Deleted', ['deleted' => true, 'order_id' => $orderId, 'total_price' => $newTotal]);
        } catch (Throwable $e) {
            $conn->rollback();
            jsonResponse(false, 'Delete failed', ['detail' => $e->getMessage()], 400);
        }
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
        $conn->begin_transaction();
        try {
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
            if ($status === 'completed') {
                if (array_key_exists('order_discount', $d) || array_key_exists('discount', $d)) {
                    if (!$this->hasColumn('orders', 'order_discount')) { throw new Exception('Missing DB column orders.order_discount'); }
                    if (!$this->hasColumn('expenses', 'order_id')) { throw new Exception('Missing DB column expenses.order_id'); }
                    $discount = array_key_exists('order_discount', $d) ? (float)$d['order_discount'] : (float)($d['discount'] ?? 0);
                    if ($discount < 0) { throw new Exception('Invalid discount'); }
                    $items = $conn->prepare('SELECT quantity, price, cost FROM order_items WHERE order_id = ?');
                    $items->bind_param('i', $oid);
                    $items->execute();
                    $res = $items->get_result();
                    $subtotal = 0.0;
                    $costTotal = 0.0;
                    while ($r = $res->fetch_assoc()) {
                        $qty = (int)($r['quantity'] ?? 0);
                        $subtotal += (float)($r['price'] ?? 0) * $qty;
                        $costTotal += (float)($r['cost'] ?? 0) * $qty;
                    }
                    $grossProfit = $subtotal - $costTotal;
                    $maxDiscount = $grossProfit > 0 ? $grossProfit : 0.0;
                    if ($discount > $maxDiscount) { throw new Exception('Discount exceeds profit'); }
                    $newTotal = $subtotal - $discount;
                    $up = $conn->prepare('UPDATE orders SET order_discount = ?, total_price = ? WHERE id = ?');
                    $up->bind_param('ddi', $discount, $newTotal, $oid);
                    $up->execute();
                    if ($discount > 0) {
                        $this->ensureDiscountExpense($oid, $discount);
                    } else {
                        $this->deleteDiscountExpense($oid);
                    }
                }
                $this->ensureDeliveryExpense($oid);
            } elseif ($status === 'returned') {
                $this->deleteDeliveryExpense($oid);
                $this->deleteDiscountExpense($oid);
            } elseif ($status === 'cancelled') {
                $this->deleteDiscountExpense($oid);
            }
            $conn->commit();
            jsonResponse(true, 'Updated', ['updated' => true]);
        } catch (Throwable $e) {
            $conn->rollback();
            jsonResponse(false, 'Update failed', ['detail' => $e->getMessage()], 400);
        }
    }
}
