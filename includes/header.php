<?php
/**
 * Cabecera y Barra de Navegación Lateral Rediseñada
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

$uri = $_SERVER['PHP_SELF'];
$current_page = basename($uri);
$current_tab = $_GET['tab'] ?? '';

// Definición estructurada del menú organizada por secciones temáticas coherentes
$menu_sections = [
    [
        'header' => 'Principal',
        'items'  => [
            'dashboard' => [
                'titulo' => 'Dashboard General',
                'label'  => 'Dashboard',
                'url'    => BASE_URL . 'index.php',
                'icono'  => 'fa-solid fa-chart-pie',
                'active' => ($current_page === 'index.php' && strpos($uri, '/modules/') === false)
            ],
        ]
    ],
    [
        'header' => 'Equipos & Activos',
        'items'  => [
            'equipos' => [
                'titulo' => 'Inventario de Equipos',
                'label'  => 'Inventario de Equipos',
                'url'    => BASE_URL . 'modules/equipos/index.php',
                'icono'  => 'fa-solid fa-laptop',
                'active' => (strpos($uri, '/equipos/') !== false)
            ],
            'entregas' => [
                'titulo' => 'Recepción y Entregas de Equipos',
                'label'  => 'Recepción y Entregas',
                'url'    => BASE_URL . 'modules/entregas/index.php',
                'icono'  => 'fa-solid fa-file-signature',
                'active' => (strpos($uri, '/entregas/') !== false)
            ],
        ]
    ],
    [
        'header' => 'Redes & Conectividad',
        'items'  => [
            'internet' => [
                'titulo' => 'Proveedores de Internet & Enlaces WAN',
                'label'  => 'Proveedores de Internet',
                'url'    => BASE_URL . 'modules/internet/index.php',
                'icono'  => 'fa-solid fa-globe',
                'active' => (strpos($uri, '/internet/') !== false)
            ],
            'infraestructura' => [
                'titulo'   => 'Infraestructura & Redes IP',
                'label'    => 'Infraestructura & Redes',
                'url'      => BASE_URL . 'modules/infraestructura/index.php?tab=equipos_red',
                'icono'    => 'fa-solid fa-network-wired',
                'active'   => (strpos($uri, '/infraestructura/') !== false && in_array($current_tab, ['equipos_red', 'matriz_ip', ''])),
                'subitems' => [
                    'equipos_red' => [
                        'titulo' => 'Dispositivos IP y Servidores',
                        'label'  => 'Switches, APs y Servers',
                        'url'    => BASE_URL . 'modules/infraestructura/index.php?tab=equipos_red',
                        'icono'  => 'fa-solid fa-server',
                        'active' => (strpos($uri, '/infraestructura/') !== false && ($current_tab === 'equipos_red' || empty($current_tab)))
                    ],
                    'matriz_ip' => [
                        'titulo' => 'Matriz de Subredes & IPs',
                        'label'  => 'Subredes & Direcciones IP',
                        'url'    => BASE_URL . 'modules/infraestructura/index.php?tab=matriz_ip',
                        'icono'  => 'fa-solid fa-sitemap',
                        'active' => (strpos($uri, '/infraestructura/') !== false && $current_tab === 'matriz_ip')
                    ],
                ]
            ],
        ]
    ],
    [
        'header' => 'Personal & Cuentas',
        'items'  => [
            'empleados' => [
                'titulo' => 'Directorio de Colaboradores',
                'label'  => 'Colaboradores',
                'url'    => BASE_URL . 'modules/empleados/index.php',
                'icono'  => 'fa-solid fa-users',
                'active' => (strpos($uri, '/empleados/') !== false)
            ],
            'credenciales' => [
                'titulo'   => 'Cuentas de Colaboradores',
                'label'    => 'Cuentas de Colaboradores',
                'url'      => BASE_URL . 'modules/credenciales/index.php?tab=fichas',
                'icono'    => 'fa-solid fa-id-badge',
                'active'   => (strpos($uri, '/credenciales/') !== false),
                'subitems' => [
                    'fichas' => [
                        'titulo' => 'Fichas Consolidadas',
                        'label'  => 'Fichas Consolidadas',
                        'url'    => BASE_URL . 'modules/credenciales/index.php?tab=fichas',
                        'icono'  => 'fa-solid fa-id-card',
                        'active' => (strpos($uri, '/credenciales/') !== false && ($current_tab === 'fichas' || empty($current_tab)))
                    ],
                    'm365' => [
                        'titulo' => 'Microsoft 365 & Licencias',
                        'label'  => 'Microsoft 365 & Licencias',
                        'url'    => BASE_URL . 'modules/credenciales/index.php?tab=m365',
                        'icono'  => 'fa-solid fa-cloud',
                        'active' => (strpos($uri, '/credenciales/') !== false && $current_tab === 'm365')
                    ],
                ]
            ],
        ]
    ],
    [
        'header' => 'Departamento de IT',
        'items'  => [
            'boveda' => [
                'titulo' => 'Bóveda Cifrada de Accesos IT',
                'label'  => 'Bóveda de Accesos IT',
                'url'    => BASE_URL . 'modules/infraestructura/index.php?tab=boveda',
                'icono'  => 'fa-solid fa-vault',
                'active' => (strpos($uri, '/infraestructura/') !== false && $current_tab === 'boveda')
            ],
            'equipo_it' => [
                'titulo' => 'Integrantes del Equipo de IT',
                'label'  => 'Equipo de IT',
                'url'    => BASE_URL . 'modules/infraestructura/index.php?tab=equipo_it',
                'icono'  => 'fa-solid fa-user-shield',
                'active' => ((strpos($uri, '/infraestructura/') !== false && $current_tab === 'equipo_it') || strpos($uri, '/usuarios/') !== false)
            ],
            'tareas' => [
                'titulo' => 'Gestión de Tareas IT',
                'label'  => 'Tareas IT',
                'url'    => BASE_URL . 'modules/tareas/index.php',
                'icono'  => 'fa-solid fa-list-check',
                'active' => (strpos($uri, '/tareas/') !== false)
            ],
        ]
    ],
    [
        'header' => 'Organización & Sistema',
        'items'  => [
            'sucursales' => [
                'titulo' => 'Sedes & Sucursales',
                'label'  => 'Sedes & Sucursales',
                'url'    => BASE_URL . 'modules/configuracion/sucursales.php',
                'icono'  => 'fa-solid fa-building',
                'active' => (strpos($uri, '/configuracion/sucursales.php') !== false)
            ],
            'configuracion' => [
                'titulo' => 'Configuración del Sistema',
                'label'  => 'Configuración',
                'url'    => BASE_URL . 'modules/configuracion/index.php',
                'icono'  => 'fa-solid fa-sliders',
                'active' => (strpos($uri, '/configuracion/') !== false && strpos($uri, '/configuracion/sucursales.php') === false)
            ],
        ]
    ],
];

// Determinar título de la página actual desde el ítem o sub-ítem activo
$topbar_title = 'Dashboard';
foreach ($menu_sections as $sec) {
    foreach ($sec['items'] as $item) {
        if (!empty($item['subitems'])) {
            foreach ($item['subitems'] as $subitem) {
                if ($subitem['active']) {
                    $topbar_title = $subitem['titulo'];
                    break 3;
                }
            }
        }
        if ($item['active']) {
            $topbar_title = $item['titulo'];
            break 2;
        }
    }
}

// Proteger la ruta si no hay sesión iniciada (exceptuando login.php y setup_db.php)
if (!isset($_SESSION['user_id']) && $current_page !== 'login.php' && $current_page !== 'setup_db.php') {
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}

$iniciales = 'IT';
if (isset($_SESSION['user_nombre'])) {
    $palabras = explode(' ', trim($_SESSION['user_nombre']));
    $iniciales = '';
    $iniciales .= isset($palabras[0]) ? strtoupper(substr($palabras[0], 0, 1)) : '';
    $iniciales .= isset($palabras[1]) ? strtoupper(substr($palabras[1], 0, 1)) : '';
    if (empty($iniciales)) $iniciales = 'IT';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($topbar_title) ?> - <?= htmlspecialchars($system_name) ?></title>
    
    <!-- Favicon de Alta Gama -->
    <link rel="icon" type="image/svg+xml" href="<?= BASE_URL ?>assets/images/favicon.svg">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= BASE_URL ?>assets/images/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="<?= BASE_URL ?>assets/images/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= BASE_URL ?>assets/images/apple-touch-icon.png">
    <link rel="shortcut icon" href="<?= BASE_URL ?>favicon.ico">
    <link rel="manifest" href="<?= BASE_URL ?>assets/images/site.webmanifest">
    <meta name="theme-color" content="#111c44">
    
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
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/styles.css">
    
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

    <div class="min-h-screen flex flex-col lg:flex-row w-full">

        <!-- OVERLAY MÓVIL -->
        <div id="sidebar-overlay" onclick="toggleSidebar()" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-30 hidden lg:hidden transition-opacity"></div>

        <!-- BARRA LATERAL (SIDEBAR LATERAL) -->
        <aside id="main-sidebar" class="fixed top-0 bottom-0 left-0 z-40 w-64 bg-[#111c44] text-white flex flex-col justify-between transition-transform duration-300 -translate-x-full lg:translate-x-0 lg:static shadow-2xl border-r border-slate-800/60 shrink-0">
            <div>
                <!-- LOGO Y TÍTULO DEL SISTEMA -->
                <div class="h-20 px-6 flex items-center justify-between border-b border-slate-800/80">
                    <a href="<?= BASE_URL ?>index.php" class="flex items-center gap-3 group">
                        <div class="h-10 w-10 rounded-xl bg-gradient-to-tr from-cyan-500 to-blue-600 flex items-center justify-center text-white text-xl shadow-lg shadow-cyan-500/20 shrink-0 group-hover:scale-105 transition-transform">
                            <i class="fa-solid fa-server"></i>
                        </div>
                        <div class="overflow-hidden">
                            <h1 class="font-bold text-base text-white tracking-tight leading-none truncate"><?= htmlspecialchars($system_name) ?></h1>
                            <p class="text-[9px] text-cyan-400 font-bold uppercase tracking-wider mt-1">Gestión Interna IT</p>
                        </div>
                    </a>
                    <button onclick="toggleSidebar()" class="lg:hidden text-slate-400 hover:text-white p-1 focus:outline-none">
                        <i class="fa-solid fa-xmark text-lg"></i>
                    </button>
                </div>

                <!-- MENU LATERAL DE NAVEGACIÓN -->
                <nav class="p-3 space-y-3 overflow-y-auto max-h-[calc(100vh-85px)] custom-scrollbar">
                    <?php foreach ($menu_sections as $sec): ?>
                        <div class="space-y-1">
                            <?php if (!empty($sec['header'])): ?>
                                <p class="px-3 pt-2 pb-1 text-[9px] font-extrabold text-slate-400/80 uppercase tracking-widest border-t border-slate-800/40 first:border-0 first:pt-0">
                                    <?= htmlspecialchars($sec['header']) ?>
                                </p>
                            <?php endif; ?>
                            
                            <?php foreach ($sec['items'] as $key => $item): 
                                $is_active = $item['active'];
                                $has_subitems = !empty($item['subitems']);
                            ?>
                                <?php if ($has_subitems): ?>
                                    <div class="space-y-1">
                                        <button onclick="toggleSubmenu('submenu-<?= $key ?>')" 
                                                class="w-full flex items-center justify-between px-3.5 py-2.5 rounded-xl font-medium text-xs transition duration-200 <?= $is_active ? 'bg-slate-800/80 text-cyan-300 font-bold shadow-sm' : 'text-slate-300 hover:bg-slate-800/60 hover:text-white' ?>">
                                            <div class="flex items-center gap-3">
                                                <i class="<?= $item['icono'] ?> text-sm w-4 text-center <?= $is_active ? 'text-cyan-400' : 'text-slate-400' ?>"></i>
                                                <span class="truncate"><?= htmlspecialchars($item['label']) ?></span>
                                            </div>
                                            <i class="fa-solid fa-chevron-down text-[10px] text-slate-400 transition-transform duration-200 <?= $is_active ? 'rotate-180 text-cyan-400' : '' ?>" id="arrow-submenu-<?= $key ?>"></i>
                                        </button>
                                        <div id="submenu-<?= $key ?>" class="pl-4 pr-1 space-y-1 py-1 <?= $is_active ? '' : 'hidden' ?>">
                                            <?php foreach ($item['subitems'] as $sub): 
                                                $sub_active = $sub['active'];
                                            ?>
                                                <a href="<?= $sub['url'] ?>" 
                                                   class="flex items-center gap-2.5 px-3 py-2 rounded-lg text-xs transition duration-200 <?= $sub_active ? 'bg-cyan-500/20 text-cyan-300 border-l-2 border-cyan-400 font-bold' : 'text-slate-400 hover:bg-slate-800/40 hover:text-slate-200' ?>">
                                                    <i class="<?= $sub['icono'] ?> text-xs w-4 text-center <?= $sub_active ? 'text-cyan-400' : 'text-slate-500' ?>"></i>
                                                    <span class="truncate"><?= htmlspecialchars($sub['label']) ?></span>
                                                </a>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <a href="<?= $item['url'] ?>" 
                                       class="flex items-center gap-3 px-3.5 py-2.5 rounded-xl font-medium text-xs transition duration-200 <?= $is_active ? 'bg-gradient-to-r from-cyan-500/20 to-blue-600/20 text-cyan-300 border-l-4 border-cyan-400 font-bold shadow-sm' : 'text-slate-300 hover:bg-slate-800/60 hover:text-white' ?>">
                                        <i class="<?= $item['icono'] ?> text-sm w-4 text-center <?= $is_active ? 'text-cyan-400' : 'text-slate-400' ?>"></i>
                                        <span class="truncate"><?= htmlspecialchars($item['label']) ?></span>
                                    </a>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </nav>
            </div>
        </aside>

        <!-- CONTENEDOR PRINCIPAL (TOPBAR + CONTENIDO) -->
        <div class="flex-1 flex flex-col min-w-0 min-h-screen">
            <!-- TOPBAR SUPERIOR -->
            <header class="h-20 px-6 sm:px-8 flex items-center justify-between sticky top-0 z-20 bg-white border-b border-slate-200 shadow-sm shrink-0">
                <div class="flex items-center gap-4">
                    <!-- Botón Toggle Menú Lateral para Móviles -->
                    <button onclick="toggleSidebar()" class="lg:hidden h-10 w-10 rounded-xl border border-slate-200 flex items-center justify-center text-slate-600 hover:bg-slate-100 transition shadow-sm" title="Abrir Menú">
                        <i class="fa-solid fa-bars text-lg"></i>
                    </button>

                    <!-- Título de la Sección Actual -->
                    <div>
                        <h2 class="text-base sm:text-lg font-extrabold text-slate-800 tracking-tight flex items-center gap-2">
                            <?= htmlspecialchars($topbar_title) ?>
                        </h2>
                    </div>
                </div>

                <!-- Acciones Derecha (Perfil de Usuario y Salir) -->
                <div class="flex items-center gap-3 sm:gap-4">
                    <?php if (isset($_SESSION['user_nombre'])): ?>
                        <div class="flex items-center gap-2.5 bg-slate-50 border border-slate-200/90 py-1.5 px-3 rounded-2xl shadow-xs">
                            <div class="h-8 w-8 rounded-xl bg-gradient-to-tr from-cyan-500 to-blue-600 flex items-center justify-center font-bold text-white text-xs shrink-0 shadow-sm shadow-cyan-500/20">
                                <?= $iniciales ?>
                            </div>
                            <div class="hidden sm:flex flex-col text-left">
                                <span class="text-xs font-bold text-slate-800 leading-tight"><?= htmlspecialchars($_SESSION['user_nombre']) ?></span>
                                <span class="text-[10px] text-cyan-600 font-semibold leading-tight"><?= htmlspecialchars($_SESSION['user_rol'] ?? 'Administrador') ?></span>
                            </div>
                        </div>
                    <?php endif; ?>

                    <a href="<?= BASE_URL ?>logout.php" class="h-9 px-3.5 rounded-xl bg-slate-100 hover:bg-rose-50 border border-slate-200 hover:border-rose-200 text-slate-600 hover:text-rose-600 flex items-center gap-2 transition duration-200 text-xs font-bold shadow-xs" title="Cerrar Sesión">
                        <i class="fa-solid fa-arrow-right-from-bracket text-xs"></i>
                        <span class="hidden sm:inline">Salir</span>
                    </a>
                </div>
            </header>

            <!-- ÁREA DE CONTENIDO DE LA PÁGINA -->
            <main class="flex-1 overflow-y-auto p-4 sm:p-6">
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


