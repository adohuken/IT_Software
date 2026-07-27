-- ========================================================
-- SISTEMA DE GESTIÓN DE INFRAESTRUCTURA IT Y CREDENCIALES
-- ESQUEMA COMPLETO DE BASE DE DATOS Y DATOS INICIALES
-- Base de Datos: gestion_it
-- ========================================================

CREATE DATABASE IF NOT EXISTS `gestion_it` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `gestion_it`;

-- --------------------------------------------------------
-- 1. Tabla de Empresas
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `empresas` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `nombre` VARCHAR(100) NOT NULL,
    `estado` ENUM('Activo', 'Inactivo') DEFAULT 'Activo',
    `creado_en` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 2. Tabla de Sucursales
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sucursales` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `empresa_id` INT NOT NULL,
    `nombre` VARCHAR(100) NOT NULL,
    `ubicacion` VARCHAR(255),
    CONSTRAINT `fk_sucursal_empresa` FOREIGN KEY (`empresa_id`) REFERENCES `empresas`(`id`) ON DELETE CASCADE,
    INDEX `idx_empresa` (`empresa_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 3. Tabla de Personal IT / Usuarios del Sistema
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `equipo_it` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `nombre` VARCHAR(100) NOT NULL,
    `email` VARCHAR(100) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `rol` ENUM('Administrador', 'Soporte', 'Desarrollo') DEFAULT 'Soporte',
    `estado` ENUM('Activo', 'Inactivo') DEFAULT 'Activo'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 4. Tabla de Empleados / Usuarios de Equipos
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `empleados` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `sucursal_id` INT NOT NULL,
    `nombre` VARCHAR(100) NOT NULL,
    `cargo` VARCHAR(100),
    `departamento` VARCHAR(100),
    `estado` ENUM('Activo', 'Inactivo') DEFAULT 'Activo',
    CONSTRAINT `fk_empleado_sucursal` FOREIGN KEY (`sucursal_id`) REFERENCES `sucursales`(`id`) ON DELETE RESTRICT,
    INDEX `idx_sucursal` (`sucursal_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 5. Tabla de Credenciales Personales del Empleado
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `credenciales_personales` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `empleado_id` INT NOT NULL,
    `tipo` ENUM('Gmail', 'Correo Corporativo', 'PC PIN', 'Pass AG', 'Otro') NOT NULL,
    `usuario` VARCHAR(150),
    `password_cifrado` VARCHAR(255),
    `notas` TEXT,
    `actualizado_en` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_credencial_empleado` FOREIGN KEY (`empleado_id`) REFERENCES `empleados`(`id`) ON DELETE CASCADE,
    INDEX `idx_empleado_cred` (`empleado_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 6. Tabla de Equipos (Asset Management)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `equipos` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `sucursal_id` INT NOT NULL,
    `tipo` ENUM('Laptop', 'Desktop', 'Servidor', 'Maquina Virtual', 'Switch', 'Firewall', 'Access Point') NOT NULL,
    `marca` VARCHAR(50),
    `modelo` VARCHAR(100),
    `service_tag_sn` VARCHAR(100) UNIQUE,
    `ip_direccion` VARCHAR(50),
    `procesador` VARCHAR(150),
    `disco` VARCHAR(100),
    `ram` VARCHAR(50),
    `estado` ENUM('Disponible', 'Asignado', 'Mantenimiento', 'De Baja') DEFAULT 'Disponible',
    `notas` TEXT,
    CONSTRAINT `fk_equipo_sucursal` FOREIGN KEY (`sucursal_id`) REFERENCES `sucursales`(`id`) ON DELETE RESTRICT,
    INDEX `idx_sucursal_equipo` (`sucursal_id`),
    INDEX `idx_estado_equipo` (`estado`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 7. Tabla de Asignaciones (Historial de Asignación)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `asignaciones` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `equipo_id` INT NOT NULL,
    `empleado_id` INT NOT NULL,
    `fecha_entrega` DATE NOT NULL,
    `fecha_devolucion` DATE NULL,
    `condicion_entrega` VARCHAR(255),
    CONSTRAINT `fk_asignacion_equipo` FOREIGN KEY (`equipo_id`) REFERENCES `equipos`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_asignacion_empleado` FOREIGN KEY (`empleado_id`) REFERENCES `empleados`(`id`) ON DELETE CASCADE,
    INDEX `idx_equipo` (`equipo_id`),
    INDEX `idx_empleado` (`empleado_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 8. Tabla de Credenciales de Equipos y BitLocker
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `credenciales_equipos` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `equipo_id` INT NOT NULL,
    `usuario_local` VARCHAR(100) NOT NULL,
    `password_cifrado` VARCHAR(255) NOT NULL,
    `clave_bitlocker` VARCHAR(255),
    `notas_acceso` TEXT,
    `actualizado_en` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_credencial_equipo` FOREIGN KEY (`equipo_id`) REFERENCES `equipos`(`id`) ON DELETE CASCADE,
    INDEX `idx_equipo_cred` (`equipo_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 9. Tabla de Credenciales Web y Portales
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `credenciales_web` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `plataforma` VARCHAR(100) NOT NULL,
    `url_acceso` VARCHAR(255),
    `usuario` VARCHAR(100) NOT NULL,
    `password_cifrado` VARCHAR(255) NOT NULL,
    `notas` TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 10. Tabla de Cuentas M365 y Facturación
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cuentas_m365` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `empleado_id` INT NULL,
    `correo` VARCHAR(150) NOT NULL UNIQUE,
    `licencia_tipo` VARCHAR(100),
    `password_cifrado` VARCHAR(255) NULL,
    `fecha_inicializacion` DATE NOT NULL,
    `ciclo_facturacion` ENUM('Mensual', 'Anual') DEFAULT 'Mensual',
    `fecha_proximo_pago` DATE NOT NULL,
    `estado` ENUM('Activa', 'Suspendida', 'Eliminada') DEFAULT 'Activa',
    CONSTRAINT `fk_m365_empleado` FOREIGN KEY (`empleado_id`) REFERENCES `empleados`(`id`) ON DELETE SET NULL,
    INDEX `idx_empleado_m365` (`empleado_id`),
    INDEX `idx_fecha_pago` (`fecha_proximo_pago`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 11. Tabla de Tareas IT y Mantenimientos
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tareas_it` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `titulo` VARCHAR(150) NOT NULL,
    `descripcion` TEXT,
    `asignado_a` INT NULL,
    `fecha_creacion` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `fecha_limite` DATE NOT NULL,
    `es_repetitiva` BOOLEAN DEFAULT FALSE,
    `frecuencia_dias` INT NULL,
    `estado` ENUM('Pendiente', 'En Progreso', 'Completada', 'Vencida') DEFAULT 'Pendiente',
    CONSTRAINT `fk_tarea_equipo_it` FOREIGN KEY (`asignado_a`) REFERENCES `equipo_it`(`id`) ON DELETE SET NULL,
    INDEX `idx_asignado_tarea` (`asignado_a`),
    INDEX `idx_estado_tarea` (`estado`),
    INDEX `idx_fecha_limite` (`fecha_limite`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- DATOS INICIALES POR DEFECTO
-- --------------------------------------------------------
INSERT IGNORE INTO `equipo_it` (`id`, `nombre`, `email`, `password_hash`, `rol`, `estado`) VALUES
(1, 'Administrador IT', 'dmartinez@mastertec.com.ni', '$2y$10$eE.lT3xMg8Mh/jGZ6g8DbeO3kK0p2LqH3m8Yx9zQ4jK7V8x9Z0Y1a', 'Administrador', 'Activo');
