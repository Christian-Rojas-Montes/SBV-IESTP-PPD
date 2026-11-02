<?php
session_set_cookie_params([
    'httponly' => true,
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Lax',
]);
session_start();
date_default_timezone_set('America/Lima');

const DB_PATH = __DIR__ . '/database/biblioteca.sqlite';

function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $directory = dirname(DB_PATH);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $pdo = new PDO('sqlite:' . DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
        ensureDatabase($pdo);
    }

    return $pdo;
}

function ensureDatabase(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            full_name TEXT NOT NULL,
            email TEXT UNIQUE NOT NULL,
            password TEXT NOT NULL,
            role TEXT NOT NULL CHECK(role IN ('administrador', 'bibliotecario', 'docente', 'estudiante')),
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        );
    ");

    $userColumns = $pdo->query('PRAGMA table_info(users)')->fetchAll();
    $hasActiveColumn = false;
    foreach ($userColumns as $column) {
        if ($column['name'] === 'is_active') {
            $hasActiveColumn = true;
            break;
        }
    }

    if (!$hasActiveColumn) {
        $pdo->exec('ALTER TABLE users ADD COLUMN is_active INTEGER NOT NULL DEFAULT 1');
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS books (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            author TEXT NOT NULL,
            category TEXT NOT NULL,
            isbn TEXT,
            editorial TEXT,
            year INTEGER,
            language TEXT,
            description TEXT,
            cover TEXT,
            type TEXT NOT NULL CHECK(type IN ('fisico', 'digital')),
            copies_total INTEGER NOT NULL DEFAULT 1,
            copies_available INTEGER NOT NULL DEFAULT 1,
            digital_path TEXT
        );
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS loans (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            book_id INTEGER NOT NULL,
            loan_date TEXT NOT NULL DEFAULT CURRENT_DATE,
            due_date TEXT NOT NULL,
            return_date TEXT,
            status TEXT NOT NULL DEFAULT 'prestado',
            notes TEXT,
            FOREIGN KEY (user_id) REFERENCES users(id),
            FOREIGN KEY (book_id) REFERENCES books(id)
        );
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS reservations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            book_id INTEGER NOT NULL,
            reservation_date TEXT NOT NULL DEFAULT CURRENT_DATE,
            expiry_date TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'activa',
            FOREIGN KEY (user_id) REFERENCES users(id),
            FOREIGN KEY (book_id) REFERENCES books(id)
        );
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS audit_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            action TEXT NOT NULL,
            details TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id)
        );
    ");

    $bookCount = (int) $pdo->query('SELECT COUNT(*) FROM books')->fetchColumn();
    if ($bookCount === 0) {
        $books = [
            ['Programación orientada a objetos', 'Juan Salazar', 'Ingeniería de software', '978-84-123456-1-0', 'Editorial Técnica', 2024, 'Español', 'Guía práctica para el desarrollo de aplicaciones con enfoque orientado a objetos.', 'https://images.unsplash.com/photo-1515879218367-8466d910aaa4?auto=format&fit=crop&w=600&q=80', 'fisico', 3, 3, null],
            ['Fundamentos de base de datos', 'María López', 'Tecnologías de la información', '978-84-123456-2-7', 'Libros & Códigos', 2023, 'Español', 'Conceptos clave para el diseño, normalización y administración de bases de datos.', 'https://images.unsplash.com/photo-1521587760476-6c12a4b040da?auto=format&fit=crop&w=600&q=80', 'fisico', 2, 2, null],
            ['Diseño UI/UX para aplicaciones web', 'Luis Pérez', 'Diseño digital', '978-84-123456-3-4', 'Nómada Ediciones', 2025, 'Español', 'Principios de usabilidad y prototipado para construir experiencias digitales funcionales.', 'https://images.unsplash.com/photo-1516321165247-4aa89a48be28?auto=format&fit=crop&w=600&q=80', 'digital', 4, 4, 'storage/sample.pdf'],
            ['Electricidad industrial y mantenimiento', 'Sofía Quispe', 'Electrónica', '978-84-123456-4-1', 'Industrial Plus', 2022, 'Español', 'Manual técnico para la operación segura de instalaciones y equipos eléctricos.', 'https://images.unsplash.com/photo-1518770660439-4636190af475?auto=format&fit=crop&w=600&q=80', 'fisico', 2, 1, null],
            ['Matemática aplicada a la ingeniería', 'Ruben Farfán', 'Matemática', '978-84-123456-5-8', 'Academia Digital', 2024, 'Español', 'Aplicaciones prácticas de álgebra, cálculo y estadística en ingeniería.', 'https://images.unsplash.com/photo-1503676260728-1c00da094a0b?auto=format&fit=crop&w=600&q=80', 'fisico', 5, 5, null],
            ['Gestión de proyectos tecnológicos', 'Patricia Honores', 'Administración', '978-84-123456-6-5', 'Versión 360', 2021, 'Español', 'Herramientas y metodologías para ejecutar proyectos de software y sistemas.', 'https://images.unsplash.com/photo-1552664730-d307ca884978?auto=format&fit=crop&w=600&q=80', 'digital', 3, 3, 'storage/sample.pdf'],
            ['Seguridad informática y redes', 'Jorge Bellido', 'Ciberseguridad', '978-84-123456-7-2', 'NetSecure', 2025, 'Español', 'Protección de infraestructura, redes y servicios digitales frente a amenazas.', 'https://images.unsplash.com/photo-1516321497487-e288fb19713f?auto=format&fit=crop&w=600&q=80', 'fisico', 4, 4, null],
            ['Metodología de la investigación', 'Diana Canales', 'Investigación', '978-84-123456-8-9', 'Eureka Editores', 2023, 'Español', 'Estrategias para plantear problemas, recolectar datos y presentar resultados.', 'https://images.unsplash.com/photo-1522202176988-66273c2fd55f?auto=format&fit=crop&w=600&q=80', 'fisico', 2, 2, null],
        ];

        $stmt = $pdo->prepare('INSERT INTO books (title, author, category, isbn, editorial, year, language, description, cover, type, copies_total, copies_available, digital_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($books as $book) {
            $stmt->execute([$book[0], $book[1], $book[2], $book[3], $book[4], $book[5], $book[6], $book[7], $book[8], $book[9], $book[10], $book[11], $book[12]]);
        }
    }

    $loanCount = (int) $pdo->query('SELECT COUNT(*) FROM loans')->fetchColumn();
    if ($loanCount === 0) {
        $predicate = $pdo->query("SELECT id FROM users ORDER BY id LIMIT 1")->fetch();
        $bookIds = $pdo->query('SELECT id FROM books ORDER BY id LIMIT 3')->fetchAll(PDO::FETCH_COLUMN);

        if ($predicate && !empty($bookIds)) {
            $userId = (int) $predicate['id'];
            $bookId = (int) $bookIds[0];
            $pdo->exec("INSERT INTO loans (user_id, book_id, loan_date, due_date, status) VALUES ($userId, $bookId, date('now'), date('now', '+14 days'), 'prestado')");

            $bookToUpdate = (int) $bookIds[0];
            $pdo->exec("UPDATE books SET copies_available = copies_available - 1 WHERE id = $bookToUpdate AND copies_available > 0");
        }
    }
}

