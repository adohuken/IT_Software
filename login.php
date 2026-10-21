<?php
/**
 * Pantalla de Inicio de Sesión (Login)
 * Gestiona el acceso al panel mediante verificación hash (bcrypt)
 */

session_start();
require_once __DIR__ . '/config/conexion.php';

$sys_config_file = __DIR__ . '/config/system_config.php';
$system_name = 'CuentasIT';
if (file_exists($sys_config_file)) {
    $sys_cfg = include $sys_config_file;
    $system_name = $sys_cfg['system_name'] ?? 'CuentasIT';
}

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
    <title>Iniciar Sesión - <?= htmlspecialchars($system_name) ?></title>
    
    <!-- Favicon de Alta Gama -->
    <link rel="icon" type="image/svg+xml" href="<?= BASE_URL ?>assets/images/favicon.svg">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= BASE_URL ?>assets/images/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="<?= BASE_URL ?>assets/images/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= BASE_URL ?>assets/images/apple-touch-icon.png">
    <link rel="shortcut icon" href="<?= BASE_URL ?>favicon.ico">
    <link rel="manifest" href="<?= BASE_URL ?>assets/images/site.webmanifest">
    <meta name="theme-color" content="#0B0F19">

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
<body class="bg-[#080b11] text-gray-100 flex items-center justify-center min-h-screen p-4 overflow-hidden relative">

    <!-- Fondo de Gradientes / Luces de Neón -->
    <div class="absolute top-1/4 left-1/4 -translate-x-1/2 -translate-y-1/2 h-[500px] w-[500px] rounded-full bg-cyan-500/10 blur-[120px] pointer-events-none"></div>
    <div class="absolute bottom-1/4 right-1/4 translate-x-1/2 translate-y-1/2 h-[500px] w-[500px] rounded-full bg-blue-500/10 blur-[120px] pointer-events-none"></div>

    <!-- Contenedor Principal Split (Moderno) -->
    <div class="w-full max-w-4xl bg-[#111827]/60 backdrop-blur-xl border border-white/10 rounded-[32px] overflow-hidden shadow-2xl flex flex-col md:flex-row min-h-[550px] z-10">
        
        <!-- Lado Izquierdo: Branding e Información de Valor (Oculto en móvil) -->
        <div class="hidden md:flex flex-1 bg-gradient-to-br from-slate-950 to-slate-900 p-12 flex flex-col justify-between relative border-r border-white/5 overflow-hidden">
            <!-- Rayo / Glow sutil interno -->
            <div class="absolute top-0 right-0 h-40 w-40 rounded-full bg-cyan-500/5 blur-[50px] pointer-events-none"></div>
            
            <!-- Logo -->
            <div class="flex items-center gap-3">
                <div class="h-10 w-10 rounded-xl bg-gradient-to-tr from-cyan-500 to-blue-600 flex items-center justify-center text-white text-lg shadow-lg shadow-cyan-500/20 shrink-0">
                    <i class="fa-solid fa-server"></i>
                </div>
                <div>
                    <h1 class="font-extrabold text-lg text-white tracking-tight leading-none"><?= htmlspecialchars($system_name) ?></h1>
                    <p class="text-[9px] text-cyan-400 font-bold uppercase tracking-wider mt-1">Portal Operativo</p>
                </div>
            </div>

            <!-- Centro: Presentación de Características -->
            <div class="space-y-6 my-8">
                <h2 class="text-3xl font-extrabold leading-tight text-white">
                    Control absoluto de <br>
                    <span class="bg-gradient-to-r from-cyan-400 to-blue-500 bg-clip-text text-transparent">infraestructura & accesos.</span>
                </h2>
                <p class="text-sm text-slate-400 leading-relaxed max-w-sm">
                    Gestiona credenciales seguras, asignación de hardware con auditoría de estado y tareas del equipo técnico en un solo entorno unificado.
                </p>

                <!-- Pequeños items premium -->
                <div class="space-y-3.5 pt-4">
                    <div class="flex items-center gap-3 text-xs text-slate-300">
                        <span class="h-5 w-5 rounded-lg bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 flex items-center justify-center shrink-0">
                            <i class="fa-solid fa-shield-halved text-[10px]"></i>
                        </span>
                        <span>Cifrado reversible AES-256 para credenciales</span>
                    </div>
                    <div class="flex items-center gap-3 text-xs text-slate-300">
                        <span class="h-5 w-5 rounded-lg bg-cyan-500/10 border border-cyan-500/20 text-cyan-400 flex items-center justify-center shrink-0">
                            <i class="fa-solid fa-clipboard-check text-[10px]"></i>
                        </span>
                        <span>Auditoría e historial de asignaciones de hardware</span>
                    </div>
                    <div class="flex items-center gap-3 text-xs text-slate-300">
                        <span class="h-5 w-5 rounded-lg bg-blue-500/10 border border-blue-500/20 text-blue-400 flex items-center justify-center shrink-0">
                            <i class="fa-solid fa-circle-nodes text-[10px]"></i>
                        </span>
                        <span>Asistente inteligente con búsqueda en tiempo real</span>
                    </div>
                </div>
            </div>

            <!-- Footer Izquierdo -->
            <div class="text-[10px] text-slate-500 font-medium">
                &copy; <?= date('Y') ?> <?= htmlspecialchars($system_name) ?> &bull; MasterTec S.A.
            </div>
        </div>

        <!-- Lado Derecho: Formulario de Login -->
        <div class="flex-1 p-10 md:p-12 flex flex-col justify-center bg-slate-900/40">
            <div>
                <!-- Logotipo en Móvil (Visible solo en pantallas pequeñas) -->
                <div class="flex md:hidden items-center gap-3 mb-6">
                    <div class="h-10 w-10 rounded-xl bg-gradient-to-tr from-cyan-500 to-blue-600 flex items-center justify-center text-white text-lg shadow-lg shadow-cyan-500/20 shrink-0">
                        <i class="fa-solid fa-server"></i>
                    </div>
                    <div>
                        <h1 class="font-extrabold text-lg text-white tracking-tight leading-none"><?= htmlspecialchars($system_name) ?></h1>
                        <p class="text-[9px] text-cyan-400 font-bold uppercase tracking-wider mt-1">Portal Operativo</p>
                    </div>
                </div>

                <!-- Titulo -->
                <h3 class="text-2xl font-bold text-white tracking-tight">Acceso Administrativo</h3>
                <p class="text-xs text-slate-400 mt-1.5 font-semibold">Introduce tus credenciales para ingresar al panel de control.</p>

                <!-- Mostrar errores si existen -->
                <?php if (!empty($error)): ?>
                    <div class="mt-5 bg-rose-500/10 border border-rose-500/20 px-4 py-3.5 rounded-2xl text-xs text-rose-400 flex items-center gap-2.5 animate-fade-in">
                        <i class="fa-solid fa-circle-exclamation text-rose-400 shrink-0 text-sm"></i>
                        <span class="font-medium"><?= htmlspecialchars($error) ?></span>
                    </div>
                <?php endif; ?>

                <form autocomplete="off" action="login.php" method="POST" class="space-y-5 mt-6">
                    <!-- Campo de Email -->
                    <div class="space-y-2">
                        <label for="email" class="text-[10px] font-bold text-slate-400 uppercase tracking-widest block">Correo Electrónico</label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 pl-4 flex items-center text-slate-500 text-base">
                                <i class="fa-regular fa-envelope"></i>
                            </span>
                            <input type="email" name="email" id="email" required placeholder="correo@empresa.com.ni"
                                   class="w-full bg-slate-950/40 border border-white/10 rounded-2xl py-3.5 pl-11 pr-4 text-sm text-white focus:outline-none focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500 placeholder-slate-650 transition duration-300">
                        </div>
                    </div>

                    <!-- Campo de Contraseña -->
                    <div class="space-y-2">
                        <div class="flex items-center justify-between">
                            <label for="password" class="text-[10px] font-bold text-slate-400 uppercase tracking-widest block">Contraseña</label>
                        </div>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 pl-4 flex items-center text-slate-500 text-base">
                                <i class="fa-solid fa-key"></i>
                            </span>
                            <input type="password" name="password" id="password" required placeholder="••••••••••••"
                                   class="w-full bg-slate-950/40 border border-white/10 rounded-2xl py-3.5 pl-11 pr-4 text-sm text-white focus:outline-none focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500 placeholder-slate-650 transition duration-300">
                        </div>
                    </div>

                    <!-- Botón de Envío -->
                    <button type="submit" 
                            class="w-full py-4 px-4 rounded-2xl bg-gradient-to-r from-cyan-500 to-blue-600 hover:opacity-95 text-slate-950 font-bold text-sm tracking-wide shadow-lg shadow-cyan-500/10 hover:shadow-cyan-500/25 transition duration-300 flex items-center justify-center gap-2 mt-8">
                        <span class="text-white font-extrabold uppercase tracking-wider text-xs">Iniciar Sesión</span>
                        <i class="fa-solid fa-arrow-right-to-bracket text-white text-xs"></i>
                    </button>
                </form>


            </div>
        </div>

    </div>

</body>
</html>
