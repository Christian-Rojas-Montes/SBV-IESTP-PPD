<?php
require __DIR__ . '/app.php';
requireLogin();

if (!hasRole('personal')) {
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
        logAudit('INVENTARIO_LIBRO_ELIMINADO', $bookToDelete['title']);
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
    $career = trim($_POST['career'] ?? 'Cursos Generales');
    $tags = trim($_POST['tags'] ?? '');
    $sede = $_POST['sede'] ?? 'Sede Arequipa';
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

    if (!in_array($career, INSTITUTION_CAREERS, true) || !in_array($sede, ['Sede Arequipa', 'Sede Majes'], true)) {
        setFlash('Selecciona una carrera y una sede válidas.');
        redirect($id > 0 ? 'books.php?edit=' . $id : 'books.php');
    }

    if ($id > 0) {
        $existing = $db->prepare('SELECT type, copies_total, copies_available FROM books WHERE id = ?');
        $existing->execute([$id]);
        $existingBook = $existing->fetch();

        if ($existingBook) {
            $activeLoans = $db->prepare('SELECT COUNT(*) FROM loans WHERE book_id = ? AND status IN ("prestado", "vencido")');
            $activeLoans->execute([$id]);
            $activeLoanCount = (int) $activeLoans->fetchColumn();
            if ($copiesTotal < $activeLoanCount) {
                setFlash('El total de copias no puede ser menor que la cantidad de préstamos activos.');
                redirect('books.php?edit=' . $id);
            }

            if ($existingBook['type'] !== $type && $activeLoanCount > 0) {
                setFlash('No se puede cambiar el tipo del material mientras tenga préstamos activos.');
                redirect('books.php?edit=' . $id);
            }

            $delta = $copiesTotal - (int) $existingBook['copies_total'];
            $available = $type === 'digital'
                ? max(0, (int) $existingBook['copies_available'] + $delta)
                : (int) $existingBook['copies_available'];

            $db->beginTransaction();
            $db->prepare('UPDATE books SET title = ?, author = ?, category = ?, career = ?, tags = ?, sede = ?, isbn = ?, editorial = ?, year = ?, language = ?, description = ?, type = ?, copies_total = ?, copies_available = ?, cover = ?, digital_path = ? WHERE id = ?')
                ->execute([$title, $author, $category, $career, $tags, $sede, $isbn, $editorial, $year, $language, $description, $type, $copiesTotal, $available, $cover, $digitalPath, $id]);

            if ($type === 'fisico') {
                $copyRows = $db->prepare("SELECT id, status FROM book_copies WHERE book_id = ? AND status <> 'retirado' ORDER BY id DESC");
                $copyRows->execute([$id]);
                $copies = $copyRows->fetchAll();
                $borrowedCopies = count(array_filter($copies, static fn (array $copy): bool => $copy['status'] === 'prestado'));
                if ($borrowedCopies > $copiesTotal) {
                    $db->rollBack();
                    setFlash('El total de copias no puede ser menor que los ejemplares prestados.');
                    redirect('books.php?edit=' . $id);
                }
                $reduction = count($copies) - $copiesTotal;

                if ($reduction > 0) {
                    $retire = $db->prepare("UPDATE book_copies SET status = 'retirado' WHERE id = ? AND status IN ('disponible', 'no_disponible')");
                    foreach ($copies as $copy) {
                        if ($reduction <= 0) {
                            break;
                        }
                        if (in_array($copy['status'], ['disponible', 'no_disponible'], true)) {
                            $retire->execute([(int) $copy['id']]);
                            $reduction -= $retire->rowCount();
                        }
                    }
                }
                if ($reduction > 0) {
                    $db->rollBack();
                    setFlash('No se pudo retirar la cantidad solicitada de ejemplares.');
                    redirect('books.php?edit=' . $id);
                }

                $copyCount = $db->prepare("SELECT COUNT(*) FROM book_copies WHERE book_id = ? AND status <> 'retirado'");
                $copyCount->execute([$id]);
                createBookCopies($db, $id, max(0, $copiesTotal - (int) $copyCount->fetchColumn()));
                $db->prepare("UPDATE books SET copies_available = (SELECT COUNT(*) FROM book_copies WHERE book_id = ? AND status = 'disponible') WHERE id = ?")
                    ->execute([$id, $id]);
            } else {
                $db->prepare("UPDATE book_copies SET status = 'retirado' WHERE book_id = ? AND status <> 'retirado'")
                    ->execute([$id]);
                $db->prepare('UPDATE books SET copies_available = ? WHERE id = ?')->execute([$copiesTotal, $id]);
            }
            $db->commit();
            logAudit('INVENTARIO_LIBRO_ACTUALIZADO', $title);
            setFlash('Libro actualizado correctamente.');
        }
    } else {
        $db->beginTransaction();
        $db->prepare('INSERT INTO books (title, author, category, career, tags, sede, isbn, editorial, year, language, description, type, copies_total, copies_available, cover, digital_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$title, $author, $category, $career, $tags, $sede, $isbn, $editorial, $year, $language, $description, $type, $copiesTotal, $copiesTotal, $cover, $digitalPath]);
        $bookId = (int) $db->lastInsertId();
        if ($type === 'fisico') {
            createBookCopies($db, $bookId, $copiesTotal);
        }
        $db->commit();
        logAudit('INVENTARIO_LIBRO_REGISTRADO', $title);
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
$copiesByBook = [];
$copyRows = $db->query("SELECT id, book_id, qr_code, status FROM book_copies WHERE status <> 'retirado' ORDER BY book_id, id")->fetchAll();
foreach ($copyRows as $copyRow) {
    $copiesByBook[(int) $copyRow['book_id']][] = $copyRow;
}
$user = currentUser();
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventario | Biblioteca Virtual</title>
    <link rel="stylesheet" href="assets/css/styles.css">
    <script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.4/lib/browser.min.js" defer></script>
    <script src="assets/js/app.js" defer></script>
    <script src="assets/js/book-qr.js" defer></script>
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
                <?php if (hasRole('personal')): ?><a href="users.php">Usuarios</a><?php endif; ?>
                <a href="reports.php">Reportes</a>
                <a href="audit.php">Auditoría</a>
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
                            Carrera profesional
                            <select name="career" required>
                                <?php foreach (INSTITUTION_CAREERS as $careerOption): ?>
                                    <option value="<?= esc($careerOption) ?>" <?= ($editingBook['career'] ?? 'Cursos Generales') === $careerOption ? 'selected' : '' ?>><?= esc($careerOption) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>
                            Sede
                            <select name="sede" required>
                                <?php foreach (['Sede Arequipa', 'Sede Majes'] as $sedeOption): ?>
                                    <option value="<?= esc($sedeOption) ?>" <?= ($editingBook['sede'] ?? 'Sede Arequipa') === $sedeOption ? 'selected' : '' ?>><?= esc($sedeOption) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>
                            Palabras clave / etiquetas
                            <input type="text" name="tags" value="<?= esc($editingBook['tags'] ?? '') ?>" placeholder="redes, circuitos, mantenimiento">
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
                                <option value="digital" <?= (($editingBook['type'] ?? 'fisico') === 'digital') ? 'selected' : '' ?>>Digital PDF</option>
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
                            <th>Carrera</th>
                            <th>Sede</th>
                            <th>Tipo</th>
                            <th>Disponibles</th>
                            <th>QR por ejemplar</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($books as $book): ?>
                            <tr>
                                <td><?= esc($book['title']) ?></td>
                                <td><?= esc($book['author']) ?></td>
                                <td><?= esc($book['career']) ?></td>
                                <td><?= esc($book['sede']) ?></td>
                                <td><?= $book['type'] === 'digital' ? 'Digital' : 'Físico' ?></td>
                                <td><?= (int) $book['copies_available'] ?></td>
                                <td>
                                    <?php if ($book['type'] === 'fisico' && !empty($copiesByBook[(int) $book['id']])): ?>
                                        <details class="qr-copy-list">
                                            <summary><?= count($copiesByBook[(int) $book['id']]) ?> etiquetas</summary>
                                            <div class="qr-label-grid">
                                                <?php foreach ($copiesByBook[(int) $book['id']] as $copy): ?>
                                                    <div class="qr-label" data-qr-label>
                                                        <strong><?= esc($book['title']) ?></strong>
                                                        <small><?= esc($book['sede']) ?> · Ejemplar <?= (int) $copy['id'] ?></small>
                                                        <canvas data-qr-value="<?= esc($copy['qr_code']) ?>" aria-label="Código QR del ejemplar <?= (int) $copy['id'] ?>"></canvas>
                                                        <code><?= esc($copy['qr_code']) ?></code>
                                                        <small>Estado: <?= esc($copy['status']) ?></small>
                                                        <button type="button" class="btn btn-secondary btn-small" data-print-qr>Imprimir etiqueta</button>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </details>
                                    <?php else: ?>
                                        <span>—</span>
                                    <?php endif; ?>
                                </td>
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
