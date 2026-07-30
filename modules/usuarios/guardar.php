<?php
session_start();
require_once __DIR__ . '/../../config/conexion.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = !empty($_POST['user_id']) ? (int)$_POST['user_id'] : null;
    $nombre = trim($_POST['nombre'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $rol = $_POST['rol'] ?? 'Soporte';
    $estado = $_POST['estado'] ?? 'Activo';

    if (empty($nombre) || empty($email)) {
        $_SESSION['flash_error'] = "Por favor, completa los campos obligatorios (nombre y correo).";
        header('Location: index.php');
        exit;
    }

    try {
        $db = Database::getConexion();
        
        if ($user_id) {
            // MODO EDICIÓN
            $stmt_old = $db->prepare("SELECT nombre, email FROM equipo_it WHERE id = ?");
            $stmt_old->execute([$user_id]);
            $old_user = $stmt_old->fetch();
            if ($old_user) {
                $isMainAdmin = ($user_id == 1 || strtolower(trim($old_user['nombre'])) === 'administrador it' || $old_user['email'] === 'admin@mastertec.com.ni' || $old_user['email'] === 'test@empresa.com.ni');
                if ($isMainAdmin) {
                    $_SESSION['flash_error'] = "El usuario Administrador IT principal está protegido y no se puede editar.";
                    header('Location: index.php');
                    exit;
                }
            }

            $stmt_check = $db->prepare("SELECT id FROM equipo_it WHERE email = ? AND id != ? LIMIT 1");
            $stmt_check->execute([$email, $user_id]);
            if ($stmt_check->fetch()) {
                $_SESSION['flash_error'] = "El correo electrónico '$email' ya pertenece a otro usuario.";
                header('Location: index.php');
                exit;
            }

            if (!empty($password)) {
                $password_hash = password_hash($password, PASSWORD_BCRYPT);
                $stmt = $db->prepare("UPDATE equipo_it SET nombre = ?, email = ?, password_hash = ?, rol = ?, estado = ? WHERE id = ?");
                $stmt->execute([$nombre, $email, $password_hash, $rol, $estado, $user_id]);
            } else {
                $stmt = $db->prepare("UPDATE equipo_it SET nombre = ?, email = ?, rol = ?, estado = ? WHERE id = ?");
                $stmt->execute([$nombre, $email, $rol, $estado, $user_id]);
            }

            if ($user_id == $_SESSION['user_id']) {
                $_SESSION['user_nombre'] = $nombre;
                $_SESSION['user_email'] = $email;
                $_SESSION['user_rol'] = $rol;
            }

            $_SESSION['flash_success'] = "¡El usuario <b>" . htmlspecialchars($nombre) . "</b> ha sido actualizado con éxito!";

        } else {
            // MODO CREACIÓN
            if (empty($password)) {
                $_SESSION['flash_error'] = "La contraseña es requerida para nuevos usuarios.";
                header('Location: index.php');
                exit;
            }

            $stmt_check = $db->prepare("SELECT id FROM equipo_it WHERE email = ? LIMIT 1");
            $stmt_check->execute([$email]);
            if ($stmt_check->fetch()) {
                $_SESSION['flash_error'] = "El correo electrónico ya está registrado por otro usuario.";
                header('Location: index.php');
                exit;
            }

            $password_hash = password_hash($password, PASSWORD_BCRYPT);

            $stmt = $db->prepare("INSERT INTO equipo_it (nombre, email, password_hash, rol, estado) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$nombre, $email, $password_hash, $rol, $estado]);

            $_SESSION['flash_success'] = "¡El usuario <b>" . htmlspecialchars($nombre) . "</b> ha sido registrado correctamente!";
        }

    } catch (PDOException $e) {
        $_SESSION['flash_error'] = "Error de base de datos: " . $e->getMessage();
    }
}

header('Location: index.php');
exit;

