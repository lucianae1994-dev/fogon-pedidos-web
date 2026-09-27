<?php
/**
 * Configuracion compartida del sistema de pedidos de El Fogon.
 * Conexion a MySQL + helpers de respuesta JSON + autenticacion.
 *
 * ESTE ES UN EJEMPLO. Copia este archivo como "config.php" (sin ".example")
 * en el servidor y completa los datos reales. "config.php" NO se sube al
 * repositorio (esta en .gitignore) para no publicar contrasenas ni claves.
 */

$DB_HOST = 'localhost';
$DB_NAME = 'TU_BASE_DE_DATOS';
$DB_USER = 'TU_USUARIO_DE_BASE_DE_DATOS';
$DB_PASS = 'TU_CONTRASENA_DE_BASE_DE_DATOS';

// Clave que usa el programa de escritorio para hablar con admin.php.
// Debe coincidir con la que se configure en el programa (config.json).
define('ADMIN_KEY', 'GENERA_UNA_CLAVE_LARGA_Y_SECRETA_ACA');

function db(): mysqli {
    static $mysqli = null;
    if ($mysqli === null) {
        global $DB_HOST, $DB_NAME, $DB_USER, $DB_PASS;
        $mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
        if ($mysqli->connect_errno) {
            json_error('No se pudo conectar a la base de datos.', 500);
        }
        $mysqli->set_charset('utf8mb4');
    }
    return $mysqli;
}

function json_out($data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_error(string $mensaje, int $status = 400): void {
    json_out(['error' => $mensaje], $status);
}

function body_json(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/** Corta la ejecucion si el header X-Admin-Key no coincide. Usar en admin.php. */
function require_admin(): void {
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $key = $headers['X-Admin-Key'] ?? $headers['x-admin-key'] ?? ($_SERVER['HTTP_X_ADMIN_KEY'] ?? '');
    if (!hash_equals(ADMIN_KEY, (string)$key)) {
        json_error('No autorizado.', 401);
    }
}

/** Headers CORS basicos: el portal y el programa llaman desde distintos origenes. */
function cors(): void {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Content-Type, X-Admin-Key, X-Codigo-Acceso');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

/** Precio efectivo (con descuento si corresponde) segun el nivel del cliente. */
function precio_para_nivel(array $producto, string $nivel): array {
    $map = [
        'mostrador' => ['precio_mostrador', 'precio_mostrador_descuento'],
        'comercio'  => ['precio_comercio', 'precio_comercio_descuento'],
        'ganaderos' => ['precio_ganaderos', 'precio_ganaderos_descuento'],
    ];
    [$campoBase, $campoDesc] = $map[$nivel] ?? $map['mostrador'];
    $base = (float)($producto[$campoBase] ?? 0);
    $desc = $producto[$campoDesc] ?? null;
    $desc = ($desc !== null && (float)$desc > 0 && (float)$desc < $base) ? (float)$desc : null;
    return [
        'precio' => $desc ?? $base,
        'precio_regular' => $base,
        'precio_descuento' => $desc,
    ];
}
