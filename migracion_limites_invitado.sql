-- Migracion: limites de pedidos/unidades + acceso invitado (cotizaciones).
-- Correr UNA VEZ en phpMyAdmin (base u974750866_fogon_pedidos), pestana SQL.

-- 1) Limite de unidades por pedido, por producto (NULL = usa el valor por defecto).
ALTER TABLE productos
    ADD COLUMN max_por_pedido INT NULL AFTER stock_maximo;

-- 2) Limite de pedidos por dia, por cliente (NULL = usa el valor por defecto)
--    y marca de cliente "invitado".
ALTER TABLE clientes
    ADD COLUMN max_pedidos_dia INT NULL AFTER notas,
    ADD COLUMN es_invitado TINYINT(1) NOT NULL DEFAULT 0 AFTER max_pedidos_dia;

-- 3) Pedidos: tipo (pedido normal o cotizacion de invitado) y datos de contacto.
ALTER TABLE pedidos
    ADD COLUMN tipo ENUM('pedido','cotizacion') NOT NULL DEFAULT 'pedido' AFTER estado,
    ADD COLUMN contacto_nombre VARCHAR(255) NULL,
    ADD COLUMN contacto_telefono VARCHAR(60) NULL,
    ADD COLUMN contacto_email VARCHAR(255) NULL,
    ADD COLUMN ip VARCHAR(45) NULL;

-- 4) Valores por defecto configurables desde el programa.
CREATE TABLE IF NOT EXISTS config_portal (
    clave VARCHAR(60) PRIMARY KEY,
    valor VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO config_portal (clave, valor) VALUES
    ('max_unidades_default', '10'),        -- 0 = sin limite
    ('max_pedidos_dia_default', '5'),      -- 0 = sin limite
    ('max_cotizaciones_dia_invitado', '3'); -- por IP, 0 = sin limite

-- 5) Cliente interno que representa a los invitados (no se usa para entrar con codigo).
INSERT INTO clientes (nombre, codigo_acceso, nivel_precio, es_invitado, activo)
VALUES ('INVITADO (cotizaciones)', CONCAT('INV-', UPPER(SUBSTRING(MD5(RAND()),1,12))), 'mostrador', 1, 1);

-- 6) IVA 21%: los precios cargados son NETOS; si aplica_iva=1 el portal y los
--    pedidos usan precio * 1.21. En cada renglon del pedido se guarda la
--    alicuota usada (0 = sin IVA / pedidos viejos) para poder desglosar.
ALTER TABLE productos
    ADD COLUMN aplica_iva TINYINT(1) NOT NULL DEFAULT 1 AFTER max_por_pedido;
ALTER TABLE pedido_items
    ADD COLUMN iva_alicuota DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER sin_stock_confirmar;

-- 7) Nuevo nivel de precio VENTA PLUS ("Ganaderos" pasa a llamarse VENTA solo
--    en pantalla; internamente sigue siendo 'ganaderos').
ALTER TABLE productos
    ADD COLUMN precio_venta_plus DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER precio_ganaderos_descuento,
    ADD COLUMN precio_venta_plus_descuento DECIMAL(12,2) NULL AFTER precio_venta_plus;
ALTER TABLE clientes
    MODIFY nivel_precio ENUM('mostrador','comercio','ganaderos','venta_plus','sin_precio') NOT NULL DEFAULT 'mostrador';
