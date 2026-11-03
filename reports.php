<?php
require __DIR__ . '/app.php';
require __DIR__ . '/report_exports.php';
requireLogin();
if (!hasRole('personal')) {
    http_response_code(403);
    exit('No tienes permisos para consultar los reportes.');
}

$db = db();
$user = currentUser();
$today = new DateTimeImmutable('today');
$defaultFrom = $today->modify('first day of this month')->format('Y-m-d');
$from = (string) ($_GET['from'] ?? $defaultFrom);
$to = (string) ($_GET['to'] ?? $today->format('Y-m-d'));
$sede = (string) ($_GET['sede'] ?? 'all');
$report = (string) ($_GET['report'] ?? 'physical_demand');
$validReports = [
    'physical_demand' => ['title' => 'Libros físicos más demandados (reservas)', 'columns' => ['Libro', 'Autor', 'Carrera', 'Sede', 'Reservas']],
    'digital_views' => ['title' => 'Recursos digitales más consultados', 'columns' => ['Recurso', 'Carrera', 'Sede', 'Lecturas']],
    'overdue' => ['title' => 'Alumnos con morosidad activa', 'columns' => ['Alumno', 'Correo', 'Libro', 'Sede', 'Vencimiento', 'Días de retraso']],
    'user_history' => ['title' => 'Historial individual de préstamos', 'columns' => ['Usuario', 'Libro', 'Tipo', 'Sede', 'Solicitud', 'Recojo', 'Devolución prevista', 'Devolución real', 'Estado']],
    'career_demand' => ['title' => 'Demandas bibliográficas por Carrera Profesional', 'columns' => ['Carrera', 'Solicitudes', 'Recursos distintos']],
    'inventory' => ['title' => 'Inventario físico por Sede', 'columns' => ['Libro', 'Carrera', 'Sede', 'Ejemplares', 'Disponibles', 'Prestados', 'Reservados']],
    'general_course_views' => ['title' => 'Consolidado de lecturas por Cursos Generales', 'columns' => ['Recurso', 'Sede', 'Lecturas']],
];
if (!isset($validReports[$report])) {
    $report = 'physical_demand';
}
if (!in_array($sede, ['all', 'Sede Arequipa', 'Sede Majes'], true)) {
    $sede = 'all';
}
$validDate = static function (string $value): bool {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    return $date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0)) && $date->format('Y-m-d') === $value;
};
$filterError = '';
if (!$validDate($from) || !$validDate($to) || $from > $to) {
    $filterError = 'Selecciona un rango de fechas válido.';
    $from = $defaultFrom;
    $to = $today->format('Y-m-d');
}
$localZone = new DateTimeZone(date_default_timezone_get());
$utcZone = new DateTimeZone('UTC');
$fromUtc = (new DateTimeImmutable($from . ' 00:00:00', $localZone))->setTimezone($utcZone)->format('Y-m-d H:i:s');
$toUtc = (new DateTimeImmutable($to . ' 00:00:00', $localZone))->modify('+1 day')->setTimezone($utcZone)->format('Y-m-d H:i:s');
$sedeSql = $sede === 'all' ? '' : ' AND b.sede = :sede';
$commonBindings = [':from' => $fromUtc, ':to' => $toUtc];
if ($sede !== 'all') {
    $commonBindings[':sede'] = $sede;
}
$rows = [];
$note = '';
$reportInfo = $validReports[$report];

