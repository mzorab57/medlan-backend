<?php
class ProductController
{
    public function backfillVariantPurchasePrice(): void
    {
        AuthMiddleware::requireAdmin();
        global $conn;
        $pid = isset($_GET['id']) ? (int)$_GET['id'] : null;
        if ($pid !== null && $pid <= 0) { $pid = null; }
        $where = $pid !== null ? 'AND ps.product_id = ?' : '';
        $sql = "UPDATE product_specifications ps
                INNER JOIN products p ON p.id = ps.product_id
                SET ps.purchase_price = p.purchase_price
                WHERE (ps.purchase_price IS NULL OR ps.purchase_price = 0)
                  $where";
        $st = $conn->prepare($sql);
        if ($pid !== null) {
            $st->bind_param('i', $pid);
        }
        if (!$st->execute()) {
            jsonResponse(false, 'Backfill failed', null, 500);
            return;
        }
        jsonResponse(true, 'OK', ['updated' => (int)$st->affected_rows]);
    }

    public function index(): void
    {
        global $conn;
        $page = max(1, (int)($_GET['page'] ?? 1));
        $per = min(100, max(1, (int)($_GET['per_page'] ?? 20)));
        $offset = ($page - 1) * $per;
        $filters = [];
        $types = '';
        $params = [];
        if (isset($_GET['category_id'])) { $filters[] = 'p.category_id = ?'; $types .= 'i'; $params[] = (int)$_GET['category_id']; }
        if (isset($_GET['subcategory_id'])) { $filters[] = 'p.subcategory_id = ?'; $types .= 'i'; $params[] = (int)$_GET['subcategory_id']; }
        if (isset($_GET['brand_id'])) { $filters[] = 'p.brand_id = ?'; $types .= 'i'; $params[] = (int)$_GET['brand_id']; }
        if (isset($_GET['featured'])) { $filters[] = 'p.is_featured = ?'; $types .= 'i'; $params[] = (int)$_GET['featured']; }
        if (isset($_GET['search'])) { $filters[] = 'p.name LIKE ?'; $types .= 's'; $params[] = '%' . sanitize($_GET['search']) . '%'; }
        $where = $filters ? ('WHERE ' . implode(' AND ', $filters)) : '';
        $sql = "SELECT p.id, p.name, p.code, p.slug, p.base_price, p.purchase_price, p.is_featured, p.is_active,
                       b.name AS brand_name, cat.name AS category_name, sub.name AS subcategory_name,
                       (SELECT COALESCE(SUM(stock),0) FROM product_specifications WHERE product_id = p.id) AS total_stock,
                       COALESCE(pi.image, (SELECT image FROM product_images WHERE product_id = p.id ORDER BY is_primary DESC, sort_order ASC, id ASC LIMIT 1)) AS primary_image
                FROM products p
                LEFT JOIN brands b ON b.id = p.brand_id
                LEFT JOIN categories cat ON cat.id = p.category_id
                LEFT JOIN subcategories sub ON sub.id = p.subcategory_id
                LEFT JOIN product_images pi ON pi.product_id = p.id AND pi.is_primary = 1
                $where
                ORDER BY p.id DESC LIMIT ? OFFSET ?";
        $types .= 'ii';
        $params[] = (int)$per;
        $params[] = (int)$offset;
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        jsonResponse(true, 'OK', ['data' => $rows, 'page' => $page, 'per_page' => $per]);
    }

    public function show($id): void
    {
        global $conn;
        $id = (int)$id;
        $pstmt = $conn->prepare('SELECT * FROM products WHERE id = ?');
        $pstmt->bind_param('i', $id);
        $pstmt->execute();
        $p = $pstmt->get_result()->fetch_assoc();
        if (!$p) {
            jsonResponse(false, 'Not Found', null, 404);
            return;
        }
        $istmt = $conn->prepare('SELECT id, image, alt_text, sort_order, is_primary FROM product_images WHERE product_id = ? ORDER BY sort_order, id');
        $istmt->bind_param('i', $id);
        $istmt->execute();
        $images = [];
        $ri = $istmt->get_result();
        while ($r = $ri->fetch_assoc()) { $images[] = $r; }
        $sstmt = $conn->prepare('SELECT id, sku_variant, spec_key, spec_value, image, price, purchase_price, stock, color_id, size_id, gender, is_active FROM product_specifications WHERE product_id = ? ORDER BY id');
        $sstmt->bind_param('i', $id);
        $sstmt->execute();
        $specs = [];
        $rs = $sstmt->get_result();
        while ($r = $rs->fetch_assoc()) { $specs[] = $r; }
        jsonResponse(true, 'OK', ['product' => $p, 'images' => $images, 'specs' => $specs]);
    }

