<?php
session_start();
require_once __DIR__ . '/../../config/conexion.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
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
    
    $pdo->exec("SET FOREIGN_KEY_CHECKS=0");

    $resetCount = 0;
    foreach ($tables as $table) {
        // Excluir la tabla de usuarios para no perder acceso
        if ($table !== 'usuarios') {
            $pdo->exec("TRUNCATE TABLE `$table`");
            $resetCount++;
        }
    }
    
    $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
    
    $_SESSION['flash_success'] = "¡El sistema ha sido reseteado! Se borraron los datos de $resetCount tablas. Tu usuario de acceso se mantuvo intacto.";

} catch (Exception $e) {
    $_SESSION['flash_error'] = "Error al resetear la base de datos: " . $e->getMessage();
}

header('Location: index.php');
exit;
