-- ========================================================
-- MIGRACIÓN: Módulos de Impresoras por Sucursal y Tenants Cloud
-- ========================================================

-- 1. Tabla de Impresoras por Sucursal
CREATE TABLE IF NOT EXISTS `impresoras` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `sucursal_id` INT NOT NULL,
    `nombre` VARCHAR(150) NOT NULL,
    `marca` VARCHAR(50) DEFAULT NULL,
    `modelo` VARCHAR(100) DEFAULT NULL,
    `numero_serie` VARCHAR(100) DEFAULT NULL,
    `tipo` ENUM(
        'Multifuncional Láser',
        'Láser Monocromática',
        'Láser Color',
        'Inyección de Tinta / Tinta Continua',
        'Térmica / Facturación / POS',
        'Matriz de Punto',
        'Plotter / Gran Formato',
        'Otra'
    ) DEFAULT 'Multifuncional Láser',
    `tipo_conexion` ENUM(
        'Red Cableada (LAN)',
        'Wi-Fi Inalámbrica',
        'USB Directa',
        'Servidor de Impresión / Compartida'
    ) DEFAULT 'Red Cableada (LAN)',
    `ip_direccion` VARCHAR(50) DEFAULT NULL,
    `usuario_admin` VARCHAR(100) DEFAULT NULL,
    `password_cifrado` VARCHAR(255) DEFAULT NULL,
    `ubicacion_area` VARCHAR(150) DEFAULT NULL,
    `estado` ENUM(
        'Operativa',
        'En Mantenimiento',
        'Sin Insumos / Tóner',
        'Dañada / Fuera de Servicio'
    ) DEFAULT 'Operativa',
    `toner_insumos` VARCHAR(150) DEFAULT NULL,
    `notas` TEXT DEFAULT NULL,
    `creado_en` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `actualizado_en` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_impresora_sucursal` FOREIGN KEY (`sucursal_id`) REFERENCES `sucursales`(`id`) ON DELETE CASCADE,
    INDEX `idx_impresora_sucursal` (`sucursal_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Tabla de Tenants Cloud / Microsoft 365
CREATE TABLE IF NOT EXISTS `tenants` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `empresa_id` INT DEFAULT NULL,
    `nombre_tenant` VARCHAR(150) NOT NULL,
    `dominio_principal` VARCHAR(150) DEFAULT NULL,
    `correo` VARCHAR(150) NOT NULL,
    `password_cifrado` VARCHAR(255) NOT NULL,
    `tenant_id_cloud` VARCHAR(100) DEFAULT NULL,
    `url_portal` VARCHAR(255) DEFAULT 'https://admin.microsoft.com',
    `descripcion` TEXT DEFAULT NULL,
    `estado` ENUM(
        'Activo',
        'En Configuración',
        'Mantenimiento',
        'Inactivo'
    ) DEFAULT 'Activo',
    `creado_en` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `actualizado_en` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_tenant_empresa` FOREIGN KEY (`empresa_id`) REFERENCES `empresas`(`id`) ON DELETE SET NULL,
    INDEX `idx_tenant_empresa` (`empresa_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
