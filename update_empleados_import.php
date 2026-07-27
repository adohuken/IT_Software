<?php
$file = 'c:/xampp/htdocs/CuentasIT/modules/empleados/guardar.php';
$content = file_get_contents($file);

$startMarker = "// 1.5. Importar Personal desde CSV";
$endMarker = "} else {";

$posStart = strpos($content, $startMarker);
$posEnd = strpos($content, $endMarker, $posStart);

if ($posStart === false || $posEnd === false) {
    die("Markers not found");
}

$newLogic = <<<PHP
// 1.5. Importar Personal desde CSV
        if (\$action === 'import_csv') {
            if (!isset(\$_FILES['archivo_csv']) || \$_FILES['archivo_csv']['error'] !== UPLOAD_ERR_OK) {
                \$_SESSION['flash_error'] = "Debe seleccionar un archivo válido.";
                header('Location: index.php');
                exit;
            }

            \$tmpPath = \$_FILES['archivo_csv']['tmp_name'];
            \$handle = fopen(\$tmpPath, 'r');

            if (!\$handle) {
                \$_SESSION['flash_error'] = "No se pudo abrir el archivo subido.";
                header('Location: index.php');
                exit;
            }

            \$firstLine = fgets(\$handle);
            rewind(\$handle);
            \$delimiter = (substr_count(\$firstLine, ';') > substr_count(\$firstLine, ',')) ? ';' : ',';

            \$rawHeader = null;
            while ((\$rowTmp = fgetcsv(\$handle, 0, \$delimiter)) !== FALSE) {
                if (empty(\$rowTmp) || (count(\$rowTmp) === 1 && trim(\$rowTmp[0]) === '')) continue;
                \$firstCell = trim(preg_replace('/\\xEF\\xBB\\xBF/', '', \$rowTmp[0] ?? ''));
                if (strpos(\$firstCell, '#') === 0) continue;
                \$rowTmp[0] = \$firstCell;
                \$rawHeader = \$rowTmp;
                break;
            }

            if (!\$rawHeader) {
                fclose(\$handle);
                \$_SESSION['flash_error'] = "El archivo está vacío o no contiene encabezados válidos.";
                header('Location: index.php');
                exit;
            }

            \$header = array_map(function(\$col) {
                return strtolower(trim(str_replace(' ', '_', \$col)));
            }, \$rawHeader);

            \$colIdx = array_flip(\$header);

            if (!isset(\$colIdx['colaborador'])) {
                fclose(\$handle);
                \$_SESSION['flash_error'] = "Falta la columna requerida 'Colaborador'.";
                header('Location: index.php');
                exit;
            }

            \$db->beginTransaction();

            \$exitos = 0;
            \$errores = 0;

            while ((\$data = fgetcsv(\$handle, 0, \$delimiter)) !== FALSE) {
                if (empty(\$data) || (count(\$data) === 1 && trim(\$data[0]) === '')) continue;
                if (isset(\$data[0]) && strpos(trim(\$data[0]), '#') === 0) continue;

                \$nombre = trim(\$data[\$colIdx['colaborador']] ?? '');
                if (empty(\$nombre)) {
                    \$errores++;
                    continue;
                }

                \$area = isset(\$colIdx['area']) ? trim(\$data[\$colIdx['area']] ?? '') : null;
                \$cargo = isset(\$colIdx['cargo']) ? trim(\$data[\$colIdx['cargo']] ?? '') : null;
                \$empresa = isset(\$colIdx['empresa']) ? trim(\$data[\$colIdx['empresa']] ?? '') : 'Empresa Por Defecto';
                \$sucursal = isset(\$colIdx['sucursal']) ? trim(\$data[\$colIdx['sucursal']] ?? '') : 'Sucursal Principal';
                
                \$correo = isset(\$colIdx['correo']) ? trim(\$data[\$colIdx['correo']] ?? '') : null;
                \$passCorreo = isset(\$colIdx['pass_correo']) ? trim(\$data[\$colIdx['pass_correo']] ?? '') : null;
                \$pcPin = isset(\$colIdx['pc_pin']) ? trim(\$data[\$colIdx['pc_pin']] ?? '') : null;
                \$passAg = isset(\$colIdx['pass_ag']) ? trim(\$data[\$colIdx['pass_ag']] ?? '') : null;
                // Soportar "correo_gmail" o "correo_gmai" que viene en la captura
                \$correoGmail = isset(\$colIdx['correo_gmail']) ? trim(\$data[\$colIdx['correo_gmail']] ?? '') : (isset(\$colIdx['correo_gmai']) ? trim(\$data[\$colIdx['correo_gmai']] ?? '') : null);
                \$passGmail = isset(\$colIdx['pass_gmail']) ? trim(\$data[\$colIdx['pass_gmail']] ?? '') : null;
                
                \$estado = isset(\$colIdx['estado']) ? trim(\$data[\$colIdx['estado']] ?? '') : 'Activo';
                \$estadoNormalizado = (strcasecmp(\$estado, 'Inactivo') === 0) ? 'Inactivo' : 'Activo';

                // Obtener o Crear Empresa
                \$stmt = \$db->prepare("SELECT id FROM empresas WHERE nombre = ?");
                \$stmt->execute([\$empresa]);
                \$empresaId = \$stmt->fetchColumn();
                
                if (!\$empresaId) {
                    \$stmtIns = \$db->prepare("INSERT INTO empresas (nombre) VALUES (?)");
                    \$stmtIns->execute([\$empresa]);
                    \$empresaId = \$db->lastInsertId();
                }

                // Obtener o Crear Sucursal
                \$stmt = \$db->prepare("SELECT id FROM sucursales WHERE nombre = ? AND empresa_id = ?");
                \$stmt->execute([\$sucursal, \$empresaId]);
                \$sucursalId = \$stmt->fetchColumn();
                
                if (!\$sucursalId) {
                    \$stmtIns = \$db->prepare("INSERT INTO sucursales (empresa_id, nombre) VALUES (?, ?)");
                    \$stmtIns->execute([\$empresaId, \$sucursal]);
                    \$sucursalId = \$db->lastInsertId();
                }

                // Cifrar contraseñas
                \$passCorreoCifrado = !empty(\$passCorreo) ? Security::encrypt(\$passCorreo) : null;
                \$passAgCifrado = !empty(\$passAg) ? Security::encrypt(\$passAg) : null;
                \$passGmailCifrado = !empty(\$passGmail) ? Security::encrypt(\$passGmail) : null;

                // Insertar Empleado
                \$stmtInsert = \$db->prepare("
                    INSERT INTO empleados 
                    (sucursal_id, nombre, cargo, departamento, correo, pass_correo, pc_pin, pass_ag, correo_gmail, pass_gmail, estado) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                \$stmtInsert->execute([
                    \$sucursalId, \$nombre, \$cargo, \$area, \$correo, \$passCorreoCifrado, \$pcPin, \$passAgCifrado, \$correoGmail, \$passGmailCifrado, \$estadoNormalizado
                ]);

                \$exitos++;
            }

            \$db->commit();
            fclose(\$handle);

            \$msg = "Importación completada: \$exitos colaboradores registrados exitosamente.";
            if (\$errores > 0) {
                \$msg .= " \$errores filas ignoradas por faltar el nombre.";
            }

            \$_SESSION['flash_success'] = \$msg;
            \$redirectUrl = (isset(\$_GET['redirect']) && \$_GET['redirect'] === 'config') ? '../configuracion/index.php' : 'index.php';
            header('Location: ' . \$redirectUrl);
            exit;
        }
    
PHP;

$newContent = substr($content, 0, $posStart) . $newLogic . "    " . substr($content, $posEnd);
file_put_contents($file, $newContent);
echo "Empleados import logic updated.\n";
?>
