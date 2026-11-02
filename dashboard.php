<?php
require __DIR__ . '/app.php';
requireLogin();

$db = db();
$isStaff = hasRole('administrador', 'bibliotecario');
$totalBooks = (int) $db->query('SELECT COUNT(*) FROM books')->fetchColumn();
$availableBooks = (int) $db->query('SELECT SUM(copies_available) FROM books')->fetchColumn();
$activeLoansQuery = $isStaff
    ? $db->query('SELECT COUNT(*) FROM loans WHERE status = "prestado"')
    : $db->prepare('SELECT COUNT(*) FROM loans WHERE status = "prestado" AND user_id = ?');
if ($isStaff) {
    $activeLoans = (int) $activeLoansQuery->fetchColumn();
    $overdue = (int) $db->query('SELECT COUNT(*) FROM loans WHERE status = "prestado" AND due_date < date("now")')->fetchColumn();
} else {
    $activeLoansQuery->execute([currentUser()['id']]);
    $activeLoans = (int) $activeLoansQuery->fetchColumn();
    $overdueQuery = $db->prepare('SELECT COUNT(*) FROM loans WHERE status = "prestado" AND due_date < date("now") AND user_id = ?');
    $overdueQuery->execute([currentUser()['id']]);
    $overdue = (int) $overdueQuery->fetchColumn();
}
$digitalBooks = (int) $db->query('SELECT COUNT(*) FROM books WHERE type = "digital"')->fetchColumn();
$recentLogs = $isStaff ? $db->query('SELECT a.*, u.full_name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.created_at DESC LIMIT 5')->fetchAll() : [];
$topBooks = $db->query('SELECT b.title, COUNT(l.id) AS prestamos FROM books b LEFT JOIN loans l ON l.book_id = b.id GROUP BY b.id ORDER BY prestamos DESC LIMIT 5')->fetchAll();
$user = currentUser();
$roleMap = ['administrador' => 'Administrador', 'bibliotecario' => 'Bibliotecario', 'docente' => 'Docente', 'estudiante' => 'Estudiante'];
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | Biblioteca Virtual</title>
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
                <a class="active" href="dashboard.php">Resumen</a>
                <a href="catalog.php">Catálogo</a>
                <?php if (hasRole('administrador', 'bibliotecario')): ?><a href="books.php">Inventario</a><?php endif; ?>
                <a href="loans.php">Préstamos</a>
                <?php if (hasRole('administrador')): ?><a href="users.php">Usuarios</a><?php endif; ?>
                <?php if (hasRole('administrador', 'bibliotecario')): ?><a href="reports.php">Reportes</a><?php endif; ?>
            </nav>

            <div class="user-box">
                <span><strong><?= esc($user['full_name']) ?></strong><small class="user-role"><?= esc($roleMap[$user['role']]) ?></small></span>
                <?php renderThemeToggle(); ?>
                <a href="profile.php" class="btn btn-light">Mi perfil</a>
                <a href="logout.php" class="btn btn-light">Salir</a>
            </div>
        </header>

        <main class="container">
            <div class="page-heading">
                <div>
                    <p class="eyebrow">Sistema de gestión bibliotecaria</p>
                    <h1>Resumen institucional</h1>
                    <p class="page-description">Consulta el estado del catálogo, los préstamos y la actividad bibliotecaria.</p>
                </div>
            </div>

            <?php if (hasRole('administrador', 'bibliotecario')): ?>
                <section class="quick-actions" aria-label="Accesos rápidos">
                    <a class="quick-action" href="books.php"><span class="quick-icon">＋</span><span><strong>Registrar material</strong><small>Agregar un libro al inventario</small></span><b>→</b></a>
                    <a class="quick-action" href="loans.php"><span class="quick-icon">↗</span><span><strong>Nuevo préstamo</strong><small>Registrar una operación bibliotecaria</small></span><b>→</b></a>
                    <?php if (hasRole('administrador')): ?><a class="quick-action" href="users.php"><span class="quick-icon">♙</span><span><strong>Crear usuario</strong><small>Administrar cuentas y permisos</small></span><b>→</b></a><?php endif; ?>
                    <a class="quick-action" href="reports.php"><span class="quick-icon">▤</span><span><strong>Ver reportes</strong><small>Revisar indicadores y morosidad</small></span><b>→</b></a>
                </section>
            <?php else: ?>
                <section class="quick-actions" aria-label="Accesos rápidos">
                    <a class="quick-action" href="catalog.php"><span class="quick-icon">⌕</span><span><strong>Explorar catálogo</strong><small>Buscar material para tus estudios</small></span><b>→</b></a>
                    <a class="quick-action" href="loans.php"><span class="quick-icon">▣</span><span><strong>Mis préstamos</strong><small>Consultar tus préstamos activos</small></span><b>→</b></a>
                    <a class="quick-action" href="profile.php"><span class="quick-icon">♙</span><span><strong>Mi perfil</strong><small>Revisar tu historial bibliotecario</small></span><b>→</b></a>
                </section>
            <?php endif; ?>

            <section class="stats-grid">
                <article class="stat-card">
                    <span>Total de títulos</span>
                    <strong><?= $totalBooks ?></strong>
                    <small>Material bibliográfico</small>
                </article>
                <article class="stat-card accent">
                    <span>Disponibles</span>
                    <strong><?= $availableBooks ?></strong>
                    <small>Ejemplares activos</small>
                </article>
                <article class="stat-card warning">
                    <span>Préstamos activos</span>
                    <strong><?= $activeLoans ?></strong>
                    <small>En curso</small>
                </article>
                <article class="stat-card danger">
                    <span>Morosos</span>
                    <strong><?= $overdue ?></strong>
                    <small>Fuera de plazo</small>
                </article>
                <article class="stat-card info">
                    <span>Digitales</span>
                    <strong><?= $digitalBooks ?></strong>
                    <small>Recursos en línea</small>
                </article>
            </section>

            <section class="content-grid <?= $isStaff ? 'two-columns' : '' ?>">
                <div class="panel">
                    <div class="panel-header">
                        <h3>Material más consultado</h3>
                    </div>
                    <table class="data-table">
                        <thead><tr><th>Material</th><th>Préstamos</th></tr></thead>
                        <tbody>
                            <?php foreach ($topBooks as $book): ?>
                                <tr><td><?= esc($book['title']) ?></td><td><?= (int) $book['prestamos'] ?></td></tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($isStaff): ?>
                    <div class="panel">
                        <div class="panel-header"><h3>Actividad reciente</h3></div>
                        <ul class="activity-list">
                            <?php foreach ($recentLogs as $log): ?>
                                <li>
                                    <span class="dot"></span>
                                    <div>
                                        <strong><?= esc($log['action']) ?></strong>
                                        <p><?= esc($log['full_name'] ?? 'Sistema') ?> · <?= esc($log['details']) ?></p>
                                        <small><?= esc($log['created_at']) ?></small>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            </section>
        </main>
    </div>
</body>
</html>
