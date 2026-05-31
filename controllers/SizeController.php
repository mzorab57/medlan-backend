<?php
class SizeController
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
        $sql = "SELECT id, name FROM sizes $where ORDER BY id DESC LIMIT ? OFFSET ?";
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
        $stmt = $conn->prepare('SELECT id, name FROM sizes WHERE id = ?');
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
        if ($name === '') {
            jsonResponse(false, 'name required', null, 422);
            return;
        }
        $stmt = $conn->prepare('INSERT INTO sizes (name) VALUES (?)');
        $stmt->bind_param('s', $name);
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
        $chk = $conn->prepare('SELECT id FROM sizes WHERE id = ?');
        $chk->bind_param('i', $id);
        $chk->execute();
        if (!$chk->get_result()->fetch_assoc()) {
            jsonResponse(false, 'Not Found', null, 404);
            return;
        }
        $d = getJsonInput();
        if (!array_key_exists('name', $d)) {
            jsonResponse(true, 'No changes', ['updated' => false]);
            return;
        }
        $name = sanitize((string)$d['name']);
        $stmt = $conn->prepare('UPDATE sizes SET name = ? WHERE id = ?');
        $stmt->bind_param('si', $name, $id);
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
        $stmt = $conn->prepare('DELETE FROM sizes WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        jsonResponse(true, 'Deleted', ['deleted' => true]);
    }
}
