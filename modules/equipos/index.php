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

// Cargar catálogos para filtros y formularios
$sucursales = $db->query("SELECT * FROM sucursales ORDER BY nombre")->fetchAll();
$departamentos = $db->query("SELECT DISTINCT departamento FROM empleados WHERE departamento IS NOT NULL ORDER BY departamento")->fetchAll();
$empleados = $db->query("SELECT id, nombre, cargo, departamento FROM empleados WHERE estado = 'Activo' ORDER BY nombre")->fetchAll();

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
    WHERE e.estado != 'De Baja'
";

$params = [];
if (!empty($filtro_sucursal)) {
    $sql .= " AND e.sucursal_id = ?";
    $params[] = (int)$filtro_sucursal;
}
if (!empty($filtro_depto)) {
    $sql .= " AND emp.departamento = ?";
    $params[] = $filtro_depto;
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
            <p class="text-slate-500 text-sm">Control de equipos de red, servidores, computadoras corporativas y asignaciones.</p>
        </div>
        <div>
            <button onclick="abrirModal('modal-nuevo-equipo')" class="px-4 py-2.5 rounded-xl grad-cyan-blue hover:opacity-90 text-slate-800 font-bold text-sm tracking-wide shadow-lg shadow-cyan-500/10 flex items-center gap-2">
                <i class="fa-solid fa-plus"></i> Registrar Equipo
            </button>
        </div>
    </div>

    <!-- Barra de Filtros -->
    <div class="bg-white rounded-2xl p-5 border border-slate-200">
        <form autocomplete="off" method="GET" class="flex flex-col lg:flex-row lg:items-end gap-4">
            <div class="flex-1">
                <label for="buscar_equipo" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Búsqueda Rápida</label>
                <div class="relative">
                    <input type="text" id="buscar_equipo" onkeyup="filtrarTablaEquipos()" placeholder="Buscar por tipo, marca, modelo, S/N, IP, colaborador..." class="w-full bg-slate-100 border border-slate-200 rounded-xl py-2.5 pl-10 pr-4 text-xs text-slate-800 focus:outline-none focus:border-cyan-400 placeholder-slate-400 transition-all duration-300">
                    <span class="absolute left-3.5 top-3 text-slate-450">
                        <i class="fa-solid fa-magnifying-glass text-xs"></i>
                    </span>
                </div>
            </div>
            <div class="flex-1">
                <label for="f_suc" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Filtrar por Sucursal</label>
                <select autocomplete="off" name="sucursal_id" id="f_suc" onchange="this.form.submit()" class="w-full bg-slate-100 border border-slate-200 rounded-xl py-2.5 px-4 text-xs text-slate-800 focus:outline-none focus:border-cyan-400 cursor-pointer">
                    <option value="">-- Todas las Sucursales --</option>
                    <?php foreach ($sucursales as $s): ?>
                        <option value="<?= $s['id'] ?>" <?= ($filtro_sucursal == $s['id']) ? 'selected' : '' ?>><?= htmlspecialchars($s['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flex-1">
                <label for="f_dep" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Filtrar por Departamento</label>
                <select autocomplete="off" name="departamento" id="f_dep" onchange="this.form.submit()" class="w-full bg-slate-100 border border-slate-200 rounded-xl py-2.5 px-4 text-xs text-slate-800 focus:outline-none focus:border-cyan-400 cursor-pointer">
                    <option value="">-- Todos los Departamentos --</option>
                    <?php foreach ($departamentos as $d): ?>
                        <option value="<?= htmlspecialchars($d['departamento']) ?>" <?= ($filtro_depto === $d['departamento']) ? 'selected' : '' ?>><?= htmlspecialchars($d['departamento']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flex gap-2 shrink-0">
                <button type="submit" class="px-5 py-2.5 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold text-xs border border-slate-200 flex items-center gap-1.5 transition">
                    <i class="fa-solid fa-filter"></i> Aplicar
                </button>
                <?php if (!empty($filtro_sucursal) || !empty($filtro_depto)): ?>
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
            <table class="w-full text-left modern-table text-sm">
                <thead>
                    <tr>
                        <th class="py-3 px-3 text-center w-12">#</th>
                        <th class="py-3 px-4">Código / Tipo</th>
                        <th class="py-3 px-4">Descripción del Activo</th>
                        <th class="py-3 px-4">Identificación</th>
                        <th class="py-3 px-4">IP / Ubicación</th>
                        <th class="py-3 px-4">Asignado a</th>
                        <th class="py-3 px-4">Estado</th>
                        <th class="py-3 px-4 text-center">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($equipos)): ?>
                        <tr>
                            <td colspan="8" class="text-center py-10 text-slate-400">
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
                                <td class="py-4 px-3 text-center text-xs font-mono font-bold text-slate-400 group-hover:text-cyan-600">
                                    <?= $num_inv++ ?>
                                </td>
                                <td class="py-4 px-4">
                                    <div class="font-bold text-slate-800 group-hover:text-cyan-700 transition flex items-center gap-1.5">
                                        <i class="fa-solid fa-laptop text-xs text-slate-400 group-hover:text-cyan-600"></i>
                                        <?= htmlspecialchars($eq['tipo']) ?>
                                    </div>
                                    <span class="text-[10px] text-slate-400 font-mono">ID: #<?= $eq['id'] ?></span>
                                </td>
                                <td class="py-4 px-4">
                                    <div class="font-semibold text-slate-700"><?= htmlspecialchars($eq['marca'] . ' ' . $eq['modelo']) ?></div>
                                    <div class="text-[11px] text-slate-400 max-w-[200px] truncate" title="<?= htmlspecialchars($eq['notas'] ?? '') ?>">
                                        <?= htmlspecialchars($eq['notas'] ?? 'Sin descripción') ?>
                                    </div>
                                </td>
                                <td class="py-4 px-4 font-mono text-xs text-slate-700 font-bold">
                                    <?= htmlspecialchars($eq['service_tag_sn']) ?>
                                </td>
                                <td class="py-4 px-4 text-xs text-slate-600">
                                    <?php 
                                    $show_dhcp = !in_array($eq['tipo'], ['Laptop', 'Desktop', 'Celular']);
                                    $ip_text = $eq['ip_direccion'] ?? ($show_dhcp ? 'DHCP / Dinámica' : '');
                                    if ($ip_text !== ''): 
                                    ?>
                                        <div><?= htmlspecialchars($ip_text) ?></div>
                                    <?php endif; ?>
                                    <span class="text-[10px] text-slate-400"><?= htmlspecialchars($eq['sucursal_nombre']) ?></span>
                                </td>
                                <td class="py-4 px-4 text-xs">
                                    <?php if (!empty($eq['empleado_nombre'])): ?>
                                        <div class="font-semibold text-slate-800"><?= htmlspecialchars($eq['empleado_nombre']) ?></div>
                                        <div class="text-[10px] text-slate-400"><?= htmlspecialchars($eq['empleado_depto']) ?></div>
                                    <?php else: ?>
                                        <?php if (in_array($eq['tipo'], ['Impresora', 'Switch', 'AP', 'Control de acceso', 'Servidor'])): ?>
                                            <span class="font-semibold text-slate-600"><i class="fa-solid fa-location-dot mr-1 text-slate-400"></i><?= htmlspecialchars($eq['sucursal_nombre']) ?></span>
                                        <?php else: ?>
                                            <span class="text-slate-400 italic">No asignado</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td class="py-4 px-4">
                                    <span class="px-2.5 py-1 rounded-full text-[10px] font-bold <?= $badge ?>">
                                        <?= $eq['estado'] ?>
                                    </span>
                                </td>
                                <td class="py-4 px-4">
                                    <div class="flex items-center justify-center gap-1" onclick="event.stopPropagation();">
                                        <?php $isInfra = in_array($eq['tipo'], ['Impresora', 'Switch', 'AP', 'Control de acceso', 'Servidor']); ?>
                                        
                                        <?php if (!$isInfra): ?>
                                            <!-- Ver Historial -->
                                            <a href="?history_id=<?= $eq['id'] ?><?= !empty($filtro_sucursal)?'&sucursal_id='.$filtro_sucursal:'' ?><?= !empty($filtro_depto)?'&departamento='.$filtro_depto:'' ?>" onclick="event.stopPropagation();" class="h-8 w-8 rounded-lg hover:bg-slate-100 flex items-center justify-center text-slate-500 hover:text-slate-800 transition" title="Ver Historial Asignaciones">
                                                <i class="fa-regular fa-clock text-xs"></i>
                                            </a>
                                            
                                            <?php if ($eq['estado'] === 'Disponible'): ?>
                                                <!-- Asignar Hardware -->
                                                <button onclick="event.stopPropagation(); abrirAsignar('<?= $eq['id'] ?>', '<?= htmlspecialchars($eq['marca'].' '.$eq['modelo']) ?>')" class="h-8 w-8 rounded-lg hover:bg-emerald-50 flex items-center justify-center text-slate-500 hover:text-emerald-600 transition" title="Asignar Equipo">
                                                    <i class="fa-solid fa-user-plus text-xs"></i>
                                                </button>
                                            <?php elseif ($eq['estado'] === 'Asignado'): ?>
                                                <!-- Imprimir Constancia -->
                                                <a href="imprimir_asignacion.php?id=<?= $eq['asignacion_id'] ?>" onclick="event.stopPropagation();" class="h-8 w-8 rounded-lg hover:bg-indigo-50 flex items-center justify-center text-slate-500 hover:text-indigo-600 transition" title="Imprimir Constancia">
                                                    <i class="fa-solid fa-print text-xs"></i>
                                                </a>
                                                <!-- Retornar / Devolver Hardware -->
                                                <button onclick="event.stopPropagation(); abrirDevolver('<?= $eq['id'] ?>', '<?= htmlspecialchars($eq['marca'].' '.$eq['modelo']) ?>', '<?= $eq['asignacion_id'] ?>')" class="h-8 w-8 rounded-lg hover:bg-amber-50 flex items-center justify-center text-slate-500 hover:text-amber-600 transition" title="Registrar Devolución">
                                                    <i class="fa-solid fa-arrow-rotate-left text-xs"></i>
                                                </button>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        
                                        <!-- Eliminar / Dar de Baja -->
                                        <a href="guardar.php?action=baja&id=<?= $eq['id'] ?>" onclick="event.stopPropagation(); return confirm('¿Seguro que deseas dar de baja este equipo?');" class="h-8 w-8 rounded-lg hover:bg-rose-50 flex items-center justify-center text-slate-400 hover:text-rose-500 transition" title="Dar de Baja">
                                            <i class="fa-regular fa-trash-can text-xs"></i>
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
                        <?php foreach ($sucursales as $s): ?>
                            <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['nombre']) ?></option>
                        <?php endforeach; ?>
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
                    <div>
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

    function verDetalleEquipo(eq) {
        document.getElementById('det_titulo').innerText = (eq.tipo || 'Equipo') + ' ' + (eq.marca || '') + ' ' + (eq.modelo || '');
        document.getElementById('det_subtitulo').innerText = 'S/N: ' + (eq.service_tag_sn || 'N/A');
        document.getElementById('det_id').innerText = '#' + eq.id;
        document.getElementById('det_tipo').innerText = eq.tipo || '--';
        document.getElementById('det_marca_modelo').innerText = (eq.marca || '') + ' ' + (eq.modelo || '');
        document.getElementById('det_sn').innerText = eq.service_tag_sn || 'N/A';
        const isClientDevice = ['Laptop', 'Desktop', 'Celular'].includes(eq.tipo);
        document.getElementById('det_ip').innerText = eq.ip_direccion || (isClientDevice ? 'No Aplica (DHCP)' : 'DHCP / Dinámica');
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
        if (eq.empleado_nombre) {
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

    function filtrarTablaEquipos() {
        const input = document.getElementById('buscar_equipo');
        const filter = input.value.toLowerCase().trim();
        const trs = document.querySelectorAll('tbody tr');

        trs.forEach(tr => {
            if (tr.querySelector('td[colspan]')) return; // Omitir fila vacía

            // Obtener todo el texto de la fila (incluyendo celdas)
            const text = tr.innerText.toLowerCase();
            if (text.includes(filter)) {
                tr.style.display = '';
            } else {
                tr.style.display = 'none';
            }
        });
    }

    // Mover todos los modales al final del body para evitar problemas de stacking context (barra superior sin desenfoque)
    function relocateModals() {
        const modales = ['modal-nuevo-equipo', 'modal-asignar', 'modal-devolver', 'modal-detalle-equipo', 'modal-historial-asignaciones'];
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

