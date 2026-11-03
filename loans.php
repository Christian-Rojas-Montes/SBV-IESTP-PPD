<?php
require __DIR__ . '/app.php';
requireLogin();

$db = db();
$user = currentUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reserve') {
    verifyCsrfToken();

    $returnTo = (string) ($_POST['return_to'] ?? 'catalog.php');
    if ($returnTo !== 'catalog.php' && preg_match('/^detail\.php\?id=[0-9]+$/', $returnTo) !== 1) {
        $returnTo = 'catalog.php';
    }
    $failReservation = static function (string $message) use ($returnTo, $db): void {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        setFlash($message);
        redirect($returnTo);
    };

    $bookId = (int) ($_POST['book_id'] ?? 0);
    $bookStmt = $db->prepare('SELECT id, title, type, copies_available FROM books WHERE id = ?');
    $bookStmt->execute([$bookId]);
    $book = $bookStmt->fetch();
    if (!$book || $book['type'] !== 'fisico') {
        $failReservation('La reserva con fechas solo está disponible para libros físicos.');
    }
    if ((int) $book['copies_available'] < 1) {
        $failReservation('No quedan ejemplares disponibles para reservar.');
    }

    $accountStmt = $db->prepare('SELECT is_active FROM users WHERE id = ?');
    $accountStmt->execute([(int) $user['id']]);
    if (!(int) $accountStmt->fetchColumn()) {
        $failReservation('La cuenta no está activa.');
    }

    $blockReason = physicalReservationBlockReason($db, (int) $user['id']);
    if ($blockReason !== null) {
        $failReservation($blockReason);
    }

    $duplicate = $db->prepare("SELECT COUNT(*)
        FROM loans l
        LEFT JOIN book_copies c ON c.id = l.copy_id
        WHERE l.user_id = ? AND l.book_id = ? AND l.return_date IS NULL
            AND (
                (l.status = 'reservado' AND c.status = 'no_disponible')
                OR (l.status IN ('prestado', 'vencido') AND (l.copy_id IS NULL OR c.status = 'prestado'))
            )");
    $duplicate->execute([(int) $user['id'], $bookId]);
    if ((int) $duplicate->fetchColumn() > 0) {
        $failReservation('Ya tienes un ejemplar de este libro prestado o reservado.');
    }

    $timezone = new DateTimeZone(date_default_timezone_get());
    $nowLocal = new DateTimeImmutable('now', $timezone);
    $pickupInput = trim((string) ($_POST['pickup_date'] ?? ''));
    $dueInput = trim((string) ($_POST['due_date'] ?? ''));
    $parseDate = static function (string $value) use ($timezone): ?DateTimeImmutable {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->format('Y-m-d') !== $value) {
            return null;
        }

        return $date;
    };
    $pickupDay = $parseDate($pickupInput);
    $dueDay = $parseDate($dueInput);
    $minPickup = $nowLocal->modify('+1 day')->format('Y-m-d');
    $maxPickup = $nowLocal->modify('+48 hours')->format('Y-m-d');
    if (!$pickupDay || $pickupInput < $minPickup || $pickupInput > $maxPickup) {
        $failReservation('Selecciona una fecha de recojo entre mañana y las próximas 48 horas.');
    }
    $minDue = $pickupDay->modify('+1 day')->format('Y-m-d');
    $maxDue = $pickupDay->modify('+21 days')->format('Y-m-d');
    if (!$dueDay || $dueInput < $minDue || $dueInput > $maxDue) {
        $failReservation('La devolución debe programarse entre 1 y 21 días después del recojo.');
    }

    $utc = new DateTimeZone('UTC');
    $loanDate = $nowLocal->setTimezone($utc)->format('Y-m-d H:i:s');
    $pickupDate = $pickupDay->setTime(
        (int) $nowLocal->format('H'),
        (int) $nowLocal->format('i'),
        (int) $nowLocal->format('s')
    )->setTimezone($utc)->format('Y-m-d H:i:s');
    $dueDate = $dueDay->setTime(
        (int) $nowLocal->format('H'),
        (int) $nowLocal->format('i'),
        (int) $nowLocal->format('s')
    )->setTimezone($utc)->format('Y-m-d H:i:s');

    $db->beginTransaction();
    try {
        $blockReason = physicalReservationBlockReason($db, (int) $user['id']);
        if ($blockReason !== null) {
            $failReservation($blockReason);
        }
        $duplicate->execute([(int) $user['id'], $bookId]);
        if ((int) $duplicate->fetchColumn() > 0) {
            $failReservation('Ya tienes un ejemplar de este libro prestado o reservado.');
        }

        $copyStmt = $db->prepare("SELECT id FROM book_copies
            WHERE book_id = ? AND status = 'disponible' ORDER BY id LIMIT 1");
        $copyStmt->execute([$bookId]);
        $copyId = $copyStmt->fetchColumn();
        if ($copyId === false) {
            $db->rollBack();
            $failReservation('El último ejemplar acaba de ser reservado. Actualiza el catálogo.');
        }

        $claim = $db->prepare("UPDATE book_copies SET status = 'no_disponible' WHERE id = ? AND status = 'disponible'");
        $claim->execute([(int) $copyId]);
        $availability = $db->prepare('UPDATE books SET copies_available = copies_available - 1 WHERE id = ? AND copies_available > 0');
        $availability->execute([$bookId]);
        if ($claim->rowCount() !== 1 || $availability->rowCount() !== 1) {
            $db->rollBack();
            $failReservation('El ejemplar acaba de dejar de estar disponible. Actualiza el catálogo.');
        }

        $insert = $db->prepare("INSERT INTO loans (user_id, book_id, copy_id, loan_date, pickup_date, due_date, status, notes)
            VALUES (?, ?, ?, ?, ?, ?, 'reservado', 'Reserva física pendiente de entrega')");
        $insert->execute([(int) $user['id'], $bookId, (int) $copyId, $loanDate, $pickupDate, $dueDate]);
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }

    logAudit('RESERVA_LIBRO', $user['full_name'] . ' reservó ' . $book['title'] . ' · recojo ' . $pickupInput . ' · devolución ' . $dueInput);
    setFlash('Reserva registrada. Recoge el ejemplar dentro de las próximas 48 horas.');
    redirect($returnTo);
}

$requestAction = $_POST['action'] ?? null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $requestAction !== null) {
    verifyCsrfToken();
    $action = $requestAction;
    $bookId = (int) ($_POST['book_id'] ?? 0);

    if ($action === 'borrow_copy' && hasRole('personal')) {
        $copyId = (int) ($_POST['copy_id'] ?? 0);
        $borrowerId = (int) ($_POST['user_id'] ?? 0);
        $copyStmt = $db->prepare("SELECT c.id, c.book_id, c.status, b.title, b.copies_available
            FROM book_copies c INNER JOIN books b ON b.id = c.book_id
            WHERE c.id = ? AND b.type = 'fisico' LIMIT 1");
        $copyStmt->execute([$copyId]);
        $copyData = $copyStmt->fetch();
        $borrowerStmt = $db->prepare('SELECT id FROM users WHERE id = ? AND is_active = 1');
        $borrowerStmt->execute([$borrowerId]);

        if (!$copyData || $copyData['status'] !== 'disponible' || !$borrowerStmt->fetch()) {
            setFlash('El ejemplar ya no está disponible o la cuenta no está activa.');
            redirect('loans.php');
        }

        $blockReason = physicalReservationBlockReason($db, $borrowerId);
        if ($blockReason !== null) {
            setFlash($blockReason);
            redirect('loans.php');
        }

        $timezone = new DateTimeZone(date_default_timezone_get());
        $nowLocal = new DateTimeImmutable('now', $timezone);
        $dueInput = trim((string) ($_POST['due_date'] ?? ''));
        $dueDay = DateTimeImmutable::createFromFormat('!Y-m-d', $dueInput, $timezone);
        $dateErrors = DateTimeImmutable::getLastErrors();
        $minDue = $nowLocal->modify('+1 day')->format('Y-m-d');
        $maxDue = $nowLocal->modify('+21 days')->format('Y-m-d');
        if (!$dueDay || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
            || $dueDay->format('Y-m-d') !== $dueInput || $dueInput < $minDue || $dueInput > $maxDue) {
            setFlash('La devolución debe programarse entre 1 y 21 días después del recojo inmediato.');
            redirect('loans.php');
        }
        $utc = new DateTimeZone('UTC');
        $loanDate = $nowLocal->setTimezone($utc)->format('Y-m-d H:i:s');
        $dueDate = $dueDay->setTime(
            (int) $nowLocal->format('H'),
            (int) $nowLocal->format('i'),
            (int) $nowLocal->format('s')
        )->setTimezone($utc)->format('Y-m-d H:i:s');

        $existing = $db->prepare("SELECT COUNT(*) FROM loans l
            LEFT JOIN book_copies c ON c.id = l.copy_id
            WHERE l.user_id = ? AND l.book_id = ? AND l.return_date IS NULL
                AND (
                    (l.status = 'reservado' AND c.status = 'no_disponible')
                    OR (l.status IN ('prestado', 'vencido') AND (l.copy_id IS NULL OR c.status = 'prestado'))
                )");
        $existing->execute([$borrowerId, (int) $copyData['book_id']]);
        if ((int) $existing->fetchColumn() > 0) {
            setFlash('El usuario ya tiene este libro prestado o reservado.');
            redirect('loans.php');
        }

        $db->beginTransaction();
        $blockReason = physicalReservationBlockReason($db, $borrowerId);
        if ($blockReason !== null) {
            $db->rollBack();
            setFlash($blockReason);
            redirect('loans.php');
        }
        $existing->execute([$borrowerId, (int) $copyData['book_id']]);
        if ((int) $existing->fetchColumn() > 0) {
            $db->rollBack();
            setFlash('El usuario ya tiene este libro prestado o reservado.');
            redirect('loans.php');
        }
        $claim = $db->prepare("UPDATE book_copies SET status = 'prestado' WHERE id = ? AND status = 'disponible'");
        $claim->execute([$copyId]);
        $updateCopies = $db->prepare('UPDATE books SET copies_available = copies_available - 1 WHERE id = ? AND copies_available > 0');
        $updateCopies->execute([(int) $copyData['book_id']]);
        if ($claim->rowCount() !== 1 || $updateCopies->rowCount() !== 1) {
            $db->rollBack();
            setFlash('El ejemplar ya no está disponible. Actualiza la página e inténtalo otra vez.');
            redirect('loans.php');
        }

        $loanStmt = $db->prepare("INSERT INTO loans (user_id, book_id, copy_id, loan_date, pickup_date, due_date, status, notes)
            VALUES (?, ?, ?, ?, ?, ?, 'prestado', 'Préstamo registrado mediante QR')");
        $loanStmt->execute([$borrowerId, (int) $copyData['book_id'], $copyId, $loanDate, $loanDate, $dueDate]);
        $db->commit();
        logAudit('PRESTAMO_REGISTRADO', getUserName($db, $borrowerId) . ' · QR · ' . $copyData['title'] . ' · ejemplar ' . $copyId);
        setFlash('Préstamo del ejemplar registrado correctamente.');
        redirect('loans.php');
    }

    if ($action === 'deliver_reservation' && hasRole('personal')) {
        $loanId = (int) ($_POST['loan_id'] ?? 0);
        $reservationStmt = $db->prepare("SELECT l.id, l.user_id, l.book_id, l.copy_id, l.status, l.loan_date, l.pickup_date, b.title, c.qr_code, c.status AS copy_status
            FROM loans l
            INNER JOIN books b ON b.id = l.book_id
            INNER JOIN book_copies c ON c.id = l.copy_id
            WHERE l.id = ? AND l.status = 'reservado'");
        $reservationStmt->execute([$loanId]);
        $reservation = $reservationStmt->fetch();
        if (!$reservation || $reservation['copy_status'] !== 'no_disponible') {
            setFlash('No encontramos una reserva pendiente para ese ejemplar.');
            redirect('loans.php');
        }

        $pickupDayLocal = empty($reservation['pickup_date'])
            ? date('Y-m-d')
            : (new DateTimeImmutable($reservation['pickup_date'], new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d');
        if ((new DateTimeImmutable('now'))->format('Y-m-d') < $pickupDayLocal) {
            setFlash('La entrega puede registrarse a partir de la fecha de recojo seleccionada: ' . $pickupDayLocal . '.');
            redirect('loans.php');
        }

        $notExpired = $db->prepare("SELECT CASE WHEN datetime(?, '+48 hours') > datetime('now') THEN 1 ELSE 0 END");
        $notExpired->execute([$reservation['loan_date']]);
        if (!(int) $notExpired->fetchColumn()) {
            expirePhysicalReservations($db);
            setFlash('La reserva venció porque pasaron 48 horas sin registrar la entrega.');
            redirect('loans.php');
        }
        if (userHasActiveSanction($db, (int) $reservation['user_id'])) {
            setFlash('No se puede entregar un ejemplar físico mientras el usuario tenga una sanción activa.');
            redirect('loans.php');
        }

        $db->beginTransaction();
        if (userHasActiveSanction($db, (int) $reservation['user_id'])) {
            $db->rollBack();
            setFlash('No se puede entregar un ejemplar físico mientras el usuario tenga una sanción activa.');
            redirect('loans.php');
        }
        $claim = $db->prepare("UPDATE book_copies SET status = 'prestado' WHERE id = ? AND status = 'no_disponible'");
        $claim->execute([(int) $reservation['copy_id']]);
        $deliver = $db->prepare("UPDATE loans SET status = 'prestado'
            WHERE id = ? AND status = 'reservado' AND datetime(loan_date, '+48 hours') > datetime('now')");
        $deliver->execute([$loanId]);
        if ($claim->rowCount() !== 1 || $deliver->rowCount() !== 1) {
            $db->rollBack();
            expirePhysicalReservations($db);
            setFlash('No se pudo entregar el ejemplar. Verifica que la reserva siga vigente.');
            redirect('loans.php');
        }
        $db->commit();
        logAudit('RESERVA_ENTREGADA', $reservation['title'] . ' · ejemplar ' . $reservation['qr_code']);
        setFlash('Entrega registrada. El préstamo ya está activo.');
        redirect('loans.php');
    }

    if ($action === 'borrow' && $bookId > 0) {
        $book = $db->prepare('SELECT * FROM books WHERE id = ?');
        $book->execute([$bookId]);
        $bookData = $book->fetch();

        if (!$bookData) {
            setFlash('No se encontró el libro solicitado.');
            redirect('catalog.php');
        }

        if ($bookData['type'] === 'fisico') {
            setFlash('Solicita el préstamo de un libro físico mediante el formulario de reserva del catálogo.');
            redirect('detail.php?id=' . $bookId);
        }

        if ((int) $bookData['copies_available'] <= 0) {
            setFlash('No hay copias disponibles para este título.');
            redirect('catalog.php');
        }

        $borrowerId = (int) $user['id'];

        if (hasRole('personal') && !empty($_POST['user_id'])) {
            $borrowerId = (int) $_POST['user_id'];
        }

        $borrower = $db->prepare('SELECT id FROM users WHERE id = ? AND is_active = 1');
        $borrower->execute([$borrowerId]);
        if (!$borrower->fetch()) {
            setFlash('La cuenta seleccionada no está activa.');
            redirect('loans.php');
        }

        $existing = $db->prepare("SELECT COUNT(*) FROM loans WHERE user_id = ? AND book_id = ? AND status IN ('prestado', 'vencido') AND return_date IS NULL");
        $existing->execute([$borrowerId, $bookId]);

        if ((int) $existing->fetchColumn() > 0) {
            setFlash('El usuario ya tiene este libro en préstamo activo.');
            redirect('loans.php');
        }

        $db->beginTransaction();
        $updateCopies = $db->prepare('UPDATE books SET copies_available = copies_available - 1 WHERE id = ? AND copies_available > 0');
        $updateCopies->execute([$bookId]);
        if ($updateCopies->rowCount() !== 1) {
            $db->rollBack();
            setFlash('No quedan ejemplares disponibles para este título.');
            redirect('catalog.php');
        }

        $stmt = $db->prepare('INSERT INTO loans (user_id, book_id, loan_date, due_date, status, notes) VALUES (?, ?, datetime("now"), datetime("now", "+14 days"), "prestado", "Préstamo digital generado desde el sistema")');
        $stmt->execute([$borrowerId, $bookId]);
        $db->commit();
        logAudit('PRESTAMO_REGISTRADO', getUserName($db, $borrowerId) . ' solicitó ' . getBookTitle($db, $bookId));
        setFlash('Préstamo registrado correctamente.');
        redirect('loans.php');
    }

    if ($action === 'return_copy' && hasRole('personal')) {
        $copyId = (int) ($_POST['copy_id'] ?? 0);
        $loanStmt = $db->prepare("SELECT l.id FROM loans l
            INNER JOIN book_copies c ON c.id = l.copy_id
            WHERE l.copy_id = ? AND l.status IN ('prestado', 'vencido') AND l.return_date IS NULL AND c.status = 'prestado'
            ORDER BY l.loan_date DESC, l.id DESC LIMIT 1");
        $loanStmt->execute([$copyId]);
        $loanId = (int) $loanStmt->fetchColumn();
        if ($loanId < 1) {
            setFlash('No encontramos un préstamo activo para este ejemplar.');
            redirect('loans.php');
        }

        $result = processLoanReturn($db, $loanId, (int) $user['id']);
        setFlash(!$result['ok']
            ? 'No se pudo registrar la devolución del ejemplar.'
            : ($result['late'] ? 'Devolución tardía registrada. Se aplicó una sanción de 14 días.' : 'Devolución del ejemplar registrada correctamente.'));
        redirect('loans.php');
    }

    if (($action === 'return' || $action === 'renew') && isset($_POST['loan_id'])) {
        $loanId = (int) $_POST['loan_id'];
        $loan = $db->prepare('SELECT l.*, b.type AS book_type, c.status AS copy_status FROM loans l INNER JOIN books b ON b.id = l.book_id LEFT JOIN book_copies c ON c.id = l.copy_id WHERE l.id = ?');
        $loan->execute([$loanId]);
        $loanData = $loan->fetch();

        $validLoanStatus = $action === 'renew'
            ? ($loanData && $loanData['status'] === 'prestado' && $loanData['return_date'] === null)
            : ($loanData && in_array($loanData['status'], ['prestado', 'vencido'], true) && $loanData['return_date'] === null
                && (empty($loanData['copy_id']) || $loanData['copy_status'] === 'prestado'));
        if (!$validLoanStatus || (!hasRole('personal') && (int) $loanData['user_id'] !== (int) $user['id'])) {
            setFlash('No se pudo procesar la operación para ese préstamo.');
            redirect('loans.php');
        }

        if ($action === 'return') {
            $result = processLoanReturn($db, $loanId, (int) $user['id']);
            setFlash(!$result['ok']
                ? 'No se pudo registrar la devolución.'
                : ($result['late'] ? 'Devolución tardía registrada. Se aplicó una sanción de 14 días.' : 'Devolución registrada correctamente.'));
        } elseif ($action === 'renew') {
            if ($loanData['book_type'] === 'fisico' && userHasActiveSanction($db, (int) $loanData['user_id'])) {
                setFlash('No se puede renovar un préstamo físico mientras la sanción esté activa.');
                redirect('loans.php');
            }
            $renew = $db->prepare("UPDATE loans SET due_date = datetime(due_date, '+7 days')
                WHERE id = ? AND status = 'prestado' AND return_date IS NULL
                    AND (book_id IN (SELECT id FROM books WHERE type = 'digital')
                        OR NOT EXISTS (SELECT 1 FROM users u WHERE u.id = loans.user_id
                            AND u.status_until IS NOT NULL AND julianday(u.status_until) > julianday('now')))
                    AND julianday(datetime(due_date, '+7 days')) <= julianday(COALESCE(pickup_date, loan_date), '+21 days')");
            $renew->execute([$loanId]);
            if ($renew->rowCount() !== 1) {
                setFlash("No se puede renovar: l\u{00ED}mite de 21 d\u{00ED}as alcanzado.");
                redirect('loans.php');
            }
            logAudit('PRESTAMO_RENOVADO', getUserName($db, $loanData['user_id']) . ' renovó ' . getBookTitle($db, $loanData['book_id']));
            setFlash('Préstamo renovado por 7 días.');
        }

        redirect('loans.php');
    }
} elseif (isset($_GET['action'])) {
    redirect('loans.php');
}

if (hasRole('personal')) {
    $loans = $db->query('SELECT l.*, u.full_name, b.title, b.author, c.qr_code, c.status AS copy_status FROM loans l INNER JOIN users u ON u.id = l.user_id INNER JOIN books b ON b.id = l.book_id LEFT JOIN book_copies c ON c.id = l.copy_id ORDER BY l.loan_date DESC')->fetchAll();
} else {
    $loans = $db->prepare('SELECT l.*, u.full_name, b.title, b.author, c.qr_code, c.status AS copy_status FROM loans l INNER JOIN users u ON u.id = l.user_id INNER JOIN books b ON b.id = l.book_id LEFT JOIN book_copies c ON c.id = l.copy_id WHERE l.user_id = ? ORDER BY l.loan_date DESC');
    $loans->execute([$user['id']]);
    $loans = $loans->fetchAll();
}

$users = $db->query('SELECT id, full_name, role FROM users WHERE is_active = 1 ORDER BY full_name ASC')->fetchAll();
$digitalBooks = $db->query("SELECT id, title FROM books WHERE type = 'digital' ORDER BY title ASC")->fetchAll();
$directDueWindow = new DateTimeImmutable('now');
$directDueMin = $directDueWindow->modify('+1 day')->format('Y-m-d');
$directDueMax = $directDueWindow->modify('+21 days')->format('Y-m-d');
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Préstamos | Biblioteca Virtual</title>
    <link rel="stylesheet" href="assets/css/styles.css">
    <script src="assets/js/app.js" defer></script>
    <?php if (hasRole('personal')): ?>
        <script src="https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js" defer></script>
        <script src="assets/js/loan-scanner.js" defer></script>
    <?php endif; ?>
</head>
<body>
    <div class="page-shell">
        <header class="topbar">
            <div class="brand-wrap">
                <div class="brand-mark">PPD</div>
                <div>
                    <strong>Biblioteca Institucional</strong>
                    <small>IESTP “Pedro P. Díaz” · Arequipa</small>
                </div>
            </div>
            <nav class="main-nav">
                <a href="dashboard.php">Resumen</a>
                <a href="catalog.php">Catálogo</a>
                <?php if (hasRole('personal')): ?><a href="books.php">Inventario</a><?php endif; ?>
                <a class="active" href="loans.php">Préstamos</a>
                <?php if (hasRole('personal')): ?><a href="users.php">Usuarios</a><?php endif; ?>
                <?php if (hasRole('personal')): ?><a href="reports.php">Reportes</a><?php endif; ?>
                <?php if (hasRole('personal')): ?><a href="audit.php">Auditoría</a><?php endif; ?>
            </nav>
            <div class="user-box">
                <span><?= esc($user['full_name']) ?></span>
                <?php renderThemeToggle(); ?>
                <a href="profile.php" class="btn btn-light">Mi perfil</a>
                <a href="logout.php" class="btn btn-light">Salir</a>
            </div>
        </header>

        <main class="container">
            <div class="page-heading">
                <div>
                    <p class="eyebrow">Operaciones</p>
                    <h1>Gestión de préstamos</h1>
                </div>
            </div>

            <?php $message = getFlash(); if ($message): ?>
                <div class="alert info"><?= esc($message) ?></div>
            <?php endif; ?>

            <?php if (hasRole('personal')): ?>
                <section class="panel qr-scanner-panel" data-copy-scanner data-lookup-url="copy_lookup.php">
                    <div class="panel-header">
                        <div>
                            <p class="eyebrow">Inventario por ejemplar</p>
                            <h2>Escanear código QR</h2>
                        </div>
                        <div class="scanner-controls">
                            <button type="button" class="btn btn-primary" data-start-scanner>Activar cámara</button>
                            <button type="button" class="btn btn-secondary" data-stop-scanner hidden>Detener</button>
                            <button type="button" class="btn btn-secondary" data-reset-scanner hidden>Escanear otro ejemplar</button>
                        </div>
                    </div>
                    <p class="field-help">La cámara requiere permiso del navegador y una conexión segura HTTPS (o localhost).</p>
                    <div id="copy-qr-reader" class="qr-reader"></div>
                    <div class="scanner-result" data-scanner-status role="status" aria-live="polite">Activa la cámara y apunta al QR de un ejemplar.</div>
                    <div class="scanner-result" data-copy-result hidden></div>
                    <form method="post" action="loans.php" class="inline-fields" data-copy-borrow-form hidden>
                        <input type="hidden" name="csrf_token" value="<?= esc(csrfToken()) ?>">
                        <input type="hidden" name="action" value="borrow_copy">
                        <input type="hidden" name="copy_id" value="">
                        <label>Prestar a
                            <select name="user_id" required>
                                <?php foreach ($users as $u): ?>
                                    <option value="<?= (int) $u['id'] ?>"><?= esc($u['full_name']) ?> (<?= esc($u['role']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>Devolución límite (recojo hoy)
                            <input type="date" name="due_date" min="<?= esc($directDueMin) ?>" max="<?= esc($directDueMax) ?>" required>
                        </label>
                        <button type="submit" class="btn btn-primary">Registrar préstamo</button>
                    </form>
                    <form method="post" action="loans.php" data-copy-return-form hidden>
                        <input type="hidden" name="csrf_token" value="<?= esc(csrfToken()) ?>">
                        <input type="hidden" name="action" value="return_copy">
                        <input type="hidden" name="copy_id" value="">
                        <button type="submit" class="btn btn-primary">Registrar devolución</button>
                    </form>
                    <form method="post" action="loans.php" data-copy-deliver-form hidden>
                        <input type="hidden" name="csrf_token" value="<?= esc(csrfToken()) ?>">
                        <input type="hidden" name="action" value="deliver_reservation">
                        <input type="hidden" name="loan_id" value="">
                        <button type="submit" class="btn btn-primary">Marcar como entregado</button>
                    </form>
                </section>

                <div class="panel form-panel">
                    <div class="panel-header">
                        <h3>Registrar acceso digital</h3>
                    </div>
                    <?php if ($digitalBooks): ?>
                        <form method="post" action="loans.php?action=borrow">
                            <input type="hidden" name="csrf_token" value="<?= esc(csrfToken()) ?>">
                            <input type="hidden" name="action" value="borrow">
                            <div class="inline-fields">
                                <label>
                                    Usuario
                                    <select name="user_id">
                                        <?php foreach ($users as $u): ?>
                                            <option value="<?= (int) $u['id'] ?>"><?= esc($u['full_name']) ?> (<?= esc($u['role']) ?>)</option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label>
                                    Recurso digital
                                    <select name="book_id">
                                        <?php foreach ($digitalBooks as $book): ?>
                                            <option value="<?= (int) $book['id'] ?>"><?= esc($book['title']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                            </div>
                            <button type="submit" class="btn btn-primary">Guardar acceso digital</button>
                        </form>
                    <?php else: ?>
                        <p class="field-help">No hay recursos digitales registrados.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="panel">
                <div class="panel-header">
                    <h3>Listado de préstamos</h3>
                </div>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Usuario</th>
                            <th>Libro</th>
                            <th>Ejemplar QR</th>
                            <th>Solicitud / préstamo</th>
                            <th>Fecha recojo</th>
                            <th>Devolución límite</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($loans as $loan): ?>
                            <tr>
                                <td><?= esc($loan['full_name']) ?></td>
                                <td><?= esc($loan['title']) ?></td>
                                <td><?= !empty($loan['qr_code']) ? esc($loan['qr_code']) : '—' ?></td>
                                <td><?= esc(formatLoanDate($loan['loan_date'])) ?></td>
                                <td><?= $loan['pickup_date'] ? esc(formatLoanDate($loan['pickup_date'])) : '—' ?></td>
                                <td><?= esc(formatLoanDate($loan['due_date'])) ?></td>
                                <td>
                                    <span class="status <?= in_array($loan['status'], ['prestado', 'reservado'], true) ? 'active' : 'resolved' ?>"><?= esc($loan['status']) ?></span>
                                </td>
                                <td class="action-cell">
                                    <?php $canReturn = in_array($loan['status'], ['prestado', 'vencido'], true)
                                        && $loan['return_date'] === null
                                        && (empty($loan['copy_id']) || $loan['copy_status'] === 'prestado'); ?>
                                    <?php if ($canReturn): ?>
                                        <?php if ($loan['status'] === 'prestado'): ?>
                                            <form method="post" action="loans.php" class="inline-form">
                                                <input type="hidden" name="csrf_token" value="<?= esc(csrfToken()) ?>">
                                                <input type="hidden" name="action" value="renew">
                                                <input type="hidden" name="loan_id" value="<?= (int) $loan['id'] ?>">
                                                <button type="submit" class="btn btn-secondary btn-small">Renovar</button>
                                            </form>
                                        <?php endif; ?>
                                        <form method="post" action="loans.php" class="inline-form">
                                            <input type="hidden" name="csrf_token" value="<?= esc(csrfToken()) ?>">
                                            <input type="hidden" name="action" value="return">
                                            <input type="hidden" name="loan_id" value="<?= (int) $loan['id'] ?>">
                                            <button type="submit" class="btn btn-primary btn-small">Devolver</button>
                                        </form>
                                    <?php else: ?>
                                        <span><?= $loan['status'] === 'reservado' ? 'Pendiente de entrega' : 'Finalizado' ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>
</body>
</html>