function currentUser(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    $userId = (int) $_SESSION['user_id'];
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    return $user && (int) $user['is_active'] === 1 ? $user : null;
}

function requireLogin(): void
{
    if (!currentUser()) {
        header('Location: index.php');
        exit;
    }
}

function hasRole(string ...$roles): bool
{
    $user = currentUser();
    return $user !== null && in_array($user['role'], $roles, true);
}

function esc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function formatDate(?string $date): string
{
    if (empty($date)) {
        return 'Sin fecha';
    }

    $timestamp = strtotime($date);
    if ($timestamp === false) {
        return 'Sin fecha';
    }

    return date('d/m/Y', $timestamp);
}

function setFlash(string $message): void
{
    $_SESSION['flash'] = $message;
}

function getFlash(): ?string
{
    if (empty($_SESSION['flash'])) {
        return null;
    }

    $message = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $message;
}

function logAudit(string $action, string $details = ''): void
{
    if (empty($_SESSION['user_id'])) {
        return;
    }

    $stmt = db()->prepare('INSERT INTO audit_logs (user_id, action, details, created_at) VALUES (?, ?, ?, datetime("now"))');
    $stmt->execute([$_SESSION['user_id'], $action, $details]);
}

function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

function totalExists(?int $value): int
{
    return max(0, (int) $value);
}

function getUserName(PDO $pdo, int $userId): string
{
    $stmt = $pdo->prepare('SELECT full_name FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();

    return $row['full_name'] ?? 'Usuario';
}

function getBookTitle(PDO $pdo, int $bookId): string
{
    $stmt = $pdo->prepare('SELECT title FROM books WHERE id = ?');
    $stmt->execute([$bookId]);
    $row = $stmt->fetch();

    return $row['title'] ?? 'Libro';
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verifyCsrfToken(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals(csrfToken(), $token)) {
        http_response_code(403);
        exit('La solicitud expiró o no es válida. Recarga la página e inténtalo nuevamente.');
    }
}

function renderThemeToggle(string $class = ''): void
{
    $className = $class === '' ? '' : ' ' . esc($class);
    echo '<button type="button" class="theme-toggle' . $className . '" data-theme-toggle aria-label="Activar modo oscuro" aria-pressed="false">'
        . '<span class="theme-toggle-icon" aria-hidden="true">☾</span>'
        . '<span class="theme-toggle-label">Modo oscuro</span>'
        . '</button>';
}
