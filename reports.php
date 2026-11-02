<?php
require __DIR__ . '/app.php';
requireLogin();
if (!hasRole('administrador', 'bibliotecario')) {
    http_response_code(403);
    exit('No tienes permisos para consultar los reportes.');
}

$db = db();
$user = currentUser();
$loanSummary = $db->query('SELECT status, COUNT(*) AS total FROM loans GROUP BY status')->fetchAll();
$topBooks = $db->query('SELECT b.title, COUNT(l.id) AS prestamos FROM books b LEFT JOIN loans l ON l.book_id = b.id GROUP BY b.id ORDER BY prestamos DESC LIMIT 5')->fetchAll();
$morosos = $db->query('SELECT u.full_name, b.title, l.due_date FROM loans l INNER JOIN users u ON u.id = l.user_id INNER JOIN books b ON b.id = l.book_id WHERE l.status = "prestado" AND l.due_date < date("now") ORDER BY l.due_date ASC')->fetchAll();
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reportes | Biblioteca Virtual</title>
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
                <a href="books.php">Inventario</a>
                <a href="loans.php">Préstamos</a>
                <a class="active" href="reports.php">Reportes</a>
                <?php if (hasRole('administrador')): ?><a href="users.php">Usuarios</a><?php endif; ?>
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
                    <p class="eyebrow">Auditoría</p>
                    <h1>Reportes y estadísticas</h1>
                </div>
            </div>

            <section class="content-grid two-columns">
                <div class="panel">
                    <div class="panel-header">
                        <h3>Estado de préstamos</h3>
                    </div>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Estado</th>
                                <th>Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($loanSummary as $row): ?>
                                <tr>
                                    <td><?= esc($row['status']) ?></td>
                                    <td><?= (int) $row['total'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="panel">
                    <div class="panel-header">
                        <h3>Material más solicitado</h3>
                    </div>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Material</th>
                                <th>Préstamos</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($topBooks as $book): ?>
                                <tr>
                                    <td><?= esc($book['title']) ?></td>
                                    <td><?= (int) $book['prestamos'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="panel">
                <div class="panel-header">
                    <h3>Lista de morosos</h3>
                </div>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Usuario</th>
                            <th>Libro</th>
                            <th>Fecha límite</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($morosos as $row): ?>
                            <tr>
                                <td><?= esc($row['full_name']) ?></td>
                                <td><?= esc($row['title']) ?></td>
                                <td><?= esc(formatDate($row['due_date'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
        </main>
    </div>
</body>
</html>
