<?php
require __DIR__ . '/app.php';

if (currentUser()) {
    redirect('dashboard.php');
}

$error = $_GET['error'] ?? '';
$notice = $_GET['notice'] ?? '';
$selectedRole = $_GET['perfil'] ?? 'estudiante';
if (!in_array($selectedRole, ['estudiante', 'docente', 'personal'], true)) {
    $selectedRole = 'estudiante';
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Biblioteca Virtual IESTP PPD</title>
    <link rel="stylesheet" href="assets/css/styles.css">
    <script src="assets/js/app.js" defer></script>
</head>
<body class="auth-body">
    <div class="auth-card">
        <?php renderThemeToggle('auth-theme-toggle'); ?>
        <div class="auth-brand">
            <div class="brand-mark">PPD</div>
            <div>
                <p class="auth-kicker">Instituto de Educación Superior Tecnológico Público</p>
                <h1>Pedro P. Díaz</h1>
                <p>Biblioteca institucional · Arequipa</p>
            </div>
        </div>

        <div class="auth-intro">
            <h2>Acceso a la plataforma</h2>
            <p>Ingresa con tu cuenta institucional para consultar el catálogo y los servicios bibliotecarios.</p>
        </div>

        <?php if ($error !== ''): ?>
            <div class="alert danger"><?= esc($error) ?></div>
        <?php endif; ?>

        <?php if ($notice !== ''): ?>
            <div class="alert info"><?= esc($notice) ?></div>
        <?php endif; ?>

        <form method="post" action="login.php" class="auth-form">
            <fieldset class="access-type">
                <legend>Selecciona tu tipo de acceso</legend>
                <div class="access-options">
                    <label class="access-option">
                        <input type="radio" name="perfil" value="estudiante" <?= $selectedRole === 'estudiante' ? 'checked' : '' ?>>
                        <span><strong>Estudiante</strong><small>Acceso para alumnos</small></span>
                    </label>
                    <label class="access-option">
                        <input type="radio" name="perfil" value="docente" <?= $selectedRole === 'docente' ? 'checked' : '' ?>>
                        <span><strong>Docente</strong><small>Acceso para profesores</small></span>
                    </label>
                    <label class="access-option">
                        <input type="radio" name="perfil" value="personal" <?= $selectedRole === 'personal' ? 'checked' : '' ?>>
                        <span><strong>Personal</strong><small>Biblioteca y administración</small></span>
                    </label>
                </div>
            </fieldset>

            <label>
                Correo electrónico
                <input type="email" name="email" placeholder="Tu correo personal o institucional" autocomplete="username" required>
            </label>

            <label>
                Contraseña
                <input type="password" name="password" placeholder="••••••••" autocomplete="current-password" required>
            </label>

            <button type="submit" class="btn btn-primary btn-block">Ingresar</button>
        </form>
        <section class="quick-login" data-webauthn-login aria-live="polite">
            <div class="quick-login-divider"><span>o continúa con tu dispositivo</span></div>
            <button type="button" class="btn btn-secondary btn-block" data-quick-login-start>Inicio de Sesión Rápido</button>
            <p class="field-help" data-quick-login-status>Autoriza el acceso desde un dispositivo móvil que ya tengas vinculado.</p>
            <div class="quick-login-wait" data-quick-login-wait hidden>
                <strong>Solicitud enviada al móvil</strong>
                <span data-quick-login-countdown>Expira en 5:00</span>
                <button type="button" class="btn btn-light btn-small" data-quick-login-cancel>Cancelar solicitud</button>
            </div>
        </section>
        <p class="auth-switch">¿Eres estudiante nuevo? <a href="register.php">Crea tu cuenta</a></p>
        <p class="auth-footer">Sistema de Biblioteca Virtual · IESTP “Pedro P. Díaz”</p>
    </div>
</body>
</html>
