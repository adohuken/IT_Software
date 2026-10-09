<?php
/**
 * Procesador de datos para el módulo de Recepción y Entregas de Equipos
 */
session_start();
require_once __DIR__ . '/../../config/conexion.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}

$db = Database::getConexion();
$accion = $_POST['accion'] ?? $_GET['accion'] ?? '';

try {
    if ($accion === 'crear') {
        $equipo_tipo     = trim($_POST['equipo_tipo'] ?? 'Laptop');
        $equipo_marca    = trim($_POST['equipo_marca'] ?? '');
        $equipo_modelo   = trim($_POST['equipo_modelo'] ?? '');
        $service_tag_sn  = trim($_POST['service_tag_sn'] ?? '');
        $entregado_por   = trim($_POST['entregado_por'] ?? '');
        $receptor_final  = trim($_POST['receptor_final'] ?? '');
        $accesorios      = trim($_POST['accesorios'] ?? 'Cargador.+');
        $observaciones   = trim($_POST['observaciones'] ?? '');
        $recibido_por    = $_SESSION['user_nombre'] ?? 'Técnico IT';

        $stmt = $db->prepare("
            INSERT INTO recepciones_equipos 
            (equipo_tipo, equipo_marca, equipo_modelo, service_tag_sn, entregado_por, recibido_por, receptor_final, accesorios, observaciones, estado, fecha_ingreso)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'En Proceso', NOW())
        ");
        $stmt->execute([$equipo_tipo, $equipo_marca, $equipo_modelo, $service_tag_sn, $entregado_por, $recibido_por, $receptor_final, $accesorios, $observaciones]);

        $_SESSION['flash_success'] = 'Registro de ingreso de equipo creado con éxito.';
        header('Location: index.php');
        exit;
    }

    if ($accion === 'entregar') {
        $id              = (int)($_POST['id'] ?? 0);
        $receptor_final  = trim($_POST['receptor_final'] ?? '');
        $observaciones   = trim($_POST['observaciones'] ?? '');

        if ($id <= 0) throw new Exception("ID de registro no válido.");

        $stmt = $db->prepare("
            UPDATE recepciones_equipos 
            SET receptor_final = ?, observaciones = ?, estado = 'Entregado', fecha_entrega = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$receptor_final, $observaciones, $id]);

        $_SESSION['flash_success'] = 'Entrega registrada con éxito. Generando Constancia de Entrega...';
        // Redirigir directamente a la hoja de impresión del acta
        header('Location: imprimir_acta.php?id=' . $id);
        exit;
    }

    if ($accion === 'editar') {
        $id              = (int)($_POST['id'] ?? 0);
        $equipo_tipo     = trim($_POST['equipo_tipo'] ?? 'Laptop');
        $equipo_marca    = trim($_POST['equipo_marca'] ?? '');
        $equipo_modelo   = trim($_POST['equipo_modelo'] ?? '');
        $service_tag_sn  = trim($_POST['service_tag_sn'] ?? '');
        $entregado_por   = trim($_POST['entregado_por'] ?? '');
        $receptor_final  = trim($_POST['receptor_final'] ?? '');
        $accesorios      = trim($_POST['accesorios'] ?? '');
        $estado          = trim($_POST['estado'] ?? 'En Proceso');
        $observaciones   = trim($_POST['observaciones'] ?? '');

        if ($id <= 0) throw new Exception("ID de registro no válido.");

        $stmt = $db->prepare("
            UPDATE recepciones_equipos 
            SET equipo_tipo = ?, equipo_marca = ?, equipo_modelo = ?, service_tag_sn = ?, entregado_por = ?, receptor_final = ?, accesorios = ?, estado = ?, observaciones = ?
            WHERE id = ?
        ");
        $stmt->execute([$equipo_tipo, $equipo_marca, $equipo_modelo, $service_tag_sn, $entregado_por, $receptor_final, $accesorios, $estado, $observaciones, $id]);

        $_SESSION['flash_success'] = 'Registro actualizado correctamente.';
        header('Location: index.php');
        exit;
    }

    if ($accion === 'eliminar') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) throw new Exception("ID de registro no válido.");

        $stmt = $db->prepare("DELETE FROM recepciones_equipos WHERE id = ?");
        $stmt->execute([$id]);

        $_SESSION['flash_success'] = 'Registro eliminado correctamente.';
        header('Location: index.php');
        exit;
    }

} catch (Exception $e) {
    $_SESSION['flash_error'] = 'Error al procesar la solicitud: ' . $e->getMessage();
    header('Location: index.php');
    exit;
}
