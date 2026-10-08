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

/** Valor numerico de config_portal (defaults editables desde el programa).
 * 0 significa "sin limite". Si la tabla/clave no existe, usa $defecto. */
function config_num(mysqli $mysqli, string $clave, int $defecto): int {
    try {
        $stmt = $mysqli->prepare("SELECT valor FROM config_portal WHERE clave=?");
        $stmt->bind_param('s', $clave);
        $stmt->execute();
        $r = $stmt->get_result()->fetch_assoc();
        return $r ? max(0, (int)$r['valor']) : $defecto;
    } catch (Throwable $e) {
        return $defecto;
    }
}

/** Cantidad maxima que se puede pedir de un producto en un pedido, o null
 * si no hay limite: el menor entre el limite por producto (o el general por
 * defecto) y el stock disponible (si hay stock cargado y es mayor que 0). */
function max_cantidad_producto(array $p, int $defecto): ?int {
    $lim = isset($p['max_por_pedido']) && $p['max_por_pedido'] !== null ? (int)$p['max_por_pedido'] : $defecto;
    $max = $lim > 0 ? $lim : null;
    $actual = $p['stock_actual'] ?? null;
    if ($actual !== null && (int)$actual > 0 && ($p['stock_status'] ?? '') !== 'outofstock') {
        $max = $max === null ? (int)$actual : min($max, (int)$actual);
    }
    return $max;
}

/** Precio del producto para el nivel del cliente. Se define aca (y no se usa
 * precio_para_nivel de config.php) porque config.php no se despliega por Git.
 * 'ganaderos' se muestra como VENTA; 'venta_plus' es el nivel nuevo. */
function precio_nivel(array $producto, string $nivel): array {
    $map = [
        'mostrador'  => ['precio_mostrador', 'precio_mostrador_descuento'],
        'comercio'   => ['precio_comercio', 'precio_comercio_descuento'],
        'ganaderos'  => ['precio_ganaderos', 'precio_ganaderos_descuento'],
        'venta_plus' => ['precio_venta_plus', 'precio_venta_plus_descuento'],
        'sin_precio' => ['precio_mostrador', 'precio_mostrador_descuento'],
    ];
    [$campoBase, $campoDesc] = $map[$nivel] ?? $map['mostrador'];
    $base = (float)($producto[$campoBase] ?? 0);
    $desc = $producto[$campoDesc] ?? null;
    $desc = ($desc !== null && (float)$desc > 0 && (float)$desc < $base) ? (float)$desc : null;
    return ['precio' => $desc ?? $base, 'precio_regular' => $base, 'precio_descuento' => $desc];
}

const IVA_PORC = 21.0;

/** Aplica el IVA al precio (los precios cargados son netos). Si el producto
 * tiene aplica_iva=0 devuelve el precio tal cual. */
function con_iva(?float $v, array $prod): ?float {
    if ($v === null) return null;
    $aplica = !array_key_exists('aplica_iva', $prod) || (int)$prod['aplica_iva'] === 1;
    return $aplica ? round($v * (1 + IVA_PORC / 100), 2) : $v;
}

function iva_alicuota_producto(array $prod): float {
    $aplica = !array_key_exists('aplica_iva', $prod) || (int)$prod['aplica_iva'] === 1;
    return $aplica ? IVA_PORC : 0.0;
}

function cliente_invitado(mysqli $mysqli): ?array {
    $res = $mysqli->query("SELECT * FROM clientes WHERE es_invitado=1 AND activo=1 LIMIT 1");
    $c = $res ? $res->fetch_assoc() : null;
    // El invitado NUNCA ve precios (siempre nivel sin_precio), sin importar lo guardado en la base.
    if ($c) $c['nivel_precio'] = 'sin_precio';
    return $c ?: null;
}

/** Cliente de la peticion: el invitado si viene {"invitado": true}, o el que
 * corresponde al codigo de acceso. */
function resolver_cliente(mysqli $mysqli, array $b): ?array {
    if (!empty($b['invitado'])) return cliente_invitado($mysqli);
    return cliente_por_codigo($mysqli, (string)($b['codigo'] ?? ''));
}

