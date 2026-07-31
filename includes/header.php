<?php
/**
 * Cabecera y Barra de Navegación Lateral
 * Carga Tailwind CSS, FontAwesome, Outfit Font y Estilos Personalizados
 */

// Iniciar sesión si no está iniciada
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Cargar la configuración de conexión y constantes
require_once __DIR__ . '/../config/conexion.php';

$sys_config_file = __DIR__ . '/../config/system_config.php';
$system_name = 'CuentasIT';
if (file_exists($sys_config_file)) {
    $sys_cfg = include $sys_config_file;
    $system_name = $sys_cfg['system_name'] ?? 'CuentasIT';
}

$current_page = basename($_SERVER['PHP_SELF']);
$is_dashboard = ($current_page === 'index.php' && strpos($_SERVER['PHP_SELF'], '/modules/') === false);

$topbar_title = 'Dashboard';
if (strpos($_SERVER['PHP_SELF'], '/credenciales/') !== false) {
    $topbar_title = 'Credenciales';
} elseif (strpos($_SERVER['PHP_SELF'], '/empleados/') !== false) {
    $topbar_title = 'Colaboradores';
} elseif (strpos($_SERVER['PHP_SELF'], '/tareas/') !== false) {
    $topbar_title = 'Tareas IT';
} elseif (strpos($_SERVER['PHP_SELF'], '/usuarios/') !== false) {
    $topbar_title = 'Usuarios IT';
} elseif (strpos($_SERVER['PHP_SELF'], '/configuracion/') !== false) {
    $topbar_title = 'Configuración';
}

