<?php
require __DIR__ . '/app.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

function sendCopyLookupResponse(int $statusCode, array $payload): never
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (!currentUser()) {
    sendCopyLookupResponse(401, ['error' => 'Debes iniciar sesión.']);
}
if (!hasRole('personal')) {
    sendCopyLookupResponse(403, ['error' => 'No tienes permiso para consultar ejemplares.']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendCopyLookupResponse(405, ['error' => 'Método no permitido.']);
}

$code = trim((string) ($_GET['code'] ?? ''));
if ($code === '' || strlen($code) > 100) {
    sendCopyLookupResponse(400, ['error' => 'Código QR no válido.']);
}

$pdo = db();
$stmt = $pdo->prepare('SELECT c.id, c.qr_code, c.status, c.created_at,
        b.id AS book_id, b.title, b.author, b.career, b.sede, b.type, b.copies_available
    FROM book_copies c
    INNER JOIN books b ON b.id = c.book_id
    WHERE c.qr_code = ?
    LIMIT 1');
$stmt->execute([$code]);
$copy = $stmt->fetch();
if (!$copy) {
    sendCopyLookupResponse(404, ['error' => 'No encontramos un ejemplar con ese código.']);
}

$loanStmt = $pdo->prepare("SELECT l.id, l.user_id, l.loan_date, l.pickup_date, l.due_date, l.status, u.full_name AS borrower_name
    FROM loans l
    INNER JOIN users u ON u.id = l.user_id
    WHERE l.copy_id = ? AND l.status IN ('reservado', 'prestado', 'vencido') AND l.return_date IS NULL
    ORDER BY CASE WHEN l.status = 'reservado' THEN 0 ELSE 1 END, l.loan_date DESC, l.id DESC
    LIMIT 1");
$loanStmt->execute([(int) $copy['id']]);
$loan = $loanStmt->fetch() ?: null;
if ($loan) {
    $loan['pickup_date_label'] = formatLoanDate($loan['pickup_date']);
    $loan['due_date_label'] = formatLoanDate($loan['due_date']);
}

sendCopyLookupResponse(200, ['copy' => $copy, 'loan' => $loan]);