function cliente_por_codigo(mysqli $mysqli, string $codigo): ?array {
    $codigo = trim($codigo);
    if ($codigo === '') return null;
    $stmt = $mysqli->prepare("SELECT * FROM clientes WHERE codigo_acceso=? AND activo=1 AND es_invitado=0");
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

    // Entrada como invitado (sin codigo): ve precios de lista y solo puede
    // solicitar cotizaciones.
    case 'login_invitado':
        $g = cliente_invitado($mysqli);
        if (!$g) json_error('El acceso como invitado no esta habilitado.', 403);
        json_out([
            'ok' => true,
            'cliente' => [
                'id' => (int)$g['id'],
                'nombre' => 'Invitado',
                'nivel_precio' => $g['nivel_precio'],
                'ver_precio' => ver_precio($g['nivel_precio']),
                'invitado' => true,
            ],
        ]);
        break;

    // ------------------------------------------------------------------
    // Body: { "codigo": "..." }  -> catalogo completo con precio segun el
    // nivel del cliente. El front lo filtra por rubro en el navegador.
    // ------------------------------------------------------------------
    case 'catalogo':
        $b = body_json();
        $cliente = resolver_cliente($mysqli, $b);
        if (!$cliente) json_error('Codigo de acceso invalido.', 401);
        $defUnidades = config_num($mysqli, 'max_unidades_default', 10);

        $res = $mysqli->query(
            "SELECT id, sku, nombre, descripcion, rubro, subrubro, laboratorio, imagen_url, max_por_pedido, aplica_iva,
                    precio_mostrador, precio_mostrador_descuento,
                    precio_comercio, precio_comercio_descuento,
                    precio_ganaderos, precio_ganaderos_descuento,
                    precio_venta_plus, precio_venta_plus_descuento,
                    stock_status, stock_actual, stock_minimo, stock_maximo
             FROM productos WHERE activo=1 ORDER BY rubro, nombre"
        );

        $verPrecio = ver_precio($cliente['nivel_precio']);
        $productos = [];
        while ($p = $res->fetch_assoc()) {
            $precios = precio_nivel($p, $cliente['nivel_precio']);
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
                'max_cantidad' => max_cantidad_producto($p, $defUnidades),
                'precio' => $verPrecio ? con_iva($precios['precio'], $p) : null,
                'precio_regular' => $verPrecio ? con_iva($precios['precio_regular'], $p) : null,
                'precio_descuento' => $verPrecio ? con_iva($precios['precio_descuento'], $p) : null,
                'iva_incluido' => iva_alicuota_producto($p) > 0,
            ];
        }
        json_out(['productos' => $productos, 'nivel_precio' => $cliente['nivel_precio'], 'ver_precio' => $verPrecio, 'invitado' => (bool)$cliente['es_invitado']]);
        break;

    // ------------------------------------------------------------------
    // Body: { "codigo": "...", "items": [{"sku": "...", "cantidad": 2}], "notas": "..." }
    // Los precios se recalculan en el servidor (nunca se confia en el precio
    // que mande el navegador).
    // ------------------------------------------------------------------
    case 'pedido_crear':
        $b = body_json();
        $cliente = resolver_cliente($mysqli, $b);
        if (!$cliente) json_error('Codigo de acceso invalido.', 401);
        $esInvitado = (int)$cliente['es_invitado'] === 1;
        $clienteId = (int)$cliente['id'];
        $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);

        // Datos de contacto (obligatorios para invitados).
        $contactoNombre = trim((string)($b['contacto_nombre'] ?? ''));
        $contactoTel = trim((string)($b['contacto_telefono'] ?? ''));
        $contactoMail = trim((string)($b['contacto_email'] ?? ''));
        if ($esInvitado) {
            if ($contactoNombre === '' || ($contactoTel === '' && $contactoMail === '')) {
                json_error('Para solicitar una cotizacion ingresa tu nombre y un telefono o email de contacto.');
            }
            if ($contactoMail !== '' && !filter_var($contactoMail, FILTER_VALIDATE_EMAIL)) {
                json_error('El email ingresado no es valido.');
            }
        }

        // Limite de pedidos por dia (los cancelados no cuentan).
        if ($esInvitado) {
            $limDia = config_num($mysqli, 'max_cotizaciones_dia_invitado', 3);
            if ($limDia > 0) {
                $q = $mysqli->prepare("SELECT COUNT(*) n FROM pedidos WHERE cliente_id=? AND ip=? AND DATE(creado_en)=CURDATE() AND estado<>'cancelado'");
                $q->bind_param('is', $clienteId, $ip);
                $q->execute();
                if ((int)$q->get_result()->fetch_assoc()['n'] >= $limDia) {
                    json_error('Alcanzaste el maximo de cotizaciones por dia. Proba de nuevo manana o comunicate con nosotros.', 429);
                }
            }
        } else {
            $limDia = $cliente['max_pedidos_dia'] !== null
                ? (int)$cliente['max_pedidos_dia']
                : config_num($mysqli, 'max_pedidos_dia_default', 5);
            if ($limDia > 0) {
                $q = $mysqli->prepare("SELECT COUNT(*) n FROM pedidos WHERE cliente_id=? AND DATE(creado_en)=CURDATE() AND estado<>'cancelado'");
                $q->bind_param('i', $clienteId);
                $q->execute();
                if ((int)$q->get_result()->fetch_assoc()['n'] >= $limDia) {
                    json_error("Alcanzaste el maximo de $limDia pedido(s) por dia. Para hacer otro, comunicate con nosotros.", 429);
                }
            }
        }
        $defUnidades = config_num($mysqli, 'max_unidades_default', 10);

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
        $pedidoPorSku = [];
        foreach ($items as $it) {
            $sku = trim((string)($it['sku'] ?? ''));
            $pedidoPorSku[$sku] = ($pedidoPorSku[$sku] ?? 0) + max(1, (int)($it['cantidad'] ?? 1));
        }
        foreach ($pedidoPorSku as $sku => $cantidad) {
            if ($sku === '' || !isset($porSku[$sku])) continue;
            $prod = $porSku[$sku];
            $maxC = max_cantidad_producto($prod, $defUnidades);
            if ($maxC !== null && $cantidad > $maxC) {
                json_error('Maximo ' . $maxC . ' unidad(es) por pedido de "' . $prod['nombre'] . '". Ajusta la cantidad y volve a enviar.');
            }
            $precios = precio_nivel($prod, $cliente['nivel_precio']);
            $precioFinal = con_iva($precios['precio'], $prod);
            $subtotal = round($precioFinal * $cantidad, 2);
            $total += $subtotal;
            $lineas[] = [
                'producto_id' => (int)$prod['id'],
                'sku' => $sku,
                'nombre' => $prod['nombre'],
                'cantidad' => $cantidad,
                'precio_unitario' => $precioFinal,
                'iva_alicuota' => iva_alicuota_producto($prod),
                'subtotal' => $subtotal,
                'sin_stock_confirmar' => producto_sin_stock($prod) ? 1 : 0,
            ];
        }
        if (count($lineas) === 0) json_error('Ninguno de los productos del pedido esta disponible.');

        $notas = trim((string)($b['notas'] ?? ''));

        $mysqli->begin_transaction();
        try {
            $tipo = $esInvitado ? 'cotizacion' : 'pedido';
            if ($esInvitado) {
                $contacto = 'COTIZACION - Contacto: ' . $contactoNombre
                    . ($contactoTel !== '' ? ' | Tel: ' . $contactoTel : '')
                    . ($contactoMail !== '' ? ' | Email: ' . $contactoMail : '');
                $notas = $contacto . ($notas !== '' ? "\n" . $notas : '');
            }
            $stmtP = $mysqli->prepare(
                "INSERT INTO pedidos (cliente_id, estado, tipo, notas, total, contacto_nombre, contacto_telefono, contacto_email, ip)
                 VALUES (?, 'nuevo', ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmtP->bind_param('issdssss', $clienteId, $tipo, $notas, $total, $contactoNombre, $contactoTel, $contactoMail, $ip);
            $stmtP->execute();
            $pedidoId = $mysqli->insert_id;

            $stmtI = $mysqli->prepare(
                "INSERT INTO pedido_items (pedido_id, producto_id, sku, nombre, cantidad, precio_unitario, subtotal, sin_stock_confirmar, iva_alicuota)
                 VALUES (?,?,?,?,?,?,?,?,?)"
            );
            foreach ($lineas as $l) {
                $stmtI->bind_param(
                    'iissiddid',
                    $pedidoId, $l['producto_id'], $l['sku'], $l['nombre'],
                    $l['cantidad'], $l['precio_unitario'], $l['subtotal'], $l['sin_stock_confirmar'], $l['iva_alicuota']
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
                'cliente' => $esInvitado ? $contactoNombre : $cliente['nombre'],
                'tipo' => $tipo,
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
