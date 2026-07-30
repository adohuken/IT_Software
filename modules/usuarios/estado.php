<?php
session_start();
require_once __DIR__ . '/../../config/conexion.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $target_user_id = $_POST['user_id'] ?? null;
    $nuevo_estado = $_POST['nuevo_estado'] ?? '';

    if ($target_user_id && in_array($nuevo_estado, ['Activo', 'Inactivo'])) {
        
        // Protección: Evitar que el usuario desactive su propia cuenta
        if ($target_user_id == $_SESSION['user_id']) {
            $_SESSION['flash_error'] = "No puedes desactivar tu propia sesión activa por motivos de seguridad.";
            header('Location: index.php');
            exit;
        }

        try {
            $db = Database::getConexion();
            $stmt_check = $db->prepare("SELECT nombre, email FROM equipo_it WHERE id = ?");
            $stmt_check->execute([$target_user_id]);
            $target_user = $stmt_check->fetch();
            if ($target_user) {
                $isMainAdmin = ($target_user_id == 1 || strtolower(trim($target_user['nombre'])) === 'administrador it' || $target_user['email'] === 'admin@mastertec.com.ni' || $target_user['email'] === 'test@empresa.com.ni');
                if ($isMainAdmin) {
                    $_SESSION['flash_error'] = "El usuario Administrador IT principal está protegido y no se puede desactivar.";
                    header('Location: index.php');
                    exit;
                }
            }

            $stmt = $db->prepare("UPDATE equipo_it SET estado = ? WHERE id = ?");
            $stmt->execute([$nuevo_estado, $target_user_id]);

            $_SESSION['flash_success'] = "El estado del usuario ha sido actualizado a <b>$nuevo_estado</b>.";
        } catch (PDOException $e) {
            $_SESSION['flash_error'] = "Error al actualizar estado: " . $e->getMessage();
        }
    }
}

header('Location: index.php');
exit;
