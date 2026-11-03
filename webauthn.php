<?php
require __DIR__ . '/app.php';

function webAuthnJson(int $status, array $data): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function webAuthnPayload(): array
{
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 65536) {
        webAuthnJson(413, ['error' => 'La respuesta WebAuthn supera el tamaño permitido.']);
    }
    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    if (str_contains($contentType, 'application/json')) {
        $decoded = json_decode((string) file_get_contents('php://input'), true);
        return is_array($decoded) ? $decoded : [];
    }
    return $_POST;
}

function webAuthnVerifyCsrf(array $payload): void
{
    $token = $payload['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals(csrfToken(), $token)) {
        webAuthnJson(403, ['error' => 'La sesión del formulario expiró. Recarga la página.']);
    }
}

function webAuthnOriginAndRpId(): array
{
    $hostHeader = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ($hostHeader === '' || preg_match('/^[a-z0-9.-]+(?::[0-9]{1,5})?$/', $hostHeader) !== 1) {
        webAuthnJson(400, ['error' => 'El dominio de autenticación no es válido.']);
    }
    $host = parse_url('http://' . $hostHeader, PHP_URL_HOST);
    $port = parse_url('http://' . $hostHeader, PHP_URL_PORT);
    if (!is_string($host) || $host === '') {
        webAuthnJson(400, ['error' => 'El dominio de autenticación no es válido.']);
    }
    $scheme = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
    $originHost = strtolower($host);
    if ($port !== null && !(($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80))) {
        $originHost .= ':' . $port;
    }
    return [$scheme . '://' . $originHost, strtolower($host)];
}

function webAuthnVerifyClientData(string $clientDataJson, string $expectedType, string $challenge, string $origin): ?array
{
    $data = json_decode($clientDataJson, true);
    if (!is_array($data)
        || ($data['type'] ?? null) !== $expectedType
        || !is_string($data['challenge'] ?? null)
        || !hash_equals($challenge, $data['challenge'])
        || !is_string($data['origin'] ?? null)
        || !hash_equals($origin, $data['origin'])
        || !empty($data['crossOrigin'])) {
        return null;
    }
    return $data;
}

function webAuthnReadAuthenticatorFlags(string $authenticatorData, string $rpId): ?array
{
    if (strlen($authenticatorData) < 37 || !hash_equals(hash('sha256', $rpId, true), substr($authenticatorData, 0, 32))) {
        return null;
    }
    $flags = ord($authenticatorData[32]);
    if (($flags & 0x01) === 0 || ($flags & 0x04) === 0) {
        return null;
    }
    $signCount = unpack('Ncount', substr($authenticatorData, 33, 4))['count'];
    return ['flags' => $flags, 'sign_count' => $signCount];
}

function webAuthnParseRegistration(string $attestationObject, string $clientDataJson, string $expectedChallenge, string $origin, string $rpId): ?array
{
    try {
        $offset = 0;
        $attestation = webauthnCborRead($attestationObject, $offset);
        if (!is_array($attestation) || ($attestation['fmt'] ?? null) !== 'none'
            || !is_array($attestation['attStmt'] ?? null) || $attestation['attStmt'] !== []) {
            return null;
        }
        $authenticatorData = $attestation['authData'] ?? null;
        if (!is_string($authenticatorData)) {
            return null;
        }
        $flags = webAuthnReadAuthenticatorFlags($authenticatorData, $rpId);
        if (!$flags || ($flags['flags'] & 0x40) === 0 || strlen($authenticatorData) < 55) {
            return null;
        }
        if (!webAuthnVerifyClientData($clientDataJson, 'webauthn.create', $expectedChallenge, $origin)) {
            return null;
        }

        $credentialIdLength = unpack('nlength', substr($authenticatorData, 53, 2))['length'];
        $credentialStart = 55;
        $coseStart = $credentialStart + $credentialIdLength;
        if ($credentialIdLength < 1 || $coseStart >= strlen($authenticatorData)) {
            return null;
        }
        $credentialId = substr($authenticatorData, $credentialStart, $credentialIdLength);
        $coseOffset = $coseStart;
        $coseKey = webauthnCborRead($authenticatorData, $coseOffset);
        if (!is_array($coseKey)) {
            return null;
        }
        $publicKey = webauthnCoseEcPublicKeyPem($coseKey);
        if (!$publicKey) {
            return null;
        }
        return [
            'credential_id' => webauthnBase64UrlEncode($credentialId),
            'public_key' => $publicKey,
            'sign_count' => $flags['sign_count'],
        ];
    } catch (Throwable) {
        return null;
    }
}

