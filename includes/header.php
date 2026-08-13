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
<body class="bg-darkBase text-slate-800 h-screen overflow-hidden flex">

    <!-- SIDEBAR PREMIUM -->
    <aside class="w-[260px] bg-darkNav text-slate-300 shadow-2xl flex flex-col shrink-0 hidden md:flex relative overflow-hidden z-30">
        <!-- Decoración trasera sutil (Glow) -->
        <div class="absolute -top-24 -left-24 w-64 h-64 bg-cyan-500/20 rounded-full blur-[80px] pointer-events-none"></div>
        <div class="absolute bottom-0 right-0 w-48 h-48 bg-blue-600/10 rounded-full blur-[60px] pointer-events-none"></div>

        <!-- Branding (Logo) -->
        <div class="h-24 flex items-center px-8 border-b border-white/5 shrink-0 relative z-10">
            <a href="<?= BASE_URL ?>index.php" class="flex items-center gap-4 select-none group">
                <div class="h-11 w-11 rounded-2xl bg-gradient-to-tr from-cyan-400 to-blue-500 flex items-center justify-center text-white text-xl shadow-[0_0_20px_rgba(6,182,212,0.4)] shrink-0 transition-transform duration-300 group-hover:scale-110 group-hover:rotate-3">
                    <i class="fa-solid fa-network-wired"></i>
                </div>
                <div>
                    <h1 class="font-extrabold text-lg text-white tracking-tight leading-none"><?= htmlspecialchars($system_name) ?></h1>
                    <p class="text-[10px] text-cyan-400 font-bold uppercase tracking-[0.2em] mt-1.5 opacity-80">Gestión Interna</p>
                </div>
            </a>
        </div>
        
        <!-- Menú de Navegación -->
        <nav class="flex-1 overflow-y-auto custom-scrollbar px-4 py-6 space-y-2 relative z-10">
            <p class="px-4 text-[10px] font-bold uppercase tracking-widest text-slate-500 mb-4 mt-2">Menú Principal</p>
            
            <a href="<?= BASE_URL ?>index.php" class="flex items-center gap-3 px-4 py-3 rounded-2xl transition-all duration-300 <?= $is_dashboard ? 'bg-gradient-to-r from-blue-600/20 to-cyan-500/10 text-white border border-white/10 shadow-inner' : 'text-slate-400 hover:bg-white/5 hover:text-white hover:translate-x-1' ?>">
                <div class="w-8 h-8 rounded-xl flex items-center justify-center <?= $is_dashboard ? 'bg-blue-500 text-white shadow-[0_0_15px_rgba(59,130,246,0.5)]' : 'bg-slate-800 text-slate-400' ?> transition-colors">
                    <i class="fa-solid fa-chart-pie text-sm"></i>
                </div>
                <span class="font-semibold text-sm">Dashboard</span>
            </a>
            
            <a href="<?= BASE_URL ?>modules/equipos/index.php" class="flex items-center gap-3 px-4 py-3 rounded-2xl transition-all duration-300 <?= (strpos($_SERVER['PHP_SELF'], '/equipos/') !== false) ? 'bg-gradient-to-r from-violet-600/20 to-fuchsia-500/10 text-white border border-white/10 shadow-inner' : 'text-slate-400 hover:bg-white/5 hover:text-white hover:translate-x-1' ?>">
                <div class="w-8 h-8 rounded-xl flex items-center justify-center <?= (strpos($_SERVER['PHP_SELF'], '/equipos/') !== false) ? 'bg-violet-500 text-white shadow-[0_0_15px_rgba(139,92,246,0.5)]' : 'bg-slate-800 text-slate-400' ?> transition-colors">
                    <i class="fa-solid fa-server text-sm"></i>
                </div>
                <span class="font-semibold text-sm">Equipos</span>
            </a>
            
            <?php 
            $is_credenciales = strpos($_SERVER['PHP_SELF'], '/credenciales/') !== false && (!isset($_GET['tab']) || $_GET['tab'] !== 'web');
            $is_cred_web = strpos($_SERVER['PHP_SELF'], '/credenciales/') !== false && isset($_GET['tab']) && $_GET['tab'] === 'web';
            ?>
            <a href="<?= BASE_URL ?>modules/credenciales/index.php" class="flex items-center gap-3 px-4 py-3 rounded-2xl transition-all duration-300 <?= $is_credenciales ? 'bg-gradient-to-r from-cyan-600/20 to-teal-500/10 text-white border border-white/10 shadow-inner' : 'text-slate-400 hover:bg-white/5 hover:text-white hover:translate-x-1' ?>">
                <div class="w-8 h-8 rounded-xl flex items-center justify-center <?= $is_credenciales ? 'bg-cyan-500 text-white shadow-[0_0_15px_rgba(6,182,212,0.5)]' : 'bg-slate-800 text-slate-400' ?> transition-colors">
                    <i class="fa-solid fa-key text-sm"></i>
                </div>
                <span class="font-semibold text-sm">Credenciales</span>
            </a>
            
            <a href="<?= BASE_URL ?>modules/credenciales/index.php?tab=web" class="flex items-center gap-3 px-4 py-3 rounded-2xl transition-all duration-300 <?= $is_cred_web ? 'bg-gradient-to-r from-sky-600/20 to-blue-500/10 text-white border border-white/10 shadow-inner' : 'text-slate-400 hover:bg-white/5 hover:text-white hover:translate-x-1' ?>">
                <div class="w-8 h-8 rounded-xl flex items-center justify-center <?= $is_cred_web ? 'bg-sky-500 text-white shadow-[0_0_15px_rgba(14,165,233,0.5)]' : 'bg-slate-800 text-slate-400' ?> transition-colors">
                    <i class="fa-solid fa-globe text-sm"></i>
                </div>
                <span class="font-semibold text-sm">Web y Portales</span>
            </a>
            
            <a href="<?= BASE_URL ?>modules/empleados/index.php" class="flex items-center gap-3 px-4 py-3 rounded-2xl transition-all duration-300 <?= (strpos($_SERVER['PHP_SELF'], '/empleados/') !== false) ? 'bg-gradient-to-r from-emerald-600/20 to-green-500/10 text-white border border-white/10 shadow-inner' : 'text-slate-400 hover:bg-white/5 hover:text-white hover:translate-x-1' ?>">
                <div class="w-8 h-8 rounded-xl flex items-center justify-center <?= (strpos($_SERVER['PHP_SELF'], '/empleados/') !== false) ? 'bg-emerald-500 text-white shadow-[0_0_15px_rgba(16,185,129,0.5)]' : 'bg-slate-800 text-slate-400' ?> transition-colors">
                    <i class="fa-solid fa-users text-sm"></i>
                </div>
                <span class="font-semibold text-sm">Colaboradores</span>
            </a>
            
            <a href="<?= BASE_URL ?>modules/tareas/index.php" class="flex items-center gap-3 px-4 py-3 rounded-2xl transition-all duration-300 <?= (strpos($_SERVER['PHP_SELF'], '/tareas/') !== false) ? 'bg-gradient-to-r from-blue-600/20 to-sky-500/10 text-white border border-white/10 shadow-inner' : 'text-slate-400 hover:bg-white/5 hover:text-white hover:translate-x-1' ?>">
                <div class="w-8 h-8 rounded-xl flex items-center justify-center <?= (strpos($_SERVER['PHP_SELF'], '/tareas/') !== false) ? 'bg-blue-500 text-white shadow-[0_0_15px_rgba(59,130,246,0.5)]' : 'bg-slate-800 text-slate-400' ?> transition-colors">
                    <i class="fa-solid fa-list-check text-sm"></i>
                </div>
                <span class="font-semibold text-sm">Tareas IT</span>
            </a>
            
            <p class="px-4 text-[10px] font-bold uppercase tracking-widest text-slate-500 mb-2 mt-6">Administración</p>
            
            <a href="<?= BASE_URL ?>modules/usuarios/index.php" class="flex items-center gap-3 px-4 py-3 rounded-2xl transition-all duration-300 <?= (strpos($_SERVER['PHP_SELF'], '/usuarios/') !== false) ? 'bg-gradient-to-r from-indigo-600/20 to-purple-500/10 text-white border border-white/10 shadow-inner' : 'text-slate-400 hover:bg-white/5 hover:text-white hover:translate-x-1' ?>">
                <div class="w-8 h-8 rounded-xl flex items-center justify-center <?= (strpos($_SERVER['PHP_SELF'], '/usuarios/') !== false) ? 'bg-indigo-500 text-white shadow-[0_0_15px_rgba(99,102,241,0.5)]' : 'bg-slate-800 text-slate-400' ?> transition-colors">
                    <i class="fa-solid fa-user-shield text-sm"></i>
                </div>
                <span class="font-semibold text-sm">Usuarios IT</span>
            </a>
            
            <a href="<?= BASE_URL ?>modules/configuracion/index.php" class="flex items-center gap-3 px-4 py-3 rounded-2xl transition-all duration-300 <?= (strpos($_SERVER['PHP_SELF'], '/configuracion/') !== false) ? 'bg-gradient-to-r from-amber-600/20 to-orange-500/10 text-white border border-white/10 shadow-inner' : 'text-slate-400 hover:bg-white/5 hover:text-white hover:translate-x-1' ?>">
                <div class="w-8 h-8 rounded-xl flex items-center justify-center <?= (strpos($_SERVER['PHP_SELF'], '/configuracion/') !== false) ? 'bg-amber-500 text-white shadow-[0_0_15px_rgba(245,158,11,0.5)]' : 'bg-slate-800 text-slate-400' ?> transition-colors">
                    <i class="fa-solid fa-sliders text-sm"></i>
                </div>
                <span class="font-semibold text-sm">Configuración</span>
            </a>
        </nav>
        
        <!-- Mini Footer Sidebar -->
        <div class="p-4 border-t border-white/5 shrink-0 relative z-10 text-center">
            <p class="text-[10px] text-slate-500">Gestión de TI &copy; <?= date('Y') ?></p>
        </div>
    </aside>

    <!-- ÁREA PRINCIPAL -->
    <div class="flex-1 flex flex-col min-w-0 bg-darkBase">
        <!-- TOPBAR -->
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
        <header class="h-20 px-8 flex items-center justify-between sticky top-0 z-20 bg-white border-b border-slate-200 shadow-sm shrink-0">
            <!-- Izquierda: Título o Breadcrumb -->
            <div class="flex items-center gap-4">
                <h2 class="text-lg font-extrabold text-slate-800 tracking-tight"><?= $topbar_title ?></h2>
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

