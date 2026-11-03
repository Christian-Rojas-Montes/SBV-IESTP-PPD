<?php
require __DIR__ . '/app.php';

function sendPdfReaderJson(int $statusCode, array $payload): never
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function findDigitalPdf(PDO $pdo, int $bookId): ?array
{
    $stmt = $pdo->prepare("SELECT id, title, digital_path FROM books WHERE id = ? AND type = 'digital' LIMIT 1");
    $stmt->execute([$bookId]);
    $book = $stmt->fetch();
    if (!$book || !is_string($book['digital_path']) || trim($book['digital_path']) === '') {
        return null;
    }

    $storageRoot = realpath(__DIR__ . DIRECTORY_SEPARATOR . 'storage');
    if ($storageRoot === false) {
        return null;
    }

    $relativePath = str_replace('\\', '/', trim($book['digital_path']));
    if (str_contains($relativePath, "\0") || str_starts_with($relativePath, '/') || preg_match('/^[a-z]:/i', $relativePath)) {
        return null;
    }
    if (str_starts_with($relativePath, 'storage/')) {
        $relativePath = substr($relativePath, strlen('storage/'));
    }
    $segments = explode('/', $relativePath);
    if (in_array('..', $segments, true) || in_array('.', $segments, true) || in_array('', $segments, true)) {
        return null;
    }

    $candidate = realpath($storageRoot . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $segments));
    $storagePrefix = rtrim($storageRoot, '\\/') . DIRECTORY_SEPARATOR;
    if ($candidate === false
        || strncasecmp($candidate, $storagePrefix, strlen($storagePrefix)) !== 0
        || !is_file($candidate)
        || !is_readable($candidate)
        || strtolower(pathinfo($candidate, PATHINFO_EXTENSION)) !== 'pdf') {
        return null;
    }

    $book['absolute_file'] = $candidate;
    return $book;
}

function validReaderToken(string $token): bool
{
    return preg_match('/^[a-f0-9]{64}$/i', $token) === 1;
}

$user = currentUser();
if (!$user) {
    sendPdfReaderJson(401, ['error' => 'Inicia sesión para leer este recurso.']);
}

$pdo = db();
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = $method === 'POST' ? (string) ($_POST['action'] ?? '') : 'stream';
$bookId = (int) ($method === 'POST' ? ($_POST['book_id'] ?? 0) : ($_GET['id'] ?? 0));