function webAuthnCredentialResponse(array $payload, string $expectedType, string $challenge, string $origin, string $rpId): array
{
    $response = $payload['credential'] ?? null;
    if (!is_array($response) || ($response['type'] ?? null) !== 'public-key' || !is_array($response['response'] ?? null)) {
        webAuthnJson(400, ['error' => 'La respuesta biométrica está incompleta.']);
    }
    $rawClientData = webauthnBase64UrlDecode((string) ($response['response']['clientDataJSON'] ?? ''));
    if ($rawClientData === null || strlen($rawClientData) > 4096 || !webAuthnVerifyClientData($rawClientData, $expectedType, $challenge, $origin)) {
        webAuthnJson(403, ['error' => 'El desafío o el origen de autenticación no coincide.']);
    }
    $rawId = webauthnBase64UrlDecode((string) ($response['rawId'] ?? $response['id'] ?? ''));
    if ($rawId === null || strlen($rawId) > 1024 || webauthnBase64UrlEncode($rawId) !== (string) ($response['id'] ?? '')) {
        webAuthnJson(400, ['error' => 'El identificador de credencial no es válido.']);
    }
    return [$response, $rawClientData, $rawId];
}

$action = (string) ($_GET['action'] ?? '');
$payload = $_SERVER['REQUEST_METHOD'] === 'POST' ? webAuthnPayload() : [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($payload['action'] ?? $action);
}
$pdo = db();
[$expectedOrigin, $rpId] = webAuthnOriginAndRpId();

if ($action === 'bootstrap' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $user = currentUser();
    webAuthnJson(200, ['authenticated' => (bool) $user, 'csrf_token' => csrfToken()]);
}

if ($action === 'quick_start' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    webAuthnVerifyCsrf($payload);
    $requestToken = bin2hex(random_bytes(32));
    $challenge = webauthnBase64UrlEncode(random_bytes(32));
    $deviceInfo = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'Navegador'), 0, 500);
    createLoginRequest($requestToken, $challenge, $deviceInfo);
    recordAuditLog(null, 'LOGIN_REQUEST_CREATED', 'Solicitud de acceso rápido creada.', $_SERVER['REMOTE_ADDR'] ?? null);
    $_SESSION['webauthn_login_tokens'] ??= [];
    $_SESSION['webauthn_login_tokens'][$requestToken] = true;
    if (count($_SESSION['webauthn_login_tokens']) > 10) {
        $_SESSION['webauthn_login_tokens'] = array_slice($_SESSION['webauthn_login_tokens'], -10, null, true);
    }
    webAuthnJson(200, ['request_token' => $requestToken, 'expires_in' => 300]);
}

