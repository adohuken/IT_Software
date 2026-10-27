<?php
/**
 * Controlador de Guardado para Gestión de Sedes, Sucursales y Empresas
 * Soporta peticiones POST estándar y peticiones JSON / AJAX
 */

session_start();
require_once __DIR__ . '/../../config/conexion.php';

// Validar sesión activa
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

function responder($success, $mensaje, $data = [], $isAjax = false, $redirect = 'sucursales.php') {
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
        
        // 1. CREAR SUCURSAL
        if ($action === 'crear_sucursal') {
            $empresa_id = (int)($_POST['empresa_id'] ?? 0);
            $nombre = trim($_POST['nombre'] ?? '');
            $ubicacion = trim($_POST['ubicacion'] ?? '');
            $redirect = !empty($_POST['redirect']) ? trim($_POST['redirect']) : 'sucursales.php';

            if ($empresa_id <= 0) {
                responder(false, 'Debe seleccionar una empresa válida.', [], $isAjax, $redirect);
            }
            if (empty($nombre)) {
                responder(false, 'El nombre de la sucursal es obligatorio.', [], $isAjax, $redirect);
            }

            // Verificar si ya existe esa sucursal en esa empresa
            $stmtCheck = $db->prepare("SELECT id FROM sucursales WHERE empresa_id = ? AND LOWER(nombre) = LOWER(?) LIMIT 1");
            $stmtCheck->execute([$empresa_id, $nombre]);
            if ($stmtCheck->fetchColumn()) {
                responder(false, "La sucursal '$nombre' ya está registrada para esta empresa.", [], $isAjax, $redirect);
            }

            $stmtIns = $db->prepare("INSERT INTO sucursales (empresa_id, nombre, ubicacion) VALUES (?, ?, ?)");
            $stmtIns->execute([$empresa_id, $nombre, $ubicacion]);
            $newId = (int)$db->lastInsertId();

            // Obtener nombre de la empresa para la respuesta
            $stmtEmp = $db->prepare("SELECT nombre FROM empresas WHERE id = ?");
            $stmtEmp->execute([$empresa_id]);
            $empresa_nombre = $stmtEmp->fetchColumn() ?: '';

            responder(true, "Sucursal '$nombre' registrada exitosamente.", [
                'id' => $newId,
                'nombre' => $nombre,
                'empresa_id' => $empresa_id,
                'empresa_nombre' => $empresa_nombre,
                'ubicacion' => $ubicacion,
                'full_label' => $empresa_nombre . ' - ' . $nombre
            ], $isAjax, $redirect);
        }

        // 2. EDITAR SUCURSAL
        if ($action === 'editar_sucursal') {
            $id = (int)($_POST['id'] ?? 0);
            $empresa_id = (int)($_POST['empresa_id'] ?? 0);
            $nombre = trim($_POST['nombre'] ?? '');
            $ubicacion = trim($_POST['ubicacion'] ?? '');

            if ($id <= 0 || $empresa_id <= 0 || empty($nombre)) {
                responder(false, 'Datos incompletos para actualizar la sucursal.', [], $isAjax);
            }

            // Verificar duplicados en la misma empresa (excluyendo la actual)
            $stmtCheck = $db->prepare("SELECT id FROM sucursales WHERE empresa_id = ? AND LOWER(nombre) = LOWER(?) AND id != ? LIMIT 1");
            $stmtCheck->execute([$empresa_id, $nombre, $id]);
            if ($stmtCheck->fetchColumn()) {
                responder(false, "Ya existe otra sucursal con el nombre '$nombre' en esta empresa.", [], $isAjax);
            }

            $stmtUp = $db->prepare("UPDATE sucursales SET empresa_id = ?, nombre = ?, ubicacion = ? WHERE id = ?");
            $stmtUp->execute([$empresa_id, $nombre, $ubicacion, $id]);

            responder(true, "Sucursal '$nombre' actualizada correctamente.", [], $isAjax);
        }

        // 3. CREAR EMPRESA
        if ($action === 'crear_empresa') {
            $nombre = trim($_POST['nombre'] ?? '');
            $redirect = !empty($_POST['redirect']) ? trim($_POST['redirect']) : 'sucursales.php';

            if (empty($nombre)) {
                responder(false, 'El nombre de la empresa es obligatorio.', [], $isAjax, $redirect);
            }

            // Verificar si ya existe
            $stmtCheck = $db->prepare("SELECT id FROM empresas WHERE LOWER(nombre) = LOWER(?) LIMIT 1");
            $stmtCheck->execute([$nombre]);
            if ($stmtCheck->fetchColumn()) {
                responder(false, "La empresa '$nombre' ya está registrada.", [], $isAjax, $redirect);
            }

            $stmtIns = $db->prepare("INSERT INTO empresas (nombre, estado) VALUES (?, 'Activo')");
            $stmtIns->execute([$nombre]);
            $newEmpId = (int)$db->lastInsertId();

            responder(true, "Empresa '$nombre' creada exitosamente.", [
                'id' => $newEmpId,
                'nombre' => $nombre
            ], $isAjax, $redirect);
        }

        // 4. EDITAR EMPRESA
        if ($action === 'editar_empresa') {
            $id = (int)($_POST['id'] ?? 0);
            $nombre = trim($_POST['nombre'] ?? '');

            if ($id <= 0 || empty($nombre)) {
                responder(false, 'Datos incompletos para actualizar la empresa.', [], $isAjax);
            }

            $stmtUp = $db->prepare("UPDATE empresas SET nombre = ? WHERE id = ?");
            $stmtUp->execute([$nombre, $id]);

            responder(true, "Empresa '$nombre' actualizada correctamente.", [], $isAjax);
        }
    }

    // ACCIONES GET
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        
        // 5. ELIMINAR SUCURSAL
        if ($action === 'eliminar_sucursal') {
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) {
                responder(false, 'ID de sucursal inválido.', [], $isAjax);
            }

            // Validar si tiene colaboradores asignados
            $stmtColab = $db->prepare("SELECT COUNT(*) FROM empleados WHERE sucursal_id = ?");
            $stmtColab->execute([$id]);
            $cantColab = (int)$stmtColab->fetchColumn();

            // Validar si tiene equipos físicos asignados a esta sucursal
            $stmtEq = $db->prepare("SELECT COUNT(*) FROM equipos WHERE sucursal_id = ?");
            $stmtEq->execute([$id]);
            $cantEq = (int)$stmtEq->fetchColumn();

            // Validar infraestructura de red
            $stmtInfra = $db->prepare("SELECT COUNT(*) FROM infraestructura_red WHERE sucursal_id = ?");
            $stmtInfra->execute([$id]);
            $cantInfra = (int)$stmtInfra->fetchColumn();

            if ($cantColab > 0 || $cantEq > 0 || $cantInfra > 0) {
                responder(false, "No se puede eliminar la sucursal porque tiene elementos vinculados: $cantColab colaboradores, $cantEq equipos de inventario y $cantInfra dispositivos de red. Reasigna o desvincula estos elementos primero.", [], $isAjax);
            }

            // Obtener nombre antes de borrar
            $stmtName = $db->prepare("SELECT nombre FROM sucursales WHERE id = ?");
            $stmtName->execute([$id]);
            $sucName = $stmtName->fetchColumn() ?: 'Sucursal';

            $stmtDel = $db->prepare("DELETE FROM sucursales WHERE id = ?");
            $stmtDel->execute([$id]);

            responder(true, "La sucursal '$sucName' fue eliminada exitosamente.", [], $isAjax);
        }

        // 6. ELIMINAR EMPRESA
        if ($action === 'eliminar_empresa') {
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) {
                responder(false, 'ID de empresa inválido.', [], $isAjax);
            }

            // Validar si tiene sucursales
            $stmtSuc = $db->prepare("SELECT COUNT(*) FROM sucursales WHERE empresa_id = ?");
            $stmtSuc->execute([$id]);
            $cantSuc = (int)$stmtSuc->fetchColumn();

            if ($cantSuc > 0) {
                responder(false, "No se puede eliminar la empresa porque tiene $cantSuc sucursales asociadas. Elimina o traslada sus sucursales primero.", [], $isAjax);
            }

            $stmtDel = $db->prepare("DELETE FROM empresas WHERE id = ?");
            $stmtDel->execute([$id]);

            responder(true, "Empresa eliminada exitosamente.", [], $isAjax);
        }
    }

    responder(false, 'Acción no reconocida.', [], $isAjax);

} catch (Exception $e) {
    responder(false, 'Error del servidor: ' . $e->getMessage(), [], $isAjax);
}
