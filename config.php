<?php
declare(strict_types=1);

const DB_HOST = '127.0.0.1';
const DB_NAME = 'pwapractica5';
const DB_USER = 'root';
const DB_PASS = '';

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    }
    return $pdo;
}

function e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function require_login(): void
{
    if (!user()) {
        header('Location: index.php');
        exit;
    }
}

function require_role(string ...$roles): void
{
    require_login();
    if (!in_array(user()['rol_nombre'], $roles, true)) {
        http_response_code(403);
        exit('No tienes permiso para realizar esta acción.');
    }
}

function flash(string $message, string $type = 'success'): void
{
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

function redirect(string $url = 'index.php'): never
{
    header('Location: ' . $url);
    exit;
}

function csrf_field(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf']) . '">';
}

function verify_csrf(): void
{
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(419);
        exit('La sesión del formulario expiró. Recarga la página e inténtalo otra vez.');
    }
}

function can_access_project(int $projectId): bool
{
    if (user()['rol_nombre'] === 'Administrador') {
        return true;
    }
    $query = db()->prepare('SELECT 1 FROM proyecto_usuarios WHERE proyecto_id = ? AND usuario_id = ?');
    $query->execute([$projectId, user()['id']]);
    return (bool)$query->fetchColumn();
}
