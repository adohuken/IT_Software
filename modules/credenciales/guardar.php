<?php
/**
 * Controlador de Guardado para Módulo de Credenciales
 * Procesa inserciones, eliminaciones y realiza cifrado de campos
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

        // 1. Agregar Credencial de Equipo
        if ($action === 'add_eq') {
            $equipo_id_raw = $_POST['equipo_id'] ?? '';
            $usuario_local = isset($_POST['usuario_local']) ? trim($_POST['usuario_local']) : '';
            $password_plano = $_POST['password'] ?? '';
            $bitlocker_plano = isset($_POST['clave_bitlocker']) ? trim($_POST['clave_bitlocker']) : '';
            $notas_acceso = isset($_POST['notas_acceso']) ? trim($_POST['notas_acceso']) : '';

            if ($equipo_id_raw === 'NEW_EQUIPO') {
                $tipo = trim($_POST['nuevo_eq_tipo'] ?? 'Laptop');
                if ($tipo === '__otro__') {
                    $tipo = trim($_POST['nuevo_eq_tipo_otro'] ?? 'Laptop');
                }
                if (empty($tipo)) $tipo = 'Laptop';
                $marca = trim($_POST['nuevo_eq_marca'] ?? '');
                $modelo = trim($_POST['nuevo_eq_modelo'] ?? '');
                $serie = trim($_POST['nuevo_eq_sn'] ?? '');
                $cpu = trim($_POST['nuevo_eq_cpu'] ?? '');
                $disco = trim($_POST['nuevo_eq_disco'] ?? '');
                $ram = trim($_POST['nuevo_eq_ram'] ?? '');
                $ip_direccion = trim($_POST['nuevo_eq_ip'] ?? '');
                
                $emp_id_raw = $_POST['nuevo_eq_empleado_id'] ?? '';
                $emp_id = null;
                if (!empty($emp_id_raw)) {
                    if (strpos($emp_id_raw, 'S_') === 0) {
                        $suc_id = (int) substr($emp_id_raw, 2);
                        // Obtener nombre de la sucursal
                        $stmtSucName = $db->prepare("SELECT nombre FROM sucursales WHERE id = ?");
                        $stmtSucName->execute([$suc_id]);
                        $suc_name = $stmtSucName->fetchColumn();
                        if ($suc_name) {
                            // Buscar o crear empleado para representar a la sucursal
                            $stmtEmpCheck = $db->prepare("SELECT id FROM empleados WHERE nombre = ? AND sucursal_id = ? LIMIT 1");
                            $stmtEmpCheck->execute([$suc_name, $suc_id]);
                            $emp_id = $stmtEmpCheck->fetchColumn();
                            if (!$emp_id) {
                                $stmtInsEmp = $db->prepare("INSERT INTO empleados (nombre, sucursal_id, cargo, departamento, estado) VALUES (?, ?, 'Sucursal', 'Infraestructura', 'Activo')");
                                $stmtInsEmp->execute([$suc_name, $suc_id]);
                                $emp_id = (int) $db->lastInsertId();
                            }
                        }
                    } elseif (strpos($emp_id_raw, 'E_') === 0) {
                        $emp_comp_id = (int) substr($emp_id_raw, 2);
                        // Obtener nombre de la empresa
                        $stmtEmpName = $db->prepare("SELECT nombre FROM empresas WHERE id = ?");
                        $stmtEmpName->execute([$emp_comp_id]);
                        $emp_name = $stmtEmpName->fetchColumn();
                        if ($emp_name) {
                            // Buscar o crear empleado para representar a la empresa
                            $stmtEmpCheck = $db->prepare("SELECT id FROM empleados WHERE nombre = ? AND cargo = 'Empresa' LIMIT 1");
                            $stmtEmpCheck->execute([$emp_name]);
                            $emp_id = $stmtEmpCheck->fetchColumn();
                            if (!$emp_id) {
                                $default_suc = (int)$db->query("SELECT id FROM sucursales LIMIT 1")->fetchColumn() ?: 1;
                                $stmtInsEmp = $db->prepare("INSERT INTO empleados (nombre, sucursal_id, cargo, departamento, estado) VALUES (?, ?, 'Empresa', 'Infraestructura', 'Activo')");
                                $stmtInsEmp->execute([$emp_name, $default_suc]);
                                $emp_id = (int) $db->lastInsertId();
                            }
                        }
                    } else {
                        $emp_id = (int) $emp_id_raw;
                    }
                }

                $eq_estado = $emp_id ? 'Asignado' : 'Disponible';
                $stmtNewEq = $db->prepare("
                    INSERT INTO equipos (sucursal_id, tipo, marca, modelo, service_tag_sn, ip_direccion, procesador, disco, ram, estado)
                    VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmtNewEq->execute([$tipo, $marca, $modelo, $serie, $ip_direccion ?: null, $cpu, $disco, $ram, $eq_estado]);
                $equipo_id = (int) $db->lastInsertId();

                if ($emp_id) {
                    $stmtAsig = $db->prepare("
                        INSERT INTO asignaciones (equipo_id, empleado_id, fecha_entrega, condicion_entrega)
                        VALUES (?, ?, NOW(), 'Buen Estado')
                    ");
                    $stmtAsig->execute([$equipo_id, $emp_id]);
                }
            } else {
                $equipo_id = (int) $equipo_id_raw;
            }

            // Cifrar contraseñas sensibles si no están vacías
            $password_cifrado = !empty($password_plano) ? Security::encrypt($password_plano) : '';
            $bitlocker_cifrado = !empty($bitlocker_plano) ? Security::encrypt($bitlocker_plano) : null;

            $stmt = $db->prepare("
                INSERT INTO credenciales_equipos (equipo_id, usuario_local, password_cifrado, clave_bitlocker, notas_acceso) 
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$equipo_id, $usuario_local, $password_cifrado, $bitlocker_cifrado, $notas_acceso]);

            $_SESSION['flash_success'] = "Credencial de equipo creada correctamente.";
            header('Location: index.php?tab=equipos');
            exit;
        }

        // 1.5. Editar Credencial y Equipo
        if ($action === 'edit_eq') {
            $id = (int) $_POST['id'];
            $equipo_id = (int) $_POST['equipo_id'];
            $usuario_local = isset($_POST['usuario_local']) ? trim($_POST['usuario_local']) : '';
            $password_plano = $_POST['password'] ?? '';
            $bitlocker_plano = isset($_POST['clave_bitlocker']) ? trim($_POST['clave_bitlocker']) : '';
            $notas_acceso = isset($_POST['notas_acceso']) ? trim($_POST['notas_acceso']) : '';

            $marca = trim($_POST['marca'] ?? '');
            $modelo = trim($_POST['modelo'] ?? '');
            $service_tag_sn = trim($_POST['service_tag_sn'] ?? '');
            $procesador = trim($_POST['procesador'] ?? '');
            $disco = trim($_POST['disco'] ?? '');
            $ram = trim($_POST['ram'] ?? '');
            $ip_direccion = trim($_POST['ip_direccion'] ?? '');
            
            $empleado_id_raw = $_POST['empleado_id'] ?? '';
            $empleado_id = null;
            if (!empty($empleado_id_raw)) {
                if (strpos($empleado_id_raw, 'S_') === 0) {
                    $suc_id = (int) substr($empleado_id_raw, 2);
                    // Obtener nombre de la sucursal
                    $stmtSucName = $db->prepare("SELECT nombre FROM sucursales WHERE id = ?");
                    $stmtSucName->execute([$suc_id]);
                    $suc_name = $stmtSucName->fetchColumn();
                    if ($suc_name) {
                        // Buscar o crear empleado
                        $stmtEmpCheck = $db->prepare("SELECT id FROM empleados WHERE nombre = ? AND sucursal_id = ? LIMIT 1");
                        $stmtEmpCheck->execute([$suc_name, $suc_id]);
                        $empleado_id = $stmtEmpCheck->fetchColumn();
                        if (!$empleado_id) {
                            $stmtInsEmp = $db->prepare("INSERT INTO empleados (nombre, sucursal_id, cargo, departamento, estado) VALUES (?, ?, 'Sucursal', 'Infraestructura', 'Activo')");
                            $stmtInsEmp->execute([$suc_name, $suc_id]);
                            $empleado_id = (int) $db->lastInsertId();
                        }
                    }
                } elseif (strpos($empleado_id_raw, 'E_') === 0) {
                    $emp_comp_id = (int) substr($empleado_id_raw, 2);
                    // Obtener nombre de la empresa
                    $stmtEmpName = $db->prepare("SELECT nombre FROM empresas WHERE id = ?");
                    $stmtEmpName->execute([$emp_comp_id]);
                    $emp_name = $stmtEmpName->fetchColumn();
                    if ($emp_name) {
                        // Buscar o crear empleado
                        $stmtEmpCheck = $db->prepare("SELECT id FROM empleados WHERE nombre = ? AND cargo = 'Empresa' LIMIT 1");
                        $stmtEmpCheck->execute([$emp_name]);
                        $empleado_id = $stmtEmpCheck->fetchColumn();
                        if (!$empleado_id) {
                            $default_suc = (int)$db->query("SELECT id FROM sucursales LIMIT 1")->fetchColumn() ?: 1;
                            $stmtInsEmp = $db->prepare("INSERT INTO empleados (nombre, sucursal_id, cargo, departamento, estado) VALUES (?, ?, 'Empresa', 'Infraestructura', 'Activo')");
                            $stmtInsEmp->execute([$emp_name, $default_suc]);
                            $empleado_id = (int) $db->lastInsertId();
                        }
                    }
                } else {
                    $empleado_id = (int) $empleado_id_raw;
                }
            }

            $db->beginTransaction();

            // Actualizar tabla equipos
            $stmtEq = $db->prepare("
                UPDATE equipos 
                SET marca = ?, modelo = ?, service_tag_sn = ?, procesador = ?, disco = ?, ram = ?, ip_direccion = ? 
                WHERE id = ?
            ");
            $stmtEq->execute([$marca, $modelo, $service_tag_sn, $procesador, $disco, $ram, $ip_direccion, $equipo_id]);

            // Manejo de la asignación del colaborador
            $stmtAsigCheck = $db->prepare("SELECT * FROM asignaciones WHERE equipo_id = ? AND fecha_devolucion IS NULL LIMIT 1");
            $stmtAsigCheck->execute([$equipo_id]);
            $activeAsig = $stmtAsigCheck->fetch();

            if ($activeAsig) {
                if ((int)$activeAsig['empleado_id'] !== $empleado_id) {
                    // Cerrar asignación anterior
                    $stmtAsigClose = $db->prepare("UPDATE asignaciones SET fecha_devolucion = CURRENT_DATE(), condicion_entrega = CONCAT(condicion_entrega, ' | Liberado en Edición') WHERE id = ?");
                    $stmtAsigClose->execute([$activeAsig['id']]);

                    if ($empleado_id) {
                        // Crear nueva asignación
                        $stmtAsigNew = $db->prepare("INSERT INTO asignaciones (equipo_id, empleado_id, fecha_entrega, condicion_entrega) VALUES (?, ?, CURRENT_DATE(), 'Buen Estado')");
                        $stmtAsigNew->execute([$equipo_id, $empleado_id]);
                        $db->prepare("UPDATE equipos SET estado = 'Asignado' WHERE id = ?")->execute([$equipo_id]);
                    } else {
                        // Queda libre
                        $db->prepare("UPDATE equipos SET estado = 'Disponible' WHERE id = ?")->execute([$equipo_id]);
                    }
                }
            } else {
                if ($empleado_id) {
                    // Crear nueva asignación
                    $stmtAsigNew = $db->prepare("INSERT INTO asignaciones (equipo_id, empleado_id, fecha_entrega, condicion_entrega) VALUES (?, ?, CURRENT_DATE(), 'Buen Estado')");
                    $stmtAsigNew->execute([$equipo_id, $empleado_id]);
                    $db->prepare("UPDATE equipos SET estado = 'Asignado' WHERE id = ?")->execute([$equipo_id]);
                } else {
                    // Asegurar disponible
                    $db->prepare("UPDATE equipos SET estado = 'Disponible' WHERE id = ?")->execute([$equipo_id]);
                }
            }

            // Actualizar tabla credenciales_equipos
            if (!empty($password_plano)) {
                $password_cifrado = Security::encrypt($password_plano);
                $bitlocker_cifrado = !empty($bitlocker_plano) ? Security::encrypt($bitlocker_plano) : null;
                $stmtCred = $db->prepare("
                    UPDATE credenciales_equipos 
                    SET usuario_local = ?, password_cifrado = ?, clave_bitlocker = ?, notas_acceso = ? 
                    WHERE id = ?
                ");
                $stmtCred->execute([$usuario_local, $password_cifrado, $bitlocker_cifrado, $notas_acceso, $id]);
            } else {
                $bitlocker_cifrado = !empty($bitlocker_plano) ? Security::encrypt($bitlocker_plano) : null;
                $stmtCred = $db->prepare("
                    UPDATE credenciales_equipos 
                    SET usuario_local = ?, clave_bitlocker = ?, notas_acceso = ? 
                    WHERE id = ?
                ");
                $stmtCred->execute([$usuario_local, $bitlocker_cifrado, $notas_acceso, $id]);
            }

            $db->commit();
            $_SESSION['flash_success'] = "Credencial y detalles del equipo actualizados correctamente.";
            header('Location: index.php?tab=equipos');
            exit;
        }

        // 2. Agregar Credencial Web
        if ($action === 'add_web') {
            $plataforma = trim($_POST['plataforma']);
            $url_acceso = trim($_POST['url_acceso'] ?? '');
            $usuario = trim($_POST['usuario']);
            $password_plano = $_POST['password'];
            $notas = trim($_POST['notas'] ?? '');

            // Cifrar
            $password_cifrado = Security::encrypt($password_plano);

            $stmt = $db->prepare("
                INSERT INTO credenciales_web (plataforma, url_acceso, usuario, password_cifrado, notas) 
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$plataforma, $url_acceso, $usuario, $password_cifrado, $notas]);

            header('Location: index.php?tab=web');
            exit;
        }

        // 3. Agregar Cuenta M365
        if ($action === 'add_m365') {
            $correo = trim($_POST['correo']);
            $licencia_tipo = trim($_POST['licencia_tipo']);
            $empleado_id = (!empty($_POST['empleado_id']) && $_POST['empleado_id'] !== 'NEW_EMP') ? (int) $_POST['empleado_id'] : null;
            $nuevo_empleado_nombre = trim($_POST['nuevo_empleado_nombre'] ?? '');

            // Si ingresó un nombre nuevo de empleado
            if (!empty($nuevo_empleado_nombre)) {
                $nuevo_empleado_nombre = mb_convert_case(mb_strtolower($nuevo_empleado_nombre, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
                $stmtCheck = $db->prepare("SELECT id FROM empleados WHERE LOWER(TRIM(nombre)) = LOWER(TRIM(?)) LIMIT 1");
                $stmtCheck->execute([$nuevo_empleado_nombre]);
                $existingId = $stmtCheck->fetchColumn();

                if ($existingId) {
                    $empleado_id = (int) $existingId;
                } else {
                    $stmtInsEmp = $db->prepare("INSERT INTO empleados (nombre, sucursal_id, estado) VALUES (?, 1, 'Activo')");
                    $stmtInsEmp->execute([$nuevo_empleado_nombre]);
                    $empleado_id = (int) $db->lastInsertId();
                }
            }

            $password_plano = $_POST['password'] ?? '';
            $pass_ag_plano = $_POST['pass_ag'] ?? '';
            $correo_gmail = trim($_POST['correo_gmail'] ?? '');
            $pass_gmail_plano = $_POST['pass_gmail'] ?? '';

            $fecha_inicializacion = !empty($_POST['fecha_inicializacion']) ? $_POST['fecha_inicializacion'] : date('Y-m-d');
            $ciclo_facturacion = $_POST['ciclo_facturacion'];
            $estado = $_POST['estado'];

            // Cifrar contraseñas (AES-256)
            $password_cifrado = !empty($password_plano) ? Security::encrypt($password_plano) : null;
            $pass_ag_cifrado = !empty($pass_ag_plano) ? Security::encrypt($pass_ag_plano) : null;
            $pass_gmail_cifrado = !empty($pass_gmail_plano) ? Security::encrypt($pass_gmail_plano) : null;

            // Calcular fecha automática de próximo pago
            $fecha_proximo_pago = calcularProximoPago($fecha_inicializacion, $ciclo_facturacion);

            $stmt = $db->prepare("
                INSERT INTO cuentas_m365 (empleado_id, correo, licencia_tipo, password_cifrado, pass_ag_cifrado, correo_gmail, pass_gmail_cifrado, fecha_inicializacion, ciclo_facturacion, fecha_proximo_pago, estado) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$empleado_id, $correo, $licencia_tipo, $password_cifrado, $pass_ag_cifrado, $correo_gmail, $pass_gmail_cifrado, $fecha_inicializacion, $ciclo_facturacion, $fecha_proximo_pago, $estado]);

            $_SESSION['flash_success'] = "Cuenta M365 creada correctamente.";
            header('Location: index.php?tab=m365');
            exit;
        }

        // 3.5 Editar Cuenta M365
        if ($action === 'edit_m365') {
            $id = (int) $_POST['id'];
            $correo = trim($_POST['correo']);
            $licencia_tipo = trim($_POST['licencia_tipo']);
            $empleado_id = (!empty($_POST['empleado_id']) && $_POST['empleado_id'] !== 'NEW_EMP') ? (int) $_POST['empleado_id'] : null;
            $nuevo_empleado_nombre = trim($_POST['nuevo_empleado_nombre'] ?? '');

            if (!empty($nuevo_empleado_nombre)) {
                $nuevo_empleado_nombre = mb_convert_case(mb_strtolower($nuevo_empleado_nombre, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
                $stmtCheck = $db->prepare("SELECT id FROM empleados WHERE LOWER(TRIM(nombre)) = LOWER(TRIM(?)) LIMIT 1");
                $stmtCheck->execute([$nuevo_empleado_nombre]);
                $existingId = $stmtCheck->fetchColumn();

                if ($existingId) {
                    $empleado_id = (int) $existingId;
                } else {
                    $stmtInsEmp = $db->prepare("INSERT INTO empleados (nombre, sucursal_id, estado) VALUES (?, 1, 'Activo')");
                    $stmtInsEmp->execute([$nuevo_empleado_nombre]);
                    $empleado_id = (int) $db->lastInsertId();
                }
            }
            $password_plano = $_POST['password'] ?? '';
            $pass_ag_plano = $_POST['pass_ag'] ?? '';
            $correo_gmail = trim($_POST['correo_gmail'] ?? '');
            $pass_gmail_plano = $_POST['pass_gmail'] ?? '';

            $fecha_inicializacion = $_POST['fecha_inicializacion'];
            $ciclo_facturacion = $_POST['ciclo_facturacion'];
            $estado = $_POST['estado'];

            // Calcular fecha automática de próximo pago
            $fecha_proximo_pago = calcularProximoPago($fecha_inicializacion, $ciclo_facturacion);

            $dbFields = [
                'empleado_id = ?',
                'correo = ?',
                'licencia_tipo = ?',
                'fecha_inicializacion = ?',
                'ciclo_facturacion = ?',
                'fecha_proximo_pago = ?',
                'estado = ?',
                'correo_gmail = ?'
            ];
            $params = [
                $empleado_id,
                $correo,
                $licencia_tipo,
                $fecha_inicializacion,
                $ciclo_facturacion,
                $fecha_proximo_pago,
                $estado,
                !empty($correo_gmail) ? $correo_gmail : null
            ];

            if (!empty($password_plano)) {
                $dbFields[] = 'password_cifrado = ?';
                $params[] = Security::encrypt($password_plano);
            }
            if (!empty($pass_ag_plano)) {
                $dbFields[] = 'pass_ag_cifrado = ?';
                $params[] = Security::encrypt($pass_ag_plano);
            }
            if (!empty($pass_gmail_plano)) {
                $dbFields[] = 'pass_gmail_cifrado = ?';
                $params[] = Security::encrypt($pass_gmail_plano);
            }

            $params[] = $id;

            $sql = "UPDATE cuentas_m365 SET " . implode(', ', $dbFields) . " WHERE id = ?";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);

            $_SESSION['flash_success'] = "Cuenta M365 y credenciales adicionales actualizadas.";
            header('Location: index.php?tab=m365');
            exit;
        }

        // 4. Importar Credenciales de Equipos desde CSV
        if ($action === 'import_csv_eq') {
            if (!isset($_FILES['archivo_csv']) || $_FILES['archivo_csv']['error'] !== UPLOAD_ERR_OK) {
                $_SESSION['flash_error'] = "Debe seleccionar un archivo CSV válido.";
                header('Location: index.php?tab=equipos');
                exit;
            }

            $handle = fopen($_FILES['archivo_csv']['tmp_name'], 'r');
            if (!$handle) {
                $_SESSION['flash_error'] = "No se pudo abrir el archivo subido.";
                header('Location: index.php?tab=equipos');
                exit;
            }

            $firstLine = fgets($handle);
            rewind($handle);
            $delimiter = (substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';

            // Saltar filas de instrucciones (#) y leer encabezado real
            $rawHeader = null;
            while (($rowTmp = fgetcsv($handle, 0, $delimiter)) !== FALSE) {
                if (empty($rowTmp) || (count($rowTmp) === 1 && trim($rowTmp[0]) === ''))
                    continue;
                $firstCell = trim(preg_replace('/\xEF\xBB\xBF/', '', $rowTmp[0] ?? ''));
                if (strpos($firstCell, '#') === 0)
                    continue;
                $rowTmp[0] = $firstCell;
                $rawHeader = $rowTmp;
                break;
            }
            if (isset($rawHeader[0])) {
                $rawHeader[0] = preg_replace('/\x{EF}\x{BB}\x{BF}/', '', $rawHeader[0]);
            }
            $header = array_map(function ($col) {
                return strtolower(trim($col));
            }, $rawHeader ?: []);
            $colIdx = array_flip($header);

            if (!isset($colIdx['service_tag_sn']) || !isset($colIdx['usuario_local']) || !isset($colIdx['password'])) {
                fclose($handle);
                $_SESSION['flash_error'] = "Faltan columnas requeridas (service_tag_sn, usuario_local, password) en el CSV.";
                header('Location: index.php?tab=equipos');
                exit;
            }

            // Mapear equipos existentes por Service Tag e ID
            $stmtEq = $db->query("SELECT id, LOWER(service_tag_sn) AS sn_low FROM equipos");
            $equiposMap = [];
            while ($r = $stmtEq->fetch()) {
                $equiposMap[$r['id']] = $r['id'];
                if (!empty($r['sn_low'])) {
                    $equiposMap[$r['sn_low']] = $r['id'];
                }
            }

            $stmtInsertCredEq = $db->prepare("
                INSERT INTO credenciales_equipos (equipo_id, usuario_local, password_cifrado, clave_bitlocker, notas_acceso) 
                VALUES (?, ?, ?, ?, ?)
            ");

            $exitos = 0;
            $sinEquipo = 0;
            $errores = 0;

            $db->beginTransaction();
            while (($data = fgetcsv($handle, 0, $delimiter)) !== FALSE) {
                if (empty($data) || (count($data) === 1 && trim($data[0]) === ''))
                    continue;
                // Ignorar filas de instrucciones (comienzan con #)
                if (isset($data[0]) && strpos(trim($data[0]), '#') === 0)
                    continue;

                $tagVal = trim($data[$colIdx['service_tag_sn']] ?? '');
                $userVal = trim($data[$colIdx['usuario_local']] ?? '');
                $passVal = $data[$colIdx['password']] ?? '';
                $bitlockerVal = isset($colIdx['clave_bitlocker']) ? trim($data[$colIdx['clave_bitlocker']] ?? '') : '';
                $notasVal = isset($colIdx['notas_acceso']) ? trim($data[$colIdx['notas_acceso']] ?? '') : '';

                if (empty($tagVal) || empty($userVal) || empty($passVal)) {
                    $errores++;
                    continue;
                }

                // Resolver equipo_id
                $equipoId = null;
                if (is_numeric($tagVal) && isset($equiposMap[(int) $tagVal])) {
                    $equipoId = (int) $tagVal;
                } elseif (isset($equiposMap[strtolower($tagVal)])) {
                    $equipoId = $equiposMap[strtolower($tagVal)];
                }

                if (!$equipoId) {
                    $sinEquipo++;
                    continue;
                }

                $passCifrado = Security::encrypt($passVal);
                $bitlockerCifrado = !empty($bitlockerVal) ? Security::encrypt($bitlockerVal) : null;

                $stmtInsertCredEq->execute([
                    $equipoId,
                    $userVal,
                    $passCifrado,
                    $bitlockerCifrado,
                    !empty($notasVal) ? $notasVal : null
                ]);

                $exitos++;
            }
            $db->commit();
            fclose($handle);

            $msg = "Importación completada: $exitos credenciales de equipo registradas exitosamente.";
            if ($sinEquipo > 0)
                $msg .= " $sinEquipo omitidas por no encontrar el equipo (Service Tag/SN no coincide).";
            if ($errores > 0)
                $msg .= " $errores filas ignoradas por datos incompletos.";

            $_SESSION['flash_success'] = $msg;
            $redirectUrl = (isset($_GET['redirect']) && $_GET['redirect'] === 'config') ? '../configuracion/index.php' : 'index.php?tab=equipos';
            header('Location: ' . $redirectUrl);
            exit;
        }

        // 5. Importar Credenciales Web desde CSV
        if ($action === 'import_csv_web') {
            if (!isset($_FILES['archivo_csv']) || $_FILES['archivo_csv']['error'] !== UPLOAD_ERR_OK) {
                $_SESSION['flash_error'] = "Debe seleccionar un archivo CSV válido.";
                header('Location: index.php?tab=web');
                exit;
            }

            $handle = fopen($_FILES['archivo_csv']['tmp_name'], 'r');
            if (!$handle) {
                $_SESSION['flash_error'] = "No se pudo abrir el archivo subido.";
                header('Location: index.php?tab=web');
                exit;
            }

            $firstLine = fgets($handle);
            rewind($handle);
            $delimiter = (substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';

            // Saltar filas de instrucciones (#) y leer encabezado real
            $rawHeader = null;
            while (($rowTmp = fgetcsv($handle, 0, $delimiter)) !== FALSE) {
                if (empty($rowTmp) || (count($rowTmp) === 1 && trim($rowTmp[0]) === ''))
                    continue;
                $firstCell = trim(preg_replace('/\xEF\xBB\xBF/', '', $rowTmp[0] ?? ''));
                if (strpos($firstCell, '#') === 0)
                    continue;
                $rowTmp[0] = $firstCell;
                $rawHeader = $rowTmp;
                break;
            }
            if (isset($rawHeader[0])) {
                $rawHeader[0] = preg_replace('/\x{EF}\x{BB}\x{BF}/', '', $rawHeader[0]);
            }
            $header = array_map(function ($col) {
                return strtolower(trim($col));
            }, $rawHeader ?: []);
            $colIdx = array_flip($header);

            if (!isset($colIdx['plataforma']) || !isset($colIdx['usuario']) || !isset($colIdx['password'])) {
                fclose($handle);
                $_SESSION['flash_error'] = "Faltan columnas requeridas (plataforma, usuario, password) en el CSV.";
                header('Location: index.php?tab=web');
                exit;
            }

            $stmtInsertCredWeb = $db->prepare("
                INSERT INTO credenciales_web (plataforma, url_acceso, usuario, password_cifrado, notas) 
                VALUES (?, ?, ?, ?, ?)
            ");

            $exitos = 0;
            $errores = 0;

            $db->beginTransaction();
            while (($data = fgetcsv($handle, 0, $delimiter)) !== FALSE) {
                if (empty($data) || (count($data) === 1 && trim($data[0]) === ''))
                    continue;
                // Ignorar filas de instrucciones (comienzan con #)
                if (isset($data[0]) && strpos(trim($data[0]), '#') === 0)
                    continue;

                $platVal = trim($data[$colIdx['plataforma']] ?? '');
                $urlVal = isset($colIdx['url_acceso']) ? trim($data[$colIdx['url_acceso']] ?? '') : '';
                $userVal = trim($data[$colIdx['usuario']] ?? '');
                $passVal = $data[$colIdx['password']] ?? '';
                $notasVal = isset($colIdx['notas']) ? trim($data[$colIdx['notas']] ?? '') : '';

                if (empty($platVal) || empty($userVal) || empty($passVal)) {
                    $errores++;
                    continue;
                }

                $passCifrado = Security::encrypt($passVal);

                $stmtInsertCredWeb->execute([
                    $platVal,
                    !empty($urlVal) ? $urlVal : null,
                    $userVal,
                    $passCifrado,
                    !empty($notasVal) ? $notasVal : null
                ]);

                $exitos++;
            }
            $db->commit();
            fclose($handle);

            $msg = "Importación completada: $exitos credenciales web registradas exitosamente.";
            if ($errores > 0)
                $msg .= " $errores filas ignoradas por datos incompletos.";

            $_SESSION['flash_success'] = $msg;
            $redirectUrl = (isset($_GET['redirect']) && $_GET['redirect'] === 'config') ? '../configuracion/index.php' : 'index.php?tab=web';
            header('Location: ' . $redirectUrl);
            exit;
        }

        // 6. Importar Cuentas M365 desde CSV
        if ($action === 'import_csv_m365') {
            if (!isset($_FILES['archivo_csv']) || $_FILES['archivo_csv']['error'] !== UPLOAD_ERR_OK) {
                $_SESSION['flash_error'] = "Debe seleccionar un archivo CSV válido.";
                header('Location: index.php?tab=m365');
                exit;
            }

            $handle = fopen($_FILES['archivo_csv']['tmp_name'], 'r');
            if (!$handle) {
                $_SESSION['flash_error'] = "No se pudo abrir el archivo subido.";
                header('Location: index.php?tab=m365');
                exit;
            }

            $firstLine = fgets($handle);
            rewind($handle);
            $delimiter = (substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';

            // Saltar filas de instrucciones (#) y leer encabezado real
            $rawHeader = null;
            while (($rowTmp = fgetcsv($handle, 0, $delimiter)) !== FALSE) {
                if (empty($rowTmp) || (count($rowTmp) === 1 && trim($rowTmp[0]) === ''))
                    continue;
                $firstCell = trim(preg_replace('/\xEF\xBB\xBF/', '', $rowTmp[0] ?? ''));
                if (strpos($firstCell, '#') === 0)
                    continue;
                $rowTmp[0] = $firstCell;
                $rawHeader = $rowTmp;
                break;
            }
            $header = array_map(function ($col) {
                return strtolower(trim($col));
            }, $rawHeader ?: []);
            $colIdx = array_flip($header);

            if (!isset($colIdx['correo']) || !isset($colIdx['licencia_tipo'])) {
                fclose($handle);
                $_SESSION['flash_error'] = "Faltan columnas requeridas (correo, licencia_tipo) en el CSV.";
                header('Location: index.php?tab=m365');
                exit;
            }

            // Mapear empleados por ID y nombre
            $stmtEmp = $db->query("SELECT id, LOWER(nombre) AS nombre_low FROM empleados");
            $empleadosMap = [];
            while ($r = $stmtEmp->fetch()) {
                $empleadosMap[$r['id']] = $r['id'];
                $empleadosMap[$r['nombre_low']] = $r['id'];
            }

            $stmtCheckCorreo = $db->prepare("SELECT COUNT(*) FROM cuentas_m365 WHERE correo = ?");
            $stmtInsertM365 = $db->prepare("
                INSERT INTO cuentas_m365 (empleado_id, correo, licencia_tipo, password_cifrado, fecha_inicializacion, ciclo_facturacion, fecha_proximo_pago, estado) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $exitos = 0;
            $duplicados = 0;
            $errores = 0;

            $db->beginTransaction();
            while (($data = fgetcsv($handle, 0, $delimiter)) !== FALSE) {
                if (empty($data) || (count($data) === 1 && trim($data[0]) === ''))
                    continue;
                // Ignorar filas de instrucciones (comienzan con #)
                if (isset($data[0]) && strpos(trim($data[0]), '#') === 0)
                    continue;

                $correoVal = trim($data[$colIdx['correo']] ?? '');
                $licenciaVal = trim($data[$colIdx['licencia_tipo']] ?? '');
                $empVal = isset($colIdx['empleado_nombre']) ? trim($data[$colIdx['empleado_nombre']] ?? '') : '';
                $passVal = isset($colIdx['password']) ? $data[$colIdx['password']] : '';
                $fechaInitVal = isset($colIdx['fecha_inicializacion']) ? trim($data[$colIdx['fecha_inicializacion']] ?? '') : date('Y-m-d');
                $cicloVal = isset($colIdx['ciclo_facturacion']) ? trim($data[$colIdx['ciclo_facturacion']] ?? '') : 'Mensual';
                $estadoVal = isset($colIdx['estado']) ? trim($data[$colIdx['estado']] ?? '') : 'Activa';

                if (empty($correoVal) || empty($licenciaVal)) {
                    $errores++;
                    continue;
                }

                // Verificar correo duplicado
                $stmtCheckCorreo->execute([$correoVal]);
                if ($stmtCheckCorreo->fetchColumn() > 0) {
                    $duplicados++;
                    continue;
                }

                // Resolver empleado_id
                $empleadoId = null;
                if (is_numeric($empVal) && isset($empleadosMap[(int) $empVal])) {
                    $empleadoId = (int) $empVal;
                } elseif (!empty($empVal) && isset($empleadosMap[strtolower($empVal)])) {
                    $empleadoId = $empleadosMap[strtolower($empVal)];
                }

                $passCifrado = !empty($passVal) ? Security::encrypt($passVal) : null;
                $cicloNormalizado = (strcasecmp($cicloVal, 'Anual') === 0) ? 'Anual' : 'Mensual';
                $fechaInitOk = preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaInitVal) ? $fechaInitVal : date('Y-m-d');
                $fechaProximoPago = calcularProximoPago($fechaInitOk, $cicloNormalizado);

                $estadosPermitidos = ['Activa', 'Suspendida', 'Eliminada'];
                $estadoNormalizado = 'Activa';
                foreach ($estadosPermitidos as $e) {
                    if (strcasecmp($e, $estadoVal) === 0) {
                        $estadoNormalizado = $e;
                        break;
                    }
                }

                $stmtInsertM365->execute([
                    $empleadoId,
                    $correoVal,
                    $licenciaVal,
                    $passCifrado,
                    $fechaInitOk,
                    $cicloNormalizado,
                    $fechaProximoPago,
                    $estadoNormalizado
                ]);

                $exitos++;
            }
            $db->commit();
            fclose($handle);

            $msg = "Importación completada: $exitos cuentas M365 registradas exitosamente.";
            if ($duplicados > 0)
                $msg .= " $duplicados omitidas por correo duplicado.";
            if ($errores > 0)
                $msg .= " $errores filas ignoradas por faltar datos requeridos.";

            $_SESSION['flash_success'] = $msg;
            $redirectUrl = (isset($_GET['redirect']) && $_GET['redirect'] === 'config') ? '../configuracion/index.php' : 'index.php?tab=m365';
            header('Location: ' . $redirectUrl);
            exit;
        }
    } else {
        // Acciones GET (Eliminar)

        // 1. Eliminar Credencial Equipo
        if ($action === 'delete_eq') {
            $id = (int) $_GET['id'];
            $stmt = $db->prepare("DELETE FROM credenciales_equipos WHERE id = ?");
            $stmt->execute([$id]);
            header('Location: index.php?tab=equipos');
            exit;
        }

        // 2. Eliminar Credencial Web
        if ($action === 'delete_web') {
            $id = (int) $_GET['id'];
            $stmt = $db->prepare("DELETE FROM credenciales_web WHERE id = ?");
            $stmt->execute([$id]);
            header('Location: index.php?tab=web');
            exit;
        }

        // 3. Eliminar Cuenta M365
        if ($action === 'delete_m365') {
            $id = (int) $_GET['id'];
            $stmt = $db->prepare("DELETE FROM cuentas_m365 WHERE id = ?");
            $stmt->execute([$id]);
            header('Location: index.php?tab=m365');
            exit;
        }
    }
} catch (Exception $e) {
    die("Error procesando los datos: " . $e->getMessage());
}
