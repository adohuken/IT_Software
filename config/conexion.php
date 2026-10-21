<?php
/**
 * Conexión Segura a la Base de Datos con PDO
 * Configura la Zona Horaria para Nicaragua (América/Managua)
 */

// Establecer la zona horaria en PHP
date_default_timezone_set('America/Managua');

// Detección automática de entorno: Local (XAMPP) vs En Línea (InfinityFree)
$http_host = $_SERVER['HTTP_HOST'] ?? '';

$is_local = (
    empty($http_host) || 
    strpos($http_host, 'localhost') !== false || 
    strpos($http_host, '127.0.0.1') !== false || 
    strpos($http_host, '::1') !== false ||
    strpos($http_host, '192.168.') === 0 ||
    strpos($http_host, '10.') === 0
);

if (!defined('IS_LOCAL')) define('IS_LOCAL', $is_local);

if ($is_local) {
    // ====================================================
    // ENTORNO LOCAL (XAMPP / Desarrollo en tu PC)
    // ====================================================
    if (!defined('DB_HOST'))  define('DB_HOST', 'localhost');
    if (!defined('DB_PORT'))  define('DB_PORT', '3306');
    if (!defined('DB_USER'))  define('DB_USER', 'root');
    if (!defined('DB_PASS'))  define('DB_PASS', '');
    if (!defined('DB_NAME'))  define('DB_NAME', 'gestion_it');
    if (!defined('BASE_URL')) define('BASE_URL', '/it_software/');
} else {
    // ====================================================
    // ENTORNO EN LÍNEA (InfinityFree: gestionit.xo.je)
    // ====================================================
    if (!defined('DB_HOST'))  define('DB_HOST', 'sql103.infinityfree.com');
    if (!defined('DB_PORT'))  define('DB_PORT', '3306');
    if (!defined('DB_USER'))  define('DB_USER', 'if0_43167470');
    if (!defined('DB_PASS'))  define('DB_PASS', 'q2WuKNEtrmdR8b');
    // En InfinityFree el nombre inicia con if0_43167470_ seguido del nombre que le diste al crearla
    if (!defined('DB_NAME'))  define('DB_NAME', 'if0_43167470_gestion_it');
    if (!defined('BASE_URL')) define('BASE_URL', '/');
}

class Database {
    private static $conexion = null;

    /**
     * Retorna una instancia única de la conexión PDO (Patrón Singleton)
     */
    public static function getConexion() {
        if (self::$conexion === null) {
            $opciones = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '-06:00'"
            ];

            // Lista de posibles nombres de base de datos a intentar
            $candidatos_db = [DB_NAME];
            if (DB_USER === 'if0_43167470') {
                $alternativas = [
                    'if0_43167470_gestion_it', 
                    'if0_43167470_gestionit', 
                    'if0_43167470_it_software', 
                    'if0_43167470_itsoftware'
                ];
                foreach ($alternativas as $alt) {
                    if (!in_array($alt, $candidatos_db)) {
                        $candidatos_db[] = $alt;
                    }
                }
            }

            $ultimo_error = null;
            $db_conectada = null;

            foreach ($candidatos_db as $dbname) {
                try {
                    $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . $dbname . ";charset=utf8mb4";
                    self::$conexion = new PDO($dsn, DB_USER, DB_PASS, $opciones);
                    $db_conectada = $dbname;
                    break;
                } catch (PDOException $e) {
                    $ultimo_error = $e;
                    // Si el error no es "base de datos desconocida" (código 1049), no seguir intentando
                    if ($e->getCode() != 1049 && strpos($e->getMessage(), 'Unknown database') === false) {
                        break;
                    }
                }
            }

            if (self::$conexion === null) {
                $es_remoto = (DB_HOST !== 'localhost');
                $html = "<div style='font-family:-apple-system,BlinkMacSystemFont,\"Segoe UI\",Roboto,sans-serif;max-width:620px;margin:50px auto;padding:28px;background:#ffffff;border:1px solid #fee2e2;border-radius:16px;box-shadow:0 10px 25px -5px rgba(0,0,0,0.08);color:#1e293b;'>";
                $html .= "<div style='display:flex;align-items:center;gap:12px;margin-bottom:16px;'>";
                $html .= "<div style='width:40px;height:40px;border-radius:10px;background:#fee2e2;display:flex;align-items:center;justify-content:center;color:#ef4444;font-size:20px;font-weight:bold;'>!</div>";
                $html .= "<h2 style='margin:0;font-size:18px;color:#991b1b;'>Error de conexión con la Base de Datos</h2>";
                $html .= "</div>";
                $html .= "<p style='font-size:14px;color:#475569;margin-bottom:16px;'>No fue posible establecer conexión con el servidor MySQL configurado:</p>";
                $html .= "<table style='width:100%;font-size:13px;border-collapse:collapse;margin-bottom:18px;background:#f8fafc;border-radius:8px;overflow:hidden;'>";
                $html .= "<tr><td style='padding:8px 12px;color:#64748b;border-bottom:1px solid #e2e8f0;'>Host:</td><td style='padding:8px 12px;font-weight:600;border-bottom:1px solid #e2e8f0;'>" . htmlspecialchars(DB_HOST) . "</td></tr>";
                $html .= "<tr><td style='padding:8px 12px;color:#64748b;border-bottom:1px solid #e2e8f0;'>Usuario:</td><td style='padding:8px 12px;font-weight:600;border-bottom:1px solid #e2e8f0;'>" . htmlspecialchars(DB_USER) . "</td></tr>";
                $html .= "<tr><td style='padding:8px 12px;color:#64748b;'>Base de Datos:</td><td style='padding:8px 12px;font-weight:600;'>" . htmlspecialchars(DB_NAME) . "</td></tr>";
                $html .= "</table>";
                $html .= "<div style='padding:12px;background:#fef2f2;border-left:4px solid #ef4444;border-radius:6px;font-size:12px;color:#991b1b;margin-bottom:18px;'>";
                $html .= "<strong>Detalle técnico:</strong> " . htmlspecialchars($ultimo_error ? $ultimo_error->getMessage() : 'Error desconocido');
                $html .= "</div>";
                if ($es_remoto) {
                    $html .= "<div style='font-size:13px;color:#334155;background:#f0fdf4;border:1px solid #bbf7d0;padding:14px;border-radius:10px;'>";
                    $html .= "<strong>💡 Solución en InfinityFree:</strong><br>";
                    $html .= "1. Ve a tu panel de control de InfinityFree y abre <strong>MySQL Databases</strong>.<br>";
                    $html .= "2. Si no has creado la base de datos, créala (ejemplo: con nombre <code>gestion_it</code>).<br>";
                    $html .= "3. Haz clic en <strong>phpMyAdmin</strong> junto a tu base de datos e importa el archivo <code>database/dump.sql</code>.";
                    $html .= "</div>";
                }
                $html .= "</div>";
                die($html);
            }
        }
        return self::$conexion;
    }
}
