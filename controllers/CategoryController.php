<?php
class CategoryController
{
    public function index(): void
    {
        global $conn;
        $page = max(1, (int)($_GET['page'] ?? 1));
        $per = min(100, max(1, (int)($_GET['per_page'] ?? 20)));
        $offset = ($page - 1) * $per;
        $active = $_GET['active'] ?? null;
        $where = '';
        $types = '';
        $params = [];
        if ($active !== null) {
            $where = 'WHERE is_active = ?';
            $types .= 'i';
            $params[] = (int)$active;
        }
        $sql = "SELECT id, name, slug, image, display_order, is_active, created_at, updated_at FROM categories $where ORDER BY display_order, id LIMIT ? OFFSET ?";
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
        $stmt = $conn->prepare('SELECT id, name, slug, image, display_order, is_active, created_at, updated_at FROM categories WHERE id = ?');
        $id = (int)$id;
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        if (!$row) {
            jsonResponse(false, 'Not Found', null, 404);
            return;
        }
        jsonResponse(true, 'OK', $row);
    }

    public function store(): void
    {
        global $conn;
        $data = getJsonInput();
        $name = sanitize($data['name'] ?? '');
        $slug = sanitize($data['slug'] ?? '');
        $image = sanitize($data['image'] ?? '');
        $display = (int)($data['display_order'] ?? 0);
        $active = (int)($data['is_active'] ?? 1);
        if ($name === '') {
            jsonResponse(false, 'name required', null, 422);
            return;
        }
        if ($slug === '') {
            $slug = generateSlug($name);
        }
        $stmt = $conn->prepare('INSERT INTO categories (name, slug, image, display_order, is_active) VALUES (?, ?, ?, ?, ?)');
        $stmt->bind_param('sssii', $name, $slug, $image, $display, $active);
        if (!$stmt->execute()) {
            if ($conn->errno === 1062) {
                jsonResponse(false, 'Conflict', ['detail' => 'slug exists'], 409);
                return;
            }
            jsonResponse(false, 'Error', null, 500);
            return;
        }
        $id = $conn->insert_id;
        jsonResponse(true, 'Created', ['id' => (int)$id], 201);
    }

    public function update($id): void
    {
        global $conn;
        $id = (int)$id;
        $check = $conn->prepare('SELECT id FROM categories WHERE id = ?');
        $check->bind_param('i', $id);
        $check->execute();
        $exists = $check->get_result()->fetch_assoc();
        if (!$exists) {
            jsonResponse(false, 'Not Found', null, 404);
            return;
        }
        $data = getJsonInput();
        $fields = [];
        $types = '';
        $params = [];
        $map = [
            'name' => 's',
            'slug' => 's',
            'image' => 's',
            'display_order' => 'i',
            'is_active' => 'i'
        ];
        foreach ($map as $key => $t) {
            if (array_key_exists($key, $data)) {
                $fields[] = "$key = ?";
                $types .= $t;
                if ($t === 's') {
                    $params[] = sanitize((string)$data[$key]);
                } else {
                    $params[] = (int)$data[$key];
                }
            }
        }
        if (!$fields) {
            jsonResponse(true, 'No changes', ['updated' => false]);
            return;
        }
        $sql = 'UPDATE categories SET ' . implode(', ', $fields) . ' WHERE id = ?';
        $types .= 'i';
        $params[] = $id;
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        if (!$stmt->execute()) {
            if ($conn->errno === 1062) {
                jsonResponse(false, 'Conflict', null, 409);
                return;
            }
            jsonResponse(false, 'Error', null, 500);
            return;
        }
        jsonResponse(true, 'Updated', ['updated' => true]);
    }

    public function imageUpload($id): void
    {
        global $conn;
        $cid = (int)$id;
        $chk = $conn->prepare('SELECT id FROM categories WHERE id = ?');
        $chk->bind_param('i', $cid);
        $chk->execute();
        if (!$chk->get_result()->fetch_assoc()) {
            jsonResponse(false, 'Not Found', null, 404);
            return;
        }
        if (!isset($_FILES['image'])) {
            jsonResponse(false, 'image file required', null, 422);
            return;
        }
        $max = getenv('MAX_FILE_SIZE') ? (int)getenv('MAX_FILE_SIZE') : 5242880;
        $uploadBase = getenv('UPLOAD_PATH') ?: 'uploads/';
        $destDir = __DIR__ . '/../' . rtrim($uploadBase, '/\\') . '/categories/';
        $newName = null;
        $ok = FileUpload::save('image', $destDir, ['jpg','jpeg','png','webp'], $max, $newName);
        if (!$ok[0]) {
            jsonResponse(false, $ok[1], null, 422);
            return;
        }
        $absolute = $ok[2];
        $relative = str_replace(realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR, '', realpath($absolute)) ?: (rtrim($uploadBase, '/\\') . '/categories/' . basename($absolute));
        $st = $conn->prepare('UPDATE categories SET image = ? WHERE id = ?');
        $st->bind_param('si', $relative, $cid);
        $st->execute();
        jsonResponse(true, 'Image uploaded', ['image' => $relative]);
    }

    public function destroy($id): void
    {
        global $conn;
        $id = (int)$id;
        $stmt = $conn->prepare('DELETE FROM categories WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        jsonResponse(true, 'Deleted', ['deleted' => true]);
    }
}
