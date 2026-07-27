<?php
$file = 'c:/xampp/htdocs/CuentasIT/modules/equipos/guardar.php';
$content = file_get_contents($file);

$startMarker = "// 1.5. Importar Equipos desde CSV";
$endMarker = "// 2. Asignar Equipo a Empleado";

$posStart = strpos($content, $startMarker);
$posEnd = strpos($content, $endMarker, $posStart);

if ($posStart === false || $posEnd === false) {
    die("Markers not found");
}

$newLogic = <<<PHP
// 1.5. Importar Equipos desde CSV
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

            if (!isset(\$colIdx['serie'])) {
                fclose(\$handle);
                \$_SESSION['flash_error'] = "Falta la columna requerida 'Serie'.";
                header('Location: index.php');
                exit;
            }

            \$tiposPermitidos = ['Laptop', 'Desktop', 'Servidor', 'Maquina Virtual', 'Switch', 'Firewall', 'Access Point'];
            
            // Obtener sucursal por defecto
            \$stmtFirstSuc = \$db->query("SELECT id FROM sucursales LIMIT 1");
            \$defaultSucursalId = \$stmtFirstSuc->fetchColumn() ?: 1;

            \$db->beginTransaction();

            \$exitos = 0;
            \$duplicados = 0;
            \$errores = 0;
            \$asignados = 0;

            \$stmtCheckTag = \$db->prepare("SELECT id FROM equipos WHERE service_tag_sn = ?");
            \$stmtInsertEq = \$db->prepare("
                INSERT INTO equipos (sucursal_id, tipo, marca, modelo, service_tag_sn, procesador, disco, ram, estado) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            while ((\$data = fgetcsv(\$handle, 0, \$delimiter)) !== FALSE) {
                if (empty(\$data) || (count(\$data) === 1 && trim(\$data[0]) === '')) continue;
                if (isset(\$data[0]) && strpos(trim(\$data[0]), '#') === 0) continue;

                \$colaboradorVal = trim(\$data[\$colIdx['colaborador']] ?? '');
                \$tipoVal = trim(\$data[\$colIdx['equipo']] ?? '');
                \$marcaVal = trim(\$data[\$colIdx['marca']] ?? '');
                \$modeloVal = trim(\$data[\$colIdx['modelo']] ?? '');
                \$tagVal = trim(\$data[\$colIdx['serie']] ?? '');
                \$procVal = isset(\$colIdx['procesador']) ? trim(\$data[\$colIdx['procesador']] ?? '') : null;
                \$discoVal = isset(\$colIdx['disco']) ? trim(\$data[\$colIdx['disco']] ?? '') : null;
                \$ramVal = isset(\$colIdx['ram']) ? trim(\$data[\$colIdx['ram']] ?? '') : null;

                if (empty(\$tagVal) || empty(\$marcaVal) || empty(\$modeloVal)) {
                    \$errores++;
                    continue;
                }

                // Normalizar Tipo
                \$tipoNormalizado = 'Laptop';
                foreach (\$tiposPermitidos as \$t) {
                    if (strcasecmp(\$t, \$tipoVal) === 0) {
                        \$tipoNormalizado = \$t;
                        break;
                    }
                }

                // Si hay colaborador, el estado es Asignado, si no Disponible
                \$estadoNormalizado = !empty(\$colaboradorVal) ? 'Asignado' : 'Disponible';

                \$stmtCheckTag->execute([\$tagVal]);
                \$equipoId = \$stmtCheckTag->fetchColumn();
                
                if (\$equipoId) {
                    \$duplicados++;
                    // Ya existe, podríamos actualizarlo o solo omitirlo. Lo omitimos.
                    continue;
                }

                \$stmtInsertEq->execute([
                    \$defaultSucursalId,
                    \$tipoNormalizado,
                    \$marcaVal,
                    \$modeloVal,
                    \$tagVal,
                    \$procVal,
                    \$discoVal,
                    \$ramVal,
                    \$estadoNormalizado
                ]);
                \$equipoId = \$db->lastInsertId();
                \$exitos++;

                // Buscar empleado por nombre y crear asignación
                if (!empty(\$colaboradorVal)) {
                    \$stmtEmp = \$db->prepare("SELECT id FROM empleados WHERE nombre = ? LIMIT 1");
                    \$stmtEmp->execute([\$colaboradorVal]);
                    \$empId = \$stmtEmp->fetchColumn();

                    if (\$empId) {
                        \$stmtAsig = \$db->prepare("INSERT INTO asignaciones (equipo_id, empleado_id, fecha_entrega, condicion_entrega) VALUES (?, ?, ?, 'Buen Estado')");
                        \$stmtAsig->execute([\$equipoId, \$empId, date('Y-m-d')]);
                        \$asignados++;
                    } else {
                        // Si no existe el colaborador, devolvemos el estado a Disponible
                        \$db->prepare("UPDATE equipos SET estado = 'Disponible' WHERE id = ?")->execute([\$equipoId]);
                    }
                }
            }

            \$db->commit();
            fclose(\$handle);

            \$msg = "Importación completada: \$exitos equipos agregados exitosamente.";
            if (\$asignados > 0) \$msg .= " \$asignados auto-asignados a colaboradores.";
            if (\$duplicados > 0) \$msg .= " \$duplicados omitidos (Serie ya existente).";
            if (\$errores > 0) \$msg .= " \$errores filas ignoradas por faltar Serie, Marca o Modelo.";

            \$_SESSION['flash_success'] = \$msg;
            \$redirectUrl = (isset(\$_GET['redirect']) && \$_GET['redirect'] === 'config') ? '../configuracion/index.php' : 'index.php';
            header('Location: ' . \$redirectUrl);
            exit;
        }

        
PHP;

$newContent = substr($content, 0, $posStart) . $newLogic . "        " . substr($content, $posEnd);
file_put_contents($file, $newContent);
echo "Equipos import logic updated.\n";
?>
