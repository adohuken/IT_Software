<?php
/**
 * Controlador de Gestión de Infraestructura & Redes IP, Bóveda de Accesos IT y Personal
 */
session_start();
require_once __DIR__ . '/../../config/conexion.php';
require_once __DIR__ . '/../../config/security.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}

$db = Database::getConexion();
$action = $_GET['action'] ?? '';

// ==========================================
// 1. GUARDAR / EDITAR DISPOSITIVO IP DE RED
// ==========================================
if ($action === 'save_dispositivo' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $dev_id = !empty($_POST['dev_id']) ? (int)$_POST['dev_id'] : null;
    $sucursal_id = !empty($_POST['sucursal_id']) ? (int)$_POST['sucursal_id'] : null;
    $nombre_dispositivo = trim($_POST['nombre_dispositivo'] ?? '');
    $tipo = $_POST['tipo'] ?? 'Switch Managed';
    $marca = trim($_POST['marca'] ?? '');
    $modelo = trim($_POST['modelo'] ?? '');
    $serie_mac = trim($_POST['serie_mac'] ?? '');
    $ip_direccion = trim($_POST['ip_direccion'] ?? '');
    $mascara_vlan = trim($_POST['mascara_vlan'] ?? '');
    $puertos = trim($_POST['puertos'] ?? '');
    $usuario_admin = trim($_POST['usuario_admin'] ?? '');
    $password_plano = $_POST['password_admin'] ?? '';
    $url_gestion = trim($_POST['url_gestion'] ?? '');
    $ubicacion_rack = trim($_POST['ubicacion_rack'] ?? '');
    $estado = $_POST['estado'] ?? 'Operativo';
    $notas = trim($_POST['notas'] ?? '');

    if (empty($nombre_dispositivo) || empty($ip_direccion)) {
        $_SESSION['flash_error'] = "El nombre del dispositivo y la dirección IP son obligatorios.";
        header('Location: index.php?tab=equipos_red');
        exit;
    }

    try {
        if ($dev_id) {
            // Edición
            if (!empty($password_plano)) {
                $password_cifrado = Security::encrypt($password_plano);
                $stmt = $db->prepare("
                    UPDATE infraestructura_red SET 
                        sucursal_id = ?, nombre_dispositivo = ?, tipo = ?, marca = ?, modelo = ?, 
                        serie_mac = ?, ip_direccion = ?, mascara_vlan = ?, puertos = ?, 
                        usuario_admin = ?, password_cifrado = ?, url_gestion = ?, 
                        ubicacion_rack = ?, estado = ?, notas = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $sucursal_id, $nombre_dispositivo, $tipo, $marca, $modelo,
                    $serie_mac, $ip_direccion, $mascara_vlan, $puertos,
                    $usuario_admin, $password_cifrado, $url_gestion,
                    $ubicacion_rack, $estado, $notas, $dev_id
                ]);
            } else {
                $stmt = $db->prepare("
                    UPDATE infraestructura_red SET 
                        sucursal_id = ?, nombre_dispositivo = ?, tipo = ?, marca = ?, modelo = ?, 
                        serie_mac = ?, ip_direccion = ?, mascara_vlan = ?, puertos = ?, 
                        usuario_admin = ?, url_gestion = ?, 
                        ubicacion_rack = ?, estado = ?, notas = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $sucursal_id, $nombre_dispositivo, $tipo, $marca, $modelo,
                    $serie_mac, $ip_direccion, $mascara_vlan, $puertos,
                    $usuario_admin, $url_gestion,
                    $ubicacion_rack, $estado, $notas, $dev_id
                ]);
            }
            $_SESSION['flash_success'] = "Dispositivo de Red <b>" . htmlspecialchars($nombre_dispositivo) . "</b> actualizado correctamente.";
        } else {
            // Registro Nuevo
            $password_cifrado = !empty($password_plano) ? Security::encrypt($password_plano) : null;
            $stmt = $db->prepare("
                INSERT INTO infraestructura_red (
                    sucursal_id, nombre_dispositivo, tipo, marca, modelo, 
                    serie_mac, ip_direccion, mascara_vlan, puertos, 
                    usuario_admin, password_cifrado, url_gestion, 
                    ubicacion_rack, estado, notas
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $sucursal_id, $nombre_dispositivo, $tipo, $marca, $modelo,
                $serie_mac, $ip_direccion, $mascara_vlan, $puertos,
                $usuario_admin, $password_cifrado, $url_gestion,
                $ubicacion_rack, $estado, $notas
            ]);
            $_SESSION['flash_success'] = "Dispositivo de Red <b>" . htmlspecialchars($nombre_dispositivo) . "</b> registrado exitosamente.";
        }
    } catch (PDOException $e) {
        $_SESSION['flash_error'] = "Error en base de datos: " . $e->getMessage();
    }

    header('Location: index.php?tab=equipos_red');
    exit;
}

