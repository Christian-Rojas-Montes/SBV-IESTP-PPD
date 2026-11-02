<?php
require __DIR__ . '/app.php';
requireLogin();

if (!hasRole('administrador', 'bibliotecario')) {
    redirect('dashboard.php');
}

$db = db();
$flash = getFlash();
$editingBook = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken();
    $action = $_POST['action'] ?? '';
    if ($action === 'delete') {
        $deleteId = (int) ($_POST['id'] ?? 0);
        $stmt = $db->prepare('SELECT title FROM books WHERE id = ?');
        $stmt->execute([$deleteId]);
        $bookToDelete = $stmt->fetch();
        if (!$bookToDelete) {
            setFlash('No se encontró el material que intentas eliminar.');
            redirect('books.php');
        }

        $history = $db->prepare('SELECT (SELECT COUNT(*) FROM loans WHERE book_id = ?) + (SELECT COUNT(*) FROM reservations WHERE book_id = ?)');
        $history->execute([$deleteId, $deleteId]);
        if ((int) $history->fetchColumn() > 0) {
            setFlash('No se puede eliminar un material con historial de préstamos o reservas.');
            redirect('books.php');
        }

        $db->prepare('DELETE FROM books WHERE id = ?')->execute([$deleteId]);
        logAudit('Libro eliminado', $bookToDelete['title']);
        setFlash('Libro eliminado correctamente.');
        redirect('books.php');
    }

    if ($action !== 'save') {
        http_response_code(400);
        exit('Acción de inventario no válida.');
    }

    $id = (int) ($_POST['id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $author = trim($_POST['author'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $isbn = trim($_POST['isbn'] ?? '');
    $editorial = trim($_POST['editorial'] ?? '');
    $year = (int) ($_POST['year'] ?? 0);
    $language = trim($_POST['language'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $type = $_POST['type'] ?? 'fisico';
    $copiesTotal = max(0, (int) ($_POST['copies_total'] ?? 0));
    $cover = trim($_POST['cover'] ?? '');
    $digitalPath = trim($_POST['digital_path'] ?? '');

    if ($title === '' || $author === '' || $category === '') {
        setFlash('El título, autor y categoría son obligatorios.');
        redirect('books.php');
    }

    if (!in_array($type, ['fisico', 'digital'], true)) {
        setFlash('Selecciona un tipo de material válido.');
        redirect($id > 0 ? 'books.php?edit=' . $id : 'books.php');
    }

    if ($id > 0) {
        $existing = $db->prepare('SELECT copies_total, copies_available FROM books WHERE id = ?');
        $existing->execute([$id]);
        $existingBook = $existing->fetch();

        if ($existingBook) {
            $activeLoans = $db->prepare('SELECT COUNT(*) FROM loans WHERE book_id = ? AND status = "prestado"');
            $activeLoans->execute([$id]);
            if ($copiesTotal < (int) $activeLoans->fetchColumn()) {
                setFlash('El total de copias no puede ser menor que la cantidad de préstamos activos.');
                redirect('books.php?edit=' . $id);
            }

            $delta = $copiesTotal - (int) $existingBook['copies_total'];
            $available = max(0, (int) $existingBook['copies_available'] + $delta);
            $db->prepare('UPDATE books SET title = ?, author = ?, category = ?, isbn = ?, editorial = ?, year = ?, language = ?, description = ?, type = ?, copies_total = ?, copies_available = ?, cover = ?, digital_path = ? WHERE id = ?')
                ->execute([$title, $author, $category, $isbn, $editorial, $year, $language, $description, $type, $copiesTotal, $available, $cover, $digitalPath, $id]);
            logAudit('Libro actualizado', $title);
            setFlash('Libro actualizado correctamente.');
        }
    } else {
        $db->prepare('INSERT INTO books (title, author, category, isbn, editorial, year, language, description, type, copies_total, copies_available, cover, digital_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$title, $author, $category, $isbn, $editorial, $year, $language, $description, $type, $copiesTotal, $copiesTotal, $cover, $digitalPath]);
        logAudit('Libro registrado', $title);
        setFlash('Libro registrado correctamente.');
    }

    redirect('books.php');
}

if (isset($_GET['edit'])) {
    $editId = (int) $_GET['edit'];
    $stmt = $db->prepare('SELECT * FROM books WHERE id = ?');
    $stmt->execute([$editId]);
    $editingBook = $stmt->fetch();
}

$books = $db->query('SELECT * FROM books ORDER BY title ASC')->fetchAll();
$user = currentUser();
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventario | Biblioteca Virtual</title>
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
                <a class="active" href="books.php">Inventario</a>
                <a href="loans.php">Préstamos</a>
                <?php if (hasRole('administrador')): ?><a href="users.php">Usuarios</a><?php endif; ?>
                <a href="reports.php">Reportes</a>
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
                    <p class="eyebrow">Administración</p>
                    <h1>Gestión del inventario</h1>
                </div>
            </div>

            <?php if ($flash): ?>
                <div class="alert info"><?= esc($flash) ?></div>
            <?php endif; ?>

            <section class="panel form-panel">
                <div class="panel-header">
                    <h3><?= $editingBook ? 'Editar material' : 'Registrar nuevo material' ?></h3>
                </div>

                <form method="post" action="books.php" class="book-form">
                    <input type="hidden" name="csrf_token" value="<?= esc(csrfToken()) ?>">
                    <input type="hidden" name="action" value="save">
                    <?php if ($editingBook): ?>
                        <input type="hidden" name="id" value="<?= (int) $editingBook['id'] ?>">
                    <?php endif; ?>

                    <div class="field-grid">
                        <label>
                            Título
                            <input type="text" name="title" value="<?= esc($editingBook['title'] ?? '') ?>" required>
                        </label>
                        <label>
                            Autor
                            <input type="text" name="author" value="<?= esc($editingBook['author'] ?? '') ?>" required>
                        </label>
                        <label>
                            Categoría
                            <input type="text" name="category" value="<?= esc($editingBook['category'] ?? '') ?>" required>
                        </label>
                        <label>
                            Editorial
                            <input type="text" name="editorial" value="<?= esc($editingBook['editorial'] ?? '') ?>">
                        </label>
                        <label>
                            ISBN / Código
                            <input type="text" name="isbn" value="<?= esc($editingBook['isbn'] ?? '') ?>">
                        </label>
                        <label>
                            Año
                            <input type="number" name="year" min="1900" max="2100" value="<?= esc((string) ($editingBook['year'] ?? date('Y'))) ?>">
                        </label>
                        <label>
                            Idioma
                            <input type="text" name="language" value="<?= esc($editingBook['language'] ?? 'Español') ?>">
                        </label>
                        <label>
                            Tipo
                            <select name="type">
                                <option value="fisico" <?= (($editingBook['type'] ?? 'fisico') === 'fisico') ? 'selected' : '' ?>>Físico</option>
                                <option value="digital" <?= (($editingBook['type'] ?? 'fisico') === 'digital') ? 'selected' : '' ?>>Digital</option>
                            </select>
                        </label>
                        <label>
                            Copias totales
                            <input type="number" name="copies_total" min="0" value="<?= esc((string) ($editingBook['copies_total'] ?? 1)) ?>" required>
                        </label>
                        <label>
                            URL de portada
                            <input type="url" name="cover" value="<?= esc($editingBook['cover'] ?? '') ?>">
                        </label>
                        <label>
                            Archivo digital
                            <input type="text" name="digital_path" value="<?= esc($editingBook['digital_path'] ?? '') ?>" placeholder="storage/libro.pdf">
                        </label>
                    </div>

                    <label>
                        Descripción
                        <textarea name="description" rows="4"><?= esc($editingBook['description'] ?? '') ?></textarea>
                    </label>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary"><?= $editingBook ? 'Actualizar material' : 'Guardar material' ?></button>
                        <?php if ($editingBook): ?>
                            <a href="books.php" class="btn btn-secondary">Cancelar</a>
                        <?php endif; ?>
                    </div>
                </form>
            </section>

            <section class="panel">
                <div class="panel-header">
                    <h3>Listado del inventario</h3>
                </div>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Título</th>
                            <th>Autor</th>
                            <th>Tipo</th>
                            <th>Disponibles</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($books as $book): ?>
                            <tr>
                                <td><?= esc($book['title']) ?></td>
                                <td><?= esc($book['author']) ?></td>
                                <td><?= $book['type'] === 'digital' ? 'Digital' : 'Físico' ?></td>
                                <td><?= (int) $book['copies_available'] ?></td>
                                <td class="action-cell">
                                    <a href="books.php?edit=<?= (int) $book['id'] ?>" class="btn btn-secondary btn-small">Editar</a>
                                    <form method="post" action="books.php" class="inline-form" onsubmit="return confirm('¿Deseas eliminar este material?')">
                                        <input type="hidden" name="csrf_token" value="<?= esc(csrfToken()) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int) $book['id'] ?>">
                                        <button type="submit" class="btn btn-light btn-small">Eliminar</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
        </main>
    </div>
</body>
</html>
