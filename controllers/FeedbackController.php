<?php
class FeedbackController
{
    public function create($productId): void
    {
        global $conn;
        $pid = (int)$productId;
        $d = getJsonInput();
        foreach (['customer_name','rating'] as $k) { if (!isset($d[$k])) { jsonResponse(false, 'missing field ' . $k, null, 422); return; } }
        $name = sanitize($d['customer_name']);
        $rating = max(1, min(5, (int)$d['rating']));
        $comment = isset($d['comment']) ? sanitize($d['comment']) : null;
        $st = $conn->prepare('INSERT INTO feedback (product_id, customer_name, rating, comment, is_approved, is_active) VALUES (?, ?, ?, ?, ?, ?)');
        $approved = 0;
        $active = 1;
        $st->bind_param('isisii', $pid, $name, $rating, $comment, $approved, $active);
        if (!$st->execute()) {
            jsonResponse(false, 'Error', null, 500); return;
        }
        jsonResponse(true, 'Created', ['id' => (int)$conn->insert_id], 201);
    }

    public function list($productId): void
    {
        global $conn;
        $pid = (int)$productId;
        $st = $conn->prepare('SELECT id, customer_name, rating, comment, is_approved, created_at FROM feedback WHERE product_id = ? AND is_active = 1 ORDER BY id DESC LIMIT 200');
        $st->bind_param('i', $pid);
        $st->execute();
        $rows = [];
        $res = $st->get_result();
        while ($r = $res->fetch_assoc()) { $rows[] = $r; }
        jsonResponse(true, 'OK', ['data' => $rows]);
    }

    public function approve($id): void
    {
        global $conn;
        $fid = (int)$id;
        $st = $conn->prepare('UPDATE feedback SET is_approved = 1 WHERE id = ?');
        $st->bind_param('i', $fid);
        $st->execute();
        jsonResponse(true, 'Approved', ['approved' => true]);
    }

    public function unapprove($id): void
    {
        global $conn;
        $fid = (int)$id;
        $st = $conn->prepare('UPDATE feedback SET is_approved = 0 WHERE id = ?');
        $st->bind_param('i', $fid);
        $st->execute();
        jsonResponse(true, 'Unapproved', ['approved' => false]);
    }
}
