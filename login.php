<?php
/**
 * Pantalla de Inicio de Sesión (Login)
 * Gestiona el acceso al panel mediante verificación hash (bcrypt)
 */

session_start();
require_once __DIR__ . '/config/conexion.php';

// Redireccionar al dashboard si la sesión ya está activa
if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = 'Por favor, completa todos los campos.';
    } else {
        try {
            $db = Database::getConexion();
            $stmt = $db->prepare("SELECT * FROM equipo_it WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user && $user['estado'] === 'Activo' && password_verify($password, $user['password_hash'])) {
                // Autenticación correcta: Inicializar variables de sesión
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_nombre'] = $user['nombre'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['user_rol'] = $user['rol'];

                header('Location: index.php');
                exit;
            } else {
                $error = 'Credenciales incorrectas o usuario inactivo.';
            }
        } catch (Exception $e) {
            $error = 'Error en el sistema de autenticación: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión - CuentasIT</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        darkBase: '#0B0F19',
                        darkCard: '#151D30',
                    }
                }
            }
        }
    </script>
    <!-- FontAwesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Estilos Personalizados -->
    <link rel="stylesheet" href="assets/css/styles.css">
</head>
<body class="bg-darkBase text-gray-100 flex items-center justify-center min-h-screen p-4 overflow-hidden relative">

    <!-- Elementos decorativos de fondo (Glows de color) -->
    <div class="absolute -left-32 -top-32 h-96 w-96 rounded-full bg-cyan-500/10 blur-[100px] pointer-events-none"></div>
    <div class="absolute -right-32 -bottom-32 h-96 w-96 rounded-full bg-blue-500/10 blur-[100px] pointer-events-none"></div>

    <div class="w-full max-w-md z-10">
        
        <!-- Logotipo / Cabecera de Login -->
        <div class="text-center mb-8">
            <div class="h-14 w-14 rounded-2xl grad-cyan-blue flex items-center justify-center text-white text-2xl shadow-xl shadow-cyan-500/20 mx-auto mb-4">
                <i class="fa-solid fa-server animate-pulse"></i>
            </div>
            <h1 class="text-2xl font-bold text-white tracking-wide text-glow-cyan">CuentasIT</h1>
            <p class="text-xs text-gray-500 font-semibold tracking-widest uppercase mt-1">Infraestructura & Credenciales</p>
        </div>

        <!-- Tarjeta de Login (Glassmorphism) -->
        <div class="glass-panel-glow rounded-3xl p-8 border border-white/5 relative overflow-hidden">
            
            <h2 class="text-lg font-semibold text-white mb-6 flex items-center gap-2">
                <i class="fa-solid fa-lock text-cyan-400"></i> Acceso Administrativo
            </h2>

            <?php if (!empty($error)): ?>
                <div class="mb-5 bg-rose-500/10 border border-rose-500/20 px-4 py-3 rounded-xl text-xs text-rose-400 flex items-center gap-2">
                    <i class="fa-solid fa-circle-exclamation text-rose-400"></i>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <form action="login.php" method="POST" class="space-y-5">
                <!-- Campo de Email -->
                <div class="space-y-2">
                    <label for="email" class="text-xs font-semibold text-gray-400 uppercase tracking-wider block">Correo Electrónico</label>
                    <div class="relative">
                        <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center text-gray-500 text-sm">
                            <i class="fa-regular fa-envelope"></i>
                        </span>
                        <input type="email" name="email" id="email" required placeholder="correo@empresa.com.ni"
                               class="w-full bg-black/30 border border-white/10 rounded-xl py-3 pl-10 pr-4 text-sm text-white focus:outline-none focus:border-cyan-400 focus:ring-1 focus:ring-cyan-400 placeholder-gray-600 transition">
                    </div>
                </div>

                <!-- Campo de Contraseña -->
                <div class="space-y-2">
                    <label for="password" class="text-xs font-semibold text-gray-400 uppercase tracking-wider block">Contraseña</label>
                    <div class="relative">
                        <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center text-gray-500 text-sm">
                            <i class="fa-solid fa-key"></i>
                        </span>
                        <input type="password" name="password" id="password" required placeholder="••••••••••••"
                               class="w-full bg-black/30 border border-white/10 rounded-xl py-3 pl-10 pr-4 text-sm text-white focus:outline-none focus:border-cyan-400 focus:ring-1 focus:ring-cyan-400 placeholder-gray-600 transition">
                    </div>
                </div>

                <!-- Botón de Envío -->
                <button type="submit" 
                        class="w-full py-3 px-4 rounded-xl grad-cyan-blue hover:opacity-90 text-white font-bold text-sm tracking-wide shadow-lg shadow-cyan-500/20 hover:shadow-cyan-500/30 transition flex items-center justify-center gap-2">
                    <span>Ingresar</span>
                    <i class="fa-solid fa-arrow-right-to-bracket"></i>
                </button>
            </form>

            <div class="mt-6 text-center border-t border-white/5 pt-4">
                <span class="text-[10px] text-gray-500 uppercase tracking-wider font-bold">Datos de Prueba del Sistema:</span>
                <div class="text-[11px] text-gray-400 font-mono mt-1 select-all">
                    dmartinez@mastertec.com.ni / AdminIT2026!
                </div>
            </div>
        </div>
        
    </div>
</body>
</html>
