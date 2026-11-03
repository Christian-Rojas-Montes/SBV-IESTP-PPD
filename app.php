<?php
session_set_cookie_params([
    'httponly' => true,
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Lax',
]);
session_start();
date_default_timezone_set('America/Lima');

const DB_PATH = __DIR__ . '/database/biblioteca.sqlite';
const INSTITUTION_CAREERS = [
    'Cursos Generales',
    'Asistencia Administrativa',
    'Construcción Civil',
    'Contabilidad',
    'Desarrollo de Sistemas de Información',
    'Electrónica Industrial',
    'Electricidad Industrial',
    'Mecatrónica Automotriz',
    'Mecánica de Producción Industrial',
    'Producción Agropecuaria',
];

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
            role TEXT NOT NULL CHECK(role IN ('estudiante', 'docente', 'personal')),
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            status_until DATETIME DEFAULT NULL
        );
    ");

    if (!tableHasColumn($pdo, 'users', 'is_active')) {
        $pdo->exec('ALTER TABLE users ADD COLUMN is_active INTEGER NOT NULL DEFAULT 1');
    }
    if (!userRoleSchemaIsCurrent($pdo)) {
        migrateUserRoles($pdo);
    } elseif (!tableHasColumn($pdo, 'users', 'status_until')) {
        $pdo->exec('ALTER TABLE users ADD COLUMN status_until DATETIME DEFAULT NULL');
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
            digital_path TEXT,
            qr_code TEXT UNIQUE,
            sede TEXT NOT NULL DEFAULT 'Sede Arequipa' CHECK(sede IN ('Sede Arequipa', 'Sede Majes')),
            career TEXT NOT NULL DEFAULT 'Cursos Generales',
            tags TEXT
        );
    ");

    if (!tableHasColumn($pdo, 'books', 'qr_code')) {
        $pdo->exec('ALTER TABLE books ADD COLUMN qr_code TEXT');
    }
    if (!tableHasColumn($pdo, 'books', 'sede')) {
        $pdo->exec("ALTER TABLE books ADD COLUMN sede TEXT NOT NULL DEFAULT 'Sede Arequipa' CHECK(sede IN ('Sede Arequipa', 'Sede Majes'))");
    }
    if (!tableHasColumn($pdo, 'books', 'career')) {
        $pdo->exec("ALTER TABLE books ADD COLUMN career TEXT NOT NULL DEFAULT 'Cursos Generales'");
    }
    if (!tableHasColumn($pdo, 'books', 'tags')) {
        $pdo->exec('ALTER TABLE books ADD COLUMN tags TEXT');
    }
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS books_qr_code_unique ON books(qr_code)');

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS book_copies (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            book_id INTEGER NOT NULL,
            qr_code TEXT UNIQUE NOT NULL,
            status TEXT NOT NULL DEFAULT 'disponible' CHECK(status IN ('disponible', 'prestado', 'no_disponible', 'retirado')),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE
        );
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS loans (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            book_id INTEGER NOT NULL,
            copy_id INTEGER,
            loan_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            pickup_date DATETIME DEFAULT NULL,
            due_date DATETIME NOT NULL,
            return_date DATETIME,
            status TEXT NOT NULL DEFAULT 'prestado' CHECK(status IN ('reservado', 'prestado', 'devuelto', 'vencido')),
            notes TEXT,
            FOREIGN KEY (user_id) REFERENCES users(id),
            FOREIGN KEY (book_id) REFERENCES books(id),
            FOREIGN KEY (copy_id) REFERENCES book_copies(id)
        );
    ");

    if (!loanSchemaIsCurrent($pdo)) {
        migrateLoans($pdo);
    }
    ensureLoanDateConstraints($pdo);

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
            ip_address TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id)
        );
    ");

    if (!auditLogSchemaIsCurrent($pdo)) {
        migrateAuditLogs($pdo);
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS webauthn_credentials (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            credential_id TEXT UNIQUE NOT NULL,
            public_key TEXT NOT NULL,
            device_name TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_used_at DATETIME,
            FOREIGN KEY (user_id) REFERENCES users(id)
        );
    ");
    if (!tableHasColumn($pdo, 'webauthn_credentials', 'sign_count')) {
        $pdo->exec('ALTER TABLE webauthn_credentials ADD COLUMN sign_count INTEGER NOT NULL DEFAULT 0');
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS login_requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            request_token TEXT UNIQUE NOT NULL,
            user_id INTEGER,
            challenge TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending', 'approved', 'rejected', 'expired')),
            device_info TEXT,
            expires_at DATETIME NOT NULL DEFAULT (datetime('now', '+5 minutes')),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id)
        );
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS digital_read_sessions (
            user_id INTEGER PRIMARY KEY,
            token_hash TEXT UNIQUE NOT NULL,
            book_id INTEGER NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_activity DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE
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
            $pdo->exec("INSERT INTO loans (user_id, book_id, loan_date, due_date, status) VALUES ($userId, $bookId, datetime('now'), date('now', '+14 days'), 'prestado')");

            $bookToUpdate = (int) $bookIds[0];
            $pdo->exec("UPDATE books SET copies_available = copies_available - 1 WHERE id = $bookToUpdate AND copies_available > 0");
        }
    }

    ensurePhysicalBookCopies($pdo);
    expirePhysicalReservations($pdo);
    expireLoginRequests($pdo);
}

