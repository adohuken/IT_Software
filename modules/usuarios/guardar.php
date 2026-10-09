<?php
session_start();
require_once __DIR__ . '/../../config/conexion.php';
require_once __DIR__ . '/../../config/security.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}

$db = Database::getConexion();
$action = $_GET['action'] ?? '';

// 1. Eliminar Credencial Web
if ($action === 'delete_web' && isset($_GET['id'])) {
    $web_id = (int)$_GET['id'];
    try {
        $stmt = $db->prepare("DELETE FROM credenciales_web WHERE id = ?");
        $stmt->execute([$web_id]);
        $_SESSION['flash_success'] = "Credencial de portal web eliminada correctamente.";
    } catch (PDOException $e) {
        $_SESSION['flash_error'] = "Error al eliminar credencial: " . $e->getMessage();
    }
    header('Location: index.php?tab=boveda');
    exit;
}

// 2. Guardar o Editar Credencial Web en Bóveda IT
if ($action === 'save_web' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $web_id = !empty($_POST['web_id']) ? (int)$_POST['web_id'] : null;
    $plataforma = trim($_POST['plataforma'] ?? '');
    $url_acceso = trim($_POST['url_acceso'] ?? '');
    $usuario = trim($_POST['usuario'] ?? '');
    $password_plano = $_POST['password'] ?? '';
    $notas = trim($_POST['notas'] ?? '');

    if (empty($plataforma) || empty($usuario)) {
        $_SESSION['flash_error'] = "Debe especificar al menos la plataforma/portal y el usuario.";
        header('Location: index.php?tab=boveda');
        exit;
    }

    try {
        if ($web_id) {
            // Edición
            if (!empty($password_plano)) {
                $password_cifrado = Security::encrypt($password_plano);
                $stmt = $db->prepare("UPDATE credenciales_web SET plataforma = ?, url_acceso = ?, usuario = ?, password_cifrado = ?, notas = ? WHERE id = ?");
                $stmt->execute([$plataforma, $url_acceso, $usuario, $password_cifrado, $notas, $web_id]);
            } else {
                $stmt = $db->prepare("UPDATE credenciales_web SET plataforma = ?, url_acceso = ?, usuario = ?, notas = ? WHERE id = ?");
                $stmt->execute([$plataforma, $url_acceso, $usuario, $notas, $web_id]);
            }
            $_SESSION['flash_success'] = "¡Credencial de portal web <b>" . htmlspecialchars($plataforma) . "</b> actualizada!";
        } else {
            // Creación
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
        $_SESSION['flash_error'] = "Error al procesar la credencial: " . $e->getMessage();
    }

    header('Location: index.php?tab=boveda');
    exit;
}

// 3. Procesar Usuarios de IT (equipo_it)
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
        if ($user_id) {
            // MODO EDICIÓN USUARIO IT
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

            $_SESSION['flash_success'] = "¡El usuario de IT <b>" . htmlspecialchars($nombre) . "</b> ha sido actualizado!";

        } else {
            // MODO CREACIÓN USUARIO IT
            if (empty($password)) {
                $_SESSION['flash_error'] = "La contraseña es requerida para nuevos usuarios de IT.";
                header('Location: index.php');
                exit;
            }

            $stmt_check = $db->prepare("SELECT id FROM equipo_it WHERE email = ? LIMIT 1");
            $stmt_check->execute([$email]);
            if ($stmt_check->fetch()) {
                $_SESSION['flash_error'] = "El correo electrónico ya está registrado por otro usuario de IT.";
                header('Location: index.php');
                exit;
            }

            $password_hash = password_hash($password, PASSWORD_BCRYPT);

            $stmt = $db->prepare("INSERT INTO equipo_it (nombre, email, password_hash, rol, estado) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$nombre, $email, $password_hash, $rol, $estado]);

            $_SESSION['flash_success'] = "¡El nuevo usuario de IT <b>" . htmlspecialchars($nombre) . "</b> ha sido incorporado al equipo!";
        }

    } catch (PDOException $e) {
        $_SESSION['flash_error'] = "Error de base de datos: " . $e->getMessage();
    }
}

header('Location: index.php');
exit;
