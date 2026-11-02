<?php
require __DIR__ . '/app.php';
requireLogin();

if (!hasRole('administrador')) {
    http_response_code(403);
    exit('No tienes permisos para administrar usuarios.');
}

$db = db();
$roles = [
    'administrador' => 'Administrador',
    'bibliotecario' => 'Bibliotecario',
    'docente' => 'Docente',
    'estudiante' => 'Estudiante',
];
$newRole = $_GET['new'] ?? 'estudiante';
if (!isset($roles[$newRole])) {
    $newRole = 'estudiante';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim($_POST['full_name'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $role = $_POST['role'] ?? '';
        $password = $_POST['password'] ?? '';

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !isset($roles[$role])) {
            setFlash('Verifica el nombre, el correo institucional y el rol seleccionado.');
            redirect($id > 0 ? 'users.php?edit=' . $id : 'users.php');
        }

        if ($id === 0 && strlen($password) < 10) {
            setFlash('La contraseña inicial debe tener al menos 10 caracteres.');
            redirect('users.php');
        }

        if ($password !== '' && strlen($password) < 10) {
            setFlash('La nueva contraseña debe tener al menos 10 caracteres.');
            redirect('users.php?edit=' . $id);
        }

        $existing = $db->prepare('SELECT id FROM users WHERE email = ? AND id != ?');
        $existing->execute([$email, $id]);
        if ($existing->fetch()) {
            setFlash('Ese correo ya está registrado en el sistema.');
            redirect($id > 0 ? 'users.php?edit=' . $id : 'users.php');
        }

        if ($id > 0) {
            $target = $db->prepare('SELECT id, role, is_active FROM users WHERE id = ?');
            $target->execute([$id]);
            $targetUser = $target->fetch();
            if (!$targetUser) {
                setFlash('No se encontró la cuenta que intentas editar.');
                redirect('users.php');
            }

            if ($id === (int) currentUser()['id'] && $role !== 'administrador') {
                setFlash('No puedes quitarte el rol de administrador desde tu propia cuenta.');
                redirect('users.php?edit=' . $id);
            }

            if ((int) $targetUser['is_active'] === 1 && $targetUser['role'] === 'administrador' && $role !== 'administrador') {
                $activeAdmins = (int) $db->query("SELECT COUNT(*) FROM users WHERE role = 'administrador' AND is_active = 1")->fetchColumn();
                if ($activeAdmins <= 1) {
                    setFlash('Debe permanecer al menos una cuenta de administrador activa.');
                    redirect('users.php?edit=' . $id);
                }
            }

            if ($password !== '') {
                $db->prepare('UPDATE users SET full_name = ?, email = ?, role = ?, password = ? WHERE id = ?')
                    ->execute([$name, $email, $role, password_hash($password, PASSWORD_DEFAULT), $id]);
            } else {
                $db->prepare('UPDATE users SET full_name = ?, email = ?, role = ? WHERE id = ?')
                    ->execute([$name, $email, $role, $id]);
            }

            logAudit('Cuenta de usuario actualizada', $email . ' · ' . $role);
            setFlash('La cuenta se actualizó correctamente.');
        } else {
            $db->prepare('INSERT INTO users (full_name, email, password, role) VALUES (?, ?, ?, ?)')
                ->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role]);
            logAudit('Cuenta de usuario creada', $email . ' · ' . $role);
            setFlash('La cuenta se creó correctamente.');
        }

        redirect('users.php');
    }

    if ($action === 'toggle') {
        $id = (int) ($_POST['id'] ?? 0);
        $targetStmt = $db->prepare('SELECT id, email, role, is_active FROM users WHERE id = ?');
        $targetStmt->execute([$id]);
        $target = $targetStmt->fetch();

        if (!$target) {
            setFlash('No se encontró la cuenta solicitada.');
            redirect('users.php');
        }

        $newStatus = (int) $target['is_active'] === 1 ? 0 : 1;
        if ($id === (int) currentUser()['id'] && $newStatus === 0) {
            setFlash('No puedes desactivar tu propia cuenta mientras tienes una sesión abierta.');
            redirect('users.php');
        }

        if ($newStatus === 0 && $target['role'] === 'administrador') {
            $activeAdmins = (int) $db->query("SELECT COUNT(*) FROM users WHERE role = 'administrador' AND is_active = 1")->fetchColumn();
            if ($activeAdmins <= 1) {
                setFlash('Debe permanecer al menos una cuenta de administrador activa.');
                redirect('users.php');
            }
        }

        $db->prepare('UPDATE users SET is_active = ? WHERE id = ?')->execute([$newStatus, $id]);
        logAudit($newStatus === 1 ? 'Cuenta activada' : 'Cuenta desactivada', $target['email']);
        setFlash($newStatus === 1 ? 'La cuenta se activó correctamente.' : 'La cuenta se desactivó correctamente.');
        redirect('users.php');
    }
}

