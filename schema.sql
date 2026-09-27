-- Esquema para el sistema de pedidos de El Fogon
-- Base: u974750866_fogon_pedidos

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- productos: espejo de lo que ya administra el programa de escritorio.
-- El programa sincroniza esta tabla completa cada vez que guarda cambios
-- (reemplaza a WooCommerce como "fuente de verdad" de precios/stock).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS productos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sku VARCHAR(64) NOT NULL,
    nombre VARCHAR(255) NOT NULL,
    descripcion TEXT NULL,
    rubro VARCHAR(120) NULL,
    subrubro VARCHAR(120) NULL,
    laboratorio VARCHAR(120) NULL,
    imagen_url VARCHAR(500) NULL,

    precio_mostrador DECIMAL(12,2) NOT NULL DEFAULT 0,
    precio_mostrador_descuento DECIMAL(12,2) NULL,
    precio_comercio DECIMAL(12,2) NOT NULL DEFAULT 0,
    precio_comercio_descuento DECIMAL(12,2) NULL,
    precio_ganaderos DECIMAL(12,2) NOT NULL DEFAULT 0,
    precio_ganaderos_descuento DECIMAL(12,2) NULL,

    stock_status ENUM('instock','outofstock') NOT NULL DEFAULT 'instock',
    activo TINYINT(1) NOT NULL DEFAULT 1,

    actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_productos_sku (sku),
    KEY idx_productos_rubro (rubro),
    KEY idx_productos_activo (activo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- clientes: veterinarias/granjas/etc. Cada uno entra al portal con su
-- codigo_acceso y ve los precios segun su nivel_precio.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS clientes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(255) NOT NULL,
    codigo_acceso VARCHAR(32) NOT NULL,
    nivel_precio ENUM('mostrador','comercio','ganaderos') NOT NULL DEFAULT 'mostrador',
    email VARCHAR(255) NULL,
    telefono VARCHAR(50) NULL,
    notas TEXT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_clientes_codigo (codigo_acceso)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- pedidos: una orden generada desde el portal. Queda con fecha/hora de
-- creacion y se puede consultar/exportar a PDF desde el programa.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS pedidos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT NOT NULL,
    estado ENUM('nuevo','visto','preparando','completado','cancelado') NOT NULL DEFAULT 'nuevo',
    notas TEXT NULL,
    total DECIMAL(12,2) NOT NULL DEFAULT 0,
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    visto_en TIMESTAMP NULL,

    KEY idx_pedidos_cliente (cliente_id),
    KEY idx_pedidos_estado (estado),
    KEY idx_pedidos_creado (creado_en),
    CONSTRAINT fk_pedidos_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- pedido_items: renglones del pedido. Se guarda una "foto" del nombre y
-- precio al momento de pedir (por si el producto cambia despues).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS pedido_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pedido_id INT NOT NULL,
    producto_id INT NULL,
    sku VARCHAR(64) NOT NULL,
    nombre VARCHAR(255) NOT NULL,
    cantidad INT NOT NULL DEFAULT 1,
    precio_unitario DECIMAL(12,2) NOT NULL DEFAULT 0,
    subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,

    KEY idx_items_pedido (pedido_id),
    CONSTRAINT fk_items_pedido FOREIGN KEY (pedido_id) REFERENCES pedidos(id) ON DELETE CASCADE,
    CONSTRAINT fk_items_producto FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