function tableColumns(PDO $pdo, string $table): array
{
    $columns = $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll();
    return array_column($columns, 'name');
}

function tableHasColumn(PDO $pdo, string $table, string $columnName): bool
{
    return in_array($columnName, tableColumns($pdo, $table), true);
}

function userRoleSchemaIsCurrent(PDO $pdo): bool
{
    $schema = $pdo->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'users'")->fetchColumn();
    return is_string($schema)
        && preg_match("/CHECK\s*\(\s*role\s+IN\s*\(\s*'estudiante'\s*,\s*'docente'\s*,\s*'personal'\s*\)\s*\)/i", $schema) === 1;
}

function migrateUserRoles(PDO $pdo): void
{
    $columns = tableColumns($pdo, 'users');
    $activeExpression = in_array('is_active', $columns, true) ? 'is_active' : '1';
    $createdExpression = in_array('created_at', $columns, true) ? 'created_at' : 'CURRENT_TIMESTAMP';
    $statusExpression = in_array('status_until', $columns, true) ? 'status_until' : 'NULL';
    $foreignKeysEnabled = (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn() === 1;

    $pdo->exec('PRAGMA foreign_keys = OFF');
    try {
        $pdo->beginTransaction();
        $pdo->exec("
            CREATE TABLE users_migrated (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                full_name TEXT NOT NULL,
                email TEXT UNIQUE NOT NULL,
                password TEXT NOT NULL,
                role TEXT NOT NULL CHECK(role IN ('estudiante', 'docente', 'personal')),
                is_active INTEGER NOT NULL DEFAULT 1,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                status_until DATETIME DEFAULT NULL
            );
        ");
        $pdo->exec("
            INSERT INTO users_migrated (id, full_name, email, password, role, is_active, created_at, status_until)
            SELECT id, full_name, email, password,
                CASE
                    WHEN role IN ('administrador', 'bibliotecario', 'personal') THEN 'personal'
                    WHEN role IN ('estudiante', 'docente') THEN role
                    ELSE 'estudiante'
                END,
                $activeExpression, $createdExpression, $statusExpression
            FROM users;
        ");
        $pdo->exec('DROP TABLE users');
        $pdo->exec('ALTER TABLE users_migrated RENAME TO users');
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    } finally {
        if ($foreignKeysEnabled) {
            $pdo->exec('PRAGMA foreign_keys = ON');
        }
    }
}

function loanSchemaIsCurrent(PDO $pdo): bool
{
    $schema = $pdo->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'loans'")->fetchColumn();
    $columns = $pdo->query('PRAGMA table_info(loans)')->fetchAll();
    $dueDateType = '';
    foreach ($columns as $column) {
        if ($column['name'] === 'due_date') {
            $dueDateType = strtoupper((string) $column['type']);
            break;
        }
    }

    return is_string($schema)
        && tableHasColumn($pdo, 'loans', 'pickup_date')
        && tableHasColumn($pdo, 'loans', 'copy_id')
        && $dueDateType === 'DATETIME'
        && preg_match("/CHECK\s*\(\s*status\s+IN\s*\(\s*'reservado'\s*,\s*'prestado'\s*,\s*'devuelto'\s*,\s*'vencido'\s*\)\s*\)/i", $schema) === 1;
}

function migrateLoans(PDO $pdo): void
{
    $columns = tableColumns($pdo, 'loans');
    $loanDateExpression = in_array('loan_date', $columns, true) ? 'COALESCE(loan_date, CURRENT_DATE)' : 'CURRENT_DATE';
    $pickupExpression = in_array('pickup_date', $columns, true) ? 'pickup_date' : 'NULL';
    $copyExpression = in_array('copy_id', $columns, true) ? 'copy_id' : 'NULL';
    $dueDateExpression = in_array('due_date', $columns, true)
        ? "COALESCE(due_date, datetime($loanDateExpression, '+14 days'))"
        : "datetime($loanDateExpression, '+14 days')";
    $returnDateExpression = in_array('return_date', $columns, true) ? 'return_date' : 'NULL';
    $statusExpression = in_array('status', $columns, true)
        ? "CASE WHEN status IN ('reservado', 'prestado', 'devuelto', 'vencido') THEN status WHEN lower(status) IN ('activa', 'pendiente', 'reservada') THEN 'reservado' ELSE 'prestado' END"
        : "'prestado'";
    $notesExpression = in_array('notes', $columns, true) ? 'notes' : 'NULL';
    $foreignKeysEnabled = (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn() === 1;

    $pdo->exec('PRAGMA foreign_keys = OFF');
    try {
        $pdo->beginTransaction();
        $pdo->exec("
            CREATE TABLE loans_migrated (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                book_id INTEGER NOT NULL,
                copy_id INTEGER,
                loan_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                pickup_date DATETIME DEFAULT NULL,
                due_date DATETIME NOT NULL,
                return_date DATETIME,
                status TEXT NOT NULL DEFAULT 'prestado' CHECK(status IN ('reservado', 'prestado', 'devuelto', 'vencido')),
                notes TEXT,
                FOREIGN KEY (user_id) REFERENCES users(id),
                FOREIGN KEY (book_id) REFERENCES books(id),
                FOREIGN KEY (copy_id) REFERENCES book_copies(id)
            );
        ");
        $pdo->exec("
            INSERT INTO loans_migrated (id, user_id, book_id, copy_id, loan_date, pickup_date, due_date, return_date, status, notes)
            SELECT id, user_id, book_id, $copyExpression, $loanDateExpression, $pickupExpression, $dueDateExpression,
                $returnDateExpression, $statusExpression, $notesExpression
            FROM loans;
        ");
        $pdo->exec('DROP TABLE loans');
        $pdo->exec('ALTER TABLE loans_migrated RENAME TO loans');
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    } finally {
        if ($foreignKeysEnabled) {
            $pdo->exec('PRAGMA foreign_keys = ON');
        }
    }
}

function ensureLoanDateConstraints(PDO $pdo): void
{
    $pdo->exec('DROP TRIGGER IF EXISTS loans_validate_pickup_date_insert');
    $pdo->exec('DROP TRIGGER IF EXISTS loans_validate_pickup_date_update');
    $pdo->exec('DROP TRIGGER IF EXISTS loans_validate_due_date_insert');
    $pdo->exec('DROP TRIGGER IF EXISTS loans_validate_due_date_update');
    $pdo->exec('DROP TRIGGER IF EXISTS loans_validate_due_date_insert_v2');
    $pdo->exec('DROP TRIGGER IF EXISTS loans_validate_due_date_update_v2');

    $pdo->exec("
        CREATE TRIGGER IF NOT EXISTS loans_validate_pickup_date_insert_v2
        BEFORE INSERT ON loans
        WHEN NEW.pickup_date IS NOT NULL AND (
            julianday(NEW.pickup_date) IS NULL
            OR julianday(NEW.loan_date) IS NULL
            OR julianday(NEW.pickup_date) < julianday(NEW.loan_date)
            OR julianday(NEW.pickup_date) > julianday(NEW.loan_date, '+48 hours')
        )
        BEGIN
            SELECT RAISE(ABORT, 'pickup_date must be within 48 hours of loan_date');
        END;
    ");
    $pdo->exec("
        CREATE TRIGGER IF NOT EXISTS loans_validate_pickup_date_update_v2
        BEFORE UPDATE OF pickup_date, loan_date ON loans
        WHEN NEW.pickup_date IS NOT NULL AND (
            julianday(NEW.pickup_date) IS NULL
            OR julianday(NEW.loan_date) IS NULL
            OR julianday(NEW.pickup_date) < julianday(NEW.loan_date)
            OR julianday(NEW.pickup_date) > julianday(NEW.loan_date, '+48 hours')
        )
        BEGIN
            SELECT RAISE(ABORT, 'pickup_date must be within 48 hours of loan_date');
        END;
    ");
    $pdo->exec("
        CREATE TRIGGER IF NOT EXISTS loans_validate_due_date_insert_v3
        BEFORE INSERT ON loans
        WHEN julianday(NEW.due_date) IS NULL
            OR julianday(NEW.loan_date) IS NULL
            OR julianday(NEW.due_date) < julianday(COALESCE(NEW.pickup_date, NEW.loan_date), '+1 day')
            OR julianday(NEW.due_date) > julianday(COALESCE(NEW.pickup_date, NEW.loan_date), '+21 days')
        BEGIN
            SELECT RAISE(ABORT, 'due_date must be 1 to 21 days after pickup_date');
        END;
    ");
    $pdo->exec("
        CREATE TRIGGER IF NOT EXISTS loans_validate_due_date_update_v3
        BEFORE UPDATE OF due_date, loan_date, pickup_date ON loans
        WHEN julianday(NEW.due_date) IS NULL
            OR julianday(NEW.loan_date) IS NULL
            OR julianday(NEW.due_date) < julianday(COALESCE(NEW.pickup_date, NEW.loan_date), '+1 day')
            OR julianday(NEW.due_date) > julianday(COALESCE(NEW.pickup_date, NEW.loan_date), '+21 days')
        BEGIN
            SELECT RAISE(ABORT, 'due_date must be 1 to 21 days after pickup_date');
        END;
    ");
}

function auditLogSchemaIsCurrent(PDO $pdo): bool
{
    $columns = $pdo->query('PRAGMA table_info(audit_logs)')->fetchAll();
    $createdAtType = '';
    $hasIpAddress = false;
    foreach ($columns as $column) {
        if ($column['name'] === 'created_at') {
            $createdAtType = strtoupper((string) $column['type']);
        } elseif ($column['name'] === 'ip_address') {
            $hasIpAddress = true;
        }
    }

    return $createdAtType === 'DATETIME' && $hasIpAddress;
}

function migrateAuditLogs(PDO $pdo): void
{
    $columns = tableColumns($pdo, 'audit_logs');
    $userExpression = in_array('user_id', $columns, true) ? 'user_id' : 'NULL';
    $detailsExpression = in_array('details', $columns, true) ? 'details' : 'NULL';
    $ipExpression = in_array('ip_address', $columns, true) ? 'ip_address' : 'NULL';
    $createdAtExpression = in_array('created_at', $columns, true) ? 'COALESCE(created_at, CURRENT_TIMESTAMP)' : 'CURRENT_TIMESTAMP';
    $foreignKeysEnabled = (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn() === 1;

    $pdo->exec('PRAGMA foreign_keys = OFF');
    try {
        $pdo->beginTransaction();
        $pdo->exec("
            CREATE TABLE audit_logs_migrated (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER,
                action TEXT NOT NULL,
                details TEXT,
                ip_address TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id)
            );
        ");
        $pdo->exec("
            INSERT INTO audit_logs_migrated (id, user_id, action, details, ip_address, created_at)
            SELECT id, $userExpression, action, $detailsExpression, $ipExpression, $createdAtExpression
            FROM audit_logs;
        ");
        $pdo->exec('DROP TABLE audit_logs');
        $pdo->exec('ALTER TABLE audit_logs_migrated RENAME TO audit_logs');
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    } finally {
        if ($foreignKeysEnabled) {
            $pdo->exec('PRAGMA foreign_keys = ON');
        }
    }
}

function generateBookCopyQrCode(): string
{
    return 'PPD-COPY-' . strtoupper(bin2hex(random_bytes(16)));
}

function createBookCopies(PDO $pdo, int $bookId, int $count, string $status = 'disponible'): void
{
    if ($count <= 0) {
        return;
    }

    $stmt = $pdo->prepare('INSERT INTO book_copies (book_id, qr_code, status) VALUES (?, ?, ?)');
    for ($index = 0; $index < $count; $index++) {
        $stmt->execute([$bookId, generateBookCopyQrCode(), $status]);
    }
}

function ensurePhysicalBookCopies(PDO $pdo): void
{
    $books = $pdo->query("SELECT id, copies_total, copies_available FROM books WHERE type = 'fisico'")->fetchAll();
    $countCopies = $pdo->prepare("SELECT COUNT(*) FROM book_copies WHERE book_id = ? AND status <> 'retirado'");
    $legacyLoans = $pdo->prepare("SELECT id, status FROM loans WHERE book_id = ? AND copy_id IS NULL AND status IN ('reservado', 'prestado', 'vencido') ORDER BY loan_date, id");
    $availableCopies = $pdo->prepare("SELECT id FROM book_copies WHERE book_id = ? AND status = 'disponible' ORDER BY id");
    $claimCopy = $pdo->prepare("UPDATE book_copies SET status = ? WHERE id = ? AND status = 'disponible'");
    $linkLoan = $pdo->prepare('UPDATE loans SET copy_id = ? WHERE id = ? AND copy_id IS NULL');
    $setUnavailable = $pdo->prepare("UPDATE book_copies SET status = 'no_disponible' WHERE id = ? AND status = 'disponible'");
    $syncAvailability = $pdo->prepare("UPDATE books SET copies_available = (SELECT COUNT(*) FROM book_copies WHERE book_id = ? AND status = 'disponible') WHERE id = ?");

    foreach ($books as $book) {
        $bookId = (int) $book['id'];
        $total = max(0, (int) $book['copies_total']);
        $countCopies->execute([$bookId]);
        $existingCopies = (int) $countCopies->fetchColumn();

        if ($existingCopies < $total) {
            createBookCopies($pdo, $bookId, $total - $existingCopies);
        }

        $legacyLoans->execute([$bookId]);
        $loanRows = $legacyLoans->fetchAll();
        $availableCopies->execute([$bookId]);
        $copyIds = array_map('intval', $availableCopies->fetchAll(PDO::FETCH_COLUMN));
        $linkedCount = min(count($loanRows), count($copyIds));

        for ($index = 0; $index < $linkedCount; $index++) {
            $copyStatus = $loanRows[$index]['status'] === 'reservado' ? 'no_disponible' : 'prestado';
            $claimCopy->execute([$copyStatus, $copyIds[$index]]);
            if ($claimCopy->rowCount() === 1) {
                $linkLoan->execute([$copyIds[$index], (int) $loanRows[$index]['id']]);
            }
        }

        if ($existingCopies === 0 && $total > 0) {
            $availableNow = max(0, $total - $linkedCount);
            $desiredAvailable = min(max(0, (int) $book['copies_available']), $availableNow);
            $unavailableCount = $availableNow - $desiredAvailable;
            if ($unavailableCount > 0) {
                $availableCopies->execute([$bookId]);
                $unusedCopyIds = $availableCopies->fetchAll(PDO::FETCH_COLUMN);
                foreach (array_slice($unusedCopyIds, 0, $unavailableCount) as $copyId) {
                    $setUnavailable->execute([(int) $copyId]);
                }
            }
        }

        $syncAvailability->execute([$bookId, $bookId]);
    }
}

function activePhysicalLoanCount(PDO $pdo, int $userId): int
{
    $stmt = $pdo->prepare("SELECT COUNT(*)
        FROM loans l
        INNER JOIN books b ON b.id = l.book_id
        LEFT JOIN book_copies c ON c.id = l.copy_id
        WHERE l.user_id = ?
            AND b.type = 'fisico'
            AND l.return_date IS NULL
            AND (
                (l.status = 'reservado' AND c.status = 'no_disponible')
                OR (l.status IN ('prestado', 'vencido') AND (l.copy_id IS NULL OR c.status = 'prestado'))
            )");
    $stmt->execute([$userId]);

    return (int) $stmt->fetchColumn();
}

function userHasActiveSanction(PDO $pdo, int $userId): bool
{
    $stmt = $pdo->prepare("SELECT CASE
        WHEN status_until IS NOT NULL AND julianday(status_until) > julianday('now') THEN 1
        ELSE 0
    END FROM users WHERE id = ?");
    $stmt->execute([$userId]);

    return (int) $stmt->fetchColumn() === 1;
}

function physicalReservationBlockReason(PDO $pdo, int $userId): ?string
{
    if (userHasActiveSanction($pdo, $userId)) {
        return 'Tienes una sanción activa. No puedes reservar ni recibir préstamos físicos; el acceso digital sigue disponible.';
    }
    if (activePhysicalLoanCount($pdo, $userId) >= 2) {
        return 'Ya tienes 2 ejemplares físicos prestados o reservados. Devuelve uno para solicitar otro.';
    }

    return null;
}

function applyUserSanction(PDO $pdo, int $userId, string $reason): void
{
    $pdo->prepare("UPDATE users SET status_until = datetime('now', '+14 days') WHERE id = ?")
        ->execute([$userId]);
    $pdo->prepare('INSERT INTO audit_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)')
        ->execute([$userId, 'SANCIÓN_APLICADA', $reason, $_SERVER['REMOTE_ADDR'] ?? null]);
}

function processLoanReturn(PDO $pdo, int $loanId, int $actorId): array
{
    $stmt = $pdo->prepare("SELECT l.*, b.title, u.full_name AS borrower_name, c.status AS copy_status, c.qr_code
        FROM loans l
        INNER JOIN books b ON b.id = l.book_id
        INNER JOIN users u ON u.id = l.user_id
        LEFT JOIN book_copies c ON c.id = l.copy_id
        WHERE l.id = ?");
    $stmt->execute([$loanId]);
    $loan = $stmt->fetch();
    if (!$loan || !in_array($loan['status'], ['prestado', 'vencido'], true) || $loan['return_date'] !== null) {
        return ['ok' => false, 'late' => false];
    }
    if (!empty($loan['copy_id']) && $loan['copy_status'] !== 'prestado') {
        return ['ok' => false, 'late' => false];
    }

    $lateCheck = $pdo->prepare("SELECT CASE WHEN julianday('now') > julianday(?) THEN 1 ELSE 0 END");
    $lateCheck->execute([$loan['due_date']]);
    $late = (int) $lateCheck->fetchColumn() === 1;
    $pdo->beginTransaction();
    try {
        $update = $pdo->prepare("UPDATE loans
            SET return_date = datetime('now'), status = ?
            WHERE id = ? AND status IN ('prestado', 'vencido') AND return_date IS NULL");
        $update->execute([$late ? 'vencido' : 'devuelto', $loanId]);
        if ($update->rowCount() !== 1) {
            $pdo->rollBack();
            return ['ok' => false, 'late' => false];
        }

        if (!empty($loan['copy_id'])) {
            $release = $pdo->prepare("UPDATE book_copies SET status = 'disponible' WHERE id = ? AND status = 'prestado'");
            $release->execute([(int) $loan['copy_id']]);
            if ($release->rowCount() === 1) {
                $pdo->prepare('UPDATE books SET copies_available = MIN(copies_total, copies_available + 1) WHERE id = ?')
                    ->execute([(int) $loan['book_id']]);
            }
        } else {
            $pdo->prepare('UPDATE books SET copies_available = MIN(copies_total, copies_available + 1) WHERE id = ?')
                ->execute([(int) $loan['book_id']]);
        }

        $details = $loan['borrower_name'] . ' devolvió ' . $loan['title']
            . (!empty($loan['qr_code']) ? ' · ejemplar ' . $loan['qr_code'] : '');
        $audit = $pdo->prepare('INSERT INTO audit_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)');
        $audit->execute([$actorId, $late ? 'DEVOLUCION_TARDIA' : 'DEVOLUCION_REGISTRADA', $details, $_SERVER['REMOTE_ADDR'] ?? null]);
        if ($late) {
            applyUserSanction($pdo, (int) $loan['user_id'], $details . ' · retraso respecto a due_date');
        }

        $pdo->commit();
        return ['ok' => true, 'late' => $late];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function expirePhysicalReservations(PDO $pdo): int
{
    $expired = $pdo->query("SELECT l.id, l.user_id, l.book_id, l.copy_id, b.title, c.qr_code
        FROM loans l
        INNER JOIN books b ON b.id = l.book_id
        LEFT JOIN book_copies c ON c.id = l.copy_id
        WHERE b.type = 'fisico' AND l.status = 'reservado'
            AND l.loan_date <= datetime('now', '-48 hours')
        ORDER BY l.id ASC")->fetchAll();
    if (!$expired) {
        return 0;
    }

    $expireLoan = $pdo->prepare("UPDATE loans SET status = 'vencido'
        WHERE id = ? AND status = 'reservado'");
    $releaseCopy = $pdo->prepare("UPDATE book_copies SET status = 'disponible'
        WHERE id = ? AND status = 'no_disponible'");
    $restoreAvailability = $pdo->prepare('UPDATE books SET copies_available = MIN(copies_total, copies_available + 1) WHERE id = ?');
    $audit = $pdo->prepare('INSERT INTO audit_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)');
    $count = 0;

    $pdo->beginTransaction();
    try {
        foreach ($expired as $reservation) {
            $expireLoan->execute([(int) $reservation['id']]);
            if ($expireLoan->rowCount() !== 1) {
                continue;
            }

            if (!empty($reservation['copy_id'])) {
                $releaseCopy->execute([(int) $reservation['copy_id']]);
                if ($releaseCopy->rowCount() === 1) {
                    $restoreAvailability->execute([(int) $reservation['book_id']]);
                }
            }

            $details = 'Reserva vencida sin entrega en 48 horas: ' . $reservation['title']
                . (!empty($reservation['qr_code']) ? ' · ejemplar ' . $reservation['qr_code'] : '');
            $audit->execute([(int) $reservation['user_id'], 'RESERVA_VENCIDA', $details, $_SERVER['REMOTE_ADDR'] ?? null]);
            applyUserSanction($pdo, (int) $reservation['user_id'], $details);
            $count++;
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }

    return $count;
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

function formatLoanDate(?string $date): string
{
    if (empty($date)) {
        return 'Sin fecha';
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
        return formatDate($date);
    }

    try {
        $storedUtc = new DateTimeImmutable($date, new DateTimeZone('UTC'));
        return $storedUtc->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('d/m/Y');
    } catch (Throwable) {
        return formatDate($date);
    }
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
    $userId = empty($_SESSION['user_id']) ? null : (int) $_SESSION['user_id'];
    recordAuditLog($userId, $action, $details);
}

function recordAuditLog(?int $userId, string $action, string $details = '', ?string $ipAddress = null): void
{
    $ipAddress ??= $_SERVER['REMOTE_ADDR'] ?? null;
    $stmt = db()->prepare('INSERT INTO audit_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)');
    $stmt->execute([$userId, $action, $details, $ipAddress]);
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

function createWebAuthnCredential(int $userId, string $credentialId, string $publicKey, ?string $deviceName = null): int
{
    $stmt = db()->prepare('INSERT INTO webauthn_credentials (user_id, credential_id, public_key, device_name) VALUES (?, ?, ?, ?)');
    $stmt->execute([$userId, $credentialId, $publicKey, $deviceName]);
    return (int) db()->lastInsertId();
}

function findWebAuthnCredential(string $credentialId): ?array
{
    $stmt = db()->prepare('SELECT * FROM webauthn_credentials WHERE credential_id = ? LIMIT 1');
    $stmt->execute([$credentialId]);
    $credential = $stmt->fetch();
    return $credential ?: null;
}

function listWebAuthnCredentials(int $userId): array
{
    $stmt = db()->prepare('SELECT id, user_id, credential_id, device_name, created_at, last_used_at FROM webauthn_credentials WHERE user_id = ? ORDER BY created_at DESC, id DESC');
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function markWebAuthnCredentialUsed(string $credentialId, int $signCount = 0): bool
{
    if ($signCount > 0) {
        $stmt = db()->prepare('UPDATE webauthn_credentials SET last_used_at = CURRENT_TIMESTAMP, sign_count = ? WHERE credential_id = ? AND sign_count < ?');
        $stmt->execute([$signCount, $credentialId, $signCount]);
    } else {
        $stmt = db()->prepare('UPDATE webauthn_credentials SET last_used_at = CURRENT_TIMESTAMP WHERE credential_id = ? AND sign_count = 0');
        $stmt->execute([$credentialId]);
    }
    return $stmt->rowCount() > 0;
}

function webauthnBase64UrlEncode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function webauthnBase64UrlDecode(string $data): ?string
{
    if ($data === '' || preg_match('/^[A-Za-z0-9_-]+$/', $data) !== 1) {
        return null;
    }
    $decoded = base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4), true);
    return $decoded === false ? null : $decoded;
}

function webauthnCborRead(string $data, int &$offset): mixed
{
    if ($offset >= strlen($data)) {
        throw new UnexpectedValueException('CBOR incompleto.');
    }
    $initial = ord($data[$offset++]);
    $major = $initial >> 5;
    $additional = $initial & 31;
    if ($major === 7) {
        return match ($additional) {
            20 => false,
            21 => true,
            22 => null,
            default => throw new UnexpectedValueException('Valor simple CBOR no compatible.'),
        };
    }
    if ($additional < 24) {
        $length = $additional;
    } elseif ($additional === 24 && $offset + 1 <= strlen($data)) {
        $length = ord($data[$offset++]);
    } elseif ($additional === 25 && $offset + 2 <= strlen($data)) {
        $length = unpack('nvalue', substr($data, $offset, 2))['value'];
        $offset += 2;
    } elseif ($additional === 26 && $offset + 4 <= strlen($data)) {
        $length = unpack('Nvalue', substr($data, $offset, 4))['value'];
        $offset += 4;
    } elseif ($additional === 27 && $offset + 8 <= strlen($data)) {
        $parts = unpack('Nhigh/Nlow', substr($data, $offset, 8));
        $length = $parts['high'] * 4294967296 + $parts['low'];
        $offset += 8;
    } else {
        throw new UnexpectedValueException('Tipo o longitud CBOR no compatible.');
    }

    if (!is_int($length) || $length > 65536) {
        throw new UnexpectedValueException('Longitud CBOR no permitida.');
    }

    if ($major === 0) {
        return $length;
    }
    if ($major === 1) {
        return -1 - $length;
    }
    if ($major === 2 || $major === 3) {
        if ($offset + $length > strlen($data)) {
            throw new UnexpectedValueException('Longitud CBOR fuera de rango.');
        }
        $value = substr($data, $offset, $length);
        $offset += $length;
        return $value;
    }
    if ($major === 4) {
        $items = [];
        for ($index = 0; $index < $length; $index++) {
            $items[] = webauthnCborRead($data, $offset);
        }
        return $items;
    }
    if ($major === 5) {
        $items = [];
        for ($index = 0; $index < $length; $index++) {
            $key = webauthnCborRead($data, $offset);
            $items[$key] = webauthnCborRead($data, $offset);
        }
        return $items;
    }
    if ($major === 6) {
        return webauthnCborRead($data, $offset);
    }
    throw new UnexpectedValueException('Elemento CBOR no compatible.');
}

function webauthnCoseEcPublicKeyPem(array $coseKey): ?string
{
    if (($coseKey[1] ?? null) !== 2 || ($coseKey[3] ?? null) !== -7 || ($coseKey[-1] ?? null) !== 1
        || !is_string($coseKey[-2] ?? null) || !is_string($coseKey[-3] ?? null)
        || strlen($coseKey[-2]) !== 32 || strlen($coseKey[-3]) !== 32) {
        return null;
    }
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d03010703420004') . $coseKey[-2] . $coseKey[-3];
    $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    return openssl_pkey_get_public($pem) === false ? null : $pem;
}

function deleteWebAuthnCredential(int $userId, string $credentialId): bool
{
    $stmt = db()->prepare('DELETE FROM webauthn_credentials WHERE user_id = ? AND credential_id = ?');
    $stmt->execute([$userId, $credentialId]);
    return $stmt->rowCount() > 0;
}

function createLoginRequest(string $requestToken, string $challenge, ?string $deviceInfo = null, ?int $userId = null): int
{
    $stmt = db()->prepare("INSERT INTO login_requests (request_token, user_id, challenge, device_info, expires_at) VALUES (?, ?, ?, ?, datetime('now', '+5 minutes'))");
    $stmt->execute([$requestToken, $userId, $challenge, $deviceInfo]);
    return (int) db()->lastInsertId();
}

function findLoginRequest(string $requestToken): ?array
{
    $pdo = db();
    $expire = $pdo->prepare("UPDATE login_requests SET status = 'expired' WHERE request_token = ? AND status = 'pending' AND expires_at <= datetime('now')");
    $expire->execute([$requestToken]);

    $stmt = $pdo->prepare('SELECT * FROM login_requests WHERE request_token = ? LIMIT 1');
    $stmt->execute([$requestToken]);
    $request = $stmt->fetch();
    return $request ?: null;
}

function updateLoginRequestStatus(string $requestToken, string $status, ?int $userId = null): bool
{
    if (!in_array($status, ['approved', 'rejected'], true)) {
        throw new InvalidArgumentException('El estado de la solicitud debe ser approved o rejected.');
    }

    $stmt = db()->prepare("UPDATE login_requests SET status = ?, user_id = COALESCE(?, user_id) WHERE request_token = ? AND status = 'pending' AND expires_at > datetime('now')");
    $stmt->execute([$status, $userId, $requestToken]);
    return $stmt->rowCount() > 0;
}

function expireLoginRequests(?PDO $pdo = null): int
{
    $pdo ??= db();
    $stmt = $pdo->prepare("UPDATE login_requests SET status = 'expired' WHERE status = 'pending' AND expires_at <= datetime('now')");
    $stmt->execute();
    return $stmt->rowCount();
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
