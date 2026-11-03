<?php
require __DIR__ . '/app.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['suggestions' => []]);
    exit;
}

if (!currentUser()) {
    http_response_code(401);
    echo json_encode(['suggestions' => []]);
    exit;
}

$query = trim((string) ($_GET['q'] ?? ''));
$query = substr($query, 0, 120);
if (strlen($query) < 2) {
    echo json_encode(['suggestions' => []], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$terms = preg_split('/\s+/u', $query, -1, PREG_SPLIT_NO_EMPTY) ?: [$query];
$conditions = [];
$params = [];
foreach ($terms as $term) {
    $conditions[] = '(title LIKE ? OR author LIKE ? OR isbn LIKE ? OR category LIKE ? OR career LIKE ? OR tags LIKE ? OR description LIKE ?)';
    $like = '%' . $term . '%';
    array_push($params, $like, $like, $like, $like, $like, $like, $like);
}

$pdo = db();
$stmt = $pdo->prepare('SELECT id, title, author, career, sede, type
    FROM books
    WHERE ' . implode(' AND ', $conditions) . '
    ORDER BY CASE
        WHEN lower(title) = lower(?) THEN 0
        WHEN lower(author) = lower(?) THEN 1
        WHEN lower(COALESCE(isbn, "")) = lower(?) THEN 2
        ELSE 3
    END, title COLLATE NOCASE ASC
    LIMIT 8');
$stmt->execute(array_merge($params, [$query, $query, $query]));

echo json_encode(['suggestions' => $stmt->fetchAll()], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
