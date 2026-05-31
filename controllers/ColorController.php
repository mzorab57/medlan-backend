<?php
class ColorController
{
    public function index(): void
    {
        global $conn;
        $page = max(1, (int)($_GET['page'] ?? 1));
        $per = min(100, max(1, (int)($_GET['per_page'] ?? 20)));
        $offset = ($page - 1) * $per;
        $search = sanitize($_GET['search'] ?? '');
        $where = '';
        $types = '';
        $params = [];
        if ($search !== '') {
            $where = 'WHERE name LIKE ?';
            $types = 's';
            $params[] = '%' . $search . '%';
        }
        $sql = "SELECT id, name, hexa_number FROM colors $where ORDER BY id DESC LIMIT ? OFFSET ?";
        $types .= 'ii';
        $params[] = (int)$per;
        $params[] = (int)$offset;
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($r = $res->fetch_assoc()) { $rows[] = $r; }
        jsonResponse(true, 'OK', ['data' => $rows, 'page' => $page, 'per_page' => $per]);
    }

    public function show($id): void
    {
        global $conn;
        $id = (int)$id;
        $stmt = $conn->prepare('SELECT id, name, hexa_number FROM colors WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!$row) {
            jsonResponse(false, 'Not Found', null, 404);
            return;
        }
        jsonResponse(true, 'OK', $row);
    }

    public function store(): void
    {
        global $conn;
        $d = getJsonInput();
        $name = sanitize($d['name'] ?? '');
        $hex = sanitize($d['hexa_number'] ?? '');
        if ($name === '') {
            jsonResponse(false, 'name required', null, 422);
            return;
        }
        if ($hex !== '' && !preg_match('/^#?[0-9A-Fa-f]{6}$/', $hex)) {
            jsonResponse(false, 'invalid hex', null, 422);
            return;
        }
        if ($hex !== '' && $hex[0] !== '#') {
            $hex = '#' . $hex;
        }
        $stmt = $conn->prepare('INSERT INTO colors (name, hexa_number) VALUES (?, ?)');
        $stmt->bind_param('ss', $name, $hex);
        if (!$stmt->execute()) {
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
        $chk = $conn->prepare('SELECT id FROM colors WHERE id = ?');
        $chk->bind_param('i', $id);
        $chk->execute();
        if (!$chk->get_result()->fetch_assoc()) {
            jsonResponse(false, 'Not Found', null, 404);
            return;
        }
        $d = getJsonInput();
        $fields = [];
        $types = '';
        $params = [];
        if (array_key_exists('name', $d)) {
            $fields[] = 'name = ?';
            $types .= 's';
            $params[] = sanitize((string)$d['name']);
        }
        if (array_key_exists('hexa_number', $d)) {
            $hex = sanitize((string)$d['hexa_number']);
            if ($hex !== '' && !preg_match('/^#?[0-9A-Fa-f]{6}$/', $hex)) {
                jsonResponse(false, 'invalid hex', null, 422);
                return;
            }
            if ($hex !== '' && $hex[0] !== '#') {
                $hex = '#' . $hex;
            }
            $fields[] = 'hexa_number = ?';
            $types .= 's';
            $params[] = $hex;
        }
        if (!$fields) {
            jsonResponse(true, 'No changes', ['updated' => false]);
            return;
        }
        $sql = 'UPDATE colors SET ' . implode(', ', $fields) . ' WHERE id = ?';
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

    public function destroy($id): void
    {
        global $conn;
        $id = (int)$id;
        $stmt = $conn->prepare('DELETE FROM colors WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        jsonResponse(true, 'Deleted', ['deleted' => true]);
    }
}
