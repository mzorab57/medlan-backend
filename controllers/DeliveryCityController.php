<?php
class DeliveryCityController
{
    public function index(): void
    {
        global $conn;
        $activeOnly = isset($_GET['active']) ? sanitize($_GET['active']) : null;
        $where = '';
        if ($activeOnly !== null && $activeOnly !== '') {
            $where = 'WHERE is_active = 1';
        }
        $st = $conn->prepare("SELECT id, city_key, name, fee, is_active, created_at, updated_at FROM delivery_cities $where ORDER BY name ASC");
        $st->execute();
        $rows = [];
        $res = $st->get_result();
        while ($r = $res->fetch_assoc()) { $rows[] = $r; }
        jsonResponse(true, 'OK', ['data' => $rows]);
    }

    public function store(): void
    {
        global $conn;
        $d = getJsonInput();
        $city_key = sanitize($d['city_key'] ?? '');
        $name = sanitize($d['name'] ?? '');
        $fee = isset($d['fee']) ? (float)$d['fee'] : 0.0;
        $is_active = isset($d['is_active']) ? (int)$d['is_active'] : 1;
        if ($city_key === '' || $name === '') {
            jsonResponse(false, 'city_key and name required', null, 422);
            return;
        }
        $st = $conn->prepare('INSERT INTO delivery_cities (city_key, name, fee, is_active) VALUES (?, ?, ?, ?)');
        $st->bind_param('ssdi', $city_key, $name, $fee, $is_active);
        $st->execute();
        jsonResponse(true, 'Created', ['id' => (int)$conn->insert_id], 201);
    }

    public function update($id): void
    {
        global $conn;
        $cid = (int)$id;
        $row = $conn->prepare('SELECT id FROM delivery_cities WHERE id = ?');
        $row->bind_param('i', $cid);
        $row->execute();
        if (!$row->get_result()->fetch_assoc()) {
            jsonResponse(false, 'Not Found', null, 404);
            return;
        }
        $d = getJsonInput();
        $fields = [];
        $types = '';
        $params = [];
        $map = [
            'city_key' => 's',
            'name' => 's',
            'fee' => 'd',
            'is_active' => 'i',
        ];
        foreach ($map as $key => $t) {
            if (array_key_exists($key, $d)) {
                $fields[] = "$key = ?";
                $types .= $t;
                if ($t === 's') $params[] = sanitize((string)$d[$key]);
                elseif ($t === 'i') $params[] = (int)$d[$key];
                else $params[] = (float)$d[$key];
            }
        }
        if (!$fields) {
            jsonResponse(true, 'No changes', ['updated' => false]);
            return;
        }
        $sql = 'UPDATE delivery_cities SET ' . implode(', ', $fields) . ' WHERE id = ?';
        $types .= 'i';
        $params[] = $cid;
        $st = $conn->prepare($sql);
        $st->bind_param($types, ...$params);
        $st->execute();
        jsonResponse(true, 'Updated', ['updated' => true]);
    }

    public function destroy($id): void
    {
        global $conn;
        $cid = (int)$id;
        $st = $conn->prepare('DELETE FROM delivery_cities WHERE id = ?');
        $st->bind_param('i', $cid);
        $st->execute();
        jsonResponse(true, 'Deleted', ['deleted' => true]);
    }
}

