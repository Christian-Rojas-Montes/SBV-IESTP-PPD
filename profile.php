<?php
require __DIR__ . '/app.php';
requireLogin();

$db = db();
$user = currentUser();
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_password') {
    verifyCsrfToken();
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (!password_verify($currentPassword, $user['password'])) {
        setFlash('La contraseña actual no es correcta.');
        redirect('profile.php');
    }

    if (strlen($newPassword) < 10 || $newPassword !== $confirmPassword) {
        setFlash('La nueva contraseña debe tener al menos 10 caracteres y coincidir con la confirmación.');
        redirect('profile.php');
    }

    $db->prepare('UPDATE users SET password = ? WHERE id = ?')
        ->execute([password_hash($newPassword, PASSWORD_DEFAULT), $user['id']]);
    logAudit('Contraseña actualizada', 'El usuario actualizó sus credenciales');
    setFlash('La contraseña se actualizó correctamente.');
    redirect('profile.php');
}

$flash = getFlash();
$loanHistory = $db->prepare('SELECT l.*, b.title, b.author FROM loans l INNER JOIN books b ON b.id = l.book_id WHERE l.user_id = ? ORDER BY l.loan_date DESC');
$loanHistory->execute([$user['id']]);
$loanHistory = $loanHistory->fetchAll();

$reservations = $db->prepare('SELECT r.*, b.title FROM reservations r INNER JOIN books b ON b.id = r.book_id WHERE r.user_id = ? ORDER BY r.reservation_date DESC');
$reservations->execute([$user['id']]);
$reservations = $reservations->fetchAll();
$roleLabels = [
    'personal' => 'Personal',
    'docente' => 'Docente',
    'estudiante' => 'Estudiante',
];
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Perfil | Biblioteca Virtual</title>
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
                <?php if (hasRole('personal')): ?><a href="books.php">Inventario</a><?php endif; ?>
                <a href="loans.php">Préstamos</a>
                <?php if (hasRole('personal')): ?><a href="users.php">Usuarios</a><?php endif; ?>
                <?php if (hasRole('personal')): ?><a href="reports.php">Reportes</a><?php endif; ?>
                <?php if (hasRole('personal')): ?><a href="audit.php">Auditoría</a><?php endif; ?>
                <a class="active" href="profile.php">Mi perfil</a>
            </nav>
            <div class="user-box">
                <span><?= esc($user['full_name']) ?></span>
                <?php renderThemeToggle(); ?>
                <a href="logout.php" class="btn btn-light">Salir</a>
            </div>
        </header>

        <main class="container profile-layout">
            <?php if ($flash): ?><div class="alert info" role="status"><?= esc($flash) ?></div><?php endif; ?>
            <section class="panel profile-summary">
                <div class="panel-header">
                    <h3>Datos del usuario</h3>
                </div>
                <div class="profile-info">
                    <div class="avatar"><?= esc(substr($user['full_name'], 0, 1)) ?></div>
                    <div>
                        <h2><?= esc($user['full_name']) ?></h2>
                        <p><?= esc($user['email']) ?></p>
                        <span class="badge <?= $user['role'] === 'estudiante' ? 'physical' : 'digital' ?>"><?= esc($roleLabels[$user['role']] ?? $user['role']) ?></span>
                    </div>
                </div>
            </section>

            <section class="panel security-devices" data-webauthn-devices>
                <div class="panel-header">
                    <div>
                        <p class="eyebrow">WebAuthn / FIDO2</p>
                        <h3>Seguridad y Dispositivos</h3>
                    </div>
                    <button type="button" class="btn btn-primary" data-webauthn-register>Registrar este dispositivo</button>
                </div>
                <p class="field-help">Vincula una huella, llave de seguridad o passkey para autorizar inicios de sesión rápidos.</p>
                <p class="alert info" data-webauthn-message role="status" aria-live="polite" hidden></p>
                <div class="device-list" data-webauthn-device-list>
                    <p class="empty-state">Cargando dispositivos vinculados…</p>
                </div>
            </section>

            <section class="panel form-panel">
                <div class="panel-header"><h3>Seguridad de la cuenta</h3></div>
                <form method="post" action="profile.php" class="book-form">
                    <input type="hidden" name="csrf_token" value="<?= esc(csrfToken()) ?>">
                    <input type="hidden" name="action" value="change_password">
                    <div class="field-grid">
                        <label>Contraseña actual
                            <input type="password" name="current_password" autocomplete="current-password" required>
                        </label>
                        <label>Nueva contraseña
                            <input type="password" name="new_password" minlength="10" autocomplete="new-password" required>
                        </label>
                        <label>Confirmar nueva contraseña
                            <input type="password" name="confirm_password" minlength="10" autocomplete="new-password" required>
                        </label>
                    </div>
                    <div class="form-actions"><button type="submit" class="btn btn-primary">Actualizar contraseña</button></div>
                </form>
            </section>

            <section class="panel">
                <div class="panel-header">
                    <h3>Historial de préstamos</h3>
                </div>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Libro</th>
                            <th>Fecha</th>
                            <th>Entrega</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($loanHistory as $loan): ?>
                            <tr>
                                <td><?= esc($loan['title']) ?></td>
                                <td><?= esc(formatDate($loan['loan_date'])) ?></td>
                                <td><?= esc(formatDate($loan['due_date'])) ?></td>
                                <td><?= esc($loan['status']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </section>

            <section class="panel">
                <div class="panel-header">
                    <h3>Reservas activas</h3>
                </div>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Libro</th>
                            <th>Fecha reserva</th>
                            <th>Expira</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reservations as $reservation): ?>
                            <tr>
                                <td><?= esc($reservation['title']) ?></td>
                                <td><?= esc(formatDate($reservation['reservation_date'])) ?></td>
                                <td><?= esc(formatDate($reservation['expiry_date'])) ?></td>
                                <td><?= esc($reservation['status']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
        </main>
    </div>
</body>
</html>
