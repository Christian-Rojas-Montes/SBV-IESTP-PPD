<?php
require __DIR__ . '/app.php';
requireLogin();
if (!hasRole('personal')) {
    http_response_code(403);
    exit('La auditoría del sistema es exclusiva del personal.');
}

$db = db();
$user = currentUser();
$today = new DateTimeImmutable('today');
$from = (string) ($_GET['from'] ?? $today->modify('-30 days')->format('Y-m-d'));
$to = (string) ($_GET['to'] ?? $today->format('Y-m-d'));
$category = (string) ($_GET['category'] ?? 'all');
$search = trim((string) ($_GET['q'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;
$validDate = static function (string $value): bool {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    return $date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0)) && $date->format('Y-m-d') === $value;
};
$error = '';
if (!$validDate($from) || !$validDate($to) || $from > $to) {
    $error = 'Selecciona un rango de fechas válido.';
    $from = $today->modify('-30 days')->format('Y-m-d');
    $to = $today->format('Y-m-d');
}
if (!in_array($category, ['all', 'login', 'loans', 'sanctions', 'inventory'], true)) {
    $category = 'all';
}
$localZone = new DateTimeZone(date_default_timezone_get());
$utcZone = new DateTimeZone('UTC');
$fromUtc = (new DateTimeImmutable($from . ' 00:00:00', $localZone))->setTimezone($utcZone)->format('Y-m-d H:i:s');
$toUtc = (new DateTimeImmutable($to . ' 00:00:00', $localZone))->modify('+1 day')->setTimezone($utcZone)->format('Y-m-d H:i:s');
$where = ['datetime(a.created_at) >= :from', 'datetime(a.created_at) < :to'];
$bindings = [':from' => $fromUtc, ':to' => $toUtc];
$categorySql = [
    'login' => "(a.action LIKE 'LOGIN_%' OR a.action LIKE 'WEBAUTHN_%')",
    'loans' => "(a.action LIKE 'RESERVA_%' OR a.action LIKE 'PRESTAMO_%' OR a.action LIKE 'DEVOLUCION_%' OR a.action LIKE 'Préstamo%')",
    'sanctions' => "a.action = 'SANCIÓN_APLICADA'",
    'inventory' => "(a.action LIKE 'INVENTARIO_%' OR a.action LIKE 'Libro %')",
];
if ($category !== 'all') {
    $where[] = $categorySql[$category];
}
if ($search !== '') {
    $where[] = '(u.full_name LIKE :search_name OR u.email LIKE :search_email OR a.action LIKE :search_action OR a.details LIKE :search_details OR a.ip_address LIKE :search_ip)';
    $searchPattern = '%' . substr($search, 0, 120) . '%';
    $bindings[':search_name'] = $searchPattern;
    $bindings[':search_email'] = $searchPattern;
    $bindings[':search_action'] = $searchPattern;
    $bindings[':search_details'] = $searchPattern;
    $bindings[':search_ip'] = $searchPattern;
}
$whereSql = implode(' AND ', $where);
$count = $db->prepare("SELECT COUNT(*) FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id WHERE $whereSql");
$count->execute($bindings);
$total = (int) $count->fetchColumn();
$pages = max(1, (int) ceil($total / $perPage));
$page = min($page, $pages);
$offset = ($page - 1) * $perPage;
$stmt = $db->prepare("SELECT a.id, a.user_id, a.action, a.details, a.ip_address, a.created_at, u.full_name, u.email
    FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id
    WHERE $whereSql ORDER BY a.created_at DESC, a.id DESC LIMIT :limit OFFSET :offset");
foreach ($bindings as $key => $value) {
    $stmt->bindValue($key, $value, PDO::PARAM_STR);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$logs = $stmt->fetchAll();
$baseQuery = ['from' => $from, 'to' => $to, 'category' => $category, 'q' => $search];
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Auditoría | Biblioteca Virtual</title>
    <link rel="stylesheet" href="assets/css/styles.css">
    <script src="assets/js/app.js" defer></script>
</head>
<body>
    <div class="page-shell">
        <header class="topbar">
            <div class="brand-wrap"><div class="brand-mark">PPD</div><div><strong>Biblioteca Institucional</strong><small>IESTP “Pedro P. Díaz” · Arequipa</small></div></div>
            <nav class="main-nav">
                <a href="dashboard.php">Resumen</a><a href="catalog.php">Catálogo</a><a href="books.php">Inventario</a>
                <a href="loans.php">Préstamos</a><a href="reports.php">Reportes</a><a class="active" href="audit.php">Auditoría</a><a href="users.php">Usuarios</a>
            </nav>
            <div class="user-box"><span><?= esc($user['full_name']) ?></span><?php renderThemeToggle(); ?><a href="profile.php" class="btn btn-light">Mi perfil</a><a href="logout.php" class="btn btn-light">Salir</a></div>
        </header>

        <main class="container">
            <div class="page-heading"><div><p class="eyebrow">Solo personal autorizado</p><h1>Auditoría del sistema</h1><p class="page-description">Inicios de sesión, autorizaciones WebAuthn, préstamos, devoluciones, sanciones y cambios de inventario.</p></div></div>
            <?php if ($error !== ''): ?><div class="alert danger"><?= esc($error) ?></div><?php endif; ?>
            <form method="get" class="panel audit-filter-form">
                <label>Desde<input type="date" name="from" value="<?= esc($from) ?>" required></label>
                <label>Hasta<input type="date" name="to" value="<?= esc($to) ?>" required></label>
                <label>Evento
                    <select name="category">
                        <option value="all" <?= $category === 'all' ? 'selected' : '' ?>>Todos</option>
                        <option value="login" <?= $category === 'login' ? 'selected' : '' ?>>Autenticación</option>
                        <option value="loans" <?= $category === 'loans' ? 'selected' : '' ?>>Préstamos y reservas</option>
                        <option value="sanctions" <?= $category === 'sanctions' ? 'selected' : '' ?>>Sanciones</option>
                        <option value="inventory" <?= $category === 'inventory' ? 'selected' : '' ?>>Inventario</option>
                    </select>
                </label>
                <label>Buscar<input type="search" name="q" value="<?= esc($search) ?>" placeholder="Usuario, acción, detalle o IP"></label>
                <button type="submit" class="btn btn-primary">Filtrar</button>
            </form>

            <section class="panel audit-results">
                <div class="panel-header"><h2>Eventos registrados</h2><span class="status resolved"><?= $total ?> eventos</span></div>
                <div class="table-scroll"><table class="data-table"><thead><tr><th>Fecha y hora</th><th>Usuario</th><th>Evento</th><th>Detalle</th><th>Dirección IP</th></tr></thead><tbody>
                    <?php if (!$logs): ?><tr><td colspan="5" class="empty-state">No hay eventos para los filtros seleccionados.</td></tr><?php endif; ?>
                    <?php foreach ($logs as $log): ?><tr>
                        <td><?= esc($log['created_at']) ?></td>
                        <td><?= esc($log['full_name'] ?: 'Sistema') ?><?php if ($log['email']): ?><small class="audit-user-email"><?= esc($log['email']) ?></small><?php endif; ?></td>
                        <td><span class="audit-action"><?= esc($log['action']) ?></span></td>
                        <td class="audit-details"><?= esc($log['details'] ?? '') ?></td>
                        <td><?= esc($log['ip_address'] ?? '—') ?></td>
                    </tr><?php endforeach; ?>
                </tbody></table></div>
                <?php if ($pages > 1): ?><nav class="pagination" aria-label="Paginación de auditoría">
                    <?php if ($page > 1): ?><a class="btn btn-light btn-small" href="audit.php?<?= esc(http_build_query($baseQuery + ['page' => $page - 1])) ?>">Anterior</a><?php endif; ?>
                    <span>Página <?= $page ?> de <?= $pages ?></span>
                    <?php if ($page < $pages): ?><a class="btn btn-light btn-small" href="audit.php?<?= esc(http_build_query($baseQuery + ['page' => $page + 1])) ?>">Siguiente</a><?php endif; ?>
                </nav><?php endif; ?>
            </section>
        </main>
    </div>
</body>
</html>
