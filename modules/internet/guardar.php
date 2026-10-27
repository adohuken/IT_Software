<?php
/**
 * Controlador de Guardado para Proveedores de Internet & Enlaces WAN
 */

session_start();
require_once __DIR__ . '/../../config/conexion.php';

// Validar sesión
if (!isset($_SESSION['user_id'])) {
    if (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Sesión expirada']);
        exit;
    }
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}

$db = Database::getConexion();
$action = $_GET['action'] ?? '';
$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') 
          || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

function responder($success, $mensaje, $data = [], $isAjax = false, $redirect = 'index.php') {
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array_merge([
            'success' => $success,
            'message' => $mensaje,
            'error'   => $success ? null : $mensaje
        ], $data));
        exit;
    } else {
        if ($success) {
            $_SESSION['flash_success'] = $mensaje;
        } else {
            $_SESSION['flash_error'] = $mensaje;
        }
        header("Location: $redirect");
        exit;
    }
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        // 1. CREAR ENLACE DE INTERNET
        if ($action === 'crear') {
            $empresa_id        = (int)($_POST['empresa_id'] ?? 0);
            $sucursal_id       = (int)($_POST['sucursal_id'] ?? 0);
            $proveedor         = trim($_POST['proveedor'] ?? '');
            $numero_cliente    = trim($_POST['numero_cliente'] ?? '');
            $plan_servicio     = trim($_POST['plan_servicio'] ?? '');
            $tipo_enlace       = trim($_POST['tipo_enlace'] ?? 'Principal');
            $medio_transmision = trim($_POST['medio_transmision'] ?? 'Fibra Óptica');
            $velocidad_bajada  = trim($_POST['velocidad_bajada'] ?? '');
            $velocidad_subida  = trim($_POST['velocidad_subida'] ?? '');
            $tipo_ip           = trim($_POST['tipo_ip'] ?? 'IP Fija / Estática');
            $ip_publica        = trim($_POST['ip_publica'] ?? '');
            $mascara_subred    = trim($_POST['mascara_subred'] ?? '');
            $puerta_enlace     = trim($_POST['puerta_enlace'] ?? '');
            $dns_preferido     = trim($_POST['dns_preferido'] ?? '');
            $dns_alternativo   = trim($_POST['dns_alternativo'] ?? '');
            $vlan_id           = trim($_POST['vlan_id'] ?? '');
            $equipo_terminal   = trim($_POST['equipo_terminal'] ?? '');
            $contacto_soporte  = trim($_POST['contacto_soporte'] ?? '');
            $costo_mensual     = !empty($_POST['costo_mensual']) ? (float)$_POST['costo_mensual'] : null;
            $moneda            = trim($_POST['moneda'] ?? 'USD');
            $estado            = trim($_POST['estado'] ?? 'Activo');
            $notas             = trim($_POST['notas'] ?? '');

            if ($empresa_id <= 0 || $sucursal_id <= 0) {
                responder(false, 'Debe seleccionar una empresa y una sucursal válidas.', [], $isAjax);
            }
            if (empty($proveedor)) {
                responder(false, 'El nombre del proveedor (ISP) es obligatorio.', [], $isAjax);
            }

            $sql = "INSERT INTO proveedores_internet (
                empresa_id, sucursal_id, proveedor, numero_cliente, plan_servicio,
                tipo_enlace, medio_transmision, velocidad_bajada, velocidad_subida,
                tipo_ip, ip_publica, mascara_subred, puerta_enlace, dns_preferido,
                dns_alternativo, vlan_id, equipo_terminal, contacto_soporte,
                costo_mensual, moneda, estado, notas
            ) VALUES (
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?
            )";

            $stmt = $db->prepare($sql);
            $stmt->execute([
                $empresa_id, $sucursal_id, $proveedor, $numero_cliente ?: null, $plan_servicio ?: null,
                $tipo_enlace, $medio_transmision, $velocidad_bajada ?: null, $velocidad_subida ?: null,
                $tipo_ip, $ip_publica ?: null, $mascara_subred ?: null, $puerta_enlace ?: null, $dns_preferido ?: null,
                $dns_alternativo ?: null, $vlan_id ?: null, $equipo_terminal ?: null, $contacto_soporte ?: null,
                $costo_mensual, $moneda, $estado, $notas ?: null
            ]);

            $newId = (int)$db->lastInsertId();

            responder(true, "Enlace de internet de '$proveedor' registrado exitosamente.", ['id' => $newId], $isAjax);
        }

        // 2. EDITAR ENLACE DE INTERNET
        if ($action === 'editar') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                responder(false, 'ID de enlace inválido.', [], $isAjax);
            }

            $empresa_id        = (int)($_POST['empresa_id'] ?? 0);
            $sucursal_id       = (int)($_POST['sucursal_id'] ?? 0);
            $proveedor         = trim($_POST['proveedor'] ?? '');
            $numero_cliente    = trim($_POST['numero_cliente'] ?? '');
            $plan_servicio     = trim($_POST['plan_servicio'] ?? '');
            $tipo_enlace       = trim($_POST['tipo_enlace'] ?? 'Principal');
            $medio_transmision = trim($_POST['medio_transmision'] ?? 'Fibra Óptica');
            $velocidad_bajada  = trim($_POST['velocidad_bajada'] ?? '');
            $velocidad_subida  = trim($_POST['velocidad_subida'] ?? '');
            $tipo_ip           = trim($_POST['tipo_ip'] ?? 'IP Fija / Estática');
            $ip_publica        = trim($_POST['ip_publica'] ?? '');
            $mascara_subred    = trim($_POST['mascara_subred'] ?? '');
            $puerta_enlace     = trim($_POST['puerta_enlace'] ?? '');
            $dns_preferido     = trim($_POST['dns_preferido'] ?? '');
            $dns_alternativo   = trim($_POST['dns_alternativo'] ?? '');
            $vlan_id           = trim($_POST['vlan_id'] ?? '');
            $equipo_terminal   = trim($_POST['equipo_terminal'] ?? '');
            $contacto_soporte  = trim($_POST['contacto_soporte'] ?? '');
            $costo_mensual     = !empty($_POST['costo_mensual']) ? (float)$_POST['costo_mensual'] : null;
            $moneda            = trim($_POST['moneda'] ?? 'USD');
            $estado            = trim($_POST['estado'] ?? 'Activo');
            $notas             = trim($_POST['notas'] ?? '');

            if ($empresa_id <= 0 || $sucursal_id <= 0 || empty($proveedor)) {
                responder(false, 'Por favor complete los campos obligatorios.', [], $isAjax);
            }

            $sql = "UPDATE proveedores_internet SET
                empresa_id = ?, sucursal_id = ?, proveedor = ?, numero_cliente = ?, plan_servicio = ?,
                tipo_enlace = ?, medio_transmision = ?, velocidad_bajada = ?, velocidad_subida = ?,
                tipo_ip = ?, ip_publica = ?, mascara_subred = ?, puerta_enlace = ?, dns_preferido = ?,
                dns_alternativo = ?, vlan_id = ?, equipo_terminal = ?, contacto_soporte = ?,
                costo_mensual = ?, moneda = ?, estado = ?, notas = ?
                WHERE id = ?";

            $stmt = $db->prepare($sql);
            $stmt->execute([
                $empresa_id, $sucursal_id, $proveedor, $numero_cliente ?: null, $plan_servicio ?: null,
                $tipo_enlace, $medio_transmision, $velocidad_bajada ?: null, $velocidad_subida ?: null,
                $tipo_ip, $ip_publica ?: null, $mascara_subred ?: null, $puerta_enlace ?: null, $dns_preferido ?: null,
                $dns_alternativo ?: null, $vlan_id ?: null, $equipo_terminal ?: null, $contacto_soporte ?: null,
                $costo_mensual, $moneda, $estado, $notas ?: null,
                $id
            ]);

            responder(true, "Datos del enlace '$proveedor' actualizados correctamente.", ['id' => $id], $isAjax);
        }
    }

    // ACCIONES GET
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {

        // 3. ELIMINAR ENLACE
        if ($action === 'eliminar') {
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) {
                responder(false, 'ID de enlace inválido.', [], $isAjax);
            }

            $stmtName = $db->prepare("SELECT proveedor FROM proveedores_internet WHERE id = ?");
            $stmtName->execute([$id]);
            $ispName = $stmtName->fetchColumn() ?: 'Enlace';

            $stmtDel = $db->prepare("DELETE FROM proveedores_internet WHERE id = ?");
            $stmtDel->execute([$id]);

            responder(true, "El enlace de '$ispName' fue eliminado exitosamente.", [], $isAjax);
        }

        // 4. OBTENER DATOS DE UN ENLACE EN JSON (PARA EDICIÓN / DETALLE)
        if ($action === 'get') {
            $id = (int)($_GET['id'] ?? 0);
            $stmt = $db->prepare("SELECT * FROM proveedores_internet WHERE id = ?");
            $stmt->execute([$id]);
            $enlace = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($enlace) {
                responder(true, 'Datos recuperados', ['enlace' => $enlace], true);
            } else {
                responder(false, 'Enlace no encontrado', [], true);
            }
        }
    }

    responder(false, 'Acción no permitida.', [], $isAjax);

} catch (Exception $e) {
    responder(false, 'Error del servidor: ' . $e->getMessage(), [], $isAjax);
}
