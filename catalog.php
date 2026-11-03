<?php
require __DIR__ . '/app.php';
requireLogin();

$db = db();
$query = trim((string) ($_GET['q'] ?? ''));
$query = substr($query, 0, 120);
$career = (string) ($_GET['career'] ?? '');
$sede = (string) ($_GET['sede'] ?? '');
$type = (string) ($_GET['type'] ?? '');
$availability = (string) ($_GET['availability'] ?? '');
$sort = (string) ($_GET['sort'] ?? 'relevance');

if (!in_array($career, INSTITUTION_CAREERS, true)) {
    $career = '';
}
if (!in_array($sede, ['', 'Sede Arequipa', 'Sede Majes'], true)) {
    $sede = '';
}
if (!in_array($type, ['', 'fisico', 'digital'], true)) {
    $type = '';
}
if (!in_array($availability, ['', 'available', 'unavailable'], true)) {
    $availability = '';
}
if (!in_array($sort, ['relevance', 'recent', 'requested'], true)) {
    $sort = 'relevance';
}

$where = [];
$whereParams = [];
$scoreSql = '0 AS relevance_score';
$scoreParams = [];
if ($query !== '') {
    $scoreSql = "CASE
        WHEN lower(b.title) = lower(?) THEN 1000
        WHEN lower(b.author) = lower(?) THEN 900
        WHEN lower(COALESCE(b.isbn, '')) = lower(?) THEN 850
        WHEN lower(b.career) = lower(?) OR lower(b.category) = lower(?) OR lower(COALESCE(b.tags, '')) = lower(?) THEN 800
        WHEN lower(b.title) LIKE lower(?) || '%' THEN 700
        WHEN lower(b.author) LIKE lower(?) || '%' THEN 650
        WHEN lower(b.title) LIKE '%' || lower(?) || '%' THEN 600
        WHEN lower(b.author) LIKE '%' || lower(?) || '%' THEN 500
        WHEN lower(b.career) LIKE '%' || lower(?) || '%'
            OR lower(b.category) LIKE '%' || lower(?) || '%'
            OR lower(COALESCE(b.tags, '')) LIKE '%' || lower(?) || '%' THEN 450
        WHEN lower(COALESCE(b.description, '')) LIKE '%' || lower(?) || '%' THEN 300
        ELSE 100
    END AS relevance_score";
    $scoreParams = array_fill(0, 14, $query);

    $terms = preg_split('/\s+/u', $query, -1, PREG_SPLIT_NO_EMPTY) ?: [$query];
    foreach ($terms as $term) {
        $where[] = '(b.title LIKE ? OR b.author LIKE ? OR b.isbn LIKE ? OR b.category LIKE ? OR b.career LIKE ? OR b.tags LIKE ? OR b.description LIKE ?)';
        $like = '%' . $term . '%';
        array_push($whereParams, $like, $like, $like, $like, $like, $like, $like);
    }
}

if ($career !== '') {
    $where[] = 'b.career = ?';
    $whereParams[] = $career;
}
if ($sede !== '') {
    $where[] = 'b.sede = ?';
    $whereParams[] = $sede;
}
if ($type !== '') {
    $where[] = 'b.type = ?';
    $whereParams[] = $type;
}
if ($availability === 'available') {
    $where[] = 'b.copies_available > 0';
} elseif ($availability === 'unavailable') {
    $where[] = 'b.copies_available <= 0';
}

$orderBy = match ($sort) {
    'recent' => 'COALESCE(b.year, 0) DESC, b.id DESC',
    'requested' => 'request_count DESC, relevance_score DESC, b.title COLLATE NOCASE ASC',
    default => 'relevance_score DESC, request_count DESC, b.title COLLATE NOCASE ASC',
};
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$sql = "SELECT b.*,
            (SELECT COUNT(*) FROM loans l WHERE l.book_id = b.id) AS request_count,
            $scoreSql
        FROM books b
        $whereSql
        ORDER BY $orderBy";
