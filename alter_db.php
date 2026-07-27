<?php
/**
 * Script de migracion (Opcion B)
 */
require_once __DIR__ . "/config/conexion.php";
require_once __DIR__ . "/config/security.php";

try {
    $db = Database::getConexion();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "Iniciando migracion de credenciales personales...<br>";

    $db->exec("
        CREATE TABLE IF NOT EXISTS credenciales_personales (
            id INT AUTO_INCREMENT PRIMARY KEY,
            empleado_id INT NOT NULL,
            tipo ENUM(\"Gmail\", \"Correo Corporativo\", \"PC PIN\", \"Pass AG\", \"Otro\") NOT NULL,
            usuario VARCHAR(150),
            password_cifrado VARCHAR(255),
            notas TEXT,
            actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (empleado_id) REFERENCES empleados(id) ON DELETE CASCADE,
            INDEX idx_empleado_cred (empleado_id)
        ) ENGINE=InnoDB;
    ");
    echo "- Tabla credenciales_personales creada.<br>";

    $stmtCheck = $db->query("SHOW COLUMNS FROM empleados LIKE \"correo\"");
    if ($stmtCheck->rowCount() > 0) {
        $empleados = $db->query("SELECT id, correo, pass_correo, pc_pin, pass_ag, correo_gmail, pass_gmail FROM empleados")->fetchAll();
        $stmtInsert = $db->prepare("INSERT INTO credenciales_personales (empleado_id, tipo, usuario, password_cifrado) VALUES (?, ?, ?, ?)");
        
        $migrados = 0;
        $db->beginTransaction();
        foreach ($empleados as $emp) {
            if (!empty($emp["correo_gmail"])) {
                $stmtInsert->execute([$emp["id"], "Gmail", $emp["correo_gmail"], $emp["pass_gmail"]]);
                $migrados++;
            }
            if (!empty($emp["correo"])) {
                $stmtInsert->execute([$emp["id"], "Correo Corporativo", $emp["correo"], $emp["pass_correo"]]);
                $migrados++;
            }
            if (!empty($emp["pc_pin"])) {
                $pin_val = $emp["pc_pin"];
                if (strlen($pin_val) < 30) {
                    $pin_val = Security::encrypt($pin_val);
                }
                $stmtInsert->execute([$emp["id"], "PC PIN", null, $pin_val]);
                $migrados++;
            }
            if (!empty($emp["pass_ag"])) {
                $stmtInsert->execute([$emp["id"], "Pass AG", null, $emp["pass_ag"]]);
                $migrados++;
            }
        }
        $db->commit();
        echo "- Se migraron $migrados credenciales a la nueva tabla.<br>";

        $db->exec("ALTER TABLE empleados DROP COLUMN correo, DROP COLUMN pass_correo, DROP COLUMN pc_pin, DROP COLUMN pass_ag, DROP COLUMN correo_gmail, DROP COLUMN pass_gmail");
        echo "- Columnas antiguas eliminadas de empleados.<br>";
        
    } else {
        echo "- Las columnas antiguas ya fueron eliminadas. Nada que migrar.<br>";
    }

    echo "<b>Migracion completada con exito.</b><br>";
    echo "<a href=\"modules/credenciales/index.php\">Ir al inicio</a>";

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    echo "<b style=\"color:red;\">Error en migracion: " . $e->getMessage() . "</b>";
}
