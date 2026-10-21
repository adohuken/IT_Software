<?php
/**
 * Módulo de Gestión de Equipos (Asset Management)
 * Inventario de hardware, asignaciones a empleados y filtros
 */

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../config/security.php';

$db = Database::getConexion();

// Obtener filtros desde URL
$filtro_sucursal = $_GET['sucursal_id'] ?? '';
$filtro_depto = $_GET['departamento'] ?? '';
$filtro_tipo = $_GET['tipo'] ?? ''; // 'Laptop', 'Desktop', 'Impresora' o '__otros__' (desde el dashboard)
$filtro_estado = $_GET['estado'] ?? ''; // 'Asignado', 'Disponible', 'Mantenimiento', 'De Baja'

// Cargar catálogos limpios y agrupados por Empresa para filtros y creación de equipos
$sucursales = $db->query("
    SELECT s.id, s.nombre, e.nombre as empresa_nombre 
    FROM sucursales s 
    JOIN empresas e ON s.empresa_id = e.id 
    ORDER BY e.nombre, s.nombre
")->fetchAll();

$departamentos = $db->query("
    SELECT DISTINCT departamento 
    FROM empleados 
    WHERE departamento IS NOT NULL AND departamento != '' AND departamento != 'General'
    ORDER BY departamento
")->fetchAll();

$empleados = $db->query("SELECT id, nombre, cargo, departamento FROM empleados WHERE estado = 'Activo' ORDER BY nombre")->fetchAll();

// Métricas de inventario para resumen ejecutivo (considerando sucursal si está filtrada)
$stats_sql = "
    SELECT 
        COUNT(CASE WHEN estado != 'De Baja' THEN 1 END) as total_activos,
        COUNT(CASE WHEN estado = 'Asignado' THEN 1 END) as total_asignados,
        COUNT(CASE WHEN estado = 'Disponible' THEN 1 END) as total_disponibles,
        COUNT(CASE WHEN estado = 'Mantenimiento' THEN 1 END) as total_mantenimiento,
        COUNT(CASE WHEN estado = 'De Baja' THEN 1 END) as total_baja,
        COUNT(CASE WHEN tipo = 'Laptop' AND estado != 'De Baja' THEN 1 END) as total_laptops,
        COUNT(CASE WHEN tipo = 'Desktop' AND estado != 'De Baja' THEN 1 END) as total_desktops,
        COUNT(CASE WHEN tipo NOT IN ('Laptop', 'Desktop') AND estado != 'De Baja' THEN 1 END) as total_otros
    FROM equipos e
    WHERE 1=1
";
$stats_params = [];
if (!empty($filtro_sucursal)) {
    $stats_sql .= " AND e.sucursal_id = ?";
    $stats_params[] = (int)$filtro_sucursal;
}
$stmt_stats = $db->prepare($stats_sql);
$stmt_stats->execute($stats_params);
$stats = $stmt_stats->fetch(PDO::FETCH_ASSOC);

$tot_activos = (int)($stats['total_activos'] ?? 0);
$tot_asig    = (int)($stats['total_asignados'] ?? 0);
$tot_disp    = (int)($stats['total_disponibles'] ?? 0);
$tot_mant    = (int)($stats['total_mantenimiento'] ?? 0);
$tot_baja    = (int)($stats['total_baja'] ?? 0);
$pct_asig    = $tot_activos > 0 ? round(($tot_asig / $tot_activos) * 100) : 0;
$pct_disp    = $tot_activos > 0 ? round(($tot_disp / $tot_activos) * 100) : 0;

// Construir consulta filtrada de equipos con datos completos de credenciales y asignación
$sql = "
    SELECT e.*, s.nombre AS sucursal_nombre, s.ubicacion AS sucursal_ubicacion,
           a.id AS asignacion_id, a.fecha_entrega, a.condicion_entrega,
           emp.id AS empleado_id, emp.nombre AS empleado_nombre, 
           emp.departamento AS empleado_depto, emp.cargo AS empleado_cargo,
           ce.usuario_local, ce.password_cifrado, ce.clave_bitlocker, ce.notas_acceso
    FROM equipos e
    JOIN sucursales s ON e.sucursal_id = s.id
    LEFT JOIN asignaciones a ON a.equipo_id = e.id AND a.fecha_devolucion IS NULL
    LEFT JOIN empleados emp ON a.empleado_id = emp.id
    LEFT JOIN credenciales_equipos ce ON ce.equipo_id = e.id
    WHERE 1=1
";

$params = [];

if ($filtro_estado === 'De Baja') {
    $sql .= " AND e.estado = 'De Baja'";
} else {
    if (!empty($filtro_estado)) {
        $sql .= " AND e.estado = ?";
        $params[] = $filtro_estado;
    } else {
        $sql .= " AND e.estado != 'De Baja'";
    }
}

if (!empty($filtro_sucursal)) {
    $sql .= " AND e.sucursal_id = ?";
    $params[] = (int)$filtro_sucursal;
}
if (!empty($filtro_depto)) {
    $sql .= " AND emp.departamento = ?";
    $params[] = $filtro_depto;
}
if (!empty($filtro_tipo)) {
    if ($filtro_tipo === '__otros__') {
        $sql .= " AND e.tipo NOT IN ('Laptop', 'Desktop', 'Impresora')";
    } else {
        $sql .= " AND e.tipo = ?";
        $params[] = $filtro_tipo;
    }
}

$sql .= " ORDER BY e.tipo, e.marca, e.modelo";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$equipos_raw = $stmt->fetchAll();

// Descifrar credenciales de equipos para vista rápida en modal
$equipos = [];
foreach ($equipos_raw as $eq) {
    $eq['pass_local_clear'] = !empty($eq['password_cifrado']) ? Security::decrypt($eq['password_cifrado']) : '';
    $eq['bitlocker_clear'] = !empty($eq['clave_bitlocker']) ? Security::decrypt($eq['clave_bitlocker']) : '';
    $equipos[] = $eq;
}


// Cargar Historial de Asignación (Si se solicita ver historia de un equipo)
$ver_historial_id = $_GET['history_id'] ?? 0;
$historial = [];
$equipo_detalle = null;

if ($ver_historial_id > 0) {
    // Detalles del equipo
    $stmt_eq = $db->prepare("SELECT tipo, marca, modelo, service_tag_sn FROM equipos WHERE id = ?");
    $stmt_eq->execute([$ver_historial_id]);
    $equipo_detalle = $stmt_eq->fetch();

    if ($equipo_detalle) {
        $stmt_hist = $db->prepare("
            SELECT a.*, emp.nombre AS empleado_nombre, emp.cargo, emp.departamento 
            FROM asignaciones a 
            JOIN empleados emp ON a.empleado_id = emp.id 
            WHERE a.equipo_id = ? 
            ORDER BY a.fecha_entrega DESC, a.id DESC
        ");
        $stmt_hist->execute([$ver_historial_id]);
        $historial = $stmt_hist->fetchAll();
        
        // Obtener el proveedor origen para mostrar al final del historial
        $stmt_origen = $db->prepare("SELECT proveedor_origen, registrado_por FROM equipos WHERE id = ?");
        $stmt_origen->execute([$ver_historial_id]);
        $origen = $stmt_origen->fetch();
    }
}
?>

<div class="space-y-6">
    <!-- Encabezado de Página -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h2 class="text-2xl font-bold text-slate-800 tracking-wide">Inventario y Asignación de Hardware</h2>
            <p class="text-slate-500 text-sm">Control de computadoras corporativas, servidores, dispositivos de red (IP, Switches, APs) y asignaciones.</p>
        </div>

        <div class="flex items-center gap-2">
            <button onclick="abrirNuevoEquipo('Infraestructura')" class="px-4 py-2.5 rounded-xl bg-cyan-600 hover:bg-cyan-700 text-white text-xs font-bold transition shadow-md shadow-cyan-500/20 flex items-center gap-2">
                <i class="fa-solid fa-network-wired"></i> + Registrar Equipo IP / Red (Switch, AP, Server)
            </button>
            <button onclick="abrirNuevoEquipo('PC')" class="px-4 py-2.5 rounded-xl bg-slate-900 hover:bg-slate-800 text-white text-xs font-bold transition shadow-md flex items-center gap-2">
                <i class="fa-solid fa-plus"></i> + Nuevo Equipo
            </button>
        </div>
    </div>

    <!-- Tarjetas de Métricas Ejecutivas de Inventario (KPIs) -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <!-- Tarjeta 1: Total Activos -->
        <div onclick="filtrarPorEstado('')" 
             class="bg-white rounded-2xl p-5 border <?= empty($filtro_estado) ? 'border-cyan-500 ring-2 ring-cyan-500/20 shadow-md' : 'border-slate-200/90 shadow-sm hover:border-slate-300 hover:shadow-md' ?> transition-all cursor-pointer group flex flex-col justify-between">
            <div class="flex items-start justify-between">
                <div>
                    <span class="text-[11px] font-bold uppercase tracking-wider text-slate-500 block">Total Hardware</span>
                    <h3 class="text-3xl font-extrabold text-slate-800 mt-1"><?= $tot_activos ?></h3>
                </div>
                <div class="h-12 w-12 rounded-2xl bg-cyan-500/10 border border-cyan-500/20 text-cyan-600 flex items-center justify-center text-xl shrink-0 group-hover:scale-105 transition-transform">
                    <i class="fa-solid fa-boxes-stacked"></i>
                </div>
            </div>
            <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-xs text-slate-500">
                <span class="truncate"><?= $stats['total_laptops'] ?> Laptops • <?= $stats['total_desktops'] ?> Desktops</span>
                <span class="font-bold text-cyan-600 shrink-0">Ver todos &rarr;</span>
            </div>
        </div>

        <!-- Tarjeta 2: Asignados -->
        <div onclick="filtrarPorEstado('Asignado')" 
             class="bg-white rounded-2xl p-5 border <?= $filtro_estado === 'Asignado' ? 'border-blue-500 ring-2 ring-blue-500/20 shadow-md' : 'border-slate-200/90 shadow-sm hover:border-blue-300 hover:shadow-md' ?> transition-all cursor-pointer group flex flex-col justify-between">
            <div class="flex items-start justify-between">
                <div>
                    <div class="flex items-center gap-2">
                        <span class="text-[11px] font-bold uppercase tracking-wider text-slate-500 block">Asignados</span>
                        <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-blue-50 text-blue-700 border border-blue-200"><?= $pct_asig ?>%</span>
                    </div>
                    <h3 class="text-3xl font-extrabold text-blue-600 mt-1"><?= $tot_asig ?></h3>
                </div>
                <div class="h-12 w-12 rounded-2xl bg-blue-500/10 border border-blue-500/20 text-blue-600 flex items-center justify-center text-xl shrink-0 group-hover:scale-105 transition-transform">
                    <i class="fa-solid fa-user-check"></i>
                </div>
            </div>
            <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-xs text-slate-500">
                <span>En uso por colaboradores</span>
                <span class="font-bold text-blue-600 shrink-0">Filtrar &rarr;</span>
            </div>
        </div>

        <!-- Tarjeta 3: Disponibles en Stock (Sin Asignar) -->
        <div onclick="filtrarPorEstado('Disponible')" 
             class="bg-white rounded-2xl p-5 border <?= $filtro_estado === 'Disponible' ? 'border-emerald-500 ring-2 ring-emerald-500/20 shadow-md' : 'border-slate-200/90 shadow-sm hover:border-emerald-300 hover:shadow-md' ?> transition-all cursor-pointer group flex flex-col justify-between">
            <div class="flex items-start justify-between">
                <div>
                    <div class="flex items-center gap-2">
                        <span class="text-[11px] font-bold uppercase tracking-wider text-slate-500 block">En Stock (Libres)</span>
                        <?php if ($tot_disp === 0): ?>
                            <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-amber-50 text-amber-700 border border-amber-200">Sin Reserva</span>
                        <?php else: ?>
                            <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200"><?= $pct_disp ?>% Libre</span>
                        <?php endif; ?>
                    </div>
                    <h3 class="text-3xl font-extrabold <?= $tot_disp > 0 ? 'text-emerald-600' : 'text-slate-400' ?> mt-1"><?= $tot_disp ?></h3>
                </div>
                <div class="h-12 w-12 rounded-2xl <?= $tot_disp > 0 ? 'bg-emerald-500/10 border-emerald-500/20 text-emerald-600' : 'bg-slate-100 border-slate-200 text-slate-400' ?> border flex items-center justify-center text-xl shrink-0 group-hover:scale-105 transition-transform">
                    <i class="fa-solid fa-box-open"></i>
                </div>
            </div>
            <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-xs text-slate-500">
                <span><?= $tot_disp > 0 ? 'Listos para entregar' : 'Requiere reabastecer' ?></span>
                <span class="font-bold text-emerald-600 shrink-0">Filtrar &rarr;</span>
            </div>
        </div>

        <!-- Tarjeta 4: En Mantenimiento -->
        <div onclick="filtrarPorEstado('Mantenimiento')" 
             class="bg-white rounded-2xl p-5 border <?= $filtro_estado === 'Mantenimiento' ? 'border-amber-500 ring-2 ring-amber-500/20 shadow-md' : 'border-slate-200/90 shadow-sm hover:border-amber-300 hover:shadow-md' ?> transition-all cursor-pointer group flex flex-col justify-between">
            <div class="flex items-start justify-between">
                <div>
                    <div class="flex items-center gap-2">
                        <span class="text-[11px] font-bold uppercase tracking-wider text-slate-500 block">En Mantenimiento</span>
                        <?php if ($tot_mant > 0): ?>
                            <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-amber-50 text-amber-700 border border-amber-200">En Taller</span>
                        <?php endif; ?>
                    </div>
                    <h3 class="text-3xl font-extrabold <?= $tot_mant > 0 ? 'text-amber-600' : 'text-slate-400' ?> mt-1"><?= $tot_mant ?></h3>
                </div>
                <div class="h-12 w-12 rounded-2xl <?= $tot_mant > 0 ? 'bg-amber-500/10 border-amber-500/20 text-amber-600' : 'bg-slate-100 border-slate-200 text-slate-400' ?> border flex items-center justify-center text-xl shrink-0 group-hover:scale-105 transition-transform">
                    <i class="fa-solid fa-screwdriver-wrench"></i>
                </div>
            </div>
            <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-xs text-slate-500">
                <span>Revisión técnica o daños</span>
                <span class="font-bold text-amber-600 shrink-0">Filtrar &rarr;</span>
            </div>
        </div>
    </div>

    <!-- Pestañas Rápidas de Filtrado por Estado (Pill Tabs) -->
    <div class="flex flex-wrap items-center justify-between gap-3 bg-white p-2.5 rounded-2xl border border-slate-200">
        <div class="flex flex-wrap items-center gap-2">
            <button type="button" onclick="filtrarPorEstado('')" 
                    class="px-4 py-2 rounded-xl text-xs font-bold transition-all flex items-center gap-2 <?= empty($filtro_estado) ? 'bg-slate-900 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-100' ?>">
                <i class="fa-solid fa-list-ul text-[11px]"></i>
                <span>Todos</span>
                <span class="px-2 py-0.5 rounded-full text-[10px] <?= empty($filtro_estado) ? 'bg-white/20 text-white' : 'bg-slate-200 text-slate-700' ?>"><?= $tot_activos ?></span>
            </button>

            <button type="button" onclick="filtrarPorEstado('Asignado')" 
                    class="px-4 py-2 rounded-xl text-xs font-bold transition-all flex items-center gap-2 <?= $filtro_estado === 'Asignado' ? 'bg-blue-600 text-white shadow-sm shadow-blue-500/20' : 'text-slate-600 hover:bg-blue-50 hover:text-blue-600' ?>">
                <span class="h-2 w-2 rounded-full <?= $filtro_estado === 'Asignado' ? 'bg-white' : 'bg-blue-500' ?>"></span>
                <span>Asignados</span>
                <span class="px-2 py-0.5 rounded-full text-[10px] <?= $filtro_estado === 'Asignado' ? 'bg-white/20 text-white' : 'bg-blue-100 text-blue-700' ?>"><?= $tot_asig ?></span>
            </button>

            <button type="button" onclick="filtrarPorEstado('Disponible')" 
                    class="px-4 py-2 rounded-xl text-xs font-bold transition-all flex items-center gap-2 <?= $filtro_estado === 'Disponible' ? 'bg-emerald-600 text-white shadow-sm shadow-emerald-500/20' : 'text-slate-600 hover:bg-emerald-50 hover:text-emerald-600' ?>">
                <span class="h-2 w-2 rounded-full <?= $filtro_estado === 'Disponible' ? 'bg-white' : 'bg-emerald-500' ?>"></span>
                <span>En Stock / Libres</span>
                <span class="px-2 py-0.5 rounded-full text-[10px] <?= $filtro_estado === 'Disponible' ? 'bg-white/20 text-white' : 'bg-emerald-100 text-emerald-700' ?>"><?= $tot_disp ?></span>
            </button>

            <button type="button" onclick="filtrarPorEstado('Mantenimiento')" 
                    class="px-4 py-2 rounded-xl text-xs font-bold transition-all flex items-center gap-2 <?= $filtro_estado === 'Mantenimiento' ? 'bg-amber-600 text-white shadow-sm shadow-amber-500/20' : 'text-slate-600 hover:bg-amber-50 hover:text-amber-600' ?>">
                <span class="h-2 w-2 rounded-full <?= $filtro_estado === 'Mantenimiento' ? 'bg-white' : 'bg-amber-500' ?>"></span>
                <span>En Mantenimiento</span>
                <span class="px-2 py-0.5 rounded-full text-[10px] <?= $filtro_estado === 'Mantenimiento' ? 'bg-white/20 text-white' : 'bg-amber-100 text-amber-700' ?>"><?= $tot_mant ?></span>
            </button>

            <?php if ($tot_baja > 0 || $filtro_estado === 'De Baja'): ?>
            <button type="button" onclick="filtrarPorEstado('De Baja')" 
                    class="px-4 py-2 rounded-xl text-xs font-bold transition-all flex items-center gap-2 <?= $filtro_estado === 'De Baja' ? 'bg-rose-600 text-white shadow-sm' : 'text-slate-500 hover:bg-rose-50 hover:text-rose-600' ?>">
                <span class="h-2 w-2 rounded-full <?= $filtro_estado === 'De Baja' ? 'bg-white' : 'bg-rose-400' ?>"></span>
                <span>De Baja</span>
                <span class="px-2 py-0.5 rounded-full text-[10px] <?= $filtro_estado === 'De Baja' ? 'bg-white/20 text-white' : 'bg-rose-100 text-rose-700' ?>"><?= $tot_baja ?></span>
            </button>
            <?php endif; ?>
        </div>

        <div class="text-xs text-slate-400 font-medium px-2">
            Mostrando: <span class="font-bold text-slate-700" id="contadorEquiposTop"><?= count($equipos) ?></span> equipos
        </div>
    </div>

    <!-- Barra de Filtros -->
    <div class="bg-white rounded-2xl p-5 border border-slate-200">
        <form id="form-filtros-equipos" autocomplete="off" method="GET" class="flex flex-col lg:flex-row lg:items-end gap-4">
            <div class="flex-1">
                <label for="buscar_equipo" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Búsqueda Rápida</label>
                <div class="relative flex items-center">
                    <span class="absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none">
                        <i class="fa-solid fa-magnifying-glass text-xs"></i>
                    </span>
                    <input type="text" id="buscar_equipo" 
                           oninput="toggleClearBtn('buscar_equipo', 'btn_clear_equipo'); filtrarTablaEquipos();" 
                           onkeydown="if(event.key === 'Escape'){ limpiarBuscador('buscar_equipo', 'btn_clear_equipo', filtrarTablaEquipos); }" 
                           placeholder="Buscar por tipo, marca, modelo, S/N, IP, colaborador..." 
                           class="w-full bg-slate-100 border border-slate-200 rounded-xl py-2.5 pl-10 pr-10 text-xs text-slate-800 focus:outline-none focus:border-cyan-400 placeholder-slate-400 transition-all duration-300">
                    <button type="button" id="btn_clear_equipo" 
                            onclick="limpiarBuscador('buscar_equipo', 'btn_clear_equipo', filtrarTablaEquipos)" 
                            class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-700 hover:bg-slate-200/60 p-1 rounded-full transition-all duration-200 hidden items-center justify-center cursor-pointer" 
                            title="Limpiar búsqueda">
                        <i class="fa-solid fa-circle-xmark text-sm"></i>
                    </button>
                </div>
            </div>
            <div class="w-full lg:w-48">
                <label for="f_estado" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Estado</label>
                <select autocomplete="off" name="estado" id="f_estado" onchange="this.form.submit()" class="w-full bg-slate-100 border border-slate-200 rounded-xl py-2.5 px-3 text-xs text-slate-800 focus:outline-none focus:border-cyan-400 cursor-pointer font-medium">
                    <option value="">-- Todos los Estados --</option>
                    <option value="Asignado" <?= ($filtro_estado === 'Asignado') ? 'selected' : '' ?>>🔵 Asignados (<?= $tot_asig ?>)</option>
                    <option value="Disponible" <?= ($filtro_estado === 'Disponible') ? 'selected' : '' ?>>🟢 En Stock (<?= $tot_disp ?>)</option>
                    <option value="Mantenimiento" <?= ($filtro_estado === 'Mantenimiento') ? 'selected' : '' ?>>🟡 Mantenimiento (<?= $tot_mant ?>)</option>
                    <option value="De Baja" <?= ($filtro_estado === 'De Baja') ? 'selected' : '' ?>>🔴 De Baja (<?= $tot_baja ?>)</option>
                </select>
            </div>
            <div class="flex-1">
                <label for="f_suc" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Filtrar por Sucursal</label>
                <select autocomplete="off" name="sucursal_id" id="f_suc" onchange="this.form.submit()" class="w-full bg-slate-100 border border-slate-200 rounded-xl py-2.5 px-4 text-xs text-slate-800 focus:outline-none focus:border-cyan-400 cursor-pointer font-medium">
                    <option value="">-- Todas las Sucursales --</option>
                    <?php 
                    $currentEmp = null;
                    foreach ($sucursales as $s): 
                        if ($currentEmp !== $s['empresa_nombre']):
                            if ($currentEmp !== null) echo '</optgroup>';
                            $currentEmp = $s['empresa_nombre'];
                            echo '<optgroup label="🏢 ' . htmlspecialchars($currentEmp) . '">';
                        endif;
                    ?>
                        <option value="<?= $s['id'] ?>" <?= ($filtro_sucursal == $s['id']) ? 'selected' : '' ?>><?= htmlspecialchars($s['nombre']) ?></option>
                    <?php endforeach; ?>
                    <?php if ($currentEmp !== null) echo '</optgroup>'; ?>
                </select>
            </div>
            <div class="flex-1">
                <label for="f_dep" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Filtrar por Departamento</label>
                <select autocomplete="off" name="departamento" id="f_dep" onchange="this.form.submit()" class="w-full bg-slate-100 border border-slate-200 rounded-xl py-2.5 px-4 text-xs text-slate-800 focus:outline-none focus:border-cyan-400 cursor-pointer font-medium">
                    <option value="">-- Todos los Departamentos --</option>
                    <?php foreach ($departamentos as $d): ?>
                        <option value="<?= htmlspecialchars($d['departamento']) ?>" <?= ($filtro_depto === $d['departamento']) ? 'selected' : '' ?>><?= htmlspecialchars($d['departamento']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flex gap-2 shrink-0">
                <?php if (!empty($filtro_tipo)): ?>
                    <input type="hidden" name="tipo" value="<?= htmlspecialchars($filtro_tipo) ?>">
                    <span id="chip-filtro-tipo" class="px-3 py-2.5 rounded-xl bg-violet-50 border border-violet-200 text-violet-600 text-xs font-semibold flex items-center gap-1.5">
                        <i class="fa-solid fa-microchip"></i> <?= htmlspecialchars($filtro_tipo === '__otros__' ? 'Otros' : $filtro_tipo) ?>
                    </span>
                <?php endif; ?>
                <button type="submit" class="px-5 py-2.5 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold text-xs border border-slate-200 flex items-center gap-1.5 transition">
                    <i class="fa-solid fa-filter"></i> Aplicar
                </button>
                <?php if (!empty($filtro_sucursal) || !empty($filtro_depto) || !empty($filtro_tipo) || !empty($filtro_estado)): ?>
                    <a href="index.php" class="px-5 py-2.5 rounded-xl bg-rose-500/10 border border-rose-500/20 text-rose-500 hover:bg-rose-500/20 text-xs font-semibold flex items-center gap-1.5 transition">
                        <i class="fa-solid fa-xmark"></i> Limpiar
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Historial de Asignaciones (Visible si se solicita) -->
    <?php if ($ver_historial_id > 0 && $equipo_detalle): ?>
        <div id="modal-historial-asignaciones" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[9999] flex items-center justify-center p-4">
            <div class="bg-white border border-slate-200 rounded-3xl w-full max-w-2xl overflow-hidden shadow-2xl animate-fade-in relative max-h-[85vh] flex flex-col">
                
                <!-- Cabecera del Modal -->
                <div class="p-6 pb-2 flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="h-12 w-12 rounded-2xl bg-cyan-500/10 border border-cyan-500/20 text-cyan-600 flex items-center justify-center text-xl shrink-0">
                            <i class="fa-regular fa-clock"></i>
                        </div>
                        <div>
                            <span class="text-[10px] text-cyan-500 uppercase font-bold tracking-wider">Historial de Asignaciones</span>
                            <h3 class="font-bold text-slate-800 text-sm md:text-base leading-tight mt-0.5"><?= htmlspecialchars("{$equipo_detalle['tipo']} {$equipo_detalle['marca']} {$equipo_detalle['modelo']}") ?></h3>
                            <p class="text-xs text-slate-400 font-mono mt-0.5">S/N: <?= htmlspecialchars($equipo_detalle['service_tag_sn']) ?></p>
                        </div>
                    </div>
                </div>

                <!-- Cuerpo del Modal (Scrollable) -->
                <div class="p-6 space-y-6 overflow-y-auto flex-1 max-h-[50vh]">
                    <div class="relative pl-6 border-l border-slate-200 space-y-6 ml-2 pt-2">
                        <?php if (empty($historial)): ?>
                            <p class="text-xs text-slate-400 pl-2">Este equipo nunca ha sido asignado a ningún empleado.</p>
                        <?php else: ?>
                            <?php foreach ($historial as $h): 
                                $activo = is_null($h['fecha_devolucion']);
                            ?>
                                <div class="relative">
                                    <!-- Nodo indicador de la línea de tiempo -->
                                    <span class="absolute -left-[31px] top-1.5 h-2.5 w-2.5 rounded-full <?= $activo ? 'bg-emerald-400 border-2 border-emerald-400 animate-pulse' : 'bg-gray-600 border border-slate-200' ?>"></span>
                                    
                                    <div class="text-xs">
                                        <div class="flex items-center justify-between gap-4 mb-1">
                                            <span class="font-bold text-slate-800"><?= htmlspecialchars($h['empleado_nombre']) ?></span>
                                            <span class="text-[10px] text-slate-400 font-mono"><?= date('d/m/Y', strtotime($h['fecha_entrega'])) ?></span>
                                        </div>
                                        <p class="text-slate-500"><?= htmlspecialchars($h['cargo'] ?? '') ?> &bull; <?= htmlspecialchars($h['departamento'] ?? '') ?></p>
                                        <?php if (!empty($h['entregado_a'])): ?>
                                            <p class="text-[11px] text-slate-600 mt-1"><i class="fa-solid fa-handshake text-slate-400 mr-1"></i>Físicamente entregado a: <span class="font-semibold"><?= htmlspecialchars($h['entregado_a']) ?></span></p>
                                        <?php endif; ?>
                                        <p class="text-slate-400 mt-1 italic">Entrega: "<?= htmlspecialchars($h['condicion_entrega'] ?? 'Sin condiciones especificadas') ?>"</p>
                                        <?php if (!empty($h['asignado_por'])): ?>
                                            <p class="text-[10px] text-slate-400 mt-0.5"><i class="fa-solid fa-user-pen mr-1"></i>Asignado por: <span class="font-medium text-slate-600"><?= htmlspecialchars($h['asignado_por']) ?></span></p>
                                        <?php endif; ?>
                                        
                                        <?php if (!$activo): ?>
                                            <div class="mt-2 text-[11px] bg-slate-50 p-2.5 rounded-xl border border-slate-200 text-slate-400 space-y-1">
                                                <div class="flex justify-between font-medium">
                                                    <span>Devuelto el: <?= date('d/m/Y', strtotime($h['fecha_devolucion'])) ?></span>
                                                    <div class="text-right">
                                                        <?php if (!empty($h['devuelto_por'])): ?>
                                                            <div class="mb-0.5">Devuelto por: <span class="font-semibold text-slate-600"><?= htmlspecialchars($h['devuelto_por']) ?></span></div>
                                                        <?php endif; ?>
                                                        <?php if (!empty($h['recibido_por'])): ?>
                                                            <div>Recibido por (IT): <span class="font-semibold text-slate-600"><?= htmlspecialchars($h['recibido_por']) ?></span></div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <?php if (!empty($h['condicion_devolucion'])): ?>
                                                    <p class="italic text-slate-500 font-medium">Recepción: "<?= htmlspecialchars($h['condicion_devolucion']) ?>"</p>
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="mt-1.5 inline-block px-1.5 py-0.5 rounded text-[8px] font-bold bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 uppercase">Activo</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            
                            <!-- Nodo Inicial: Origen del Equipo -->
                            <div class="relative">
                                <span class="absolute -left-[31px] top-1.5 h-2.5 w-2.5 rounded-full bg-slate-400 border border-slate-200"></span>
                                <div class="text-xs">
                                    <div class="flex items-center justify-between gap-4 mb-1">
                                        <span class="font-bold text-slate-600">Registro Inicial en Inventario</span>
                                    </div>
                                    <?php if (!empty($origen['proveedor_origen'])): ?>
                                        <p class="text-slate-500 mt-1 italic">Entregado / Provisto por: "<?= htmlspecialchars($origen['proveedor_origen']) ?>"</p>
                                    <?php endif; ?>
                                    <?php if (!empty($origen['registrado_por'])): ?>
                                        <p class="text-[10px] text-slate-400 mt-0.5"><i class="fa-solid fa-user-check mr-1"></i>Registrado por (IT): <span class="font-medium text-slate-500"><?= htmlspecialchars($origen['registrado_por']) ?></span></p>
                                    <?php endif; ?>
                                </div>
                            </div>

                        <?php endif; ?>
                    </div>
                </div>

                <!-- Pie del Modal -->
                <div class="p-6 pt-0 flex items-center justify-end">
                    <a href="index.php" class="px-5 py-2.5 rounded-xl bg-slate-800 hover:bg-slate-900 text-white text-xs font-bold transition shadow-md flex items-center gap-1.5">
                        <i class="fa-solid fa-check"></i> Cerrar Historial
                    </a>
                </div>

            </div>
        </div>
    <?php endif; ?>

    <!-- Tabla de Equipos -->
    <div class="glass-panel rounded-2xl overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left modern-table text-sm" id="tablaEquipos">
                <thead>
                    <tr class="bg-slate-50 border-b border-slate-200 text-xs font-bold text-slate-600 uppercase tracking-wider">
                        <th class="py-3.5 px-3 text-center w-12">#</th>
                        <th class="py-3.5 px-4">Sucursal</th>
                        <th class="py-3.5 px-4">Colaborador</th>
                        <th class="py-3.5 px-4">Área</th>
                        <th class="py-3.5 px-4">Equipo</th>
                        <th class="py-3.5 px-4">Marca</th>
                        <th class="py-3.5 px-4">Modelo</th>
                        <th class="py-3.5 px-4">Serie (S/N)</th>
                        <th class="py-3.5 px-4">Procesador</th>
                        <th class="py-3.5 px-4">Disco</th>
                        <th class="py-3.5 px-4">RAM</th>
                        <th class="py-3.5 px-4 text-center">Estado</th>
                        <th class="py-3.5 px-4 text-center">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($equipos)): ?>
                        <tr>
                            <td colspan="13" class="text-center py-10 text-slate-400">
                                No se encontraron equipos en inventario bajo estos filtros.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php 
                        $num_inv = 1;
                        foreach ($equipos as $eq): 
                            // Badges del hardware
                            if ($eq['estado'] === 'Disponible') $badge = 'bg-emerald-500/10 text-emerald-500 border border-emerald-500/20';
                            elseif ($eq['estado'] === 'Asignado') $badge = 'bg-blue-500/10 text-blue-500 border border-blue-500/20';
                            elseif ($eq['estado'] === 'Mantenimiento') $badge = 'bg-amber-500/10 text-amber-500 border border-amber-500/20';
                            else $badge = 'bg-rose-500/10 text-rose-500 border border-rose-500/20';
                        ?>
                            <tr class="hover:bg-cyan-50/60 cursor-pointer transition border-b border-slate-100 group" onclick='verDetalleEquipo(<?= json_encode($eq, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                <!-- # Numeración -->
                                <td class="py-3.5 px-3 text-center text-xs font-mono font-bold text-slate-400 group-hover:text-cyan-600">
                                    <?= $num_inv++ ?>
                                </td>

                                <!-- Sucursal -->
                                <td class="py-3.5 px-4 text-xs font-semibold text-slate-700">
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-slate-100 text-slate-700 border border-slate-200 font-medium">
                                        <i class="fa-solid fa-building text-cyan-600 text-[10px]"></i>
                                        <?= htmlspecialchars($eq['sucursal_nombre']) ?>
                                    </span>
                                </td>

                                <!-- Colaborador -->
                                <td class="py-3.5 px-4 text-xs font-semibold text-slate-800">
                                    <?php if ($eq['estado'] === 'Disponible'): ?>
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-emerald-50 text-emerald-700 border border-emerald-200/80 font-bold text-xs">
                                            <i class="fa-solid fa-box text-emerald-500 text-[10px]"></i> En Stock / Libre
                                        </span>
                                    <?php elseif ($eq['estado'] === 'Mantenimiento'): ?>
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-amber-50 text-amber-700 border border-amber-200/80 font-bold text-xs">
                                            <i class="fa-solid fa-screwdriver-wrench text-amber-500 text-[10px]"></i> En Taller
                                        </span>
                                    <?php elseif (!empty($eq['empleado_nombre'])): ?>
                                        <div class="flex items-center gap-1.5">
                                            <i class="fa-regular fa-user text-blue-500 text-[11px]"></i>
                                            <span class="font-bold text-slate-800"><?= htmlspecialchars($eq['empleado_nombre']) ?></span>
                                        </div>
                                    <?php else: ?>
                                        <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-lg bg-slate-100 text-slate-500 text-xs italic">
                                            Sin Asignar
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <!-- Área -->
                                <td class="py-3.5 px-4 text-xs text-slate-600 font-medium">
                                    <?= htmlspecialchars(!empty($eq['empleado_depto']) ? $eq['empleado_depto'] : $eq['sucursal_nombre']) ?>
                                </td>

                                <!-- Equipo (Tipo) & IP -->
                                <td class="py-3.5 px-4 text-xs font-bold text-slate-800">
                                    <div class="flex flex-col gap-1">
                                        <span class="inline-flex items-center gap-1.5">
                                            <?php
                                            $tUpper = strtoupper(trim($eq['tipo']));
                                            if ($tUpper === 'IMPRESORA') echo '<i class="fa-solid fa-print text-purple-500 text-xs"></i>';
                                            elseif ($tUpper === 'DESKTOP') echo '<i class="fa-solid fa-desktop text-blue-500 text-xs"></i>';
                                            elseif ($tUpper === 'SWITCH' || $tUpper === 'AP' || $tUpper === 'ACCESS POINT' || $tUpper === 'FIREWALL') echo '<i class="fa-solid fa-network-wired text-amber-500 text-xs"></i>';
                                            elseif ($tUpper === 'SERVIDOR') echo '<i class="fa-solid fa-server text-indigo-500 text-xs"></i>';
                                            elseif ($tUpper === 'OTRO') echo '<i class="fa-solid fa-plug text-emerald-500 text-xs"></i>';
                                            else echo '<i class="fa-solid fa-laptop text-cyan-500 text-xs"></i>';
                                            ?>
                                            <?= htmlspecialchars($eq['tipo']) ?>
                                        </span>
                                        <?php if (!empty($eq['ip_direccion'])): ?>
                                            <span class="inline-flex items-center gap-1 text-[10px] font-mono font-bold bg-slate-900 text-cyan-400 px-2 py-0.5 rounded border border-slate-700 w-max" title="Dirección IP de Red">
                                                <i class="fa-solid fa-network-wired text-[9px]"></i> <?= htmlspecialchars($eq['ip_direccion']) ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <!-- Marca -->
                                <td class="py-3.5 px-4 text-xs text-slate-700 font-medium">
                                    <?= htmlspecialchars($eq['marca']) ?>
                                </td>

                                <!-- Modelo -->
                                <td class="py-3.5 px-4 text-xs text-slate-700 font-mono">
                                    <?= htmlspecialchars($eq['modelo']) ?>
                                </td>

                                <!-- Serie -->
                                <td class="py-3.5 px-4 text-xs font-mono font-bold text-slate-700 select-all">
                                    <?= htmlspecialchars($eq['service_tag_sn']) ?>
                                </td>

                                <!-- Procesador -->
                                <td class="py-3.5 px-4 text-xs text-slate-500 max-w-[120px] truncate" title="<?= htmlspecialchars($eq['procesador'] ?? '') ?>">
                                    <?= htmlspecialchars($eq['procesador'] ?: '-') ?>
                                </td>

                                <!-- Disco -->
                                <td class="py-3.5 px-4 text-xs text-slate-500 font-mono">
                                    <?= htmlspecialchars($eq['disco'] ?: '-') ?>
                                </td>

                                <!-- RAM -->
                                <td class="py-3.5 px-4 text-xs text-slate-500 font-mono">
                                    <?= htmlspecialchars($eq['ram'] ?: '-') ?>
                                </td>

                                <!-- Estado -->
                                <td class="py-3.5 px-4 text-center">
                                    <?php if ($eq['estado'] === 'Disponible'): ?>
                                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                            <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span> Disponible
                                        </span>
                                    <?php elseif ($eq['estado'] === 'Asignado'): ?>
                                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[10px] font-bold bg-blue-50 text-blue-700 border border-blue-200">
                                            <span class="h-1.5 w-1.5 rounded-full bg-blue-500"></span> Asignado
                                        </span>
                                    <?php elseif ($eq['estado'] === 'Mantenimiento'): ?>
                                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[10px] font-bold bg-amber-50 text-amber-700 border border-amber-200">
                                            <span class="h-1.5 w-1.5 rounded-full bg-amber-500"></span> Taller
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[10px] font-bold bg-rose-50 text-rose-700 border border-rose-200">
                                            <span class="h-1.5 w-1.5 rounded-full bg-rose-500"></span> De Baja
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <!-- Acciones -->
                                <td class="py-3.5 px-4 text-center" onclick="event.stopPropagation();">
                                    <div class="flex items-center justify-center gap-1">
                                        <?php $isInfra = in_array($eq['tipo'], ['Impresora', 'Switch', 'AP', 'Control de acceso', 'Servidor']); ?>
                                        
                                        <?php if (!$isInfra): ?>
                                            <!-- Ver Historial -->
                                            <a href="?history_id=<?= $eq['id'] ?><?= !empty($filtro_sucursal)?'&sucursal_id='.$filtro_sucursal:'' ?><?= !empty($filtro_depto)?'&departamento='.$filtro_depto:'' ?><?= !empty($filtro_estado)?'&estado='.$filtro_estado:'' ?>" onclick="event.stopPropagation();" class="h-8 w-8 rounded-lg hover:bg-slate-100 flex items-center justify-center text-slate-500 hover:text-slate-800 transition" title="Ver Historial Asignaciones">
                                                <i class="fa-regular fa-clock text-xs"></i>
                                            </a>
                                            
                                            <?php if ($eq['estado'] === 'Disponible'): ?>
                                                <!-- Asignar Hardware -->
                                                <button onclick="event.stopPropagation(); abrirAsignar('<?= $eq['id'] ?>', '<?= htmlspecialchars($eq['marca'].' '.$eq['modelo']) ?>')" class="h-8 w-8 rounded-lg hover:bg-emerald-50 text-emerald-600 flex items-center justify-center transition" title="Asignar a Colaborador">
                                                    <i class="fa-solid fa-user-plus text-xs"></i>
                                                </button>
                                                <!-- Enviar a Mantenimiento -->
                                                <button onclick="event.stopPropagation(); abrirCambiarEstado('<?= $eq['id'] ?>', '<?= htmlspecialchars($eq['marca'].' '.$eq['modelo']) ?>', 'Mantenimiento')" class="h-8 w-8 rounded-lg hover:bg-amber-50 text-slate-400 hover:text-amber-600 flex items-center justify-center transition" title="Enviar a Mantenimiento">
                                                    <i class="fa-solid fa-wrench text-xs"></i>
                                                </button>
                                            <?php elseif ($eq['estado'] === 'Asignado'): ?>
                                                <!-- Imprimir Constancia -->
                                                <a href="imprimir_asignacion.php?id=<?= $eq['asignacion_id'] ?>" onclick="event.stopPropagation();" class="h-8 w-8 rounded-lg hover:bg-indigo-50 text-slate-500 hover:text-indigo-600 flex items-center justify-center transition" title="Imprimir Constancia">
                                                    <i class="fa-solid fa-print text-xs"></i>
                                                </a>
                                                <!-- Retornar / Devolver Hardware -->
                                                <button onclick="event.stopPropagation(); abrirDevolver('<?= $eq['id'] ?>', '<?= htmlspecialchars($eq['marca'].' '.$eq['modelo']) ?>', '<?= $eq['asignacion_id'] ?>')" class="h-8 w-8 rounded-lg hover:bg-amber-50 text-slate-500 hover:text-amber-600 flex items-center justify-center transition" title="Registrar Devolución a Stock">
                                                    <i class="fa-solid fa-arrow-rotate-left text-xs"></i>
                                                </button>
                                                <!-- Enviar a Mantenimiento -->
                                                <button onclick="event.stopPropagation(); abrirCambiarEstado('<?= $eq['id'] ?>', '<?= htmlspecialchars($eq['marca'].' '.$eq['modelo']) ?>', 'Mantenimiento')" class="h-8 w-8 rounded-lg hover:bg-amber-50 text-slate-400 hover:text-amber-600 flex items-center justify-center transition" title="Enviar a Mantenimiento">
                                                    <i class="fa-solid fa-wrench text-xs"></i>
                                                </button>
                                            <?php elseif ($eq['estado'] === 'Mantenimiento'): ?>
                                                <!-- Reincorporar a Stock -->
                                                <button onclick="event.stopPropagation(); abrirCambiarEstado('<?= $eq['id'] ?>', '<?= htmlspecialchars($eq['marca'].' '.$eq['modelo']) ?>', 'Disponible')" class="h-8 w-8 rounded-lg hover:bg-emerald-50 text-emerald-600 flex items-center justify-center transition" title="Finalizar Mantenimiento y Regresar a Stock">
                                                    <i class="fa-solid fa-circle-check text-xs"></i>
                                                </button>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        
                                        <!-- Eliminar / Dar de Baja -->
                                        <button onclick="event.stopPropagation(); confirmarBaja('<?= $eq['id'] ?>')" class="h-8 w-8 rounded-lg hover:bg-rose-50 flex items-center justify-center text-slate-400 hover:text-rose-500 transition" title="Dar de Baja">
                                            <i class="fa-regular fa-trash-can text-xs"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <!-- Contenedor de Paginación de Alta Gama -->
        <div id="paginacionEquipos"></div>
    </div>
</div>

<!-- ========================================================
     MODALES DEL MÓDULO (OCULTOS POR DEFECTO)
     ======================================================== -->

<!-- 1. Modal Nuevo Equipo -->
<div id="modal-nuevo-equipo" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-[9999] flex items-center justify-center hidden p-4">
    <div class="bg-white border border-slate-200 rounded-3xl w-full max-w-lg overflow-hidden shadow-2xl animate-fade-in">
        <div class="p-6 border-b border-slate-200 flex items-center justify-between">
            <h3 class="font-bold text-slate-800 flex items-center gap-2">
                <i class="fa-solid fa-plus text-cyan-400"></i> Registrar Nuevo Activo IT
            </h3>
            <button onclick="cerrarModal('modal-nuevo-equipo')" class="text-slate-400 hover:text-slate-800"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form autocomplete="off" action="guardar.php?action=add_eq" method="POST" class="p-6 space-y-4">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="eq_tipo" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Tipo de Activo</label>
                    <select autocomplete="off" name="tipo" id="eq_tipo" required onchange="aplicarReglasCamposActivo(this.value)" class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400">
                        <option value="Laptop" selected>Laptop</option>
                        <option value="Desktop">Desktop</option>
                        <option value="Celular">Celular</option>
                        <option value="Servidor">Servidor</option>
                        <option value="Impresora">Impresora</option>
                        <option value="Control de acceso">Control de acceso</option>
                        <option value="Switch">Switch</option>
                        <option value="AP">AP</option>
                        <option value="__otro__">+ Agregar otro tipo...</option>
                    </select>
                    <div id="eq_tipo_otro_container" class="hidden mt-2">
                        <input type="text" name="tipo_otro" id="eq_tipo_otro" placeholder="Especifica el tipo de equipo..." class="w-full bg-slate-100 border border-slate-200 rounded-xl py-2 px-3 text-xs text-slate-800 focus:outline-none focus:border-cyan-400">
                    </div>
                </div>
                <div id="campo_sucursal">
                    <label for="eq_suc" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Ubicación / Sucursal</label>
                    <select autocomplete="off" name="sucursal_id" id="eq_suc" required class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400">
                        <?php 
                        $mEmp = null;
                        foreach ($sucursales as $s): 
                            if ($mEmp !== $s['empresa_nombre']):
                                if ($mEmp !== null) echo '</optgroup>';
                                $mEmp = $s['empresa_nombre'];
                                echo '<optgroup label="🏢 ' . htmlspecialchars($mEmp) . '">';
                            endif;
                        ?>
                            <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['empresa_nombre'] . ' - ' . $s['nombre']) ?></option>
                        <?php endforeach; ?>
                        <?php if ($mEmp !== null) echo '</optgroup>'; ?>
                    </select>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div id="campo_marca">
                    <label for="eq_marca" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Marca</label>
                    <input type="text" name="marca" id="eq_marca" required placeholder="Ej: Dell, Cisco, Fortinet" class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400">
                </div>
                <div id="campo_modelo">
                    <label for="eq_mod" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Modelo</label>
                    <input type="text" name="modelo" id="eq_mod" required placeholder="Ej: Latitude 5420" class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400">
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div id="campo_sn">
                    <label for="eq_sn" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Service Tag / S/N</label>
                    <input type="text" name="service_tag_sn" id="eq_sn" required placeholder="Código Único" class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400">
                </div>
                <div id="campo_ip" class="hidden">
                    <label for="eq_ip" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Dirección IP</label>
                    <input type="text" name="ip_direccion" id="eq_ip" placeholder="Ej: 192.168.10.X" class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400">
                </div>
            </div>

            <!-- Grupo Hardware (Oculto por defecto para ciertos equipos) -->
            <div id="grupo_hardware" class="grid grid-cols-3 gap-4">
                <div>
                    <label for="eq_procesador" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Procesador</label>
                    <input type="text" name="procesador" id="eq_procesador" placeholder="Ej: i5 11va Gen" class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400">
                </div>
                <div>
                    <label for="eq_ram" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">RAM</label>
                    <input type="text" name="ram" id="eq_ram" placeholder="Ej: 16GB DDR4" class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400">
                </div>
                <div>
                    <label for="eq_disco" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Disco</label>
                    <input type="text" name="disco" id="eq_disco" placeholder="Ej: 512GB SSD" class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400">
                </div>
            </div>

            <!-- Grupo Credenciales Locales -->
            <div id="grupo_credenciales" class="grid grid-cols-2 gap-4 border border-blue-100 rounded-2xl p-4 bg-blue-50/50">
                <div>
                    <label for="eq_usuario_local" class="text-xs font-semibold text-blue-600 uppercase tracking-wider block mb-2">Usuario Local (OS)</label>
                    <input type="text" name="usuario_local" id="eq_usuario_local" placeholder="Ej: AdminLocal" class="w-full bg-white border border-blue-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-blue-400">
                </div>
                <div>
                    <label for="eq_password_local" class="text-xs font-semibold text-blue-600 uppercase tracking-wider block mb-2">Contraseña Local</label>
                    <input type="text" name="password_local" id="eq_password_local" placeholder="Contraseña de la cuenta" class="w-full bg-white border border-blue-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-blue-400">
                </div>
            </div>
            <!-- Origen del equipo al registrar -->
            <div class="border border-slate-200 rounded-2xl p-4 bg-slate-50 space-y-4">
                <div>
                    <label for="eq_proveedor_origen" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Entregado a IT / Provisto por</label>
                    <input type="text" name="proveedor_origen" id="eq_proveedor_origen" required placeholder="Nombre de quien entregó o proveedor del equipo" class="w-full bg-white border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400">
                </div>
            </div>
            
            <div class="pt-4 flex gap-3">
                <button type="button" onclick="cerrarModal('modal-nuevo-equipo')" class="flex-1 py-3 rounded-xl bg-white/5 hover:bg-white/10 text-slate-800 font-semibold text-sm transition border border-slate-200">Cancelar</button>
                <button type="submit" class="flex-1 py-3 rounded-xl grad-cyan-blue text-slate-800 font-bold text-sm tracking-wide shadow-lg shadow-cyan-500/20 hover:opacity-90 transition">Guardar Activo</button>
            </div>
        </form>
    </div>
</div>

<!-- 2. Modal Asignar Equipo -->
<div id="modal-asignar" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-[9999] flex items-center justify-center hidden p-4">
    <div class="bg-white border border-slate-200 rounded-3xl w-full max-w-lg overflow-hidden shadow-2xl animate-fade-in">
        <div class="p-6 border-b border-slate-200 flex items-center justify-between">
            <h3 class="font-bold text-slate-800 flex items-center gap-2">
                <i class="fa-solid fa-user-plus text-cyan-400"></i> Asignación de Hardware
            </h3>
            <button onclick="cerrarModal('modal-asignar')" class="text-slate-400 hover:text-slate-800"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form autocomplete="off" action="guardar.php?action=assign" method="POST" class="p-6 space-y-4">
            <input type="hidden" name="equipo_id" id="asig_eq_id">
            <div>
                <label class="text-[10px] text-slate-400 uppercase font-bold tracking-wider">Equipo Seleccionado</label>
                <div id="asig_eq_label" class="text-sm font-semibold text-slate-500 bg-slate-200 border border-slate-200 rounded-xl py-3 px-4 mt-1 font-mono cursor-not-allowed"></div>
            </div>
            <div>
                <label for="asig_emp" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Empleado Asignado</label>
                <select autocomplete="off" name="empleado_id" id="asig_emp" required class="searchable-select w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400" data-placeholder="Selecciona el empleado que recibe el hardware...">
                    <option value="" disabled selected>Selecciona el empleado que recibe el hardware...</option>
                    <?php foreach ($empleados as $emp): ?>
                        <option value="<?= $emp['id'] ?>"><?= htmlspecialchars($emp['nombre'] . ' (' . $emp['cargo'] . ' - ' . $emp['departamento'] . ')') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="asig_date" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Fecha de Entrega</label>
                <input type="date" name="fecha_entrega" id="asig_date" value="<?= date('Y-m-d') ?>" required readonly class="w-full bg-slate-200 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-500 focus:outline-none cursor-not-allowed">
            </div>
            <div>
                <label for="asig_cond" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Condición de Entrega</label>
                <input type="text" name="condicion_entrega" id="asig_cond" required placeholder="Ej: Cargador, mouse, estuche. Sin golpes, pantalla limpia." class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400">
            </div>
            <div>
                <label for="asig_entregado_a" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Se entregará físicamente a</label>
                <input type="text" name="entregado_a" id="asig_entregado_a" required placeholder="Nombre de la persona que lo recibe físicamente" class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400">
            </div>
            <div class="pt-4 flex gap-3">
                <button type="button" onclick="cerrarModal('modal-asignar')" class="flex-1 py-3 rounded-xl bg-white/5 hover:bg-white/10 text-slate-800 font-semibold text-sm transition border border-slate-200">Cancelar</button>
                <button type="submit" class="flex-1 py-3 rounded-xl bg-emerald-500 hover:bg-emerald-600 text-white font-bold text-sm tracking-wide shadow-lg shadow-emerald-500/20 transition">Confirmar Asignación</button>
            </div>
        </form>
    </div>
</div>

<!-- 3. Modal Devolver Equipo -->
<div id="modal-devolver" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-[9999] flex items-center justify-center hidden p-4">
    <div class="bg-white border border-slate-200 rounded-3xl w-full max-w-lg overflow-hidden shadow-2xl animate-fade-in">
        <div class="p-6 border-b border-slate-200 flex items-center justify-between">
            <h3 class="font-bold text-slate-800 flex items-center gap-2">
                <i class="fa-solid fa-arrow-rotate-left text-cyan-400"></i> Registrar Devolución de Activo
            </h3>
            <button onclick="cerrarModal('modal-devolver')" class="text-slate-400 hover:text-slate-800"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form autocomplete="off" action="guardar.php?action=return" method="POST" class="p-6 space-y-4">
            <input type="hidden" name="equipo_id" id="dev_eq_id">
            <input type="hidden" name="asignacion_id" id="dev_asig_id">
            <div>
                <label class="text-[10px] text-slate-400 uppercase font-bold tracking-wider">Equipo en Proceso de Devolución</label>
                <div id="dev_eq_label" class="text-sm font-semibold text-slate-500 bg-slate-200 border border-slate-200 rounded-xl py-3 px-4 mt-1 font-mono cursor-not-allowed"></div>
            </div>
            <div>
                <label for="dev_date" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Fecha de Devolución</label>
                <input type="date" name="fecha_devolucion" id="dev_date" value="<?= date('Y-m-d') ?>" required readonly class="w-full bg-slate-200 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-500 focus:outline-none cursor-not-allowed">
            </div>
            <div>
                <label for="dev_cond" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Condición de Recepción (Historial hardware)</label>
                <input type="text" name="condicion_devolucion" id="dev_cond" required placeholder="Ej: Devuelto con rayón en carcasa, cargador original operativo." class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400">
            </div>
            <div>
                <label for="dev_devuelto_por" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Devuelto / Entregado por</label>
                <input type="text" name="devuelto_por" id="dev_devuelto_por" placeholder="Nombre de la persona que lo entrega a IT" required class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400">
            </div>
            <div class="pt-4 flex gap-3">
                <button type="button" onclick="cerrarModal('modal-devolver')" class="flex-1 py-3 rounded-xl bg-white/5 hover:bg-white/10 text-slate-800 font-semibold text-sm transition border border-slate-200">Cancelar</button>
                <button type="submit" class="flex-1 py-3 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-800 font-bold text-sm tracking-wide shadow-lg shadow-amber-500/20 transition">Registrar Retorno</button>
            </div>
        </form>
    </div>
</div>

<!-- 4. Modal Detalle Completo del Activo IT -->
<div id="modal-detalle-equipo" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[9999] flex items-center justify-center hidden p-4">
    <div class="bg-white border border-slate-200 rounded-3xl w-full max-w-2xl overflow-hidden shadow-2xl animate-fade-in relative max-h-[90vh] flex flex-col">
        <!-- Cabecera de la Ficha -->
        <div class="p-6 border-b border-slate-100 bg-slate-50/80 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="h-12 w-12 rounded-2xl bg-cyan-500/10 border border-cyan-500/20 text-cyan-600 flex items-center justify-center text-xl shrink-0">
                    <i class="fa-solid fa-laptop" id="det_icon"></i>
                </div>
                <div>
                    <h3 class="font-bold text-slate-800 text-lg leading-tight" id="det_titulo">Detalle del Activo</h3>
                    <p class="text-xs text-slate-500 font-mono mt-0.5" id="det_subtitulo">S/N: ---</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <span id="det_badge_estado" class="px-3 py-1 rounded-full text-xs font-bold bg-emerald-100 text-emerald-700">Asignado</span>
                <button onclick="cerrarModal('modal-detalle-equipo')" class="h-8 w-8 rounded-full hover:bg-slate-200 text-slate-400 hover:text-slate-700 flex items-center justify-center transition">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
        </div>

        <!-- Cuerpo del Modal Scrollable -->
        <div class="p-6 space-y-6 overflow-y-auto custom-scrollbar flex-1 text-sm">
            <!-- Sección 1: Especificaciones del Hardware -->
            <div>
                <h4 class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-3 flex items-center gap-2">
                    <i class="fa-solid fa-microchip text-cyan-600"></i> Especificaciones del Hardware
                </h4>
                <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 bg-slate-50 rounded-2xl p-4 border border-slate-200/80">
                    <div>
                        <span class="text-[11px] text-slate-400 block font-semibold">Tipo de Equipo</span>
                        <span class="font-bold text-slate-800" id="det_tipo">--</span>
                    </div>
                    <div>
                        <span class="text-[11px] text-slate-400 block font-semibold">Marca / Modelo</span>
                        <span class="font-bold text-slate-800" id="det_marca_modelo">--</span>
                    </div>
                    <div>
                        <span class="text-[11px] text-slate-400 block font-semibold">Serie / Service Tag</span>
                        <span class="font-mono font-bold text-slate-800" id="det_sn">--</span>
                    </div>
                    <div id="det_ip_wrapper">
                        <span class="text-[11px] text-slate-400 block font-semibold">Dirección IP</span>
                        <span class="font-mono text-slate-800" id="det_ip">--</span>
                    </div>
                    <div>
                        <span class="text-[11px] text-slate-400 block font-semibold">Sucursal</span>
                        <span class="font-bold text-slate-800" id="det_sucursal">--</span>
                    </div>
                    <div>
                        <span class="text-[11px] text-slate-400 block font-semibold">ID de Registro</span>
                        <span class="font-mono text-slate-800" id="det_id">--</span>
                    </div>
                </div>
            </div>

            <!-- Sección 2: Responsable / Asignado Actual -->
            <div id="det_sec_asignacion">
                <h4 class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-3 flex items-center gap-2">
                    <i class="fa-solid fa-user-check text-blue-600"></i> Colaborador Responsable
                </h4>
                <div class="bg-blue-50/60 rounded-2xl p-4 border border-blue-100 space-y-2">
                    <div class="flex items-center justify-between">
                        <div>
                            <span class="font-bold text-slate-800 text-base block" id="det_emp_nombre">--</span>
                            <p class="text-xs text-slate-500" id="det_emp_cargo_depto">--</p>
                        </div>
                        <span class="text-xs font-medium text-slate-500 bg-white px-2.5 py-1 rounded-lg border border-blue-100" id="det_fecha_entrega">--</span>
                    </div>
                    <div class="pt-2 border-t border-blue-100 text-xs text-slate-600 flex items-center gap-1">
                        <span class="font-semibold text-slate-700">Condición de Entrega:</span>
                        <span id="det_condicion_entrega" class="italic text-slate-700">--</span>
                    </div>
                </div>
            </div>

            <!-- Sección 3: Credenciales de Acceso Local -->
            <div id="det_sec_credenciales">
                <h4 class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-3 flex items-center gap-2">
                    <i class="fa-solid fa-key text-amber-500"></i> Acceso Local y Seguridad
                </h4>
                <div class="bg-slate-50 rounded-2xl p-4 border border-slate-200 space-y-3">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <span class="text-[11px] text-slate-400 block font-semibold">Usuario Local</span>
                            <span class="font-mono font-bold text-slate-800" id="det_usr_local">--</span>
                        </div>
                        <div>
                            <span class="text-[11px] text-slate-400 block font-semibold">Contraseña Local</span>
                            <div class="flex items-center gap-2 mt-0.5">
                                <input type="password" readonly id="det_pass_local" class="bg-white border border-slate-200 rounded-lg px-2.5 py-1 text-xs font-mono w-full text-slate-800 focus:outline-none" value="">
                                <button type="button" onclick="toggleDetPass()" class="text-slate-500 hover:text-slate-800 text-xs px-2.5 py-1 rounded-lg bg-slate-200 hover:bg-slate-300 transition shrink-0" title="Mostrar / Ocultar">
                                    <i class="fa-solid fa-eye" id="det_pass_icon"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <div id="det_row_bitlocker" class="pt-2 border-t border-slate-200">
                        <span class="text-[11px] text-slate-400 block font-semibold">Clave de Recuperación BitLocker</span>
                        <span class="font-mono text-xs font-bold text-slate-800 select-all block bg-white border border-slate-200 p-2.5 rounded-xl mt-1 tracking-wider" id="det_bitlocker">--</span>
                    </div>
                    <div id="det_row_notas" class="pt-2 border-t border-slate-200 text-xs">
                        <span class="text-[11px] text-slate-400 block font-semibold">Notas de Acceso / PIN:</span>
                        <span class="text-slate-700 font-medium" id="det_notas_acceso">--</span>
                    </div>
                </div>
            </div>

            <!-- Sección 4: Observaciones del Registro -->
            <div id="det_sec_observaciones">
                <h4 class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-2 flex items-center gap-2">
                    <i class="fa-solid fa-file-lines text-slate-400"></i> Observaciones del Activo
                </h4>
                <div class="bg-slate-50 rounded-xl p-3 border border-slate-200 text-xs text-slate-700 italic mb-2" id="det_notas">
                    Sin observaciones guardadas.
                </div>
                <div class="text-[10px] text-slate-400 flex items-center gap-1">
                    <i class="fa-solid fa-user-shield"></i>
                    <span>Registrado por: <span id="det_registrado_por" class="font-semibold text-slate-600">--</span></span>
                </div>
            </div>
        </div>

        <!-- Pie de la Ficha -->
        <div class="p-4 border-t border-slate-100 bg-slate-50 flex items-center justify-between">
            <a id="det_link_historial" href="#" class="px-4 py-2 rounded-xl bg-slate-200 hover:bg-slate-300 text-slate-700 text-xs font-bold transition flex items-center gap-1.5">
                <i class="fa-regular fa-clock"></i> Ver Historial de Asignaciones
            </a>
            <button onclick="cerrarModal('modal-detalle-equipo')" class="px-5 py-2 rounded-xl bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold transition shadow-md">
                Cerrar
            </button>
        </div>
    </div>
</div>

<!-- 5. Modal Dar de Baja Equipo -->
<!-- 5. Modal Dar de Baja Equipo -->
<div id="modal-baja-equipo" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-[9999] flex items-center justify-center hidden p-4">
    <div class="bg-white border border-slate-200 rounded-3xl w-full max-w-sm overflow-hidden shadow-2xl animate-fade-in text-center p-8">
        <div class="h-16 w-16 bg-rose-100 text-rose-500 rounded-full flex items-center justify-center text-3xl mx-auto mb-4">
            <i class="fa-solid fa-triangle-exclamation"></i>
        </div>
        <h3 class="font-bold text-slate-800 text-xl mb-2">Dar de baja equipo</h3>
        <p class="text-slate-500 text-sm mb-6">¿Estás seguro que deseas dar de baja este equipo? Esta acción cambiará su estado a "De Baja".</p>
        <div class="flex gap-3 w-full">
            <button type="button" onclick="cerrarModal('modal-baja-equipo')" class="flex-1 py-3 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold text-sm transition">Cancelar</button>
            <a id="btn_confirmar_baja" href="#" class="flex-1 py-3 rounded-xl bg-rose-500 hover:bg-rose-600 text-white font-bold text-sm transition shadow-lg shadow-rose-500/30 flex items-center justify-center">Sí, dar de baja</a>
        </div>
    </div>
</div>

<!-- 6. Modal Cambiar Estado Rápido (Disponible / Mantenimiento) -->
<div id="modal-cambiar-estado" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-[9999] flex items-center justify-center hidden p-4">
    <div class="bg-white border border-slate-200 rounded-3xl w-full max-w-md overflow-hidden shadow-2xl animate-fade-in">
        <div class="p-6 border-b border-slate-200 flex items-center justify-between">
            <h3 class="font-bold text-slate-800 flex items-center gap-2">
                <i class="fa-solid fa-sliders text-cyan-600"></i> Gestión de Estado del Activo
            </h3>
            <button onclick="cerrarModal('modal-cambiar-estado')" class="text-slate-400 hover:text-slate-800"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form autocomplete="off" action="guardar.php?action=cambiar_estado" method="POST" class="p-6 space-y-4">
            <input type="hidden" name="equipo_id" id="ce_eq_id">
            <div>
                <label class="text-[10px] text-slate-400 uppercase font-bold tracking-wider">Activo</label>
                <div id="ce_eq_label" class="text-xs font-semibold text-slate-700 bg-slate-100 border border-slate-200 rounded-xl py-2.5 px-3 mt-1 font-mono"></div>
            </div>
            <div>
                <label for="ce_nuevo_estado" class="text-xs font-semibold text-slate-600 uppercase tracking-wider block mb-2">Nuevo Estado</label>
                <select name="nuevo_estado" id="ce_nuevo_estado" required class="w-full bg-slate-100 border border-slate-200 rounded-xl py-2.5 px-3 text-xs font-bold text-slate-800 focus:outline-none focus:border-cyan-400">
                    <option value="Disponible">🟢 Disponible (En Stock / Bodega)</option>
                    <option value="Mantenimiento">🟡 En Mantenimiento / Taller</option>
                </select>
                <p class="text-[11px] text-slate-400 mt-1">Si el equipo estaba asignado a un colaborador, su asignación se cerrará automáticamente.</p>
            </div>
            <div>
                <label for="ce_motivo" class="text-xs font-semibold text-slate-600 uppercase tracking-wider block mb-2">Detalle o Motivo (Opcional)</label>
                <textarea name="motivo" id="ce_motivo" rows="2" placeholder="Ej: Cambio de batería, mantenimiento preventivo, liberado a bodega..." class="w-full bg-slate-100 border border-slate-200 rounded-xl py-2 px-3 text-xs text-slate-800 focus:outline-none focus:border-cyan-400"></textarea>
            </div>
            <div class="pt-2 flex gap-3">
                <button type="button" onclick="cerrarModal('modal-cambiar-estado')" class="flex-1 py-2.5 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold text-xs transition">Cancelar</button>
                <button type="submit" class="flex-1 py-2.5 rounded-xl bg-cyan-600 hover:bg-cyan-700 text-white font-bold text-xs shadow-md transition">Actualizar Estado</button>
            </div>
        </form>
    </div>
</div>

<script>
    function abrirModal(id) {
        const modal = document.getElementById(id);
        const isEditOrDetail = id.includes('edit') || id.includes('det') || id.includes('ficha');
        if (!isEditOrDetail) {
            modal.querySelectorAll('form').forEach(function(f) { f.reset(); });
            modal.querySelectorAll('select').forEach(function(s) {
                if (!s.closest('form')) s.selectedIndex = 0;
            });
        }
        modal.classList.remove('hidden');
    }
    
    function cerrarModal(id) {
        document.getElementById(id).classList.add('hidden');
    }

    function confirmarBaja(id) {
        document.getElementById('btn_confirmar_baja').href = 'guardar.php?action=baja&id=' + id;
        abrirModal('modal-baja-equipo');
    }

    function abrirCambiarEstado(eq_id, name, estadoSugerido) {
        document.getElementById('ce_eq_id').value = eq_id;
        document.getElementById('ce_eq_label').innerText = name;
        if (estadoSugerido) {
            document.getElementById('ce_nuevo_estado').value = estadoSugerido;
        }
        abrirModal('modal-cambiar-estado');
    }

    function filtrarPorEstado(estado) {
        const sel = document.getElementById('f_estado');
        if (sel) {
            sel.value = estado;
            sel.form.submit();
        } else {
            const url = new URL(window.location.href);
            if (estado) {
                url.searchParams.set('estado', estado);
            } else {
                url.searchParams.delete('estado');
            }
            window.location.href = url.toString();
        }
    }

    function verDetalleEquipo(eq) {
        document.getElementById('det_titulo').innerText = (eq.tipo || 'Equipo') + ' ' + (eq.marca || '') + ' ' + (eq.modelo || '');
        document.getElementById('det_subtitulo').innerText = 'S/N: ' + (eq.service_tag_sn || 'N/A');
        document.getElementById('det_id').innerText = '#' + eq.id;
        document.getElementById('det_tipo').innerText = eq.tipo || '--';
        document.getElementById('det_marca_modelo').innerText = (eq.marca || '') + ' ' + (eq.modelo || '');
        document.getElementById('det_sn').innerText = eq.service_tag_sn || 'N/A';
        const isClientDevice = ['Laptop', 'Desktop', 'Celular'].includes(eq.tipo);
        
        if (isClientDevice) {
            document.getElementById('det_ip_wrapper').classList.add('hidden');
        } else {
            document.getElementById('det_ip_wrapper').classList.remove('hidden');
            document.getElementById('det_ip').innerText = eq.ip_direccion || 'DHCP / Dinámica';
        }
        document.getElementById('det_sucursal').innerText = eq.sucursal_nombre || '--';
        document.getElementById('det_notas').innerText = eq.notas || 'Sin observaciones guardadas.';
        document.getElementById('det_registrado_por').innerText = eq.registrado_por || 'Sistema / Pre-existente';

        // Icono según el tipo de hardware
        const iconEl = document.getElementById('det_icon');
        if (eq.tipo === 'Laptop') iconEl.className = 'fa-solid fa-laptop';
        else if (eq.tipo === 'Desktop') iconEl.className = 'fa-solid fa-desktop';
        else if (eq.tipo === 'Servidor') iconEl.className = 'fa-solid fa-server';
        else if (eq.tipo === 'Switch' || eq.tipo === 'Firewall' || eq.tipo === 'Access Point') iconEl.className = 'fa-solid fa-network-wired';
        else iconEl.className = 'fa-solid fa-microchip';

        // Badge de Estado
        const badgeEl = document.getElementById('det_badge_estado');
        badgeEl.innerText = eq.estado;
        if (eq.estado === 'Disponible') {
            badgeEl.className = 'px-3 py-1 rounded-full text-xs font-bold bg-emerald-100 text-emerald-700 border border-emerald-200';
        } else if (eq.estado === 'Asignado') {
            badgeEl.className = 'px-3 py-1 rounded-full text-xs font-bold bg-blue-100 text-blue-700 border border-blue-200';
        } else if (eq.estado === 'Mantenimiento') {
            badgeEl.className = 'px-3 py-1 rounded-full text-xs font-bold bg-amber-100 text-amber-700 border border-amber-200';
        } else {
            badgeEl.className = 'px-3 py-1 rounded-full text-xs font-bold bg-rose-100 text-rose-700 border border-rose-200';
        }

        // Asignación
        const secAsig = document.getElementById('det_sec_asignacion');
        if (eq.empleado_nombre && eq.estado === 'Asignado') {
            secAsig.classList.remove('hidden');
            document.getElementById('det_emp_nombre').innerText = eq.empleado_nombre;
            document.getElementById('det_emp_cargo_depto').innerText = (eq.empleado_cargo || '') + ' • ' + (eq.empleado_depto || '');
            document.getElementById('det_fecha_entrega').innerText = eq.fecha_entrega ? 'Entregado: ' + eq.fecha_entrega : '';
            document.getElementById('det_condicion_entrega').innerText = eq.condicion_entrega || 'Sin condición registrada';
        } else {
            secAsig.classList.add('hidden');
        }

        // Credenciales
        document.getElementById('det_usr_local').innerText = eq.usuario_local || 'admin_local';
        document.getElementById('det_pass_local').value = eq.pass_local_clear || '';
        document.getElementById('det_pass_local').type = 'password';
        document.getElementById('det_pass_icon').className = 'fa-solid fa-eye';

        const rowBit = document.getElementById('det_row_bitlocker');
        if (eq.bitlocker_clear) {
            rowBit.classList.remove('hidden');
            document.getElementById('det_bitlocker').innerText = eq.bitlocker_clear;
        } else {
            rowBit.classList.add('hidden');
        }

        const rowNotas = document.getElementById('det_row_notas');
        if (eq.notas_acceso) {
            rowNotas.classList.remove('hidden');
            document.getElementById('det_notas_acceso').innerText = eq.notas_acceso;
        } else {
            rowNotas.classList.add('hidden');
        }

        // Ocultar componentes extra para infraestructura
        const isInfra = ['Impresora', 'Switch', 'AP', 'Control de acceso', 'Servidor'].includes(eq.tipo);
        const secCredenciales = document.getElementById('det_sec_credenciales');
        const secObservaciones = document.getElementById('det_sec_observaciones');
        const linkHistorial = document.getElementById('det_link_historial');
        
        if (isInfra) {
            secCredenciales.classList.add('hidden');
            secObservaciones.classList.add('hidden');
            linkHistorial.classList.add('hidden');
        } else {
            secCredenciales.classList.remove('hidden');
            secObservaciones.classList.remove('hidden');
            linkHistorial.classList.remove('hidden');
        }

        // Enlace al historial
        linkHistorial.href = '?history_id=' + eq.id;

        abrirModal('modal-detalle-equipo');
    }

    function toggleDetPass() {
        const input = document.getElementById('det_pass_local');
        const icon = document.getElementById('det_pass_icon');
        if (input.type === 'password') {
            input.type = 'text';
            icon.className = 'fa-solid fa-eye-slash';
        } else {
            input.type = 'password';
            icon.className = 'fa-solid fa-eye';
        }
    }

    function abrirAsignar(id, name) {
        document.getElementById('asig_eq_id').value = id;
        document.getElementById('asig_eq_label').innerText = name;
        abrirModal('modal-asignar');
    }

    function abrirDevolver(eq_id, name, asig_id) {
        document.getElementById('dev_eq_id').value = eq_id;
        document.getElementById('dev_asig_id').value = asig_id;
        document.getElementById('dev_eq_label').innerText = name;
        abrirModal('modal-devolver');
    }

    // Reglas de visualización de campos al crear activo
    function aplicarReglasCamposActivo(tipo) {
        // Referencias
        const containerOtro = document.getElementById('eq_tipo_otro_container');
        const campoIp = document.getElementById('campo_ip');
        const eqIp = document.getElementById('eq_ip');
        
        const grupoHardware = document.getElementById('grupo_hardware');
        const grupoCredenciales = document.getElementById('grupo_credenciales');
        
        const labelSucursal = document.querySelector('label[for="eq_suc"]');
        
        // "Otro"
        if (tipo === '__otro__') {
            containerOtro.classList.remove('hidden');
            document.getElementById('eq_tipo_otro').required = true;
        } else {
            containerOtro.classList.add('hidden');
            document.getElementById('eq_tipo_otro').required = false;
        }

        // Cambiar Etiqueta de Sucursal
        if (['Impresora', 'Control de acceso', 'Switch', 'AP', 'Servidor'].includes(tipo)) {
            labelSucursal.innerText = 'Asignado a (Sucursal)';
        } else {
            labelSucursal.innerText = 'Ubicación / Sucursal';
        }

        // Grupo C (Sin Hardware, Sin Cred, CON IP)
        if (['Impresora', 'Control de acceso', 'Switch', 'AP'].includes(tipo)) {
            // Ocultar hardware y credenciales
            grupoHardware.classList.add('hidden');
            grupoCredenciales.classList.add('hidden');
            
            // Mostrar IP
            campoIp.classList.remove('hidden');
            eqIp.required = false;
        } 
        // Grupo A (Con Hardware, Con Cred, SIN IP)
        else if (['Laptop', 'Desktop', 'Celular'].includes(tipo)) {
            grupoHardware.classList.remove('hidden');
            grupoCredenciales.classList.remove('hidden');
            
            campoIp.classList.add('hidden');
            eqIp.value = ''; // Limpiar
        }
        // Grupo B (Servidor - Con Hardware, Sin Cred, CON IP)
        else if (tipo === 'Servidor') {
            grupoHardware.classList.remove('hidden');
            grupoCredenciales.classList.add('hidden'); // Se quitaron las credenciales para servidor
            
            campoIp.classList.remove('hidden');
        } else {
            // Por defecto muestra todo si selecciona '__otro__'
            grupoHardware.classList.remove('hidden');
            grupoCredenciales.classList.remove('hidden');
            campoIp.classList.remove('hidden');
        }
    }

    // Ejecutar al inicio para el valor por defecto
    document.addEventListener('DOMContentLoaded', () => {
        aplicarReglasCamposActivo(document.getElementById('eq_tipo').value);
    });

    let paginadorEquipos = null;

    function filtrarTablaEquipos() {
        const input = document.getElementById('buscar_equipo');
        if (!input) return;
        toggleClearBtn('buscar_equipo', 'btn_clear_equipo');
        const filter = input.value.toLowerCase().trim();

        if (paginadorEquipos) {
            paginadorEquipos.setFilter(tr => {
                const text = tr.innerText.toLowerCase();
                return !filter || text.includes(filter);
            });
        }
    }

    function initPaginacionEquipos() {
        if (typeof TablePaginator !== 'undefined') {
            paginadorEquipos = new TablePaginator({
                table: '#tablaEquipos',
                container: '#paginacionEquipos',
                itemLabel: 'equipos',
                pageSize: 15,
                counterEl: '#contadorEquiposTop'
            });
            window.paginadorEquipos = paginadorEquipos;
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initPaginacionEquipos);
    } else {
        initPaginacionEquipos();
    }

    // Mover todos los modales al final del body para evitar problemas de stacking context (barra superior sin desenfoque)
    function relocateModals() {
        const modales = ['modal-nuevo-equipo', 'modal-asignar', 'modal-devolver', 'modal-detalle-equipo', 'modal-historial-asignaciones', 'modal-baja-equipo', 'modal-cambiar-estado'];
        modales.forEach(function(id) {
            const el = document.getElementById(id);
            if (el) {
                document.body.appendChild(el);
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', relocateModals);
    } else {
        relocateModals();
    }
</script>

<?php
require_once __DIR__ . '/../../includes/footer.php';
?>

