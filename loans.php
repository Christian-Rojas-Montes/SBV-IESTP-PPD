<?php
require __DIR__ . '/app.php';
requireLogin();

$db = db();
$user = currentUser();

$requestAction = $_POST['action'] ?? null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $requestAction !== null) {
    verifyCsrfToken();
    $action = $requestAction;
    $bookId = (int) ($_POST['book_id'] ?? 0);

    if ($action === 'borrow' && $bookId > 0) {
        $book = $db->prepare('SELECT * FROM books WHERE id = ?');
        $book->execute([$bookId]);
        $bookData = $book->fetch();

        if (!$bookData) {
            setFlash('No se encontró el libro solicitado.');
            redirect('catalog.php');
        }

        if ((int) $bookData['copies_available'] <= 0) {
            setFlash('No hay copias disponibles para este título.');
            redirect('catalog.php');
        }

        $borrowerId = (int) $user['id'];

        if (hasRole('administrador', 'bibliotecario') && !empty($_POST['user_id'])) {
            $borrowerId = (int) $_POST['user_id'];
        }

        $borrower = $db->prepare('SELECT id FROM users WHERE id = ? AND is_active = 1');
        $borrower->execute([$borrowerId]);
        if (!$borrower->fetch()) {
            setFlash('La cuenta seleccionada no está activa.');
            redirect('loans.php');
        }

        $existing = $db->prepare('SELECT COUNT(*) FROM loans WHERE user_id = ? AND book_id = ? AND status = "prestado"');
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

        $stmt = $db->prepare('INSERT INTO loans (user_id, book_id, loan_date, due_date, status, notes) VALUES (?, ?, date("now"), date("now", "+14 days"), "prestado", "Préstamo generado desde el sistema")');
        $stmt->execute([$borrowerId, $bookId]);
        $db->commit();
        logAudit('Préstamo registrado', getUserName($db, $borrowerId) . ' solicitó ' . getBookTitle($db, $bookId));
        setFlash('Préstamo registrado correctamente.');
        redirect('loans.php');
    }

    if (($action === 'return' || $action === 'renew') && isset($_POST['loan_id'])) {
        $loanId = (int) $_POST['loan_id'];
        $loan = $db->prepare('SELECT * FROM loans WHERE id = ?');
        $loan->execute([$loanId]);
        $loanData = $loan->fetch();

        if (!$loanData || $loanData['status'] !== 'prestado' || (!hasRole('administrador', 'bibliotecario') && (int) $loanData['user_id'] !== (int) $user['id'])) {
            setFlash('No se pudo procesar la operación para ese préstamo.');
            redirect('loans.php');
        }

        if ($action === 'return') {
            $db->beginTransaction();
            $updated = $db->prepare('UPDATE loans SET return_date = date("now"), status = "devuelto" WHERE id = ? AND status = "prestado"');
            $updated->execute([$loanId]);
            if ($updated->rowCount() === 1) {
                $db->prepare('UPDATE books SET copies_available = MIN(copies_total, copies_available + 1) WHERE id = ?')->execute([$loanData['book_id']]);
            }
            $db->commit();
            logAudit('Devolución registrada', getUserName($db, $loanData['user_id']) . ' devolvió ' . getBookTitle($db, $loanData['book_id']));
            setFlash('Devolución registrada correctamente.');
        } elseif ($action === 'renew') {
            $db->prepare('UPDATE loans SET due_date = date(due_date, "+7 days") WHERE id = ? AND status = "prestado"')->execute([$loanId]);
            logAudit('Renovación de préstamo', getUserName($db, $loanData['user_id']) . ' renovó ' . getBookTitle($db, $loanData['book_id']));
            setFlash('Préstamo renovado por 7 días.');
        }

        redirect('loans.php');
    }
} elseif (isset($_GET['action'])) {
    redirect('loans.php');
}

if (hasRole('administrador', 'bibliotecario')) {
    $loans = $db->query('SELECT l.*, u.full_name, b.title, b.author FROM loans l INNER JOIN users u ON u.id = l.user_id INNER JOIN books b ON b.id = l.book_id ORDER BY l.loan_date DESC')->fetchAll();
} else {
    $loans = $db->prepare('SELECT l.*, u.full_name, b.title, b.author FROM loans l INNER JOIN users u ON u.id = l.user_id INNER JOIN books b ON b.id = l.book_id WHERE l.user_id = ? ORDER BY l.loan_date DESC');
    $loans->execute([$user['id']]);
    $loans = $loans->fetchAll();
}

$users = $db->query('SELECT id, full_name, role FROM users WHERE is_active = 1 ORDER BY full_name ASC')->fetchAll();
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Préstamos | Biblioteca Virtual</title>
    <link rel="stylesheet" href="assets/css/styles.css">
    <script src="assets/js/app.js" defer></script>
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
                <?php if (hasRole('administrador', 'bibliotecario')): ?><a href="books.php">Inventario</a><?php endif; ?>
                <a class="active" href="loans.php">Préstamos</a>
                <?php if (hasRole('administrador')): ?><a href="users.php">Usuarios</a><?php endif; ?>
                <?php if (hasRole('administrador', 'bibliotecario')): ?><a href="reports.php">Reportes</a><?php endif; ?>
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

            <?php if (hasRole('administrador', 'bibliotecario')): ?>
                <div class="panel form-panel">
                    <div class="panel-header">
                        <h3>Registrar préstamo</h3>
                    </div>
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
                                Libro
                                <select name="book_id">
                                    <?php foreach ($db->query('SELECT id, title FROM books ORDER BY title ASC')->fetchAll() as $book): ?>
                                        <option value="<?= (int) $book['id'] ?>"><?= esc($book['title']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        </div>
                        <button type="submit" class="btn btn-primary">Guardar préstamo</button>
                    </form>
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
                            <th>Fecha préstamo</th>
                            <th>Fecha entrega</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($loans as $loan): ?>
                            <tr>
                                <td><?= esc($loan['full_name']) ?></td>
                                <td><?= esc($loan['title']) ?></td>
                                <td><?= esc(formatDate($loan['loan_date'])) ?></td>
                                <td><?= esc(formatDate($loan['due_date'])) ?></td>
                                <td>
                                    <span class="status <?= $loan['status'] === 'prestado' ? 'active' : 'resolved' ?>"><?= esc($loan['status']) ?></span>
                                </td>
                                <td class="action-cell">
                                    <?php if ($loan['status'] === 'prestado'): ?>
                                        <form method="post" action="loans.php" class="inline-form">
                                            <input type="hidden" name="csrf_token" value="<?= esc(csrfToken()) ?>">
                                            <input type="hidden" name="action" value="renew">
                                            <input type="hidden" name="loan_id" value="<?= (int) $loan['id'] ?>">
                                            <button type="submit" class="btn btn-secondary btn-small">Renovar</button>
                                        </form>
                                        <form method="post" action="loans.php" class="inline-form">
                                            <input type="hidden" name="csrf_token" value="<?= esc(csrfToken()) ?>">
                                            <input type="hidden" name="action" value="return">
                                            <input type="hidden" name="loan_id" value="<?= (int) $loan['id'] ?>">
                                            <button type="submit" class="btn btn-primary btn-small">Devolver</button>
                                        </form>
                                    <?php else: ?>
                                        <span>Finalizado</span>
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