// ==========================================
// 2. ELIMINAR DISPOSITIVO DE RED
// ==========================================
if ($action === 'delete_dispositivo' && isset($_GET['id'])) {
    $dev_id = (int)$_GET['id'];
    try {
        $stmt = $db->prepare("DELETE FROM infraestructura_red WHERE id = ?");
        $stmt->execute([$dev_id]);
        $_SESSION['flash_success'] = "Dispositivo eliminado del inventario de infraestructura.";
    } catch (PDOException $e) {
        $_SESSION['flash_error'] = "Error al eliminar dispositivo: " . $e->getMessage();
    }
    header('Location: index.php?tab=equipos_red');
    exit;
}

// ==========================================
// 3. BÓVEDA DE CREDENCIALES WEB IT
// ==========================================
if ($action === 'save_boveda' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $web_id = !empty($_POST['web_id']) ? (int)$_POST['web_id'] : null;
    $plataforma = trim($_POST['plataforma'] ?? '');
    $url_acceso = trim($_POST['url_acceso'] ?? '');
    $usuario = trim($_POST['usuario'] ?? '');
    $password_plano = $_POST['password'] ?? '';
    $notas = trim($_POST['notas'] ?? '');

    if (empty($plataforma) || empty($usuario)) {
        $_SESSION['flash_error'] = "Plataforma/Portal y Usuario son requeridos.";
        header('Location: index.php?tab=boveda');
        exit;
    }

    try {
        if ($web_id) {
            if (!empty($password_plano)) {
                $password_cifrado = Security::encrypt($password_plano);
                $stmt = $db->prepare("UPDATE credenciales_web SET plataforma = ?, url_acceso = ?, usuario = ?, password_cifrado = ?, notas = ? WHERE id = ?");
                $stmt->execute([$plataforma, $url_acceso, $usuario, $password_cifrado, $notas, $web_id]);
            } else {
                $stmt = $db->prepare("UPDATE credenciales_web SET plataforma = ?, url_acceso = ?, usuario = ?, notas = ? WHERE id = ?");
                $stmt->execute([$plataforma, $url_acceso, $usuario, $notas, $web_id]);
            }
            $_SESSION['flash_success'] = "Credencial de portal <b>" . htmlspecialchars($plataforma) . "</b> actualizada.";
        } else {
            if (empty($password_plano)) {
                $_SESSION['flash_error'] = "La contraseña es requerida para nuevas credenciales web.";
                header('Location: index.php?tab=boveda');
                exit;
            }
            $password_cifrado = Security::encrypt($password_plano);
            $stmt = $db->prepare("INSERT INTO credenciales_web (plataforma, url_acceso, usuario, password_cifrado, notas) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$plataforma, $url_acceso, $usuario, $password_cifrado, $notas]);
            $_SESSION['flash_success'] = "¡Credencial de portal web <b>" . htmlspecialchars($plataforma) . "</b> guardada en la bóveda de IT!";
        }
    } catch (PDOException $e) {
        $_SESSION['flash_error'] = "Error al guardar en bóveda: " . $e->getMessage();
    }
    header('Location: index.php?tab=boveda');
    exit;
}

if ($action === 'delete_boveda' && isset($_GET['id'])) {
    $web_id = (int)$_GET['id'];
    try {
        $stmt = $db->prepare("DELETE FROM credenciales_web WHERE id = ?");
        $stmt->execute([$web_id]);
        $_SESSION['flash_success'] = "Credencial eliminada de la bóveda.";
    } catch (PDOException $e) {
        $_SESSION['flash_error'] = "Error al eliminar credencial: " . $e->getMessage();
    }
    header('Location: index.php?tab=boveda');
    exit;
}

// ==========================================
// 4. GESTIÓN DE INTEGRANTES DEL EQUIPO IT
// ==========================================
if ($action === 'save_it_user' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = !empty($_POST['user_id']) ? (int)$_POST['user_id'] : null;
    $nombre = trim($_POST['nombre'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $rol = $_POST['rol'] ?? 'Soporte';
    $estado = $_POST['estado'] ?? 'Activo';

    if (empty($nombre) || empty($email)) {
        $_SESSION['flash_error'] = "Nombre y correo son requeridos.";
        header('Location: index.php?tab=equipo_it');
        exit;
    }

    try {
        if ($user_id) {
            if (!empty($password)) {
                $pass_hash = password_hash($password, PASSWORD_BCRYPT);
                $stmt = $db->prepare("UPDATE equipo_it SET nombre = ?, email = ?, password_hash = ?, rol = ?, estado = ? WHERE id = ?");
                $stmt->execute([$nombre, $email, $pass_hash, $rol, $estado, $user_id]);
            } else {
                $stmt = $db->prepare("UPDATE equipo_it SET nombre = ?, email = ?, rol = ?, estado = ? WHERE id = ?");
                $stmt->execute([$nombre, $email, $rol, $estado, $user_id]);
            }
            $_SESSION['flash_success'] = "Integrante IT <b>" . htmlspecialchars($nombre) . "</b> actualizado.";
        } else {
            if (empty($password)) {
                $_SESSION['flash_error'] = "La contraseña es requerida para el nuevo integrante de IT.";
                header('Location: index.php?tab=equipo_it');
                exit;
            }
            $pass_hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $db->prepare("INSERT INTO equipo_it (nombre, email, password_hash, rol, estado) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$nombre, $email, $pass_hash, $rol, $estado]);
            $_SESSION['flash_success'] = "¡Nuevo integrante <b>" . htmlspecialchars($nombre) . "</b> agregado al equipo de IT!";
        }
    } catch (PDOException $e) {
        $_SESSION['flash_error'] = "Error al procesar integrante: " . $e->getMessage();
    }
    header('Location: index.php?tab=equipo_it');
    exit;
}

if ($action === 'delete_it_user' && isset($_GET['id'])) {
    $user_id = (int)$_GET['id'];
    try {
        if ($user_id == 1) {
            $_SESSION['flash_error'] = "El Administrador principal de IT no se puede eliminar.";
        } else {
            $stmt = $db->prepare("DELETE FROM equipo_it WHERE id = ?");
            $stmt->execute([$user_id]);
            $_SESSION['flash_success'] = "Usuario eliminado del equipo de IT.";
        }
    } catch (PDOException $e) {
        $_SESSION['flash_error'] = "Error al eliminar usuario: " . $e->getMessage();
    }
    header('Location: index.php?tab=equipo_it');
    exit;
}

// ==========================================
// 5. IMPORTACIÓN MASIVA DE INFRAESTRUCTURA & REDES (CSV / XLSX)
// ==========================================
if ($action === 'import_infra_csv' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $redirect = $_GET['redirect'] ?? '';
    $retUrl = ($redirect === 'config') ? BASE_URL . 'modules/configuracion/index.php' : 'index.php?tab=equipos_red';

    if (!isset($_FILES['archivo_csv']) || $_FILES['archivo_csv']['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['flash_error'] = "Por favor selecciona un archivo CSV o Excel válido.";
        if ($redirect === 'config') $_SESSION['flash_sys_err'] = "Por favor selecciona un archivo CSV o Excel válido.";
        header("Location: $retUrl");
        exit;
    }

    $tmpPath = $_FILES['archivo_csv']['tmp_name'];
    $fileName = $_FILES['archivo_csv']['name'];
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

    $sucursalesDb = $db->query("SELECT id, nombre FROM sucursales")->fetchAll(PDO::FETCH_ASSOC);
    $defaultSucId = !empty($sucursalesDb[0]['id']) ? $sucursalesDb[0]['id'] : 1;

    $itemsToImport = [];

    if ($ext === 'csv') {
        $rawContent = file_get_contents($tmpPath);
        $rawContent = str_replace(["\r\n", "\r"], "\n", $rawContent);
        $rawContent = preg_replace('/\xEF\xBB\xBF/', '', $rawContent);
        $allLines = explode("\n", $rawContent);

        $delimiter = ',';
        foreach ($allLines as $l) {
            if (trim($l) !== '') {
                if (substr_count($l, ';') > substr_count($l, ',')) $delimiter = ';';
                break;
            }
        }

        $header = null;
        foreach ($allLines as $line) {
            $lineStr = trim($line);
            if ($lineStr === '' || strpos($lineStr, '#') === 0) continue;
            $row = str_getcsv($lineStr, $delimiter);

            if (!$header) {
                $header = array_map(fn($h) => strtolower(trim($h)), $row);
                continue;
            }

            if (count($row) < 2) continue;

            $data = [];
            foreach ($header as $i => $hName) {
                $data[$hName] = trim($row[$i] ?? '');
            }

            $nombre = $data['nombre_dispositivo'] ?? ($data['nombre'] ?? ($row[0] ?? ''));
            $tipo = $data['tipo'] ?? ($row[1] ?? 'Switch Managed');
            $sucName = $data['sucursal'] ?? ($row[3] ?? '');
            $ip = $data['ip_direccion'] ?? ($data['ip'] ?? ($row[4] ?? ''));
            $vlan = $data['mascara_vlan'] ?? ($data['vlan'] ?? ($row[5] ?? ''));
            $marca = $data['marca'] ?? ($row[6] ?? '');
            $modelo = $data['modelo'] ?? ($row[7] ?? '');
            $serie = $data['serie_mac'] ?? ($data['serie'] ?? ($row[8] ?? ''));
            $puertos = $data['puertos'] ?? ($row[9] ?? '');
            $uAdmin = $data['usuario_admin'] ?? ($data['usuario'] ?? ($row[10] ?? ''));
            $pAdmin = $data['password_admin'] ?? ($data['password'] ?? ($row[11] ?? ''));
            $url = $data['url_gestion'] ?? ($data['url'] ?? ($row[12] ?? ''));
            $rack = $data['ubicacion_rack'] ?? ($data['rack'] ?? ($row[13] ?? ''));
            $estado = $data['estado'] ?? ($row[14] ?? 'Operativo');
            $notas = $data['notas'] ?? ($row[15] ?? '');

            if (empty($nombre) && empty($ip)) continue;
            if (strcasecmp($nombre, 'nombre_dispositivo') === 0 || strcasecmp($nombre, 'nombre') === 0) continue;

            $sucId = $defaultSucId;
            if (!empty($sucName)) {
                foreach ($sucursalesDb as $sDb) {
                    if (strpos(strtolower($sDb['nombre']), strtolower($sucName)) !== false || strpos(strtolower($sucName), strtolower($sDb['nombre'])) !== false) {
                        $sucId = $sDb['id'];
                        break;
                    }
                }
            }

            $itemsToImport[] = [
                'sucursal_id' => $sucId,
                'nombre_dispositivo' => $nombre ?: ($tipo . ' ' . $marca . ' ' . $modelo),
                'tipo' => $tipo ?: 'Switch Managed',
                'marca' => $marca,
                'modelo' => $modelo,
                'serie_mac' => $serie,
                'ip_direccion' => $ip ?: '192.168.1.1',
                'mascara_vlan' => $vlan,
                'puertos' => $puertos,
                'usuario_admin' => $uAdmin,
                'password_cifrado' => !empty($pAdmin) ? Security::encrypt($pAdmin) : null,
                'url_gestion' => $url,
                'ubicacion_rack' => $rack,
                'estado' => $estado ?: 'Operativo',
                'notas' => $notas
            ];
        }
    }

    $importados = 0;
    $stmtIns = $db->prepare("
        INSERT INTO infraestructura_red (
            sucursal_id, nombre_dispositivo, tipo, marca, modelo, 
            serie_mac, ip_direccion, mascara_vlan, puertos, 
            usuario_admin, password_cifrado, url_gestion, 
            ubicacion_rack, estado, notas
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    foreach ($itemsToImport as $item) {
        try {
            $stmtIns->execute([
                $item['sucursal_id'], $item['nombre_dispositivo'], $item['tipo'], $item['marca'], $item['modelo'],
                $item['serie_mac'], $item['ip_direccion'], $item['mascara_vlan'], $item['puertos'],
                $item['usuario_admin'], $item['password_cifrado'], $item['url_gestion'],
                $item['ubicacion_rack'], $item['estado'], $item['notas']
            ]);
            $importados++;
        } catch (Exception $ex) {
            // Ignorar duplicados o errores en filas individuales
        }
    }

    $msg = "Se importaron exitosamente <b>$importados</b> dispositivos de red e infraestructura.";
    $_SESSION['flash_success'] = $msg;
    if ($redirect === 'config') $_SESSION['flash_sys_ok'] = "Se importaron exitosamente $importados equipos de red e infraestructura IP.";

    header("Location: $retUrl");
    exit;
}

// ==========================================
// 6. IMPORTACIÓN MASIVA DE BÓVEDA WEB IT (CSV / XLSX)
// ==========================================
if ($action === 'import_web_csv' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $redirect = $_GET['redirect'] ?? '';
    $retUrl = ($redirect === 'config') ? BASE_URL . 'modules/configuracion/index.php' : 'index.php?tab=boveda';

    if (!isset($_FILES['archivo_csv']) || $_FILES['archivo_csv']['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['flash_error'] = "Por favor selecciona un archivo CSV o Excel válido.";
        if ($redirect === 'config') $_SESSION['flash_sys_err'] = "Por favor selecciona un archivo CSV o Excel válido.";
        header("Location: $retUrl");
        exit;
    }

    $tmpPath = $_FILES['archivo_csv']['tmp_name'];
    $rawContent = file_get_contents($tmpPath);
    $rawContent = str_replace(["\r\n", "\r"], "\n", $rawContent);
    $rawContent = preg_replace('/\xEF\xBB\xBF/', '', $rawContent);
    $allLines = explode("\n", $rawContent);

    $delimiter = ',';
    foreach ($allLines as $l) {
        if (trim($l) !== '') {
            if (substr_count($l, ';') > substr_count($l, ',')) $delimiter = ';';
            break;
        }
    }

    $header = null;
    $importados = 0;
    $stmtIns = $db->prepare("INSERT INTO credenciales_web (plataforma, url_acceso, usuario, password_cifrado, notas) VALUES (?, ?, ?, ?, ?)");

    foreach ($allLines as $line) {
        $lineStr = trim($line);
        if ($lineStr === '' || strpos($lineStr, '#') === 0) continue;
        $row = str_getcsv($lineStr, $delimiter);

        if (!$header) {
            $header = array_map(fn($h) => strtolower(trim($h)), $row);
            continue;
        }

        if (count($row) < 2) continue;

        $data = [];
        foreach ($header as $i => $hName) {
            $data[$hName] = trim($row[$i] ?? '');
        }

        $plat = $data['plataforma'] ?? ($row[0] ?? '');
        $url = $data['url_acceso'] ?? ($data['url'] ?? ($row[1] ?? ''));
        $user = $data['usuario'] ?? ($row[2] ?? '');
        $pass = $data['password'] ?? ($row[3] ?? '');
        $notas = $data['notas'] ?? ($row[4] ?? '');

        if (empty($plat) || empty($user)) continue;
        if (strcasecmp($plat, 'plataforma') === 0) continue;

        try {
            $passEnc = !empty($pass) ? Security::encrypt($pass) : '';
            $stmtIns->execute([$plat, $url, $user, $passEnc, $notas]);
            $importados++;
        } catch (Exception $ex) {}
    }

    $msg = "Se importaron <b>$importados</b> credenciales web a la bóveda de IT.";
    $_SESSION['flash_success'] = $msg;
    if ($redirect === 'config') $_SESSION['flash_sys_ok'] = "Se importaron $importados credenciales web a la bóveda de IT.";

    header("Location: $retUrl");
    exit;
}

header('Location: index.php');
exit;
