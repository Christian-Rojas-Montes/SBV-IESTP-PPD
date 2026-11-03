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
$reservationBlockReason = physicalReservationBlockReason($db, (int) $user['id']);
$pickupMin = date('Y-m-d', strtotime('+1 day'));
$pickupMax = (new DateTimeImmutable('now'))->modify('+48 hours')->format('Y-m-d');
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= esc($book['title']) ?> | Biblioteca Virtual</title>
    <link rel="stylesheet" href="assets/css/styles.css">
    <script src="assets/js/app.js" defer></script>
    <script src="assets/js/loan-reservation.js" defer></script>
    <?php if ($book['type'] === 'digital'): ?>
        <script type="module" src="assets/js/pdf-reader.js"></script>
    <?php endif; ?>
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

        <main class="container detail-layout">
            <?php $message = getFlash(); if ($message): ?>
                <div class="alert info detail-flash"><?= esc($message) ?></div>
            <?php endif; ?>
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
                        <?php if ($book['type'] === 'digital'): ?>
                            <a href="#digital-reader" class="btn btn-primary">Leer en línea</a>
                        <?php elseif ((int) $book['copies_available'] > 0): ?>
                            <?php if ($reservationBlockReason === null): ?>
                                <button type="button" class="btn btn-primary" data-reserve-open data-book-id="<?= (int) $book['id'] ?>" data-book-title="<?= esc($book['title']) ?>">Solicitar préstamo</button>
                            <?php else: ?>
                                <span class="status resolved" title="<?= esc($reservationBlockReason) ?>">Reserva bloqueada</span>
                                <p class="field-help"><?= esc($reservationBlockReason) ?></p>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="status resolved">Sin disponibilidad</span>
                        <?php endif; ?>
                    </div>
                </div>
            </article>

            <?php if ($book['type'] === 'digital'): ?>
                <section id="digital-reader" class="panel pdf-reader-panel" data-pdf-reader
                    data-book-id="<?= (int) $book['id'] ?>"
                    data-stream-url="stream_pdf.php"
                    data-csrf-token="<?= esc(csrfToken()) ?>">
                    <div class="pdf-reader-header">
                        <div>
                            <p class="eyebrow">Lectura protegida</p>
                            <h2><?= esc($book['title']) ?></h2>
                        </div>
                        <div class="pdf-reader-controls" aria-label="Controles de lectura">
                            <button type="button" class="btn btn-secondary btn-small" data-page-previous disabled aria-label="Página anterior">Anterior</button>
                            <span data-page-count>— / —</span>
                            <button type="button" class="btn btn-secondary btn-small" data-page-next disabled aria-label="Página siguiente">Siguiente</button>
                            <button type="button" class="btn btn-secondary btn-small" data-zoom-out disabled aria-label="Alejar">−</button>
                            <button type="button" class="btn btn-secondary btn-small" data-zoom-in disabled aria-label="Acercar">+</button>
                        </div>
                    </div>
                    <p class="pdf-reader-status" data-reader-status role="status" aria-live="polite">Preparando el documento…</p>
                    <div class="pdf-stage" data-pdf-stage>
                        <canvas data-pdf-canvas hidden aria-label="Página del documento PDF"></canvas>
                        <div class="pdf-reader-overlay" data-reader-overlay hidden>
                            <p data-reader-overlay-message>Sesión de lectura pausada por inactividad. Haz clic para reanudar</p>
                            <button type="button" class="btn btn-primary" data-reader-resume>Reanudar lectura</button>
                        </div>
                    </div>
                    <p class="field-help">La lectura se pausa tras 15 minutos sin interacción. Solo se permite una lectura activa por cuenta.</p>
                </section>
            <?php else: ?>
                <aside class="panel detail-reading-note">
                    <div class="panel-header"><h3>Material físico</h3></div>
                    <p>Consulta la disponibilidad y reserva un ejemplar para recogerlo en biblioteca.</p>
                </aside>
            <?php endif; ?>
        </main>
    </div>
    <?php if ($book['type'] === 'fisico' && (int) $book['copies_available'] > 0 && $reservationBlockReason === null): ?>
        <dialog class="reservation-modal" data-reservation-modal data-pickup-min="<?= esc($pickupMin) ?>" data-pickup-max="<?= esc($pickupMax) ?>" data-return-to="detail.php?id=<?= (int) $book['id'] ?>">
            <button type="button" class="reservation-modal-close" data-reservation-close aria-label="Cerrar">×</button>
            <h2>Reservar ejemplar físico</h2>
            <p class="reservation-book-title" data-reservation-book-title></p>
            <p class="reservation-warning"><strong>Plazos:</strong> recoge el libro dentro de las 48 horas posteriores a la solicitud. Si no lo recoges o lo devuelves después de la fecha límite, se aplicará una sanción de 14 días sin préstamos físicos ni reservas. El acceso digital se mantiene.</p>
            <form method="post" action="loans.php" class="reservation-form">
                <input type="hidden" name="csrf_token" value="<?= esc(csrfToken()) ?>">
                <input type="hidden" name="action" value="reserve">
                <input type="hidden" name="book_id" value="<?= (int) $book['id'] ?>" data-reservation-book-id>
                <input type="hidden" name="return_to" value="detail.php?id=<?= (int) $book['id'] ?>">
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
    <?php endif; ?>
</body>
</html>
