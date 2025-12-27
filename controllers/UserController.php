<?php
class UserController
{
    public function index(): void
    {
        $current = AuthMiddleware::requireAdmin();
        global $conn;
        $page = max(1, (int)($_GET['page'] ?? 1));
        $per = min(100, max(1, (int)($_GET['per_page'] ?? 20)));
        $offset = ($page - 1) * $per;
        $role = $_GET['role'] ?? null;
        $active = $_GET['active'] ?? null;
        $search = trim((string)($_GET['search'] ?? ''));
        $where = [];
        $types = '';
        $params = [];
        if ($role !== null) {
            $role = sanitize($role);
            $where[] = 'role = ?';
            $types .= 's';
            $params[] = $role;
        }
        if ($active !== null) {
            $where[] = 'is_active = ?';
            $types .= 'i';
            $params[] = (int)$active;
        }
        if ($search !== '') {
            $searchLike = '%' . $search . '%';
            $where[] = '(username LIKE ? OR email LIKE ? OR phone LIKE ?)';
            $types .= 'sss';
            $params[] = $searchLike;
            $params[] = $searchLike;
            $params[] = $searchLike;
        }
        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        $sql = "SELECT id, username, email, phone, role, is_active, last_login, created_at, updated_at FROM users $whereSql ORDER BY id DESC LIMIT ? OFFSET ?";
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
        $current = AuthMiddleware::requireAdmin();
        global $conn;
        $id = (int)$id;
        $stmt = $conn->prepare('SELECT id, username, email, phone, role, is_active, last_login, created_at, updated_at FROM users WHERE id = ?');
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
        $current = AuthMiddleware::requireAdmin();
        global $conn;
        $data = getJsonInput();
        $username = sanitize($data['username'] ?? '');
        $email = sanitize($data['email'] ?? '');
        $password = (string)($data['password'] ?? '');
        $phone = sanitize($data['phone'] ?? '');
        $role = sanitize($data['role'] ?? 'employee');
        $is_active = (int)($data['is_active'] ?? 1);
        if ($username === '' || $email === '') {
            jsonResponse(false, 'username و email پێویستن', null, 422);
            return;
        }
        if (!Validator::email($email)) {
            jsonResponse(false, 'ئیمەیڵ هەڵەیە', null, 422);
            return;
        }
        if (!in_array($role, ['admin','employee'], true)) {
            jsonResponse(false, 'ڕۆڵ هەڵەیە', null, 422);
            return;
        }
        $autoPwd = false;
        if ($password === '') {
            $password = randomString(12);
            $autoPwd = true;
        } elseif (mb_strlen($password, 'UTF-8') < 6) {
            jsonResponse(false, 'پاسوۆرد دەبێت کەمەند 6 پیت بێت', null, 422);
            return;
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare('INSERT INTO users (username, email, password, phone, role, is_active) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('sssssi', $username, $email, $hash, $phone, $role, $is_active);
        if (!$stmt->execute()) {
            if ($conn->errno === 1062) {
                jsonResponse(false, 'دووبارەبوونەوەی username/email', null, 409);
                return;
            }
            jsonResponse(false, 'هەڵەی ڕاگەیاندن', null, 500);
            return;
        }
        $resp = ['id' => (int)$conn->insert_id];
        if ($autoPwd) { $resp['initial_password'] = $password; }
        jsonResponse(true, 'دروستکرا', $resp, 201);
    }

    public function update($id): void
    {
        $current = AuthMiddleware::requireAdmin();
        global $conn;
        $id = (int)$id;
        $chk = $conn->prepare('SELECT id FROM users WHERE id = ?');
        $chk->bind_param('i', $id);
        $chk->execute();
        if (!$chk->get_result()->fetch_assoc()) {
            jsonResponse(false, 'Not Found', null, 404);
            return;
        }
        $data = getJsonInput();
        $fields = [];
        $types = '';
        $params = [];
        $map = [
            'username' => 's',
            'email' => 's',
            'phone' => 's',
            'role' => 's',
            'is_active' => 'i'
        ];
        foreach ($map as $key => $t) {
            if (array_key_exists($key, $data)) {
                if ($key === 'email' && !Validator::email((string)$data[$key])) {
                    jsonResponse(false, 'ئیمەیڵ هەڵەیە', null, 422);
                    return;
                }
                if ($key === 'role' && !in_array($data[$key], ['admin','employee'], true)) {
                    jsonResponse(false, 'ڕۆڵ هەڵەیە', null, 422);
                    return;
                }
                $fields[] = "$key = ?";
                $types .= $t;
                $params[] = $t === 's' ? sanitize((string)$data[$key]) : (int)$data[$key];
            }
        }
        if (array_key_exists('password', $data)) {
            $pwd = (string)$data['password'];
            if ($pwd !== '') {
                if (mb_strlen($pwd, 'UTF-8') < 6) {
                    jsonResponse(false, 'پاسوۆرد دەبێت کەمەند 6 پیت بێت', null, 422);
                    return;
                }
                $fields[] = "password = ?";
                $types .= 's';
                $params[] = password_hash($pwd, PASSWORD_DEFAULT);
            }
        }
        if (!$fields) {
            jsonResponse(true, 'No changes', ['updated' => false]);
            return;
        }
        $sql = 'UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?';
        $types .= 'i';
        $params[] = $id;
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        if (!$stmt->execute()) {
            if ($conn->errno === 1062) {
                jsonResponse(false, 'دووبارەبوونەوەی username/email', null, 409);
                return;
            }
            jsonResponse(false, 'هەڵەی ڕاگەیاندن', null, 500);
            return;
        }
        jsonResponse(true, 'نوێکرانەوە', ['updated' => true]);
    }

    public function destroy($id): void
    {
        $current = AuthMiddleware::requireAdmin();
        global $conn;
        $id = (int)$id;
        if ($current['user_id'] === $id) {
            jsonResponse(false, 'ناتوانیت خۆت بسڕیتەوە', null, 400);
            return;
        }
        $stmt = $conn->prepare('DELETE FROM users WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        jsonResponse(true, 'سڕاوەتەوە', ['deleted' => true]);
    }
}