if ($action === 'quick_status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    webAuthnVerifyCsrf($payload);
    $requestToken = (string) ($payload['request_token'] ?? '');
    if (preg_match('/^[a-f0-9]{64}$/', $requestToken) !== 1 || empty($_SESSION['webauthn_login_tokens'][$requestToken])) {
        webAuthnJson(403, ['error' => 'La solicitud no pertenece a esta sesión de computadora.']);
    }
    $request = findLoginRequest($requestToken);
    if (!$request || $request['status'] === 'expired' || $request['status'] === 'rejected') {
        unset($_SESSION['webauthn_login_tokens'][$requestToken]);
        webAuthnJson(410, ['status' => $request['status'] ?? 'expired', 'error' => 'La solicitud de acceso venció o fue cancelada.']);
    }
    if ($request['status'] !== 'approved' || empty($request['user_id'])) {
        webAuthnJson(200, ['status' => 'pending']);
    }
    $userStmt = $pdo->prepare('SELECT id, full_name, is_active FROM users WHERE id = ? LIMIT 1');
    $userStmt->execute([(int) $request['user_id']]);
    $user = $userStmt->fetch();
    if (!$user || (int) $user['is_active'] !== 1) {
        $pdo->prepare("UPDATE login_requests SET status = 'rejected' WHERE id = ? AND status = 'approved'")->execute([(int) $request['id']]);
        unset($_SESSION['webauthn_login_tokens'][$requestToken]);
        webAuthnJson(403, ['error' => 'La cuenta vinculada no está activa.']);
    }
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    unset($_SESSION['webauthn_login_tokens'][$requestToken]);
    recordAuditLog((int) $user['id'], 'LOGIN_WEBAUTHN', 'Inicio de sesión aprobado desde un dispositivo móvil.', $_SERVER['REMOTE_ADDR'] ?? null);
    webAuthnJson(200, ['status' => 'approved', 'redirect' => 'dashboard.php']);
}

if ($action === 'cancel' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    webAuthnVerifyCsrf($payload);
    $requestToken = (string) ($payload['request_token'] ?? '');
    if (preg_match('/^[a-f0-9]{64}$/', $requestToken) !== 1 || empty($_SESSION['webauthn_login_tokens'][$requestToken])) {
        webAuthnJson(403, ['error' => 'La solicitud no pertenece a esta sesión de computadora.']);
    }
    $cancel = $pdo->prepare("UPDATE login_requests SET status = 'rejected' WHERE request_token = ? AND status = 'pending'");
    $cancel->execute([$requestToken]);
    unset($_SESSION['webauthn_login_tokens'][$requestToken]);
    recordAuditLog(null, 'LOGIN_REQUEST_CANCELLED', 'La computadora canceló una solicitud pendiente.', $_SERVER['REMOTE_ADDR'] ?? null);
    webAuthnJson(200, ['ok' => true]);
}

