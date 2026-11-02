<?php
require __DIR__ . '/app.php';
requireLogin();

$db = db();
$id = (int) ($_GET['id'] ?? 0);
$book = $db->prepare('SELECT * FROM books WHERE id = ?');
$book->execute([$id]);
$book = $book->fetch();

if (!$book) {
    redirect('catalog.php');
}

$user = currentUser();
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= esc($book['title']) ?> | Biblioteca Virtual</title>
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
                <a class="active" href="catalog.php">Catálogo</a>
                <?php if (hasRole('administrador', 'bibliotecario')): ?><a href="books.php">Inventario</a><?php endif; ?>
                <a href="loans.php">Préstamos</a>
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

        <main class="container detail-layout">
            <article class="detail-card">
                <img src="<?= esc($book['cover']) ?>" alt="<?= esc($book['title']) ?>" class="detail-cover">
                <div class="detail-copy">
                    <span class="badge <?= $book['type'] === 'digital' ? 'digital' : 'physical' ?>"><?= $book['type'] === 'digital' ? 'Digital' : 'Físico' ?></span>
                    <h1><?= esc($book['title']) ?></h1>
                    <p class="book-meta">Autor: <?= esc($book['author']) ?> · Editorial: <?= esc($book['editorial']) ?></p>
                    <p><?= esc($book['description']) ?></p>
                    <ul class="detail-list">
                        <li><strong>Categoría:</strong> <?= esc($book['category']) ?></li>
                        <li><strong>ISBN:</strong> <?= esc($book['isbn']) ?></li>
                        <li><strong>Idioma:</strong> <?= esc($book['language']) ?></li>
                        <li><strong>Año:</strong> <?= (int) $book['year'] ?></li>
                        <li><strong>Disponibles:</strong> <?= (int) $book['copies_available'] ?></li>
                    </ul>
                    <div class="detail-actions">
                        <a href="catalog.php" class="btn btn-secondary">Volver</a>
                        <?php if ((int) $book['copies_available'] > 0): ?>
                            <form method="post" action="loans.php" class="inline-form">
                                <input type="hidden" name="csrf_token" value="<?= esc(csrfToken()) ?>">
                                <input type="hidden" name="action" value="borrow">
                                <input type="hidden" name="book_id" value="<?= (int) $book['id'] ?>">
                                <button type="submit" class="btn btn-primary">Solicitar préstamo</button>
                            </form>
                        <?php else: ?>
                            <span class="status resolved">Sin disponibilidad</span>
                        <?php endif; ?>
                    </div>
                </div>
            </article>

            <aside class="panel">
                <div class="panel-header">
                    <h3>Visualización digital</h3>
                </div>
                <?php if (!empty($book['digital_path']) && file_exists(__DIR__ . '/' . $book['digital_path'])): ?>
                    <iframe src="<?= esc($book['digital_path']) ?>" title="Visualizador de contenido digital"></iframe>
                <?php else: ?>
                    <div class="empty-state">
                        <p>Este recurso aún no tiene un archivo digital cargado.</p>
                    </div>
                <?php endif; ?>
            </aside>
        </main>
    </div>
</body>
</html>
