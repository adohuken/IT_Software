<?php
/**
 * Conexión Segura a la Base de Datos con PDO
 * Configura la Zona Horaria para Nicaragua (América/Managua)
 */

// Establecer la zona horaria en PHP
date_default_timezone_set('America/Managua');

// Parámetros de conexión (Ajustar según entorno de producción si es necesario)
define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'gestion_it');
define('BASE_URL', '/CuentasIT/');

class Database {
    private static $conexion = null;

    /**
     * Retorna una instancia única de la conexión PDO (Patrón Singleton)
     */
    public static function getConexion() {
        if (self::$conexion === null) {
            try {
                $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
                
                $opciones = [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    // Asegurar que la conexión a MySQL use UTF-8 y configure la zona horaria de Nicaragua (-06:00)
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '-06:00'"
                ];

                self::$conexion = new PDO($dsn, DB_USER, DB_PASS, $opciones);
            } catch (PDOException $e) {
                // Registrar el error de manera segura en producción. Para desarrollo local mostramos el mensaje:
                die("Error crítico de conexión a la base de datos: " . $e->getMessage());
            }
        }
        return self::$conexion;
    }
}
