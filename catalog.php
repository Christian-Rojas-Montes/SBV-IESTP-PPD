<?php
require __DIR__ . '/app.php';
requireLogin();

$db = db();
$query = trim($_GET['q'] ?? '');

if ($query !== '') {
    $stmt = $db->prepare('SELECT * FROM books WHERE title LIKE ? OR author LIKE ? OR category LIKE ? ORDER BY title ASC');
    $value = '%' . $query . '%';
    $stmt->execute([$value, $value, $value]);
    $books = $stmt->fetchAll();
} else {
    $books = $db->query('SELECT * FROM books ORDER BY title ASC')->fetchAll();
}

$user = currentUser();
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Catálogo | Biblioteca Virtual</title>
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

        <main class="container">
            <div class="page-heading">
                <div>
                    <p class="eyebrow">Catálogo</p>
                    <h1>Material bibliográfico</h1>
                </div>
                <form method="get" action="catalog.php" class="search-form">
                    <input type="text" name="q" value="<?= esc($query) ?>" placeholder="Buscar por título, autor o categoría">
                    <button type="submit" class="btn btn-primary">Buscar</button>
                </form>
            </div>

            <div class="book-grid">
                <?php foreach ($books as $book): ?>
                    <article class="book-card">
                        <img src="<?= esc($book['cover']) ?>" alt="<?= esc($book['title']) ?>">
                        <div class="book-body">
                            <span class="badge <?= $book['type'] === 'digital' ? 'digital' : 'physical' ?>"><?= $book['type'] === 'digital' ? 'Digital' : 'Físico' ?></span>
                            <h3><?= esc($book['title']) ?></h3>
                            <p class="book-meta"><?= esc($book['author']) ?> · <?= esc($book['category']) ?></p>
                            <p><?= esc(substr($book['description'], 0, 120)) ?>...</p>
                            <div class="book-availability">
                                <span>Disponibles: <?= (int) $book['copies_available'] ?></span>
                            </div>
                            <div class="book-actions">
                                <a href="detail.php?id=<?= (int) $book['id'] ?>" class="btn btn-secondary">Detalles</a>
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
                <?php endforeach; ?>
            </div>
        </main>
    </div>
</body>
</html>
