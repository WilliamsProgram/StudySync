<?php
/*
 * Module 1: Authentication and User Onboarding Backend
 * Project: StudySync
 *
 * Purpose
 * - Register users
 * - Log users in
 * - Log users out
 * - Return the current authenticated user
 * 
 * Notes
 * - First backend module so it can be tested on its own
 * - Uses PHP sessions for authentication state
 * - Uses PDO and prepared statements
 * - Variables/Datatypes subject to change per DB
 */

declare(strict_types=1);

/* Config */ 
const DB_HOST = 'localhost';
const DB_NAME = 'studysync_db';
const DB_USER = 'root';
const DB_PASS = '';

/*  Mainline / Router */ 
startSession();
setJsonHeader();

try {
    $action = $_GET['action'] ?? '';

    switch ($action) {
        case 'register':
            enforceMethod('POST');
            handleRegister();
            break;

        case 'login':
            enforceMethod('POST');
            handleLogin();
            break;

        case 'logout':
            enforceMethod('POST');
            handleLogout();
            break;

        case 'me':
            enforceMethod('GET');
            handleCurrentUser();
            break;

        default:
            respond(404, [
                'success' => false,
                'message' => 'Unknown action.'
            ]);
    }
} catch (Throwable $e) {
    error_log('Module 1 fatal error: ' . $e->getMessage());
    respond(500, [
        'success' => false,
        'message' => 'Server error.'
    ]);
}

/* Controller Functions */ 
function handleRegister(): void
{
    $input = readJsonInput();
    $data = validateRegistrationInput($input);

    $pdo = connectDatabase();

    if (emailExists($pdo, $data['email'])) {
        respond(409, [
            'success' => false,
            'message' => 'Email is already registered.'
        ]);
    }

    $userId = createUser($pdo, $data);
    $_SESSION['user_id'] = $userId;
    $_SESSION['role'] = $data['role'];

    $user = findUserById($pdo, $userId);

    respond(201, [
        'success' => true,
        'message' => 'User registered successfully.',
        'data' => [
            'user' => sanitizeUser($user)
        ]
    ]);
}

function handleLogin(): void
{
    $input = readJsonInput();
    $data = validateLoginInput($input);
    $pdo = connectDatabase();

    $user = findUserByEmail($pdo, $data['email']);

    if (!$user || !password_verify($data['password'], $user['password_hash'])) {
        respond(401, [
            'success' => false,
            'message' => 'Invalid email or password.'
        ]);
    }

    $_SESSION['user_id'] = (int) $user['user_id'];
    $_SESSION['role'] = $user['role'];

    respond(200, [
        'success' => true,
        'message' => 'Login successful.',
        'data' => [
            'user' => sanitizeUser($user)
        ]
    ]);
}

function handleLogout(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();

    respond(200, [
        'success' => true,
        'message' => 'Logout successful.'
    ]);
}

function handleCurrentUser(): void
{
    if (empty($_SESSION['user_id'])) {
        respond(401, [
            'success' => false,
            'message' => 'Not authenticated.'
        ]);
    }

    $pdo = connectDatabase();
    $user = findUserById($pdo, (int) $_SESSION['user_id']);

    if (!$user) {
        respond(404, [
            'success' => false,
            'message' => 'User record not found.'
        ]);
    }

    respond(200, [
        'success' => true,
        'data' => [
            'user' => sanitizeUser($user)
        ]
    ]);
}

/* Validation Functions */ 
function validateRegistrationInput(array $input): array
{
    $name = trim((string) ($input['full_name'] ?? ''));
    $email = strtolower(trim((string) ($input['email'] ?? '')));
    $password = (string) ($input['password'] ?? '');
    $role = strtolower(trim((string) ($input['role'] ?? 'worker')));

    if ($name === '' || mb_strlen($name) < 3) {
        respond(422, [
            'success' => false,
            'message' => 'Full name must be at least 3 characters.'
        ]);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        respond(422, [
            'success' => false,
            'message' => 'A valid email address is required.'
        ]);
    }

    if (strlen($password) < 8) {
        respond(422, [
            'success' => false,
            'message' => 'Password must be at least 8 characters.'
        ]);
    }

    if (!in_array($role, ['client', 'worker', 'admin'], true)) {
        respond(422, [
            'success' => false,
            'message' => 'Role must be client, worker, or admin.'
        ]);
    }

    return [
        'full_name' => $name,
        'email' => $email,
        'password' => $password,
        'role' => $role
    ];
}

function validateLoginInput(array $input): array
{
    $email = strtolower(trim((string) ($input['email'] ?? '')));
    $password = (string) ($input['password'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        respond(422, [
            'success' => false,
            'message' => 'A valid email address is required.'
        ]);
    }

    if ($password === '') {
        respond(422, [
            'success' => false,
            'message' => 'Password is required.'
        ]);
    }

    return [
        'email' => $email,
        'password' => $password
    ];
}

/*  Data Access Functions */ 
function connectDatabase(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';

    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}

function emailExists(PDO $pdo, string $email): bool
{
    $sql = 'SELECT COUNT(*) FROM users WHERE email = :email';
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['email' => $email]);

    return (int) $stmt->fetchColumn() > 0;
}

function createUser(PDO $pdo, array $data): int
{
    $passwordHash = password_hash($data['password'], PASSWORD_DEFAULT);

    $sql = 'INSERT INTO users (full_name, email, password_hash, role, created_at)
            VALUES (:full_name, :email, :password_hash, :role, NOW())';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'full_name' => $data['full_name'],
        'email' => $data['email'],
        'password_hash' => $passwordHash,
        'role' => $data['role']
    ]);

    return (int) $pdo->lastInsertId();
}

function findUserByEmail(PDO $pdo, string $email): ?array
{
    $sql = 'SELECT user_id, full_name, email, password_hash, role, created_at
            FROM users
            WHERE email = :email
            LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();

    return $user ?: null;
}

function findUserById(PDO $pdo, int $userId): ?array
{
    $sql = 'SELECT user_id, full_name, email, password_hash, role, created_at
            FROM users
            WHERE user_id = :user_id
            LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['user_id' => $userId]);
    $user = $stmt->fetch();

    return $user ?: null;
}

function sanitizeUser(array $user): array
{
    unset($user['password_hash']);
    return $user;
}

/* Utility Functions */
function startSession(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start([
            'cookie_httponly' => true,
            'cookie_samesite' => 'Lax',
            'use_strict_mode' => true,
        ]);
    }
}

function setJsonHeader(): void
{
    header('Content-Type: application/json; charset=utf-8');
}

function enforceMethod(string $expected): void
{
    if ($_SERVER['REQUEST_METHOD'] !== $expected) {
        respond(405, [
            'success' => false,
            'message' => 'Method not allowed.'
        ]);
    }
}

function readJsonInput(): array
{
    $raw = file_get_contents('php://input');

    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);

    if (!is_array($decoded)) {
        respond(400, [
            'success' => false,
            'message' => 'Invalid JSON payload.'
        ]);
    }

    return $decoded;
}

function respond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}