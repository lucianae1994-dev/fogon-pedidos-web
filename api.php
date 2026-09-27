<?php
/**
 * API lado CLIENTE: la usa el portal web publico (veterinarias, granjas).
 * Autenticacion simple por codigo de acceso (no hay contrasenas ni cuentas).
 */
require __DIR__ . '/config.php';
cors();

$accion = $_GET['accion'] ?? '';
$mysqli = db();

/** true si a este nivel de cliente se le puede mostrar el precio en el portal.
 * Definida aca (no en config.php) porque config.php no se despliega por Git
 * (tiene contrasenas) y este archivo si. */
function ver_precio(string $nivel): bool {
    return $nivel !== 'sin_precio';
}

/** Bucket de stock para el semaforo del portal, a partir de actual/minimo y
 * el stock_status que ya se usaba. 'agotado' siempre gana (se muestra en rojo
 * con el texto "Consultar stock"); si no hay datos de minimo/actual cargados,
 * se asume 'alto' (disponible) para no romper productos viejos sin esos datos. */
function stock_nivel(?int $actual, ?int $minimo): string {
    if ($actual !== null && $actual <= 0) return 'agotado';
    if ($actual !== null && $minimo !== null && $actual <= $minimo) return 'bajo';
    return 'alto';
}

/** true si el producto esta sin stock ahora mismo (mismo criterio que se usa
 * para el punto rojo "Consultar stock" del catalogo). Se usa al crear un
 * pedido para marcar el renglon como "a confirmar" en vez de bloquear el
 * pedido. */
function producto_sin_stock(array $producto): bool {
    if (($producto['stock_status'] ?? '') === 'outofstock') return true;
    $actual = $producto['stock_actual'] ?? null;
    return $actual !== null && (int)$actual <= 0;
}

function cliente_por_codigo(mysqli $mysqli, string $codigo): ?array {
    $codigo = trim($codigo);
    if ($codigo === '') return null;
    $stmt = $mysqli->prepare("SELECT * FROM clientes WHERE codigo_acceso=? AND activo=1");
    $stmt->bind_param('s', $codigo);
    $stmt->execute();
    $c = $stmt->get_result()->fetch_assoc();
    return $c ?: null;
}

