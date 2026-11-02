<?php
require __DIR__ . '/app.php';

if (currentUser()) {
    logAudit('Cierre de sesión', 'Sesión cerrada');
}

session_unset();
session_destroy();

redirect('index.php?notice=Sesión cerrada correctamente.');
