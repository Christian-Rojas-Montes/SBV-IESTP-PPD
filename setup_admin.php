<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/app.php';

$db = db();
$adminCount = (int) $db->query("SELECT COUNT(*) FROM users WHERE role = 'personal'")->fetchColumn();
if ($adminCount > 0) {
    fwrite(STDERR, "Ya existe una cuenta de administrador. No se creó ninguna cuenta.\n");
    exit(1);
}

fwrite(STDOUT, "Nombre del administrador: ");
$name = trim((string) fgets(STDIN));
fwrite(STDOUT, "Correo del administrador: ");
$email = strtolower(trim((string) fgets(STDIN)));
$password = getenv('SBV_ADMIN_PASSWORD');

if ($name === '' || strlen($name) > 160 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
    fwrite(STDERR, "Ingresa un nombre y un correo electrónico válidos.\n");
    exit(1);
}

if (!is_string($password) || strlen($password) < 14) {
    fwrite(STDERR, "Define SBV_ADMIN_PASSWORD con una contraseña de al menos 14 caracteres y vuelve a ejecutar este comando.\n");
    exit(1);
}

$existing = $db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
$existing->execute([$email]);
if ($existing->fetch()) {
    fwrite(STDERR, "Ese correo ya está registrado. Elige otro correo para el administrador.\n");
    exit(1);
}

$db->prepare('INSERT INTO users (full_name, email, password, role) VALUES (?, ?, ?, ?)')
    ->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), 'personal']);

fwrite(STDOUT, "Cuenta de administrador creada correctamente. Ya puedes iniciar sesión.\n");