$stmt = $db->prepare($sql);
$stmt->execute(array_merge($scoreParams, $whereParams));
$books = $stmt->fetchAll();
$user = currentUser();
$reservationBlockReason = physicalReservationBlockReason($db, (int) $user['id']);
$pickupMin = date('Y-m-d', strtotime('+1 day'));
$pickupMax = (new DateTimeImmutable('now'))->modify('+48 hours')->format('Y-m-d');
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Catálogo | Biblioteca Virtual</title>
    <link rel="stylesheet" href="assets/css/styles.css">
    <script src="assets/js/app.js" defer></script>
    <script src="assets/js/catalog-search.js" defer></script>
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
                <?php if (hasRole('personal')): ?><a href="books.php">Inventario</a><?php endif; ?>
                <a href="loans.php">Préstamos</a>
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
            <?php $message = getFlash(); if ($message): ?>
                <div class="alert info"><?= esc($message) ?></div>
            <?php endif; ?>
            <section class="page-heading">
                <div>
                    <p class="eyebrow">Catálogo</p>
                    <h1>Material bibliográfico</h1>
                    <p class="page-description">Busca por coincidencia exacta, palabras clave o etiquetas y combina filtros.</p>
                </div>
            </section>

            <section class="panel catalog-search-panel">
                <form method="get" action="catalog.php" class="catalog-search-form" id="catalog-search-form">
                    <div class="catalog-query-field">
                        <label for="catalog-query">Buscar material</label>
                        <div class="autocomplete-wrap">
                            <input id="catalog-query" type="search" name="q" value="<?= esc($query) ?>" maxlength="120" placeholder="Título, autor, carrera, tema o etiqueta" autocomplete="off" aria-autocomplete="list" aria-controls="catalog-suggestions" data-suggest-url="catalog_suggest.php">
                            <div id="catalog-suggestions" class="autocomplete-list" role="listbox" hidden></div>
                        </div>
                        <small>Las coincidencias exactas aparecen primero; también puedes buscar varias palabras.</small>
                    </div>
                    <div class="catalog-filter-grid">
                        <label>Carrera profesional
                            <select name="career">
                                <option value="">Todas las carreras</option>
                                <?php foreach (INSTITUTION_CAREERS as $careerOption): ?>
                                    <option value="<?= esc($careerOption) ?>" <?= $career === $careerOption ? 'selected' : '' ?>><?= esc($careerOption) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>Sede
                            <select name="sede">
                                <option value="">Todas las sedes</option>
                                <option value="Sede Arequipa" <?= $sede === 'Sede Arequipa' ? 'selected' : '' ?>>Sede Arequipa</option>
                                <option value="Sede Majes" <?= $sede === 'Sede Majes' ? 'selected' : '' ?>>Sede Majes</option>
                            </select>
                        </label>
                        <label>Tipo de recurso
                            <select name="type">
                                <option value="">Todos los tipos</option>
                                <option value="fisico" <?= $type === 'fisico' ? 'selected' : '' ?>>Físico</option>
                                <option value="digital" <?= $type === 'digital' ? 'selected' : '' ?>>Digital PDF</option>
                            </select>
                        </label>
                        <label>Disponibilidad
                            <select name="availability">
                                <option value="">Cualquiera</option>
                                <option value="available" <?= $availability === 'available' ? 'selected' : '' ?>>Disponible</option>
                                <option value="unavailable" <?= $availability === 'unavailable' ? 'selected' : '' ?>>No disponible</option>
                            </select>
                        </label>
                        <label>Ordenar por
                            <select name="sort">
                                <option value="relevance" <?= $sort === 'relevance' ? 'selected' : '' ?>>Relevancia</option>
                                <option value="recent" <?= $sort === 'recent' ? 'selected' : '' ?>>Más recientes</option>
                                <option value="requested" <?= $sort === 'requested' ? 'selected' : '' ?>>Más solicitados</option>
                            </select>
                        </label>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">Buscar en el catálogo</button>
                        <a href="catalog.php" class="btn btn-secondary">Limpiar filtros</a>
                    </div>
                </form>
            </section>

            <div class="catalog-results-heading">
                <h2>Resultados</h2>
                <span><?= count($books) ?> materiales</span>
            </div>

            <?php if ($books): ?>
                <div class="book-grid">
                    <?php foreach ($books as $book): ?>
                        <article class="book-card">
                            <?php if (!empty($book['cover'])): ?>
                                <img src="<?= esc((string) $book['cover']) ?>" alt="<?= esc($book['title']) ?>" loading="lazy">
                            <?php endif; ?>
                            <div class="book-body">
                                <span class="badge <?= $book['type'] === 'digital' ? 'digital' : 'physical' ?>"><?= $book['type'] === 'digital' ? 'Digital PDF' : 'Físico' ?></span>
                                <h3><?= esc($book['title']) ?></h3>
                                <p class="book-meta"><?= esc($book['author']) ?> · <?= esc($book['career']) ?></p>
                                <p><?= esc((string) ($book['description'] ?? '')) ?></p>
                                <?php if (!empty($book['tags'])): ?><p class="catalog-tags">Etiquetas: <?= esc($book['tags']) ?></p><?php endif; ?>
                                <div class="catalog-book-meta"><span><?= esc($book['sede']) ?></span><span><?= esc($book['category']) ?></span></div>
                                <div class="book-availability">
                                    <span><?= (int) $book['copies_available'] > 0 ? 'Disponibles: ' . (int) $book['copies_available'] : 'No disponible' ?></span>
                                </div>
                                <div class="book-actions">
                                    <a href="detail.php?id=<?= (int) $book['id'] ?>" class="btn btn-secondary">Detalles</a>
                                    <?php if ($book['type'] === 'digital'): ?>
                                        <a href="detail.php?id=<?= (int) $book['id'] ?>#digital-reader" class="btn btn-primary">Leer en línea</a>
                                    <?php elseif ((int) $book['copies_available'] > 0): ?>
                                        <?php if ($reservationBlockReason === null): ?>
                                            <button type="button" class="btn btn-primary" data-reserve-open data-book-id="<?= (int) $book['id'] ?>" data-book-title="<?= esc($book['title']) ?>">Solicitar préstamo</button>
                                        <?php else: ?>
                                            <span class="status resolved">Reserva bloqueada</span>
                                            <small class="reservation-blocked-note"><?= esc($reservationBlockReason) ?></small>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="status resolved">Sin disponibilidad</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="panel empty-state"><p>No encontramos materiales con esos términos y filtros.</p></div>
            <?php endif; ?>
        </main>
    </div>
    <dialog class="reservation-modal" data-reservation-modal data-pickup-min="<?= esc($pickupMin) ?>" data-pickup-max="<?= esc($pickupMax) ?>" data-return-to="catalog.php">
        <button type="button" class="reservation-modal-close" data-reservation-close aria-label="Cerrar">×</button>
        <h2>Reservar ejemplar físico</h2>
        <p class="reservation-book-title" data-reservation-book-title></p>
        <p class="reservation-warning"><strong>Plazos:</strong> recoge el libro dentro de las 48 horas posteriores a la solicitud. Si no lo recoges o lo devuelves después de la fecha límite, se aplicará una sanción de 14 días sin préstamos físicos ni reservas. El acceso digital se mantiene.</p>
        <form method="post" action="loans.php" class="reservation-form">
            <input type="hidden" name="csrf_token" value="<?= esc(csrfToken()) ?>">
            <input type="hidden" name="action" value="reserve">
            <input type="hidden" name="book_id" value="" data-reservation-book-id>
            <input type="hidden" name="return_to" value="catalog.php">
            <label>Fecha de recojo
                <input type="date" name="pickup_date" required data-pickup-date>
            </label>
            <label>Fecha de devolución
                <input type="date" name="due_date" required data-due-date>
            </label>
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" data-reservation-close>Cancelar</button>
                <button type="submit" class="btn btn-primary">Confirmar reserva</button>
            </div>
        </form>
    </dialog>
    <script src="assets/js/loan-reservation.js" defer></script>
</body>
</html>
