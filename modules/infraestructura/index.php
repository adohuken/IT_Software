<?php
/**
 * Módulo de Infraestructura & Redes IP, Bóveda IT y Gestión de Personal
 * Administra switches, APs, servidores, direccionamiento IP, credenciales de administración y operadores IT.
 */

session_start();
require_once __DIR__ . '/../../config/conexion.php';
require_once __DIR__ . '/../../config/security.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}

$db = Database::getConexion();
$tab = $_GET['tab'] ?? 'equipos_red';

// 1. Cargar Sucursales y Empresas para selects
$sucursales = $db->query("
    SELECT s.id, s.nombre, e.nombre as empresa_nombre 
    FROM sucursales s 
    LEFT JOIN empresas e ON s.empresa_id = e.id 
    ORDER BY e.nombre, s.nombre
")->fetchAll(PDO::FETCH_ASSOC);

// 2. Cargar Dispositivos de Infraestructura / Red
try {
    $stmt_dev = $db->query("
        SELECT ir.*, s.nombre as sucursal_nombre, e.nombre as empresa_nombre 
        FROM infraestructura_red ir
        LEFT JOIN sucursales s ON ir.sucursal_id = s.id
        LEFT JOIN empresas e ON s.empresa_id = e.id
        ORDER BY ir.id DESC
    ");
    $dispositivos_raw = $stmt_dev->fetchAll(PDO::FETCH_ASSOC);
    
    $dispositivos = [];
    foreach ($dispositivos_raw as $dev) {
        $dev['pass_plain'] = !empty($dev['password_cifrado']) ? Security::decrypt($dev['password_cifrado']) : '';
        $dispositivos[] = $dev;
    }
} catch (Exception $e) {
    $dispositivos = [];
}

// 3. Cargar Bóveda de Accesos Web IT con relación al Integrante IT asignado
try {
    $stmt_web = $db->query("
        SELECT cw.*, 
               it.nombre AS it_nombre, 
               it.rol AS it_rol, 
               it.email AS it_email
        FROM credenciales_web cw
        LEFT JOIN equipo_it it ON cw.usuario_it_id = it.id
        ORDER BY cw.plataforma ASC
    ");
    $cred_web_raw = $stmt_web->fetchAll(PDO::FETCH_ASSOC);
    
    $cred_web = [];
    foreach ($cred_web_raw as $cw) {
        $cw['pass_plain'] = !empty($cw['password_cifrado']) ? Security::decrypt($cw['password_cifrado']) : '';
        $cred_web[] = $cw;
    }
} catch (Exception $e) {
    $cred_web = [];
}

// 4. Cargar Equipo de IT con conteo de credenciales web asignadas
try {
    $stmt_it = $db->query("
        SELECT it.*,
               (SELECT COUNT(*) FROM credenciales_web WHERE usuario_it_id = it.id) AS total_credenciales
        FROM equipo_it it 
        ORDER BY it.id ASC
    ");
    $equipo_it = $stmt_it->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $equipo_it = [];
}

// 5. Cargar Subredes & Matriz de Direccionamiento IP
try {
    $stmt_sub = $db->query("
        SELECT sub.*, 
               s.nombre AS sucursal_nombre, 
               s.ubicacion AS sucursal_ubicacion,
               e.nombre AS empresa_nombre
        FROM subredes sub
        LEFT JOIN sucursales s ON sub.sucursal_id = s.id
        LEFT JOIN empresas e ON s.empresa_id = e.id
        ORDER BY e.nombre ASC, s.nombre ASC, sub.id ASC
    ");
    $subredes_db = $stmt_sub->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $subredes_db = [];
}

// 6. Cargar equipos de cómputo con IP para mapeo holístico
try {
    $equipos_con_ip = $db->query("
        SELECT eq.id, eq.sucursal_id, 
               CONCAT(COALESCE(eq.tipo, 'Equipo'), ' ', COALESCE(eq.marca, ''), ' ', COALESCE(eq.modelo, '')) AS nombre_dispositivo,
               eq.tipo, eq.marca, eq.modelo, eq.service_tag_sn AS serie_mac, 
               eq.ip_direccion, '' AS mascara_vlan, eq.estado,
               s.nombre AS sucursal_nombre, 'equipo_computo' AS fuente
        FROM equipos eq
        LEFT JOIN sucursales s ON eq.sucursal_id = s.id
        WHERE eq.ip_direccion IS NOT NULL AND eq.ip_direccion != ''
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $equipos_con_ip = [];
}

// Funciones de cálculo CIDR y emparejamiento IPv4
if (!function_exists('calc_cidr_info')) {
    function calc_cidr_info($cidr) {
        $cidr = trim($cidr);
        if (strpos($cidr, '/') === false) {
            $cidr .= '/24';
        }
        $parts = explode('/', $cidr);
        $net = trim($parts[0]);
        $bits = isset($parts[1]) ? (int)$parts[1] : 24;
        if ($bits < 1 || $bits > 32) $bits = 24;
        
        $total_hosts = (int)pow(2, (32 - $bits));
        $usable_hosts = ($bits >= 31) ? $total_hosts : max(1, $total_hosts - 2);
        
        $mask_long = (int)(-1 << (32 - $bits));
        $mask_str = long2ip($mask_long);
        
        $ip_l = ip2long($net);
        if ($ip_l === false) {
            $ip_l = ip2long('192.168.1.0');
        }
        $net_long = $ip_l & $mask_long;
        $net_str = long2ip($net_long);
        
        $bcast_long = $net_long | (~$mask_long & 0xFFFFFFFF);
        $bcast_str = long2ip($bcast_long);
        
        $first_usable = ($bits >= 31) ? $net_str : long2ip($net_long + 1);
        $last_usable = ($bits >= 31) ? $bcast_str : long2ip($bcast_long - 1);
        
        return [
            'cidr' => $cidr,
            'network' => $net_str,
            'netmask' => $mask_str,
            'broadcast' => $bcast_str,
            'first_ip' => $first_usable,
            'last_ip' => $last_usable,
            'total_usable' => $usable_hosts,
            'mask_long' => $mask_long,
            'net_long' => $net_long
        ];
    }
}

if (!function_exists('is_ip_in_cidr')) {
    function is_ip_in_cidr($ip, $cidr_info) {
        if (empty($ip) || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return false;
        $ip_long = ip2long($ip);
        if ($ip_long === false) return false;
        return ($ip_long & $cidr_info['mask_long']) == $cidr_info['net_long'];
    }
}

$subredes_procesadas = [];
$total_subredes_activas = 0;
$total_ips_usables_global = 0;
$total_ips_ocupadas_global = 0;

foreach ($subredes_db as $sub) {
    $cidr_info = calc_cidr_info($sub['segmento_cidr']);
    
    // Buscar dispositivos con IP en este segmento
    $matched = [];
    foreach ($dispositivos as $d) {
        if (!empty($d['ip_direccion']) && is_ip_in_cidr($d['ip_direccion'], $cidr_info)) {
            $matched[] = [
                'id' => $d['id'],
                'nombre' => $d['nombre_dispositivo'],
                'tipo' => $d['tipo'],
                'ip' => $d['ip_direccion'],
                'marca' => $d['marca'] ?? '',
                'modelo' => $d['modelo'] ?? '',
                'serie_mac' => $d['serie_mac'] ?? '',
                'estado' => $d['estado'] ?? 'Operativo',
                'fuente' => 'infra'
            ];
        }
    }
    foreach ($equipos_con_ip as $eq) {
        if (!empty($eq['ip_direccion']) && is_ip_in_cidr($eq['ip_direccion'], $cidr_info)) {
            $matched[] = [
                'id' => $eq['id'],
                'nombre' => $eq['nombre_dispositivo'],
                'tipo' => $eq['tipo'],
                'ip' => $eq['ip_direccion'],
                'marca' => $eq['marca'] ?? '',
                'modelo' => $eq['modelo'] ?? '',
                'serie_mac' => $eq['serie_mac'] ?? '',
                'estado' => $eq['estado'] ?? 'Asignado',
                'fuente' => 'equipo'
            ];
        }
    }
    
    usort($matched, function($a, $b) {
        return ip2long($a['ip']) <=> ip2long($b['ip']);
    });
    
    $usadas = count($matched);
    $usables = $cidr_info['total_usable'];
    $libres = max(0, $usables - $usadas);
    $pct = $usables > 0 ? min(100, round(($usadas / $usables) * 100, 1)) : 0;
    
    $sub['cidr_info'] = $cidr_info;
    $sub['matched_devices'] = $matched;
    $sub['usadas'] = $usadas;
    $sub['total_usables'] = $usables;
    $sub['libres'] = $libres;
    $sub['pct_ocupacion'] = $pct;
    
    if (($sub['estado'] ?? 'Activa') === 'Activa') {
        $total_subredes_activas++;
        $total_ips_usables_global += $usables;
        $total_ips_ocupadas_global += $usadas;
    }
    
    $subredes_procesadas[] = $sub;
}

// Conteo de compartidas
$total_compartidas = 0;
foreach ($cred_web as $cw_item) {
    if (empty($cw_item['usuario_it_id'])) {
        $total_compartidas++;
    }
}
$filtro_user_it = $_GET['user_it'] ?? 'all';

// Métricas de Cabecera
$total_dispositivos = count($dispositivos);
$switches_aps = 0;
$servidores = 0;
foreach ($dispositivos as $d) {
    if (in_array($d['tipo'], ['Switch Managed', 'Switch Unmanaged', 'Access Point (AP)'])) $switches_aps++;
    if (in_array($d['tipo'], ['Servidor Físico', 'Servidor Virtual (VM)'])) $servidores++;
}
$total_boveda = count($cred_web);
$total_operadores_it = count($equipo_it);

$current_page = 'infraestructura';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="space-y-6 animate-fade-in-up">
    <!-- Encabezado Principal del Módulo con KPI Cards Glassmorphism -->
    <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4 bg-white/80 backdrop-blur-xl p-6 rounded-3xl border border-slate-200/80 shadow-sm">
        <div>
            <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-blue-50 border border-blue-200 text-blue-700 text-xs font-bold mb-2">
                <i class="fa-solid fa-network-wired"></i> Módulo de Redes e Infraestructura
            </div>
            <h2 class="text-2xl font-black text-slate-800 tracking-wide">
                Gestión de Infraestructura & Redes IP
            </h2>
            <p class="text-xs text-slate-500 mt-1">Control centralizado de switches, APs, servidores, direccionamiento IP, bóveda cifrada de accesos y personal IT.</p>
        </div>
        
        <div class="flex flex-wrap items-center gap-2">
            <?php if ($tab === 'equipos_red' || empty($tab)): ?>
                <button onclick="abrirModalDispositivo()" class="px-4 py-2.5 rounded-xl bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white font-bold text-xs shadow-lg shadow-blue-500/20 transition flex items-center gap-2">
                    <i class="fa-solid fa-plus-circle"></i> + Registrar Dispositivo IP / Red
                </button>
            <?php elseif ($tab === 'boveda'): ?>
                <button onclick="abrirModalBoveda()" class="px-4 py-2.5 rounded-xl bg-gradient-to-r from-cyan-600 to-teal-600 hover:from-cyan-700 hover:to-teal-700 text-white font-bold text-xs shadow-lg shadow-cyan-500/20 transition flex items-center gap-2">
                    <i class="fa-solid fa-vault"></i> + Nueva Credencial Web IT
                </button>
            <?php elseif ($tab === 'equipo_it'): ?>
                <button onclick="document.getElementById('modalNuevoUsuario').classList.remove('hidden')" class="px-4 py-2.5 rounded-xl bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-700 hover:to-indigo-700 text-white font-bold text-xs shadow-lg shadow-purple-500/20 transition flex items-center gap-2">
                    <i class="fa-solid fa-user-plus"></i> + Nuevo Integrante IT
                </button>
            <?php elseif ($tab === 'matriz_ip'): ?>
                <button onclick="abrirModalSubred()" class="px-4 py-2.5 rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-700 hover:to-teal-700 text-white font-bold text-xs shadow-lg shadow-emerald-500/20 transition flex items-center gap-2">
                    <i class="fa-solid fa-plus-circle"></i> + Nueva Subred IP
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Muestra de Mensajes Flash -->
    <?php if (isset($_SESSION['flash_success'])): ?>
        <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 px-4 py-3 rounded-2xl text-xs font-semibold flex items-center justify-between shadow-sm">
            <span class="flex items-center gap-2"><i class="fa-solid fa-circle-check text-emerald-600 text-base"></i> <?= $_SESSION['flash_success'] ?></span>
            <button onclick="this.parentElement.remove()" class="text-emerald-500 hover:text-emerald-800"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <?php unset($_SESSION['flash_success']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['flash_error'])): ?>
        <div class="bg-rose-50 border border-rose-200 text-rose-800 px-4 py-3 rounded-2xl text-xs font-semibold flex items-center justify-between shadow-sm">
            <span class="flex items-center gap-2"><i class="fa-solid fa-triangle-exclamation text-rose-600 text-base"></i> <?= $_SESSION['flash_error'] ?></span>
            <button onclick="this.parentElement.remove()" class="text-rose-500 hover:text-rose-800"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <?php unset($_SESSION['flash_error']); ?>
    <?php endif; ?>

    <!-- KPI Cards Resumen Executive -->
    <div class="grid grid-cols-2 md:grid-cols-5 gap-3.5">
        <div class="bg-white/90 backdrop-blur-xl p-4 rounded-2xl border border-slate-200/80 shadow-xs hover:shadow-md hover:-translate-y-0.5 transition-all duration-200 flex flex-col justify-between">
            <div class="flex items-center justify-between mb-2">
                <span class="w-10 h-10 rounded-xl bg-blue-500/10 text-blue-600 flex items-center justify-center text-lg shadow-2xs">
                    <i class="fa-solid fa-network-wired"></i>
                </span>
                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-blue-50 text-blue-700 border border-blue-200">En Red</span>
            </div>
            <div>
                <p class="text-2xl font-black text-slate-800 tracking-tight"><?= $total_dispositivos ?></p>
                <p class="text-xs text-slate-500 font-semibold mt-0.5">Equipos IP & Red</p>
            </div>
        </div>

        <div class="bg-white/90 backdrop-blur-xl p-4 rounded-2xl border border-slate-200/80 shadow-xs hover:shadow-md hover:-translate-y-0.5 transition-all duration-200 flex flex-col justify-between">
            <div class="flex items-center justify-between mb-2">
                <span class="w-10 h-10 rounded-xl bg-indigo-500/10 text-indigo-600 flex items-center justify-center text-lg shadow-2xs">
                    <i class="fa-solid fa-wifi"></i>
                </span>
                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-indigo-50 text-indigo-700 border border-indigo-200">Hardware</span>
            </div>
            <div>
                <p class="text-2xl font-black text-slate-800 tracking-tight"><?= $switches_aps ?></p>
                <p class="text-xs text-slate-500 font-semibold mt-0.5">Switches & APs</p>
            </div>
        </div>

        <div class="bg-white/90 backdrop-blur-xl p-4 rounded-2xl border border-slate-200/80 shadow-xs hover:shadow-md hover:-translate-y-0.5 transition-all duration-200 flex flex-col justify-between">
            <div class="flex items-center justify-between mb-2">
                <span class="w-10 h-10 rounded-xl bg-purple-500/10 text-purple-600 flex items-center justify-center text-lg shadow-2xs">
                    <i class="fa-solid fa-server"></i>
                </span>
                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-purple-50 text-purple-700 border border-purple-200">Data Center</span>
            </div>
            <div>
                <p class="text-2xl font-black text-slate-800 tracking-tight"><?= $servidores ?></p>
                <p class="text-xs text-slate-500 font-semibold mt-0.5">Servidores Físico / VM</p>
            </div>
        </div>

        <div class="bg-white/90 backdrop-blur-xl p-4 rounded-2xl border border-slate-200/80 shadow-xs hover:shadow-md hover:-translate-y-0.5 transition-all duration-200 flex flex-col justify-between">
            <div class="flex items-center justify-between mb-2">
                <span class="w-10 h-10 rounded-xl bg-cyan-500/10 text-cyan-600 flex items-center justify-center text-lg shadow-2xs">
                    <i class="fa-solid fa-vault"></i>
                </span>
                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-cyan-50 text-cyan-700 border border-cyan-200">AES-256</span>
            </div>
            <div>
                <p class="text-2xl font-black text-slate-800 tracking-tight"><?= $total_boveda ?></p>
                <p class="text-xs text-slate-500 font-semibold mt-0.5">Credenciales Bóveda</p>
            </div>
        </div>

        <div class="bg-white/90 backdrop-blur-xl p-4 rounded-2xl border border-slate-200/80 shadow-xs hover:shadow-md hover:-translate-y-0.5 transition-all duration-200 flex flex-col justify-between col-span-2 md:col-span-1">
            <div class="flex items-center justify-between mb-2">
                <span class="w-10 h-10 rounded-xl bg-emerald-500/10 text-emerald-600 flex items-center justify-center text-lg shadow-2xs">
                    <i class="fa-solid fa-user-shield"></i>
                </span>
                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">Equipo IT</span>
            </div>
            <div>
                <p class="text-2xl font-black text-slate-800 tracking-tight"><?= $total_operadores_it ?></p>
                <p class="text-xs text-slate-500 font-semibold mt-0.5">Integrantes Activos</p>
            </div>
        </div>
    </div>

    <!-- Pestañas de Navegación del Módulo Estilo Executive Tabs -->
    <div class="flex border-b border-slate-200 gap-2 text-xs font-bold overflow-x-auto pb-1">
        <a href="?tab=equipos_red" class="px-4 py-2.5 rounded-xl transition flex items-center gap-2 shrink-0 <?= ($tab === 'equipos_red' || empty($tab)) ? 'bg-blue-600 text-white shadow-md shadow-blue-500/20' : 'bg-white/60 text-slate-600 hover:bg-white hover:text-slate-800 border border-slate-200/80' ?>">
            <i class="fa-solid fa-server"></i>
            <span>Dispositivos IP & Red</span>
            <span class="px-1.5 py-0.2 rounded-full text-[10px] font-black <?= ($tab === 'equipos_red' || empty($tab)) ? 'bg-white/20 text-white' : 'bg-slate-100 text-slate-600' ?>"><?= $total_dispositivos ?></span>
        </a>
        <a href="?tab=boveda" class="px-4 py-2.5 rounded-xl transition flex items-center gap-2 shrink-0 <?= $tab === 'boveda' ? 'bg-cyan-600 text-white shadow-md shadow-cyan-500/20' : 'bg-white/60 text-slate-600 hover:bg-white hover:text-slate-800 border border-slate-200/80' ?>">
            <i class="fa-solid fa-vault"></i>
            <span>Bóveda Cifrada de Accesos IT</span>
            <span class="px-1.5 py-0.2 rounded-full text-[10px] font-black <?= $tab === 'boveda' ? 'bg-white/20 text-white' : 'bg-slate-100 text-slate-600' ?>"><?= $total_boveda ?></span>
        </a>
        <a href="?tab=equipo_it" class="px-4 py-2.5 rounded-xl transition flex items-center gap-2 shrink-0 <?= $tab === 'equipo_it' ? 'bg-purple-600 text-white shadow-md shadow-purple-500/20' : 'bg-white/60 text-slate-600 hover:bg-white hover:text-slate-800 border border-slate-200/80' ?>">
            <i class="fa-solid fa-user-shield"></i>
            <span>Integrantes de IT</span>
            <span class="px-1.5 py-0.2 rounded-full text-[10px] font-black <?= $tab === 'equipo_it' ? 'bg-white/20 text-white' : 'bg-slate-100 text-slate-600' ?>"><?= $total_operadores_it ?></span>
        </a>
        <a href="?tab=matriz_ip" class="px-4 py-2.5 rounded-xl transition flex items-center gap-2 shrink-0 <?= $tab === 'matriz_ip' ? 'bg-emerald-600 text-white shadow-md shadow-emerald-500/20' : 'bg-white/60 text-slate-600 hover:bg-white hover:text-slate-800 border border-slate-200/80' ?>">
            <i class="fa-solid fa-sitemap"></i>
            <span>Matriz de Subredes & IPs</span>
            <span class="px-1.5 py-0.2 rounded-full text-[10px] font-black <?= $tab === 'matriz_ip' ? 'bg-white/20 text-white' : 'bg-slate-100 text-slate-600' ?>"><?= count($subredes_procesadas) ?></span>
        </a>
    </div>

    <!-- TAB 1: DISPOSITIVOS IP & RED -->
    <?php if ($tab === 'equipos_red' || empty($tab)): ?>
        <div class="bg-white/80 backdrop-blur-xl rounded-3xl border border-slate-200/80 shadow-sm overflow-hidden p-6 space-y-4">
            <!-- Barra de Búsqueda y Filtros de Red -->
            <div class="flex flex-col md:flex-row gap-3 justify-between items-center">
                <div class="relative w-full md:w-80 flex items-center">
                    <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-xs pointer-events-none"></i>
                    <input type="text" id="busquedaInfra" 
                           oninput="toggleClearBtn('busquedaInfra', 'btn_clear_infra'); filtrarInfra();" 
                           onkeydown="if(event.key === 'Escape'){ limpiarBuscador('busquedaInfra', 'btn_clear_infra', filtrarInfra); }" 
                           placeholder="Buscar equipo, IP, marca, MAC, sucursal..." 
                           class="w-full pl-9 pr-9 py-2.5 rounded-xl border border-slate-200 text-xs focus:ring-2 focus:ring-blue-500 outline-none">
                    <button type="button" id="btn_clear_infra" 
                            onclick="limpiarBuscador('busquedaInfra', 'btn_clear_infra', filtrarInfra)" 
                            class="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-700 hover:bg-slate-200/60 p-1 rounded-full transition-all duration-200 hidden items-center justify-center cursor-pointer" 
                            title="Limpiar búsqueda">
                        <i class="fa-solid fa-circle-xmark text-sm"></i>
                    </button>
                </div>
                <div class="flex items-center gap-2 w-full md:w-auto">
                    <select id="filtroTipoInfra" onchange="filtrarInfra()" class="px-3 py-2.5 rounded-xl border border-slate-200 text-xs font-semibold text-slate-700 outline-none">
                        <option value="">-- Todos los Tipos --</option>
                        <option value="Switch Managed">Switch Managed</option>
                        <option value="Switch Unmanaged">Switch Unmanaged</option>
                        <option value="Access Point (AP)">Access Point (AP)</option>
                        <option value="Servidor Físico">Servidor Físico</option>
                        <option value="Servidor Virtual (VM)">Servidor Virtual (VM)</option>
                        <option value="Firewall / Router">Firewall / Router</option>
                        <option value="UPS / PDU">UPS / PDU</option>
                        <option value="Cámara / NVR IP">Cámara / NVR IP</option>
                    </select>

                    <select id="filtroSucursalInfra" onchange="filtrarInfra()" class="px-3 py-2.5 rounded-xl border border-slate-200 text-xs font-semibold text-slate-700 outline-none">
                        <option value="">-- Todas las Sucursales --</option>
                        <?php foreach ($sucursales as $suc): ?>
                            <option value="<?= htmlspecialchars($suc['nombre']) ?>"><?= htmlspecialchars($suc['empresa_nombre'] . ' - ' . $suc['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Tabla de Dispositivos IP -->
            <div class="overflow-x-auto rounded-2xl border border-slate-200/80">
                <table class="w-full text-left text-xs text-slate-600" id="tablaInfra">
                    <thead class="bg-slate-50 text-slate-700 uppercase font-black tracking-wider text-[11px] border-b border-slate-200">
                        <tr>
                            <th class="py-3 px-4">Dispositivo / Tipo</th>
                            <th class="py-3 px-4">Dirección IP & VLAN</th>
                            <th class="py-3 px-4">Sucursal / Ubicación</th>
                            <th class="py-3 px-4">Marca / Modelo / Serie</th>
                            <th class="py-3 px-4">Accesos Admin</th>
                            <th class="py-3 px-4 text-center">Estado</th>
                            <th class="py-3 px-4 text-center">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 font-medium">
                        <?php if (empty($dispositivos)): ?>
                            <tr>
                                <td colspan="7" class="py-8 text-center text-slate-400">No hay equipos de red ni dispositivos IP registrados.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($dispositivos as $d): 
                                $badgeTipo = 'bg-slate-100 text-slate-700 border-slate-200';
                                if (strpos($d['tipo'], 'Switch') !== false) $badgeTipo = 'bg-blue-50 text-blue-700 border-blue-200';
                                elseif (strpos($d['tipo'], 'Access Point') !== false) $badgeTipo = 'bg-indigo-50 text-indigo-700 border-indigo-200';
                                elseif (strpos($d['tipo'], 'Servidor') !== false) $badgeTipo = 'bg-purple-50 text-purple-700 border-purple-200';
                                elseif (strpos($d['tipo'], 'Firewall') !== false) $badgeTipo = 'bg-rose-50 text-rose-700 border-rose-200';

                                $badgeEstado = 'bg-emerald-50 text-emerald-700 border-emerald-200';
                                if ($d['estado'] === 'Mantenimiento') $badgeEstado = 'bg-amber-50 text-amber-700 border-amber-200';
                                elseif ($d['estado'] === 'Falla') $badgeEstado = 'bg-rose-50 text-rose-700 border-rose-200';
                                elseif ($d['estado'] === 'Inactivo') $badgeEstado = 'bg-slate-100 text-slate-500 border-slate-200';
                            ?>
                                <tr class="hover:bg-slate-50/80 transition fila-infra" data-tipo="<?= htmlspecialchars($d['tipo']) ?>" data-sucursal="<?= htmlspecialchars($d['sucursal_nombre'] ?? '') ?>">
                                    <!-- Dispositivo -->
                                    <td class="py-3.5 px-4 font-bold text-slate-800">
                                        <div class="flex items-center gap-2">
                                            <i class="fa-solid fa-network-wired text-blue-500"></i>
                                            <div>
                                                <p class="font-bold text-slate-900"><?= htmlspecialchars($d['nombre_dispositivo']) ?></p>
                                                <span class="inline-block mt-0.5 px-2 py-0.5 rounded-md border text-[10px] font-bold <?= $badgeTipo ?>">
                                                    <?= htmlspecialchars($d['tipo']) ?>
                                                </span>
                                            </div>
                                        </div>
                                    </td>

                                    <!-- IP & VLAN -->
                                    <td class="py-3.5 px-4">
                                        <div class="flex items-center gap-1.5">
                                            <span class="font-mono font-bold text-blue-700 bg-blue-50 px-2 py-0.5 rounded-lg border border-blue-200 inline-flex items-center gap-1">
                                                <i class="fa-solid fa-server text-[10px]"></i> <?= htmlspecialchars($d['ip_direccion']) ?>
                                            </span>
                                            <button onclick="navigator.clipboard.writeText('<?= htmlspecialchars($d['ip_direccion']) ?>')" title="Copiar IP" class="text-slate-400 hover:text-blue-600 transition">
                                                <i class="fa-regular fa-copy"></i>
                                            </button>
                                        </div>
                                        <?php if (!empty($d['mascara_vlan'])): ?>
                                            <p class="text-[11px] text-slate-500 mt-1 font-mono"><i class="fa-solid fa-tags text-[9px]"></i> <?= htmlspecialchars($d['mascara_vlan']) ?></p>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Sucursal & Ubicación -->
                                    <td class="py-3.5 px-4">
                                        <p class="font-bold text-slate-700"><i class="fa-solid fa-building text-slate-400"></i> <?= htmlspecialchars($d['sucursal_nombre'] ?? 'Managua Central') ?></p>
                                        <?php if (!empty($d['ubicacion_rack'])): ?>
                                            <p class="text-[11px] text-slate-500 mt-0.5"><i class="fa-solid fa-box text-slate-400"></i> <?= htmlspecialchars($d['ubicacion_rack']) ?></p>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Marca/Modelo/MAC -->
                                    <td class="py-3.5 px-4">
                                        <p class="font-bold text-slate-800"><?= htmlspecialchars(($d['marca'] ?? '') . ' ' . ($d['modelo'] ?? '')) ?></p>
                                        <?php if (!empty($d['serie_mac'])): ?>
                                            <p class="text-[11px] font-mono text-slate-500">S/N: <?= htmlspecialchars($d['serie_mac']) ?></p>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Credenciales Admin -->
                                    <td class="py-3.5 px-4">
                                        <?php if (!empty($d['usuario_admin']) || !empty($d['pass_plain'])): ?>
                                            <div class="text-[11px] space-y-0.5">
                                                <p><span class="text-slate-400">User:</span> <strong class="text-slate-700"><?= htmlspecialchars($d['usuario_admin']) ?></strong></p>
                                                <?php if (!empty($d['pass_plain'])): ?>
                                                    <div class="flex items-center gap-1">
                                                        <span class="text-slate-400">Pass:</span>
                                                        <span id="pass_dev_<?= $d['id'] ?>" class="font-mono text-slate-800 font-bold hidden"><?= htmlspecialchars($d['pass_plain']) ?></span>
                                                        <span id="pass_mask_<?= $d['id'] ?>" class="font-mono text-slate-400">••••••••</span>
                                                        <button onclick="togglePass('pass_dev_<?= $d['id'] ?>', 'pass_mask_<?= $d['id'] ?>')" class="text-slate-400 hover:text-blue-600 text-[10px] ml-1">
                                                            <i class="fa-solid fa-eye"></i>
                                                        </button>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-slate-400 text-[11px] italic">Sin credenciales</span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Estado -->
                                    <td class="py-3.5 px-4 text-center">
                                        <span class="px-2.5 py-1 rounded-full border text-[11px] font-bold <?= $badgeEstado ?>">
                                            ● <?= htmlspecialchars($d['estado']) ?>
                                        </span>
                                    </td>

                                    <!-- Acciones -->
                                    <td class="py-3.5 px-4 text-center">
                                        <div class="flex items-center justify-center gap-2">
                                            <?php if (!empty($d['url_gestion'])): ?>
                                                <a href="<?= htmlspecialchars($d['url_gestion']) ?>" target="_blank" title="Abrir Web UI" class="p-1.5 rounded-lg bg-blue-50 text-blue-600 hover:bg-blue-600 hover:text-white transition">
                                                    <i class="fa-solid fa-arrow-up-right-from-square"></i>
                                                </a>
                                            <?php endif; ?>
                                            <button onclick='editarDispositivo(<?= json_encode($d, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' title="Editar Dispositivo" class="p-1.5 rounded-lg bg-slate-100 text-slate-600 hover:bg-slate-200 transition">
                                                <i class="fa-solid fa-pen"></i>
                                            </button>
                                            <a href="guardar.php?action=delete_dispositivo&id=<?= $d['id'] ?>" onclick="return confirm('¿Seguro que deseas eliminar este equipo de infraestructura?')" title="Eliminar" class="p-1.5 rounded-lg bg-rose-50 text-rose-600 hover:bg-rose-600 hover:text-white transition">
                                                <i class="fa-solid fa-trash"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <!-- Paginación de Dispositivos IP & Red -->
            <div id="paginacionInfra"></div>
        </div>
    <?php endif; ?>

    <!-- TAB 2: BÓVEDA CIFRADA DE ACCESOS IT -->
    <?php if ($tab === 'boveda'): ?>
        <div class="bg-white/90 backdrop-blur-xl rounded-3xl border border-slate-200/80 shadow-sm p-6 space-y-5">
            <!-- Selector de Perfiles Corporativos de IT -->
            <div class="p-5 rounded-2xl bg-gradient-to-br from-slate-900 via-slate-800 to-indigo-950 text-white shadow-xl space-y-4 relative overflow-hidden">
                <!-- Efectos de iluminación sutiles de fondo -->
                <div class="absolute -top-24 -right-24 w-80 h-80 bg-cyan-500/10 rounded-full blur-3xl pointer-events-none"></div>
                <div class="absolute -bottom-24 -left-24 w-80 h-80 bg-purple-500/10 rounded-full blur-3xl pointer-events-none"></div>

                <div class="flex flex-col md:flex-row md:items-center justify-between gap-3 relative z-10">
                    <div class="flex items-center gap-3">
                        <div class="w-11 h-11 rounded-2xl bg-gradient-to-tr from-cyan-500 to-blue-600 flex items-center justify-center text-white text-lg shadow-lg shadow-cyan-500/30 shrink-0">
                            <i class="fa-solid fa-id-badge"></i>
                        </div>
                        <div>
                            <div class="flex items-center gap-2 flex-wrap">
                                <h3 class="text-sm font-black tracking-wide text-white uppercase">Perfiles de Integrantes IT & Credenciales</h3>
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-cyan-500/20 text-cyan-300 border border-cyan-500/30">
                                    <i class="fa-solid fa-shield-cat text-[9px] mr-1"></i> Bóveda Segura AES-256
                                </span>
                            </div>
                            <p class="text-xs text-slate-300 mt-0.5">Selecciona el perfil de cada integrante para auditar sus cuentas asignadas de forma aislada, o consulta los accesos de uso común.</p>
                        </div>
                    </div>
                </div>

                <!-- Grid de Perfiles de Integrantes IT -->
                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-2.5 pt-1 relative z-10" id="perfilesBovedaContainer">
                    <!-- Todas las Credenciales -->
                    <button type="button" onclick="seleccionarPerfilBoveda('all')" data-perfil-btn="all"
                            class="perfil-card text-left p-3 rounded-xl border transition-all duration-200 cursor-pointer flex flex-col justify-between gap-2.5 bg-white/10 border-white/20 hover:bg-white/15 text-white group">
                        <div class="flex items-center justify-between">
                            <div class="w-7 h-7 rounded-lg bg-white/10 flex items-center justify-center text-xs text-cyan-300 group-hover:scale-105 transition-transform">
                                <i class="fa-solid fa-layer-group"></i>
                            </div>
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-black bg-white/20 text-white"><?= $total_boveda ?></span>
                        </div>
                        <div>
                            <p class="text-xs font-bold text-white truncate">Todas</p>
                            <p class="text-[10px] text-slate-300 truncate">Total Bóveda</p>
                        </div>
                    </button>

                    <!-- Integrantes individuales -->
                    <?php foreach ($equipo_it as $it_user): 
                        $pal = explode(' ', $it_user['nombre']);
                        $ini = strtoupper(substr($pal[0] ?? 'U', 0, 1) . substr($pal[1] ?? '', 0, 1));
                        $primerNombre = $pal[0] . (isset($pal[1]) ? ' ' . substr($pal[1], 0, 1) . '.' : '');
                        
                        $avatarGrad = 'from-blue-600 to-cyan-500 shadow-blue-500/20';
                        if ($it_user['rol'] === 'Desarrollo') $avatarGrad = 'from-purple-600 to-indigo-500 shadow-purple-500/20';
                        elseif ($it_user['rol'] === 'Soporte') $avatarGrad = 'from-emerald-600 to-teal-500 shadow-emerald-500/20';
                    ?>
                        <button type="button" onclick="seleccionarPerfilBoveda('<?= $it_user['id'] ?>')" data-perfil-btn="<?= $it_user['id'] ?>"
                                class="perfil-card text-left p-3 rounded-xl border transition-all duration-200 cursor-pointer flex flex-col justify-between gap-2.5 bg-slate-800/80 border-slate-700/80 hover:bg-slate-700/80 text-white group">
                            <div class="flex items-center justify-between">
                                <div class="w-7 h-7 rounded-lg bg-gradient-to-tr <?= $avatarGrad ?> flex items-center justify-center text-[10px] font-black text-white shadow-xs group-hover:scale-105 transition-transform">
                                    <?= $ini ?>
                                </div>
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-black <?= $it_user['total_credenciales'] > 0 ? 'bg-cyan-500/20 text-cyan-300 border border-cyan-500/30' : 'bg-slate-700 text-slate-400' ?>">
                                    <?= $it_user['total_credenciales'] ?>
                                </span>
                            </div>
                            <div>
                                <p class="text-xs font-bold text-white truncate" title="<?= htmlspecialchars($it_user['nombre']) ?>"><?= htmlspecialchars($primerNombre) ?></p>
                                <p class="text-[10px] text-slate-300 truncate"><?= htmlspecialchars($it_user['rol']) ?></p>
                            </div>
                        </button>
                    <?php endforeach; ?>

                    <!-- Compartidas del Equipo -->
                    <button type="button" onclick="seleccionarPerfilBoveda('shared')" data-perfil-btn="shared"
                            class="perfil-card text-left p-3 rounded-xl border transition-all duration-200 cursor-pointer flex flex-col justify-between gap-2.5 bg-slate-800/80 border-slate-700/80 hover:bg-slate-700/80 text-white group">
                        <div class="flex items-center justify-between">
                            <div class="w-7 h-7 rounded-lg bg-gradient-to-tr from-amber-500 to-orange-500 flex items-center justify-center text-xs text-white shadow-xs group-hover:scale-105 transition-transform">
                                <i class="fa-solid fa-users"></i>
                            </div>
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-black <?= $total_compartidas > 0 ? 'bg-amber-500/20 text-amber-300 border border-amber-500/30' : 'bg-slate-700 text-slate-400' ?>"><?= $total_compartidas ?></span>
                        </div>
                        <div>
                            <p class="text-xs font-bold text-white truncate">Compartidas</p>
                            <p class="text-[10px] text-amber-300/80 truncate">Todo el Equipo</p>
                        </div>
                    </button>
                </div>
            </div>

            <!-- Banner Contextual del Perfil Activo -->
            <div id="perfilContextBanner" class="p-3.5 rounded-2xl bg-gradient-to-r from-cyan-50/80 via-white to-cyan-50/40 border border-cyan-200/80 flex flex-col sm:flex-row sm:items-center justify-between gap-3 shadow-2xs">
                <div class="flex items-center gap-3">
                    <span class="w-8 h-8 rounded-xl bg-cyan-600/10 text-cyan-600 flex items-center justify-center text-xs font-bold shrink-0">
                        <i class="fa-solid fa-filter"></i>
                    </span>
                    <div class="text-xs">
                        <span class="text-slate-500">Vista activa:</span>
                        <strong class="text-slate-900 font-bold ml-1" id="contextPerfilNombre">Todas las Credenciales de IT</strong>
                        <span class="text-slate-300 mx-2">|</span>
                        <span class="text-slate-600 font-medium" id="contextPerfilConteo">Mostrando <?= $total_boveda ?> accesos</span>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <button type="button" onclick="abrirModalBoveda()" class="px-3 py-1.5 rounded-xl bg-white hover:bg-cyan-50 text-cyan-700 font-bold text-xs border border-cyan-200 hover:border-cyan-300 transition flex items-center gap-1.5 shadow-2xs">
                        <i class="fa-solid fa-plus text-[10px]"></i> Asignar Credencial Aquí
                    </button>
                </div>
            </div>

            <!-- Cabecera de búsqueda y estado de seguridad -->
            <div class="flex flex-col md:flex-row justify-between items-center gap-3 pt-1">
                <div>
                    <h3 class="text-sm font-black text-slate-800 flex items-center gap-2">
                        <i class="fa-solid fa-lock text-cyan-600"></i> Registros en Bóveda Cifrada
                        <span class="text-[10px] font-mono px-2 py-0.5 rounded-md bg-slate-100 text-slate-600 border border-slate-200 font-bold">AES-256 GCM</span>
                    </h3>
                    <p class="text-xs text-slate-500">Copia credenciales y contraseñas de forma inmediata con 1 solo clic seguro.</p>
                </div>
                <div class="relative w-full md:w-80 flex items-center">
                    <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-xs pointer-events-none"></i>
                    <input type="text" id="searchBoveda" 
                           oninput="toggleClearBtn('searchBoveda', 'btn_clear_boveda'); filtrarBoveda();" 
                           onkeydown="if(event.key === 'Escape'){ limpiarBuscador('searchBoveda', 'btn_clear_boveda', filtrarBoveda); }" 
                           placeholder="Buscar por portal, usuario, notas o enlace..." 
                           class="w-full pl-9 pr-9 py-2.5 rounded-xl border border-slate-200 text-xs outline-none focus:ring-2 focus:ring-cyan-500 focus:border-cyan-500 bg-white shadow-2xs">
                    <button type="button" id="btn_clear_boveda" 
                            onclick="limpiarBuscador('searchBoveda', 'btn_clear_boveda', filtrarBoveda)" 
                            class="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-700 hover:bg-slate-200/60 p-1 rounded-full transition-all duration-200 hidden items-center justify-center cursor-pointer" 
                            title="Limpiar búsqueda">
                        <i class="fa-solid fa-circle-xmark text-sm"></i>
                    </button>
                </div>
            </div>

            <!-- Tabla de Bóveda Corporativa -->
            <div class="overflow-x-auto rounded-2xl border border-slate-200/90 shadow-2xs">
                <table class="w-full text-left text-xs" id="tablaBoveda">
                    <thead class="bg-slate-50/90 text-slate-700 uppercase font-black tracking-wider text-[11px] border-b border-slate-200">
                        <tr>
                            <th class="py-3.5 px-4 w-12 text-center">#</th>
                            <th class="py-3.5 px-4">Perfil / Propietario IT</th>
                            <th class="py-3.5 px-4">Plataforma / Portal</th>
                            <th class="py-3.5 px-4">Acceso Web</th>
                            <th class="py-3.5 px-4">Usuario Autorizado</th>
                            <th class="py-3.5 px-4">Contraseña Cifrada</th>
                            <th class="py-3.5 px-4">Notas / Destino</th>
                            <th class="py-3.5 px-4 text-center">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 font-medium bg-white">
                        <?php if (empty($cred_web)): ?>
                            <tr>
                                <td colspan="8" class="py-12 text-center text-slate-400">
                                    <div class="flex flex-col items-center justify-center gap-2">
                                        <div class="w-12 h-12 rounded-2xl bg-cyan-50 text-cyan-600 flex items-center justify-center text-xl">
                                            <i class="fa-solid fa-vault"></i>
                                        </div>
                                        <p class="font-bold text-slate-700 text-sm">Bóveda vacía</p>
                                        <p class="text-xs text-slate-400 max-w-sm">No se han registrado credenciales web en la bóveda de IT todavía.</p>
                                        <button onclick="abrirModalBoveda()" class="mt-2 px-4 py-2 rounded-xl bg-cyan-600 hover:bg-cyan-700 text-white font-bold text-xs shadow-md">
                                            + Registrar Primera Credencial
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php $idx=1; foreach ($cred_web as $cw): 
                                // Detección de marca / ícono para la plataforma
                                $platLower = strtolower($cw['plataforma']);
                                $platIcon = 'fa-solid fa-globe';
                                $platColor = 'bg-cyan-50 text-cyan-600 border-cyan-200';
                                if (strpos($platLower, 'meraki') !== false || strpos($platLower, 'cisco') !== false) {
                                    $platIcon = 'fa-solid fa-network-wired';
                                    $platColor = 'bg-blue-50 text-blue-600 border-blue-200';
                                } elseif (strpos($platLower, 'cloudflare') !== false) {
                                    $platIcon = 'fa-solid fa-cloud-bolt';
                                    $platColor = 'bg-amber-50 text-amber-600 border-amber-200';
                                } elseif (strpos($platLower, 'forti') !== false || strpos($platLower, 'vpn') !== false || strpos($platLower, 'firewall') !== false) {
                                    $platIcon = 'fa-solid fa-shield-halved';
                                    $platColor = 'bg-rose-50 text-rose-600 border-rose-200';
                                } elseif (strpos($platLower, 'cpanel') !== false || strpos($platLower, 'hosting') !== false || strpos($platLower, 'server') !== false) {
                                    $platIcon = 'fa-solid fa-server';
                                    $platColor = 'bg-purple-50 text-purple-600 border-purple-200';
                                } elseif (strpos($platLower, 'google') !== false || strpos($platLower, 'workspace') !== false) {
                                    $platIcon = 'fa-brands fa-google';
                                    $platColor = 'bg-red-50 text-red-600 border-red-200';
                                } elseif (strpos($platLower, 'microsoft') !== false || strpos($platLower, 'azure') !== false || strpos($platLower, 'office') !== false) {
                                    $platIcon = 'fa-brands fa-microsoft';
                                    $platColor = 'bg-sky-50 text-sky-600 border-sky-200';
                                }
                            ?>
                                <tr class="hover:bg-cyan-50/30 transition fila-boveda" data-user-it="<?= $cw['usuario_it_id'] ?? 'shared' ?>">
                                    <td class="py-3.5 px-4 font-mono font-bold text-slate-400 text-center"><?= $idx++ ?></td>
                                    
                                    <!-- Perfil / Propietario -->
                                    <td class="py-3.5 px-4">
                                        <?php if (!empty($cw['usuario_it_id']) && !empty($cw['it_nombre'])): 
                                            $pal = explode(' ', $cw['it_nombre']);
                                            $ini = strtoupper(substr($pal[0] ?? 'U', 0, 1) . substr($pal[1] ?? '', 0, 1));
                                            
                                            $roleColor = 'bg-blue-50 text-blue-700 border-blue-200';
                                            $avatarGrad = 'from-blue-600 to-cyan-500';
                                            if ($cw['it_rol'] === 'Desarrollo') {
                                                $roleColor = 'bg-purple-50 text-purple-700 border-purple-200';
                                                $avatarGrad = 'from-purple-600 to-indigo-500';
                                            } elseif ($cw['it_rol'] === 'Soporte') {
                                                $roleColor = 'bg-emerald-50 text-emerald-700 border-emerald-200';
                                                $avatarGrad = 'from-emerald-600 to-teal-500';
                                            }
                                        ?>
                                            <div class="inline-flex items-center gap-2 px-2.5 py-1.5 rounded-xl bg-slate-50 border border-slate-200/90 shadow-2xs">
                                                <span class="w-6 h-6 rounded-lg bg-gradient-to-tr <?= $avatarGrad ?> text-white text-[10px] font-black flex items-center justify-center shrink-0">
                                                    <?= $ini ?>
                                                </span>
                                                <div class="leading-tight">
                                                    <p class="font-bold text-slate-800 text-[11px] whitespace-nowrap"><?= htmlspecialchars($cw['it_nombre']) ?></p>
                                                    <span class="inline-block mt-0.5 px-1.5 py-0.2 rounded text-[9px] font-bold border <?= $roleColor ?>"><?= htmlspecialchars($cw['it_rol'] ?? 'IT') ?></span>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-xl bg-amber-50 border border-amber-200 text-amber-800 text-[11px] font-bold shadow-2xs whitespace-nowrap">
                                                <i class="fa-solid fa-users text-amber-600 text-[10px]"></i> Compartida (Equipo IT)
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Plataforma / Portal con Icono de Marca -->
                                    <td class="py-3.5 px-4 font-bold text-slate-800">
                                        <div class="flex items-center gap-2.5">
                                            <div class="w-8 h-8 rounded-xl border flex items-center justify-center text-xs shrink-0 <?= $platColor ?>">
                                                <i class="<?= $platIcon ?>"></i>
                                            </div>
                                            <div>
                                                <p class="font-bold text-slate-900 text-xs"><?= htmlspecialchars($cw['plataforma']) ?></p>
                                                <?php if (!empty($cw['url_acceso'])): 
                                                    $parsed = parse_url($cw['url_acceso']);
                                                    $host = $parsed['host'] ?? '';
                                                ?>
                                                    <p class="text-[10px] font-mono text-slate-400 mt-0.5"><?= htmlspecialchars($host) ?></p>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>

                                    <!-- Enlace / URL -->
                                    <td class="py-3.5 px-4 font-mono text-xs">
                                        <?php if (!empty($cw['url_acceso'])): 
                                            $parsedUrl = parse_url($cw['url_acceso']);
                                            $displayHost = $parsedUrl['host'] ?? substr($cw['url_acceso'], 0, 26);
                                        ?>
                                            <a href="<?= htmlspecialchars($cw['url_acceso']) ?>" target="_blank" class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-slate-50 hover:bg-cyan-50 border border-slate-200 hover:border-cyan-300 text-slate-700 hover:text-cyan-700 font-mono text-[11px] transition group" title="<?= htmlspecialchars($cw['url_acceso']) ?>">
                                                <span><?= htmlspecialchars($displayHost) ?></span>
                                                <i class="fa-solid fa-arrow-up-right-from-square text-[9px] text-slate-400 group-hover:text-cyan-600 transition-colors"></i>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-slate-400 font-mono">-</span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Usuario con 1-Click Copy -->
                                    <td class="py-3.5 px-4 font-semibold text-slate-700">
                                        <div class="inline-flex items-center gap-1.5 bg-slate-50 px-2.5 py-1 rounded-lg border border-slate-200 font-mono text-[11px] text-slate-800 shadow-2xs">
                                            <span><?= htmlspecialchars($cw['usuario']) ?></span>
                                            <button type="button" onclick="copiarAlPortapapeles('<?= htmlspecialchars($cw['usuario']) ?>', this)" title="Copiar Usuario" class="text-slate-400 hover:text-cyan-600 transition ml-0.5">
                                                <i class="fa-regular fa-copy"></i>
                                            </button>
                                        </div>
                                    </td>

                                    <!-- Contraseña Cifrada AES-256 -->
                                    <td class="py-3.5 px-4">
                                        <div class="inline-flex items-center gap-2 bg-slate-50 px-2.5 py-1 rounded-xl border border-slate-200/90 shadow-2xs">
                                            <span id="pass_cw_<?= $cw['id'] ?>" class="font-mono font-bold text-slate-900 hidden"><?= htmlspecialchars($cw['pass_plain']) ?></span>
                                            <span id="pass_cw_mask_<?= $cw['id'] ?>" class="font-mono text-slate-400 text-xs tracking-wider">••••••••••••</span>
                                            <div class="flex items-center gap-1 pl-1 border-l border-slate-200">
                                                <button type="button" onclick="togglePass('pass_cw_<?= $cw['id'] ?>', 'pass_cw_mask_<?= $cw['id'] ?>')" class="p-1 rounded-md text-slate-400 hover:text-cyan-600 hover:bg-slate-200/50 transition text-xs" title="Mostrar / Ocultar">
                                                    <i class="fa-solid fa-eye"></i>
                                                </button>
                                                <button type="button" onclick="copiarAlPortapapeles('<?= htmlspecialchars($cw['pass_plain']) ?>', this)" class="p-1 rounded-md text-slate-400 hover:text-cyan-600 hover:bg-slate-200/50 transition text-xs" title="Copiar Contraseña">
                                                    <i class="fa-regular fa-copy"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </td>

                                    <!-- Notas -->
                                    <td class="py-3.5 px-4 text-slate-500 text-[11px] max-w-xs">
                                        <span class="line-clamp-2" title="<?= htmlspecialchars($cw['notas'] ?? '') ?>">
                                            <?= htmlspecialchars($cw['notas'] ?? '-') ?>
                                        </span>
                                    </td>

                                    <!-- Acciones -->
                                    <td class="py-3.5 px-4 text-center">
                                        <div class="flex items-center justify-center gap-1.5">
                                            <?php if (!empty($cw['url_acceso'])): ?>
                                                <a href="<?= htmlspecialchars($cw['url_acceso']) ?>" target="_blank" title="Abrir portal web" class="p-1.5 rounded-lg bg-cyan-50 text-cyan-600 hover:bg-cyan-600 hover:text-white transition">
                                                    <i class="fa-solid fa-arrow-up-right-from-square"></i>
                                                </a>
                                            <?php endif; ?>
                                            <button onclick='editarBoveda(<?= json_encode($cw, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' title="Editar Credencial" class="p-1.5 rounded-lg bg-slate-100 text-slate-600 hover:bg-slate-200 hover:text-slate-900 transition">
                                                <i class="fa-solid fa-pen"></i>
                                            </button>
                                            <a href="guardar.php?action=delete_boveda&id=<?= $cw['id'] ?>" onclick="return confirm('¿Eliminar esta credencial web de la bóveda?')" title="Eliminar Credencial" class="p-1.5 rounded-lg bg-rose-50 text-rose-600 hover:bg-rose-600 hover:text-white transition">
                                                <i class="fa-solid fa-trash"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <!-- Paginación de Bóveda IT -->
            <div id="paginacionBoveda"></div>
        </div>
    <?php endif; ?>

    <!-- TAB 3: INTEGRANTES DE IT -->
    <?php if ($tab === 'equipo_it'): ?>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php foreach ($equipo_it as $user): 
                $isMainAdmin = ($user['id'] == 1);
                $palabras = explode(' ', $user['nombre']);
                $iniciales = isset($palabras[0]) ? strtoupper(substr($palabras[0], 0, 1)) : 'U';
                if (isset($palabras[1])) $iniciales .= strtoupper(substr($palabras[1], 0, 1));

                $roleBadge = 'bg-slate-100 text-slate-700 border-slate-200';
                if ($user['rol'] === 'Administrador') $roleBadge = 'bg-blue-50 text-blue-600 border-blue-200';
                elseif ($user['rol'] === 'Desarrollo') $roleBadge = 'bg-purple-50 text-purple-600 border-purple-200';
                elseif ($user['rol'] === 'Soporte') $roleBadge = 'bg-emerald-50 text-emerald-600 border-emerald-200';
            ?>
                <div class="bg-white/90 backdrop-blur-xl rounded-3xl p-6 border border-slate-200/80 shadow-sm hover:shadow-md transition duration-300 flex flex-col justify-between relative group">
                    <div>
                        <div class="flex items-start justify-between gap-3 mb-4">
                            <div class="flex items-center gap-3">
                                <div class="h-12 w-12 rounded-2xl bg-gradient-to-tr from-purple-600 to-indigo-600 text-white flex items-center justify-center font-black text-sm shadow-md shadow-purple-500/20 shrink-0">
                                    <?= $iniciales ?>
                                </div>
                                <div>
                                    <h4 class="font-bold text-slate-800 text-base leading-snug"><?= htmlspecialchars($user['nombre']) ?></h4>
                                    <p class="text-xs text-slate-400 font-mono"><?= htmlspecialchars($user['email']) ?></p>
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center gap-2 mb-3">
                            <span class="px-2.5 py-1 rounded-lg border text-[11px] font-bold <?= $roleBadge ?>">
                                <i class="fa-solid fa-user-shield text-[10px]"></i> <?= $user['rol'] ?>
                            </span>
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold <?= $user['estado'] === 'Activo' ? 'bg-emerald-50 text-emerald-600 border border-emerald-200' : 'bg-rose-50 text-rose-600 border border-rose-200' ?>">
                                ● <?= $user['estado'] ?>
                            </span>
                        </div>

                        <!-- Resumen de Credenciales Web Asignadas -->
                        <div class="my-3 p-3 rounded-2xl bg-slate-50 border border-slate-100 flex items-center justify-between">
                            <div class="flex items-center gap-2.5">
                                <div class="w-8 h-8 rounded-xl bg-cyan-500/10 text-cyan-600 flex items-center justify-center text-xs font-bold">
                                    <i class="fa-solid fa-key"></i>
                                </div>
                                <div>
                                    <p class="text-xs font-bold text-slate-800">Credenciales Web</p>
                                    <p class="text-[10px] text-slate-400">Portales & servicios asignados</p>
                                </div>
                            </div>
                            <span class="px-2.5 py-1 rounded-xl text-xs font-black <?= $user['total_credenciales'] > 0 ? 'bg-cyan-50 text-cyan-700 border border-cyan-200' : 'bg-slate-100 text-slate-500' ?>">
                                <?= $user['total_credenciales'] ?> asignada<?= $user['total_credenciales'] == 1 ? '' : 's' ?>
                            </span>
                        </div>
                    </div>

                    <div class="pt-4 border-t border-slate-100 flex items-center justify-between text-xs">
                        <span class="text-slate-400 font-mono">ID #<?= str_pad($user['id'], 3, '0', STR_PAD_LEFT) ?></span>
                        <div class="flex items-center gap-1.5">
                            <a href="?tab=boveda&user_it=<?= $user['id'] ?>" class="px-2.5 py-1.5 rounded-xl bg-cyan-50 hover:bg-cyan-100 text-cyan-700 font-bold transition flex items-center gap-1 text-xs" title="Ver credenciales de este integrante">
                                <i class="fa-solid fa-vault"></i> Bóveda
                            </a>
                            <button onclick="abrirModalBovedaParaUsuario(<?= $user['id'] ?>)" class="p-1.5 rounded-xl bg-cyan-50 hover:bg-cyan-600 hover:text-white text-cyan-700 transition" title="Asignar nueva credencial a <?= htmlspecialchars($user['nombre']) ?>">
                                <i class="fa-solid fa-plus"></i>
                            </button>
                            <button onclick='editarUsuario(<?= json_encode($user, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' class="px-2.5 py-1.5 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold transition flex items-center gap-1">
                                <i class="fa-solid fa-pen"></i>
                            </button>
                            <?php if (!$isMainAdmin): ?>
                                <a href="guardar.php?action=delete_it_user&id=<?= $user['id'] ?>" onclick="return confirm('¿Eliminar integrante del equipo IT?')" class="p-1.5 rounded-xl bg-rose-50 text-rose-600 hover:bg-rose-600 hover:text-white transition">
                                    <i class="fa-solid fa-trash"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- TAB 4: MATRIZ DE SUBREDES & IPS (DINÁMICA, GESTIONABLE Y EDITABLE) -->
    <?php if ($tab === 'matriz_ip'): ?>
        <div class="space-y-6">
            <!-- Barra Superior de Control y Métricas de Direccionamiento IP -->
            <div class="bg-white/80 backdrop-blur-xl rounded-3xl border border-slate-200/80 shadow-sm p-6 space-y-5">
                <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4 border-b border-slate-100 pb-4">
                    <div>
                        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-emerald-50 border border-emerald-200 text-emerald-700 text-xs font-bold mb-2">
                            <i class="fa-solid fa-sitemap"></i> Gestión Dinámica de Redes IP (IPAM)
                        </div>
                        <h3 class="text-xl font-black text-slate-800 tracking-tight flex items-center gap-2">
                            Matriz & Mapa de Direccionamiento IP por Sucursal
                        </h3>
                        <p class="text-xs text-slate-500 mt-1">Monitorea subredes corporativas, puertas de enlace (Gateways), VLANs y ocupación de IPs host en tiempo real.</p>
                    </div>
                    
                    <div class="flex items-center gap-2">
                        <button onclick="abrirModalSubred()" class="px-4 py-2.5 rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-700 hover:to-teal-700 text-white font-bold text-xs shadow-md shadow-emerald-500/20 transition flex items-center gap-2">
                            <i class="fa-solid fa-plus-circle"></i> + Nueva Subred IP
                        </button>
                    </div>
                </div>

                <!-- Métricas Globales de Capacidad IP -->
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3.5">
                    <div class="bg-slate-50/80 rounded-2xl p-4 border border-slate-200/80 shadow-2xs">
                        <div class="flex items-center justify-between text-slate-400 mb-1">
                            <span class="text-[11px] font-bold uppercase tracking-wider">Subredes Totales</span>
                            <i class="fa-solid fa-network-wired text-emerald-600"></i>
                        </div>
                        <p class="text-2xl font-black text-slate-800"><?= count($subredes_procesadas) ?></p>
                        <p class="text-[11px] font-semibold text-emerald-600 mt-0.5"><?= $total_subredes_activas ?> Subredes Activas</p>
                    </div>

                    <div class="bg-slate-50/80 rounded-2xl p-4 border border-slate-200/80 shadow-2xs">
                        <div class="flex items-center justify-between text-slate-400 mb-1">
                            <span class="text-[11px] font-bold uppercase tracking-wider">Pool Total Hosts</span>
                            <i class="fa-solid fa-server text-blue-600"></i>
                        </div>
                        <p class="text-2xl font-black text-slate-800"><?= number_format($total_ips_usables_global) ?></p>
                        <p class="text-[11px] font-semibold text-slate-500 mt-0.5">IPs Asignables</p>
                    </div>

                    <div class="bg-slate-50/80 rounded-2xl p-4 border border-slate-200/80 shadow-2xs">
                        <div class="flex items-center justify-between text-slate-400 mb-1">
                            <span class="text-[11px] font-bold uppercase tracking-wider">IPs en Uso</span>
                            <i class="fa-solid fa-link text-indigo-600"></i>
                        </div>
                        <p class="text-2xl font-black text-indigo-600"><?= $total_ips_ocupadas_global ?></p>
                        <p class="text-[11px] font-semibold text-slate-500 mt-0.5">Equipos Mapeados</p>
                    </div>

                    <div class="bg-slate-50/80 rounded-2xl p-4 border border-slate-200/80 shadow-2xs">
                        <div class="flex items-center justify-between text-slate-400 mb-1">
                            <span class="text-[11px] font-bold uppercase tracking-wider">IPs Disponibles</span>
                            <i class="fa-solid fa-circle-check text-teal-600"></i>
                        </div>
                        <p class="text-2xl font-black text-teal-600"><?= number_format(max(0, $total_ips_usables_global - $total_ips_ocupadas_global)) ?></p>
                        <p class="text-[11px] font-semibold text-slate-500 mt-0.5">Hosts Libres</p>
                    </div>
                </div>

                <!-- Barra de Búsqueda y Filtros de Empresa -->
                <div class="flex flex-col md:flex-row gap-3 justify-between items-center pt-1">
                    <div class="relative w-full md:w-80 flex items-center">
                        <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-xs pointer-events-none"></i>
                        <input type="text" id="busquedaSubredes" oninput="filtrarSubredes()" placeholder="Buscar sucursal, segmento, IP, VLAN..." class="w-full pl-9 pr-4 py-2.5 rounded-xl border border-slate-200 text-xs focus:ring-2 focus:ring-emerald-500 outline-none">
                    </div>

                    <div class="flex items-center gap-1.5 overflow-x-auto w-full md:w-auto pb-1" id="filtrosEmpresaSubred">
                        <button type="button" onclick="filtrarEmpresaSubred('all', this)" class="filtro-empresa-btn px-3 py-1.5 rounded-xl text-xs font-bold transition bg-slate-900 text-white shadow-xs">Todas</button>
                        <button type="button" onclick="filtrarEmpresaSubred('MASTERTEC', this)" class="filtro-empresa-btn px-3 py-1.5 rounded-xl text-xs font-bold transition bg-slate-100 hover:bg-slate-200 text-slate-700">Mastertec</button>
                        <button type="button" onclick="filtrarEmpresaSubred('SUMINISTROS', this)" class="filtro-empresa-btn px-3 py-1.5 rounded-xl text-xs font-bold transition bg-slate-100 hover:bg-slate-200 text-slate-700">Suministros Integrales</button>
                        <button type="button" onclick="filtrarEmpresaSubred('CENTRO', this)" class="filtro-empresa-btn px-3 py-1.5 rounded-xl text-xs font-bold transition bg-slate-100 hover:bg-slate-200 text-slate-700">Centro de Pintura</button>
                    </div>
                </div>
            </div>

            <!-- Grilla Dinámica de Tarjetas de Subredes -->
            <?php if (empty($subredes_procesadas)): ?>
                <div class="bg-white/80 backdrop-blur-xl rounded-3xl border border-slate-200/80 p-12 text-center shadow-sm">
                    <div class="w-16 h-16 rounded-3xl bg-emerald-50 text-emerald-600 flex items-center justify-center text-2xl mx-auto mb-4 border border-emerald-100">
                        <i class="fa-solid fa-sitemap"></i>
                    </div>
                    <h4 class="text-base font-black text-slate-800">No hay subredes registradas aún</h4>
                    <p class="text-xs text-slate-500 max-w-md mx-auto mt-1 mb-5">Comienza creando los segmentos y puertas de enlace para cada una de las sucursales de tu organización.</p>
                    <button onclick="abrirModalSubred()" class="px-4 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs shadow-md transition inline-flex items-center gap-2">
                        <i class="fa-solid fa-plus-circle"></i> + Registrar Primera Subred
                    </button>
                </div>
            <?php else: ?>
                <div id="gridSubredes" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <?php foreach ($subredes_procesadas as $sub): 
                        $empresa_badge_color = 'bg-blue-50 text-blue-700 border-blue-200';
                        if (stripos($sub['empresa_nombre'] ?? '', 'suministros') !== false) {
                            $empresa_badge_color = 'bg-amber-50 text-amber-700 border-amber-200';
                        } elseif (stripos($sub['empresa_nombre'] ?? '', 'pintura') !== false) {
                            $empresa_badge_color = 'bg-purple-50 text-purple-700 border-purple-200';
                        }
                        
                        $estado_color = 'bg-emerald-50 text-emerald-700 border-emerald-200';
                        if ($sub['estado'] === 'En Planificación') $estado_color = 'bg-amber-50 text-amber-700 border-amber-200';
                        if ($sub['estado'] === 'Inactiva') $estado_color = 'bg-slate-100 text-slate-600 border-slate-300';
                        
                        $pct = $sub['pct_ocupacion'];
                        $bar_color = 'bg-emerald-500';
                        if ($pct >= 70 && $pct < 90) $bar_color = 'bg-amber-500';
                        if ($pct >= 90) $bar_color = 'bg-rose-500';
                    ?>
                        <div class="card-subred bg-white/90 backdrop-blur-xl rounded-3xl p-5 border border-slate-200/90 shadow-xs hover:shadow-md transition-all duration-200 flex flex-col justify-between space-y-4"
                             data-empresa="<?= htmlspecialchars($sub['empresa_nombre'] ?? '') ?>"
                             data-sucursal="<?= htmlspecialchars($sub['sucursal_nombre'] ?? '') ?>"
                             data-search="<?= htmlspecialchars(strtolower(($sub['sucursal_nombre'] ?? '') . ' ' . ($sub['empresa_nombre'] ?? '') . ' ' . $sub['nombre_red'] . ' ' . $sub['segmento_cidr'] . ' ' . $sub['gateway'] . ' ' . ($sub['vlan'] ?? ''))) ?>">
                            
                            <!-- Cabecera de la Tarjeta -->
                            <div>
                                <div class="flex items-center justify-between gap-2 mb-2">
                                    <div class="flex items-center gap-1.5 flex-wrap">
                                        <span class="px-2 py-0.5 rounded-md text-[10px] font-bold border <?= $empresa_badge_color ?>">
                                            <?= htmlspecialchars($sub['empresa_nombre'] ?? 'MASTERTEC') ?>
                                        </span>
                                        <span class="px-2 py-0.5 rounded-md text-[10px] font-semibold bg-slate-100 text-slate-700 border border-slate-200">
                                            <?= htmlspecialchars($sub['tipo_red']) ?>
                                        </span>
                                    </div>
                                    <span class="px-2 py-0.5 rounded-md text-[10px] font-bold border <?= $estado_color ?>">
                                        <?= htmlspecialchars($sub['estado']) ?>
                                    </span>
                                </div>

                                <div class="flex items-start justify-between gap-2 mt-1">
                                    <div>
                                        <h4 class="font-black text-slate-800 text-base leading-tight">
                                            <?= htmlspecialchars($sub['sucursal_nombre']) ?>
                                        </h4>
                                        <p class="text-xs text-slate-500 font-medium mt-0.5">
                                            <?= htmlspecialchars($sub['nombre_red']) ?>
                                        </p>
                                    </div>

                                    <!-- Segmento CIDR con botón copiar -->
                                    <div class="flex items-center gap-1 bg-slate-100/90 hover:bg-slate-200/80 border border-slate-200 px-2 py-1 rounded-xl transition shrink-0" title="Copiar Segmento CIDR">
                                        <code class="text-xs font-mono font-bold text-slate-800"><?= htmlspecialchars($sub['segmento_cidr']) ?></code>
                                        <button type="button" onclick="copiarAlPortapapeles('<?= htmlspecialchars($sub['segmento_cidr']) ?>', this)" class="text-slate-400 hover:text-emerald-600 transition p-0.5">
                                            <i class="fa-regular fa-copy text-xs"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!-- Bloque de Parámetros de Red -->
                            <div class="bg-slate-50/80 rounded-2xl p-3.5 border border-slate-200/70 space-y-1.5 text-xs font-mono text-slate-600">
                                <div class="flex items-center justify-between">
                                    <span class="text-slate-400 font-sans font-medium">Gateway:</span>
                                    <div class="flex items-center gap-1">
                                        <strong class="text-slate-800 font-bold"><?= htmlspecialchars($sub['gateway']) ?></strong>
                                        <button type="button" onclick="copiarAlPortapapeles('<?= htmlspecialchars($sub['gateway']) ?>', this)" class="text-slate-400 hover:text-blue-600 transition p-0.5" title="Copiar Gateway">
                                            <i class="fa-regular fa-copy text-[11px]"></i>
                                        </button>
                                    </div>
                                </div>
                                
                                <div class="flex items-center justify-between">
                                    <span class="text-slate-400 font-sans font-medium">Rango Hosts:</span>
                                    <span class="text-slate-700 text-[11px]"><?= $sub['cidr_info']['first_ip'] ?> - <?= $sub['cidr_info']['last_ip'] ?></span>
                                </div>

                                <div class="flex items-center justify-between">
                                    <span class="text-slate-400 font-sans font-medium">VLANs:</span>
                                    <span class="text-slate-800 font-semibold truncate max-w-[180px]" title="<?= htmlspecialchars($sub['vlan'] ?? '') ?>"><?= htmlspecialchars($sub['vlan'] ?: 'VLAN 1') ?></span>
                                </div>

                                <?php if (!empty($sub['rango_dhcp'])): ?>
                                    <div class="flex items-center justify-between">
                                        <span class="text-slate-400 font-sans font-medium">DHCP:</span>
                                        <span class="text-slate-700 text-[11px]"><?= htmlspecialchars($sub['rango_dhcp']) ?></span>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($sub['dns_servers'])): ?>
                                    <div class="flex items-center justify-between">
                                        <span class="text-slate-400 font-sans font-medium">DNS:</span>
                                        <span class="text-slate-700 text-[11px]"><?= htmlspecialchars($sub['dns_servers']) ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Barra de Ocupación Real de IPs -->
                            <div>
                                <div class="flex justify-between text-[11px] font-bold text-slate-600 mb-1.5">
                                    <span class="flex items-center gap-1.5">
                                        <i class="fa-solid fa-server text-xs text-slate-400"></i>
                                        Ocupación Real
                                    </span>
                                    <span class="text-slate-800">
                                        <strong><?= $sub['usadas'] ?></strong> de <?= $sub['total_usables'] ?> IPs <span class="text-slate-400 font-normal">(<?= $sub['libres'] ?> libres)</span>
                                    </span>
                                </div>
                                <div class="w-full h-2.5 bg-slate-200/90 rounded-full overflow-hidden p-0.5">
                                    <div class="h-full <?= $bar_color ?> rounded-full transition-all duration-500" style="width: <?= max(3, $pct) ?>%"></div>
                                </div>
                                <div class="flex justify-between items-center text-[10px] text-slate-400 font-semibold mt-1">
                                    <span>Pool /<?= explode('/', $sub['segmento_cidr'])[1] ?? '24' ?></span>
                                    <span class="text-emerald-700 font-bold"><?= $pct ?>% ocupado</span>
                                </div>
                            </div>

                            <?php if (!empty($sub['notas'])): ?>
                                <p class="text-[11px] text-slate-500 bg-amber-50/60 border border-amber-200/60 p-2 rounded-xl italic line-clamp-2" title="<?= htmlspecialchars($sub['notas']) ?>">
                                    <i class="fa-solid fa-circle-info text-amber-500 mr-1"></i><?= htmlspecialchars($sub['notas']) ?>
                                </p>
                            <?php endif; ?>

                            <!-- Acciones de la Tarjeta -->
                            <div class="pt-3 border-t border-slate-100 flex items-center justify-between gap-2">
                                <button type="button" 
                                        onclick='verDetalleSubred(<?= json_encode($sub, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)' 
                                        class="flex-1 py-2 px-3 rounded-xl bg-slate-900 hover:bg-slate-800 text-white font-bold text-xs transition flex items-center justify-center gap-1.5 shadow-xs">
                                    <i class="fa-solid fa-list-check text-xs"></i>
                                    <span>Ver IPs & Equipos (<?= $sub['usadas'] ?>)</span>
                                </button>

                                <button type="button" 
                                        onclick='editarSubred(<?= json_encode($sub, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)' 
                                        class="p-2 rounded-xl bg-slate-100 hover:bg-blue-50 text-slate-600 hover:text-blue-600 border border-slate-200 transition" 
                                        title="Editar Subred">
                                    <i class="fa-solid fa-pen-to-square text-xs"></i>
                                </button>

                                <button type="button" 
                                        onclick="eliminarSubred(<?= $sub['id'] ?>, '<?= addslashes($sub['nombre_red']) ?>')" 
                                        class="p-2 rounded-xl bg-slate-100 hover:bg-rose-50 text-slate-600 hover:text-rose-600 border border-slate-200 transition" 
                                        title="Eliminar Subred">
                                    <i class="fa-solid fa-trash text-xs"></i>
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<!-- MODAL 1: REGISTRAR / EDITAR DISPOSITIVO DE RED -->
<div id="modalDispositivo" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 flex items-center justify-center hidden p-4">
    <div class="bg-white rounded-3xl max-w-2xl w-full p-6 shadow-2xl space-y-4 max-h-[90vh] overflow-y-auto">
        <div class="flex justify-between items-center border-b border-slate-100 pb-3">
            <h3 id="modalDispositivoTitulo" class="text-lg font-black text-slate-800 flex items-center gap-2">
                <i class="fa-solid fa-server text-blue-600"></i> Registrar Equipo de Red / IP
            </h3>
            <button onclick="cerrarModalDispositivo()" class="text-slate-400 hover:text-slate-700 text-lg"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <form action="guardar.php?action=save_dispositivo" method="POST" class="space-y-4">
            <input type="hidden" name="dev_id" id="dev_id">
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Nombre del Dispositivo *</label>
                    <input type="text" name="nombre_dispositivo" id="dev_nombre" required placeholder="Ej. Switch-Core-Managua" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs focus:ring-2 focus:ring-blue-500 outline-none">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Tipo de Equipo *</label>
                    <select name="tipo" id="dev_tipo" required class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs font-semibold text-slate-700 outline-none">
                        <option value="Switch Managed">Switch Managed</option>
                        <option value="Switch Unmanaged">Switch Unmanaged</option>
                        <option value="Access Point (AP)">Access Point (AP)</option>
                        <option value="Servidor Físico">Servidor Físico</option>
                        <option value="Servidor Virtual (VM)">Servidor Virtual (VM)</option>
                        <option value="Firewall / Router">Firewall / Router</option>
                        <option value="UPS / PDU">UPS / PDU</option>
                        <option value="Cámara / NVR IP">Cámara / NVR IP</option>
                        <option value="Impresora de Red">Impresora de Red</option>
                        <option value="Otro Dispositivo IP">Otro Dispositivo IP</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Sucursal *</label>
                    <select name="sucursal_id" id="dev_sucursal" required class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs font-semibold text-slate-700 outline-none">
                        <?php foreach ($sucursales as $suc): ?>
                            <option value="<?= $suc['id'] ?>"><?= htmlspecialchars($suc['empresa_nombre'] . ' - ' . $suc['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Dirección IP *</label>
                    <input type="text" name="ip_direccion" id="dev_ip" required placeholder="Ej. 192.168.10.2" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs font-mono focus:ring-2 focus:ring-blue-500 outline-none">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Marca</label>
                    <input type="text" name="marca" id="dev_marca" placeholder="Ej. Cisco, Ubiquiti, Dell" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs focus:ring-2 focus:ring-blue-500 outline-none">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Modelo</label>
                    <input type="text" name="modelo" id="dev_modelo" placeholder="Ej. Catalyst 2960X" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs focus:ring-2 focus:ring-blue-500 outline-none">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Serie / Direccion MAC</label>
                    <input type="text" name="serie_mac" id="dev_mac" placeholder="Ej. FCW2145L09A o 74:83:C2:11:44:99" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs font-mono focus:ring-2 focus:ring-blue-500 outline-none">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">VLAN / Máscara de Red</label>
                    <input type="text" name="mascara_vlan" id="dev_vlan" placeholder="Ej. VLAN 10 (Datos)" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs focus:ring-2 focus:ring-blue-500 outline-none">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Usuario Admin</label>
                    <input type="text" name="usuario_admin" id="dev_user" placeholder="Ej. admin" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs focus:ring-2 focus:ring-blue-500 outline-none">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Contraseña Admin (Cifrada AES-256)</label>
                    <input type="password" name="password_admin" id="dev_pass" placeholder="Dejar en blanco para no cambiar" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs font-mono focus:ring-2 focus:ring-blue-500 outline-none">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">URL de Gestión (Web UI)</label>
                    <input type="text" name="url_gestion" id="dev_url" placeholder="Ej. https://192.168.10.2" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs font-mono focus:ring-2 focus:ring-blue-500 outline-none">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Ubicación en Rack / Armario</label>
                    <input type="text" name="ubicacion_rack" id="dev_rack" placeholder="Ej. Rack Principal - U2" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs focus:ring-2 focus:ring-blue-500 outline-none">
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Notas / Especificaciones adicionales</label>
                <textarea name="notas" id="dev_notas" rows="2" placeholder="Notas sobre la configuración..." class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs focus:ring-2 focus:ring-blue-500 outline-none"></textarea>
            </div>

            <div class="flex justify-end gap-2 pt-3 border-t border-slate-100">
                <button type="button" onclick="cerrarModalDispositivo()" class="px-4 py-2 rounded-xl border border-slate-200 text-slate-600 font-bold text-xs hover:bg-slate-50">Cancelar</button>
                <button type="submit" class="px-5 py-2 rounded-xl bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs shadow-lg shadow-blue-500/20">Guardar Dispositivo</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL 2: NUEVA CREDENCIAL WEB BÓVEDA IT -->
<div id="modalNuevaCredWeb" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 flex items-center justify-center hidden p-4">
    <div class="bg-white rounded-3xl max-w-md w-full p-6 shadow-2xl space-y-4">
        <div class="flex justify-between items-center border-b border-slate-100 pb-3">
            <h3 id="modalBovedaTitulo" class="text-lg font-black text-slate-800 flex items-center gap-2">
                <i class="fa-solid fa-vault text-cyan-600"></i> Credencial en Bóveda IT
            </h3>
            <button onclick="cerrarModalBoveda()" class="text-slate-400 hover:text-slate-700 text-lg"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <form action="guardar.php?action=save_boveda" method="POST" class="space-y-3">
            <input type="hidden" name="web_id" id="boveda_id">
            
            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">
                    <i class="fa-solid fa-user-tag text-cyan-600 mr-1"></i> Asignar a Perfil / Propietario *
                </label>
                <select name="usuario_it_id" id="boveda_user_it" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs font-semibold text-slate-700 outline-none focus:ring-2 focus:ring-cyan-500">
                    <option value="">👥 Compartida (Para todo el equipo de IT)</option>
                    <optgroup label="Integrantes individuales de IT">
                        <?php foreach ($equipo_it as $it_u): ?>
                            <option value="<?= $it_u['id'] ?>">👤 <?= htmlspecialchars($it_u['nombre']) ?> (<?= htmlspecialchars($it_u['rol']) ?>)</option>
                        <?php endforeach; ?>
                    </optgroup>
                </select>
                <p class="text-[10px] text-slate-400 mt-1">Selecciona a qué integrante de IT le pertenece este acceso o déjalo compartido.</p>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Plataforma / Portal *</label>
                <input type="text" name="plataforma" id="boveda_plataforma" required placeholder="Ej. Mikrotik Cloud, UniFi Controller, VMWare" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs outline-none focus:ring-2 focus:ring-cyan-500">
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Enlace / URL de Acceso</label>
                <input type="text" name="url_acceso" id="boveda_url" placeholder="https://..." class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs font-mono outline-none focus:ring-2 focus:ring-cyan-500">
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Usuario *</label>
                <input type="text" name="usuario" id="boveda_usuario" required placeholder="Ej. admin@empresa.com.ni" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs outline-none focus:ring-2 focus:ring-cyan-500">
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Contraseña (Cifrada AES-256)</label>
                <input type="password" name="password" id="boveda_pass" placeholder="Ingresa contraseña" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs font-mono outline-none focus:ring-2 focus:ring-cyan-500">
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Notas</label>
                <textarea name="notas" id="boveda_notas" rows="2" placeholder="Comentarios..." class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs outline-none focus:ring-2 focus:ring-cyan-500"></textarea>
            </div>

            <div class="flex justify-end gap-2 pt-3 border-t border-slate-100">
                <button type="button" onclick="cerrarModalBoveda()" class="px-4 py-2 rounded-xl border border-slate-200 text-slate-600 font-bold text-xs hover:bg-slate-50">Cancelar</button>
                <button type="submit" class="px-5 py-2 rounded-xl bg-cyan-600 hover:bg-cyan-700 text-white font-bold text-xs shadow-lg shadow-cyan-500/20">Guardar Credencial</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL 3: NUEVO / EDITAR INTEGRANTE IT -->
<div id="modalNuevoUsuario" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 flex items-center justify-center hidden p-4">
    <div class="bg-white rounded-3xl max-w-md w-full p-6 shadow-2xl space-y-4">
        <div class="flex justify-between items-center border-b border-slate-100 pb-3">
            <h3 id="modalUsuarioTitulo" class="text-lg font-black text-slate-800 flex items-center gap-2">
                <i class="fa-solid fa-user-plus text-purple-600"></i> Integrante del Equipo de IT
            </h3>
            <button onclick="cerrarModalUsuario()" class="text-slate-400 hover:text-slate-700 text-lg"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <form action="guardar.php?action=save_it_user" method="POST" class="space-y-3">
            <input type="hidden" name="user_id" id="usr_id">
            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Nombre Completo *</label>
                <input type="text" name="nombre" id="usr_nombre" required placeholder="Ej. Carlos Mendoza" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs outline-none focus:ring-2 focus:ring-purple-500">
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Correo Electrónico *</label>
                <input type="email" name="email" id="usr_email" required placeholder="cmendoza@mastertec.com.ni" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs font-mono outline-none focus:ring-2 focus:ring-purple-500">
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Rol en el Departamento *</label>
                <select name="rol" id="usr_rol" required class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs font-semibold text-slate-700 outline-none">
                    <option value="Administrador">Administrador</option>
                    <option value="Soporte">Soporte Técnico</option>
                    <option value="Desarrollo">Desarrollo de Software</option>
                </select>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Contraseña</label>
                <input type="password" name="password" id="usr_pass" placeholder="Dejar en blanco para no cambiar" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs font-mono outline-none focus:ring-2 focus:ring-purple-500">
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Estado</label>
                <select name="estado" id="usr_estado" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs font-semibold text-slate-700 outline-none">
                    <option value="Activo">Activo</option>
                    <option value="Inactivo">Inactivo</option>
                </select>
            </div>

            <div class="flex justify-end gap-2 pt-3 border-t border-slate-100">
                <button type="button" onclick="cerrarModalUsuario()" class="px-4 py-2 rounded-xl border border-slate-200 text-slate-600 font-bold text-xs hover:bg-slate-50">Cancelar</button>
                <button type="submit" class="px-5 py-2 rounded-xl bg-purple-600 hover:bg-purple-700 text-white font-bold text-xs shadow-lg shadow-purple-500/20">Guardar Integrante</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL 4: REGISTRAR / EDITAR SUBRED IP -->
<div id="modalSubred" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 flex items-center justify-center hidden p-4">
    <div class="bg-white rounded-3xl max-w-2xl w-full p-6 shadow-2xl space-y-4 max-h-[90vh] overflow-y-auto">
        <div class="flex justify-between items-center border-b border-slate-100 pb-3">
            <h3 id="modalSubredTitulo" class="text-lg font-black text-slate-800 flex items-center gap-2">
                <i class="fa-solid fa-sitemap text-emerald-600"></i> Registrar Subred IP / Rango
            </h3>
            <button onclick="cerrarModalSubred()" class="text-slate-400 hover:text-slate-700 text-lg"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <form action="guardar.php?action=save_subred" method="POST" class="space-y-4">
            <input type="hidden" name="subred_id" id="subred_id">

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Sucursal / Sede *</label>
                    <select name="sucursal_id" id="subred_sucursal" required class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs font-semibold text-slate-700 outline-none focus:ring-2 focus:ring-emerald-500">
                        <?php foreach ($sucursales as $suc): ?>
                            <option value="<?= $suc['id'] ?>"><?= htmlspecialchars(($suc['empresa_nombre'] ?? 'MASTERTEC') . ' - ' . $suc['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Nombre de la Red *</label>
                    <input type="text" name="nombre_red" id="subred_nombre" required placeholder="Ej. Red Corporativa Datos Central" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs outline-none focus:ring-2 focus:ring-emerald-500">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Segmento CIDR *</label>
                    <input type="text" name="segmento_cidr" id="subred_cidr" required placeholder="Ej. 192.168.10.0/24" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs font-mono outline-none focus:ring-2 focus:ring-emerald-500">
                    <p class="text-[10px] text-slate-400 mt-0.5">Formato: 192.168.10.0/24 (permite 254 hosts usables)</p>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Puerta de Enlace (Gateway) *</label>
                    <input type="text" name="gateway" id="subred_gateway" required placeholder="Ej. 192.168.10.1" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs font-mono outline-none focus:ring-2 focus:ring-emerald-500">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Tipo de Red</label>
                    <select name="tipo_red" id="subred_tipo" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs font-semibold text-slate-700 outline-none focus:ring-2 focus:ring-emerald-500">
                        <option value="Datos / LAN">Datos / LAN</option>
                        <option value="WiFi / WLAN">WiFi / WLAN</option>
                        <option value="Servidores / DMZ">Servidores / DMZ</option>
                        <option value="Gestión / Infra">Gestión / Infra</option>
                        <option value="Voz / VoIP">Voz / VoIP</option>
                        <option value="Cámaras / CCTV">Cámaras / CCTV</option>
                        <option value="Otra">Otra</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">VLAN(s) Asociadas</label>
                    <input type="text" name="vlan" id="subred_vlan" placeholder="Ej. VLAN 10 (Datos), VLAN 20 (WiFi)" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs outline-none focus:ring-2 focus:ring-emerald-500">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Rango DHCP Dinámico</label>
                    <input type="text" name="rango_dhcp" id="subred_dhcp" placeholder="Ej. 192.168.10.50 - 192.168.10.180" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs font-mono outline-none focus:ring-2 focus:ring-emerald-500">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Servidores DNS</label>
                    <input type="text" name="dns_servers" id="subred_dns" placeholder="Ej. 1.1.1.1, 8.8.8.8" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs font-mono outline-none focus:ring-2 focus:ring-emerald-500">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Estado de la Red</label>
                    <select name="estado" id="subred_estado" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs font-semibold text-slate-700 outline-none focus:ring-2 focus:ring-emerald-500">
                        <option value="Activa">Activa</option>
                        <option value="En Planificación">En Planificación</option>
                        <option value="Inactiva">Inactiva</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Notas / Observaciones del Router o ISP</label>
                <textarea name="notas" id="subred_notas" rows="2" placeholder="Ej. Router MikroTik RB4011, enlace Claro 100Mbps simétrico..." class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs outline-none focus:ring-2 focus:ring-emerald-500"></textarea>
            </div>

            <div class="flex justify-end gap-2 pt-3 border-t border-slate-100">
                <button type="button" onclick="cerrarModalSubred()" class="px-4 py-2 rounded-xl border border-slate-200 text-slate-600 font-bold text-xs hover:bg-slate-50">Cancelar</button>
                <button type="submit" class="px-5 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs shadow-lg shadow-emerald-500/20">Guardar Subred</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL 5: DETALLE DE SUBRED & DISPOSITIVOS ASIGNADOS -->
<div id="modalDetalleSubred" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 flex items-center justify-center hidden p-4">
    <div class="bg-white rounded-3xl max-w-4xl w-full p-6 shadow-2xl space-y-5 max-h-[90vh] overflow-y-auto">
        <div class="flex justify-between items-start border-b border-slate-100 pb-3">
            <div>
                <div class="flex items-center gap-2 mb-1">
                    <span id="detalleSubredEmpresa" class="px-2 py-0.5 rounded-md text-[10px] font-bold bg-blue-50 text-blue-700 border border-blue-200">MASTERTEC</span>
                    <span id="detalleSubredCidr" class="px-2 py-0.5 rounded-md text-xs font-mono font-bold bg-slate-100 text-slate-800 border border-slate-200">192.168.10.0/24</span>
                    <span id="detalleSubredEstado" class="px-2 py-0.5 rounded-md text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">Activa</span>
                </div>
                <h3 id="detalleSubredTitulo" class="text-xl font-black text-slate-800">Managua Central</h3>
                <p id="detalleSubredNombre" class="text-xs text-slate-500">Red Corporativa & Datos Central</p>
            </div>
            <button onclick="cerrarModalDetalleSubred()" class="text-slate-400 hover:text-slate-700 text-lg p-1"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <!-- 4 KPI Cards del direccionamiento -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-xs">
            <div class="bg-slate-50 p-3 rounded-2xl border border-slate-200/80">
                <span class="text-slate-400 font-semibold block text-[10px]">Gateway</span>
                <span id="detalleKpiGateway" class="font-mono font-bold text-slate-800 text-sm">192.168.10.1</span>
            </div>
            <div class="bg-slate-50 p-3 rounded-2xl border border-slate-200/80">
                <span class="text-slate-400 font-semibold block text-[10px]">Máscara</span>
                <span id="detalleKpiMascara" class="font-mono font-bold text-slate-800 text-sm">255.255.255.0</span>
            </div>
            <div class="bg-slate-50 p-3 rounded-2xl border border-slate-200/80">
                <span class="text-slate-400 font-semibold block text-[10px]">Broadcast</span>
                <span id="detalleKpiBroadcast" class="font-mono font-bold text-slate-800 text-sm">192.168.10.255</span>
            </div>
            <div class="bg-slate-50 p-3 rounded-2xl border border-slate-200/80">
                <span class="text-slate-400 font-semibold block text-[10px]">Rango de Hosts</span>
                <span id="detalleKpiRango" class="font-mono font-bold text-slate-800 text-xs">.1 a .254</span>
            </div>
        </div>

        <!-- Barra de Ocupación detallada -->
        <div class="bg-slate-50/80 p-4 rounded-2xl border border-slate-200/80 space-y-2">
            <div class="flex justify-between items-center text-xs font-bold">
                <span class="text-slate-700 flex items-center gap-1.5">
                    <i class="fa-solid fa-chart-pie text-emerald-600"></i>
                    Capacidad y Disponibilidad de Direcciones IP
                </span>
                <span id="detalleResumenOcupacion" class="text-slate-800">4 de 254 IPs (250 libres)</span>
            </div>
            <div class="w-full h-3 bg-slate-200 rounded-full overflow-hidden p-0.5">
                <div id="detalleBarraOcupacion" class="h-full bg-emerald-500 rounded-full transition-all duration-300" style="width: 5%"></div>
            </div>
        </div>

        <!-- Tabla de Dispositivos e IPs Asignadas -->
        <div>
            <div class="flex items-center justify-between mb-2">
                <h4 class="text-xs font-black text-slate-700 uppercase tracking-wider flex items-center gap-1.5">
                    <i class="fa-solid fa-server text-blue-600"></i> Dispositivos y Equipos con IP en este Segmento
                </h4>
                <span id="detalleConteoDispositivos" class="text-xs font-bold text-slate-500">4 equipos</span>
            </div>

            <div class="border border-slate-200 rounded-2xl overflow-hidden max-h-72 overflow-y-auto">
                <table class="w-full text-left text-xs border-collapse">
                    <thead class="bg-slate-50 text-slate-500 font-bold border-b border-slate-200 sticky top-0">
                        <tr>
                            <th class="p-3">Dirección IP</th>
                            <th class="p-3">Dispositivo / Equipo</th>
                            <th class="p-3">Tipo</th>
                            <th class="p-3">Marca / Modelo</th>
                            <th class="p-3">MAC / Serie</th>
                            <th class="p-3">Estado</th>
                        </tr>
                    </thead>
                    <tbody id="detalleTablaDispositivos" class="divide-y divide-slate-100">
                        <!-- Generado dinámicamente con JavaScript -->
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Footer del Modal -->
        <div class="flex flex-col sm:flex-row justify-between items-center gap-3 pt-3 border-t border-slate-100">
            <div class="flex items-center gap-2 w-full sm:w-auto">
                <button type="button" id="btnRegistrarEnSubred" onclick="registrarDispositivoEnSubredActual()" class="w-full sm:w-auto px-4 py-2 rounded-xl bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs shadow-md transition flex items-center justify-center gap-1.5">
                    <i class="fa-solid fa-plus-circle"></i> + Registrar Dispositivo en esta Sede
                </button>
            </div>

            <div class="flex items-center gap-2 w-full sm:w-auto justify-end">
                <button type="button" onclick="cerrarModalDetalleSubred()" class="px-5 py-2 rounded-xl border border-slate-200 text-slate-700 font-bold text-xs hover:bg-slate-50">Cerrar</button>
            </div>
        </div>
    </div>
</div>

<script>
function togglePass(idText, idMask) {
    const elText = document.getElementById(idText);
    const elMask = document.getElementById(idMask);
    if (elText && elMask) {
        if (elText.classList.contains('hidden')) {
            elText.classList.remove('hidden');
            elMask.classList.add('hidden');
        } else {
            elText.classList.add('hidden');
            elMask.classList.remove('hidden');
        }
    }
}

let paginadorInfra = null;
let paginadorBoveda = null;

function filtrarInfra() {
    const input = document.getElementById('busquedaInfra');
    if (input) toggleClearBtn('busquedaInfra', 'btn_clear_infra');
    const q = input ? input.value.toLowerCase().trim() : '';
    const tipo = document.getElementById('filtroTipoInfra').value;
    const sucursal = document.getElementById('filtroSucursalInfra').value;

    if (paginadorInfra) {
        paginadorInfra.setFilter(f => {
            const text = f.innerText.toLowerCase();
            const fTipo = f.getAttribute('data-tipo');
            const fSuc = f.getAttribute('data-sucursal');

            const matchQ = !q || text.includes(q);
            const matchTipo = !tipo || fTipo === tipo;
            const matchSuc = !sucursal || (fSuc && fSuc.includes(sucursal));

            return matchQ && matchTipo && matchSuc;
        });
    }
}

let perfilBovedaActivo = '<?= htmlspecialchars($filtro_user_it) ?>';

// Mapa de nombres para banner contextual
const perfilesNombres = {
    'all': 'Todas las Credenciales de IT',
    'shared': 'Credenciales Compartidas del Equipo',
    <?php foreach ($equipo_it as $it_u): ?>
        '<?= $it_u['id'] ?>': '<?= addslashes(htmlspecialchars($it_u['nombre'])) ?> (<?= $it_u['rol'] ?>)',
    <?php endforeach; ?>
};

function seleccionarPerfilBoveda(userId) {
    perfilBovedaActivo = String(userId);
    
    // Actualizar estilos de las tarjetas de perfil
    document.querySelectorAll('.perfil-card').forEach(btn => {
        const btnId = btn.getAttribute('data-perfil-btn');
        if (btnId === perfilBovedaActivo) {
            btn.className = 'perfil-card text-left p-3 rounded-xl border transition-all duration-200 cursor-pointer flex flex-col justify-between gap-2.5 bg-gradient-to-b from-cyan-500/25 to-blue-600/25 border-cyan-400 ring-2 ring-cyan-400/50 shadow-lg shadow-cyan-500/20 text-white scale-[1.02]';
        } else {
            btn.className = 'perfil-card text-left p-3 rounded-xl border transition-all duration-200 cursor-pointer flex flex-col justify-between gap-2.5 bg-slate-800/80 border-slate-700/80 hover:bg-slate-700/80 text-white';
        }
    });

    // Actualizar banner contextual
    const nombreCtx = document.getElementById('contextPerfilNombre');
    if (nombreCtx) {
        nombreCtx.innerText = perfilesNombres[perfilBovedaActivo] || 'Perfil IT';
    }

    filtrarBoveda();
}

function filtrarBoveda() {
    const input = document.getElementById('searchBoveda');
    if (input) toggleClearBtn('searchBoveda', 'btn_clear_boveda');
    const q = input ? input.value.toLowerCase().trim() : '';
    let visibles = 0;

    if (paginadorBoveda) {
        paginadorBoveda.setFilter(f => {
            const text = f.innerText.toLowerCase();
            const rowUserIt = f.getAttribute('data-user-it');

            const matchQ = !q || text.includes(q);
            let matchUser = true;

            if (perfilBovedaActivo === 'all') {
                matchUser = true;
            } else if (perfilBovedaActivo === 'shared') {
                matchUser = (rowUserIt === 'shared' || !rowUserIt || rowUserIt === '');
            } else {
                matchUser = (String(rowUserIt) === String(perfilBovedaActivo));
            }

            const visible = matchQ && matchUser;
            if (visible) visibles++;
            return visible;
        });
    }

    const conteoCtx = document.getElementById('contextPerfilConteo');
    if (conteoCtx) {
        conteoCtx.innerText = `Mostrando ${visibles} acceso(s)`;
    }
}

// Función moderna de copiado con tooltip y retroalimentación visual inmediata
function copiarAlPortapapeles(texto, btnElement) {
    if (!texto) return;
    navigator.clipboard.writeText(texto).then(() => {
        mostrarToast('¡Copiado al portapapeles!', 'success');
        if (btnElement) {
            const originalHtml = btnElement.innerHTML;
            btnElement.innerHTML = '<i class="fa-solid fa-check text-emerald-500"></i>';
            setTimeout(() => {
                btnElement.innerHTML = originalHtml;
            }, 1500);
        }
    }).catch(err => {
        mostrarToast('No se pudo copiar', 'error');
    });
}

function mostrarToast(mensaje, tipo = 'success') {
    let toast = document.getElementById('toastBovedaNotif');
    if (!toast) {
        toast = document.createElement('div');
        toast.id = 'toastBovedaNotif';
        toast.className = 'fixed bottom-5 right-5 z-50 flex items-center gap-2 px-4 py-2.5 rounded-2xl shadow-2xl text-xs font-bold transition-all duration-300 transform translate-y-12 opacity-0';
        document.body.appendChild(toast);
    }
    
    if (tipo === 'success') {
        toast.className = 'fixed bottom-5 right-5 z-50 flex items-center gap-2 px-4 py-2.5 rounded-2xl shadow-2xl text-xs font-bold transition-all duration-300 bg-slate-900 text-white border border-cyan-500/30 transform translate-y-0 opacity-100';
        toast.innerHTML = `<i class="fa-solid fa-circle-check text-emerald-400 text-sm"></i> ${mensaje}`;
    } else {
        toast.className = 'fixed bottom-5 right-5 z-50 flex items-center gap-2 px-4 py-2.5 rounded-2xl shadow-2xl text-xs font-bold transition-all duration-300 bg-rose-900 text-white border border-rose-500/30 transform translate-y-0 opacity-100';
        toast.innerHTML = `<i class="fa-solid fa-circle-exclamation text-rose-400 text-sm"></i> ${mensaje}`;
    }

    setTimeout(() => {
        toast.classList.add('translate-y-12', 'opacity-0');
    }, 2200);
}

function initPaginacionInfra() {
    if (typeof TablePaginator !== 'undefined') {
        if (document.getElementById('tablaInfra') && document.getElementById('paginacionInfra')) {
            paginadorInfra = new TablePaginator({
                table: '#tablaInfra',
                container: '#paginacionInfra',
                itemLabel: 'dispositivos',
                pageSize: 15
            });
            window.paginadorInfra = paginadorInfra;
        }
        if (document.getElementById('tablaBoveda') && document.getElementById('paginacionBoveda')) {
            paginadorBoveda = new TablePaginator({
                table: '#tablaBoveda',
                container: '#paginacionBoveda',
                itemLabel: 'accesos',
                pageSize: 15
            });
            window.paginadorBoveda = paginadorBoveda;
            
            // Si hay un perfil activo predeterminado por URL
            if (perfilBovedaActivo && perfilBovedaActivo !== 'all') {
                seleccionarPerfilBoveda(perfilBovedaActivo);
            }
        }
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initPaginacionInfra);
} else {
    initPaginacionInfra();
}

function abrirModalDispositivo() {
    document.getElementById('modalDispositivoTitulo').innerText = 'Registrar Equipo de Red / IP';
    document.getElementById('dev_id').value = '';
    document.getElementById('dev_nombre').value = '';
    document.getElementById('dev_ip').value = '';
    document.getElementById('dev_marca').value = '';
    document.getElementById('dev_modelo').value = '';
    document.getElementById('dev_mac').value = '';
    document.getElementById('dev_vlan').value = '';
    document.getElementById('dev_user').value = '';
    document.getElementById('dev_pass').value = '';
    document.getElementById('dev_url').value = '';
    document.getElementById('dev_rack').value = '';
    document.getElementById('dev_notas').value = '';
    document.getElementById('modalDispositivo').classList.remove('hidden');
}

function cerrarModalDispositivo() {
    document.getElementById('modalDispositivo').classList.add('hidden');
}

function editarDispositivo(d) {
    document.getElementById('modalDispositivoTitulo').innerText = 'Editar Dispositivo de Red';
    document.getElementById('dev_id').value = d.id;
    document.getElementById('dev_nombre').value = d.nombre_dispositivo || '';
    document.getElementById('dev_tipo').value = d.tipo || 'Switch Managed';
    document.getElementById('dev_sucursal').value = d.sucursal_id || '';
    document.getElementById('dev_ip').value = d.ip_direccion || '';
    document.getElementById('dev_marca').value = d.marca || '';
    document.getElementById('dev_modelo').value = d.modelo || '';
    document.getElementById('dev_mac').value = d.serie_mac || '';
    document.getElementById('dev_vlan').value = d.mascara_vlan || '';
    document.getElementById('dev_user').value = d.usuario_admin || '';
    document.getElementById('dev_pass').value = '';
    document.getElementById('dev_url').value = d.url_gestion || '';
    document.getElementById('dev_rack').value = d.ubicacion_rack || '';
    document.getElementById('dev_notas').value = d.notas || '';
    document.getElementById('modalDispositivo').classList.remove('hidden');
}

function abrirModalBoveda(userItId = null) {
    document.getElementById('modalBovedaTitulo').innerHTML = '<i class="fa-solid fa-vault text-cyan-600"></i> Registrar Credencial en Bóveda IT';
    document.getElementById('boveda_id').value = '';
    document.getElementById('boveda_plataforma').value = '';
    document.getElementById('boveda_url').value = '';
    document.getElementById('boveda_usuario').value = '';
    document.getElementById('boveda_pass').value = '';
    document.getElementById('boveda_notas').value = '';
    
    const selUser = document.getElementById('boveda_user_it');
    if (selUser) {
        if (userItId !== null && userItId !== undefined) {
            selUser.value = String(userItId);
        } else if (perfilBovedaActivo !== 'all' && perfilBovedaActivo !== 'shared') {
            selUser.value = perfilBovedaActivo;
        } else {
            selUser.value = '';
        }
    }
    
    document.getElementById('modalNuevaCredWeb').classList.remove('hidden');
}

function abrirModalBovedaParaUsuario(userId) {
    abrirModalBoveda(userId);
}

function cerrarModalBoveda() {
    document.getElementById('modalNuevaCredWeb').classList.add('hidden');
}

function editarBoveda(cw) {
    document.getElementById('modalBovedaTitulo').innerHTML = '<i class="fa-solid fa-pen-to-square text-cyan-600"></i> Editar Credencial en Bóveda IT';
    document.getElementById('boveda_id').value = cw.id;
    document.getElementById('boveda_plataforma').value = cw.plataforma || '';
    document.getElementById('boveda_url').value = cw.url_acceso || '';
    document.getElementById('boveda_usuario').value = cw.usuario || '';
    document.getElementById('boveda_user_it').value = cw.usuario_it_id || '';
    document.getElementById('boveda_pass').value = '';
    document.getElementById('boveda_notas').value = cw.notas || '';
    document.getElementById('modalNuevaCredWeb').classList.remove('hidden');
}

function cerrarModalUsuario() {
    document.getElementById('modalNuevoUsuario').classList.add('hidden');
}

function editarUsuario(u) {
    document.getElementById('usr_id').value = u.id;
    document.getElementById('usr_nombre').value = u.nombre || '';
    document.getElementById('usr_email').value = u.email || '';
    document.getElementById('usr_rol').value = u.rol || 'Soporte';
    document.getElementById('usr_estado').value = u.estado || 'Activo';
    document.getElementById('usr_pass').value = '';
    document.getElementById('modalNuevoUsuario').classList.remove('hidden');
}

// ==========================================
// FUNCIONES DE GESTIÓN DE SUBREDES & IPS (IPAM)
// ==========================================
let subredSeleccionadaActual = null;

function abrirModalSubred() {
    document.getElementById('modalSubredTitulo').innerHTML = '<i class="fa-solid fa-sitemap text-emerald-600"></i> Registrar Subred IP / Rango';
    document.getElementById('subred_id').value = '';
    document.getElementById('subred_nombre').value = '';
    document.getElementById('subred_cidr').value = '';
    document.getElementById('subred_gateway').value = '';
    document.getElementById('subred_tipo').value = 'Datos / LAN';
    document.getElementById('subred_vlan').value = '';
    document.getElementById('subred_dhcp').value = '';
    document.getElementById('subred_dns').value = '1.1.1.1, 8.8.8.8';
    document.getElementById('subred_estado').value = 'Activa';
    document.getElementById('subred_notas').value = '';
    document.getElementById('modalSubred').classList.remove('hidden');
}

function cerrarModalSubred() {
    document.getElementById('modalSubred').classList.add('hidden');
}

function editarSubred(sub) {
    document.getElementById('modalSubredTitulo').innerHTML = '<i class="fa-solid fa-pen-to-square text-emerald-600"></i> Editar Subred & Direccionamiento IP';
    document.getElementById('subred_id').value = sub.id;
    document.getElementById('subred_sucursal').value = sub.sucursal_id || '';
    document.getElementById('subred_nombre').value = sub.nombre_red || '';
    document.getElementById('subred_cidr').value = sub.segmento_cidr || '';
    document.getElementById('subred_gateway').value = sub.gateway || '';
    document.getElementById('subred_tipo').value = sub.tipo_red || 'Datos / LAN';
    document.getElementById('subred_vlan').value = sub.vlan || '';
    document.getElementById('subred_dhcp').value = sub.rango_dhcp || '';
    document.getElementById('subred_dns').value = sub.dns_servers || '1.1.1.1, 8.8.8.8';
    document.getElementById('subred_estado').value = sub.estado || 'Activa';
    document.getElementById('subred_notas').value = sub.notas || '';
    document.getElementById('modalSubred').classList.remove('hidden');
}

function eliminarSubred(id, nombre) {
    if (confirm(`¿Estás seguro de eliminar la subred "${nombre}" de la matriz de direccionamiento IP?`)) {
        window.location.href = `guardar.php?action=delete_subred&id=${id}`;
    }
}

function verDetalleSubred(sub) {
    subredSeleccionadaActual = sub;
    
    document.getElementById('detalleSubredEmpresa').innerText = sub.empresa_nombre || 'MASTERTEC';
    document.getElementById('detalleSubredCidr').innerText = sub.segmento_cidr || '';
    document.getElementById('detalleSubredEstado').innerText = sub.estado || 'Activa';
    document.getElementById('detalleSubredTitulo').innerText = sub.sucursal_nombre || '';
    document.getElementById('detalleSubredNombre').innerText = sub.nombre_red || '';
    
    const c = sub.cidr_info || {};
    document.getElementById('detalleKpiGateway').innerText = sub.gateway || '';
    document.getElementById('detalleKpiMascara').innerText = c.netmask || '255.255.255.0';
    document.getElementById('detalleKpiBroadcast').innerText = c.broadcast || '';
    document.getElementById('detalleKpiRango').innerText = `${c.first_ip || ''} - ${c.last_ip || ''}`;
    
    document.getElementById('detalleResumenOcupacion').innerText = `${sub.usadas} de ${sub.total_usables} IPs (${sub.libres} libres)`;
    const barra = document.getElementById('detalleBarraOcupacion');
    if (barra) {
        barra.style.width = `${Math.max(4, sub.pct_ocupacion)}%`;
        barra.className = 'h-full rounded-full transition-all duration-300 ' + 
            (sub.pct_ocupacion >= 90 ? 'bg-rose-500' : (sub.pct_ocupacion >= 70 ? 'bg-amber-500' : 'bg-emerald-500'));
    }
    
    const devices = sub.matched_devices || [];
    document.getElementById('detalleConteoDispositivos').innerText = `${devices.length} equipo(s) mapeado(s)`;
    
    const tbody = document.getElementById('detalleTablaDispositivos');
    if (tbody) {
        if (devices.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="6" class="p-8 text-center text-slate-400">
                        <i class="fa-solid fa-network-wired text-2xl text-slate-300 mb-2 block"></i>
                        No hay dispositivos con IP registrada en este segmento actualmente.
                        <p class="text-[11px] text-slate-400 mt-1">Registra switches, APs o servidores en esta sucursal para verlos mapeados aquí.</p>
                    </td>
                </tr>
            `;
        } else {
            tbody.innerHTML = devices.map(d => `
                <tr class="hover:bg-slate-50 transition">
                    <td class="p-3 font-mono font-bold text-slate-800">
                        <span class="inline-flex items-center gap-1.5 bg-slate-100 px-2 py-0.5 rounded-lg border border-slate-200">
                            ${d.ip}
                            <button type="button" onclick="copiarAlPortapapeles('${d.ip}', this)" class="text-slate-400 hover:text-blue-600 transition" title="Copiar IP">
                                <i class="fa-regular fa-copy text-[10px]"></i>
                            </button>
                        </span>
                    </td>
                    <td class="p-3 font-bold text-slate-700">${d.nombre}</td>
                    <td class="p-3 text-slate-600">
                        <span class="px-2 py-0.5 rounded text-[10px] font-semibold ${d.fuente === 'infra' ? 'bg-blue-50 text-blue-700 border border-blue-200' : 'bg-indigo-50 text-indigo-700 border border-indigo-200'}">
                            ${d.tipo}
                        </span>
                    </td>
                    <td class="p-3 text-slate-600">${d.marca || ''} ${d.modelo || ''}</td>
                    <td class="p-3 font-mono text-[11px] text-slate-500">${d.serie_mac || 'N/A'}</td>
                    <td class="p-3">
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold ${d.estado === 'Operativo' ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-slate-100 text-slate-600'}">
                            ${d.estado || 'Activo'}
                        </span>
                    </td>
                </tr>
            `).join('');
        }
    }
    
    document.getElementById('modalDetalleSubred').classList.remove('hidden');
}

function cerrarModalDetalleSubred() {
    document.getElementById('modalDetalleSubred').classList.add('hidden');
}

function registrarDispositivoEnSubredActual() {
    if (!subredSeleccionadaActual) return;
    const sucursalId = subredSeleccionadaActual.sucursal_id;
    cerrarModalDetalleSubred();
    abrirModalDispositivo();
    const selSuc = document.getElementById('dev_sucursal');
    if (selSuc && sucursalId) {
        selSuc.value = sucursalId;
    }
}

let filtroEmpresaSubredActivo = 'all';

function filtrarEmpresaSubred(empresa, btn) {
    filtroEmpresaSubredActivo = empresa;
    
    document.querySelectorAll('#filtrosEmpresaSubred .filtro-empresa-btn').forEach(b => {
        b.className = 'filtro-empresa-btn px-3 py-1.5 rounded-xl text-xs font-bold transition bg-slate-100 hover:bg-slate-200 text-slate-700';
    });
    if (btn) {
        btn.className = 'filtro-empresa-btn px-3 py-1.5 rounded-xl text-xs font-bold transition bg-slate-900 text-white shadow-xs';
    }
    
    filtrarSubredes();
}

function filtrarSubredes() {
    const q = (document.getElementById('busquedaSubredes') ? document.getElementById('busquedaSubredes').value.toLowerCase().trim() : '');
    const cards = document.querySelectorAll('.card-subred');
    
    cards.forEach(card => {
        const text = card.getAttribute('data-search') || '';
        const empresa = card.getAttribute('data-empresa') || '';
        
        const matchQ = !q || text.includes(q);
        let matchEmpresa = true;
        if (filtroEmpresaSubredActivo !== 'all') {
            matchEmpresa = empresa.toUpperCase().includes(filtroEmpresaSubredActivo.toUpperCase());
        }
        
        if (matchQ && matchEmpresa) {
            card.style.display = 'flex';
        } else {
            card.style.display = 'none';
        }
    });
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
