<?php
/**
 * Controlador de Gestión de Impresoras por Sucursal
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
// 1. GUARDAR / EDITAR IMPRESORA
// ==========================================
if ($action === 'save_impresora' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $impresora_id   = !empty($_POST['impresora_id']) ? (int)$_POST['impresora_id'] : null;
    $sucursal_id    = !empty($_POST['sucursal_id']) ? (int)$_POST['sucursal_id'] : null;
    $nombre         = trim($_POST['nombre'] ?? '');
    $marca          = trim($_POST['marca'] ?? '');
    $modelo         = trim($_POST['modelo'] ?? '');
    $numero_serie   = trim($_POST['numero_serie'] ?? '');
    $tipo           = $_POST['tipo'] ?? 'Multifuncional Láser';
    $tipo_conexion  = $_POST['tipo_conexion'] ?? 'Red Cableada (LAN)';
    $ip_direccion   = trim($_POST['ip_direccion'] ?? '');
    $usuario_admin  = trim($_POST['usuario_admin'] ?? '');
    $password_admin = trim($_POST['password_admin'] ?? '');
    $ubicacion_area = trim($_POST['ubicacion_area'] ?? '');
    $estado         = $_POST['estado'] ?? 'Operativa';
    $toner_insumos  = trim($_POST['toner_insumos'] ?? '');
    $notas          = trim($_POST['notas'] ?? '');

    if (empty($sucursal_id) || empty($nombre)) {
        $_SESSION['flash_error'] = "La Sede/Sucursal y el Nombre de la Impresora son obligatorios.";
        header('Location: index.php');
        exit;
    }

    try {
        if ($impresora_id) {
            // Edición
            if (!empty($password_admin)) {
                $password_cifrado = Security::encrypt($password_admin);
                $stmt = $db->prepare("
                    UPDATE impresoras SET 
                        sucursal_id = ?, nombre = ?, marca = ?, modelo = ?, numero_serie = ?,
                        tipo = ?, tipo_conexion = ?, ip_direccion = ?, usuario_admin = ?,
                        password_cifrado = ?, ubicacion_area = ?, estado = ?, toner_insumos = ?, notas = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $sucursal_id, $nombre, $marca, $modelo, $numero_serie,
                    $tipo, $tipo_conexion, $ip_direccion ?: null, $usuario_admin ?: null,
                    $password_cifrado, $ubicacion_area ?: null, $estado, $toner_insumos ?: null, $notas ?: null,
                    $impresora_id
                ]);
            } else {
                // Conservar contraseña existente
                $stmt = $db->prepare("
                    UPDATE impresoras SET 
                        sucursal_id = ?, nombre = ?, marca = ?, modelo = ?, numero_serie = ?,
                        tipo = ?, tipo_conexion = ?, ip_direccion = ?, usuario_admin = ?,
                        ubicacion_area = ?, estado = ?, toner_insumos = ?, notas = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $sucursal_id, $nombre, $marca, $modelo, $numero_serie,
                    $tipo, $tipo_conexion, $ip_direccion ?: null, $usuario_admin ?: null,
                    $ubicacion_area ?: null, $estado, $toner_insumos ?: null, $notas ?: null,
                    $impresora_id
                ]);
            }
            $_SESSION['flash_success'] = "Impresora <b>" . htmlspecialchars($nombre) . "</b> actualizada correctamente.";
        } else {
            // Nuevo registro (usuario y contraseña son opcionales)
            $password_cifrado = !empty($password_admin) ? Security::encrypt($password_admin) : null;
            $stmt = $db->prepare("
                INSERT INTO impresoras (
                    sucursal_id, nombre, marca, modelo, numero_serie,
                    tipo, tipo_conexion, ip_direccion, usuario_admin,
                    password_cifrado, ubicacion_area, estado, toner_insumos, notas
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $sucursal_id, $nombre, $marca ?: null, $modelo ?: null, $numero_serie ?: null,
                $tipo, $tipo_conexion, $ip_direccion ?: null, $usuario_admin ?: null,
                $password_cifrado, $ubicacion_area ?: null, $estado, $toner_insumos ?: null, $notas ?: null
            ]);
            $_SESSION['flash_success'] = "Impresora <b>" . htmlspecialchars($nombre) . "</b> registrada exitosamente.";
        }
    } catch (PDOException $e) {
        $_SESSION['flash_error'] = "Error en base de datos: " . $e->getMessage();
    }

    header('Location: index.php');
    exit;
}

// ==========================================
// 2. ELIMINAR IMPRESORA
// ==========================================
if ($action === 'delete_impresora' && isset($_GET['id'])) {
    $impresora_id = (int)$_GET['id'];
    try {
        $stmt = $db->prepare("DELETE FROM impresoras WHERE id = ?");
        $stmt->execute([$impresora_id]);
        $_SESSION['flash_success'] = "Impresora eliminada correctamente del registro.";
    } catch (PDOException $e) {
        $_SESSION['flash_error'] = "Error al eliminar impresora: " . $e->getMessage();
    }
    header('Location: index.php');
    exit;
}

header('Location: index.php');
exit;
