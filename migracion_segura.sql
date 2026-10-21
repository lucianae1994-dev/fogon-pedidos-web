-- Migracion SEGURA: se puede correr varias veces. Saltea lo que ya existe y
-- agrega lo que falta. Pegar todo en phpMyAdmin > pestana SQL.

ALTER TABLE productos ADD COLUMN IF NOT EXISTS max_por_pedido INT NULL AFTER stock_maximo;

ALTER TABLE clientes ADD COLUMN IF NOT EXISTS max_pedidos_dia INT NULL AFTER notas;
ALTER TABLE clientes ADD COLUMN IF NOT EXISTS es_invitado TINYINT(1) NOT NULL DEFAULT 0 AFTER max_pedidos_dia;

ALTER TABLE pedidos ADD COLUMN IF NOT EXISTS tipo ENUM('pedido','cotizacion') NOT NULL DEFAULT 'pedido' AFTER estado;
ALTER TABLE pedidos ADD COLUMN IF NOT EXISTS contacto_nombre VARCHAR(255) NULL;
ALTER TABLE pedidos ADD COLUMN IF NOT EXISTS contacto_telefono VARCHAR(60) NULL;
ALTER TABLE pedidos ADD COLUMN IF NOT EXISTS contacto_email VARCHAR(255) NULL;
ALTER TABLE pedidos ADD COLUMN IF NOT EXISTS ip VARCHAR(45) NULL;

CREATE TABLE IF NOT EXISTS config_portal (
    clave VARCHAR(60) PRIMARY KEY,
    valor VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO config_portal (clave, valor) VALUES
    ('max_unidades_default', '10'),
    ('max_pedidos_dia_default', '5'),
    ('max_cotizaciones_dia_invitado', '3');

-- Cliente invitado: solo se crea si todavia no existe.
INSERT INTO clientes (nombre, codigo_acceso, nivel_precio, es_invitado, activo)
SELECT 'INVITADO (cotizaciones)', CONCAT('INV-', UPPER(SUBSTRING(MD5(RAND()),1,12))), 'sin_precio', 1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM clientes WHERE es_invitado = 1);

-- Nivel de precio VENTA PLUS
ALTER TABLE productos ADD COLUMN IF NOT EXISTS precio_venta_plus DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER precio_ganaderos_descuento;
ALTER TABLE productos ADD COLUMN IF NOT EXISTS precio_venta_plus_descuento DECIMAL(12,2) NULL AFTER precio_venta_plus;
ALTER TABLE clientes MODIFY nivel_precio ENUM('mostrador','comercio','ganaderos','venta_plus','sin_precio') NOT NULL DEFAULT 'mostrador';
