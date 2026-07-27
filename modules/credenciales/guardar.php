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
            $equipo_id = (int)$_POST['equipo_id'];
            $usuario_local = trim($_POST['usuario_local']);
            $password_plano = $_POST['password'];
            $bitlocker_plano = trim($_POST['clave_bitlocker'] ?? '');
            $notas_acceso = trim($_POST['notas_acceso'] ?? '');

            // Cifrar contraseñas sensibles
            $password_cifrado = Security::encrypt($password_plano);
            $bitlocker_cifrado = !empty($bitlocker_plano) ? Security::encrypt($bitlocker_plano) : null;

            $stmt = $db->prepare("
                INSERT INTO credenciales_equipos (equipo_id, usuario_local, password_cifrado, clave_bitlocker, notas_acceso) 
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$equipo_id, $usuario_local, $password_cifrado, $bitlocker_cifrado, $notas_acceso]);

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
            $empleado_id = !empty($_POST['empleado_id']) ? (int)$_POST['empleado_id'] : null;
            $password_plano = $_POST['password'] ?? '';
            $fecha_inicializacion = $_POST['fecha_inicializacion'];
            $ciclo_facturacion = $_POST['ciclo_facturacion'];
            $estado = $_POST['estado'];

            // Cifrar contraseña de correo M365 (AES-256)
            $password_cifrado = !empty($password_plano) ? Security::encrypt($password_plano) : null;

            // Calcular fecha automática de próximo pago
            $fecha_proximo_pago = calcularProximoPago($fecha_inicializacion, $ciclo_facturacion);

            $stmt = $db->prepare("
                INSERT INTO cuentas_m365 (empleado_id, correo, licencia_tipo, password_cifrado, fecha_inicializacion, ciclo_facturacion, fecha_proximo_pago, estado) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$empleado_id, $correo, $licencia_tipo, $password_cifrado, $fecha_inicializacion, $ciclo_facturacion, $fecha_proximo_pago, $estado]);

            header('Location: index.php?tab=m365');
            exit;
        }

        // 3.5 Editar Cuenta M365
        if ($action === 'edit_m365') {
            $id = (int)$_POST['id'];
            $correo = trim($_POST['correo']);
            $licencia_tipo = trim($_POST['licencia_tipo']);
            $empleado_id = !empty($_POST['empleado_id']) ? (int)$_POST['empleado_id'] : null;
            $password_plano = $_POST['password'] ?? '';
            $fecha_inicializacion = $_POST['fecha_inicializacion'];
            $ciclo_facturacion = $_POST['ciclo_facturacion'];
            $estado = $_POST['estado'];

            // Calcular fecha automática de próximo pago
            $fecha_proximo_pago = calcularProximoPago($fecha_inicializacion, $ciclo_facturacion);

            if (!empty($password_plano)) {
                $password_cifrado = Security::encrypt($password_plano);
                $stmt = $db->prepare("
                    UPDATE cuentas_m365 
                    SET empleado_id=?, correo=?, licencia_tipo=?, password_cifrado=?, fecha_inicializacion=?, ciclo_facturacion=?, fecha_proximo_pago=?, estado=? 
                    WHERE id=?
                ");
                $stmt->execute([$empleado_id, $correo, $licencia_tipo, $password_cifrado, $fecha_inicializacion, $ciclo_facturacion, $fecha_proximo_pago, $estado, $id]);
            } else {
                $stmt = $db->prepare("
                    UPDATE cuentas_m365 
                    SET empleado_id=?, correo=?, licencia_tipo=?, fecha_inicializacion=?, ciclo_facturacion=?, fecha_proximo_pago=?, estado=? 
                    WHERE id=?
                ");
                $stmt->execute([$empleado_id, $correo, $licencia_tipo, $fecha_inicializacion, $ciclo_facturacion, $fecha_proximo_pago, $estado, $id]);
            }

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
                if (empty($rowTmp) || (count($rowTmp) === 1 && trim($rowTmp[0]) === '')) continue;
                $firstCell = trim(preg_replace('/\xEF\xBB\xBF/', '', $rowTmp[0] ?? ''));
                if (strpos($firstCell, '#') === 0) continue;
                $rowTmp[0] = $firstCell;
                $rawHeader = $rowTmp;
                break;
            }
            if (isset($rawHeader[0])) {
                $rawHeader[0] = preg_replace('/\x{EF}\x{BB}\x{BF}/', '', $rawHeader[0]);
            }
            $header = array_map(function($col) { return strtolower(trim($col)); }, $rawHeader ?: []);
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
                if (empty($data) || (count($data) === 1 && trim($data[0]) === '')) continue;
                // Ignorar filas de instrucciones (comienzan con #)
                if (isset($data[0]) && strpos(trim($data[0]), '#') === 0) continue;

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
                if (is_numeric($tagVal) && isset($equiposMap[(int)$tagVal])) {
                    $equipoId = (int)$tagVal;
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
            if ($sinEquipo > 0) $msg .= " $sinEquipo omitidas por no encontrar el equipo (Service Tag/SN no coincide).";
            if ($errores > 0) $msg .= " $errores filas ignoradas por datos incompletos.";

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
                if (empty($rowTmp) || (count($rowTmp) === 1 && trim($rowTmp[0]) === '')) continue;
                $firstCell = trim(preg_replace('/\xEF\xBB\xBF/', '', $rowTmp[0] ?? ''));
                if (strpos($firstCell, '#') === 0) continue;
                $rowTmp[0] = $firstCell;
                $rawHeader = $rowTmp;
                break;
            }
            if (isset($rawHeader[0])) {
                $rawHeader[0] = preg_replace('/\x{EF}\x{BB}\x{BF}/', '', $rawHeader[0]);
            }
            $header = array_map(function($col) { return strtolower(trim($col)); }, $rawHeader ?: []);
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
                if (empty($data) || (count($data) === 1 && trim($data[0]) === '')) continue;
                // Ignorar filas de instrucciones (comienzan con #)
                if (isset($data[0]) && strpos(trim($data[0]), '#') === 0) continue;

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
            if ($errores > 0) $msg .= " $errores filas ignoradas por datos incompletos.";

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
                if (empty($rowTmp) || (count($rowTmp) === 1 && trim($rowTmp[0]) === '')) continue;
                $firstCell = trim(preg_replace('/\xEF\xBB\xBF/', '', $rowTmp[0] ?? ''));
                if (strpos($firstCell, '#') === 0) continue;
                $rowTmp[0] = $firstCell;
                $rawHeader = $rowTmp;
                break;
            }
            $header = array_map(function($col) { return strtolower(trim($col)); }, $rawHeader ?: []);
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
                if (empty($data) || (count($data) === 1 && trim($data[0]) === '')) continue;
                // Ignorar filas de instrucciones (comienzan con #)
                if (isset($data[0]) && strpos(trim($data[0]), '#') === 0) continue;

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
                if (is_numeric($empVal) && isset($empleadosMap[(int)$empVal])) {
                    $empleadoId = (int)$empVal;
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
            if ($duplicados > 0) $msg .= " $duplicados omitidas por correo duplicado.";
            if ($errores > 0) $msg .= " $errores filas ignoradas por faltar datos requeridos.";

            $_SESSION['flash_success'] = $msg;
            $redirectUrl = (isset($_GET['redirect']) && $_GET['redirect'] === 'config') ? '../configuracion/index.php' : 'index.php?tab=m365';
            header('Location: ' . $redirectUrl);
            exit;
        }
    } else {
        // Acciones GET (Eliminar)
        
        // 1. Eliminar Credencial Equipo
        if ($action === 'delete_eq') {
            $id = (int)$_GET['id'];
            $stmt = $db->prepare("DELETE FROM credenciales_equipos WHERE id = ?");
            $stmt->execute([$id]);
            header('Location: index.php?tab=equipos');
            exit;
        }

        // 2. Eliminar Credencial Web
        if ($action === 'delete_web') {
            $id = (int)$_GET['id'];
            $stmt = $db->prepare("DELETE FROM credenciales_web WHERE id = ?");
            $stmt->execute([$id]);
            header('Location: index.php?tab=web');
            exit;
        }

        // 3. Eliminar Cuenta M365
        if ($action === 'delete_m365') {
            $id = (int)$_GET['id'];
            $stmt = $db->prepare("DELETE FROM cuentas_m365 WHERE id = ?");
            $stmt->execute([$id]);
            header('Location: index.php?tab=m365');
            exit;
        }
    }
} catch (Exception $e) {
    die("Error procesando los datos: " . $e->getMessage());
}
