-- ========================================================
-- MIGRACIÓN: Módulo de Gestión de Redes Wi-Fi por Sede
-- ========================================================

-- 1. Crear tabla redes_wifi
CREATE TABLE IF NOT EXISTS `redes_wifi` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `sucursal_id` INT(11) NOT NULL,
  `ssid` VARCHAR(150) NOT NULL,
  `password_cifrado` VARCHAR(255) NOT NULL,
  `tipo_red` ENUM(
      'Corporativa / Empleados',
      'Invitados / Clientes',
      'Operativa / Almacén',
      'Administración / Gerencia',
      'Otra'
  ) DEFAULT 'Corporativa / Empleados',
  `frecuencia` ENUM(
      '2.4 GHz',
      '5 GHz',
      'Dual Band (2.4 / 5 GHz)',
      'Wi-Fi 6'
  ) DEFAULT 'Dual Band (2.4 / 5 GHz)',
  `seguridad` VARCHAR(50) DEFAULT 'WPA2-PSK',
  `vlan_id` VARCHAR(20) DEFAULT NULL,
  `ap_asociado` VARCHAR(150) DEFAULT NULL,
  `estado` ENUM(
      'Activa',
      'Inactiva',
      'Oculta',
      'Mantenimiento'
  ) DEFAULT 'Activa',
  `notas` TEXT DEFAULT NULL,
  `creado_en` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `actualizado_en` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_wifi_sucursal` (`sucursal_id`),
  INDEX `idx_wifi_tipo` (`tipo_red`),
  CONSTRAINT `fk_wifi_sucursal` FOREIGN KEY (`sucursal_id`) REFERENCES `sucursales` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
