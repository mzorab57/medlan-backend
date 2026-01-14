<?php
class ExpenseController
{
    public function index(): void
    {
        global $conn;
        $from = isset($_GET['from']) ? sanitize($_GET['from']) : null;
        $to = isset($_GET['to']) ? sanitize($_GET['to']) : null;
        $category = isset($_GET['category']) ? sanitize($_GET['category']) : null;
        $where = [];
        $types = '';
        $params = [];
        if ($from && $to) { $where[] = 'DATE(created_at) BETWEEN ? AND ?'; $types .= 'ss'; $params[] = $from; $params[] = $to; }
        elseif ($from) { $where[] = 'DATE(created_at) >= ?'; $types .= 's'; $params[] = $from; }
        elseif ($to) { $where[] = 'DATE(created_at) <= ?'; $types .= 's'; $params[] = $to; }
        if ($category) { $where[] = 'category = ?'; $types .= 's'; $params[] = $category; }
        $w = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        $sql = "SELECT id, title, amount, category, note, created_at FROM expenses $w ORDER BY id DESC LIMIT 500";
        $st = $conn->prepare($sql);
        if ($types !== '') { $st->bind_param($types, ...$params); }
        $st->execute();
        $res = $st->get_result();
        $rows = [];
        while ($r = $res->fetch_assoc()) { $rows[] = $r; }
        jsonResponse(true, 'OK', ['data' => $rows]);
    }

    public function create(): void
    {
        global $conn;
        $d = getJsonInput();
        $title = sanitize($d['title'] ?? '');
        $amount = isset($d['amount']) ? (float)$d['amount'] : 0.0;
        $category = sanitize($d['category'] ?? 'general');
        $note = isset($d['note']) ? sanitize($d['note']) : null;
        $date = isset($d['date']) ? sanitize($d['date']) : null;
        if ($title === '' || $amount <= 0) {
            jsonResponse(false, 'title and amount required', null, 422);
            return;
        }
        if ($date) {
            $st = $conn->prepare('INSERT INTO expenses (title, amount, category, note, created_at) VALUES (?, ?, ?, ?, ?)');
            $st->bind_param('sdsss', $title, $amount, $category, $note, $date);
        } else {
            $st = $conn->prepare('INSERT INTO expenses (title, amount, category, note) VALUES (?, ?, ?, ?)');
            $st->bind_param('sdss', $title, $amount, $category, $note);
        }
        $st->execute();
        jsonResponse(true, 'Created', ['id' => (int)$conn->insert_id], 201);
    }

    public function update($id): void
    {
        global $conn;
        $eid = (int)$id;
        $row = $conn->prepare('SELECT id FROM expenses WHERE id = ?');
        $row->bind_param('i', $eid);
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
            'title' => 's',
            'amount' => 'd',
            'category' => 's',
            'note' => 's',
            'created_at' => 's',
        ];
        foreach ($map as $key => $t) {
            if (array_key_exists($key, $d)) {
                $fields[] = "$key = ?";
                $types .= $t;
                if ($t === 's') $params[] = sanitize((string)$d[$key]);
                else $params[] = (float)$d[$key];
            }
        }
        if (!$fields) {
            jsonResponse(true, 'No changes', ['updated' => false]);
            return;
        }
        $sql = 'UPDATE expenses SET ' . implode(', ', $fields) . ' WHERE id = ?';
        $types .= 'i';
        $params[] = $eid;
        $st = $conn->prepare($sql);
        $st->bind_param($types, ...$params);
        $st->execute();
        jsonResponse(true, 'Updated', ['updated' => true]);
    }

    public function destroy($id): void
    {
        global $conn;
        $eid = (int)$id;
        $st = $conn->prepare('DELETE FROM expenses WHERE id = ?');
        $st->bind_param('i', $eid);
        $st->execute();
        jsonResponse(true, 'Deleted', ['deleted' => true]);
    }

    public function summary(): void
    {
        global $conn;
        $from = isset($_GET['from']) ? sanitize($_GET['from']) : null;
        $to = isset($_GET['to']) ? sanitize($_GET['to']) : null;
        if (!$from || !$to) { jsonResponse(false, 'from and to required', null, 422); return; }
        $st = $conn->prepare("SELECT DATE(created_at) AS day, COALESCE(SUM(amount),0) AS total FROM expenses WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY DATE(created_at) ORDER BY DATE(created_at)");
        $st->bind_param('ss', $from, $to);
        $st->execute();
        $rows = [];
        $res = $st->get_result();
        while ($r = $res->fetch_assoc()) { $rows[] = $r; }
        jsonResponse(true, 'OK', ['data' => $rows]);
    }
}

