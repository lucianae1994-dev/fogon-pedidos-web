<?php
/**
 * API lado CLIENTE: la usa el portal web publico (veterinarias, granjas).
 * Autenticacion simple por codigo de acceso (no hay contrasenas ni cuentas).
 */
require __DIR__ . '/config.php';
cors();

$accion = $_GET['accion'] ?? '';
$mysqli = db();

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
                    stock_status
             FROM productos WHERE activo=1 ORDER BY rubro, nombre"
        );

        $productos = [];
        while ($p = $res->fetch_assoc()) {
            $precios = precio_para_nivel($p, $cliente['nivel_precio']);
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
                'precio' => $precios['precio'],
                'precio_regular' => $precios['precio_regular'],
                'precio_descuento' => $precios['precio_descuento'],
            ];
        }
        json_out(['productos' => $productos, 'nivel_precio' => $cliente['nivel_precio']]);
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
                "INSERT INTO pedido_items (pedido_id, producto_id, sku, nombre, cantidad, precio_unitario, subtotal)
                 VALUES (?,?,?,?,?,?,?)"
            );
            foreach ($lineas as $l) {
                $stmtI->bind_param(
                    'iissidd',
                    $pedidoId, $l['producto_id'], $l['sku'], $l['nombre'],
                    $l['cantidad'], $l['precio_unitario'], $l['subtotal']
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

        json_out([
            'ok' => true,
            'pedido' => [
                'id' => (int)$pedidoId,
                'creado_en' => $creado,
                'total' => round($total, 2),
                'items' => $lineas,
                'cliente' => $cliente['nombre'],
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
                "SELECT pedido_id, sku, nombre, cantidad, precio_unitario, subtotal
                 FROM pedido_items WHERE pedido_id IN ($placeholders) ORDER BY id"
            );
            $stmtI->bind_param(str_repeat('i', count($ids)), ...$ids);
            $stmtI->execute();
            $itemsPorPedido = [];
            foreach ($stmtI->get_result()->fetch_all(MYSQLI_ASSOC) as $it) {
                $itemsPorPedido[(int)$it['pedido_id']][] = $it;
            }
            foreach ($pedidos as &$p) {
                $p['id'] = (int)$p['id'];
                $p['total'] = (float)$p['total'];
                $p['items'] = $itemsPorPedido[$p['id']] ?? [];
            }
            unset($p);
        }

        json_out(['pedidos' => $pedidos]);
        break;

    default:
        json_error('Accion desconocida.', 404);
}
