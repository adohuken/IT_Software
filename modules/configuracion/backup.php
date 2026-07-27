<?php
session_start();
require_once __DIR__ . '/../../config/conexion.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}

try {
    $pdo = Database::getConexion();
    
    // Obtener todas las tablas
    $tables = [];
    $stmt = $pdo->query('SHOW TABLES');
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        $tables[] = $row[0];
    }
    
    $sqlScript = "-- Respaldo de Base de Datos CuentasIT\n";
    $sqlScript .= "-- Generado el: " . date('Y-m-d H:i:s') . "\n\n";
    
    $sqlScript .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

    foreach ($tables as $table) {
        // Estructura de la tabla
        $stmt = $pdo->query("SHOW CREATE TABLE `$table`");
        $row = $stmt->fetch(PDO::FETCH_NUM);
        $sqlScript .= "\n-- --------------------------------------------------------\n";
        $sqlScript .= "-- Estructura de la tabla `$table`\n";
        $sqlScript .= "-- --------------------------------------------------------\n";
        $sqlScript .= "DROP TABLE IF EXISTS `$table`;\n";
        $sqlScript .= $row[1] . ";\n\n";
        
        // Datos de la tabla
        $stmt = $pdo->query("SELECT * FROM `$table`");
        $rowCount = $stmt->rowCount();
        
        if ($rowCount > 0) {
            $sqlScript .= "-- Volcando datos para la tabla `$table`\n";
            while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $keys = array_keys($data);
                $values = array_values($data);
                
                $keysString = implode("`, `", $keys);
                
                $valuesEscaped = array_map(function($val) use ($pdo) {
                    if (is_null($val)) {
                        return "NULL";
                    }
                    return $pdo->quote($val);
                }, $values);
                
                $valuesString = implode(", ", $valuesEscaped);
                
                $sqlScript .= "INSERT INTO `$table` (`$keysString`) VALUES ($valuesString);\n";
            }
            $sqlScript .= "\n";
        }
    }
    
    $sqlScript .= "SET FOREIGN_KEY_CHECKS=1;\n";
    
    // Forzar descarga del archivo
    $filename = 'respaldo_cuentasit_' . date('Y_m_d_H_i') . '.sql';
    
    // Limpiar el buffer de salida si hay algo antes
    while (ob_get_level()) ob_end_clean();
    
    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    echo $sqlScript;
    exit;

} catch (Exception $e) {
    $_SESSION['flash_error'] = "Error al generar el respaldo: " . $e->getMessage();
    header('Location: index.php');
    exit;
}