    public function specsIndex($productId): void
    {
        global $conn;
        $pid = (int)$productId;
        $st = $conn->prepare('SELECT 
                ps.id, ps.sku_variant, ps.spec_key, ps.spec_value, ps.image, ps.price, ps.purchase_price, ps.stock, ps.color_id, ps.size_id, ps.gender, ps.is_active,
                c.name AS color_name, 
                s.name AS size_name,
                v.final_price,
                v.discount_amount
            FROM product_specifications ps
            LEFT JOIN colors c ON c.id = ps.color_id
            LEFT JOIN sizes s ON s.id = ps.size_id
            LEFT JOIN vw_product_prices v ON v.spec_id = ps.id
            WHERE ps.product_id = ?
            ORDER BY ps.id');
        $st->bind_param('i', $pid);
        $st->execute();
        $rows = [];
        $res = $st->get_result();
        while ($r = $res->fetch_assoc()) { $rows[] = $r; }
        jsonResponse(true, 'OK', ['data' => $rows]);
    }

    public function specsCreate($productId): void
    {
        global $conn;
        $pid = (int)$productId;
        $data = getJsonInput();
        $price = (float)($data['price'] ?? 0);
        $stock = (int)($data['stock'] ?? 0);
        $spec_key = sanitize($data['spec_key'] ?? '');
        $spec_value = sanitize($data['spec_value'] ?? '');
        $sku = sanitize($data['sku_variant'] ?? '');
        $image = sanitize($data['image'] ?? '');
        if ($image === '') { $image = null; }
        $purchase_price = isset($data['purchase_price']) ? (float)$data['purchase_price'] : null;
        $color_id = isset($data['color_id']) ? (int)$data['color_id'] : null;
        $size_id = isset($data['size_id']) ? (int)$data['size_id'] : null;
        $gender = sanitize($data['gender'] ?? '');
        if ($gender === '') { $gender = null; }
        $active = (int)($data['is_active'] ?? 1);
        if ($price <= 0) {
            jsonResponse(false, 'price required', null, 422);
            return;
        }
        $st = $conn->prepare('INSERT INTO product_specifications (product_id, sku_variant, spec_key, spec_value, image, price, purchase_price, stock, color_id, size_id, gender, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $st->bind_param('issssddiiisi', $pid, $sku, $spec_key, $spec_value, $image, $price, $purchase_price, $stock, $color_id, $size_id, $gender, $active);
        if (!$st->execute()) {
            jsonResponse(false, 'Error', null, 500);
            return;
        }
        jsonResponse(true, 'Created', ['id' => (int)$conn->insert_id], 201);
    }

    public function specUpdate($specId): void
    {
        global $conn;
        $sid = (int)$specId;
        $row = $conn->prepare('SELECT id FROM product_specifications WHERE id = ?');
        $row->bind_param('i', $sid);
        $row->execute();
        if (!$row->get_result()->fetch_assoc()) {
            jsonResponse(false, 'Not Found', null, 404);
            return;
        }
        $data = getJsonInput();
        $fields = [];
        $types = '';
        $params = [];
        $map = [
            'sku_variant' => 's',
            'spec_key' => 's',
            'spec_value' => 's',
            'image' => 's',
            'price' => 'd',
            'purchase_price' => 'd',
            'stock' => 'i',
            'color_id' => 'i',
            'size_id' => 'i',
            'gender' => 's',
            'is_active' => 'i'
        ];
        foreach ($map as $key => $t) {
            if (array_key_exists($key, $data)) {
                $fields[] = "$key = ?";
                $types .= $t;
                if ($t === 's') {
                    $params[] = sanitize((string)$data[$key]);
                } elseif ($t === 'i') {
                    $params[] = (int)$data[$key];
                } else {
                    $params[] = (float)$data[$key];
                }
            }
        }
        if (!$fields) {
            jsonResponse(true, 'No changes', ['updated' => false]);
            return;
        }
        $sql = 'UPDATE product_specifications SET ' . implode(', ', $fields) . ' WHERE id = ?';
        $types .= 'i';
        $params[] = $sid;
        $st = $conn->prepare($sql);
        $st->bind_param($types, ...$params);
        $st->execute();
        jsonResponse(true, 'Updated', ['updated' => true]);
    }

    public function specDestroy($specId): void
    {
        global $conn;
        $sid = (int)$specId;
        $st = $conn->prepare('DELETE FROM product_specifications WHERE id = ?');
        $st->bind_param('i', $sid);
        $st->execute();
        jsonResponse(true, 'Deleted', ['deleted' => true]);
    }

    public function specImagesList($specId): void
    {
        global $conn;
        $sid = (int)$specId;
        $st = $conn->prepare('SELECT id, spec_id, image, alt_text, sort_order, is_primary, created_at FROM product_spec_images WHERE spec_id = ? ORDER BY sort_order, id');
        $st->bind_param('i', $sid);
        $st->execute();
        $rows = [];
        $res = $st->get_result();
        while ($r = $res->fetch_assoc()) { $rows[] = $r; }
        jsonResponse(true, 'OK', ['data' => $rows]);
    }

    public function specImagesUpload($specId): void
    {
        global $conn;
        $sid = (int)$specId;
        $has = $conn->prepare('SELECT id FROM product_specifications WHERE id = ?');
        $has->bind_param('i', $sid);
        $has->execute();
        if (!$has->get_result()->fetch_assoc()) {
            jsonResponse(false, 'Not Found', null, 404);
            return;
        }
        if (!isset($_FILES['image'])) {
            jsonResponse(false, 'image file required', null, 422);
            return;
        }
        $max = getenv('MAX_FILE_SIZE') ? (int)getenv('MAX_FILE_SIZE') : 5242880;
        $uploadBase = getenv('UPLOAD_PATH') ?: 'uploads/';
        $destDir = __DIR__ . '/../' . rtrim($uploadBase, '/\\') . '/products/specs/';
        $ok = FileUpload::save('image', $destDir, ['jpg','jpeg','png','webp'], $max, null);
        if (!$ok[0]) {
            jsonResponse(false, $ok[1], null, 422);
            return;
        }
        $absolute = $ok[2];
        $relative = str_replace(realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR, '', realpath($absolute)) ?: (rtrim($uploadBase, '/\\') . '/products/specs/' . basename($absolute));
        $alt = sanitize($_POST['alt_text'] ?? '');
        $sort = (int)($_POST['sort_order'] ?? 0);
        $primary = (int)($_POST['is_primary'] ?? 0);
        // If no images exist yet for this variant, set first upload as primary by default
        $cnt = 0;
        $cntStmt = $conn->prepare('SELECT COUNT(*) AS c FROM product_spec_images WHERE spec_id = ?');
        $cntStmt->bind_param('i', $sid);
        $cntStmt->execute();
        $cntRes = $cntStmt->get_result()->fetch_assoc();
        if ($cntRes && isset($cntRes['c'])) { $cnt = (int)$cntRes['c']; }
        if ($cnt === 0) { $primary = 1; }
        if ($primary) {
            $off = $conn->prepare('UPDATE product_spec_images SET is_primary = 0 WHERE spec_id = ?');
            $off->bind_param('i', $sid);
            $off->execute();
        }
        $st = $conn->prepare('INSERT INTO product_spec_images (spec_id, image, alt_text, sort_order, is_primary) VALUES (?, ?, ?, ?, ?)');
        $st->bind_param('issii', $sid, $relative, $alt, $sort, $primary);
        $st->execute();
        jsonResponse(true, 'Created', ['id' => (int)$conn->insert_id, 'image' => $relative], 201);
    }

    public function specImageDestroy($imageId): void
    {
        global $conn;
        $iid = (int)$imageId;
        $st = $conn->prepare('SELECT image FROM product_spec_images WHERE id = ?');
        $st->bind_param('i', $iid);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        if ($row && isset($row['image'])) {
            $file = __DIR__ . '/../' . $row['image'];
            if (is_file($file)) @unlink($file);
        }
        $del = $conn->prepare('DELETE FROM product_spec_images WHERE id = ?');
        $del->bind_param('i', $iid);
        $del->execute();
        jsonResponse(true, 'Deleted', ['deleted' => true]);
    }

    public function specImageSetPrimary($imageId): void
    {
        global $conn;
        $iid = (int)$imageId;
        $st = $conn->prepare('SELECT spec_id FROM product_spec_images WHERE id = ?');
        $st->bind_param('i', $iid);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        if (!$row) {
            jsonResponse(false, 'Not Found', null, 404);
            return;
        }
        $sid = (int)$row['spec_id'];
        $off = $conn->prepare('UPDATE product_spec_images SET is_primary = 0 WHERE spec_id = ?');
        $off->bind_param('i', $sid);
        $off->execute();
        $on = $conn->prepare('UPDATE product_spec_images SET is_primary = 1 WHERE id = ?');
        $on->bind_param('i', $iid);
        $on->execute();
        jsonResponse(true, 'Updated', ['updated' => true]);
    }
    public function specImageUpload($specId): void
    {
        global $conn;
        $sid = (int)$specId;
        $row = $conn->prepare('SELECT id FROM product_specifications WHERE id = ?');
        $row->bind_param('i', $sid);
        $row->execute();
        if (!$row->get_result()->fetch_assoc()) {
            jsonResponse(false, 'Not Found', null, 404);
            return;
        }
        if (!isset($_FILES['image'])) {
            jsonResponse(false, 'image file required', null, 422);
            return;
        }
        $max = getenv('MAX_FILE_SIZE') ? (int)getenv('MAX_FILE_SIZE') : 5242880;
        $uploadBase = getenv('UPLOAD_PATH') ?: 'uploads/';
        $destDir = __DIR__ . '/../' . rtrim($uploadBase, '/\\') . '/products/specs/';
        $ok = FileUpload::save('image', $destDir, ['jpg','jpeg','png','webp'], $max, null);
        if (!$ok[0]) {
            jsonResponse(false, $ok[1], null, 422);
            return;
        }
        $absolute = $ok[2];
        $relative = str_replace(realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR, '', realpath($absolute)) ?: (rtrim($uploadBase, '/\\') . '/products/specs/' . basename($absolute));
        $alt = sanitize($_POST['alt_text'] ?? '');
        $st = $conn->prepare('UPDATE product_specifications SET image = ? WHERE id = ?');
        $st->bind_param('si', $relative, $sid);
        $st->execute();
        jsonResponse(true, 'Updated', ['image' => $relative]);
    }
    public function imagesUpload($productId): void
    {
        global $conn;
        $pid = (int)$productId;
        if (!isset($_FILES['image'])) {
            jsonResponse(false, 'image file required', null, 422);
            return;
        }
        $max = getenv('MAX_FILE_SIZE') ? (int)getenv('MAX_FILE_SIZE') : 5242880;
        $uploadBase = getenv('UPLOAD_PATH') ?: 'uploads/';
        $destDir = __DIR__ . '/../' . rtrim($uploadBase, '/\\') . '/products/';
        $newName = null;
        $ok = FileUpload::save('image', $destDir, ['jpg','jpeg','png','webp'], $max, $newName);
        if (!$ok[0]) {
            jsonResponse(false, $ok[1], null, 422);
            return;
        }
        $absolute = $ok[2];
        $relative = str_replace(realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR, '', realpath($absolute)) ?: (rtrim($uploadBase, '/\\') . '/products/' . basename($absolute));
        $alt = sanitize($_POST['alt_text'] ?? '');
        $sort = (int)($_POST['sort_order'] ?? 0);
        $primary = (int)($_POST['is_primary'] ?? 0);
        $st = $conn->prepare('INSERT INTO product_images (product_id, image, alt_text, sort_order, is_primary) VALUES (?, ?, ?, ?, ?)');
        $st->bind_param('issii', $pid, $relative, $alt, $sort, $primary);
        $st->execute();
        jsonResponse(true, 'Created', ['id' => (int)$conn->insert_id, 'image' => $relative], 201);
    }

    public function imageDestroy($imageId): void
    {
        global $conn;
        $iid = (int)$imageId;
        $st = $conn->prepare('SELECT image FROM product_images WHERE id = ?');
        $st->bind_param('i', $iid);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        if ($row && isset($row['image'])) {
            $file = __DIR__ . '/../' . $row['image'];
            if (is_file($file)) @unlink($file);
        }
        $del = $conn->prepare('DELETE FROM product_images WHERE id = ?');
        $del->bind_param('i', $iid);
        $del->execute();
        jsonResponse(true, 'Deleted', ['deleted' => true]);
    }

    public function imageSetPrimary($imageId): void
    {
        global $conn;
        $iid = (int)$imageId;
        $st = $conn->prepare('SELECT product_id FROM product_images WHERE id = ?');
        $st->bind_param('i', $iid);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        if (!$row) {
            jsonResponse(false, 'Not Found', null, 404);
            return;
        }
        $pid = (int)$row['product_id'];
        $off = $conn->prepare('UPDATE product_images SET is_primary = 0 WHERE product_id = ?');
        $off->bind_param('i', $pid);
        $off->execute();
        $on = $conn->prepare('UPDATE product_images SET is_primary = 1 WHERE id = ?');
        $on->bind_param('i', $iid);
        $on->execute();
        jsonResponse(true, 'Updated', ['updated' => true]);
    }

    public function store(): void
    {
        global $conn;
        $d = getJsonInput();
        $name = sanitize($d['name'] ?? '');
        $category_id = isset($d['category_id']) ? (int)$d['category_id'] : 0;
        $base_price = isset($d['base_price']) ? (float)$d['base_price'] : 0;
        $purchase_price = isset($d['purchase_price']) ? (float)$d['purchase_price'] : 0;
        if ($name === '' || $category_id <= 0 || $base_price <= 0 || $purchase_price <= 0) {
            jsonResponse(false, 'validation_error', ['fields' => ['name','category_id','base_price','purchase_price']], 422);
            return;
        }
        $slug = sanitize($d['slug'] ?? '');
        if ($slug === '') {
            $slug = generateSlug($name);
        }
        $code = sanitize($d['code'] ?? '');
        if ($code === '') {
            $code = strtoupper(randomString(10));
            $tries = 0;
            while ($tries < 5) {
                $chk = $conn->prepare('SELECT id FROM products WHERE code = ?');
                $chk->bind_param('s', $code);
                $chk->execute();
                if (!$chk->get_result()->fetch_assoc()) break;
                $code = strtoupper(randomString(10));
                $tries++;
            }
        }
        $brand_id = isset($d['brand_id']) ? (int)$d['brand_id'] : null;
        $subcategory_id = isset($d['subcategory_id']) ? (int)$d['subcategory_id'] : null;
        $short_description = isset($d['short_description']) ? sanitize($d['short_description']) : null;
        $long_description = isset($d['long_description']) ? sanitize($d['long_description']) : null;
        $is_active = (int)($d['is_active'] ?? 1);
        $is_featured = (int)($d['is_featured'] ?? 0);
        $st = $conn->prepare('INSERT INTO products (name, code, slug, brand_id, subcategory_id, category_id, short_description, long_description, base_price, purchase_price, is_active, is_featured) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $st->bind_param('sssiiissddii', $name, $code, $slug, $brand_id, $subcategory_id, $category_id, $short_description, $long_description, $base_price, $purchase_price, $is_active, $is_featured);
        if (!$st->execute()) {
            if ($conn->errno === 1062) {
                jsonResponse(false, 'Conflict', null, 409);
                return;
            }
            jsonResponse(false, 'Error', null, 500);
            return;
        }
        jsonResponse(true, 'Created', ['id' => (int)$conn->insert_id], 201);
    }

    public function update($id): void
    {
        global $conn;
        $id = (int)$id;
        $oldq = $conn->prepare('SELECT id, base_price FROM products WHERE id = ?');
        $oldq->bind_param('i', $id);
        $oldq->execute();
        $oldRow = $oldq->get_result()->fetch_assoc();
        if (!$oldRow) {
            jsonResponse(false, 'Not Found', null, 404);
            return;
        }
        $d = getJsonInput();
        $fields = [];
        $types = '';
        $params = [];
        $map = [
            'name' => 's',
            'code' => 's',
            'slug' => 's',
            'brand_id' => 'i',
            'subcategory_id' => 'i',
            'category_id' => 'i',
            'short_description' => 's',
            'long_description' => 's',
            'base_price' => 'd',
            'purchase_price' => 'd',
            'is_active' => 'i',
            'is_featured' => 'i'
        ];
        $willUpdateBase = array_key_exists('base_price', $d);
        foreach ($map as $key => $t) {
            if (array_key_exists($key, $d)) {
                $fields[] = "$key = ?";
                $types .= $t;
                if ($t === 's') {
                    $val = sanitize((string)$d[$key]);
                    if ($key === 'code' && $val === '') {
                        $val = strtoupper(randomString(10));
                    }
                    $params[] = $val;
                } elseif ($t === 'i') {
                    $params[] = (int)$d[$key];
                } else {
                    $params[] = (float)$d[$key];
                }
            }
        }
        if (!$fields) {
            jsonResponse(true, 'No changes', ['updated' => false]);
            return;
        }
        $sql = 'UPDATE products SET ' . implode(', ', $fields) . ' WHERE id = ?';
        $types .= 'i';
        $params[] = $id;
        $st = $conn->prepare($sql);
        $st->bind_param($types, ...$params);
        if (!$st->execute()) {
            if ($conn->errno === 1062) {
                jsonResponse(false, 'Conflict', null, 409);
                return;
            }
            jsonResponse(false, 'Error', null, 500);
            return;
        }
        if ($willUpdateBase) {
            $oldBase = isset($oldRow['base_price']) ? (float)$oldRow['base_price'] : null;
            $newBase = isset($d['base_price']) ? (float)$d['base_price'] : null;
            if ($oldBase !== null && $newBase !== null && $newBase != $oldBase) {
                $us = $conn->prepare('UPDATE product_specifications SET price = ? WHERE product_id = ? AND price = ?');
                $us->bind_param('dii', $newBase, $id, $oldBase);
                $us->execute();
            }
        }
        jsonResponse(true, 'Updated', ['updated' => true]);
    }

    public function destroy($id): void
    {
        global $conn;
        $id = (int)$id;
        $ref = $conn->prepare('SELECT COUNT(*) AS c FROM order_items WHERE product_id = ?');
        $ref->bind_param('i', $id);
        $ref->execute();
        $r = $ref->get_result()->fetch_assoc();
        $cnt = $r ? (int)$r['c'] : 0;
        if ($cnt > 0) {
            $upd = $conn->prepare('UPDATE products SET is_active = 0 WHERE id = ?');
            $upd->bind_param('i', $id);
            if ($upd->execute()) {
                jsonResponse(true, 'Archived (referenced by orders)', ['archived' => true, 'order_items' => $cnt]);
            } else {
                jsonResponse(false, 'Cannot archive', null, 500);
            }
            return;
        }
        try {
            $st = $conn->prepare('DELETE FROM products WHERE id = ?');
            $st->bind_param('i', $id);
            $st->execute();
            jsonResponse(true, 'Deleted', ['deleted' => true]);
        } catch (Throwable $e) {
            jsonResponse(false, 'Delete failed', ['detail' => $e->getMessage()], 500);
        }
    }
}