// Proteger la ruta si no hay sesión iniciada (exceptuando login.php y setup_db.php)
if (!isset($_SESSION['user_id']) && $current_page !== 'login.php' && $current_page !== 'setup_db.php') {
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CuentasIT - Gestión de Infraestructura y Credenciales</title>
    
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        darkBase: '#f4f7fe',
                        darkCard: '#ffffff',
                        darkNav: '#111c44',
                    }
                }
            }
        }
    </script>
    
    <!-- FontAwesome para Iconos -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Estilos Personalizados -->
    <link rel="stylesheet" href="assets/css/styles.css">
    
    <!-- TomSelect para Dropdowns Buscables -->
    <link href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>
    <style>
        .ts-control { border-radius: 0.75rem !important; padding: 0.75rem 1rem !important; border-color: #e2e8f0 !important; background-color: #f1f5f9 !important; font-size: 0.875rem !important; color: #1e293b !important; box-shadow: none !important; }
        .ts-control.focus { border-color: #22d3ee !important; background-color: #fff !important; }
        .ts-dropdown { border-radius: 0.75rem !important; border-color: #e2e8f0 !important; margin-top: 4px; box-shadow: 0 10px 15px -3px rgb(0 0 0 / 0.1); font-size: 0.875rem !important; overflow: hidden; z-index: 10000 !important;}
        .ts-dropdown .option { padding: 0.5rem 1rem !important; }
        .ts-dropdown .option.active { background-color: #ecfeff !important; color: #0891b2 !important; }
    </style>
</head>
<body class="bg-darkBase text-slate-800 min-h-screen">

    <!-- CABECERA SUPERIOR (TOPBAR) -->
    <?php
    $iniciales = 'IT';
    if (isset($_SESSION['user_nombre'])) {
        $palabras = explode(' ', $_SESSION['user_nombre']);
        $iniciales = '';
        $iniciales .= isset($palabras[0]) ? strtoupper(substr($palabras[0], 0, 1)) : '';
        $iniciales .= isset($palabras[1]) ? strtoupper(substr($palabras[1], 0, 1)) : '';
        if (empty($iniciales)) $iniciales = 'IT';
    }
    ?>
    <div class="min-h-screen flex flex-col">
        <header class="h-20 px-8 flex items-center justify-between sticky top-0 z-20 bg-white border-b border-slate-200 shadow-sm shrink-0">
            <!-- Izquierda: Logo y Branding -->
            <div class="flex items-center gap-4">
                <!-- Branding -->
                <a href="<?= BASE_URL ?>index.php" class="flex items-center gap-3 select-none">
                    <div class="h-10 w-10 rounded-xl bg-gradient-to-tr from-cyan-500 to-blue-600 flex items-center justify-center text-white text-xl shadow-lg shadow-cyan-500/20 shrink-0">
                        <i class="fa-solid fa-server"></i>
                    </div>
                    <div>
                        <h1 class="font-bold text-base text-slate-800 tracking-tight leading-none"><?= htmlspecialchars($system_name) ?></h1>
                        <p class="text-[9px] text-slate-400 font-bold uppercase tracking-wider mt-1">Gestión Interna</p>
                    </div>
                </a>
                
                <span class="h-6 w-px bg-slate-200"></span>
                
                <!-- Título o Breadcrumb -->
                <div>
                    <h2 class="text-sm font-extrabold text-slate-700 tracking-tight"><?= $topbar_title ?></h2>
                </div>
            </div>

            <!-- Derecha: Perfil de Usuario Logueado, Notificaciones y Salir -->
            <div class="flex items-center gap-5">
                <!-- Perfil del Operador IT -->
                <?php if (isset($_SESSION['user_nombre'])): ?>
                <div class="flex items-center gap-2 border-r border-slate-200 pr-5">
                    <div class="h-9 w-9 rounded-full bg-gradient-to-tr from-cyan-500 to-blue-600 flex items-center justify-center font-bold text-white text-xs shrink-0 shadow-md">
                        <?= $iniciales ?>
                    </div>
                    <div class="hidden sm:block text-left">
                        <h4 class="text-xs font-bold text-slate-700 leading-tight"><?= htmlspecialchars($_SESSION['user_nombre']) ?></h4>
                        <p class="text-[9px] text-slate-400 font-semibold mt-0.5"><?= htmlspecialchars($_SESSION['user_rol']) ?></p>
                    </div>
                </div>
                <?php endif; ?>


                
                <a href="<?= BASE_URL ?>logout.php" class="h-10 px-4 rounded-xl bg-white border border-slate-200 text-slate-500 hover:text-rose-500 hover:border-rose-100 flex items-center gap-2 shadow-sm transition duration-200" title="Cerrar Sesión">
                    <i class="fa-solid fa-arrow-right-from-bracket text-xs"></i>
                    <span class="text-xs font-bold">Salir</span>
                </a>
            </div>
        </header>

        <!-- CONTENEDOR DE CONTENIDO PRINCIPAL -->
        <main class="flex-1 overflow-y-auto p-6">
            <?php if (!empty($_SESSION['flash_success'])): ?>
                <div class="mb-5 p-4 rounded-2xl bg-emerald-50 border border-emerald-200 text-emerald-800 flex items-center justify-between gap-3 text-sm shadow-sm animate-fade-in-up">
                    <div class="flex items-center gap-3">
                        <div class="h-8 w-8 rounded-xl bg-emerald-100 border border-emerald-200 text-emerald-700 flex items-center justify-center shrink-0">
                            <i class="fa-solid fa-circle-check text-sm"></i>
                        </div>
                        <span class="font-medium"><?= $_SESSION['flash_success'] ?></span>
                    </div>
                    <button onclick="this.parentElement.remove()" class="h-7 w-7 rounded-lg hover:bg-emerald-100 text-emerald-400 hover:text-emerald-700 flex items-center justify-center transition">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
                <?php unset($_SESSION['flash_success']); ?>
            <?php endif; ?>

            <?php if (!empty($_SESSION['flash_error'])): ?>
                <div class="mb-5 p-4 rounded-2xl bg-rose-50 border border-rose-200 text-rose-800 flex items-center justify-between gap-3 text-sm shadow-sm animate-fade-in-up">
                    <div class="flex items-center gap-3">
                        <div class="h-8 w-8 rounded-xl bg-rose-100 border border-rose-200 text-rose-700 flex items-center justify-center shrink-0">
                            <i class="fa-solid fa-triangle-exclamation text-sm"></i>
                        </div>
                        <span class="font-medium"><?= $_SESSION['flash_error'] ?></span>
                    </div>
                    <button onclick="this.parentElement.remove()" class="h-7 w-7 rounded-lg hover:bg-rose-100 text-rose-400 hover:text-rose-700 flex items-center justify-center transition">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
                <?php unset($_SESSION['flash_error']); ?>
            <?php endif; ?>

