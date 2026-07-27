<?php
require_once __DIR__ . '/config/conexion.php';

$db = Database::getConexion();
$stmt = $db->query("SELECT id, correo, pass_correo FROM empleados WHERE correo IS NOT NULL AND correo != ''");

$db->beginTransaction();

$stmtCheck = $db->prepare("SELECT id FROM cuentas_m365 WHERE correo = ?");
$stmtInsert = $db->prepare("
    INSERT INTO cuentas_m365 (empleado_id, correo, licencia_tipo, password_cifrado, fecha_inicializacion, ciclo_facturacion, fecha_proximo_pago, estado) 
    VALUES (?, ?, 'Microsoft 365 Business Standard', ?, CURDATE(), 'Mensual', DATE_ADD(CURDATE(), INTERVAL 1 MONTH), 'Activa')
");

$count = 0;
while ($row = $stmt->fetch()) {
    $stmtCheck->execute([$row['correo']]);
    if (!$stmtCheck->fetchColumn()) {
        $stmtInsert->execute([$row['id'], $row['correo'], $row['pass_correo']]);
        $count++;
    }
}

// También migrar el correo de Gmail al M365 (opcional, pero la vista asume que las cuentas están en cuentas_m365 o credenciales_web)
// Mejor no migrar Gmail a M365, M365 es para el correo corporativo Microsoft. El usuario dijo "no estas tomando las cuentas de correo del documento".
// M365 table is specifically for the corporate email.

$db->commit();
echo "Migrados $count correos a cuentas_m365.\n";
?>
