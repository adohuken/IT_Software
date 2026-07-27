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

$current_page = basename($_SERVER['PHP_SELF']);

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
</head>
<body class="bg-darkBase text-slate-800 min-h-screen flex">

    <!-- BARRA LATERAL (SIDEBAR) -->
    <aside class="w-64 bg-darkNav flex flex-col justify-between shrink-0 hidden md:flex text-white sticky top-0 h-screen">
        
        <!-- Parte Superior: Branding e Identidad -->
        <div>
            <div class="p-6 flex items-center gap-3 border-b border-slate-200">
                <div class="h-10 w-10 rounded-xl bg-blue-500 flex items-center justify-center text-slate-800 text-xl shadow-lg shadow-blue-500/20">
                    <i class="fa-solid fa-chart-pie"></i>
                </div>
                <div>
                    <h1 class="font-bold text-2xl text-slate-800">Dash <span class="text-blue-400 font-normal">Pro</span></h1>
                </div>
            </div>
            
            <!-- Enlaces de Navegación Principal -->
            <nav class="p-4 space-y-1">
                
                <a href="<?= BASE_URL ?>index.php" class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-medium transition-colors <?= ($current_page == 'index.php') ? 'bg-blue-500 text-white shadow-lg shadow-blue-500/30' : 'text-slate-400 hover:text-white hover:bg-white/5' ?>">
                    <div class="w-1.5 h-1.5 rounded-sm <?= ($current_page == 'index.php') ? 'bg-white' : 'bg-gray-500' ?>"></div>
                    Dashboard
                </a>

                <a href="<?= BASE_URL ?>modules/equipos/index.php" class="flex items-center gap-3 px-4 py-3 mt-4 rounded-xl text-sm font-medium transition-colors <?= (strpos($_SERVER['PHP_SELF'], '/equipos/') !== false) ? 'bg-blue-500 text-white shadow-lg shadow-blue-500/30' : 'text-slate-400 hover:text-white hover:bg-white/5' ?>">
                    <div class="w-1.5 h-1.5 rounded-sm <?= (strpos($_SERVER['PHP_SELF'], '/equipos/') !== false) ? 'bg-white' : 'bg-gray-500' ?>"></div>
                    Equipos / Assets
                </a>
                
                <a href="<?= BASE_URL ?>modules/credenciales/index.php" class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-medium transition-colors <?= (strpos($_SERVER['PHP_SELF'], '/credenciales/') !== false && !isset($_GET['tab'])) ? 'bg-blue-500 text-white shadow-lg shadow-blue-500/30' : 'text-slate-400 hover:text-white hover:bg-white/5' ?>">
                    <div class="w-1.5 h-1.5 rounded-sm <?= (strpos($_SERVER['PHP_SELF'], '/credenciales/') !== false && !isset($_GET['tab'])) ? 'bg-white' : 'bg-gray-500' ?>"></div>
                    Credenciales
                </a>

                <a href="<?= BASE_URL ?>modules/credenciales/index.php?tab=m365" class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-medium transition-colors <?= (strpos($_SERVER['PHP_SELF'], '/credenciales/') !== false && isset($_GET['tab']) && $_GET['tab'] == 'm365') ? 'bg-blue-500 text-white shadow-lg shadow-blue-500/30' : 'text-slate-400 hover:text-white hover:bg-white/5' ?>">
                    <div class="w-1.5 h-1.5 rounded-sm <?= (strpos($_SERVER['PHP_SELF'], '/credenciales/') !== false && isset($_GET['tab']) && $_GET['tab'] == 'm365') ? 'bg-white' : 'bg-gray-500' ?>"></div>
                    Microsoft 365
                </a>

                <a href="<?= BASE_URL ?>modules/empleados/index.php" class="flex items-center gap-3 px-4 py-3 mt-4 rounded-xl text-sm font-medium transition-colors <?= (strpos($_SERVER['PHP_SELF'], '/empleados/') !== false) ? 'bg-blue-500 text-white shadow-lg shadow-blue-500/30' : 'text-slate-400 hover:text-white hover:bg-white/5' ?>">
                    <div class="w-1.5 h-1.5 rounded-sm <?= (strpos($_SERVER['PHP_SELF'], '/empleados/') !== false) ? 'bg-white' : 'bg-gray-500' ?>"></div>
                    Empleados
                </a>

                <a href="<?= BASE_URL ?>modules/tareas/index.php" class="flex items-center gap-3 px-4 py-3 mt-4 rounded-xl text-sm font-medium transition-colors <?= (strpos($_SERVER['PHP_SELF'], '/tareas/') !== false) ? 'bg-blue-500 text-white shadow-lg shadow-blue-500/30' : 'text-slate-400 hover:text-white hover:bg-white/5' ?>">
                    <div class="w-1.5 h-1.5 rounded-sm <?= (strpos($_SERVER['PHP_SELF'], '/tareas/') !== false) ? 'bg-white' : 'bg-gray-500' ?>"></div>
                    Tareas IT
                </a>

                <a href="<?= BASE_URL ?>modules/usuarios/index.php" class="flex items-center gap-3 px-4 py-3 mt-4 rounded-xl text-sm font-medium transition-colors <?= (strpos($_SERVER['PHP_SELF'], '/usuarios/') !== false) ? 'bg-blue-500 text-white shadow-lg shadow-blue-500/30' : 'text-slate-400 hover:text-white hover:bg-white/5' ?>">
                    <div class="w-1.5 h-1.5 rounded-sm <?= (strpos($_SERVER['PHP_SELF'], '/usuarios/') !== false) ? 'bg-white' : 'bg-gray-500' ?>"></div>
                    Usuarios
                </a>

                <a href="<?= BASE_URL ?>modules/configuracion/index.php" class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-medium transition-colors <?= (strpos($_SERVER['PHP_SELF'], '/configuracion/') !== false) ? 'bg-blue-500 text-white shadow-lg shadow-blue-500/30' : 'text-slate-400 hover:text-white hover:bg-white/5' ?>">
                    <div class="w-1.5 h-1.5 rounded-sm <?= (strpos($_SERVER['PHP_SELF'], '/configuracion/') !== false) ? 'bg-white' : 'bg-gray-500' ?>"></div>
                    Configuración
                </a>
            </nav>
        </div>

        <!-- Parte Inferior: Perfil del Operador IT e Indicador de Hora -->
        <div class="p-4 border-t border-slate-200 bg-slate-50">
            <?php if (isset($_SESSION['user_nombre'])): 
                // Generar iniciales
                $palabras = explode(' ', $_SESSION['user_nombre']);
                $iniciales = '';
                $iniciales .= isset($palabras[0]) ? strtoupper(substr($palabras[0], 0, 1)) : '';
                $iniciales .= isset($palabras[1]) ? strtoupper(substr($palabras[1], 0, 1)) : '';
                if (empty($iniciales)) $iniciales = 'IT';
            ?>
            <div class="flex items-center justify-between mb-3">
                <div class="flex items-center gap-2.5 overflow-hidden">
                    <div class="h-9 w-9 rounded-full bg-blue-600 border border-blue-400 flex items-center justify-center font-bold text-slate-800 text-xs shrink-0">
                        <?= $iniciales ?>
                    </div>
                    <div class="overflow-hidden whitespace-nowrap">
                        <h4 class="text-xs font-semibold text-slate-800 truncate leading-tight" title="<?= htmlspecialchars($_SESSION['user_nombre']) ?>"><?= htmlspecialchars($_SESSION['user_nombre']) ?></h4>
                        <p class="text-[10px] text-slate-400 truncate"><?= htmlspecialchars($_SESSION['user_rol']) ?></p>
                    </div>
                </div>
                <a href="<?= BASE_URL ?>logout.php" class="h-8 w-8 rounded-lg hover:bg-rose-500/10 flex items-center justify-center text-slate-400 hover:text-rose-400 transition" title="Cerrar Sesión">
                    <i class="fa-solid fa-arrow-right-from-bracket text-xs"></i>
                </a>
            </div>
            <?php endif; ?>
            <div class="bg-darkBase rounded-lg p-2 text-center border border-slate-200">
                <span class="text-[10px] text-slate-400 block uppercase font-bold tracking-wider">Hora Local (Nicaragua)</span>
                <span id="txt-reloj" class="text-xs font-semibold text-cyan-400 font-mono">--:--:--</span>
            </div>
        </div>
    </aside>

    <!-- CONTENEDOR DE CONTENIDO PRINCIPAL -->
    <div class="flex-1 flex flex-col min-w-0">
        
        <!-- CABECERA SUPERIOR (TOPBAR) -->
        <header class="h-20 px-8 flex items-center justify-between sticky top-0 z-20 bg-darkBase/90 backdrop-blur-md border-b border-slate-200">
            <!-- Izquierda: Mobile Menu Toggle y Breadcrumb/Título -->
            <div class="flex items-center gap-4">
                <button class="text-slate-400 hover:text-white md:hidden text-xl focus:outline-none">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <div class="hidden sm:block mt-2">
                    <h2 class="text-3xl font-bold text-slate-800 tracking-tight">Dashboard <span class="text-blue-500 font-medium">Pro</span></h2>
                </div>
            </div>

            <!-- Derecha: Buscador y Notificaciones -->
            <div class="flex items-center gap-5">
                <!-- Buscador simulado -->
                <div class="relative hidden lg:block">
                    <div class="absolute inset-y-0 left-0 flex items-center pl-4 pointer-events-none text-slate-400">
                        <i class="fa-solid fa-magnifying-glass"></i>
                    </div>
                    <input type="text" placeholder="Buscar..." class="bg-white border-0 text-sm rounded-full pl-10 pr-4 py-2.5 shadow-sm text-slate-600 focus:outline-none focus:ring-2 focus:ring-blue-500 w-64">
                </div>
                
                <div class="relative">
                    <button class="h-10 w-10 rounded-full bg-white flex items-center justify-center text-slate-500 hover:text-blue-500 shadow-sm transition">
                        <i class="fa-regular fa-bell text-lg"></i>
                    </button>
                    <!-- Alerta de notificaciones pendientes -->
                    <span class="absolute -top-1 -right-1 h-4 w-4 rounded-full bg-rose-500 text-white text-[9px] font-bold flex items-center justify-center border-2 border-[#f4f7fe]">3</span>
                </div>
            </div>
        </header>

        <!-- SECCIÓN DE CONTENIDO DINÁMICO -->
        <main class="flex-1 overflow-y-auto p-6">
            <?php if (!empty($_SESSION['flash_success'])): ?>
                <div class="mb-4 p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 flex items-center justify-between gap-3 text-sm">
                    <div class="flex items-center gap-3">
                        <i class="fa-solid fa-circle-check text-lg"></i>
                        <span><?= htmlspecialchars($_SESSION['flash_success']) ?></span>
                    </div>
                    <button onclick="this.parentElement.remove()" class="text-emerald-400/60 hover:text-emerald-400"><i class="fa-solid fa-xmark"></i></button>
                </div>
                <?php unset($_SESSION['flash_success']); ?>
            <?php endif; ?>

            <?php if (!empty($_SESSION['flash_error'])): ?>
                <div class="mb-4 p-4 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-400 flex items-center justify-between gap-3 text-sm">
                    <div class="flex items-center gap-3">
                        <i class="fa-solid fa-triangle-exclamation text-lg"></i>
                        <span><?= htmlspecialchars($_SESSION['flash_error']) ?></span>
                    </div>
                    <button onclick="this.parentElement.remove()" class="text-rose-400/60 hover:text-rose-400"><i class="fa-solid fa-xmark"></i></button>
                </div>
                <?php unset($_SESSION['flash_error']); ?>
            <?php endif; ?>

