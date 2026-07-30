<?php
/**
 * Controlador de Guardado para Módulo de Equipos
 * Procesa altas, bajas, asignaciones y devoluciones
 */

session_start();
require_once __DIR__ . '/../../config/conexion.php';

// Validar sesión activa
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}

$db = Database::getConexion();
$action = $_GET['action'] ?? '';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        
        // 1. Agregar Nuevo Equipo
        if ($action === 'add_eq') {
            $tipo = $_POST['tipo'];
            if ($tipo === '__otro__') {
                $tipo = trim($_POST['tipo_otro'] ?? 'Laptop');
            }
            if (empty($tipo)) $tipo = 'Laptop';
            $sucursal_id = (int)$_POST['sucursal_id'];
            $marca = trim($_POST['marca']);
            $modelo = trim($_POST['modelo']);
            $service_tag_sn = trim($_POST['service_tag_sn']);
            $ip_direccion = trim($_POST['ip_direccion'] ?? '') ?: null;
            $notas = trim($_POST['notas'] ?? '') ?: null;
            
            $proveedor_origen = trim($_POST['proveedor_origen'] ?? '');

            $db->beginTransaction();

            $stmt = $db->prepare("
                INSERT INTO equipos (sucursal_id, tipo, marca, modelo, service_tag_sn, ip_direccion, estado, notas, registrado_por, proveedor_origen) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$sucursal_id, $tipo, $marca, $modelo, $service_tag_sn, $ip_direccion, $estado, $notas, $_SESSION['user_nombre'] ?? 'Sistema', $proveedor_origen]);
            $equipo_id = $db->lastInsertId();

            $db->commit();

            $_SESSION['flash_success'] = "Equipo registrado correctamente.";
            header('Location: index.php');
            exit;
        }

        // 1.5. Importar Equipos desde CSV
        if ($action === 'import_csv') {
            if (!isset($_FILES['archivo_csv']) || $_FILES['archivo_csv']['error'] !== UPLOAD_ERR_OK) {
                $_SESSION['flash_error'] = "Debe seleccionar un archivo válido.";
                header('Location: index.php');
                exit;
            }

            $tmpPath = $_FILES['archivo_csv']['tmp_name'];
            $handle = fopen($tmpPath, 'r');

            if (!$handle) {
                $_SESSION['flash_error'] = "No se pudo abrir el archivo subido.";
                header('Location: index.php');
                exit;
            }

            $firstLine = fgets($handle);
            rewind($handle);
            $delimiter = (substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';

            $rawHeader = null;
            while (($rowTmp = fgetcsv($handle, 0, $delimiter)) !== FALSE) {
                if (empty($rowTmp) || (count($rowTmp) === 1 && trim($rowTmp[0]) === '')) continue;
                $firstCell = trim(preg_replace('/\xEF\xBB\xBF/', '', $rowTmp[0] ?? ''));
                if (strpos($firstCell, '#') === 0) continue;
                $rowTmp[0] = $firstCell;
                $rawHeader = $rowTmp;
                break;
            }

            if (!$rawHeader) {
                fclose($handle);
                $_SESSION['flash_error'] = "El archivo está vacío o no contiene encabezados válidos.";
                header('Location: index.php');
                exit;
            }

            $header = array_map(function($col) {
                return strtolower(trim(str_replace(' ', '_', $col)));
            }, $rawHeader);

            $colIdx = array_flip($header);

            if (!isset($colIdx['serie'])) {
                fclose($handle);
                $_SESSION['flash_error'] = "Falta la columna requerida 'Serie'.";
                header('Location: index.php');
                exit;
            }

            $tiposPermitidos = ['Laptop', 'Desktop', 'Servidor', 'Maquina Virtual', 'Switch', 'Firewall', 'Access Point'];
            
            // Obtener sucursal por defecto
            $stmtFirstSuc = $db->query("SELECT id FROM sucursales LIMIT 1");
            $defaultSucursalId = $stmtFirstSuc->fetchColumn() ?: 1;

            $db->beginTransaction();

            $exitos = 0;
            $duplicados = 0;
            $errores = 0;
            $asignados = 0;

            $stmtCheckTag = $db->prepare("SELECT id FROM equipos WHERE service_tag_sn = ?");
            $stmtInsertEq = $db->prepare("
                INSERT INTO equipos (sucursal_id, tipo, marca, modelo, service_tag_sn, procesador, disco, ram, estado, registrado_por) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            while (($data = fgetcsv($handle, 0, $delimiter)) !== FALSE) {
                if (empty($data) || (count($data) === 1 && trim($data[0]) === '')) continue;
                if (isset($data[0]) && strpos(trim($data[0]), '#') === 0) continue;

                $colaboradorVal = trim($data[$colIdx['colaborador']] ?? '');
                $tipoVal = trim($data[$colIdx['equipo']] ?? '');
                $marcaVal = trim($data[$colIdx['marca']] ?? '');
                $modeloVal = trim($data[$colIdx['modelo']] ?? '');
                $tagVal = trim($data[$colIdx['serie']] ?? '');
                $procVal = isset($colIdx['procesador']) ? trim($data[$colIdx['procesador']] ?? '') : null;
                $discoVal = isset($colIdx['disco']) ? trim($data[$colIdx['disco']] ?? '') : null;
                $ramVal = isset($colIdx['ram']) ? trim($data[$colIdx['ram']] ?? '') : null;

                if (empty($tagVal) || empty($marcaVal) || empty($modeloVal)) {
                    $errores++;
                    continue;
                }

                // Normalizar Tipo
                $tipoNormalizado = 'Laptop';
                foreach ($tiposPermitidos as $t) {
                    if (strcasecmp($t, $tipoVal) === 0) {
                        $tipoNormalizado = $t;
                        break;
                    }
                }

                // Si hay colaborador, el estado es Asignado, si no Disponible
                $estadoNormalizado = !empty($colaboradorVal) ? 'Asignado' : 'Disponible';

                $stmtCheckTag->execute([$tagVal]);
                $equipoId = $stmtCheckTag->fetchColumn();
                
                if ($equipoId) {
                    $duplicados++;
                    // Ya existe, podríamos actualizarlo o solo omitirlo. Lo omitimos.
                    continue;
                }

                 $stmtInsertEq->execute([
                    $defaultSucursalId,
                    $tipoNormalizado,
                    $marcaVal,
                    $modeloVal,
                    $tagVal,
                    $procVal,
                    $discoVal,
                    $ramVal,
                    $estadoNormalizado,
                    $_SESSION['user_nombre'] ?? 'Sistema / CSV'
                ]);
                $equipoId = $db->lastInsertId();
                $exitos++;

                // Buscar empleado por nombre y crear asignación
                if (!empty($colaboradorVal)) {
                    $stmtEmp = $db->prepare("SELECT id FROM empleados WHERE nombre = ? LIMIT 1");
                    $stmtEmp->execute([$colaboradorVal]);
                    $empId = $stmtEmp->fetchColumn();

                    if ($empId) {
                        $stmtAsig = $db->prepare("INSERT INTO asignaciones (equipo_id, empleado_id, fecha_entrega, condicion_entrega, asignado_por) VALUES (?, ?, ?, 'Buen Estado', ?)");
                        $stmtAsig->execute([$equipoId, $empId, date('Y-m-d'), $_SESSION['user_nombre'] ?? 'Sistema / CSV']);
                        $asignados++;
                    } else {
                        // Si no existe el colaborador, devolvemos el estado a Disponible
                        $db->prepare("UPDATE equipos SET estado = 'Disponible' WHERE id = ?")->execute([$equipoId]);
                    }
                }
            }

            $db->commit();
            fclose($handle);

            $msg = "Importación completada: $exitos equipos agregados exitosamente.";
            if ($asignados > 0) $msg .= " $asignados auto-asignados a colaboradores.";
            if ($duplicados > 0) $msg .= " $duplicados omitidos (Serie ya existente).";
            if ($errores > 0) $msg .= " $errores filas ignoradas por faltar Serie, Marca o Modelo.";

            $_SESSION['flash_success'] = $msg;
            $redirectUrl = (isset($_GET['redirect']) && $_GET['redirect'] === 'config') ? '../configuracion/index.php' : 'index.php';
            header('Location: ' . $redirectUrl);
            exit;
        }

                // 2. Asignar Equipo a Empleado
        if ($action === 'assign') {
            $equipo_id = (int)$_POST['equipo_id'];
            $empleado_id = (int)$_POST['empleado_id'];
            $fecha_entrega = $_POST['fecha_entrega'];
            $condicion_entrega = trim($_POST['condicion_entrega']);

            // Iniciar Transacción para asegurar consistencia
            $db->beginTransaction();

            // VERIFICACIÓN CRÍTICA: Asegurarse de que el equipo esté "Disponible"
            $stmt_check = $db->prepare("SELECT estado FROM equipos WHERE id = ? FOR UPDATE");
            $stmt_check->execute([$equipo_id]);
            $estado_actual = $stmt_check->fetchColumn();

            if ($estado_actual !== 'Disponible') {
                $db->rollBack();
                $_SESSION['flash_error'] = "Error: El equipo ya se encuentra asignado o no está disponible.";
                header('Location: index.php');
                exit;
            }

            $asignado_por_val = $_SESSION['user_nombre'] ?? 'Sistema';
            $entregado_a_val = trim($_POST['entregado_a'] ?? '');

            // Insertar asignación
            $stmt_asig = $db->prepare("
                INSERT INTO asignaciones (equipo_id, empleado_id, fecha_entrega, condicion_entrega, asignado_por, entregado_a) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt_asig->execute([$equipo_id, $empleado_id, $fecha_entrega, $condicion_entrega, $asignado_por_val, $entregado_a_val]);
            
            $asig_id = $db->lastInsertId();

            // Actualizar estado del equipo a 'Asignado'
            $stmt_eq = $db->prepare("UPDATE equipos SET estado = 'Asignado' WHERE id = ?");
            $stmt_eq->execute([$equipo_id]);

            $db->commit();
            
            // Redirigir a la vista de impresión con el ID de la asignación generada
            header('Location: imprimir_asignacion.php?id=' . $asig_id);
            exit;
        }

        // 3. Devolver Equipo / Registrar Retorno
        if ($action === 'return') {
            $equipo_id = (int)$_POST['equipo_id'];
            $asignacion_id = (int)$_POST['asignacion_id'];
            $fecha_devolucion = $_POST['fecha_devolucion'];
            $condicion_devolucion = trim($_POST['condicion_devolucion']);

            $db->beginTransaction();

            $devuelto_por_val = !empty(trim($_POST['devuelto_por'])) ? trim($_POST['devuelto_por']) : 'No especificado';
            $recibido_por_val = $_SESSION['user_nombre'] ?? 'Sistema';

            // Actualizar la asignación activa agregando la fecha de devolución y la nota de retorno
            $stmt_asig = $db->prepare("
                UPDATE asignaciones 
                SET fecha_devolucion = ?, 
                    condicion_devolucion = ?,
                    devuelto_por = ?,
                    recibido_por = ?
                WHERE id = ?
            ");
            $stmt_asig->execute([$fecha_devolucion, $condicion_devolucion, $devuelto_por_val, $recibido_por_val, $asignacion_id]);

            // Regresar el equipo a 'Disponible'
            $stmt_eq = $db->prepare("UPDATE equipos SET estado = 'Disponible' WHERE id = ?");
            $stmt_eq->execute([$equipo_id]);

            $db->commit();
            header('Location: index.php');
            exit;
        }
    } else {
        // Acciones GET
        
        // 4. Dar de Baja un Equipo (Descomisionar)
        if ($action === 'baja') {
            $id = (int)$_GET['id'];
            
            $db->beginTransaction();
            
            // Si estaba asignado, cerrar la asignación pendiente primero
            $stmt_close = $db->prepare("
                UPDATE asignaciones 
                SET fecha_devolucion = CURDATE(), 
                    condicion_devolucion = 'Cierre por Baja',
                    recibido_por = ?
                WHERE equipo_id = ? AND fecha_devolucion IS NULL
            ");
            $stmt_close->execute([$_SESSION['user_nombre'] ?? 'Sistema / Baja', $id]);

            // Marcar equipo como de baja
            $stmt_eq = $db->prepare("UPDATE equipos SET estado = 'De Baja' WHERE id = ?");
            $stmt_eq->execute([$id]);

            $db->commit();
            header('Location: index.php');
            exit;
        }
    }
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    die("Error procesando inventario: " . $e->getMessage());
}
