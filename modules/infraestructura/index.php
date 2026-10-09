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

// 3. Cargar Bóveda de Accesos Web IT
try {
    $stmt_web = $db->query("SELECT * FROM credenciales_web ORDER BY plataforma ASC");
    $cred_web_raw = $stmt_web->fetchAll(PDO::FETCH_ASSOC);
    
    $cred_web = [];
    foreach ($cred_web_raw as $cw) {
        $cw['pass_plain'] = !empty($cw['password_cifrado']) ? Security::decrypt($cw['password_cifrado']) : '';
        $cred_web[] = $cw;
    }
} catch (Exception $e) {
    $cred_web = [];
}

// 4. Cargar Equipo de IT
try {
    $stmt_it = $db->query("SELECT * FROM equipo_it ORDER BY id ASC");
    $equipo_it = $stmt_it->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $equipo_it = [];
}

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
                <button onclick="document.getElementById('modalNuevaCredWeb').classList.remove('hidden')" class="px-4 py-2.5 rounded-xl bg-gradient-to-r from-cyan-600 to-teal-600 hover:from-cyan-700 hover:to-teal-700 text-white font-bold text-xs shadow-lg shadow-cyan-500/20 transition flex items-center gap-2">
                    <i class="fa-solid fa-vault"></i> + Nueva Credencial Web IT
                </button>
            <?php elseif ($tab === 'equipo_it'): ?>
                <button onclick="document.getElementById('modalNuevoUsuario').classList.remove('hidden')" class="px-4 py-2.5 rounded-xl bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-700 hover:to-indigo-700 text-white font-bold text-xs shadow-lg shadow-purple-500/20 transition flex items-center gap-2">
                    <i class="fa-solid fa-user-plus"></i> + Nuevo Integrante IT
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
    <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
        <div class="bg-white/80 backdrop-blur-xl p-4 rounded-2xl border border-slate-200/80 shadow-sm flex items-center gap-4">
            <div class="h-12 w-12 rounded-xl bg-blue-500/10 text-blue-600 flex items-center justify-center text-xl shrink-0">
                <i class="fa-solid fa-network-wired"></i>
            </div>
            <div>
                <p class="text-2xl font-black text-slate-800"><?= $total_dispositivos ?></p>
                <p class="text-xs text-slate-500 font-medium">Equipos IP Red</p>
            </div>
        </div>

        <div class="bg-white/80 backdrop-blur-xl p-4 rounded-2xl border border-slate-200/80 shadow-sm flex items-center gap-4">
            <div class="h-12 w-12 rounded-xl bg-indigo-500/10 text-indigo-600 flex items-center justify-center text-xl shrink-0">
                <i class="fa-solid fa-wifi"></i>
            </div>
            <div>
                <p class="text-2xl font-black text-slate-800"><?= $switches_aps ?></p>
                <p class="text-xs text-slate-500 font-medium">Switches & APs</p>
            </div>
        </div>

        <div class="bg-white/80 backdrop-blur-xl p-4 rounded-2xl border border-slate-200/80 shadow-sm flex items-center gap-4">
            <div class="h-12 w-12 rounded-xl bg-purple-500/10 text-purple-600 flex items-center justify-center text-xl shrink-0">
                <i class="fa-solid fa-server"></i>
            </div>
            <div>
                <p class="text-2xl font-black text-slate-800"><?= $servidores ?></p>
                <p class="text-xs text-slate-500 font-medium">Servidores</p>
            </div>
        </div>

        <div class="bg-white/80 backdrop-blur-xl p-4 rounded-2xl border border-slate-200/80 shadow-sm flex items-center gap-4">
            <div class="h-12 w-12 rounded-xl bg-cyan-500/10 text-cyan-600 flex items-center justify-center text-xl shrink-0">
                <i class="fa-solid fa-vault"></i>
            </div>
            <div>
                <p class="text-2xl font-black text-slate-800"><?= $total_boveda ?></p>
                <p class="text-xs text-slate-500 font-medium">Credenciales Bóveda</p>
            </div>
        </div>

        <div class="bg-white/80 backdrop-blur-xl p-4 rounded-2xl border border-slate-200/80 shadow-sm flex items-center gap-4 col-span-2 md:col-span-1">
            <div class="h-12 w-12 rounded-xl bg-emerald-500/10 text-emerald-600 flex items-center justify-center text-xl shrink-0">
                <i class="fa-solid fa-users-gear"></i>
            </div>
            <div>
                <p class="text-2xl font-black text-slate-800"><?= $total_operadores_it ?></p>
                <p class="text-xs text-slate-500 font-medium">Equipo de IT</p>
            </div>
        </div>
    </div>

    <!-- Pestañas de Navegación del Módulo -->
    <div class="flex border-b border-slate-200 gap-4 text-sm font-bold overflow-x-auto">
        <a href="?tab=equipos_red" class="pb-3 px-2 flex items-center gap-2 border-b-2 transition shrink-0 <?= ($tab === 'equipos_red' || empty($tab)) ? 'border-blue-600 text-blue-600' : 'border-transparent text-slate-400 hover:text-slate-600' ?>">
            <i class="fa-solid fa-server"></i> Dispositivos IP & Red (<?= $total_dispositivos ?>)
        </a>
        <a href="?tab=boveda" class="pb-3 px-2 flex items-center gap-2 border-b-2 transition shrink-0 <?= $tab === 'boveda' ? 'border-cyan-600 text-cyan-600' : 'border-transparent text-slate-400 hover:text-slate-600' ?>">
            <i class="fa-solid fa-vault"></i> Bóveda Cifrada de Accesos IT (<?= $total_boveda ?>)
        </a>
        <a href="?tab=equipo_it" class="pb-3 px-2 flex items-center gap-2 border-b-2 transition shrink-0 <?= $tab === 'equipo_it' ? 'border-purple-600 text-purple-600' : 'border-transparent text-slate-400 hover:text-slate-600' ?>">
            <i class="fa-solid fa-user-shield"></i> Integrantes de IT (<?= $total_operadores_it ?>)
        </a>
        <a href="?tab=matriz_ip" class="pb-3 px-2 flex items-center gap-2 border-b-2 transition shrink-0 <?= $tab === 'matriz_ip' ? 'border-emerald-600 text-emerald-600' : 'border-transparent text-slate-400 hover:text-slate-600' ?>">
            <i class="fa-solid fa-sitemap"></i> Matriz de Subredes & IPs
        </a>
    </div>

    <!-- TAB 1: DISPOSITIVOS IP & RED -->
    <?php if ($tab === 'equipos_red' || empty($tab)): ?>
        <div class="bg-white/80 backdrop-blur-xl rounded-3xl border border-slate-200/80 shadow-sm overflow-hidden p-6 space-y-4">
            <!-- Barra de Búsqueda y Filtros de Red -->
            <div class="flex flex-col md:flex-row gap-3 justify-between items-center">
                <div class="relative w-full md:w-80">
                    <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-3 text-slate-400 text-xs"></i>
                    <input type="text" id="busquedaInfra" onkeyup="filtrarInfra()" placeholder="Buscar equipo, IP, marca, MAC, sucursal..." class="w-full pl-9 pr-4 py-2.5 rounded-xl border border-slate-200 text-xs focus:ring-2 focus:ring-blue-500 outline-none">
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
        </div>
    <?php endif; ?>

    <!-- TAB 2: BÓVEDA CIFRADA DE ACCESOS IT -->
    <?php if ($tab === 'boveda'): ?>
        <div class="bg-white/80 backdrop-blur-xl rounded-3xl border border-slate-200/80 shadow-sm p-6 space-y-4">
            <div class="flex flex-col md:flex-row justify-between items-center gap-3">
                <div>
                    <h3 class="text-lg font-black text-slate-800 flex items-center gap-2">
                        <i class="fa-solid fa-vault text-cyan-600"></i> Bóveda Compartida de Accesos Web & Servicios IT
                    </h3>
                    <p class="text-xs text-slate-500">Credenciales cifradas con AES-256 para portales web, routers, cloud y administración compartida.</p>
                </div>
                <div class="w-full md:w-72">
                    <input type="text" id="searchBoveda" onkeyup="filtrarBoveda()" placeholder="Buscar portal, usuario o enlace..." class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs outline-none focus:ring-2 focus:ring-cyan-500">
                </div>
            </div>

            <div class="overflow-x-auto rounded-2xl border border-slate-200">
                <table class="w-full text-left text-xs" id="tablaBoveda">
                    <thead class="bg-slate-50 text-slate-700 uppercase font-black tracking-wider text-[11px] border-b border-slate-200">
                        <tr>
                            <th class="py-3 px-4">#</th>
                            <th class="py-3 px-4">Plataforma / Portal</th>
                            <th class="py-3 px-4">Enlace de Acceso</th>
                            <th class="py-3 px-4">Usuario</th>
                            <th class="py-3 px-4">Contraseña Cifrada</th>
                            <th class="py-3 px-4">Notas</th>
                            <th class="py-3 px-4 text-center">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 font-medium">
                        <?php if (empty($cred_web)): ?>
                            <tr>
                                <td colspan="7" class="py-8 text-center text-slate-400">No hay credenciales web registradas en la bóveda de IT.</td>
                            </tr>
                        <?php else: ?>
                            <?php $idx=1; foreach ($cred_web as $cw): ?>
                                <tr class="hover:bg-cyan-50/40 transition fila-boveda">
                                    <td class="py-3 px-4 font-mono font-bold text-slate-400"><?= $idx++ ?></td>
                                    <td class="py-3 px-4 font-bold text-slate-800">
                                        <i class="fa-solid fa-globe text-cyan-600 mr-1.5"></i> <?= htmlspecialchars($cw['plataforma']) ?>
                                    </td>
                                    <td class="py-3 px-4 font-mono text-xs">
                                        <?php if (!empty($cw['url_acceso'])): ?>
                                            <a href="<?= htmlspecialchars($cw['url_acceso']) ?>" target="_blank" class="text-cyan-600 hover:underline flex items-center gap-1">
                                                <?= htmlspecialchars(substr($cw['url_acceso'], 0, 35)) ?> <i class="fa-solid fa-arrow-up-right-from-square text-[10px]"></i>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-slate-400">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3 px-4 font-semibold text-slate-700"><?= htmlspecialchars($cw['usuario']) ?></td>
                                    <td class="py-3 px-4">
                                        <div class="flex items-center gap-2">
                                            <span id="pass_cw_<?= $cw['id'] ?>" class="font-mono font-bold text-slate-800 hidden bg-slate-100 px-2 py-0.5 rounded border"><?= htmlspecialchars($cw['pass_plain']) ?></span>
                                            <span id="pass_cw_mask_<?= $cw['id'] ?>" class="font-mono text-slate-400">••••••••••••</span>
                                            <button onclick="togglePass('pass_cw_<?= $cw['id'] ?>', 'pass_cw_mask_<?= $cw['id'] ?>')" class="text-cyan-600 hover:text-cyan-800 text-xs">
                                                <i class="fa-solid fa-eye"></i>
                                            </button>
                                            <button onclick="navigator.clipboard.writeText('<?= htmlspecialchars($cw['pass_plain']) ?>')" title="Copiar Contraseña" class="text-slate-400 hover:text-cyan-600 text-xs">
                                                <i class="fa-regular fa-copy"></i>
                                            </button>
                                        </div>
                                    </td>
                                    <td class="py-3 px-4 text-slate-500 text-[11px]"><?= htmlspecialchars($cw['notas'] ?? '-') ?></td>
                                    <td class="py-3 px-4 text-center">
                                        <div class="flex items-center justify-center gap-1">
                                            <button onclick='editarBoveda(<?= json_encode($cw, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' title="Editar" class="p-1.5 rounded-lg bg-slate-100 text-slate-600 hover:bg-slate-200">
                                                <i class="fa-solid fa-pen"></i>
                                            </button>
                                            <a href="guardar.php?action=delete_boveda&id=<?= $cw['id'] ?>" onclick="return confirm('¿Eliminar esta credencial web de la bóveda?')" class="p-1.5 rounded-lg bg-rose-50 text-rose-600 hover:bg-rose-600 hover:text-white">
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

                        <div class="flex items-center gap-2 mb-4">
                            <span class="px-2.5 py-1 rounded-lg border text-[11px] font-bold <?= $roleBadge ?>">
                                <i class="fa-solid fa-user-shield text-[10px]"></i> <?= $user['rol'] ?>
                            </span>
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold <?= $user['estado'] === 'Activo' ? 'bg-emerald-50 text-emerald-600 border border-emerald-200' : 'bg-rose-50 text-rose-600 border border-rose-200' ?>">
                                ● <?= $user['estado'] ?>
                            </span>
                        </div>
                    </div>

                    <div class="pt-4 border-t border-slate-100 flex items-center justify-between text-xs">
                        <span class="text-slate-400 font-mono">ID #<?= str_pad($user['id'], 3, '0', STR_PAD_LEFT) ?></span>
                        <div class="flex items-center gap-2">
                            <button onclick='editarUsuario(<?= json_encode($user, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' class="px-3 py-1.5 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold transition flex items-center gap-1">
                                <i class="fa-solid fa-pen"></i> Editar
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

    <!-- TAB 4: MATRIZ DE SUBREDES & IPS -->
    <?php if ($tab === 'matriz_ip'): ?>
        <div class="bg-white/80 backdrop-blur-xl rounded-3xl border border-slate-200/80 shadow-sm p-6 space-y-6">
            <div>
                <h3 class="text-lg font-black text-slate-800 flex items-center gap-2">
                    <i class="fa-solid fa-sitemap text-emerald-600"></i> Mapa & Matriz de Direccionamiento IP por Sucursal
                </h3>
                <p class="text-xs text-slate-500">Distribución de subredes corporativas, rangos asignados e infraestructura por sede.</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php 
                $subredes = [
                    ['sucursal' => 'Managua Central', 'empresa' => 'MASTERTEC', 'segmento' => '192.168.10.0/24', 'gateway' => '192.168.10.1', 'vlan' => 'VLAN 10 (Datos), VLAN 20 (WiFi)', 'usadas' => count(array_filter($dispositivos, fn($x) => strpos($x['sucursal_nombre'] ?? '', 'Managua') !== false))],
                    ['sucursal' => 'León Sucursal', 'empresa' => 'MASTERTEC', 'segmento' => '192.168.20.0/24', 'gateway' => '192.168.20.1', 'vlan' => 'VLAN 1 (Nivel 1)', 'usadas' => count(array_filter($dispositivos, fn($x) => strpos($x['sucursal_nombre'] ?? '', 'León') !== false))],
                    ['sucursal' => 'Centro', 'empresa' => 'MASTERTEC', 'segmento' => '192.168.30.0/24', 'gateway' => '192.168.30.1', 'vlan' => 'VLAN 30', 'usadas' => count(array_filter($dispositivos, fn($x) => strpos($x['sucursal_nombre'] ?? '', 'Centro') !== false))],
                    ['sucursal' => 'Suministros Managua', 'empresa' => 'SUMINISTROS', 'segmento' => '192.168.40.0/24', 'gateway' => '192.168.40.1', 'vlan' => 'VLAN 40', 'usadas' => 0],
                    ['sucursal' => 'Suministros Sebaco', 'empresa' => 'SUMINISTROS', 'segmento' => '192.168.50.0/24', 'gateway' => '192.168.50.1', 'vlan' => 'VLAN 50', 'usadas' => 0],
                    ['sucursal' => 'Suministros Leon', 'empresa' => 'SUMINISTROS', 'segmento' => '192.168.60.0/24', 'gateway' => '192.168.60.1', 'vlan' => 'VLAN 60', 'usadas' => 0],
                ];
                foreach ($subredes as $sub): 
                    $pct = min(100, max(5, ($sub['usadas'] * 10)));
                ?>
                    <div class="bg-slate-50/80 rounded-2xl p-5 border border-slate-200">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-xs font-bold text-blue-600 bg-blue-50 px-2 py-0.5 rounded-md border border-blue-200"><?= $sub['empresa'] ?></span>
                            <span class="text-[11px] font-mono text-slate-500 font-bold"><?= $sub['segmento'] ?></span>
                        </div>
                        <h4 class="font-black text-slate-800 text-base"><?= $sub['sucursal'] ?></h4>
                        <div class="mt-3 space-y-1 text-xs font-mono text-slate-600">
                            <p><span class="text-slate-400">Gateway:</span> <?= $sub['gateway'] ?></p>
                            <p><span class="text-slate-400">VLANs:</span> <?= $sub['vlan'] ?></p>
                            <p><span class="text-slate-400">Equipos Registrados:</span> <strong class="text-slate-800"><?= $sub['usadas'] ?> IP(s)</strong></p>
                        </div>
                        
                        <div class="mt-4">
                            <div class="flex justify-between text-[10px] text-slate-500 mb-1 font-bold">
                                <span>Ocupación de IP</span>
                                <span><?= $sub['usadas'] ?> IPs registradas</span>
                            </div>
                            <div class="w-full h-2 bg-slate-200 rounded-full overflow-hidden">
                                <div class="h-full bg-emerald-500 rounded-full" style="width: <?= $pct ?>%"></div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
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