if ($action === 'pending' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $user = currentUser();
    if (!$user) {
        webAuthnJson(200, ['authenticated' => false, 'requests' => []]);
    }
    expireLoginRequests();
    $credentialsStmt = $pdo->prepare('SELECT credential_id FROM webauthn_credentials WHERE user_id = ? ORDER BY created_at DESC');
    $credentialsStmt->execute([(int) $user['id']]);
    $credentialIds = array_map(static fn (array $item): string => (string) $item['credential_id'], $credentialsStmt->fetchAll());
    $requests = [];
    if ($credentialIds) {
        $rows = $pdo->query("SELECT request_token, challenge, device_info, expires_at, created_at FROM login_requests
            WHERE status = 'pending' AND user_id IS NULL AND expires_at > datetime('now')
            ORDER BY created_at ASC LIMIT 10")->fetchAll();
        foreach ($rows as $row) {
            $expires = $pdo->prepare("SELECT MAX(0, CAST((julianday(?) - julianday('now')) * 86400 AS INTEGER))");
            $expires->execute([$row['expires_at']]);
            $requests[] = [
                'request_token' => $row['request_token'],
                'challenge' => $row['challenge'],
                'device_info' => $row['device_info'],
                'expires_in' => (int) $expires->fetchColumn(),
                'credential_ids' => $credentialIds,
            ];
        }
    }
    webAuthnJson(200, ['authenticated' => true, 'requests' => $requests]);
}

if ($action === 'register_options' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    webAuthnVerifyCsrf($payload);
    $user = currentUser();
    if (!$user) {
        webAuthnJson(401, ['error' => 'Inicia sesión para registrar un dispositivo.']);
    }
    $challenge = webauthnBase64UrlEncode(random_bytes(32));
    $_SESSION['webauthn_register'] = ['challenge' => $challenge, 'user_id' => (int) $user['id'], 'created_at' => time()];
    $existing = listWebAuthnCredentials((int) $user['id']);
    webAuthnJson(200, ['options' => [
        'challenge' => $challenge,
        'rp' => ['name' => 'Biblioteca Virtual · IESTP Pedro P. Díaz', 'id' => $rpId],
        'user' => [
            'id' => webauthnBase64UrlEncode((string) $user['id']),
            'name' => (string) $user['email'],
            'displayName' => (string) $user['full_name'],
        ],
        'pubKeyCredParams' => [['type' => 'public-key', 'alg' => -7]],
        'timeout' => 60000,
        'attestation' => 'none',
        'authenticatorSelection' => ['userVerification' => 'required', 'residentKey' => 'preferred'],
        'excludeCredentials' => array_map(static fn (array $item): array => ['type' => 'public-key', 'id' => $item['credential_id']], $existing),
    ]]);
}

if ($action === 'register_verify' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    webAuthnVerifyCsrf($payload);
    $user = currentUser();
    $registration = $_SESSION['webauthn_register'] ?? null;
    unset($_SESSION['webauthn_register']);
    if (!$user || !is_array($registration) || (int) ($registration['user_id'] ?? 0) !== (int) $user['id']
        || time() - (int) ($registration['created_at'] ?? 0) > 180) {
        webAuthnJson(403, ['error' => 'El registro venció. Inicia el registro nuevamente.']);
    }
    $credential = $payload['credential'] ?? null;
    if (!is_array($credential) || ($credential['type'] ?? null) !== 'public-key' || !is_array($credential['response'] ?? null)) {
        webAuthnJson(400, ['error' => 'La respuesta del autenticador está incompleta.']);
    }
    $clientData = webauthnBase64UrlDecode((string) ($credential['response']['clientDataJSON'] ?? ''));
    $attestationObject = webauthnBase64UrlDecode((string) ($credential['response']['attestationObject'] ?? ''));
    if ($clientData === null || strlen($clientData) > 4096 || $attestationObject === null || strlen($attestationObject) > 16384
        || !webAuthnVerifyClientData($clientData, 'webauthn.create', (string) $registration['challenge'], $expectedOrigin)) {
        webAuthnJson(403, ['error' => 'No se pudo validar el desafío del registro.']);
    }
    $parsed = webAuthnParseRegistration($attestationObject, $clientData, (string) $registration['challenge'], $expectedOrigin, $rpId);
    $rawId = webauthnBase64UrlDecode((string) ($credential['rawId'] ?? $credential['id'] ?? ''));
    if (!$parsed || !$rawId || !hash_equals($parsed['credential_id'], webauthnBase64UrlEncode($rawId))) {
        webAuthnJson(400, ['error' => 'No se pudo verificar la credencial. Usa un dispositivo compatible con FIDO2/WebAuthn.']);
    }
    $deviceName = trim((string) ($payload['device_name'] ?? ''));
    $deviceName = $deviceName === '' ? 'Dispositivo sin nombre' : substr($deviceName, 0, 80);
    try {
        $credentialId = createWebAuthnCredential((int) $user['id'], $parsed['credential_id'], $parsed['public_key'], $deviceName);
        $pdo->prepare('UPDATE webauthn_credentials SET sign_count = ? WHERE id = ?')->execute([$parsed['sign_count'], $credentialId]);
    } catch (PDOException) {
        webAuthnJson(409, ['error' => 'Este dispositivo ya está vinculado a una cuenta.']);
    }
    logAudit('WEBAUTHN_CREDENTIAL_REGISTERED', 'Dispositivo registrado: ' . $deviceName);
    webAuthnJson(201, ['ok' => true, 'message' => 'Dispositivo registrado correctamente.']);
}

if ($action === 'devices' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $user = currentUser();
    if (!$user) {
        webAuthnJson(401, ['error' => 'Inicia sesión para consultar tus dispositivos.']);
    }
    webAuthnJson(200, ['devices' => listWebAuthnCredentials((int) $user['id'])]);
}

if ($action === 'revoke' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    webAuthnVerifyCsrf($payload);
    $user = currentUser();
    if (!$user) {
        webAuthnJson(401, ['error' => 'Inicia sesión para revocar dispositivos.']);
    }
    $credentialId = (string) ($payload['credential_id'] ?? '');
    if (!deleteWebAuthnCredential((int) $user['id'], $credentialId)) {
        webAuthnJson(404, ['error' => 'No se encontró ese dispositivo vinculado a tu cuenta.']);
    }
    logAudit('WEBAUTHN_CREDENTIAL_REVOKED', 'Se revocó el dispositivo ' . substr($credentialId, 0, 12));
    webAuthnJson(200, ['ok' => true]);
}

if ($action === 'approve' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    webAuthnVerifyCsrf($payload);
    $user = currentUser();
    if (!$user) {
        webAuthnJson(401, ['error' => 'Inicia sesión en el móvil antes de autorizar la computadora.']);
    }
    $requestToken = (string) ($payload['request_token'] ?? '');
    if (preg_match('/^[a-f0-9]{64}$/', $requestToken) !== 1) {
        webAuthnJson(400, ['error' => 'La solicitud no es válida.']);
    }
    $request = findLoginRequest($requestToken);
    if (!$request || $request['status'] !== 'pending' || !empty($request['user_id'])) {
        webAuthnJson(410, ['error' => 'La solicitud venció o ya fue atendida.']);
    }
    [$response, $clientData, $rawCredentialId] = webAuthnCredentialResponse($payload, 'webauthn.get', (string) $request['challenge'], $expectedOrigin, $rpId);
    $credentialId = webauthnBase64UrlEncode($rawCredentialId);
    $credential = findWebAuthnCredential($credentialId);
    if (!$credential || (int) $credential['user_id'] !== (int) $user['id']) {
        webAuthnJson(403, ['error' => 'El dispositivo no pertenece a la cuenta móvil autenticada.']);
    }
    $userHandle = webauthnBase64UrlDecode((string) ($response['response']['userHandle'] ?? ''));
    if ($userHandle !== null && !hash_equals((string) $user['id'], $userHandle)) {
        webAuthnJson(403, ['error' => 'La cuenta del autenticador no coincide.']);
    }
    $authenticatorData = webauthnBase64UrlDecode((string) ($response['response']['authenticatorData'] ?? ''));
    $signature = webauthnBase64UrlDecode((string) ($response['response']['signature'] ?? ''));
    $flags = $authenticatorData === null || strlen($authenticatorData) > 4096 ? null : webAuthnReadAuthenticatorFlags($authenticatorData, $rpId);
    if (!$flags || $signature === null
        || openssl_verify($authenticatorData . hash('sha256', $clientData, true), $signature, (string) $credential['public_key'], OPENSSL_ALGO_SHA256) !== 1) {
        webAuthnJson(403, ['error' => 'La firma biométrica no es válida.']);
    }
    if (!markWebAuthnCredentialUsed($credentialId, (int) $flags['sign_count'])) {
        webAuthnJson(403, ['error' => 'La credencial ya fue usada o el contador de seguridad no es válido.']);
    }
    $approved = $pdo->prepare("UPDATE login_requests SET status = 'approved', user_id = ?
        WHERE id = ? AND status = 'pending' AND user_id IS NULL AND expires_at > datetime('now')");
    $approved->execute([(int) $user['id'], (int) $request['id']]);
    if ($approved->rowCount() !== 1) {
        webAuthnJson(410, ['error' => 'La solicitud venció antes de completar la autorización.']);
    }
    recordAuditLog((int) $user['id'], 'WEBAUTHN_ACCESS_APPROVED', 'Acceso rápido autorizado para: ' . substr((string) $request['device_info'], 0, 160), $_SERVER['REMOTE_ADDR'] ?? null);
    webAuthnJson(200, ['ok' => true]);
}

webAuthnJson(404, ['error' => 'Acción WebAuthn no válida.']);
