<?php
/**
 * Módulo de Proveedores de Internet & Enlaces WAN
 * Gestión de conexiones ISP, configuraciones de red, IPs fijas y números de cliente por sede.
 */

require_once __DIR__ . '/../../includes/header.php';

$db = Database::getConexion();

// 1. Cargar Empresas y Sucursales para filtros y formularios
$empresas = $db->query("SELECT * FROM empresas ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);

$sucursales = $db->query("
    SELECT s.*, e.nombre as empresa_nombre 
    FROM sucursales s 
    JOIN empresas e ON s.empresa_id = e.id 
    ORDER BY e.nombre ASC, s.nombre ASC
")->fetchAll(PDO::FETCH_ASSOC);

// 2. Cargar Enlaces de Internet con datos de Empresa y Sucursal
$sql_isp = "
    SELECT 
        pi.*,
        e.nombre AS empresa_nombre,
        s.nombre AS sucursal_nombre,
        s.ubicacion AS sucursal_ubicacion
    FROM proveedores_internet pi
    JOIN empresas e ON pi.empresa_id = e.id
    JOIN sucursales s ON pi.sucursal_id = s.id
    ORDER BY pi.id DESC
";
$enlaces = $db->query($sql_isp)->fetchAll(PDO::FETCH_ASSOC);

// KPIs globales
$total_enlaces = count($enlaces);
$total_activos = count(array_filter($enlaces, fn($e) => $e['estado'] === 'Activo'));
$total_principales = count(array_filter($enlaces, fn($e) => $e['tipo_enlace'] === 'Principal'));
$total_backups = count(array_filter($enlaces, fn($e) => stripos($e['tipo_enlace'], 'Backup') !== false));
$sedes_conectadas = count(array_unique(array_column($enlaces, 'sucursal_id')));

// Función para determinar bandera de la sede
function obtenerBanderaSedeISP($sucursalNombre, $ubicacion) {
    $texto = mb_strtolower($sucursalNombre . ' ' . $ubicacion, 'UTF-8');
    if (strpos($texto, 'honduras') !== false) return ['flag' => '🇭🇳', 'pais' => 'Honduras'];
    if (strpos($texto, 'salvador') !== false) return ['flag' => '🇸🇻', 'pais' => 'El Salvador'];
    if (strpos($texto, 'dominicana') !== false) return ['flag' => '🇩🇴', 'pais' => 'Rep. Dominicana'];
    return ['flag' => '🇳🇮', 'pais' => 'Nicaragua'];
}

// Función para estilo del ISP
function obtenerEstiloISP($proveedor) {
    $p = mb_strtolower($proveedor, 'UTF-8');
    if (strpos($p, 'claro') !== false) {
        return [
            'color' => 'bg-red-50 text-red-600 border-red-200',
            'dot'   => 'bg-red-500',
            'icon'  => 'fa-solid fa-tower-cell text-red-500',
            'badge' => 'Claro',
            'header_bg' => 'from-red-500/10 via-red-500/5 to-transparent'
        ];
    }
    if (strpos($p, 'tigo') !== false) {
        return [
            'color' => 'bg-blue-50 text-blue-700 border-blue-200',
            'dot'   => 'bg-blue-600',
            'icon'  => 'fa-solid fa-wifi text-blue-600',
            'badge' => 'Tigo',
            'header_bg' => 'from-blue-600/10 via-blue-600/5 to-transparent'
        ];
    }
    if (strpos($p, 'cootel') !== false) {
        return [
            'color' => 'bg-orange-50 text-orange-700 border-orange-200',
            'dot'   => 'bg-orange-500',
            'icon'  => 'fa-solid fa-satellite-dish text-orange-500',
            'badge' => 'Cootel',
            'header_bg' => 'from-orange-500/10 via-orange-500/5 to-transparent'
        ];
    }
    if (strpos($p, 'ufinet') !== false) {
        return [
            'color' => 'bg-cyan-50 text-cyan-700 border-cyan-200',
            'dot'   => 'bg-cyan-500',
            'icon'  => 'fa-solid fa-network-wired text-cyan-600',
            'badge' => 'Ufinet',
            'header_bg' => 'from-cyan-500/10 via-cyan-500/5 to-transparent'
        ];
    }
    if (strpos($p, 'starlink') !== false) {
        return [
            'color' => 'bg-slate-900 text-white border-slate-700',
            'dot'   => 'bg-cyan-400',
            'icon'  => 'fa-solid fa-satellite text-cyan-400',
            'badge' => 'Starlink',
            'header_bg' => 'from-slate-900/15 via-slate-800/5 to-transparent'
        ];
    }
    return [
        'color' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
        'dot'   => 'bg-emerald-500',
        'icon'  => 'fa-solid fa-globe text-emerald-600',
        'badge' => 'ISP',
        'header_bg' => 'from-emerald-500/10 via-emerald-500/5 to-transparent'
    ];
}
?>

<div class="space-y-8 pb-16">

    <!-- Encabezado de Página -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <div class="flex items-center gap-2">
                <span class="text-xs font-bold uppercase tracking-widest text-cyan-600 bg-cyan-50 px-2.5 py-0.5 rounded-full border border-cyan-200">
                    <i class="fa-solid fa-globe mr-1"></i> Conectividad WAN
                </span>
            </div>
            <h2 class="text-2xl font-bold text-slate-800 tracking-wide mt-1 flex items-center gap-2.5">
                <i class="fa-solid fa-network-wired text-cyan-600"></i>
                <span>Proveedores de Internet & Enlaces WAN</span>
            </h2>
            <p class="text-slate-500 text-sm mt-0.5">Control de enlaces ISP, números de cliente, configuraciones de red, IPs estáticas y puertas de enlace por empresa y sucursal.</p>
        </div>

        <!-- Botones de Acción Superior -->
        <div class="flex items-center gap-2.5 flex-wrap">
            <button type="button" onclick="abrirConsolaPingModal('', 'Diagnóstico General WAN')"
                class="px-3.5 py-2.5 rounded-xl border border-slate-700 bg-slate-900 hover:bg-slate-800 text-cyan-400 font-bold text-xs shadow-sm transition flex items-center gap-2 group">
                <i class="fa-solid fa-terminal text-cyan-400 group-hover:text-cyan-300"></i> Consola Ping
            </button>
            <a href="../infraestructura/index.php?tab=matriz_ip"
                class="px-3.5 py-2.5 rounded-xl border border-slate-200 bg-white hover:bg-slate-50 text-slate-700 font-bold text-xs shadow-sm transition flex items-center gap-2">
                <i class="fa-solid fa-sitemap text-slate-400"></i> Matriz de IPs
            </a>
            <button type="button" onclick="abrirModalNuevoEnlace()"
                class="px-4 py-2.5 rounded-xl bg-cyan-600 hover:bg-cyan-700 text-white font-bold text-xs tracking-wide shadow-lg shadow-cyan-600/20 flex items-center gap-2 transition">
                <i class="fa-solid fa-plus"></i> Registrar Enlace ISP
            </button>
        </div>
    </div>

    <!-- Mensajes Flash -->
    <?php if (!empty($_SESSION['flash_success'])): ?>
        <div class="p-4 rounded-2xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-xs font-semibold flex items-center justify-between animate-fade-in">
            <div class="flex items-center gap-2">
                <i class="fa-solid fa-circle-check text-emerald-600 text-base"></i>
                <span><?= htmlspecialchars($_SESSION['flash_success']) ?></span>
            </div>
            <button onclick="this.parentElement.remove()" class="text-emerald-500 hover:text-emerald-800"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <?php unset($_SESSION['flash_success']); ?>
    <?php endif; ?>

    <?php if (!empty($_SESSION['flash_error'])): ?>
        <div class="p-4 rounded-2xl bg-rose-50 border border-rose-200 text-rose-800 text-xs font-semibold flex items-center justify-between animate-fade-in">
            <div class="flex items-center gap-2">
                <i class="fa-solid fa-triangle-exclamation text-rose-600 text-base"></i>
                <span><?= htmlspecialchars($_SESSION['flash_error']) ?></span>
            </div>
            <button onclick="this.parentElement.remove()" class="text-rose-500 hover:text-rose-800"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <?php unset($_SESSION['flash_error']); ?>
    <?php endif; ?>

    <!-- KPIs Dinámicos -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="bg-white p-5 rounded-3xl border border-slate-200/90 shadow-sm flex items-center gap-3.5">
            <div class="h-12 w-12 rounded-2xl bg-cyan-50 border border-cyan-200 text-cyan-600 flex items-center justify-center text-xl shrink-0">
                <i class="fa-solid fa-globe"></i>
            </div>
            <div>
                <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider block">Total Enlaces</span>
                <span class="text-2xl font-extrabold text-slate-800"><?= $total_enlaces ?></span>
                <span class="text-[11px] text-slate-500 block"><?= $total_activos ?> activos operando</span>
            </div>
        </div>

        <div class="bg-white p-5 rounded-3xl border border-slate-200/90 shadow-sm flex items-center gap-3.5">
            <div class="h-12 w-12 rounded-2xl bg-emerald-50 border border-emerald-200 text-emerald-600 flex items-center justify-center text-xl shrink-0">
                <i class="fa-solid fa-shield-halved"></i>
            </div>
            <div>
                <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider block">Principales vs Backup</span>
                <span class="text-2xl font-extrabold text-emerald-600"><?= $total_principales ?> <span class="text-sm font-normal text-slate-400">/ <?= $total_backups ?> bk</span></span>
                <span class="text-[11px] text-slate-500 block">Alta disponibilidad WAN</span>
            </div>
        </div>

        <div class="bg-white p-5 rounded-3xl border border-slate-200/90 shadow-sm flex items-center gap-3.5">
            <div class="h-12 w-12 rounded-2xl bg-indigo-50 border border-indigo-200 text-indigo-600 flex items-center justify-center text-xl shrink-0">
                <i class="fa-solid fa-building-circle-check"></i>
            </div>
            <div>
                <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider block">Sedes Conectadas</span>
                <span class="text-2xl font-extrabold text-indigo-600"><?= $sedes_conectadas ?></span>
                <span class="text-[11px] text-slate-500 block">Sucursales con enlace</span>
            </div>
        </div>

        <div class="bg-white p-5 rounded-3xl border border-slate-200/90 shadow-sm flex items-center gap-3.5">
            <div class="h-12 w-12 rounded-2xl bg-purple-50 border border-purple-200 text-purple-600 flex items-center justify-center text-xl shrink-0">
                <i class="fa-solid fa-gauge-high"></i>
            </div>
            <div>
                <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider block">Topología WAN</span>
                <span class="text-2xl font-extrabold text-purple-700">Fibra</span>
                <span class="text-[11px] text-slate-500 block">IP Fija / Gateway dedicada</span>
            </div>
        </div>
    </div>

    <!-- Barra de Búsqueda y Filtros -->
    <div class="bg-white p-4 rounded-3xl border border-slate-200 shadow-sm flex flex-col md:flex-row gap-3 items-center justify-between">
        <div class="flex-1 w-full relative flex items-center">
            <i class="fa-solid fa-magnifying-glass absolute left-4 text-slate-400 pointer-events-none text-xs"></i>
            <input type="text" id="filtro-busqueda-isp" oninput="filtrarTarjetasISP()"
                placeholder="Buscar por proveedor, N° cliente, IP pública, Gateway, sucursal o empresa..."
                class="w-full bg-slate-50 border border-slate-200 rounded-xl py-2 pl-10 pr-4 text-xs text-slate-800 focus:outline-none focus:border-cyan-500 transition">
        </div>

        <div class="flex items-center gap-2 overflow-x-auto w-full md:w-auto pb-1 md:pb-0 scrollbar-none">
            <!-- Selector de Empresa Rápido -->
            <button type="button" onclick="filtrarPorEmpresaISP('', this)" class="btn-filtro-emp-isp px-3 py-1.5 rounded-xl text-xs font-bold transition bg-slate-800 text-white shadow-sm shrink-0">
                Todas las Empresas (<?= $total_enlaces ?>)
            </button>
            <?php foreach ($empresas as $emp): 
                $cant_emp_isp = count(array_filter($enlaces, fn($e) => $e['empresa_id'] == $emp['id']));
            ?>
                <button type="button" onclick="filtrarPorEmpresaISP('<?= htmlspecialchars($emp['nombre']) ?>', this)" 
                    class="btn-filtro-emp-isp px-3 py-1.5 rounded-xl text-xs font-semibold bg-white border border-slate-200 text-slate-600 hover:bg-slate-50 transition shadow-xs shrink-0 flex items-center gap-1.5">
                    <span><?= htmlspecialchars($emp['nombre']) ?></span>
                    <span class="text-[10px] bg-slate-100 text-slate-600 px-1.5 py-0.2 rounded-full font-bold font-mono"><?= $cant_emp_isp ?></span>
                </button>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Tarjetas de Proveedores de Internet & Configuración IP -->
    <?php if (empty($enlaces)): ?>
        <div class="bg-white border border-slate-200 rounded-3xl p-12 text-center text-slate-400 shadow-sm">
            <div class="h-16 w-16 bg-cyan-50 text-cyan-500 rounded-full flex items-center justify-center text-3xl mx-auto mb-3">
                <i class="fa-solid fa-network-wired"></i>
            </div>
            <h3 class="text-base font-bold text-slate-700">No hay proveedores de internet registrados</h3>
            <p class="text-xs text-slate-400 mt-1 max-w-md mx-auto">Registra los enlaces de internet por sucursal con su número de cliente, IP estática, máscara y puerta de enlace.</p>
            <button type="button" onclick="abrirModalNuevoEnlace()"
                class="mt-4 px-4 py-2.5 rounded-xl bg-cyan-600 hover:bg-cyan-700 text-white font-bold text-xs tracking-wide shadow-md transition">
                <i class="fa-solid fa-plus mr-1"></i> Registrar Primer Enlace
            </button>
        </div>
    <?php else: ?>
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6" id="contenedor-tarjetas-isp">
            <?php foreach ($enlaces as $e): 
                $estilo = obtenerEstiloISP($e['proveedor']);
                $bandera = obtenerBanderaSedeISP($e['sucursal_nombre'], $e['sucursal_ubicacion'] ?? '');

                // Empresa badge
                $empresaBadgeClass = 'bg-slate-100 text-slate-700 border-slate-200';
                if (stripos($e['empresa_nombre'], 'Mastertec') !== false) {
                    $empresaBadgeClass = 'bg-indigo-50 text-indigo-700 border-indigo-200';
                } elseif (stripos($e['empresa_nombre'], 'Suministros') !== false) {
                    $empresaBadgeClass = 'bg-emerald-50 text-emerald-700 border-emerald-200';
                } elseif (stripos($e['empresa_nombre'], 'Centro') !== false) {
                    $empresaBadgeClass = 'bg-amber-50 text-amber-700 border-amber-200';
                }

                // Enlace type badge
                $isBackup = stripos($e['tipo_enlace'], 'Backup') !== false;
                $tipoEnlaceClass = $isBackup 
                    ? 'bg-amber-50 text-amber-700 border-amber-200' 
                    : 'bg-emerald-50 text-emerald-700 border-emerald-200';

                // Search string
                $searchContent = mb_strtolower(
                    "{$e['proveedor']} {$e['numero_cliente']} {$e['ip_publica']} {$e['puerta_enlace']} {$e['mascara_subred']} {$e['dns_preferido']} {$e['empresa_nombre']} {$e['sucursal_nombre']} {$e['plan_servicio']}",
                    'UTF-8'
                );

                // Configuración completa para copiar
                $configCompleta = "PROVEEDOR: {$e['proveedor']}\n"
                    . "EMPRESA: {$e['empresa_nombre']} - SEDE: {$e['sucursal_nombre']}\n"
                    . "N° CLIENTE: " . ($e['numero_cliente'] ?: 'N/A') . "\n"
                    . "DIRECCIÓN IP: " . ($e['ip_publica'] ?: 'N/A') . "\n"
                    . "MÁSCARA DE SUBRED: " . ($e['mascara_subred'] ?: 'N/A') . "\n"
                    . "PUERTA DE ENLACE: " . ($e['puerta_enlace'] ?: 'N/A') . "\n"
                    . "DNS PREFERIDO: " . ($e['dns_preferido'] ?: 'N/A') . "\n"
                    . "DNS ALTERNATIVO: " . ($e['dns_alternativo'] ?: 'N/A') . "\n"
                    . "VELOCIDAD: " . ($e['velocidad_bajada'] ?: 'N/A') . " / " . ($e['velocidad_subida'] ?: 'N/A');
            ?>
                <div class="tarjeta-isp bg-white border border-slate-200/90 rounded-3xl overflow-hidden shadow-sm hover:shadow-xl hover:border-cyan-300 transition-all duration-300 flex flex-col justify-between group"
                    data-empresa="<?= htmlspecialchars($e['empresa_nombre']) ?>"
                    data-search="<?= htmlspecialchars($searchContent) ?>">

                    <!-- Cabecera de la Tarjeta con Badges -->
                    <div>
                        <div class="p-5 pb-3 bg-gradient-to-br <?= $estilo['header_bg'] ?> border-b border-slate-100 flex items-start justify-between gap-3">
                            <div class="space-y-1.5">
                                <div class="flex items-center gap-1.5 flex-wrap">
                                    <!-- Badge Empresa -->
                                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-lg text-[11px] font-bold border shadow-2xs <?= $empresaBadgeClass ?>">
                                        <i class="fa-solid fa-building text-[9px]"></i>
                                        <?= htmlspecialchars($e['empresa_nombre']) ?>
                                    </span>
                                    <!-- Badge Sede -->
                                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-lg text-[11px] font-semibold bg-white border border-slate-200 text-slate-700 shadow-2xs">
                                        <span><?= $bandera['flag'] ?></span>
                                        <strong><?= htmlspecialchars($e['sucursal_nombre']) ?></strong>
                                    </span>
                                </div>
                                <!-- Nombre del Proveedor ISP -->
                                <div class="flex items-center gap-2 pt-1">
                                    <div class="h-9 w-9 rounded-xl <?= $estilo['color'] ?> border flex items-center justify-center text-base shrink-0 shadow-xs">
                                        <i class="<?= $estilo['icon'] ?>"></i>
                                    </div>
                                    <div>
                                        <h3 class="font-extrabold text-slate-900 text-base leading-tight group-hover:text-cyan-600 transition-colors">
                                            <?= htmlspecialchars($e['proveedor']) ?>
                                        </h3>
                                        <span class="text-[11px] text-slate-500 font-medium block">
                                            <?= htmlspecialchars($e['plan_servicio'] ?: 'Enlace de Internet Corporativo') ?>
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <!-- Estado y Tipo de Enlace -->
                            <div class="flex flex-col items-end gap-1.5 shrink-0">
                                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-extrabold border <?= $tipoEnlaceClass ?>">
                                    <span class="h-1.5 w-1.5 rounded-full <?= $isBackup ? 'bg-amber-500' : 'bg-emerald-500' ?> animate-pulse"></span>
                                    <?= htmlspecialchars($e['tipo_enlace']) ?>
                                </span>
                                <?php if (!empty($e['medio_transmision'])): ?>
                                    <span class="text-[10px] text-slate-500 font-medium bg-slate-100 px-2 py-0.5 rounded-md border border-slate-200">
                                        <i class="fa-solid fa-bolt text-[9px] text-amber-500 mr-0.5"></i> <?= htmlspecialchars($e['medio_transmision']) ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Métricas Rápidas: Número de Cliente & Velocidad -->
                        <div class="p-5 space-y-4">
                            <div class="grid grid-cols-2 gap-3 bg-slate-50/80 p-3.5 rounded-2xl border border-slate-200/80">
                                <!-- N° de Cliente -->
                                <div>
                                    <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">N° de Cliente / Cuenta</span>
                                    <div class="flex items-center gap-1.5 mt-0.5">
                                        <span class="font-mono font-extrabold text-slate-800 text-xs truncate select-all" title="<?= htmlspecialchars($e['numero_cliente']) ?>">
                                            <?= htmlspecialchars($e['numero_cliente'] ?: 'Sin ID registrado') ?>
                                        </span>
                                        <?php if (!empty($e['numero_cliente'])): ?>
                                            <button type="button" onclick="copiarTextoAlPortapapeles('<?= addslashes($e['numero_cliente']) ?>', this)"
                                                class="text-slate-400 hover:text-cyan-600 transition p-0.5" title="Copiar N° de Cliente">
                                                <i class="fa-regular fa-copy text-xs"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Velocidad de Ancho de Banda -->
                                <div>
                                    <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">Ancho de Banda</span>
                                    <div class="font-bold text-cyan-700 text-xs flex items-center gap-1 mt-0.5">
                                        <i class="fa-solid fa-arrows-up-down text-[10px] text-cyan-500"></i>
                                        <span><?= htmlspecialchars(($e['velocidad_bajada'] ?: '--') . ' ↓ / ' . ($e['velocidad_subida'] ?: '--') . ' ↑') ?></span>
                                    </div>
                                </div>
                            </div>

                            <!-- CONSOLA DE CONFIGURACIÓN DE RED & IP (Estilo visual del diseño del usuario con Ping interactivo) -->
                            <div class="bg-gradient-to-b from-slate-900 to-slate-950 border border-slate-800 rounded-2xl p-4 text-slate-200 shadow-md">
                                <div class="flex items-center justify-between pb-2.5 mb-3 border-b border-slate-800 text-xs">
                                    <div class="flex items-center gap-2 font-mono text-[11px] text-cyan-400 font-bold uppercase tracking-wider">
                                        <i class="fa-solid fa-terminal text-xs"></i>
                                        <span>Configuración de Red IP</span>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <button type="button" onclick="lanzarPingDesdeTarjeta('<?= addslashes($e['ip_publica'] ?: $e['puerta_enlace']) ?>', '<?= addslashes($e['proveedor']) ?> (<?= addslashes($e['empresa_nombre']) ?>)', '<?= addslashes($e['ip_publica']) ?>', '<?= addslashes($e['puerta_enlace']) ?>', '<?= addslashes($e['dns_preferido']) ?>', '<?= addslashes($e['dns_alternativo']) ?>')"
                                            class="text-[10px] font-mono px-2 py-0.5 rounded bg-cyan-950/80 hover:bg-cyan-900 text-cyan-300 border border-cyan-700/50 flex items-center gap-1 transition"
                                            title="Abrir consola y diagnosticar este enlace">
                                            <i class="fa-solid fa-bolt text-[9px] text-cyan-400"></i> Ping WAN
                                        </button>
                                        <button type="button" onclick="copiarTextoAlPortapapeles(`<?= addslashes($configCompleta) ?>`, this)"
                                            class="text-[10px] font-mono text-slate-400 hover:text-cyan-400 flex items-center gap-1 transition"
                                            title="Copiar parámetros de red completos">
                                            <i class="fa-regular fa-copy text-[10px]"></i> Copiar Todo
                                        </button>
                                    </div>
                                </div>

                                <div class="space-y-2.5 text-xs font-mono">
                                    <!-- Dirección IP -->
                                    <div>
                                        <span class="text-[10px] text-slate-400 block tracking-wider uppercase font-semibold">Dirección IP</span>
                                        <div class="flex items-center justify-between bg-slate-800/80 border border-slate-700/80 rounded-xl px-3 py-1.5 mt-0.5">
                                            <span class="text-emerald-400 font-bold tracking-wide select-all text-xs truncate mr-2">
                                                <?= htmlspecialchars($e['ip_publica'] ?: 'DHCP / No registrada') ?>
                                            </span>
                                            <div class="flex items-center gap-1.5 shrink-0">
                                                <?php if (!empty($e['ip_publica'])): ?>
                                                    <button type="button" onclick="lanzarPingDesdeTarjeta('<?= addslashes($e['ip_publica']) ?>', '<?= addslashes($e['proveedor']) ?> - IP Pública', '<?= addslashes($e['ip_publica']) ?>', '<?= addslashes($e['puerta_enlace']) ?>', '<?= addslashes($e['dns_preferido']) ?>', '<?= addslashes($e['dns_alternativo']) ?>')"
                                                        class="text-[10px] font-mono px-2 py-0.5 rounded bg-emerald-500/15 hover:bg-emerald-500/25 text-emerald-400 border border-emerald-500/30 transition flex items-center gap-1"
                                                        title="Hacer Ping a la IP Pública">
                                                        <i class="fa-solid fa-terminal text-[9px]"></i> Ping
                                                    </button>
                                                    <button type="button" onclick="copiarTextoAlPortapapeles('<?= addslashes($e['ip_publica']) ?>', this)"
                                                        class="text-slate-400 hover:text-emerald-300 p-0.5" title="Copiar IP">
                                                        <i class="fa-regular fa-copy text-xs"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Máscara de subred -->
                                    <div>
                                        <span class="text-[10px] text-slate-400 block tracking-wider uppercase font-semibold">Máscara de subred</span>
                                        <div class="bg-slate-800/80 border border-slate-700/80 rounded-xl px-3 py-1.5 mt-0.5 text-slate-300 text-xs select-all">
                                            <?= htmlspecialchars($e['mascara_subred'] ?: '255.255.255.0') ?>
                                        </div>
                                    </div>

                                    <!-- Puerta de enlace -->
                                    <div>
                                        <span class="text-[10px] text-slate-400 block tracking-wider uppercase font-semibold">Puerta de enlace</span>
                                        <div class="flex items-center justify-between bg-slate-800/80 border border-slate-700/80 rounded-xl px-3 py-1.5 mt-0.5">
                                            <span class="text-cyan-300 font-bold text-xs select-all truncate mr-2">
                                                <?= htmlspecialchars($e['puerta_enlace'] ?: '--') ?>
                                            </span>
                                            <div class="flex items-center gap-1.5 shrink-0">
                                                <?php if (!empty($e['puerta_enlace'])): ?>
                                                    <button type="button" onclick="lanzarPingDesdeTarjeta('<?= addslashes($e['puerta_enlace']) ?>', '<?= addslashes($e['proveedor']) ?> - Gateway', '<?= addslashes($e['ip_publica']) ?>', '<?= addslashes($e['puerta_enlace']) ?>', '<?= addslashes($e['dns_preferido']) ?>', '<?= addslashes($e['dns_alternativo']) ?>')"
                                                        class="text-[10px] font-mono px-2 py-0.5 rounded bg-cyan-500/15 hover:bg-cyan-500/25 text-cyan-300 border border-cyan-500/30 transition flex items-center gap-1"
                                                        title="Hacer Ping al Gateway">
                                                        <i class="fa-solid fa-terminal text-[9px]"></i> Ping
                                                    </button>
                                                    <button type="button" onclick="copiarTextoAlPortapapeles('<?= addslashes($e['puerta_enlace']) ?>', this)"
                                                        class="text-slate-400 hover:text-cyan-200 p-0.5" title="Copiar Puerta de Enlace">
                                                        <i class="fa-regular fa-copy text-xs"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- DNS Preferido y Alternativo -->
                                    <div class="grid grid-cols-2 gap-2 pt-0.5">
                                        <div>
                                            <span class="text-[10px] text-slate-400 block tracking-wider uppercase font-semibold">DNS preferido</span>
                                            <div class="flex items-center justify-between bg-slate-800/80 border border-slate-700/80 rounded-xl px-2.5 py-1.5 mt-0.5 text-slate-300 text-[11px]">
                                                <span class="truncate select-all mr-1"><?= htmlspecialchars($e['dns_preferido'] ?: '1.1.1.1') ?></span>
                                                <button type="button" onclick="lanzarPingDesdeTarjeta('<?= addslashes($e['dns_preferido'] ?: '1.1.1.1') ?>', 'DNS 1 (<?= addslashes($e['proveedor']) ?>)', '<?= addslashes($e['ip_publica']) ?>', '<?= addslashes($e['puerta_enlace']) ?>', '<?= addslashes($e['dns_preferido']) ?>', '<?= addslashes($e['dns_alternativo']) ?>')"
                                                    class="text-slate-400 hover:text-cyan-400 text-[10px] px-1 shrink-0" title="Ping DNS 1">
                                                    <i class="fa-solid fa-bolt"></i>
                                                </button>
                                            </div>
                                        </div>
                                        <div>
                                            <span class="text-[10px] text-slate-400 block tracking-wider uppercase font-semibold">DNS alternativo</span>
                                            <div class="flex items-center justify-between bg-slate-800/80 border border-slate-700/80 rounded-xl px-2.5 py-1.5 mt-0.5 text-slate-300 text-[11px]">
                                                <span class="truncate select-all mr-1"><?= htmlspecialchars($e['dns_alternativo'] ?: '8.8.8.8') ?></span>
                                                <button type="button" onclick="lanzarPingDesdeTarjeta('<?= addslashes($e['dns_alternativo'] ?: '8.8.8.8') ?>', 'DNS 2 (<?= addslashes($e['proveedor']) ?>)', '<?= addslashes($e['ip_publica']) ?>', '<?= addslashes($e['puerta_enlace']) ?>', '<?= addslashes($e['dns_preferido']) ?>', '<?= addslashes($e['dns_alternativo']) ?>')"
                                                    class="text-slate-400 hover:text-cyan-400 text-[10px] px-1 shrink-0" title="Ping DNS 2">
                                                    <i class="fa-solid fa-bolt"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Equipo Terminal ONT & Soporte -->
                            <?php if (!empty($e['equipo_terminal']) || !empty($e['contacto_soporte'])): ?>
                                <div class="space-y-1.5 text-xs text-slate-600 pt-1">
                                    <?php if (!empty($e['equipo_terminal'])): ?>
                                        <div class="flex items-start gap-1.5 text-[11px]">
                                            <i class="fa-solid fa-server text-slate-400 mt-0.5 text-xs shrink-0"></i>
                                            <span class="truncate"><strong class="text-slate-700">Módem/ONT:</strong> <?= htmlspecialchars($e['equipo_terminal']) ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($e['contacto_soporte'])): ?>
                                        <div class="flex items-start gap-1.5 text-[11px]">
                                            <i class="fa-solid fa-headset text-slate-400 mt-0.5 text-xs shrink-0"></i>
                                            <span class="truncate"><strong class="text-slate-700">Soporte NOC:</strong> <?= htmlspecialchars($e['contacto_soporte']) ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Footer de la Tarjeta (Acciones & Costo) -->
                    <div class="p-4 bg-slate-50/90 border-t border-slate-100 flex items-center justify-between gap-2">
                        <div>
                            <?php if (!empty($e['costo_mensual'])): ?>
                                <span class="text-[11px] font-bold text-slate-700 font-mono">
                                    <?= htmlspecialchars($e['moneda']) ?> $<?= number_format($e['costo_mensual'], 2) ?> <span class="text-[10px] font-normal text-slate-400">/mes</span>
                                </span>
                            <?php else: ?>
                                <span class="text-[11px] text-slate-400 italic">Plan Corporativo</span>
                            <?php endif; ?>
                        </div>

                        <!-- Botones de Acción -->
                        <div class="flex items-center gap-1.5">
                            <button type="button" onclick='abrirEditarEnlace(<?= json_encode($e, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'
                                class="h-8 px-3 rounded-xl bg-white hover:bg-slate-100 text-slate-700 text-xs font-bold border border-slate-200 transition shadow-xs flex items-center gap-1.5"
                                title="Editar enlace">
                                <i class="fa-solid fa-pen-to-square text-xs text-slate-500"></i>
                                <span>Editar</span>
                            </button>
                            <button type="button" onclick="confirmarEliminarEnlace(<?= $e['id'] ?>, '<?= addslashes($e['proveedor']) ?> - <?= addslashes($e['sucursal_nombre']) ?>')"
                                class="h-8 w-8 rounded-xl bg-white hover:bg-rose-50 text-slate-500 hover:text-rose-600 border border-slate-200 hover:border-rose-200 transition shadow-xs flex items-center justify-center"
                                title="Eliminar enlace">
                                <i class="fa-solid fa-trash-can text-xs"></i>
                            </button>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div>

<!-- ================= MODALES ================= -->

<!-- 1. MODAL REGISTRAR NUEVO ENLACE ISP -->
<div id="modal-nuevo-enlace" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[9999] flex items-center justify-center hidden p-4">
    <div class="bg-white border border-slate-200 rounded-3xl w-full max-w-2xl overflow-hidden shadow-2xl animate-fade-in max-h-[90vh] flex flex-col">
        <div class="p-6 border-b border-slate-100 bg-gradient-to-r from-cyan-50 to-white flex items-center justify-between shrink-0">
            <h3 class="font-bold text-slate-800 flex items-center gap-2">
                <i class="fa-solid fa-globe text-cyan-600"></i>
                <span>Registrar Proveedor de Internet & Enlace WAN</span>
            </h3>
            <button type="button" onclick="cerrarModal('modal-nuevo-enlace')" class="text-slate-400 hover:text-slate-800"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <form autocomplete="off" action="guardar.php?action=crear" method="POST" class="p-6 space-y-5 overflow-y-auto">
            <!-- 1. Empresa & Sucursal -->
            <div>
                <h4 class="text-xs font-bold text-cyan-800 uppercase tracking-wider mb-2.5 flex items-center gap-1.5">
                    <i class="fa-solid fa-building text-cyan-600"></i> Ubicación del Enlace
                </h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label for="ne_empresa" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-1.5">Empresa</label>
                        <select name="empresa_id" id="ne_empresa" required onchange="filtrarSucursalesPorEmpresa(this.value, 'ne_sucursal')"
                            class="w-full bg-slate-100 border border-slate-200 rounded-xl py-2.5 px-3.5 text-xs text-slate-800 focus:outline-none focus:border-cyan-500">
                            <option value="" disabled selected>-- Selecciona Empresa --</option>
                            <?php foreach ($empresas as $emp): ?>
                                <option value="<?= $emp['id'] ?>"><?= htmlspecialchars($emp['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="ne_sucursal" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-1.5">Sucursal / Sede</label>
                        <select name="sucursal_id" id="ne_sucursal" required
                            class="w-full bg-slate-100 border border-slate-200 rounded-xl py-2.5 px-3.5 text-xs text-slate-800 focus:outline-none focus:border-cyan-500">
                            <option value="" disabled selected>-- Primero selecciona empresa --</option>
                            <?php foreach ($sucursales as $s): ?>
                                <option value="<?= $s['id'] ?>" data-empresa-id="<?= $s['empresa_id'] ?>"><?= htmlspecialchars($s['empresa_nombre'] . ' - ' . $s['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <!-- 2. Proveedor & Contrato -->
            <div class="pt-2 border-t border-slate-100">
                <h4 class="text-xs font-bold text-cyan-800 uppercase tracking-wider mb-2.5 flex items-center gap-1.5">
                    <i class="fa-solid fa-file-contract text-cyan-600"></i> Datos del Proveedor (ISP)
                </h4>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label for="ne_proveedor" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-1.5">Proveedor (ISP)</label>
                        <input type="text" name="proveedor" id="ne_proveedor" required list="lista_isps" placeholder="Ej: Claro, Tigo, Cootel..."
                            class="w-full bg-slate-100 border border-slate-200 rounded-xl py-2.5 px-3.5 text-xs text-slate-800 focus:outline-none focus:border-cyan-500">
                        <datalist id="lista_isps">
                            <option value="Claro Empresas">
                            <option value="Tigo Business">
                            <option value="Cootel">
                            <option value="Ufinet">
                            <option value="Starlink">
                            <option value="Telecable">
                            <option value="IBW">
                        </datalist>
                    </div>
                    <div>
                        <label for="ne_numero_cliente" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-1.5">N° de Cliente / Contrato</label>
                        <input type="text" name="numero_cliente" id="ne_numero_cliente" placeholder="Ej: CLI-8849201 / Contrato #1290"
                            class="w-full bg-slate-100 border border-slate-200 rounded-xl py-2.5 px-3.5 text-xs font-mono text-slate-800 focus:outline-none focus:border-cyan-500">
                    </div>
                    <div>
                        <label for="ne_plan" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-1.5">Plan de Servicio</label>
                        <input type="text" name="plan_servicio" id="ne_plan" placeholder="Ej: Fibra Óptica Dedicada Pyme"
                            class="w-full bg-slate-100 border border-slate-200 rounded-xl py-2.5 px-3.5 text-xs text-slate-800 focus:outline-none focus:border-cyan-500">
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mt-3">
                    <div>
                        <label for="ne_tipo_enlace" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-1.5">Rol de Enlace</label>
                        <select name="tipo_enlace" id="ne_tipo_enlace" class="w-full bg-slate-100 border border-slate-200 rounded-xl py-2.5 px-3.5 text-xs text-slate-800 focus:outline-none focus:border-cyan-500">
                            <option value="Principal" selected>Principal</option>
                            <option value="Backup / Respaldo">Backup / Respaldo</option>
                            <option value="Dedicado">Dedicado</option>
                            <option value="Banda Ancha">Banda Ancha</option>
                        </select>
                    </div>
                    <div>
                        <label for="ne_medio" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-1.5">Medio Físico</label>
                        <select name="medio_transmision" id="ne_medio" class="w-full bg-slate-100 border border-slate-200 rounded-xl py-2.5 px-3.5 text-xs text-slate-800 focus:outline-none focus:border-cyan-500">
                            <option value="Fibra Óptica" selected>Fibra Óptica</option>
                            <option value="Radioenlace">Radioenlace</option>
                            <option value="Cobre / HFC">Cobre / HFC</option>
                            <option value="Satelital">Satelital</option>
                            <option value="4G / 5G LTE">4G / 5G LTE</option>
                            <option value="Otro">Otro</option>
                        </select>
                    </div>
                    <div>
                        <label for="ne_bajada" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-1.5">Velocidad Bajada</label>
                        <input type="text" name="velocidad_bajada" id="ne_bajada" placeholder="Ej: 100 Mbps"
                            class="w-full bg-slate-100 border border-slate-200 rounded-xl py-2.5 px-3.5 text-xs text-slate-800 focus:outline-none focus:border-cyan-500">
                    </div>
                    <div>
                        <label for="ne_subida" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-1.5">Velocidad Subida</label>
                        <input type="text" name="velocidad_subida" id="ne_subida" placeholder="Ej: 100 Mbps"
                            class="w-full bg-slate-100 border border-slate-200 rounded-xl py-2.5 px-3.5 text-xs text-slate-800 focus:outline-none focus:border-cyan-500">
                    </div>
                </div>
            </div>

            <!-- 3. CONFIGURACIÓN DE RED IP (Consola oscura) -->
            <div class="bg-gradient-to-b from-slate-900 to-slate-950 p-5 rounded-2xl border border-slate-800 text-slate-100 space-y-3">
                <div class="flex items-center justify-between pb-2 border-b border-slate-800">
                    <span class="text-xs font-mono font-bold text-cyan-400 uppercase tracking-wider flex items-center gap-2">
                        <i class="fa-solid fa-network-wired"></i> Parámetros de Configuración IP
                    </span>
                    <span class="text-[10px] text-slate-400 font-mono">Direccionamiento WAN</span>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label for="ne_ip_publica" class="text-[11px] font-mono font-semibold text-slate-400 block mb-1">Dirección IP</label>
                        <input type="text" name="ip_publica" id="ne_ip_publica" placeholder="Ej: 190.212.145.26"
                            class="w-full bg-slate-800/80 border border-slate-700 rounded-xl py-2 px-3 text-xs font-mono text-emerald-400 focus:outline-none focus:border-cyan-400">
                    </div>
                    <div>
                        <label for="ne_mascara" class="text-[11px] font-mono font-semibold text-slate-400 block mb-1">Máscara de subred</label>
                        <input type="text" name="mascara_subred" id="ne_mascara" placeholder="Ej: 255.255.255.248"
                            class="w-full bg-slate-800/80 border border-slate-700 rounded-xl py-2 px-3 text-xs font-mono text-slate-200 focus:outline-none focus:border-cyan-400">
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label for="ne_gateway" class="text-[11px] font-mono font-semibold text-slate-400 block mb-1">Puerta de enlace</label>
                        <input type="text" name="puerta_enlace" id="ne_gateway" placeholder="Ej: 190.212.145.25"
                            class="w-full bg-slate-800/80 border border-slate-700 rounded-xl py-2 px-3 text-xs font-mono text-cyan-300 focus:outline-none focus:border-cyan-400">
                    </div>
                    <div>
                        <label for="ne_dns1" class="text-[11px] font-mono font-semibold text-slate-400 block mb-1">DNS preferido</label>
                        <input type="text" name="dns_preferido" id="ne_dns1" placeholder="Ej: 190.212.0.1 o 1.1.1.1"
                            class="w-full bg-slate-800/80 border border-slate-700 rounded-xl py-2 px-3 text-xs font-mono text-slate-200 focus:outline-none focus:border-cyan-400">
                    </div>
                    <div>
                        <label for="ne_dns2" class="text-[11px] font-mono font-semibold text-slate-400 block mb-1">DNS alternativo</label>
                        <input type="text" name="dns_alternativo" id="ne_dns2" placeholder="Ej: 8.8.8.8"
                            class="w-full bg-slate-800/80 border border-slate-700 rounded-xl py-2 px-3 text-xs font-mono text-slate-200 focus:outline-none focus:border-cyan-400">
                    </div>
                </div>
            </div>

            <!-- 4. Equipo ONT, Soporte y Costos -->
            <div class="pt-2 border-t border-slate-100">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label for="ne_equipo" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-1.5">Equipo Terminal / Módem ONT</label>
                        <input type="text" name="equipo_terminal" id="ne_equipo" placeholder="Ej: Huawei HG8245H / S/N: 48575..."
                            class="w-full bg-slate-100 border border-slate-200 rounded-xl py-2.5 px-3.5 text-xs text-slate-800 focus:outline-none focus:border-cyan-500">
                    </div>
                    <div>
                        <label for="ne_soporte" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-1.5">Contacto Soporte / NOC</label>
                        <input type="text" name="contacto_soporte" id="ne_soporte" placeholder="Ej: 1800-2527 / noc@claro.com.ni"
                            class="w-full bg-slate-100 border border-slate-200 rounded-xl py-2.5 px-3.5 text-xs text-slate-800 focus:outline-none focus:border-cyan-500">
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-3">
                    <div>
                        <label for="ne_costo" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-1.5">Costo Mensual</label>
                        <input type="number" step="0.01" name="costo_mensual" id="ne_costo" placeholder="Ej: 150.00"
                            class="w-full bg-slate-100 border border-slate-200 rounded-xl py-2.5 px-3.5 text-xs text-slate-800 focus:outline-none focus:border-cyan-500">
                    </div>
                    <div>
                        <label for="ne_moneda" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-1.5">Moneda</label>
                        <select name="moneda" id="ne_moneda" class="w-full bg-slate-100 border border-slate-200 rounded-xl py-2.5 px-3.5 text-xs text-slate-800 focus:outline-none focus:border-cyan-500">
                            <option value="USD" selected>USD ($)</option>
                            <option value="NIO">NIO (C$)</option>
                            <option value="HNL">HNL (L)</option>
                            <option value="DOP">DOP ($)</option>
                        </select>
                    </div>
                    <div>
                        <label for="ne_estado" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-1.5">Estado Operativo</label>
                        <select name="estado" id="ne_estado" class="w-full bg-slate-100 border border-slate-200 rounded-xl py-2.5 px-3.5 text-xs text-slate-800 focus:outline-none focus:border-cyan-500">
                            <option value="Activo" selected>Activo</option>
                            <option value="En Mantenimiento">En Mantenimiento</option>
                            <option value="Inactivo">Inactivo</option>
                        </select>
                    </div>
                </div>

                <div class="mt-3">
                    <label for="ne_notas" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-1.5">Notas Técnicas / Observaciones</label>
                    <textarea name="notas" id="ne_notas" rows="2" placeholder="Ej: Conectado a puerto WAN1 de FortiGate en Data Center..."
                        class="w-full bg-slate-100 border border-slate-200 rounded-xl py-2 px-3 text-xs text-slate-800 focus:outline-none focus:border-cyan-500"></textarea>
                </div>
            </div>

            <!-- Botones -->
            <div class="pt-3 flex gap-3">
                <button type="button" onclick="cerrarModal('modal-nuevo-enlace')" class="flex-1 py-3 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold text-xs transition">Cancelar</button>
                <button type="submit" class="flex-1 py-3 rounded-xl bg-cyan-600 hover:bg-cyan-700 text-white font-bold text-xs tracking-wide shadow-md transition">Guardar Enlace ISP</button>
            </div>
        </form>
    </div>
</div>

<!-- 2. MODAL EDITAR ENLACE ISP -->
<div id="modal-editar-enlace" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[9999] flex items-center justify-center hidden p-4">
    <div class="bg-white border border-slate-200 rounded-3xl w-full max-w-2xl overflow-hidden shadow-2xl animate-fade-in max-h-[90vh] flex flex-col">
        <div class="p-6 border-b border-slate-100 bg-gradient-to-r from-cyan-50 to-white flex items-center justify-between shrink-0">
            <h3 class="font-bold text-slate-800 flex items-center gap-2">
                <i class="fa-solid fa-pen-to-square text-cyan-600"></i>
                <span>Editar Enlace de Internet (ISP)</span>
            </h3>
            <button type="button" onclick="cerrarModal('modal-editar-enlace')" class="text-slate-400 hover:text-slate-800"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <form autocomplete="off" action="guardar.php?action=editar" method="POST" class="p-6 space-y-5 overflow-y-auto">
            <input type="hidden" name="id" id="ee_id">

            <!-- 1. Empresa & Sucursal -->
            <div>
                <h4 class="text-xs font-bold text-cyan-800 uppercase tracking-wider mb-2.5 flex items-center gap-1.5">
                    <i class="fa-solid fa-building text-cyan-600"></i> Ubicación del Enlace
                </h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label for="ee_empresa" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-1.5">Empresa</label>
                        <select name="empresa_id" id="ee_empresa" required onchange="filtrarSucursalesPorEmpresa(this.value, 'ee_sucursal')"
                            class="w-full bg-slate-100 border border-slate-200 rounded-xl py-2.5 px-3.5 text-xs text-slate-800 focus:outline-none focus:border-cyan-500">
                            <?php foreach ($empresas as $emp): ?>
                                <option value="<?= $emp['id'] ?>"><?= htmlspecialchars($emp['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="ee_sucursal" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-1.5">Sucursal / Sede</label>
                        <select name="sucursal_id" id="ee_sucursal" required
                            class="w-full bg-slate-100 border border-slate-200 rounded-xl py-2.5 px-3.5 text-xs text-slate-800 focus:outline-none focus:border-cyan-500">
                            <?php foreach ($sucursales as $s): ?>
                                <option value="<?= $s['id'] ?>" data-empresa-id="<?= $s['empresa_id'] ?>"><?= htmlspecialchars($s['empresa_nombre'] . ' - ' . $s['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <!-- 2. Proveedor & Contrato -->
            <div class="pt-2 border-t border-slate-100">
                <h4 class="text-xs font-bold text-cyan-800 uppercase tracking-wider mb-2.5 flex items-center gap-1.5">
                    <i class="fa-solid fa-file-contract text-cyan-600"></i> Datos del Proveedor (ISP)
                </h4>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label for="ee_proveedor" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-1.5">Proveedor (ISP)</label>
                        <input type="text" name="proveedor" id="ee_proveedor" required list="lista_isps"
                            class="w-full bg-slate-100 border border-slate-200 rounded-xl py-2.5 px-3.5 text-xs text-slate-800 focus:outline-none focus:border-cyan-500">
                    </div>
                    <div>
                        <label for="ee_numero_cliente" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-1.5">N° de Cliente / Contrato</label>
                        <input type="text" name="numero_cliente" id="ee_numero_cliente"
                            class="w-full bg-slate-100 border border-slate-200 rounded-xl py-2.5 px-3.5 text-xs font-mono text-slate-800 focus:outline-none focus:border-cyan-500">
                    </div>
                    <div>
                        <label for="ee_plan" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-1.5">Plan de Servicio</label>
                        <input type="text" name="plan_servicio" id="ee_plan"
                            class="w-full bg-slate-100 border border-slate-200 rounded-xl py-2.5 px-3.5 text-xs text-slate-800 focus:outline-none focus:border-cyan-500">
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mt-3">
                    <div>
                        <label for="ee_tipo_enlace" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-1.5">Rol de Enlace</label>
                        <select name="tipo_enlace" id="ee_tipo_enlace" class="w-full bg-slate-100 border border-slate-200 rounded-xl py-2.5 px-3.5 text-xs text-slate-800 focus:outline-none focus:border-cyan-500">
                            <option value="Principal">Principal</option>
                            <option value="Backup / Respaldo">Backup / Respaldo</option>
                            <option value="Dedicado">Dedicado</option>
                            <option value="Banda Ancha">Banda Ancha</option>
                        </select>
                    </div>
                    <div>
                        <label for="ee_medio" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-1.5">Medio Físico</label>
                        <select name="medio_transmision" id="ee_medio" class="w-full bg-slate-100 border border-slate-200 rounded-xl py-2.5 px-3.5 text-xs text-slate-800 focus:outline-none focus:border-cyan-500">
                            <option value="Fibra Óptica">Fibra Óptica</option>
                            <option value="Radioenlace">Radioenlace</option>
                            <option value="Cobre / HFC">Cobre / HFC</option>
                            <option value="Satelital">Satelital</option>
                            <option value="4G / 5G LTE">4G / 5G LTE</option>
                            <option value="Otro">Otro</option>
                        </select>
                    </div>
                    <div>
                        <label for="ee_bajada" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-1.5">Velocidad Bajada</label>
                        <input type="text" name="velocidad_bajada" id="ee_bajada"
                            class="w-full bg-slate-100 border border-slate-200 rounded-xl py-2.5 px-3.5 text-xs text-slate-800 focus:outline-none focus:border-cyan-500">
                    </div>
                    <div>
                        <label for="ee_subida" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-1.5">Velocidad Subida</label>
                        <input type="text" name="velocidad_subida" id="ee_subida"
                            class="w-full bg-slate-100 border border-slate-200 rounded-xl py-2.5 px-3.5 text-xs text-slate-800 focus:outline-none focus:border-cyan-500">
                    </div>
                </div>
            </div>

            <!-- 3. CONFIGURACIÓN DE RED IP (Consola oscura) -->
            <div class="bg-gradient-to-b from-slate-900 to-slate-950 p-5 rounded-2xl border border-slate-800 text-slate-100 space-y-3">
                <div class="flex items-center justify-between pb-2 border-b border-slate-800">
                    <span class="text-xs font-mono font-bold text-cyan-400 uppercase tracking-wider flex items-center gap-2">
                        <i class="fa-solid fa-terminal"></i> Parámetros de Configuración IP
                    </span>
                    <span class="text-[10px] text-slate-400 font-mono">Direccionamiento WAN</span>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label for="ee_ip_publica" class="text-[11px] font-mono font-semibold text-slate-400 block mb-1">Dirección IP</label>
                        <input type="text" name="ip_publica" id="ee_ip_publica"
                            class="w-full bg-slate-800/80 border border-slate-700 rounded-xl py-2 px-3 text-xs font-mono text-emerald-400 focus:outline-none focus:border-cyan-400">
                    </div>
                    <div>
                        <label for="ee_mascara" class="text-[11px] font-mono font-semibold text-slate-400 block mb-1">Máscara de subred</label>
                        <input type="text" name="mascara_subred" id="ee_mascara"
                            class="w-full bg-slate-800/80 border border-slate-700 rounded-xl py-2 px-3 text-xs font-mono text-slate-200 focus:outline-none focus:border-cyan-400">
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label for="ee_gateway" class="text-[11px] font-mono font-semibold text-slate-400 block mb-1">Puerta de enlace</label>
                        <input type="text" name="puerta_enlace" id="ee_gateway"
                            class="w-full bg-slate-800/80 border border-slate-700 rounded-xl py-2 px-3 text-xs font-mono text-cyan-300 focus:outline-none focus:border-cyan-400">
                    </div>
                    <div>
                        <label for="ee_dns1" class="text-[11px] font-mono font-semibold text-slate-400 block mb-1">DNS preferido</label>
                        <input type="text" name="dns_preferido" id="ee_dns1"
                            class="w-full bg-slate-800/80 border border-slate-700 rounded-xl py-2 px-3 text-xs font-mono text-slate-200 focus:outline-none focus:border-cyan-400">
                    </div>
                    <div>
                        <label for="ee_dns2" class="text-[11px] font-mono font-semibold text-slate-400 block mb-1">DNS alternativo</label>
                        <input type="text" name="dns_alternativo" id="ee_dns2"
                            class="w-full bg-slate-800/80 border border-slate-700 rounded-xl py-2 px-3 text-xs font-mono text-slate-200 focus:outline-none focus:border-cyan-400">
                    </div>
                </div>
            </div>

            <!-- 4. Equipo ONT, Soporte y Costos -->
            <div class="pt-2 border-t border-slate-100">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label for="ee_equipo" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-1.5">Equipo Terminal / Módem ONT</label>
                        <input type="text" name="equipo_terminal" id="ee_equipo"
                            class="w-full bg-slate-100 border border-slate-200 rounded-xl py-2.5 px-3.5 text-xs text-slate-800 focus:outline-none focus:border-cyan-500">
                    </div>
                    <div>
                        <label for="ee_soporte" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-1.5">Contacto Soporte / NOC</label>
                        <input type="text" name="contacto_soporte" id="ee_soporte"
                            class="w-full bg-slate-100 border border-slate-200 rounded-xl py-2.5 px-3.5 text-xs text-slate-800 focus:outline-none focus:border-cyan-500">
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-3">
                    <div>
                        <label for="ee_costo" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-1.5">Costo Mensual</label>
                        <input type="number" step="0.01" name="costo_mensual" id="ee_costo"
                            class="w-full bg-slate-100 border border-slate-200 rounded-xl py-2.5 px-3.5 text-xs text-slate-800 focus:outline-none focus:border-cyan-500">
                    </div>
                    <div>
                        <label for="ee_moneda" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-1.5">Moneda</label>
                        <select name="moneda" id="ee_moneda" class="w-full bg-slate-100 border border-slate-200 rounded-xl py-2.5 px-3.5 text-xs text-slate-800 focus:outline-none focus:border-cyan-500">
                            <option value="USD">USD ($)</option>
                            <option value="NIO">NIO (C$)</option>
                            <option value="HNL">HNL (L)</option>
                            <option value="DOP">DOP ($)</option>
                        </select>
                    </div>
                    <div>
                        <label for="ee_estado" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-1.5">Estado Operativo</label>
                        <select name="estado" id="ee_estado" class="w-full bg-slate-100 border border-slate-200 rounded-xl py-2.5 px-3.5 text-xs text-slate-800 focus:outline-none focus:border-cyan-500">
                            <option value="Activo">Activo</option>
                            <option value="En Mantenimiento">En Mantenimiento</option>
                            <option value="Inactivo">Inactivo</option>
                        </select>
                    </div>
                </div>

                <div class="mt-3">
                    <label for="ee_notas" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-1.5">Notas Técnicas / Observaciones</label>
                    <textarea name="notas" id="ee_notas" rows="2"
                        class="w-full bg-slate-100 border border-slate-200 rounded-xl py-2 px-3 text-xs text-slate-800 focus:outline-none focus:border-cyan-500"></textarea>
                </div>
            </div>

            <!-- Botones -->
            <div class="pt-3 flex gap-3">
                <button type="button" onclick="cerrarModal('modal-editar-enlace')" class="flex-1 py-3 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold text-xs transition">Cancelar</button>
                <button type="submit" class="flex-1 py-3 rounded-xl bg-cyan-600 hover:bg-cyan-700 text-white font-bold text-xs tracking-wide shadow-md transition">Guardar Cambios</button>
            </div>
        </form>
    </div>
</div>

<!-- 3. MODAL CONFIRMAR ELIMINACIÓN -->
<div id="modal-eliminar-enlace" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-[9999] flex items-center justify-center hidden p-4">
    <div class="bg-white border border-slate-200 rounded-3xl w-full max-w-sm overflow-hidden shadow-2xl animate-fade-in text-center p-6 space-y-4">
        <div class="h-14 w-14 rounded-full bg-rose-100 text-rose-500 flex items-center justify-center text-2xl mx-auto">
            <i class="fa-solid fa-trash-can"></i>
        </div>
        <div>
            <h4 class="font-bold text-slate-800 text-lg">¿Eliminar Enlace ISP?</h4>
            <p class="text-xs text-slate-500 mt-1" id="del_isp_msg">Esta acción eliminará el registro de conectividad de la base de datos.</p>
        </div>
        <div class="flex gap-2.5 pt-2">
            <button type="button" onclick="cerrarModal('modal-eliminar-enlace')" class="flex-1 py-2.5 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold text-xs transition">Cancelar</button>
            <a href="#" id="del_isp_btn" class="flex-1 py-2.5 rounded-xl bg-rose-500 hover:bg-rose-600 text-white font-bold text-xs shadow-md transition flex items-center justify-center">Sí, eliminar</a>
        </div>
    </div>
</div>

<!-- 4. MODAL CONSOLA DE DIAGNÓSTICO ICMP / PING WAN -->
<div id="modal-consola-ping" class="fixed inset-0 bg-slate-950/80 backdrop-blur-md z-[9999] flex items-center justify-center hidden p-3 sm:p-5">
    <div class="bg-[#0b101b] border border-cyan-500/30 rounded-3xl w-full max-w-3xl overflow-hidden shadow-2xl shadow-cyan-950/60 flex flex-col max-h-[92vh] animate-fade-in text-slate-200">
        
        <!-- Header estilo Terminal Window -->
        <div class="px-5 py-3.5 bg-slate-900/90 border-b border-slate-800 flex items-center justify-between shrink-0">
            <div class="flex items-center gap-3">
                <!-- Mac-style dots -->
                <div class="flex items-center gap-1.5">
                    <button type="button" onclick="cerrarModal('modal-consola-ping')" class="h-3 w-3 rounded-full bg-rose-500 hover:opacity-80 transition cursor-pointer" title="Cerrar consola"></button>
                    <span class="h-3 w-3 rounded-full bg-amber-500 opacity-60"></span>
                    <span class="h-3 w-3 rounded-full bg-emerald-500 opacity-60"></span>
                </div>
                <div class="h-4 w-px bg-slate-700"></div>
                <div class="flex items-center gap-2">
                    <i class="fa-solid fa-terminal text-cyan-400 text-sm"></i>
                    <span class="font-mono text-xs font-bold text-slate-100 tracking-wide">Consola de Diagnóstico ICMP & Ping WAN</span>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[10px] font-mono font-bold bg-cyan-950/80 text-cyan-400 border border-cyan-800/60">
                    <span class="h-1.5 w-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
                    <span>NOC LIVE</span>
                </span>
                <button type="button" onclick="cerrarModal('modal-consola-ping')" class="text-slate-400 hover:text-white transition">
                    <i class="fa-solid fa-xmark text-sm"></i>
                </button>
            </div>
        </div>

        <!-- Cuerpo de la Consola -->
        <div class="p-5 overflow-y-auto space-y-4">
            
            <!-- Barra de Selección de Enlace / Destinos Rápidos -->
            <div>
                <div class="flex items-center justify-between mb-1.5">
                    <span class="text-[11px] font-mono uppercase tracking-wider text-slate-400 font-bold flex items-center gap-1.5">
                        <i class="fa-solid fa-crosshairs text-cyan-400"></i> Destinos Rápidos & Enlaces
                    </span>
                    <span id="ping_active_context_label" class="text-[11px] font-mono text-cyan-400 font-semibold truncate max-w-xs"></span>
                </div>
                <div id="ping_quick_chips" class="flex flex-wrap gap-1.5 font-mono text-xs">
                    <!-- Inyectados dinámicamente por JS -->
                </div>
            </div>

            <!-- Formulario de Entrada de Ping -->
            <form id="form_ping_consola" onsubmit="ejecutarPingDesdeFormulario(event)" class="space-y-2">
                <div class="flex flex-col sm:flex-row gap-2">
                    <div class="relative flex-1">
                        <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none font-mono text-cyan-400 font-bold text-sm">
                            &gt;_
                        </div>
                        <input type="text" id="ping_target_host" required
                            placeholder="Ingrese IP (ej: 190.212.45.10 o 1.1.1.1) o Hostname"
                            class="w-full pl-9 pr-3 py-2.5 bg-slate-900 border border-slate-700 focus:border-cyan-500 rounded-xl font-mono text-xs text-white placeholder-slate-500 outline-none transition focus:ring-1 focus:ring-cyan-500">
                    </div>
                    <input type="hidden" id="ping_target_label" value="">
                    
                    <button type="submit" id="btn_ping_submit"
                        class="px-5 py-2.5 bg-cyan-600 hover:bg-cyan-500 text-white font-mono font-bold text-xs rounded-xl shadow-lg shadow-cyan-900/40 transition flex items-center justify-center gap-2 shrink-0">
                        <i class="fa-solid fa-play text-[11px]"></i>
                        <span>Ejecutar Ping</span>
                    </button>
                </div>
            </form>

            <!-- KPI Banner de Diagnóstico -->
            <div id="ping_kpi_banner" class="hidden grid grid-cols-2 sm:grid-cols-4 gap-2.5 font-mono">
                <div class="p-3 rounded-2xl bg-slate-900/90 border border-slate-800">
                    <span class="text-[10px] text-slate-400 uppercase tracking-wider block">Estado</span>
                    <div id="ping_kpi_status" class="mt-1 flex items-center gap-1.5 font-bold text-xs">
                        <span class="h-2 w-2 rounded-full bg-slate-400"></span>
                        <span>Esperando...</span>
                    </div>
                </div>
                <div class="p-3 rounded-2xl bg-slate-900/90 border border-slate-800">
                    <span class="text-[10px] text-slate-400 uppercase tracking-wider block">Latencia Media</span>
                    <span id="ping_kpi_latency" class="mt-1 font-bold text-sm text-cyan-300 block">--</span>
                </div>
                <div class="p-3 rounded-2xl bg-slate-900/90 border border-slate-800">
                    <span class="text-[10px] text-slate-400 uppercase tracking-wider block">Pérdida de Paquetes</span>
                    <span id="ping_kpi_loss" class="mt-1 font-bold text-sm text-slate-200 block">--</span>
                </div>
                <div class="p-3 rounded-2xl bg-slate-900/90 border border-slate-800">
                    <span class="text-[10px] text-slate-400 uppercase tracking-wider block">Duración</span>
                    <span id="ping_kpi_duration" class="mt-1 font-bold text-sm text-slate-300 block">--</span>
                </div>
            </div>

            <!-- Pantalla Terminal -->
            <div class="rounded-2xl border border-slate-800 bg-[#060911] overflow-hidden shadow-inner">
                <!-- Mini barra de herramientas de la terminal -->
                <div class="px-4 py-2 bg-slate-900/60 border-b border-slate-800/80 flex items-center justify-between text-[11px] font-mono text-slate-400">
                    <div class="flex items-center gap-2">
                        <i class="fa-solid fa-circle text-[7px] text-cyan-500"></i>
                        <span id="terminal_header_label">bash - ping diagnostics</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <button type="button" onclick="copiarSalidaTerminal()" class="hover:text-cyan-400 transition flex items-center gap-1">
                            <i class="fa-regular fa-copy text-[10px]"></i> Copiar
                        </button>
                        <span class="text-slate-700">|</span>
                        <button type="button" onclick="limpiarTerminal()" class="hover:text-rose-400 transition flex items-center gap-1">
                            <i class="fa-solid fa-eraser text-[10px]"></i> Limpiar
                        </button>
                    </div>
                </div>

                <!-- Contenido de salida terminal -->
                <div id="consola_terminal_output" class="p-4 font-mono text-xs leading-relaxed text-slate-300 min-h-[200px] max-h-[300px] overflow-y-auto whitespace-pre-wrap select-all">
<span class="text-slate-500"># Consola de diagnóstico de enlaces WAN y pruebas ICMP activa.</span>
<span class="text-slate-500"># Seleccione un destino rápido arriba o ingrese una IP pública o gateway para probar.</span>
                </div>
            </div>

        </div>

        <!-- Footer -->
        <div class="px-5 py-3 bg-slate-900/90 border-t border-slate-800 flex items-center justify-between text-[11px] font-mono text-slate-400 shrink-0">
            <span class="flex items-center gap-1.5">
                <i class="fa-solid fa-shield-halved text-cyan-400"></i> Ejecución con backend PHP y sanitización estricta RFC
            </span>
            <button type="button" onclick="cerrarModal('modal-consola-ping')" class="px-4 py-1.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 font-semibold transition">
                Cerrar
            </button>
        </div>

    </div>
</div>

<script>
    function abrirModal(id) {
        const m = document.getElementById(id);
        if (m) m.classList.remove('hidden');
    }

    function cerrarModal(id) {
        const m = document.getElementById(id);
        if (m) m.classList.add('hidden');
    }

    function abrirModalNuevoEnlace() {
        abrirModal('modal-nuevo-enlace');
    }

    function filtrarSucursalesPorEmpresa(empresaId, selectSucursalId) {
        const select = document.getElementById(selectSucursalId);
        if (!select) return;

        let visibleCount = 0;
        let primerVisible = null;

        for (let i = 0; i < select.options.length; i++) {
            const opt = select.options[i];
            const empId = opt.getAttribute('data-empresa-id');
            if (!empId) continue; // Skip default disabled

            if (!empresaId || empId == empresaId) {
                opt.style.display = '';
                visibleCount++;
                if (!primerVisible) primerVisible = opt;
            } else {
                opt.style.display = 'none';
            }
        }

        if (primerVisible) {
            primerVisible.selected = true;
        } else {
            select.selectedIndex = 0;
        }
    }

    function abrirEditarEnlace(enlace) {
        document.getElementById('ee_id').value = enlace.id;
        document.getElementById('ee_empresa').value = enlace.empresa_id;
        filtrarSucursalesPorEmpresa(enlace.empresa_id, 'ee_sucursal');
        document.getElementById('ee_sucursal').value = enlace.sucursal_id;
        document.getElementById('ee_proveedor').value = enlace.proveedor || '';
        document.getElementById('ee_numero_cliente').value = enlace.numero_cliente || '';
        document.getElementById('ee_plan').value = enlace.plan_servicio || '';
        document.getElementById('ee_tipo_enlace').value = enlace.tipo_enlace || 'Principal';
        document.getElementById('ee_medio').value = enlace.medio_transmision || 'Fibra Óptica';
        document.getElementById('ee_bajada').value = enlace.velocidad_bajada || '';
        document.getElementById('ee_subida').value = enlace.velocidad_subida || '';
        document.getElementById('ee_ip_publica').value = enlace.ip_publica || '';
        document.getElementById('ee_mascara').value = enlace.mascara_subred || '';
        document.getElementById('ee_gateway').value = enlace.puerta_enlace || '';
        document.getElementById('ee_dns1').value = enlace.dns_preferido || '';
        document.getElementById('ee_dns2').value = enlace.dns_alternativo || '';
        document.getElementById('ee_equipo').value = enlace.equipo_terminal || '';
        document.getElementById('ee_soporte').value = enlace.contacto_soporte || '';
        document.getElementById('ee_costo').value = enlace.costo_mensual || '';
        document.getElementById('ee_moneda').value = enlace.moneda || 'USD';
        document.getElementById('ee_estado').value = enlace.estado || 'Activo';
        document.getElementById('ee_notas').value = enlace.notas || '';

        abrirModal('modal-editar-enlace');
    }

    function confirmarEliminarEnlace(id, descripcion) {
        document.getElementById('del_isp_msg').innerText = `¿Seguro que deseas eliminar el enlace de "${descripcion}"?`;
        document.getElementById('del_isp_btn').href = `guardar.php?action=eliminar&id=${id}`;
        abrirModal('modal-eliminar-enlace');
    }

    function copiarTextoAlPortapapeles(texto, boton) {
        if (!texto) return;
        navigator.clipboard.writeText(texto).then(() => {
            const iconoOriginal = boton.innerHTML;
            boton.innerHTML = '<i class="fa-solid fa-check text-emerald-400"></i>';
            setTimeout(() => {
                boton.innerHTML = iconoOriginal;
            }, 1200);
        }).catch(err => {
            console.error('Error al copiar:', err);
        });
    }

    let _empresaActivaFiltroISP = '';

    function filtrarPorEmpresaISP(empNombre, btn) {
        _empresaActivaFiltroISP = empNombre;
        document.querySelectorAll('.btn-filtro-emp-isp').forEach(b => {
            b.className = 'btn-filtro-emp-isp px-3 py-1.5 rounded-xl text-xs font-semibold bg-white border border-slate-200 text-slate-600 hover:bg-slate-50 transition shadow-xs shrink-0 flex items-center gap-1.5';
        });
        if (btn) {
            btn.className = 'btn-filtro-emp-isp px-3 py-1.5 rounded-xl text-xs font-bold transition bg-slate-800 text-white shadow-sm shrink-0 flex items-center gap-1.5';
        }
        filtrarTarjetasISP();
    }

    function filtrarTarjetasISP() {
        const query = (document.getElementById('filtro-busqueda-isp').value || '').toLowerCase().trim();
        const tarjetas = document.querySelectorAll('.tarjeta-isp');

        tarjetas.forEach(t => {
            const dataEmp = t.getAttribute('data-empresa') || '';
            const dataSearch = t.getAttribute('data-search') || '';

            const matchEmp = !_empresaActivaFiltroISP || dataEmp === _empresaActivaFiltroISP;
            const matchQuery = !query || dataSearch.includes(query);

            if (matchEmp && matchQuery) {
                t.style.display = '';
            } else {
                t.style.display = 'none';
            }
        });
    }

    // ==========================================
    // FUNCIONES DE LA CONSOLA PING & DIAGNÓSTICO
    // ==========================================
    function abrirConsolaPingModal(host = '', label = '', targets = null) {
        document.getElementById('ping_active_context_label').innerText = label ? `[${label}]` : '';
        document.getElementById('ping_target_label').value = label || '';
        
        // Configurar chips rápidos
        const chipsContainer = document.getElementById('ping_quick_chips');
        chipsContainer.innerHTML = '';
        
        const chipItems = [];
        if (targets) {
            if (targets.ip && targets.ip !== '--') chipItems.push({ host: targets.ip, label: 'IP Pública', color: 'bg-emerald-400' });
            if (targets.gateway && targets.gateway !== '--') chipItems.push({ host: targets.gateway, label: 'Gateway', color: 'bg-cyan-400' });
            if (targets.dns1 && targets.dns1 !== '--') chipItems.push({ host: targets.dns1, label: 'DNS 1', color: 'bg-blue-400' });
            if (targets.dns2 && targets.dns2 !== '--') chipItems.push({ host: targets.dns2, label: 'DNS 2', color: 'bg-indigo-400' });
        }
        // Destinos públicos de referencia mundial
        chipItems.push({ host: '1.1.1.1', label: 'Cloudflare (1.1.1.1)', color: 'bg-amber-400' });
        chipItems.push({ host: '8.8.8.8', label: 'Google (8.8.8.8)', color: 'bg-violet-400' });
        chipItems.push({ host: '9.9.9.9', label: 'Quad9 (9.9.9.9)', color: 'bg-slate-400' });

        chipItems.forEach(item => {
            if (!item.host || item.host === '--') return;
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'px-2.5 py-1 rounded-lg bg-slate-900/90 hover:bg-slate-800 text-slate-200 border border-slate-700/80 hover:border-cyan-500 transition flex items-center gap-1.5 text-[11px] cursor-pointer';
            btn.innerHTML = `<span class="h-1.5 w-1.5 rounded-full ${item.color}"></span><span class="text-slate-400 font-normal">${item.label}:</span> <strong>${item.host}</strong>`;
            btn.onclick = () => {
                document.getElementById('ping_target_host').value = item.host;
                document.getElementById('ping_target_label').value = item.label;
                ejecutarPing(item.host, item.label);
            };
            chipsContainer.appendChild(btn);
        });

        if (host) {
            document.getElementById('ping_target_host').value = host;
        }

        abrirModal('modal-consola-ping');

        if (host) {
            ejecutarPing(host, label);
        }
    }

    function lanzarPingDesdeTarjeta(host, label, ip, gateway, dns1, dns2) {
        if (!host || host === '--') {
            alert('Este enlace no tiene una dirección IP o Gateway configurado aún.');
            return;
        }
        abrirConsolaPingModal(host, label, { ip, gateway, dns1, dns2 });
    }

    function ejecutarPingDesdeFormulario(e) {
        e.preventDefault();
        const host = document.getElementById('ping_target_host').value.trim();
        const label = document.getElementById('ping_target_label').value.trim();
        if (!host) return;
        ejecutarPing(host, label);
    }

    function ejecutarPing(host, label) {
        if (!host) return;
        const btn = document.getElementById('btn_ping_submit');
        const term = document.getElementById('consola_terminal_output');
        const kpiBanner = document.getElementById('ping_kpi_banner');
        const kpiStatus = document.getElementById('ping_kpi_status');
        const kpiLatency = document.getElementById('ping_kpi_latency');
        const kpiLoss = document.getElementById('ping_kpi_loss');
        const kpiDuration = document.getElementById('ping_kpi_duration');

        // Estado visual de ejecución
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin text-[11px]"></i> <span>Haciendo Ping...</span>';
        
        kpiBanner.classList.remove('hidden');
        kpiStatus.innerHTML = '<span class="h-2 w-2 rounded-full bg-cyan-400 animate-ping"></span><span class="text-cyan-400">Enviando paquetes...</span>';
        kpiLatency.innerHTML = '<span class="text-slate-400 animate-pulse text-xs">Midiendo...</span>';
        kpiLoss.innerHTML = '<span class="text-slate-400 animate-pulse text-xs">En curso...</span>';
        kpiDuration.innerHTML = '<span class="text-slate-400 animate-pulse text-xs">...</span>';

        const timestamp = new Date().toLocaleTimeString();
        term.innerHTML = `<span class="text-cyan-400 font-bold">[${timestamp}] $ ping -n 4 ${host}</span>\n<span class="text-slate-400">Iniciando diagnóstico ICMP hacia <strong>${host}</strong>${label ? ' [' + label + ']' : ''}...</span>\n`;
        term.scrollTop = term.scrollHeight;

        const formData = new URLSearchParams();
        formData.append('host', host);
        formData.append('label', label || '');

        fetch('ping.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formData.toString()
        })
        .then(r => r.json())
        .then(data => {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-play text-[11px]"></i> <span>Ejecutar Ping</span>';

            if (!data.success) {
                kpiStatus.innerHTML = '<span class="h-2 w-2 rounded-full bg-rose-500"></span><span class="text-rose-400">Error</span>';
                kpiLatency.innerText = 'N/A';
                kpiLoss.innerText = '100%';
                kpiDuration.innerText = '--';
                term.innerHTML += `\n<span class="text-rose-400 font-bold"><i class="fa-solid fa-triangle-exclamation mr-1"></i> Error: ${data.error || 'No se pudo contactar el host.'}</span>\n`;
                term.scrollTop = term.scrollHeight;
                return;
            }

            // Banner KPI
            if (data.status === 'online') {
                kpiStatus.innerHTML = '<span class="h-2 w-2 rounded-full bg-emerald-400 animate-pulse"></span><span class="text-emerald-400 font-bold">ONLINE (100% OK)</span>';
            } else if (data.status === 'degraded') {
                kpiStatus.innerHTML = '<span class="h-2 w-2 rounded-full bg-amber-400"></span><span class="text-amber-400 font-bold">DEGRADADO</span>';
            } else {
                kpiStatus.innerHTML = '<span class="h-2 w-2 rounded-full bg-rose-500"></span><span class="text-rose-400 font-bold">OFFLINE / INALCANZABLE</span>';
            }

            kpiLatency.innerHTML = `<span class="${data.status === 'online' ? 'text-emerald-400' : (data.status === 'degraded' ? 'text-amber-400' : 'text-slate-400')} font-bold text-sm sm:text-base">${data.avg_latency}</span>`;
            kpiLoss.innerHTML = `<span class="${data.loss === '0%' ? 'text-emerald-400' : 'text-rose-400'} font-bold">${data.loss}</span> <span class="text-slate-400 text-xs">(${data.packets_ok}/${data.packets_sent})</span>`;
            kpiDuration.innerText = `${data.duration_ms} ms`;

            // Formatear líneas con colores terminal NOC
            let outputFormatted = '';
            if (data.lines && data.lines.length > 0) {
                data.lines.forEach(line => {
                    if (line.match(/(?:Respuesta desde|Reply from|bytes from)/i)) {
                        outputFormatted += `<span class="text-emerald-400 font-mono">${escapeHtml(line)}</span>\n`;
                    } else if (line.match(/(?:Tiempo de espera agotado|Request timed out|Host de destino inaccesible|Destination host unreachable)/i)) {
                        outputFormatted += `<span class="text-rose-400 font-mono">${escapeHtml(line)}</span>\n`;
                    } else if (line.match(/(?:Estadísticas|Ping statistics|Paquetes:|Packets:)/i)) {
                        outputFormatted += `<span class="text-cyan-300 font-bold font-mono">\n${escapeHtml(line)}</span>\n`;
                    } else if (line.match(/(?:Aproximados de ida y vuelta|Round trip times)/i)) {
                        outputFormatted += `<span class="text-amber-300 font-bold font-mono">${escapeHtml(line)}</span>\n`;
                    } else {
                        outputFormatted += `<span class="text-slate-300 font-mono">${escapeHtml(line)}</span>\n`;
                    }
                });
            } else {
                outputFormatted = escapeHtml(data.raw_output || 'Sin respuesta del sistema.');
            }

            term.innerHTML += `\n${outputFormatted}\n<span class="text-slate-500 font-mono"># Diagnóstico finalizado en ${data.duration_ms}ms · Timestamp: ${data.timestamp}</span>\n`;
            term.scrollTop = term.scrollHeight;
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-play text-[11px]"></i> <span>Ejecutar Ping</span>';
            kpiStatus.innerHTML = '<span class="h-2 w-2 rounded-full bg-rose-500"></span><span class="text-rose-400">Error HTTP</span>';
            term.innerHTML += `\n<span class="text-rose-400 font-bold font-mono">Error de red o comunicación: ${err.message}</span>\n`;
            term.scrollTop = term.scrollHeight;
        });
    }

    function escapeHtml(text) {
        const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return text.replace(/[&<>"']/g, m => map[m]);
    }

    function copiarSalidaTerminal() {
        const term = document.getElementById('consola_terminal_output');
        if (!term) return;
        navigator.clipboard.writeText(term.innerText).then(() => {
            const headerLabel = document.getElementById('terminal_header_label');
            const originalText = headerLabel.innerText;
            headerLabel.innerText = '¡Copiado al portapapeles!';
            headerLabel.className = 'text-emerald-400 font-bold';
            setTimeout(() => {
                headerLabel.innerText = originalText;
                headerLabel.className = '';
            }, 1500);
        });
    }

    function limpiarTerminal() {
        const term = document.getElementById('consola_terminal_output');
        if (term) {
            term.innerHTML = '<span class="text-slate-500"># Consola limpia. Seleccione un enlace o ingrese una IP/host para probar.</span>\n';
        }
    }
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
