<?php
require __DIR__ . '/app.php';

if (currentUser()) {
    redirect('dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken();

    $name = trim($_POST['full_name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $passwordConfirmation = $_POST['password_confirmation'] ?? '';

    if ($name === '' || strlen($name) > 160 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
        redirect('register.php?error=Ingresa un nombre y un correo electrónico válidos.');
    }

    if (strlen($password) < 10) {
        redirect('register.php?error=La contraseña debe tener al menos 10 caracteres.');
    }

    if (!hash_equals($password, $passwordConfirmation)) {
        redirect('register.php?error=Las contraseñas no coinciden.');
    }

    $db = db();
    $existing = $db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $existing->execute([$email]);
    if ($existing->fetch()) {
        redirect('register.php?error=Ese correo ya tiene una cuenta. Inicia sesión o utiliza otro correo.');
    }

    $db->prepare('INSERT INTO users (full_name, email, password, role) VALUES (?, ?, ?, ?)')
        ->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), 'estudiante']);

    $userId = (int) $db->lastInsertId();
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    logAudit('Cuenta de estudiante creada', $email . ' · Registro público');
    redirect('dashboard.php');
}

$error = $_GET['error'] ?? '';
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Crear cuenta de estudiante | Biblioteca IESTP PPD</title>
    <link rel="stylesheet" href="assets/css/styles.css">
    <script src="assets/js/app.js" defer></script>
</head>
<body class="auth-body">
    <main class="auth-card">
        <?php renderThemeToggle('auth-theme-toggle'); ?>
        <div class="auth-brand">
            <div class="brand-mark">PPD</div>
            <div>
                <p class="auth-kicker">Biblioteca institucional · Arequipa</p>
                <h1>Cuenta de estudiante</h1>
                <p>IESTP “Pedro P. Díaz”</p>
            </div>
        </div>

        <div class="auth-intro">
            <h2>Crea tu cuenta</h2>
            <p>Regístrate para consultar el catálogo y gestionar tus préstamos bibliotecarios.</p>
        </div>

        <?php if ($error !== ''): ?>
            <div class="alert danger" role="alert"><?= esc($error) ?></div>
        <?php endif; ?>

        <form method="post" action="register.php" class="auth-form">
            <input type="hidden" name="csrf_token" value="<?= esc(csrfToken()) ?>">
            <label>
                Nombre y apellidos
                <input type="text" name="full_name" autocomplete="name" maxlength="160" required>
            </label>

            <label>
                Correo electrónico
                <input type="email" name="email" autocomplete="email" maxlength="190" placeholder="Puede ser tu correo personal" required>
            </label>

            <label>
                Crea una contraseña
                <input type="password" name="password" autocomplete="new-password" minlength="10" required>
                <small class="field-help">Usa al menos 10 caracteres.</small>
            </label>

            <label>
                Confirma tu contraseña
                <input type="password" name="password_confirmation" autocomplete="new-password" minlength="10" required>
            </label>

            <button type="submit" class="btn btn-primary btn-block">Crear cuenta de estudiante</button>
        </form>

        <p class="auth-switch">¿Ya tienes una cuenta? <a href="index.php">Inicia sesión</a></p>
        <p class="auth-footer">El registro público crea únicamente cuentas de estudiante.</p>
    </main>
</body>
</html>
