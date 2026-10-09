<?php
/**
 * Controlador de Guardado para Módulo de Empleados
 * Realiza el alta consolidada en transacciones y procesa bajas
 */

session_start();
require_once __DIR__ . '/../../config/conexion.php';
require_once __DIR__ . '/../../config/security.php';
require_once __DIR__ . '/../../config/helpers.php';

// Validar sesión activa
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}

$db = Database::getConexion();
$action = $_GET['action'] ?? '';


try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        
        // 1. Procesar Alta Consolidada (Onboarding)
        if ($action === 'onboard') {
            $nombre      = trim($_POST['nombre']);
            $sucursal_id_raw = $_POST['sucursal_id'];

            // Si el usuario eligió crear nueva sucursal
            if ($sucursal_id_raw === 'NEW_SUC') {
                $nueva_suc_nombre = trim($_POST['nueva_sucursal_nombre'] ?? '');
                $nueva_suc_empresa = (int)($_POST['nueva_sucursal_empresa'] ?? 1);
                if (empty($nueva_suc_nombre)) {
                    throw new Exception('Debe ingresar el nombre de la nueva sucursal.');
                }
                $stmtNewSuc = $db->prepare("INSERT INTO sucursales (empresa_id, nombre, ubicacion) VALUES (?, ?, '')");
                $stmtNewSuc->execute([$nueva_suc_empresa, $nueva_suc_nombre]);
                $sucursal_id = (int)$db->lastInsertId();
            } else {
                $sucursal_id = (int)$sucursal_id_raw;
            }
            $cargo       = trim($_POST['cargo']);
            $departamento = trim($_POST['departamento']);

            // Credenciales personales (opcionales)
            $correo_gmail    = trim($_POST['correo_gmail'] ?? '');
            $pass_gmail_raw  = $_POST['pass_gmail'] ?? '';
            $correo_corp     = trim($_POST['correo'] ?? '');
            $pass_correo_raw = $_POST['pass_correo'] ?? '';
            $pc_pin_raw      = $_POST['pc_pin'] ?? '';
            $pass_ag_raw     = $_POST['pass_ag'] ?? '';

            // Cifrar campos sensibles
            $pass_gmail_enc  = !empty($pass_gmail_raw)  ? Security::encrypt($pass_gmail_raw)  : null;
            $pass_correo_enc = !empty($pass_correo_raw) ? Security::encrypt($pass_correo_raw) : null;
            $pc_pin_enc      = !empty($pc_pin_raw)      ? Security::encrypt($pc_pin_raw)      : null;
            $pass_ag_enc     = !empty($pass_ag_raw)     ? Security::encrypt($pass_ag_raw)     : null;

            $vincular_equipo = isset($_POST['vincular_equipo']) ? 1 : 0;
            $vincular_m365   = isset($_POST['vincular_m365'])   ? 1 : 0;

            // Iniciar Transacción Atómica
            $db->beginTransaction();

            // A. Insertar el Empleado
            $stmt_emp = $db->prepare("
                INSERT INTO empleados (sucursal_id, nombre, cargo, departamento, estado)
                VALUES (?, ?, ?, ?, 'Activo')
            ");
            $stmt_emp->execute([$sucursal_id, $nombre, $cargo, $departamento]);
            $empleado_id = $db->lastInsertId();

            // Insertar credenciales personales en la nueva tabla
            $stmt_cred = $db->prepare("INSERT INTO credenciales_personales (empleado_id, tipo, usuario, password_cifrado) VALUES (?, ?, ?, ?)");
            
            if (!empty($correo_gmail) || !empty($pass_gmail_enc)) {
                $stmt_cred->execute([$empleado_id, 'Gmail', $correo_gmail, $pass_gmail_enc]);
            }
            if (!empty($correo_corp) || !empty($pass_correo_enc)) {
                $stmt_cred->execute([$empleado_id, 'Correo Corporativo', $correo_corp, $pass_correo_enc]);
            }
            if (!empty($pc_pin_enc)) {
                $stmt_cred->execute([$empleado_id, 'PC PIN', null, $pc_pin_enc]);
            }
            if (!empty($pass_ag_enc)) {
                $stmt_cred->execute([$empleado_id, 'Pass AG', null, $pass_ag_enc]);
            }

            // B. Si se seleccionó Vincular Equipo
            if ($vincular_equipo && !empty($_POST['equipo_id'])) {
                $equipo_id = (int)$_POST['equipo_id'];
                $condicion_entrega = trim($_POST['condicion_entrega']);
                $fecha_entrega = date('Y-m-d');

                // Insertar asignación
                $stmt_asig = $db->prepare("
                    INSERT INTO asignaciones (equipo_id, empleado_id, fecha_entrega, condicion_entrega) 
                    VALUES (?, ?, ?, ?)
                ");
                $stmt_asig->execute([$equipo_id, $empleado_id, $fecha_entrega, $condicion_entrega]);

                // Cambiar equipo a 'Asignado'
                $stmt_eq = $db->prepare("UPDATE equipos SET estado = 'Asignado' WHERE id = ?");
                $stmt_eq->execute([$equipo_id]);
            }

            // C. Si se seleccionó Vincular M365
            if ($vincular_m365 && !empty($_POST['m365_correo'])) {
                $correo = trim($_POST['m365_correo']);
                $licencia_tipo = $_POST['m365_licencia'];
                $m365_password = $_POST['m365_password'] ?? '';
                $fecha_init = $_POST['m365_fecha_init'];
                $ciclo = $_POST['m365_ciclo'];

                // Cifrar contraseña de forma reversible (AES-256)
                $password_cifrado = !empty($m365_password) ? Security::encrypt($m365_password) : null;

                // Calcular fecha próxima de pago
                $fecha_proximo_pago = calcularProximoPago($fecha_init, $ciclo);

                // Insertar licencia
                $stmt_m365 = $db->prepare("
                    INSERT INTO cuentas_m365 (empleado_id, correo, licencia_tipo, password_cifrado, fecha_inicializacion, ciclo_facturacion, fecha_proximo_pago, estado) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'Activa')
                ");
                $stmt_m365->execute([$empleado_id, $correo, $licencia_tipo, $password_cifrado, $fecha_init, $ciclo, $fecha_proximo_pago]);
            }

            // Confirmar transacción
            $db->commit();

            $_SESSION['flash_success'] = "Onboarding de colaborador completado exitosamente.";
            header('Location: index.php');
            exit;
        }

        // 1.2 Editar Empleado
        if ($action === 'editar') {
            $id = (int)$_POST['id'];
            $nombre = trim($_POST['nombre']);
            $sucursal_id_raw = $_POST['sucursal_id'];

            // Si el usuario eligió crear nueva sucursal
            if ($sucursal_id_raw === 'NEW_SUC') {
                $nueva_suc_nombre = trim($_POST['nueva_sucursal_nombre'] ?? '');
                $nueva_suc_empresa = (int)($_POST['nueva_sucursal_empresa'] ?? 1);
                if (empty($nueva_suc_nombre)) {
                    throw new Exception('Debe ingresar el nombre de la nueva sucursal.');
                }
                $stmtNewSuc = $db->prepare("INSERT INTO sucursales (empresa_id, nombre, ubicacion) VALUES (?, ?, '')");
                $stmtNewSuc->execute([$nueva_suc_empresa, $nueva_suc_nombre]);
                $sucursal_id = (int)$db->lastInsertId();
            } else {
                $sucursal_id = (int)$sucursal_id_raw;
            }
            $cargo = trim($_POST['cargo']);
            $departamento = trim($_POST['departamento']);

            // Credenciales Personales
            $correo_gmail = trim($_POST['correo_gmail'] ?? '');
            $pass_gmail_raw = $_POST['pass_gmail'] ?? '';
            $pc_pin_raw = $_POST['pc_pin'] ?? '';
            $pass_ag_raw = $_POST['pass_ag'] ?? '';

            // Cifrar campos sensibles
            $pass_gmail_enc = !empty($pass_gmail_raw) ? Security::encrypt($pass_gmail_raw) : null;
            $pc_pin_enc = !empty($pc_pin_raw) ? Security::encrypt($pc_pin_raw) : null;
            $pass_ag_enc = !empty($pass_ag_raw) ? Security::encrypt($pass_ag_raw) : null;

                        $db->beginTransaction();

            // A. Actualizar empleados
            $stmt_up = $db->prepare("
                UPDATE empleados 
                SET sucursal_id = ?, nombre = ?, cargo = ?, departamento = ? 
                WHERE id = ?
            ");
            $stmt_up->execute([$sucursal_id, $nombre, $cargo, $departamento, $id]);

            // B. Actualizar Credenciales Personales

            // Gmail
            $stmtGmailCheck = $db->prepare("SELECT id FROM credenciales_personales WHERE empleado_id = ? AND tipo = 'Gmail' LIMIT 1");
            $stmtGmailCheck->execute([$id]);
            $gmailId = $stmtGmailCheck->fetchColumn();

            if ($gmailId) {
                $db->prepare("UPDATE credenciales_personales SET usuario = ?, password_cifrado = ? WHERE id = ?")
                   ->execute([$correo_gmail, $pass_gmail_enc, $gmailId]);
            } else {
                if (!empty($correo_gmail) || !empty($pass_gmail_enc)) {
                    $db->prepare("INSERT INTO credenciales_personales (empleado_id, tipo, usuario, password_cifrado) VALUES (?, 'Gmail', ?, ?)")
                       ->execute([$id, $correo_gmail, $pass_gmail_enc]);
                }
            }

            // PC PIN
            $stmtPinCheck = $db->prepare("SELECT id FROM credenciales_personales WHERE empleado_id = ? AND tipo = 'PC PIN' LIMIT 1");
            $stmtPinCheck->execute([$id]);
            $pinId = $stmtPinCheck->fetchColumn();

            if ($pinId) {
                $db->prepare("UPDATE credenciales_personales SET password_cifrado = ? WHERE id = ?")
                   ->execute([$pc_pin_enc, $pinId]);
            } else {
                if (!empty($pc_pin_enc)) {
                    $db->prepare("INSERT INTO credenciales_personales (empleado_id, tipo, password_cifrado) VALUES (?, 'PC PIN', ?)")
                       ->execute([$id, $pc_pin_enc]);
                }
            }

            // Pass AG
            $stmtAgCheck = $db->prepare("SELECT id FROM credenciales_personales WHERE empleado_id = ? AND tipo = 'Pass AG' LIMIT 1");
            $stmtAgCheck->execute([$id]);
            $agId = $stmtAgCheck->fetchColumn();

            if ($agId) {
                $db->prepare("UPDATE credenciales_personales SET password_cifrado = ? WHERE id = ?")
                   ->execute([$pass_ag_enc, $agId]);
            } else {
                if (!empty($pass_ag_enc)) {
                    $db->prepare("INSERT INTO credenciales_personales (empleado_id, tipo, password_cifrado) VALUES (?, 'Pass AG', ?)")
                       ->execute([$id, $pass_ag_enc]);
                }
            }

            // C. Manejo de la asignación del equipo

            // Desasignar marcados
            $desasignar_equipos = $_POST['desasignar_equipos'] ?? [];
            if (!empty($desasignar_equipos)) {
                foreach ($desasignar_equipos as $eq_id) {
                    $eq_id = (int)$eq_id;
                    // Cerrar asignación
                    $db->prepare("UPDATE asignaciones SET fecha_devolucion = CURRENT_DATE(), condicion_entrega = CONCAT(condicion_entrega, ' | Liberado en Edición Colaborador') WHERE empleado_id = ? AND equipo_id = ? AND fecha_devolucion IS NULL")->execute([$id, $eq_id]);
                    // Liberar equipo
                    $db->prepare("UPDATE equipos SET estado = 'Disponible' WHERE id = ?")->execute([$eq_id]);
                }
            }

            // Asignar nuevo si se seleccionó
            $nuevo_equipo_id = !empty($_POST['nuevo_equipo_id']) ? (int)$_POST['nuevo_equipo_id'] : null;
            if ($nuevo_equipo_id) {
                // Crear asignación
                $stmtAsigNew = $db->prepare("INSERT INTO asignaciones (equipo_id, empleado_id, fecha_entrega, condicion_entrega) VALUES (?, ?, CURRENT_DATE(), 'Buen Estado')");
                $stmtAsigNew->execute([$nuevo_equipo_id, $id]);
                // Marcar equipo como asignado
                $db->prepare("UPDATE equipos SET estado = 'Asignado' WHERE id = ?")->execute([$nuevo_equipo_id]);
            }

            $db->commit();

            $_SESSION['flash_success'] = "Información del colaborador y sus recursos vinculados actualizada correctamente.";
            header('Location: index.php');
            exit;
        }

        // 1.5. Importar Personal desde CSV
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

            if (!isset($colIdx['colaborador'])) {
                fclose($handle);
                $_SESSION['flash_error'] = "Falta la columna requerida 'Colaborador'.";
                header('Location: index.php');
                exit;
            }

            $db->beginTransaction();

            $exitos = 0;
            $errores = 0;

            while (($data = fgetcsv($handle, 0, $delimiter)) !== FALSE) {
                if (empty($data) || (count($data) === 1 && trim($data[0]) === '')) continue;
                $cell0 = trim($data[0] ?? '');
                if (strpos($cell0, '#') === 0 || strpos($cell0, '---') === 0 || strpos($cell0, '=>') === 0 || stripos($cell0, 'INSTRUCCIONES') !== false) continue;

                $nombre = trim($data[$colIdx['colaborador']] ?? '');
                if (empty($nombre) || strcasecmp($nombre, 'colaborador') === 0 || strpos($nombre, 'OBLIGATORIO') !== false || strpos($nombre, '=>') !== false) {
                    continue;
                }

                $area = isset($colIdx['area']) ? trim($data[$colIdx['area']] ?? '') : null;
                $cargo = isset($colIdx['cargo']) ? trim($data[$colIdx['cargo']] ?? '') : null;
                $empresa = isset($colIdx['empresa']) ? trim($data[$colIdx['empresa']] ?? '') : 'Empresa Por Defecto';
                $sucursal = isset($colIdx['sucursal']) ? trim($data[$colIdx['sucursal']] ?? '') : 'Sucursal Principal';
                
                $correo = isset($colIdx['correo']) ? trim($data[$colIdx['correo']] ?? '') : null;
                $passCorreo = isset($colIdx['pass_correo']) ? trim($data[$colIdx['pass_correo']] ?? '') : null;
                $pcPin = isset($colIdx['pc_pin']) ? trim($data[$colIdx['pc_pin']] ?? '') : null;
                $passAg = isset($colIdx['pass_ag']) ? trim($data[$colIdx['pass_ag']] ?? '') : null;
                // Soportar "correo_gmail" o "correo_gmai" que viene en la captura
                $correoGmail = isset($colIdx['correo_gmail']) ? trim($data[$colIdx['correo_gmail']] ?? '') : (isset($colIdx['correo_gmai']) ? trim($data[$colIdx['correo_gmai']] ?? '') : null);
                $passGmail = isset($colIdx['pass_gmail']) ? trim($data[$colIdx['pass_gmail']] ?? '') : null;
                
                $estado = isset($colIdx['estado']) ? trim($data[$colIdx['estado']] ?? '') : 'Activo';
                $estadoNormalizado = (strcasecmp($estado, 'Inactivo') === 0) ? 'Inactivo' : 'Activo';

                // Obtener o Crear Empresa
                $stmt = $db->prepare("SELECT id FROM empresas WHERE nombre = ?");
                $stmt->execute([$empresa]);
                $empresaId = $stmt->fetchColumn();
                
                if (!$empresaId) {
                    $stmtIns = $db->prepare("INSERT INTO empresas (nombre) VALUES (?)");
                    $stmtIns->execute([$empresa]);
                    $empresaId = $db->lastInsertId();
                }

                // Obtener o Crear Sucursal
                $stmt = $db->prepare("SELECT id FROM sucursales WHERE nombre = ? AND empresa_id = ?");
                $stmt->execute([$sucursal, $empresaId]);
                $sucursalId = $stmt->fetchColumn();
                
                if (!$sucursalId) {
                    $stmtIns = $db->prepare("INSERT INTO sucursales (empresa_id, nombre) VALUES (?, ?)");
                    $stmtIns->execute([$empresaId, $sucursal]);
                    $sucursalId = $db->lastInsertId();
                }

                // Cifrar contraseñas
                $passCorreoCifrado = !empty($passCorreo) ? Security::encrypt($passCorreo) : null;
                $passAgCifrado     = !empty($passAg)     ? Security::encrypt($passAg)     : null;
                $passGmailCifrado  = !empty($passGmail)  ? Security::encrypt($passGmail)  : null;
                $pcPinCifrado      = !empty($pcPin)      ? Security::encrypt($pcPin)       : null;

                // Insertar Empleado
                $stmtInsert = $db->prepare("
                    INSERT INTO empleados (sucursal_id, nombre, cargo, departamento, estado) 
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmtInsert->execute([
                    $sucursalId, $nombre, $cargo, $area, $estadoNormalizado
                ]);
                $empleadoId = $db->lastInsertId();

                // Insertar credenciales personales en la nueva tabla
                $stmt_cred = $db->prepare("INSERT INTO credenciales_personales (empleado_id, tipo, usuario, password_cifrado) VALUES (?, ?, ?, ?)");
                
                if (!empty($correoGmail) || !empty($passGmailCifrado)) {
                    $stmt_cred->execute([$empleadoId, 'Gmail', $correoGmail, $passGmailCifrado]);
                }
                if (!empty($correo) || !empty($passCorreoCifrado)) {
                    $stmt_cred->execute([$empleadoId, 'Correo Corporativo', $correo, $passCorreoCifrado]);

                    if (!empty($correo)) {
                        $licenciaTipo = 'Microsoft 365 Business Standard';
                        if (strpos(strtolower($area ?? ''), 'geren') !== false || strpos(strtolower($cargo ?? ''), 'geren') !== false || strpos(strtolower($nombre), 'geren') !== false) {
                            $licenciaTipo = 'Microsoft 365 Business Premium';
                        }
                        $fechaInit = date('Y-m-d');
                        $fechaPago = date('Y-m-d', strtotime('+1 month'));
                        $estadoM365 = ($estadoNormalizado === 'Inactivo') ? 'Suspendida' : 'Activa';

                        $stmtCheckM365 = $db->prepare("SELECT id FROM cuentas_m365 WHERE correo = ?");
                        $stmtCheckM365->execute([$correo]);
                        $existingM365Id = $stmtCheckM365->fetchColumn();

                        if ($existingM365Id) {
                            $stmtUpM365 = $db->prepare("
                                UPDATE cuentas_m365 
                                SET empleado_id = ?, password_cifrado = COALESCE(?, password_cifrado), estado = ?
                                WHERE id = ?
                            ");
                            $stmtUpM365->execute([$empleadoId, $passCorreoCifrado, $estadoM365, $existingM365Id]);
                        } else {
                            $stmtInsM365 = $db->prepare("
                                INSERT INTO cuentas_m365 (empleado_id, correo, licencia_tipo, password_cifrado, fecha_inicializacion, ciclo_facturacion, fecha_proximo_pago, estado)
                                VALUES (?, ?, ?, ?, ?, 'Mensual', ?, ?)
                            ");
                            $stmtInsM365->execute([$empleadoId, $correo, $licenciaTipo, $passCorreoCifrado, $fechaInit, $fechaPago, $estadoM365]);
                        }
                    }
                }
                if (!empty($pcPinCifrado)) {
                    $stmt_cred->execute([$empleadoId, 'PC PIN', null, $pcPinCifrado]);
                }
                if (!empty($passAgCifrado)) {
                    $stmt_cred->execute([$empleadoId, 'Pass AG', null, $passAgCifrado]);
                }

                $exitos++;
            }

            $db->commit();
            fclose($handle);

            $msg = "Importación completada: $exitos colaboradores registrados exitosamente.";
            if ($errores > 0) {
                $msg .= " $errores filas ignoradas por faltar el nombre.";
            }

            $_SESSION['flash_success'] = $msg;
            $redirectUrl = (isset($_GET['redirect']) && $_GET['redirect'] === 'config') ? '../configuracion/index.php' : 'index.php';
            header('Location: ' . $redirectUrl);
            exit;
        }

    } else {
        // Acciones GET
        
        // 2. Dar de baja a un empleado y liberar sus activos vinculados
        if ($action === 'baja') {
            $id = (int)$_GET['id'];

            $db->beginTransaction();

            // A. Liberar todos los equipos asignados actualmente a este empleado
            // Obtener asignaciones activas
            $stmt_asig_get = $db->prepare("SELECT id, equipo_id FROM asignaciones WHERE empleado_id = ? AND fecha_devolucion IS NULL");
            $stmt_asig_get->execute([$id]);
            $asigs_activas = $stmt_asig_get->fetchAll();

            foreach ($asigs_activas as $asig) {
                // Cerrar la asignación
                $stmt_asig_up = $db->prepare("
                    UPDATE asignaciones 
                    SET fecha_devolucion = CURDATE(), 
                        condicion_entrega = CONCAT(condicion_entrega, ' | Liberado por Baja de Colaborador') 
                    WHERE id = ?
                ");
                $stmt_asig_up->execute([$asig['id']]);

                // Regresar el equipo a 'Disponible'
                $stmt_eq_up = $db->prepare("UPDATE equipos SET estado = 'Disponible' WHERE id = ?");
                $stmt_eq_up->execute([$asig['equipo_id']]);
            }

            // B. Suspender cuentas de correo M365 ligadas a este usuario (por seguridad de facturación)
            $stmt_m365_up = $db->prepare("UPDATE cuentas_m365 SET estado = 'Suspendida' WHERE empleado_id = ?");
            $stmt_m365_up->execute([$id]);

            // C. Cambiar estado del empleado a Inactivo
            $stmt_emp_up = $db->prepare("UPDATE empleados SET estado = 'Inactivo' WHERE id = ?");
            $stmt_emp_up->execute([$id]);

            $db->commit();

            header('Location: index.php');
            exit;
        }
    }
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    die("Error procesando Onboarding de Colaborador: " . $e->getMessage());
}