$editingUser = null;
if (isset($_GET['edit'])) {
    $editId = (int) $_GET['edit'];
    $stmt = $db->prepare('SELECT id, full_name, email, role FROM users WHERE id = ?');
    $stmt->execute([$editId]);
    $editingUser = $stmt->fetch() ?: null;
}

$users = $db->query('SELECT id, full_name, email, role, is_active, created_at FROM users ORDER BY full_name ASC')->fetchAll();
$activeCount = (int) $db->query('SELECT COUNT(*) FROM users WHERE is_active = 1')->fetchColumn();
$flash = getFlash();
$user = currentUser();
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Usuarios | Biblioteca Virtual IESTP PPD</title>
    <link rel="stylesheet" href="assets/css/styles.css">
    <script src="assets/js/app.js" defer></script>
</head>
<body>
    <div class="page-shell">
        <header class="topbar">
            <a class="brand-wrap" href="dashboard.php">
                <div class="brand-mark">PPD</div>
                <div><strong>Biblioteca Institucional</strong><small>IESTP “Pedro P. Díaz” · Arequipa</small></div>
            </a>
            <nav class="main-nav" aria-label="Navegación principal">
                <a href="dashboard.php">Resumen</a>
                <a href="catalog.php">Catálogo</a>
                <a href="books.php">Inventario</a>
                <a href="loans.php">Préstamos</a>
                <a class="active" href="users.php">Usuarios</a>
                <a href="reports.php">Reportes</a>
            </nav>
            <div class="user-box">
                <span><?= esc($user['full_name']) ?></span>
                <?php renderThemeToggle(); ?>
                <a href="logout.php" class="btn btn-light">Cerrar sesión</a>
            </div>
        </header>

        <main class="container">
            <section class="page-heading">
                <div>
                    <p class="eyebrow">Administración institucional</p>
                    <h1>Usuarios y permisos</h1>
                    <p class="page-description">Gestiona las cuentas de estudiantes, docentes y personal autorizado.</p>
                </div>
                <a href="#user-form" class="btn btn-primary">＋ Crear usuario</a>
            </section>

            <?php if ($flash): ?>
                <div class="alert info" role="status"><?= esc($flash) ?></div>
            <?php endif; ?>

            <section class="stats-grid user-stats">
                <article class="stat-card accent"><span>Cuentas registradas</span><strong><?= count($users) ?></strong><small>Todos los perfiles institucionales</small></article>
                <article class="stat-card info"><span>Cuentas activas</span><strong><?= $activeCount ?></strong><small>Con acceso habilitado</small></article>
                <article class="stat-card"><span>Roles disponibles</span><strong><?= count($roles) ?></strong><small>Permisos diferenciados</small></article>
            </section>

            <section class="role-shortcuts" aria-label="Crear cuentas por perfil">
                <a class="btn btn-secondary" href="users.php?new=estudiante#user-form">＋ Estudiante</a>
                <a class="btn btn-secondary" href="users.php?new=docente#user-form">＋ Docente</a>
                <a class="btn btn-secondary" href="users.php?new=bibliotecario#user-form">＋ Bibliotecario</a>
                <a class="btn btn-secondary" href="users.php?new=administrador#user-form">＋ Administrador</a>
            </section>

            <section class="panel form-panel" id="user-form">
                <div class="panel-header">
                    <div>
                        <p class="eyebrow">Directorio de acceso</p>
                        <h2><?= $editingUser ? 'Editar cuenta institucional' : 'Crear cuenta institucional' ?></h2>
                    </div>
                </div>
                <form method="post" action="users.php" class="book-form">
                    <input type="hidden" name="csrf_token" value="<?= esc(csrfToken()) ?>">
                    <input type="hidden" name="action" value="save">
                    <?php if ($editingUser): ?><input type="hidden" name="id" value="<?= (int) $editingUser['id'] ?>"><?php endif; ?>
                    <div class="field-grid">
                        <label>Nombre completo
                            <input type="text" name="full_name" value="<?= esc($editingUser['full_name'] ?? '') ?>" autocomplete="name" required>
                        </label>
                        <label>Correo electrónico
                            <input type="email" name="email" value="<?= esc($editingUser['email'] ?? '') ?>" placeholder="usuario@iestppedroppdiaz.edu.pe" autocomplete="email" required>
                        </label>
                        <label>Perfil de acceso
                            <select name="role" required>
                                <?php foreach ($roles as $roleValue => $roleLabel): ?>
                                    <option value="<?= esc($roleValue) ?>" <?= ($editingUser['role'] ?? $newRole) === $roleValue ? 'selected' : '' ?>><?= esc($roleLabel) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label><?= $editingUser ? 'Nueva contraseña (opcional)' : 'Contraseña inicial' ?>
                            <input type="password" name="password" minlength="10" <?= $editingUser ? '' : 'required' ?> autocomplete="new-password" placeholder="Mínimo 10 caracteres">
                        </label>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary"><?= $editingUser ? 'Guardar cambios' : 'Crear cuenta' ?></button>
                        <?php if ($editingUser): ?><a href="users.php" class="btn btn-secondary">Cancelar edición</a><?php endif; ?>
                    </div>
                </form>
            </section>

            <section class="panel">
                <div class="panel-header">
                    <div><p class="eyebrow">Control de acceso</p><h2>Directorio de usuarios</h2></div>
                    <span class="table-count"><?= count($users) ?> cuentas</span>
                </div>
                <div class="table-scroll">
                    <table class="data-table">
                        <thead><tr><th>Nombre</th><th>Correo institucional</th><th>Perfil</th><th>Estado</th><th>Registro</th><th>Acciones</th></tr></thead>
                        <tbody>
                            <?php foreach ($users as $account): ?>
                                <tr>
                                    <td><strong><?= esc($account['full_name']) ?></strong></td>
                                    <td><?= esc($account['email']) ?></td>
                                    <td><span class="role-label"><?= esc($roles[$account['role']] ?? $account['role']) ?></span></td>
                                    <td><span class="status <?= (int) $account['is_active'] === 1 ? 'active' : 'resolved' ?>"><?= (int) $account['is_active'] === 1 ? 'Activo' : 'Inactivo' ?></span></td>
                                    <td><?= esc(formatDate($account['created_at'])) ?></td>
                                    <td class="action-cell">
                                        <a href="users.php?edit=<?= (int) $account['id'] ?>#user-form" class="btn btn-secondary btn-small">Editar</a>
                                        <form method="post" action="users.php" class="inline-form">
                                            <input type="hidden" name="csrf_token" value="<?= esc(csrfToken()) ?>">
                                            <input type="hidden" name="action" value="toggle">
                                            <input type="hidden" name="id" value="<?= (int) $account['id'] ?>">
                                            <button type="submit" class="btn <?= (int) $account['is_active'] === 1 ? 'btn-light' : 'btn-secondary' ?> btn-small" <?= (int) $account['id'] === (int) $user['id'] ? 'disabled' : '' ?>>
                                                <?= (int) $account['is_active'] === 1 ? 'Desactivar' : 'Activar' ?>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
        <footer class="site-footer">Sistema de Biblioteca Virtual · IESTP “Pedro P. Díaz”</footer>
    </div>
</body>
</html>
