<?php
class CampaignController
{
    private function fetchCampaign(int $id): ?array
    {
        global $conn;
        $st = $conn->prepare("SELECT * FROM promotions WHERE id = ? AND discount_type = 'campaign'");
        $st->bind_param('i', $id);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        return $row ?: null;
    }

    private function fetchCampaignItems(int $campaignId): array
    {
        global $conn;
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
        $items->bind_param('i', $campaignId);
        $items->execute();
        $list = [];
        $ri = $items->get_result();
        while ($r = $ri->fetch_assoc()) { $list[] = $r; }
        return $list;
    }

    private function splitItems(array $items, ?int $displayLimit, ?int $extraPool): array
    {
        $displayLimit = $displayLimit !== null ? max(0, (int)$displayLimit) : null;
        $extraPool = $extraPool !== null ? max(0, (int)$extraPool) : 0;
        if ($displayLimit === null || $displayLimit <= 0) {
            return [$items, []];
        }
        $display = array_slice($items, 0, $displayLimit);
        $extra = $extraPool > 0 ? array_slice($items, $displayLimit, $extraPool) : [];
        return [$display, $extra];
    }

    public function index(): void
    {
        global $conn;
        $onlyActive = isset($_GET['active']) ? (int)$_GET['active'] : 1;
        $w = [];
        $types = '';
        $params = [];
        $w[] = "discount_type = 'campaign'";
        if ($onlyActive === 1) {
            $w[] = 'is_active = 1';
            $w[] = 'CURDATE() BETWEEN start_date AND end_date';
        }
        $where = $w ? ('WHERE ' . implode(' AND ', $w)) : '';
        $sql = "SELECT id, name, description, display_limit, extra_pool_limit, start_date, end_date, is_active, priority 
                FROM promotions $where
                ORDER BY priority DESC, id DESC";
        $st = $conn->prepare($sql);
        if ($types !== '') { $st->bind_param($types, ...$params); }
        $st->execute();
        $res = $st->get_result();
        $rows = [];
        while ($r = $res->fetch_assoc()) {
            $cid = (int)$r['id'];
            $items = $this->fetchCampaignItems($cid);
            $displayLimit = $r['display_limit'] !== null ? (int)$r['display_limit'] : null;
            $extraPool = $r['extra_pool_limit'] !== null ? (int)$r['extra_pool_limit'] : 0;
            [$displayItems, $extraItems] = $this->splitItems($items, $displayLimit, $extraPool);
            $total = 0.0;
            foreach ($displayItems as $it) { $total += (float)($it['override_price'] ?? 0); }
            $r['total_display_price'] = $total;
            $r['display_items_count'] = count($displayItems);
            $r['extra_pool_count'] = count($extraItems);
            $r['items_count'] = count($items);
            $rows[] = $r;
        }
        jsonResponse(true, 'OK', ['data' => $rows]);
    }

    public function show($id): void
    {
        $cid = (int)$id;
        $campaign = $this->fetchCampaign($cid);
        if (!$campaign) { jsonResponse(false, 'Not Found', null, 404); return; }
        if ((int)($campaign['is_active'] ?? 0) !== 1) { jsonResponse(false, 'Inactive', null, 409); return; }
        $today = date('Y-m-d');
        if (($campaign['start_date'] ?? '0000-00-00') > $today || ($campaign['end_date'] ?? '0000-00-00') < $today) {
            jsonResponse(false, 'Expired', null, 409);
            return;
        }
        $items = $this->fetchCampaignItems($cid);
        $displayLimit = $campaign['display_limit'] !== null ? (int)$campaign['display_limit'] : null;
        $extraPool = $campaign['extra_pool_limit'] !== null ? (int)$campaign['extra_pool_limit'] : 0;
        [$displayItems, $extraItems] = $this->splitItems($items, $displayLimit, $extraPool);
        $total = 0.0;
        foreach ($displayItems as $it) { $total += (float)($it['override_price'] ?? 0); }
        $minSelectable = 0;
        $maxSelectable = $displayLimit !== null ? max(0, $displayLimit) : count($displayItems);
        if ($displayLimit !== null) {
            $minSelectable = max(0, $displayLimit - max(0, $extraPool));
        }
        jsonResponse(true, 'OK', [
            'campaign' => $campaign,
            'display_items' => $displayItems,
            'extra_pool_items' => $extraItems,
            'total_display_price' => $total,
            'constraints' => [
                'display_limit' => $displayLimit,
                'extra_pool_limit' => $extraPool,
                'min_selectable_count' => $minSelectable,
                'max_selectable_count' => $maxSelectable,
                'max_extra_selectable_count' => max(0, $extraPool),
            ],
        ]);
    }

    public function quote($id): void
    {
        $cid = (int)$id;
        $campaign = $this->fetchCampaign($cid);
        if (!$campaign) { jsonResponse(false, 'Not Found', null, 404); return; }
        $d = getJsonInput();
        $selected = $d['selected_spec_ids'] ?? ($d['spec_ids'] ?? null);
        if (!is_array($selected)) { jsonResponse(false, 'selected_spec_ids required', null, 422); return; }
        $selectedIds = [];
        foreach ($selected as $v) {
            $sid = (int)$v;
            if ($sid > 0) { $selectedIds[$sid] = true; }
        }
        $selectedList = array_keys($selectedIds);
        sort($selectedList);
        $items = $this->fetchCampaignItems($cid);
        $displayLimit = $campaign['display_limit'] !== null ? (int)$campaign['display_limit'] : null;
        $extraPool = $campaign['extra_pool_limit'] !== null ? (int)$campaign['extra_pool_limit'] : 0;
        $displayLimit = $displayLimit !== null ? max(0, $displayLimit) : null;
        $extraPool = max(0, $extraPool);
        [$displayItems, $extraItems] = $this->splitItems($items, $displayLimit, $extraPool);
        $displaySet = [];
        $extraSet = [];
        $priceBySpec = [];
        foreach ($displayItems as $it) {
            $sid = (int)$it['product_spec_id'];
            $displaySet[$sid] = true;
            $priceBySpec[$sid] = (float)($it['override_price'] ?? 0);
        }
        foreach ($extraItems as $it) {
            $sid = (int)$it['product_spec_id'];
            $extraSet[$sid] = true;
            $priceBySpec[$sid] = (float)($it['override_price'] ?? 0);
        }
        $allowedSet = $displaySet + $extraSet;
        foreach ($selectedList as $sid) {
            if (!isset($allowedSet[$sid])) { jsonResponse(false, 'invalid selection', ['spec_id' => $sid], 422); return; }
        }
        $selectedCount = count($selectedList);
        if ($displayLimit !== null && $displayLimit > 0) {
            $min = max(0, $displayLimit - $extraPool);
            if ($selectedCount > $displayLimit) { jsonResponse(false, 'too many items', ['max' => $displayLimit], 422); return; }
            if ($selectedCount < $min) { jsonResponse(false, 'too few items', ['min' => $min], 422); return; }
            $extraSelected = 0;
            foreach ($selectedList as $sid) { if (isset($extraSet[$sid])) $extraSelected++; }
            if ($extraSelected > $extraPool) { jsonResponse(false, 'too many extra items', ['max_extra' => $extraPool], 422); return; }
        }
        $total = 0.0;
        foreach ($selectedList as $sid) { $total += (float)($priceBySpec[$sid] ?? 0.0); }
        jsonResponse(true, 'OK', ['total_price' => $total, 'selected_count' => $selectedCount]);
    }
}