if ($method === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!is_string($csrf) || !hash_equals(csrfToken(), $csrf)) {
        sendPdfReaderJson(403, ['error' => 'La sesión del formulario expiró. Recarga la página.']);
    }

    $token = (string) ($_POST['token'] ?? '');
    if (!validReaderToken($token) || $bookId < 1) {
        sendPdfReaderJson(400, ['error' => 'La sesión de lectura no es válida.']);
    }
    $tokenHash = hash('sha256', strtolower($token));

    if ($action === 'start') {
        $digitalBook = findDigitalPdf($pdo, $bookId);
        if (!$digitalBook) {
            sendPdfReaderJson(404, ['error' => 'Este recurso no tiene un PDF disponible.']);
        }
        $start = $pdo->prepare("INSERT INTO digital_read_sessions (user_id, token_hash, book_id, last_activity, expires_at)
            VALUES (?, ?, ?, datetime('now'), datetime('now', '+15 minutes'))
            ON CONFLICT(user_id) DO UPDATE SET
                token_hash = excluded.token_hash,
                book_id = excluded.book_id,
                last_activity = datetime('now'),
                expires_at = datetime('now', '+15 minutes')
            WHERE digital_read_sessions.expires_at <= datetime('now')
                OR digital_read_sessions.token_hash = excluded.token_hash");
        $start->execute([(int) $user['id'], $tokenHash, $bookId]);
        if ($start->rowCount() !== 1) {
            sendPdfReaderJson(409, ['error' => 'Ya hay una lectura digital activa en otra pestaña o navegador.']);
        }
        recordAuditLog((int) $user['id'], 'LECTURA_DIGITAL', json_encode([
            'book_id' => $bookId,
            'title' => $digitalBook['title'],
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '', $_SERVER['REMOTE_ADDR'] ?? null);
        sendPdfReaderJson(200, ['ok' => true, 'expires_in' => 900]);
    }

    if ($action === 'touch') {
        $touch = $pdo->prepare("UPDATE digital_read_sessions
            SET last_activity = datetime('now'), expires_at = datetime('now', '+15 minutes')
            WHERE user_id = ? AND token_hash = ? AND book_id = ? AND expires_at > datetime('now')");
        $touch->execute([(int) $user['id'], $tokenHash, $bookId]);
        if ($touch->rowCount() !== 1) {
            sendPdfReaderJson(409, ['error' => 'La sesión de lectura venció o fue abierta en otro navegador.']);
        }
        sendPdfReaderJson(200, ['ok' => true, 'expires_in' => 900]);
    }

    if ($action === 'end') {
        $end = $pdo->prepare('DELETE FROM digital_read_sessions WHERE user_id = ? AND token_hash = ? AND book_id = ?');
        $end->execute([(int) $user['id'], $tokenHash, $bookId]);
        sendPdfReaderJson(200, ['ok' => true]);
    }

    sendPdfReaderJson(400, ['error' => 'Acción de lectura no válida.']);
}

if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD, POST');
    sendPdfReaderJson(405, ['error' => 'Método no permitido.']);
}

$token = (string) ($_SERVER['HTTP_X_READER_TOKEN'] ?? '');
if (!validReaderToken($token) || $bookId < 1) {
    sendPdfReaderJson(403, ['error' => 'La sesión de lectura no es válida.']);
}
$tokenHash = hash('sha256', strtolower($token));
$lease = $pdo->prepare("SELECT 1 FROM digital_read_sessions
    WHERE user_id = ? AND token_hash = ? AND book_id = ? AND expires_at > datetime('now')");
$lease->execute([(int) $user['id'], $tokenHash, $bookId]);
if (!$lease->fetchColumn()) {
    sendPdfReaderJson(403, ['error' => 'La sesión de lectura venció.']);
}

$book = findDigitalPdf($pdo, $bookId);
if (!$book) {
    sendPdfReaderJson(404, ['error' => 'Este recurso no tiene un PDF disponible.']);
}
$filePath = $book['absolute_file'];
$fileSize = @filesize($filePath);
if ($fileSize === false || $fileSize < 1) {
    sendPdfReaderJson(404, ['error' => 'No se pudo abrir el documento.']);
}

$start = 0;
$end = $fileSize - 1;
$statusCode = 200;
$rangeHeader = trim((string) ($_SERVER['HTTP_RANGE'] ?? ''));
if ($rangeHeader !== '') {
    if (preg_match('/^bytes=(\d*)-(\d*)$/', $rangeHeader, $matches) !== 1 || ($matches[1] === '' && $matches[2] === '')) {
        http_response_code(416);
        header('Content-Range: bytes */' . $fileSize);
        exit;
    }

    if ($matches[1] === '') {
        $suffixLength = (int) $matches[2];
        if ($suffixLength < 1) {
            http_response_code(416);
            header('Content-Range: bytes */' . $fileSize);
            exit;
        }
        $start = max(0, $fileSize - $suffixLength);
    } else {
        $start = (int) $matches[1];
        if ($matches[2] !== '') {
            $end = min((int) $matches[2], $fileSize - 1);
        }
    }
    if ($start > $end || $start >= $fileSize) {
        http_response_code(416);
        header('Content-Range: bytes */' . $fileSize);
        exit;
    }
    $statusCode = 206;
}

$contentLength = $end - $start + 1;
http_response_code($statusCode);
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="lectura.pdf"');
header('Content-Length: ' . $contentLength);
header('Accept-Ranges: bytes');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cross-Origin-Resource-Policy: same-origin');
header('Cache-Control: private, no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
if ($statusCode === 206) {
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $fileSize);
}

session_write_close();
if ($method === 'HEAD') {
    exit;
}

while (ob_get_level() > 0) {
    ob_end_clean();
}
@ini_set('zlib.output_compression', 'Off');
$handle = @fopen($filePath, 'rb');
if ($handle === false) {
    exit;
}
fseek($handle, $start);
$remaining = $contentLength;
$nextLeaseCheck = microtime(true) + 5;
while ($remaining > 0 && !connection_aborted()) {
    if (microtime(true) >= $nextLeaseCheck) {
        $leaseCheck = $pdo->prepare("SELECT 1 FROM digital_read_sessions
            WHERE user_id = ? AND token_hash = ? AND book_id = ? AND expires_at > datetime('now')");
        $leaseCheck->execute([(int) $user['id'], $tokenHash, $bookId]);
        if (!$leaseCheck->fetchColumn()) {
            break;
        }
        $nextLeaseCheck = microtime(true) + 5;
    }
    $chunk = fread($handle, min(65536, $remaining));
    if ($chunk === false || $chunk === '') {
        break;
    }
    echo $chunk;
    $remaining -= strlen($chunk);
    flush();
}
fclose($handle);
