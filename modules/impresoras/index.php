<?php
/**
 * Módulo de Impresoras por Sucursal
 * Gestión centralizada de impresoras, conexiones de red/USB, credenciales y consumibles por sede.
 */

session_start();
require_once __DIR__ . '/../../config/conexion.php';
require_once __DIR__ . '/../../config/security.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}

$db = Database::getConexion();

// 1. Cargar Sucursales y Empresas
$sucursales = $db->query("
    SELECT s.id, s.nombre, s.ubicacion, e.nombre as empresa_nombre 
    FROM sucursales s 
    LEFT JOIN empresas e ON s.empresa_id = e.id 
    ORDER BY e.nombre ASC, s.nombre ASC
")->fetchAll(PDO::FETCH_ASSOC);

// 2. Cargar Impresoras
$sql = "
    SELECT imp.*, 
           s.nombre AS sucursal_nombre, 
           s.ubicacion AS sucursal_ubicacion,
           e.nombre AS empresa_nombre
    FROM impresoras imp
    LEFT JOIN sucursales s ON imp.sucursal_id = s.id
    LEFT JOIN empresas e ON s.empresa_id = e.id
    ORDER BY e.nombre ASC, s.nombre ASC, imp.nombre ASC
";
$stmt = $db->query($sql);
$impresoras_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

$impresoras = [];
$total_red = 0;
$total_operativas = 0;
$sedes_cubiertas = [];

foreach ($impresoras_raw as $imp) {
    $imp['pass_plain'] = !empty($imp['password_cifrado']) ? Security::decrypt($imp['password_cifrado']) : '';
    $impresoras[] = $imp;

    if (!empty($imp['ip_direccion'])) {
        $total_red++;
    }
    if ($imp['estado'] === 'Operativa') {
        $total_operativas++;
    }
    if (!empty($imp['sucursal_id'])) {
        $sedes_cubiertas[$imp['sucursal_id']] = true;
    }
}

$total_impresoras = count($impresoras);
$total_sedes_cubiertas = count($sedes_cubiertas);

// Función auxiliar para bandera de país
function obtenerBanderaPaisImpresora($sucursalNombre, $ubicacion) {
    $texto = mb_strtolower($sucursalNombre . ' ' . $ubicacion, 'UTF-8');
    if (strpos($texto, 'honduras') !== false) return '🇭🇳';
    if (strpos($texto, 'salvador') !== false) return '🇸🇻';
    if (strpos($texto, 'dominicana') !== false) return '🇩🇴';
    return '🇳🇮';
}

$current_page = 'impresoras';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="space-y-6 animate-fade-in-up">
    <!-- Encabezado Principal del Módulo -->
    <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4 bg-white/80 backdrop-blur-xl p-6 rounded-3xl border border-slate-200/80 shadow-sm">
        <div>
            <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-blue-50 border border-blue-200 text-blue-700 text-xs font-bold mb-2">
                <i class="fa-solid fa-print"></i> Equipos de Impresión Corporativos
            </div>
            <h2 class="text-2xl font-black text-slate-800 tracking-wide">
                Impresoras por Sucursal
            </h2>
            <p class="text-xs text-slate-500 mt-1">Control de impresoras de red y locales, direcciones IP, accesos administrativos e insumos por sede.</p>
        </div>
        
        <div class="flex flex-wrap items-center gap-2">
            <button onclick="abrirModalImpresora()" class="px-4 py-2.5 rounded-xl bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white font-bold text-xs shadow-lg shadow-blue-500/20 transition flex items-center gap-2">
                <i class="fa-solid fa-plus-circle"></i> + Registrar Impresora
            </button>
        </div>
    </div>

    <!-- Mensajes Flash -->
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

    <!-- KPI Cards Resumen -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3.5">
        <div class="bg-white/90 backdrop-blur-xl p-4 rounded-2xl border border-slate-200/80 shadow-xs hover:shadow-md hover:-translate-y-0.5 transition-all duration-200 flex flex-col justify-between">
            <div class="flex items-center justify-between mb-2">
                <span class="w-10 h-10 rounded-xl bg-blue-500/10 text-blue-600 flex items-center justify-center text-lg shadow-2xs">
                    <i class="fa-solid fa-print"></i>
                </span>
                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-blue-50 text-blue-700 border border-blue-200">Parque Activo</span>
            </div>
            <div>
                <p class="text-2xl font-black text-slate-800 tracking-tight"><?= $total_impresoras ?></p>
                <p class="text-xs text-slate-500 font-semibold mt-0.5">Total Impresoras</p>
            </div>
        </div>

        <div class="bg-white/90 backdrop-blur-xl p-4 rounded-2xl border border-slate-200/80 shadow-xs hover:shadow-md hover:-translate-y-0.5 transition-all duration-200 flex flex-col justify-between">
            <div class="flex items-center justify-between mb-2">
                <span class="w-10 h-10 rounded-xl bg-indigo-500/10 text-indigo-600 flex items-center justify-center text-lg shadow-2xs">
                    <i class="fa-solid fa-network-wired"></i>
                </span>
                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-indigo-50 text-indigo-700 border border-indigo-200">En Red</span>
            </div>
            <div>
                <p class="text-2xl font-black text-slate-800 tracking-tight"><?= $total_red ?></p>
                <p class="text-xs text-slate-500 font-semibold mt-0.5">Conectadas LAN / IP</p>
            </div>
        </div>

        <div class="bg-white/90 backdrop-blur-xl p-4 rounded-2xl border border-slate-200/80 shadow-xs hover:shadow-md hover:-translate-y-0.5 transition-all duration-200 flex flex-col justify-between">
            <div class="flex items-center justify-between mb-2">
                <span class="w-10 h-10 rounded-xl bg-emerald-500/10 text-emerald-600 flex items-center justify-center text-lg shadow-2xs">
                    <i class="fa-solid fa-circle-check"></i>
                </span>
                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">Operativas</span>
            </div>
            <div>
                <p class="text-2xl font-black text-slate-800 tracking-tight"><?= $total_operativas ?></p>
                <p class="text-xs text-slate-500 font-semibold mt-0.5">En Funcionamiento</p>
            </div>
        </div>

        <div class="bg-white/90 backdrop-blur-xl p-4 rounded-2xl border border-slate-200/80 shadow-xs hover:shadow-md hover:-translate-y-0.5 transition-all duration-200 flex flex-col justify-between">
            <div class="flex items-center justify-between mb-2">
                <span class="w-10 h-10 rounded-xl bg-purple-500/10 text-purple-600 flex items-center justify-center text-lg shadow-2xs">
                    <i class="fa-solid fa-building"></i>
                </span>
                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-purple-50 text-purple-700 border border-purple-200">Cobertura</span>
            </div>
            <div>
                <p class="text-2xl font-black text-slate-800 tracking-tight"><?= $total_sedes_cubiertas ?></p>
                <p class="text-xs text-slate-500 font-semibold mt-0.5">Sedes con Impresoras</p>
            </div>
        </div>
    </div>

    <!-- Contenedor Principal: Búsqueda, Filtros y Tabla -->
    <div class="bg-white/80 backdrop-blur-xl rounded-3xl border border-slate-200/80 shadow-sm overflow-hidden p-6 space-y-5">
        <!-- Barra de Búsqueda y Filtros de Cabecera -->
        <div class="flex flex-col md:flex-row gap-3 justify-between items-center">
            <div class="relative w-full md:w-80 flex items-center">
                <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-xs pointer-events-none"></i>
                <input type="text" id="busquedaImpresoras" 
                       oninput="toggleClearBtn('busquedaImpresoras', 'btn_clear_imp'); filtrarImpresoras();" 
                       onkeydown="if(event.key === 'Escape'){ limpiarBuscador('busquedaImpresoras', 'btn_clear_imp', filtrarImpresoras); }" 
                       placeholder="Buscar impresora, modelo, IP, serie, tóner..." 
                       class="w-full pl-9 pr-9 py-2.5 rounded-xl border border-slate-200 text-xs focus:ring-2 focus:ring-blue-500 outline-none">
                <button type="button" id="btn_clear_imp" 
                        onclick="limpiarBuscador('busquedaImpresoras', 'btn_clear_imp', filtrarImpresoras)" 
                        class="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-700 hover:bg-slate-200/60 p-1 rounded-full transition-all duration-200 hidden items-center justify-center cursor-pointer" 
                        title="Limpiar búsqueda">
                    <i class="fa-solid fa-circle-xmark text-sm"></i>
                </button>
            </div>

            <div class="flex flex-wrap items-center gap-2 w-full md:w-auto">
                <!-- Filtro por Sede -->
                <select id="filtroSedeImp" onchange="filtrarImpresoras()" class="px-3 py-2.5 rounded-xl border border-slate-200 text-xs font-semibold text-slate-700 outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="">-- Todas las Sedes --</option>
                    <?php foreach ($sucursales as $s): ?>
                        <option value="<?= htmlspecialchars($s['nombre']) ?>"><?= htmlspecialchars($s['empresa_nombre'] . ' - ' . $s['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>

                <!-- Filtro por Conexión -->
                <select id="filtroConexionImp" onchange="filtrarImpresoras()" class="px-3 py-2.5 rounded-xl border border-slate-200 text-xs font-semibold text-slate-700 outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="">-- Todas las Conexiones --</option>
                    <option value="Red Cableada (LAN)">Red Cableada (LAN)</option>
                    <option value="Wi-Fi Inalámbrica">Wi-Fi Inalámbrica</option>
                    <option value="USB Directa">USB Directa</option>
                    <option value="Servidor de Impresión">Servidor de Impresión</option>
                </select>

                <!-- Filtro por Estado -->
                <select id="filtroEstadoImp" onchange="filtrarImpresoras()" class="px-3 py-2.5 rounded-xl border border-slate-200 text-xs font-semibold text-slate-700 outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="">-- Todos los Estados --</option>
                    <option value="Operativa">Operativa</option>
                    <option value="En Mantenimiento">En Mantenimiento</option>
                    <option value="Sin Insumos / Tóner">Sin Insumos / Tóner</option>
                    <option value="Dañada / Fuera de Servicio">Dañada / Fuera de Servicio</option>
                </select>
            </div>
        </div>

        <!-- Filtros rápidos de Empresa (Chips) -->
        <div id="filtrosEmpresaImp" class="flex flex-wrap items-center gap-2 pt-1 border-t border-slate-100">
            <span class="text-[11px] font-bold text-slate-500 mr-1"><i class="fa-solid fa-filter text-slate-400 mr-1"></i> Empresa:</span>
            <button type="button" onclick="filtrarEmpresaImp('all', this)" class="filtro-imp-empresa-btn px-3 py-1.5 rounded-xl text-xs font-bold transition bg-slate-900 text-white shadow-xs">
                Todas las Empresas
            </button>
            <button type="button" onclick="filtrarEmpresaImp('SUMINISTROS INTEGRALES', this)" class="filtro-imp-empresa-btn px-3 py-1.5 rounded-xl text-xs font-bold transition bg-slate-100 hover:bg-slate-200 text-slate-700">
                Suministros Integrales
            </button>
            <button type="button" onclick="filtrarEmpresaImp('MASTERTEC', this)" class="filtro-imp-empresa-btn px-3 py-1.5 rounded-xl text-xs font-bold transition bg-slate-100 hover:bg-slate-200 text-slate-700">
                Mastertec
            </button>
            <button type="button" onclick="filtrarEmpresaImp('CENTRO DE PINTURA', this)" class="filtro-imp-empresa-btn px-3 py-1.5 rounded-xl text-xs font-bold transition bg-slate-100 hover:bg-slate-200 text-slate-700">
                Centro de Pintura
            </button>
        </div>

        <!-- Tabla de Impresoras -->
        <?php if (empty($impresoras)): ?>
            <div class="p-12 text-center bg-slate-50 rounded-2xl border border-dashed border-slate-200">
                <div class="w-16 h-16 mx-auto rounded-2xl bg-blue-50 text-blue-500 flex items-center justify-center text-3xl mb-3 shadow-inner">
                    <i class="fa-solid fa-print"></i>
                </div>
                <h3 class="text-base font-black text-slate-800">No hay impresoras registradas actualmente</h3>
                <p class="text-xs text-slate-500 max-w-md mx-auto mt-1 mb-4">
                    Registra las impresoras de cada una de tus sucursales para controlar su conectividad, IPs y credenciales de acceso.
                </p>
                <button onclick="abrirModalImpresora()" class="px-5 py-2.5 rounded-xl bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs shadow-md shadow-blue-500/20 transition inline-flex items-center gap-2">
                    <i class="fa-solid fa-plus-circle"></i> + Registrar Primera Impresora
                </button>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto rounded-2xl border border-slate-200/80 shadow-xs">
                <table class="w-full text-left border-collapse text-xs">
                    <thead>
                        <tr class="bg-gradient-to-r from-slate-900 to-slate-800 text-white uppercase text-[10px] tracking-wider font-bold">
                            <th class="p-3.5"><i class="fa-solid fa-building mr-1.5 opacity-70"></i> Sede & Área</th>
                            <th class="p-3.5"><i class="fa-solid fa-print mr-1.5 opacity-70"></i> Impresora / Modelo</th>
                            <th class="p-3.5"><i class="fa-solid fa-network-wired mr-1.5 opacity-70"></i> Conexión & IP</th>
                            <th class="p-3.5"><i class="fa-solid fa-key mr-1.5 opacity-70"></i> Usuario & Clave Admin</th>
                            <th class="p-3.5"><i class="fa-solid fa-barcode mr-1.5 opacity-70"></i> Serie / Consumible</th>
                            <th class="p-3.5"><i class="fa-solid fa-circle-dot mr-1.5 opacity-70"></i> Estado</th>
                            <th class="p-3.5 text-right"><i class="fa-solid fa-gear mr-1.5 opacity-70"></i> Acciones</th>
                        </tr>
                    </thead>
                    <tbody id="tablaImpresorasCuerpo" class="divide-y divide-slate-100 bg-white">
                        <?php foreach ($impresoras as $imp): 
                            $bandera = obtenerBanderaPaisImpresora($imp['sucursal_nombre'] ?? '', $imp['sucursal_ubicacion'] ?? '');

                            $color_estado = 'bg-emerald-50 text-emerald-700 border-emerald-200';
                            if ($imp['estado'] === 'En Mantenimiento') {
                                $color_estado = 'bg-amber-50 text-amber-700 border-amber-200';
                            } elseif ($imp['estado'] === 'Sin Insumos / Tóner') {
                                $color_estado = 'bg-indigo-50 text-indigo-700 border-indigo-200';
                            } elseif ($imp['estado'] === 'Dañada / Fuera de Servicio') {
                                $color_estado = 'bg-rose-50 text-rose-700 border-rose-200';
                            }

                            $search_data = strtolower(
                                ($imp['nombre'] ?? '') . ' ' . 
                                ($imp['marca'] ?? '') . ' ' . 
                                ($imp['modelo'] ?? '') . ' ' . 
                                ($imp['ip_direccion'] ?? '') . ' ' . 
                                ($imp['numero_serie'] ?? '') . ' ' . 
                                ($imp['sucursal_nombre'] ?? '') . ' ' . 
                                ($imp['empresa_nombre'] ?? '') . ' ' . 
                                ($imp['ubicacion_area'] ?? '') . ' ' . 
                                ($imp['toner_insumos'] ?? '') . ' ' . 
                                ($imp['usuario_admin'] ?? '')
                            );
                        ?>
                        <tr class="fila-impresora hover:bg-slate-50/80 transition"
                            data-search="<?= htmlspecialchars($search_data) ?>"
                            data-empresa="<?= htmlspecialchars($imp['empresa_nombre'] ?? '') ?>"
                            data-sede="<?= htmlspecialchars($imp['sucursal_nombre'] ?? '') ?>"
                            data-conexion="<?= htmlspecialchars($imp['tipo_conexion'] ?? '') ?>"
                            data-estado="<?= htmlspecialchars($imp['estado'] ?? '') ?>">
                            
                            <!-- Sede & Área -->
                            <td class="p-3.5">
                                <div class="flex items-center gap-2">
                                    <span class="text-lg leading-none" title="País"><?= $bandera ?></span>
                                    <div>
                                        <span class="font-black text-slate-800 block text-xs"><?= htmlspecialchars($imp['sucursal_nombre'] ?? 'Sin Sede') ?></span>
                                        <div class="flex items-center gap-1 mt-0.5">
                                            <span class="text-[10px] font-bold px-1.5 py-0.2 rounded bg-slate-100 text-slate-600 border border-slate-200">
                                                <?= htmlspecialchars($imp['empresa_nombre'] ?? 'N/A') ?>
                                            </span>
                                            <?php if (!empty($imp['ubicacion_area'])): ?>
                                                <span class="text-[10px] text-slate-500 font-semibold">
                                                    • <?= htmlspecialchars($imp['ubicacion_area']) ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </td>

                            <!-- Impresora / Modelo -->
                            <td class="p-3.5">
                                <div class="flex items-center gap-2.5">
                                    <div class="w-8 h-8 rounded-xl bg-blue-50 border border-blue-200 text-blue-600 flex items-center justify-center text-sm shrink-0">
                                        <i class="fa-solid fa-print"></i>
                                    </div>
                                    <div>
                                        <span class="font-black text-slate-800 text-xs tracking-tight block"><?= htmlspecialchars($imp['nombre']) ?></span>
                                        <span class="text-[10px] text-slate-500 font-medium block">
                                            <?= htmlspecialchars(($imp['marca'] ?? '') . ' ' . ($imp['modelo'] ?? '')) ?>
                                        </span>
                                        <span class="text-[9px] font-semibold text-slate-400 block mt-0.5">
                                            <?= htmlspecialchars($imp['tipo']) ?>
                                        </span>
                                    </div>
                                </div>
                            </td>

                            <!-- Conexión & IP -->
                            <td class="p-3.5">
                                <div class="space-y-1">
                                    <span class="px-2 py-0.5 rounded-md text-[10px] font-bold border <?= !empty($imp['ip_direccion']) ? 'bg-indigo-50 text-indigo-700 border-indigo-200' : 'bg-slate-100 text-slate-600 border-slate-200' ?> inline-block">
                                        <i class="<?= !empty($imp['ip_direccion']) ? 'fa-solid fa-network-wired' : 'fa-solid fa-plug' ?> mr-1 text-[9px]"></i>
                                        <?= htmlspecialchars($imp['tipo_conexion']) ?>
                                    </span>

                                    <?php if (!empty($imp['ip_direccion'])): ?>
                                        <div class="flex items-center gap-1.5">
                                            <span class="font-mono text-xs font-bold text-slate-800 bg-slate-100 px-2 py-0.5 rounded border border-slate-200">
                                                <?= htmlspecialchars($imp['ip_direccion']) ?>
                                            </span>
                                            <button type="button" onclick="copiarTexto('<?= htmlspecialchars($imp['ip_direccion']) ?>', this)" class="text-slate-400 hover:text-blue-600 transition" title="Copiar IP">
                                                <i class="fa-regular fa-copy text-[10px]"></i>
                                            </button>
                                            <a href="http://<?= htmlspecialchars($imp['ip_direccion']) ?>" target="_blank" rel="noopener noreferrer" class="text-blue-600 hover:text-blue-800 transition" title="Abrir Consola Web de la Impresora">
                                                <i class="fa-solid fa-arrow-up-right-from-square text-[10px]"></i>
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </td>

                            <!-- Usuario & Contraseña Admin (Opcionales) -->
                            <td class="p-3.5">
                                <?php if (!empty($imp['usuario_admin']) || !empty($imp['pass_plain'])): ?>
                                    <div class="space-y-1">
                                        <?php if (!empty($imp['usuario_admin'])): ?>
                                            <div class="text-[11px] font-bold text-slate-700 flex items-center gap-1">
                                                <i class="fa-regular fa-user text-slate-400 text-[10px]"></i>
                                                <span class="font-mono"><?= htmlspecialchars($imp['usuario_admin']) ?></span>
                                                <button type="button" onclick="copiarTexto('<?= htmlspecialchars($imp['usuario_admin']) ?>', this)" class="text-slate-400 hover:text-blue-600 text-[10px] ml-0.5" title="Copiar Usuario">
                                                    <i class="fa-regular fa-copy"></i>
                                                </button>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (!empty($imp['pass_plain'])): ?>
                                            <div class="inline-flex items-center gap-1.5 bg-slate-100 border border-slate-200 px-2 py-0.5 rounded-lg text-xs">
                                                <span id="imp_pass_masked_<?= $imp['id'] ?>" class="font-mono font-bold text-slate-600">••••••••</span>
                                                <span id="imp_pass_plain_<?= $imp['id'] ?>" class="font-mono font-bold text-indigo-900 hidden"><?= htmlspecialchars($imp['pass_plain']) ?></span>
                                                <button type="button" onclick="togglePassImp(<?= $imp['id'] ?>)" id="btn_eye_imp_<?= $imp['id'] ?>" class="text-slate-400 hover:text-slate-700 p-0.5" title="Mostrar/Ocultar">
                                                    <i class="fa-regular fa-eye text-xs"></i>
                                                </button>
                                                <button type="button" onclick="copiarTexto('<?= addslashes($imp['pass_plain']) ?>', this)" class="text-slate-400 hover:text-indigo-600 p-0.5" title="Copiar Clave">
                                                    <i class="fa-regular fa-copy text-xs"></i>
                                                </button>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <span class="text-[10px] text-slate-400 font-medium italic">Sin credenciales requeridas</span>
                                <?php endif; ?>
                            </td>

                            <!-- Serie / Tóner -->
                            <td class="p-3.5">
                                <div class="space-y-0.5">
                                    <?php if (!empty($imp['numero_serie'])): ?>
                                        <span class="font-mono text-[10px] font-bold text-slate-600 block">
                                            SN: <?= htmlspecialchars($imp['numero_serie']) ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if (!empty($imp['toner_insumos'])): ?>
                                        <span class="text-[10px] font-semibold text-indigo-700 bg-indigo-50 px-1.5 py-0.2 rounded border border-indigo-200 inline-block">
                                            <i class="fa-solid fa-fill-drip text-[8px] mr-1"></i><?= htmlspecialchars($imp['toner_insumos']) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </td>

                            <!-- Estado -->
                            <td class="p-3.5">
                                <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold border <?= $color_estado ?> inline-flex items-center gap-1.5">
                                    <span class="w-1.5 h-1.5 rounded-full <?= $imp['estado'] === 'Operativa' ? 'bg-emerald-500' : 'bg-amber-400' ?>"></span>
                                    <?= htmlspecialchars($imp['estado']) ?>
                                </span>
                            </td>

                            <!-- Acciones -->
                            <td class="p-3.5 text-right">
                                <div class="flex items-center justify-end gap-1.5">
                                    <?php if (!empty($imp['ip_direccion'])): ?>
                                        <a href="http://<?= htmlspecialchars($imp['ip_direccion']) ?>" target="_blank" rel="noopener noreferrer" 
                                           class="p-2 rounded-xl bg-slate-100 hover:bg-indigo-50 text-slate-600 hover:text-indigo-600 border border-slate-200 transition" 
                                           title="Abrir Consola Web de la Impresora">
                                            <i class="fa-solid fa-arrow-up-right-from-square text-xs"></i>
                                        </a>
                                    <?php endif; ?>

                                    <!-- Editar -->
                                    <button type="button" 
                                            onclick='abrirModalImpresora(<?= json_encode($imp, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)' 
                                            class="p-2 rounded-xl bg-slate-100 hover:bg-blue-50 text-slate-600 hover:text-blue-600 border border-slate-200 transition" 
                                            title="Editar Impresora">
                                        <i class="fa-solid fa-pen-to-square text-xs"></i>
                                    </button>

                                    <!-- Eliminar -->
                                    <button type="button" 
                                            onclick="eliminarImpresora(<?= $imp['id'] ?>, '<?= addslashes($imp['nombre']) ?>')" 
                                            class="p-2 rounded-xl bg-slate-100 hover:bg-rose-50 text-slate-600 hover:text-rose-600 border border-slate-200 transition" 
                                            title="Eliminar Impresora">
                                        <i class="fa-solid fa-trash text-xs"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- MODAL: REGISTRAR / EDITAR IMPRESORA -->
<div id="modalImpresora" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 flex items-center justify-center hidden p-4">
    <div class="bg-white rounded-3xl max-w-2xl w-full p-6 shadow-2xl space-y-4 max-h-[90vh] overflow-y-auto">
        <div class="flex justify-between items-center border-b border-slate-100 pb-3">
            <h3 id="modalImpresoraTitulo" class="text-lg font-black text-slate-800 flex items-center gap-2">
                <i class="fa-solid fa-print text-blue-600"></i> Registrar Impresora
            </h3>
            <button onclick="cerrarModalImpresora()" class="text-slate-400 hover:text-slate-700 text-lg p-1"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <form action="guardar.php?action=save_impresora" method="POST" class="space-y-4">
            <input type="hidden" name="impresora_id" id="impresora_id">

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <!-- Sede -->
                <div class="sm:col-span-2">
                    <label class="block text-xs font-bold text-slate-700 mb-1">Sede / Sucursal *</label>
                    <select name="sucursal_id" id="imp_sucursal" required class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs font-semibold text-slate-700 outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="">-- Seleccionar Sede --</option>
                        <?php foreach ($sucursales as $s): ?>
                            <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['empresa_nombre'] . ' - ' . $s['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Nombre -->
                <div class="sm:col-span-2">
                    <label class="block text-xs font-bold text-slate-700 mb-1">Nombre / Identificador de la Impresora *</label>
                    <input type="text" name="nombre" id="imp_nombre" required placeholder="Ej. HP LaserJet M428 Contabilidad o EPSON LX-350 Caja 1" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs font-bold text-slate-800 outline-none focus:ring-2 focus:ring-blue-500">
                </div>

                <!-- Marca -->
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Marca</label>
                    <input type="text" name="marca" id="imp_marca" placeholder="Ej. HP, Epson, Brother, Canon" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs outline-none focus:ring-2 focus:ring-blue-500">
                </div>

                <!-- Modelo -->
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Modelo</label>
                    <input type="text" name="modelo" id="imp_modelo" placeholder="Ej. LaserJet MFP M428fdw / LX-350" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs outline-none focus:ring-2 focus:ring-blue-500">
                </div>

                <!-- Tipo -->
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Tipo / Tecnología</label>
                    <select name="tipo" id="imp_tipo" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs font-semibold text-slate-700 outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="Multifuncional Láser">Multifuncional Láser</option>
                        <option value="Láser Monocromática">Láser Monocromática</option>
                        <option value="Láser Color">Láser Color</option>
                        <option value="Inyección de Tinta / Tinta Continua">Inyección de Tinta / Tinta Continua</option>
                        <option value="Térmica / Facturación / POS">Térmica / Facturación / POS</option>
                        <option value="Matriz de Punto">Matriz de Punto</option>
                        <option value="Plotter / Gran Formato">Plotter / Gran Formato</option>
                        <option value="Otra">Otra</option>
                    </select>
                </div>

                <!-- Tipo Conexión -->
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Tipo de Conexión</label>
                    <select name="tipo_conexion" id="imp_conexion" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs font-semibold text-slate-700 outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="Red Cableada (LAN)">Red Cableada (LAN)</option>
                        <option value="Wi-Fi Inalámbrica">Wi-Fi Inalámbrica</option>
                        <option value="USB Directa">USB Directa</option>
                        <option value="Servidor de Impresión / Compartida">Servidor de Impresión / Compartida</option>
                    </select>
                </div>

                <!-- Dirección IP -->
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Dirección IP (Opcional)</label>
                    <input type="text" name="ip_direccion" id="imp_ip" placeholder="Ej. 192.168.10.25" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs font-mono outline-none focus:ring-2 focus:ring-blue-500">
                </div>

                <!-- Ubicación Física / Área -->
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Ubicación Física / Área</label>
                    <input type="text" name="ubicacion_area" id="imp_area" placeholder="Ej. Contabilidad, Recepción, Bodega" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs outline-none focus:ring-2 focus:ring-blue-500">
                </div>

                <!-- Usuario Admin (Opcional) -->
                <div>
                    <div class="flex items-center justify-between mb-1">
                        <label class="block text-xs font-bold text-slate-700">Usuario Admin</label>
                        <span class="text-[10px] text-slate-400 font-semibold">(Opcional)</span>
                    </div>
                    <input type="text" name="usuario_admin" id="imp_user" placeholder="Ej. admin" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs font-mono outline-none focus:ring-2 focus:ring-blue-500">
                </div>

                <!-- Password Admin (Opcional) -->
                <div>
                    <div class="flex items-center justify-between mb-1">
                        <label class="block text-xs font-bold text-slate-700">Contraseña Admin</label>
                        <span class="text-[10px] text-slate-400 font-semibold">(Opcional)</span>
                    </div>
                    <div class="relative">
                        <input type="password" name="password_admin" id="imp_pass" placeholder="Contraseña de la impresora" class="w-full px-3 pr-8 py-2 rounded-xl border border-slate-200 text-xs font-mono outline-none focus:ring-2 focus:ring-blue-500">
                        <button type="button" onclick="togglePassInput('imp_pass', this)" class="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600">
                            <i class="fa-regular fa-eye text-xs"></i>
                        </button>
                    </div>
                    <p id="imp_pass_hint" class="text-[10px] text-slate-400 mt-0.5 hidden">Al editar, dejar en blanco para conservar la clave actual.</p>
                </div>

                <!-- Número de Serie -->
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Número de Serie / Service Tag</label>
                    <input type="text" name="numero_serie" id="imp_serie" placeholder="Ej. CN21F27205" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs font-mono outline-none focus:ring-2 focus:ring-blue-500">
                </div>

                <!-- Tóner / Insumos -->
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Consumible / Modelo de Tóner</label>
                    <input type="text" name="toner_insumos" id="imp_toner" placeholder="Ej. Tóner HP 58A / Tinta T544" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs outline-none focus:ring-2 focus:ring-blue-500">
                </div>

                <!-- Estado -->
                <div class="sm:col-span-2">
                    <label class="block text-xs font-bold text-slate-700 mb-1">Estado Operativo</label>
                    <select name="estado" id="imp_estado" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs font-semibold text-slate-700 outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="Operativa">Operativa</option>
                        <option value="En Mantenimiento">En Mantenimiento</option>
                        <option value="Sin Insumos / Tóner">Sin Insumos / Tóner</option>
                        <option value="Dañada / Fuera de Servicio">Dañada / Fuera de Servicio</option>
                    </select>
                </div>
            </div>

            <!-- Notas -->
            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Notas / Observaciones</label>
                <textarea name="notas" id="imp_notas" rows="2" placeholder="Observaciones adicionales, proveedor de recarga, configuración especial..." class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs outline-none focus:ring-2 focus:ring-blue-500"></textarea>
            </div>

            <div class="flex justify-end gap-2 pt-3 border-t border-slate-100">
                <button type="button" onclick="cerrarModalImpresora()" class="px-4 py-2 rounded-xl border border-slate-200 text-slate-600 font-bold text-xs hover:bg-slate-50">Cancelar</button>
                <button type="submit" class="px-5 py-2 rounded-xl bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs shadow-lg shadow-blue-500/20">Guardar Impresora</button>
            </div>
        </form>
    </div>
</div>

<script>
function abrirModalImpresora(data = null) {
    const modal = document.getElementById('modalImpresora');
    const form = modal.querySelector('form');
    const titulo = document.getElementById('modalImpresoraTitulo');
    const passHint = document.getElementById('imp_pass_hint');

    if (data) {
        titulo.innerHTML = '<i class="fa-solid fa-pen-to-square text-blue-600"></i> Editar Impresora';
        document.getElementById('impresora_id').value = data.id || '';
        document.getElementById('imp_sucursal').value = data.sucursal_id || '';
        document.getElementById('imp_nombre').value = data.nombre || '';
        document.getElementById('imp_marca').value = data.marca || '';
        document.getElementById('imp_modelo').value = data.modelo || '';
        document.getElementById('imp_tipo').value = data.tipo || 'Multifuncional Láser';
        document.getElementById('imp_conexion').value = data.tipo_conexion || 'Red Cableada (LAN)';
        document.getElementById('imp_ip').value = data.ip_direccion || '';
        document.getElementById('imp_area').value = data.ubicacion_area || '';
        document.getElementById('imp_user').value = data.usuario_admin || '';
        document.getElementById('imp_pass').value = '';
        passHint.classList.remove('hidden');
        document.getElementById('imp_serie').value = data.numero_serie || '';
        document.getElementById('imp_toner').value = data.toner_insumos || '';
        document.getElementById('imp_estado').value = data.estado || 'Operativa';
        document.getElementById('imp_notas').value = data.notas || '';
    } else {
        titulo.innerHTML = '<i class="fa-solid fa-print text-blue-600"></i> Registrar Impresora';
        form.reset();
        document.getElementById('impresora_id').value = '';
        passHint.classList.add('hidden');
        document.getElementById('imp_tipo').value = 'Multifuncional Láser';
        document.getElementById('imp_conexion').value = 'Red Cableada (LAN)';
        document.getElementById('imp_estado').value = 'Operativa';
    }
    modal.classList.remove('hidden');
}

function cerrarModalImpresora() {
    document.getElementById('modalImpresora').classList.add('hidden');
}

function togglePassImp(id) {
    const masked = document.getElementById('imp_pass_masked_' + id);
    const plain = document.getElementById('imp_pass_plain_' + id);
    const btn = document.getElementById('btn_eye_imp_' + id);
    if (!masked || !plain || !btn) return;

    if (plain.classList.contains('hidden')) {
        plain.classList.remove('hidden');
        masked.classList.add('hidden');
        btn.innerHTML = '<i class="fa-regular fa-eye-slash text-xs text-indigo-600"></i>';
    } else {
        plain.classList.add('hidden');
        masked.classList.remove('hidden');
        btn.innerHTML = '<i class="fa-regular fa-eye text-xs"></i>';
    }
}

function togglePassInput(inputId, btn) {
    const input = document.getElementById(inputId);
    if (input.type === 'password') {
        input.type = 'text';
        btn.innerHTML = '<i class="fa-regular fa-eye-slash text-xs"></i>';
    } else {
        input.type = 'password';
        btn.innerHTML = '<i class="fa-regular fa-eye text-xs"></i>';
    }
}

function copiarTexto(texto, btn) {
    if (!texto) return;
    navigator.clipboard.writeText(texto).then(() => {
        const originalIcon = btn.innerHTML;
        btn.innerHTML = '<i class="fa-solid fa-check text-xs text-emerald-600"></i>';
        setTimeout(() => {
            btn.innerHTML = originalIcon;
        }, 1500);
    });
}

let filtroEmpresaImpActivo = 'all';

function filtrarEmpresaImp(empresa, btn) {
    filtroEmpresaImpActivo = empresa;
    document.querySelectorAll('#filtrosEmpresaImp .filtro-imp-empresa-btn').forEach(b => {
        b.className = 'filtro-imp-empresa-btn px-3 py-1.5 rounded-xl text-xs font-bold transition bg-slate-100 hover:bg-slate-200 text-slate-700';
    });
    if (btn) {
        btn.className = 'filtro-imp-empresa-btn px-3 py-1.5 rounded-xl text-xs font-bold transition bg-slate-900 text-white shadow-xs';
    }
    filtrarImpresoras();
}

function filtrarImpresoras() {
    const q = (document.getElementById('busquedaImpresoras') ? document.getElementById('busquedaImpresoras').value.toLowerCase().trim() : '');
    const sede = (document.getElementById('filtroSedeImp') ? document.getElementById('filtroSedeImp').value.toLowerCase().trim() : '');
    const conexion = (document.getElementById('filtroConexionImp') ? document.getElementById('filtroConexionImp').value.toLowerCase().trim() : '');
    const estado = (document.getElementById('filtroEstadoImp') ? document.getElementById('filtroEstadoImp').value.toLowerCase().trim() : '');

    const filas = document.querySelectorAll('.fila-impresora');
    filas.forEach(fila => {
        const search = fila.getAttribute('data-search') || '';
        const fEmpresa = (fila.getAttribute('data-empresa') || '').toUpperCase();
        const fSede = (fila.getAttribute('data-sede') || '').toLowerCase();
        const fConexion = (fila.getAttribute('data-conexion') || '').toLowerCase();
        const fEstado = (fila.getAttribute('data-estado') || '').toLowerCase();

        const matchQ = !q || search.includes(q);
        const matchSede = !sede || fSede.includes(sede);
        const matchConexion = !conexion || fConexion === conexion;
        const matchEstado = !estado || fEstado === estado;
        let matchEmpresa = true;
        if (filtroEmpresaImpActivo !== 'all') {
            matchEmpresa = fEmpresa.includes(filtroEmpresaImpActivo.toUpperCase());
        }

        if (matchQ && matchSede && matchConexion && matchEstado && matchEmpresa) {
            fila.style.display = '';
        } else {
            fila.style.display = 'none';
        }
    });
}

function eliminarImpresora(id, nombre) {
    if (confirm(`¿Estás seguro de que deseas eliminar la impresora "${nombre}"? Esta acción no se puede deshacer.`)) {
        window.location.href = `guardar.php?action=delete_impresora&id=${id}`;
    }
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
