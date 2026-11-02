<?php
require __DIR__ . '/app.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php');
}

$email = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';
$profile = $_POST['perfil'] ?? '';

if ($email === '' || $password === '' || !in_array($profile, ['estudiante', 'docente', 'personal'], true)) {
    redirect('index.php?error=Completa los campos y selecciona un tipo de acceso.&perfil=' . rawurlencode($profile));
}

$stmt = db()->prepare('SELECT * FROM users WHERE email = ? AND is_active = 1 LIMIT 1');
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, $user['password'])) {
    redirect('index.php?error=Correo o contraseña incorrectos.&perfil=' . rawurlencode($profile));
}

$profileMatches = match ($profile) {
    'estudiante' => $user['role'] === 'estudiante',
    'docente' => $user['role'] === 'docente',
    'personal' => in_array($user['role'], ['administrador', 'bibliotecario'], true),
};

if (!$profileMatches) {
    redirect('index.php?error=La cuenta no corresponde al tipo de acceso seleccionado.&perfil=' . rawurlencode($profile));
}

session_regenerate_id(true);
$_SESSION['user_id'] = (int) $user['id'];
logAudit('Inicio de sesión', 'Acceso mediante perfil: ' . $profile);
redirect('dashboard.php');
