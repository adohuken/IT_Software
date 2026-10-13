<?php
/**
 * Conexión Segura a la Base de Datos con PDO
 * Configura la Zona Horaria para Nicaragua (América/Managua)
 */

// Establecer la zona horaria en PHP
date_default_timezone_set('America/Managua');

// Detección de entorno: Local (XAMPP) vs Servidor Remoto (InfinityFree)
$is_local = (
    !isset($_SERVER['HTTP_HOST']) || 
    strpos($_SERVER['HTTP_HOST'], 'localhost') !== false || 
    strpos($_SERVER['HTTP_HOST'], '127.0.0.1') !== false
);

if ($is_local) {
    // Entorno Local (XAMPP)
    if (!defined('DB_HOST')) define('DB_HOST', 'localhost');
    if (!defined('DB_PORT')) define('DB_PORT', '3306');
    if (!defined('DB_USER')) define('DB_USER', 'root');
    if (!defined('DB_PASS')) define('DB_PASS', '');
    if (!defined('DB_NAME')) define('DB_NAME', 'gestion_it');
    if (!defined('BASE_URL')) define('BASE_URL', '/it_software/');
} else {
    // Entorno Producción (InfinityFree / gestionit.xo.je)
    // NOTA: Ajustar con los datos de 'MySQL Databases' de tu panel de InfinityFree
    if (!defined('DB_HOST')) define('DB_HOST', getenv('DB_HOST') ?: 'sql208.infinityfree.com'); 
    if (!defined('DB_PORT')) define('DB_PORT', '3306');
    if (!defined('DB_USER')) define('DB_USER', getenv('DB_USER') ?: 'if0_43167470');
    if (!defined('DB_PASS')) define('DB_PASS', getenv('DB_PASS') ?: 'q2WuKNEtrmdR8b');
    if (!defined('DB_NAME')) define('DB_NAME', getenv('DB_NAME') ?: 'if0_43167470_gestion_it');
    if (!defined('BASE_URL')) define('BASE_URL', '/');
}

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