function filtrarInfra() {
    const q = document.getElementById('busquedaInfra').value.toLowerCase();
    const tipo = document.getElementById('filtroTipoInfra').value;
    const sucursal = document.getElementById('filtroSucursalInfra').value;
    const filas = document.querySelectorAll('.fila-infra');

    filas.forEach(f => {
        const text = f.innerText.toLowerCase();
        const fTipo = f.getAttribute('data-tipo');
        const fSuc = f.getAttribute('data-sucursal');

        const matchQ = !q || text.includes(q);
        const matchTipo = !tipo || fTipo === tipo;
        const matchSuc = !sucursal || fSuc.includes(sucursal);

        if (matchQ && matchTipo && matchSuc) {
            f.style.display = '';
        } else {
            f.style.display = 'none';
        }
    });
}

function filtrarBoveda() {
    const q = document.getElementById('searchBoveda').value.toLowerCase();
    const filas = document.querySelectorAll('.fila-boveda');
    filas.forEach(f => {
        const text = f.innerText.toLowerCase();
        f.style.display = (!q || text.includes(q)) ? '' : 'none';
    });
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

function cerrarModalBoveda() {
    document.getElementById('modalNuevaCredWeb').classList.add('hidden');
}

function editarBoveda(cw) {
    document.getElementById('boveda_id').value = cw.id;
    document.getElementById('boveda_plataforma').value = cw.plataforma || '';
    document.getElementById('boveda_url').value = cw.url_acceso || '';
    document.getElementById('boveda_usuario').value = cw.usuario || '';
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
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
