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

// Aumentar límites para archivos de base de datos grandes
set_time_limit(300);
ini_set('memory_limit', '256M');

if (!isset($_FILES['sql_file']) || $_FILES['sql_file']['error'] !== UPLOAD_ERR_OK) {
    $errorMsg = 'No se ha subido ningún archivo o ocurrió un error en la carga.';
    if (isset($_FILES['sql_file']['error'])) {
        switch ($_FILES['sql_file']['error']) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                $errorMsg = 'El archivo supera el tamaño máximo permitido por el servidor.';
                break;
            case UPLOAD_ERR_NO_FILE:
                $errorMsg = 'Debes seleccionar un archivo .sql.';
                break;
        }
    }
    $_SESSION['flash_sys_err'] = $errorMsg;
    header('Location: index.php');
    exit;
}

$file = $_FILES['sql_file'];
$fileName = $file['name'];
$fileTmp = $file['tmp_name'];

$extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
if ($extension !== 'sql') {
    $_SESSION['flash_sys_err'] = 'Formato inválido. El archivo debe tener extensión .sql.';
    header('Location: index.php');
    exit;
}

try {
    $sqlContent = file_get_contents($fileTmp);
    if (empty(trim($sqlContent))) {
        throw new Exception('El archivo de respaldo está vacío.');
    }

    // Limpiar BOM UTF-8 si existe
    $sqlContent = preg_replace('/^\xEF\xBB\xBF/', '', $sqlContent);

    $pdo = Database::getConexion();
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, 1);

    // 1. Desactivar verificación de claves foráneas
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");

    // 2. Limpiar tablas existentes para evitar conflictos de "Table already exists"
    $tables = [];
    $stmt = $pdo->query('SHOW TABLES');
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        $tables[] = $row[0];
    }
    foreach ($tables as $tbl) {
        $pdo->exec("DROP TABLE IF EXISTS `$tbl`;");
    }

    // 3. Ejecutar el contenido del archivo SQL de respaldo
    $pdo->exec($sqlContent);

    // 4. Reactivar verificación de claves foráneas
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");

    $_SESSION['flash_sys_ok'] = "¡Base de datos restaurada correctamente desde " . htmlspecialchars($fileName) . "!";

} catch (Exception $e) {
    // Asegurar reactivar claves foráneas si hubo error
    try {
        if (isset($pdo)) {
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
        }
    } catch (Exception $ignored) {}

    $_SESSION['flash_sys_err'] = 'Error al restaurar la base de datos: ' . $e->getMessage();
}

header('Location: index.php');
exit;