if ($filterError === '') {
    if ($report === 'physical_demand') {
        $stmt = $db->prepare("SELECT b.title, b.author, b.career, b.sede, COUNT(l.id) AS total
            FROM loans l INNER JOIN books b ON b.id = l.book_id
            WHERE b.type = 'fisico' AND l.notes LIKE 'Reserva física%' AND datetime(l.loan_date) >= :from AND datetime(l.loan_date) < :to $sedeSql
            GROUP BY b.id ORDER BY total DESC, b.title ASC");
        $stmt->execute($commonBindings);
        foreach ($stmt->fetchAll() as $row) {
            $rows[] = [$row['title'], $row['author'], $row['career'], $row['sede'], (int) $row['total']];
        }
    } elseif ($report === 'digital_views' || $report === 'general_course_views') {
        $stmt = $db->prepare("SELECT a.details, a.created_at FROM audit_logs a
            WHERE a.action = 'LECTURA_DIGITAL' AND datetime(a.created_at) >= :from AND datetime(a.created_at) < :to
            ORDER BY a.created_at DESC");
        $stmt->execute([':from' => $fromUtc, ':to' => $toUtc]);
        $counts = [];
        $bookCache = [];
        foreach ($stmt->fetchAll() as $log) {
            $details = json_decode((string) $log['details'], true);
            $bookId = is_array($details) ? (int) ($details['book_id'] ?? 0) : 0;
            if ($bookId < 1) {
                continue;
            }
            if (!array_key_exists($bookId, $bookCache)) {
                $bookStmt = $db->prepare("SELECT id, title, career, sede FROM books WHERE id = ? AND type = 'digital' LIMIT 1");
                $bookStmt->execute([$bookId]);
                $bookCache[$bookId] = $bookStmt->fetch() ?: null;
            }
            $book = $bookCache[$bookId];
            if (!$book || ($sede !== 'all' && $book['sede'] !== $sede)
                || ($report === 'general_course_views' && $book['career'] !== 'Cursos Generales')) {
                continue;
            }
            $counts[$bookId] ??= ['title' => $book['title'], 'career' => $book['career'], 'sede' => $book['sede'], 'total' => 0];
            $counts[$bookId]['total']++;
        }
        uasort($counts, static fn (array $left, array $right): int => $right['total'] <=> $left['total'] ?: strcmp($left['title'], $right['title']));
        foreach ($counts as $item) {
            $rows[] = $report === 'general_course_views'
                ? [$item['title'], $item['sede'], $item['total']]
                : [$item['title'], $item['career'], $item['sede'], $item['total']];
        }
        $note = 'Cada lectura representa una apertura autenticada del visor digital.';
    } elseif ($report === 'overdue') {
        $stmt = $db->prepare("SELECT u.full_name, u.email, b.title, b.sede, l.due_date,
                CAST(julianday('now') - julianday(l.due_date) AS INTEGER) AS late_days
            FROM loans l INNER JOIN users u ON u.id = l.user_id INNER JOIN books b ON b.id = l.book_id
            WHERE u.role = 'estudiante' AND l.status IN ('prestado', 'vencido') AND l.return_date IS NULL
                AND julianday(l.due_date) < julianday('now') AND datetime(l.due_date) >= :from AND datetime(l.due_date) < :to $sedeSql
            ORDER BY l.due_date ASC");
        $stmt->execute($commonBindings);
        foreach ($stmt->fetchAll() as $row) {
            $rows[] = [$row['full_name'], $row['email'], $row['title'], $row['sede'], $row['due_date'], (int) $row['late_days']];
        }
    } elseif ($report === 'user_history') {
        $userId = (int) ($_GET['user_id'] ?? 0);
        if ($userId > 0) {
            $bindings = $commonBindings + [':user_id' => $userId];
            $stmt = $db->prepare("SELECT u.full_name, b.title, b.type, b.sede, l.loan_date, l.pickup_date, l.due_date, l.return_date, l.status
                FROM loans l INNER JOIN users u ON u.id = l.user_id INNER JOIN books b ON b.id = l.book_id
                WHERE l.user_id = :user_id AND datetime(l.loan_date) >= :from AND datetime(l.loan_date) < :to $sedeSql
                ORDER BY l.loan_date DESC");
            $stmt->execute($bindings);
            foreach ($stmt->fetchAll() as $row) {
                $rows[] = [$row['full_name'], $row['title'], $row['type'] === 'fisico' ? 'Físico' : 'Digital', $row['sede'], $row['loan_date'], $row['pickup_date'] ?: '—', $row['due_date'], $row['return_date'] ?: '—', $row['status']];
            }
        } else {
            $note = 'Selecciona un usuario para consultar su historial individual.';
        }
    } elseif ($report === 'career_demand') {
        $stmt = $db->prepare("SELECT b.career, COUNT(l.id) AS total, COUNT(DISTINCT b.id) AS titles
            FROM loans l INNER JOIN books b ON b.id = l.book_id
            WHERE datetime(l.loan_date) >= :from AND datetime(l.loan_date) < :to $sedeSql
            GROUP BY b.career ORDER BY total DESC, b.career ASC");
        $stmt->execute($commonBindings);
        foreach ($stmt->fetchAll() as $row) {
            $rows[] = [$row['career'], (int) $row['total'], (int) $row['titles']];
        }
    } elseif ($report === 'inventory') {
        $stmt = $db->prepare("SELECT b.title, b.career, b.sede,
                COUNT(c.id) AS copies,
                SUM(CASE WHEN c.status = 'disponible' THEN 1 ELSE 0 END) AS available,
                SUM(CASE WHEN c.status = 'prestado' THEN 1 ELSE 0 END) AS borrowed,
                SUM(CASE WHEN c.status = 'no_disponible' THEN 1 ELSE 0 END) AS reserved
            FROM books b LEFT JOIN book_copies c ON c.book_id = b.id AND c.status <> 'retirado'
            WHERE b.type = 'fisico'" . ($sede === 'all' ? '' : ' AND b.sede = :sede') . "
            GROUP BY b.id ORDER BY b.sede, b.title");
        $stmt->execute($sede === 'all' ? [] : [':sede' => $sede]);
        foreach ($stmt->fetchAll() as $row) {
            $rows[] = [$row['title'], $row['career'], $row['sede'], (int) $row['copies'], (int) $row['available'], (int) $row['borrowed'], (int) $row['reserved']];
        }
        $note = 'El inventario refleja el estado actual; el rango de fechas no modifica esta fotografía.';
    }
}

if (isset($_GET['export'])) {
    if ($filterError !== '') {
        http_response_code(400);
        exit($filterError);
    }
    $filters = ['from' => $from, 'to' => $to, 'sede' => $sede];
    if ($_GET['export'] === 'xlsx') {
        exportReportXlsx($reportInfo['title'], $filters, $reportInfo['columns'], $rows);
    }
    if ($_GET['export'] === 'pdf') {
        exportReportPdf($reportInfo['title'], $filters, $reportInfo['columns'], $rows);
    }
    http_response_code(400);
    exit('Formato de exportación no válido.');
}

$users = $db->query('SELECT id, full_name, email FROM users WHERE is_active = 1 ORDER BY full_name')->fetchAll();
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
            <div class="brand-wrap"><div class="brand-mark">PPD</div><div><strong>Biblioteca Institucional</strong><small>IESTP “Pedro P. Díaz” · Arequipa</small></div></div>
            <nav class="main-nav">
                <a href="dashboard.php">Resumen</a><a href="catalog.php">Catálogo</a><a href="books.php">Inventario</a>
                <a href="loans.php">Préstamos</a><a class="active" href="reports.php">Reportes</a><a href="audit.php">Auditoría</a>
                <?php if (hasRole('personal')): ?><a href="users.php">Usuarios</a><?php endif; ?>
            </nav>
            <div class="user-box"><span><?= esc($user['full_name']) ?></span><?php renderThemeToggle(); ?><a href="profile.php" class="btn btn-light">Mi perfil</a><a href="logout.php" class="btn btn-light">Salir</a></div>
        </header>

        <main class="container">
            <div class="page-heading"><div><p class="eyebrow">Estadísticas institucionales</p><h1>Reportes y exportación</h1><p class="page-description">Filtra la información por fecha y sede, y descarga el reporte en PDF o Excel.</p></div></div>
            <?php if ($filterError !== ''): ?><div class="alert danger"><?= esc($filterError) ?></div><?php endif; ?>
            <form method="get" class="panel report-filter-form">
                <label>Reporte
                    <select name="report" data-report-select>
                        <?php foreach ($validReports as $key => $info): ?><option value="<?= esc($key) ?>" <?= $report === $key ? 'selected' : '' ?>><?= esc($info['title']) ?></option><?php endforeach; ?>
                    </select>
                </label>
                <label>Desde<input type="date" name="from" value="<?= esc($from) ?>" required></label>
                <label>Hasta<input type="date" name="to" value="<?= esc($to) ?>" required></label>
                <label>Sede
                    <select name="sede"><option value="all" <?= $sede === 'all' ? 'selected' : '' ?>>Todas</option><option <?= $sede === 'Sede Arequipa' ? 'selected' : '' ?>>Sede Arequipa</option><option <?= $sede === 'Sede Majes' ? 'selected' : '' ?>>Sede Majes</option></select>
                </label>
                <label data-user-filter <?= $report !== 'user_history' ? 'hidden' : '' ?>>Usuario
                    <select name="user_id"><option value="0">Selecciona un usuario</option>
                        <?php foreach ($users as $student): ?><option value="<?= (int) $student['id'] ?>" <?= (int) ($_GET['user_id'] ?? 0) === (int) $student['id'] ? 'selected' : '' ?>><?= esc($student['full_name'] . ' · ' . $student['email']) ?></option><?php endforeach; ?>
                    </select>
                </label>
                <div class="report-filter-actions">
                    <button type="submit" class="btn btn-primary">Generar reporte</button>
                    <?php $query = http_build_query(['report' => $report, 'from' => $from, 'to' => $to, 'sede' => $sede, 'user_id' => (int) ($_GET['user_id'] ?? 0)]); ?>
                    <a class="btn btn-secondary" href="reports.php?<?= esc($query) ?>&export=pdf">Exportar PDF</a>
                    <a class="btn btn-secondary" href="reports.php?<?= esc($query) ?>&export=xlsx">Exportar Excel</a>
                </div>
            </form>

            <section class="panel report-results">
                <div class="panel-header"><div><h2><?= esc($reportInfo['title']) ?></h2><small>Periodo <?= esc($from) ?> al <?= esc($to) ?> · <?= esc($sede === 'all' ? 'Todas las sedes' : $sede) ?></small></div><span class="status resolved"><?= count($rows) ?> filas</span></div>
                <?php if ($note !== ''): ?><p class="field-help"><?= esc($note) ?></p><?php endif; ?>
                <div class="table-scroll"><table class="data-table"><thead><tr><?php foreach ($reportInfo['columns'] as $column): ?><th><?= esc($column) ?></th><?php endforeach; ?></tr></thead><tbody>
                    <?php if (!$rows): ?><tr><td colspan="<?= count($reportInfo['columns']) ?>" class="empty-state"><?= esc($note !== '' ? $note : 'No hay registros para los filtros seleccionados.') ?></td></tr><?php endif; ?>
                    <?php foreach ($rows as $row): ?><tr><?php foreach ($row as $value): ?><td><?= esc((string) $value) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
                </tbody></table></div>
            </section>
        </main>
    </div>
    <script>
        document.querySelector('[data-report-select]')?.addEventListener('change', (event) => {
            const userFilter = document.querySelector('[data-user-filter]');
            if (userFilter) userFilter.hidden = event.target.value !== 'user_history';
        });
    </script>
</body>
</html>
