<?php
/**
 * API lado ADMIN: la usa el programa de escritorio (Gestor de Productos).
 * Requiere el header X-Admin-Key en cada pedido.
 */
require __DIR__ . '/config.php';
cors();
require_admin();

$accion = $_GET['accion'] ?? '';
$mysqli = db();

switch ($accion) {

    // ------------------------------------------------------------------
    // Sincroniza TODA la lista de productos que administra el programa.
    // Body: { "productos": [ {sku, nombre, ...}, ... ] }
    // Hace upsert por sku y desactiva (activo=0) los que ya no vienen.
    // ------------------------------------------------------------------
    case 'productos_sync':
        $body = body_json();
        $productos = $body['productos'] ?? [];
        if (!is_array($productos)) json_error('Formato invalido: se esperaba "productos".');

        $mysqli->begin_transaction();
        try {
            $stmt = $mysqli->prepare(
                "INSERT INTO productos
                    (sku, nombre, descripcion, rubro, subrubro, laboratorio, imagen_url,
                     precio_mostrador, precio_mostrador_descuento,
                     precio_comercio, precio_comercio_descuento,
                     precio_ganaderos, precio_ganaderos_descuento,
                     stock_status, activo)
                 VALUES (?,?,?,?,?,?,?, ?,?, ?,?, ?,?, ?,1)
                 ON DUPLICATE KEY UPDATE
                    nombre=VALUES(nombre), descripcion=VALUES(descripcion),
                    rubro=VALUES(rubro), subrubro=VALUES(subrubro), laboratorio=VALUES(laboratorio),
                    imagen_url=VALUES(imagen_url),
                    precio_mostrador=VALUES(precio_mostrador), precio_mostrador_descuento=VALUES(precio_mostrador_descuento),
                    precio_comercio=VALUES(precio_comercio), precio_comercio_descuento=VALUES(precio_comercio_descuento),
                    precio_ganaderos=VALUES(precio_ganaderos), precio_ganaderos_descuento=VALUES(precio_ganaderos_descuento),
                    stock_status=VALUES(stock_status), activo=1"
            );

            $sku = $nombre = $descripcion = $rubro = $subrubro = $laboratorio = $imagenUrl = $stock = '';
            $pm = $pmd = $pc = $pcd = $pg = $pgd = 0.0;
            // 14 placeholders: sku,nombre,descripcion,rubro,subrubro,laboratorio,imagen_url (7x s),
            // precio_mostrador..precio_ganaderos_descuento (6x d), stock_status (1x s)
            $stmt->bind_param(
                'sssssssdddddds',
                $sku, $nombre, $descripcion, $rubro, $subrubro, $laboratorio, $imagenUrl,
                $pm, $pmd, $pc, $pcd, $pg, $pgd, $stock
            );

            $skusVistos = [];
            foreach ($productos as $p) {
                $sku = trim((string)($p['sku'] ?? ''));
                if ($sku === '') continue;
                $skusVistos[] = $sku;

                $nombre = (string)($p['nombre'] ?? '');
                $descripcion = (string)($p['descripcion'] ?? '');
                $rubro = (string)($p['rubro'] ?? '');
                $subrubro = (string)($p['subrubro'] ?? '');
                $laboratorio = (string)($p['laboratorio'] ?? '');
                $imagenUrl = (string)($p['imagen_url'] ?? '');
                $pm = (float)($p['precio_mostrador'] ?? 0);
                $pmd = (isset($p['precio_mostrador_descuento']) && $p['precio_mostrador_descuento'] !== null && $p['precio_mostrador_descuento'] !== '')
                    ? (float)$p['precio_mostrador_descuento'] : null;
                $pc = (float)($p['precio_comercio'] ?? 0);
                $pcd = (isset($p['precio_comercio_descuento']) && $p['precio_comercio_descuento'] !== null && $p['precio_comercio_descuento'] !== '')
                    ? (float)$p['precio_comercio_descuento'] : null;
                $pg = (float)($p['precio_ganaderos'] ?? 0);
                $pgd = (isset($p['precio_ganaderos_descuento']) && $p['precio_ganaderos_descuento'] !== null && $p['precio_ganaderos_descuento'] !== '')
                    ? (float)$p['precio_ganaderos_descuento'] : null;
                $stock = (string)($p['stock_status'] ?? 'instock');

                $stmt->execute();
            }

            // Cualquier producto que ya estaba en la base y no vino en este sync
            // se marca inactivo (no se borra, para no romper pedidos anteriores).
            if (count($skusVistos) > 0) {
                $placeholders = implode(',', array_fill(0, count($skusVistos), '?'));
                $tipos = str_repeat('s', count($skusVistos));
                $stmtOff = $mysqli->prepare("UPDATE productos SET activo=0 WHERE sku NOT IN ($placeholders)");
                $stmtOff->bind_param($tipos, ...$skusVistos);
                $stmtOff->execute();
            } else {
                $mysqli->query("UPDATE productos SET activo=0");
            }

            $mysqli->commit();
        } catch (Throwable $e) {
            $mysqli->rollback();
            json_error('Error al sincronizar: ' . $e->getMessage(), 500);
        }

        json_out(['ok' => true, 'total' => count($skusVistos)]);
        break;

    // ------------------------------------------------------------------
    // Catalogo completo (para que el programa pueda "traer" lo que ya esta
    // guardado en el servidor, por ejemplo al abrir en otra PC).
    // ------------------------------------------------------------------
    case 'productos_listar':
        $res = $mysqli->query("SELECT * FROM productos ORDER BY nombre ASC");
        json_out(['productos' => $res->fetch_all(MYSQLI_ASSOC)]);
        break;

    // ------------------------------------------------------------------
    // Guarda UN producto (alta o edicion individual desde el panel del
    // programa). Reemplaza el guardado contra WooCommerce.
    // Body: { sku, sku_original (si se renombro), nombre, ... }
    // ------------------------------------------------------------------
    case 'producto_guardar':
        $b = body_json();
        $sku = trim((string)($b['sku'] ?? ''));
        $skuOriginal = trim((string)($b['sku_original'] ?? ''));
        if ($sku === '') json_error('El codigo (SKU) es obligatorio.');
        $nombre = trim((string)($b['nombre'] ?? ''));
        if ($nombre === '') json_error('El nombre es obligatorio.');

        $descripcion = (string)($b['descripcion'] ?? '');
        $rubro = (string)($b['rubro'] ?? '');
        $subrubro = (string)($b['subrubro'] ?? '');
        $laboratorio = (string)($b['laboratorio'] ?? '');
        $imagenUrl = (string)($b['imagen_url'] ?? '');
        $pm = (float)($b['precio_mostrador'] ?? 0);
        $pmd = (isset($b['precio_mostrador_descuento']) && $b['precio_mostrador_descuento'] !== null && $b['precio_mostrador_descuento'] !== '')
            ? (float)$b['precio_mostrador_descuento'] : null;
        $pc = (float)($b['precio_comercio'] ?? 0);
        $pcd = (isset($b['precio_comercio_descuento']) && $b['precio_comercio_descuento'] !== null && $b['precio_comercio_descuento'] !== '')
            ? (float)$b['precio_comercio_descuento'] : null;
        $pg = (float)($b['precio_ganaderos'] ?? 0);
        $pgd = (isset($b['precio_ganaderos_descuento']) && $b['precio_ganaderos_descuento'] !== null && $b['precio_ganaderos_descuento'] !== '')
            ? (float)$b['precio_ganaderos_descuento'] : null;
        $stock = (string)($b['stock_status'] ?? 'instock');
        $activo = isset($b['activo']) ? (int)!!$b['activo'] : 1;

        try {
            if ($skuOriginal !== '' && $skuOriginal !== $sku) {
                // Se cambio el SKU: actualiza la fila que ya existia con el sku viejo,
                // en vez de crear una fila nueva (asi no queda un producto duplicado).
                $stmt = $mysqli->prepare(
                    "UPDATE productos SET
                        sku=?, nombre=?, descripcion=?, rubro=?, subrubro=?, laboratorio=?, imagen_url=?,
                        precio_mostrador=?, precio_mostrador_descuento=?,
                        precio_comercio=?, precio_comercio_descuento=?,
                        precio_ganaderos=?, precio_ganaderos_descuento=?,
                        stock_status=?, activo=?
                     WHERE sku=?"
                );
                $stmt->bind_param(
                    'sssssssddddddsis',
                    $sku, $nombre, $descripcion, $rubro, $subrubro, $laboratorio, $imagenUrl,
                    $pm, $pmd, $pc, $pcd, $pg, $pgd, $stock, $activo, $skuOriginal
                );
                if (!$stmt->execute()) {
                    throw new RuntimeException($mysqli->error);
                }
                if ($mysqli->affected_rows === 0) {
                    json_error('No se encontro el producto a renombrar (sku original: ' . $skuOriginal . ').', 404);
                }
            } else {
                // Alta o edicion sin cambio de sku: upsert normal.
                $stmt = $mysqli->prepare(
                    "INSERT INTO productos
                        (sku, nombre, descripcion, rubro, subrubro, laboratorio, imagen_url,
                         precio_mostrador, precio_mostrador_descuento,
                         precio_comercio, precio_comercio_descuento,
                         precio_ganaderos, precio_ganaderos_descuento,
                         stock_status, activo)
                     VALUES (?,?,?,?,?,?,?, ?,?, ?,?, ?,?, ?,?)
                     ON DUPLICATE KEY UPDATE
                        nombre=VALUES(nombre), descripcion=VALUES(descripcion),
                        rubro=VALUES(rubro), subrubro=VALUES(subrubro), laboratorio=VALUES(laboratorio),
                        imagen_url=VALUES(imagen_url),
                        precio_mostrador=VALUES(precio_mostrador), precio_mostrador_descuento=VALUES(precio_mostrador_descuento),
                        precio_comercio=VALUES(precio_comercio), precio_comercio_descuento=VALUES(precio_comercio_descuento),
                        precio_ganaderos=VALUES(precio_ganaderos), precio_ganaderos_descuento=VALUES(precio_ganaderos_descuento),
                        stock_status=VALUES(stock_status), activo=VALUES(activo)"
                );
                $stmt->bind_param(
                    'sssssssddddddsi',
                    $sku, $nombre, $descripcion, $rubro, $subrubro, $laboratorio, $imagenUrl,
                    $pm, $pmd, $pc, $pcd, $pg, $pgd, $stock, $activo
                );
                if (!$stmt->execute()) {
                    throw new RuntimeException($mysqli->error);
                }
            }
            json_out(['ok' => true, 'sku' => $sku]);
        } catch (Throwable $e) {
            json_error('Error al guardar el producto: ' . $e->getMessage(), 500);
        }
        break;

    // ------------------------------------------------------------------
    // Elimina UN producto por sku (edicion individual desde el panel).
    // Es seguro: pedido_items.producto_id queda en NULL si el producto
    // ya se uso en algun pedido (no se pierde el historial del pedido).
    // ------------------------------------------------------------------
    case 'producto_eliminar':
        $b = body_json();
        $sku = trim((string)($b['sku'] ?? ''));
        if ($sku === '') json_error('Falta el sku.');
        $stmt = $mysqli->prepare("DELETE FROM productos WHERE sku=?");
        $stmt->bind_param('s', $sku);
        $stmt->execute();
        json_out(['ok' => true]);
        break;

    // ------------------------------------------------------------------
    case 'clientes_listar':
        $res = $mysqli->query("SELECT * FROM clientes ORDER BY nombre ASC");
        json_out(['clientes' => $res->fetch_all(MYSQLI_ASSOC)]);
        break;

    case 'clientes_guardar':
        $b = body_json();
        $id = isset($b['id']) ? (int)$b['id'] : 0;
        $nombre = trim((string)($b['nombre'] ?? ''));
        $codigo = trim((string)($b['codigo_acceso'] ?? ''));
        $nivel = (string)($b['nivel_precio'] ?? 'mostrador');
        if ($nombre === '' || $codigo === '') json_error('Nombre y codigo de acceso son obligatorios.');
        if (!in_array($nivel, ['mostrador', 'comercio', 'ganaderos'], true)) $nivel = 'mostrador';
        $email = (string)($b['email'] ?? '');
        $telefono = (string)($b['telefono'] ?? '');
        $notas = (string)($b['notas'] ?? '');
        $activo = isset($b['activo']) ? (int)!!$b['activo'] : 1;

        if ($id > 0) {
            $stmt = $mysqli->prepare(
                "UPDATE clientes SET nombre=?, codigo_acceso=?, nivel_precio=?, email=?, telefono=?, notas=?, activo=? WHERE id=?"
            );
            $stmt->bind_param('ssssssii', $nombre, $codigo, $nivel, $email, $telefono, $notas, $activo, $id);
        } else {
            $stmt = $mysqli->prepare(
                "INSERT INTO clientes (nombre, codigo_acceso, nivel_precio, email, telefono, notas, activo) VALUES (?,?,?,?,?,?,?)"
            );
            $stmt->bind_param('ssssssi', $nombre, $codigo, $nivel, $email, $telefono, $notas, $activo);
        }
        if (!$stmt->execute()) {
            $dup = $mysqli->errno === 1062;
            json_error($dup ? 'Ese codigo de acceso ya esta en uso.' : ('Error al guardar: ' . $mysqli->error));
        }
        json_out(['ok' => true, 'id' => $id > 0 ? $id : $mysqli->insert_id]);
        break;

    case 'clientes_eliminar':
        $b = body_json();
        $id = (int)($b['id'] ?? 0);
        if ($id <= 0) json_error('Falta id.');
        try {
            $stmt = $mysqli->prepare("DELETE FROM clientes WHERE id=?");
            $stmt->bind_param('i', $id);
            if (!$stmt->execute()) {
                throw new RuntimeException($mysqli->error);
            }
            json_out(['ok' => true]);
        } catch (Throwable $e) {
            // Si el cliente ya tiene pedidos asociados, la FK no deja borrarlo
            // (asi no se pierde ese historial). El programa, ante este error,
            // ofrece desactivarlo en su lugar.
            json_error('No se pudo eliminar (probablemente ya tiene pedidos asociados).', 409);
        }
        break;

    // ------------------------------------------------------------------
    case 'pedidos_listar':
        $estado = $_GET['estado'] ?? '';
        $sql = "SELECT p.*, c.nombre AS cliente_nombre, c.codigo_acceso
                FROM pedidos p JOIN clientes c ON c.id = p.cliente_id";
        if ($estado !== '') {
            $stmt = $mysqli->prepare($sql . " WHERE p.estado=? ORDER BY p.creado_en DESC");
            $stmt->bind_param('s', $estado);
            $stmt->execute();
            $res = $stmt->get_result();
        } else {
            $res = $mysqli->query($sql . " ORDER BY p.creado_en DESC");
        }
        json_out(['pedidos' => $res->fetch_all(MYSQLI_ASSOC)]);
        break;

    case 'pedido_detalle':
        $id = (int)($_GET['id'] ?? 0);
        $stmt = $mysqli->prepare(
            "SELECT p.*, c.nombre AS cliente_nombre, c.codigo_acceso
             FROM pedidos p JOIN clientes c ON c.id = p.cliente_id WHERE p.id=?"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $pedido = $stmt->get_result()->fetch_assoc();
        if (!$pedido) json_error('Pedido no encontrado.', 404);

        $stmt2 = $mysqli->prepare("SELECT * FROM pedido_items WHERE pedido_id=?");
        $stmt2->bind_param('i', $id);
        $stmt2->execute();
        $pedido['items'] = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);

        json_out(['pedido' => $pedido]);
        break;

    case 'pedido_estado':
        $b = body_json();
        $id = (int)($b['id'] ?? 0);
        $estado = (string)($b['estado'] ?? '');
        if (!in_array($estado, ['nuevo', 'visto', 'preparando', 'completado', 'cancelado'], true)) {
            json_error('Estado invalido.');
        }
        $stmt = $mysqli->prepare(
            "UPDATE pedidos SET estado=?, visto_en = IF(visto_en IS NULL AND ? != 'nuevo', NOW(), visto_en) WHERE id=?"
        );
        $stmt->bind_param('ssi', $estado, $estado, $id);
        $stmt->execute();
        json_out(['ok' => true]);
        break;

    default:
        json_error('Accion desconocida.', 404);
}