switch ($accion) {

    // ------------------------------------------------------------------
    // Body: { "codigo": "..." }
    // ------------------------------------------------------------------
    case 'login':
        $b = body_json();
        $cliente = cliente_por_codigo($mysqli, (string)($b['codigo'] ?? ''));
        if (!$cliente) json_error('Codigo de acceso invalido.', 401);
        json_out([
            'ok' => true,
            'cliente' => [
                'id' => (int)$cliente['id'],
                'nombre' => $cliente['nombre'],
                'nivel_precio' => $cliente['nivel_precio'],
                'ver_precio' => ver_precio($cliente['nivel_precio']),
            ],
        ]);
        break;

    // ------------------------------------------------------------------
    // Body: { "codigo": "..." }  -> catalogo completo con precio segun el
    // nivel del cliente. El front lo filtra por rubro en el navegador.
    // ------------------------------------------------------------------
    case 'catalogo':
        $b = body_json();
        $cliente = cliente_por_codigo($mysqli, (string)($b['codigo'] ?? ''));
        if (!$cliente) json_error('Codigo de acceso invalido.', 401);

        $res = $mysqli->query(
            "SELECT id, sku, nombre, descripcion, rubro, subrubro, laboratorio, imagen_url,
                    precio_mostrador, precio_mostrador_descuento,
                    precio_comercio, precio_comercio_descuento,
                    precio_ganaderos, precio_ganaderos_descuento,
                    stock_status, stock_actual, stock_minimo, stock_maximo
             FROM productos WHERE activo=1 ORDER BY rubro, nombre"
        );

        $verPrecio = ver_precio($cliente['nivel_precio']);
        $productos = [];
        while ($p = $res->fetch_assoc()) {
            $precios = precio_para_nivel($p, $cliente['nivel_precio']);
            $actual = $p['stock_actual'] !== null ? (int)$p['stock_actual'] : null;
            $minimo = $p['stock_minimo'] !== null ? (int)$p['stock_minimo'] : null;
            $maximo = $p['stock_maximo'] !== null ? (int)$p['stock_maximo'] : null;
            $nivelStock = $p['stock_status'] === 'outofstock' ? 'agotado' : stock_nivel($actual, $minimo);
            $productos[] = [
                'id' => (int)$p['id'],
                'sku' => $p['sku'],
                'nombre' => $p['nombre'],
                'descripcion' => $p['descripcion'],
                'rubro' => $p['rubro'],
                'subrubro' => $p['subrubro'],
                'laboratorio' => $p['laboratorio'],
                'imagen_url' => $p['imagen_url'],
                'stock_status' => $p['stock_status'],
                'stock_actual' => $actual,
                'stock_minimo' => $minimo,
                'stock_maximo' => $maximo,
                'stock_nivel' => $nivelStock,
                'precio' => $verPrecio ? $precios['precio'] : null,
                'precio_regular' => $verPrecio ? $precios['precio_regular'] : null,
                'precio_descuento' => $verPrecio ? $precios['precio_descuento'] : null,
            ];
        }
        json_out(['productos' => $productos, 'nivel_precio' => $cliente['nivel_precio'], 'ver_precio' => $verPrecio]);
        break;

    // ------------------------------------------------------------------
    // Body: { "codigo": "...", "items": [{"sku": "...", "cantidad": 2}], "notas": "..." }
    // Los precios se recalculan en el servidor (nunca se confia en el precio
    // que mande el navegador).
    // ------------------------------------------------------------------
    case 'pedido_crear':
        $b = body_json();
        $cliente = cliente_por_codigo($mysqli, (string)($b['codigo'] ?? ''));
        if (!$cliente) json_error('Codigo de acceso invalido.', 401);

        $items = $b['items'] ?? [];
        if (!is_array($items) || count($items) === 0) json_error('El pedido esta vacio.');

        $skus = array_values(array_unique(array_map(fn($i) => trim((string)($i['sku'] ?? '')), $items)));
        $skus = array_filter($skus, fn($s) => $s !== '');
        if (count($skus) === 0) json_error('El pedido esta vacio.');

        $placeholders = implode(',', array_fill(0, count($skus), '?'));
        $stmt = $mysqli->prepare("SELECT * FROM productos WHERE sku IN ($placeholders) AND activo=1");
        $stmt->bind_param(str_repeat('s', count($skus)), ...array_values($skus));
        $stmt->execute();
        $filas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $porSku = [];
        foreach ($filas as $f) $porSku[$f['sku']] = $f;

        $lineas = [];
        $total = 0.0;
        foreach ($items as $it) {
            $sku = trim((string)($it['sku'] ?? ''));
            $cantidad = max(1, (int)($it['cantidad'] ?? 1));
            if ($sku === '' || !isset($porSku[$sku])) continue;
            $prod = $porSku[$sku];
            $precios = precio_para_nivel($prod, $cliente['nivel_precio']);
            $subtotal = round($precios['precio'] * $cantidad, 2);
            $total += $subtotal;
            $lineas[] = [
                'producto_id' => (int)$prod['id'],
                'sku' => $sku,
                'nombre' => $prod['nombre'],
                'cantidad' => $cantidad,
                'precio_unitario' => $precios['precio'],
                'subtotal' => $subtotal,
                'sin_stock_confirmar' => producto_sin_stock($prod) ? 1 : 0,
            ];
        }
        if (count($lineas) === 0) json_error('Ninguno de los productos del pedido esta disponible.');

        $notas = trim((string)($b['notas'] ?? ''));

        $mysqli->begin_transaction();
        try {
            $stmtP = $mysqli->prepare(
                "INSERT INTO pedidos (cliente_id, estado, notas, total) VALUES (?, 'nuevo', ?, ?)"
            );
            $clienteId = (int)$cliente['id'];
            $stmtP->bind_param('isd', $clienteId, $notas, $total);
            $stmtP->execute();
            $pedidoId = $mysqli->insert_id;

            $stmtI = $mysqli->prepare(
                "INSERT INTO pedido_items (pedido_id, producto_id, sku, nombre, cantidad, precio_unitario, subtotal, sin_stock_confirmar)
                 VALUES (?,?,?,?,?,?,?,?)"
            );
            foreach ($lineas as $l) {
                $stmtI->bind_param(
                    'iissiddi',
                    $pedidoId, $l['producto_id'], $l['sku'], $l['nombre'],
                    $l['cantidad'], $l['precio_unitario'], $l['subtotal'], $l['sin_stock_confirmar']
                );
                $stmtI->execute();
            }
            $mysqli->commit();
        } catch (Throwable $e) {
            $mysqli->rollback();
            json_error('No se pudo registrar el pedido: ' . $e->getMessage(), 500);
        }

        $stmtF = $mysqli->prepare("SELECT creado_en FROM pedidos WHERE id=?");
        $stmtF->bind_param('i', $pedidoId);
        $stmtF->execute();
        $creado = $stmtF->get_result()->fetch_assoc()['creado_en'] ?? null;

        $verPrecio = ver_precio($cliente['nivel_precio']);
        $itemsSalida = array_map(function ($l) use ($verPrecio) {
            if (!$verPrecio) {
                unset($l['precio_unitario'], $l['subtotal']);
            }
            return $l;
        }, $lineas);

        json_out([
            'ok' => true,
            'pedido' => [
                'id' => (int)$pedidoId,
                'creado_en' => $creado,
                'total' => $verPrecio ? round($total, 2) : null,
                'items' => $itemsSalida,
                'cliente' => $cliente['nombre'],
                'ver_precio' => $verPrecio,
            ],
        ]);
        break;

    // ------------------------------------------------------------------
    // Body: { "codigo": "..." } -> historial de pedidos de ese cliente, con
    // el detalle de items de cada uno (para que pueda hacer seguimiento).
    // ------------------------------------------------------------------
    case 'mis_pedidos':
        $b = body_json();
        $cliente = cliente_por_codigo($mysqli, (string)($b['codigo'] ?? ''));
        if (!$cliente) json_error('Codigo de acceso invalido.', 401);

        $clienteId = (int)$cliente['id'];
        $stmt = $mysqli->prepare(
            "SELECT id, estado, total, notas, creado_en FROM pedidos WHERE cliente_id=? ORDER BY creado_en DESC LIMIT 50"
        );
        $stmt->bind_param('i', $clienteId);
        $stmt->execute();
        $pedidos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        if ($pedidos) {
            $ids = array_map(fn($p) => (int)$p['id'], $pedidos);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmtI = $mysqli->prepare(
                "SELECT pedido_id, sku, nombre, cantidad, precio_unitario, subtotal, sin_stock_confirmar
                 FROM pedido_items WHERE pedido_id IN ($placeholders) ORDER BY id"
            );
            $stmtI->bind_param(str_repeat('i', count($ids)), ...$ids);
            $stmtI->execute();
            $itemsPorPedido = [];
            foreach ($stmtI->get_result()->fetch_all(MYSQLI_ASSOC) as $it) {
                $itemsPorPedido[(int)$it['pedido_id']][] = $it;
            }
            $verPrecio = ver_precio($cliente['nivel_precio']);
            foreach ($pedidos as &$p) {
                $p['id'] = (int)$p['id'];
                $items = $itemsPorPedido[$p['id']] ?? [];
                if ($verPrecio) {
                    $p['total'] = (float)$p['total'];
                } else {
                    unset($p['total']);
                    $items = array_map(function ($it) {
                        unset($it['precio_unitario'], $it['subtotal']);
                        return $it;
                    }, $items);
                }
                $p['items'] = $items;
            }
            unset($p);
        }

        json_out(['pedidos' => $pedidos, 'ver_precio' => ver_precio($cliente['nivel_precio'])]);
        break;

    default:
        json_error('Accion desconocida.', 404);
}
