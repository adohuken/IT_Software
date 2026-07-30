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

$system_name = trim($_POST['system_name'] ?? 'CuentasIT');
if (empty($system_name)) {
    $system_name = 'CuentasIT';
}

$config_content = "<?php\n// Configuración del Nombre de Sistema\nreturn [\n    'system_name' => " . var_export($system_name, true) . ",\n];\n";
$config_path = __DIR__ . '/../../config/system_config.php';

if (file_put_contents($config_path, $config_content) !== false) {
    $_SESSION['flash_sys_ok'] = 'Nombre del sistema actualizado correctamente.';
} else {
    $_SESSION['flash_sys_err'] = 'No se pudo guardar la configuración del sistema. Verifica los permisos de escritura.';
}

header('Location: index.php');
exit;
