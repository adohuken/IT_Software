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
        <form method="GET" class="flex flex-col md:flex-row md:items-end gap-4">
            <div class="flex-1">
                <label for="f_suc" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Filtrar por Sucursal</label>
                <select name="sucursal_id" id="f_suc" onchange="this.form.submit()" class="w-full bg-slate-100 border border-slate-200 rounded-xl py-2.5 px-4 text-xs text-slate-800 focus:outline-none focus:border-cyan-400 cursor-pointer">
                    <option value="">-- Todas las Sucursales --</option>
                    <?php foreach ($sucursales as $s): ?>
                        <option value="<?= $s['id'] ?>" <?= ($filtro_sucursal == $s['id']) ? 'selected' : '' ?>><?= htmlspecialchars($s['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flex-1">
                <label for="f_dep" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Filtrar por Departamento</label>
                <select name="departamento" id="f_dep" onchange="this.form.submit()" class="w-full bg-slate-100 border border-slate-200 rounded-xl py-2.5 px-4 text-xs text-slate-800 focus:outline-none focus:border-cyan-400 cursor-pointer">
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
        <div class="bg-white border border-cyan-500/20 rounded-2xl p-6 shadow-2xl space-y-4">
            <div class="flex items-center justify-between border-b border-slate-200 pb-3">
                <div>
                    <span class="text-[10px] text-cyan-400 uppercase font-bold tracking-wider">Historial de Asignaciones</span>
                    <h3 class="text-sm font-bold text-slate-800"><?= htmlspecialchars("{$equipo_detalle['tipo']} {$equipo_detalle['marca']} {$equipo_detalle['modelo']} (S/N: {$equipo_detalle['service_tag_sn']})") ?></h3>
                </div>
                <a href="index.php" class="text-xs text-slate-400 hover:text-slate-800 transition">Cerrar Historial</a>
            </div>
            
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
                                <p class="text-slate-500"><?= htmlspecialchars($h['cargo'] . ' &bull; ' . $h['departamento']) ?></p>
                                <p class="text-slate-400 mt-1 italic">Entrega: "<?= htmlspecialchars($h['condicion_entrega'] ?? 'Sin condiciones especificadas') ?>"</p>
                                
                                <?php if (!$activo): ?>
                                    <div class="mt-2 text-[11px] bg-slate-50 p-2 rounded border border-slate-200 text-slate-400">
                                        <div class="flex justify-between font-medium">
                                            <span>Devuelto el: <?= date('d/m/Y', strtotime($h['fecha_devolucion'])) ?></span>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <span class="mt-1.5 inline-block px-1.5 py-0.5 rounded text-[8px] font-bold bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 uppercase">Activo</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Tabla de Equipos -->
    <div class="glass-panel rounded-2xl overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left modern-table text-sm">
                <thead>
                    <tr>
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
                            <td colspan="7" class="text-center py-10 text-slate-400">
                                No se encontraron equipos en inventario bajo estos filtros.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($equipos as $eq): 
                            // Badges del hardware
                            if ($eq['estado'] === 'Disponible') $badge = 'badge-success';
                            elseif ($eq['estado'] === 'Asignado') $badge = 'badge-progress';
                            elseif ($eq['estado'] === 'Mantenimiento') $badge = 'badge-pending';
                            else $badge = 'badge-danger';
                        ?>
                            <tr class="hover:bg-cyan-50/60 cursor-pointer transition border-b border-slate-100 group" onclick='verDetalleEquipo(<?= json_encode($eq, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
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
                                    <div><?= htmlspecialchars($eq['ip_direccion'] ?? 'DHCP / Dinámica') ?></div>
                                    <span class="text-[10px] text-slate-400"><?= htmlspecialchars($eq['sucursal_nombre']) ?></span>
                                </td>
                                <td class="py-4 px-4 text-xs">
                                    <?php if (!empty($eq['empleado_nombre'])): ?>
                                        <div class="font-semibold text-slate-800"><?= htmlspecialchars($eq['empleado_nombre']) ?></div>
                                        <div class="text-[10px] text-slate-400"><?= htmlspecialchars($eq['empleado_depto']) ?></div>
                                    <?php else: ?>
                                        <span class="text-slate-400 italic">No asignado</span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-4 px-4">
                                    <span class="px-2.5 py-1 rounded-full text-[10px] font-bold <?= $badge ?>">
                                        <?= $eq['estado'] ?>
                                    </span>
                                </td>
                                <td class="py-4 px-4">
                                    <div class="flex items-center justify-center gap-1" onclick="event.stopPropagation();">
                                        <!-- Ver Detalle Completo -->
                                        <button onclick='event.stopPropagation(); verDetalleEquipo(<?= json_encode($eq, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' class="h-8 w-8 rounded-lg hover:bg-cyan-50 flex items-center justify-center text-cyan-600 transition" title="Ver Detalle Completo">
                                            <i class="fa-solid fa-eye text-xs"></i>
                                        </button>

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
                                            <!-- Retornar / Devolver Hardware -->
                                            <button onclick="event.stopPropagation(); abrirDevolver('<?= $eq['id'] ?>', '<?= htmlspecialchars($eq['marca'].' '.$eq['modelo']) ?>', '<?= $eq['asignacion_id'] ?>')" class="h-8 w-8 rounded-lg hover:bg-amber-50 flex items-center justify-center text-slate-500 hover:text-amber-600 transition" title="Registrar Devolución">
                                                <i class="fa-solid fa-arrow-rotate-left text-xs"></i>
                                            </button>
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
<div id="modal-nuevo-equipo" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-center justify-center hidden p-4">
    <div class="bg-white border border-slate-200 rounded-3xl w-full max-w-lg overflow-hidden shadow-2xl animate-fade-in">
        <div class="p-6 border-b border-slate-200 flex items-center justify-between">
            <h3 class="font-bold text-slate-800 flex items-center gap-2">
                <i class="fa-solid fa-plus text-cyan-400"></i> Registrar Nuevo Activo IT
            </h3>
            <button onclick="cerrarModal('modal-nuevo-equipo')" class="text-slate-400 hover:text-slate-800"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form action="guardar.php?action=add_eq" method="POST" class="p-6 space-y-4">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="eq_tipo" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Tipo de Activo</label>
                    <select name="tipo" id="eq_tipo" required class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400">
                        <option value="Laptop" selected>Laptop</option>
                        <option value="Desktop">Desktop</option>
                        <option value="Servidor">Servidor</option>
                        <option value="Maquina Virtual">Máquina Virtual</option>
                        <option value="Switch">Switch</option>
                        <option value="Firewall">Firewall</option>
                        <option value="Access Point">Access Point</option>
                    </select>
                </div>
                <div>
                    <label for="eq_suc" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Ubicación / Sucursal</label>
                    <select name="sucursal_id" id="eq_suc" required class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400">
                        <?php foreach ($sucursales as $s): ?>
                            <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="eq_marca" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Marca</label>
                    <input type="text" name="marca" id="eq_marca" required placeholder="Ej: Dell, Cisco, Fortinet" class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400">
                </div>
                <div>
                    <label for="eq_mod" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Modelo</label>
                    <input type="text" name="modelo" id="eq_mod" required placeholder="Ej: Latitude 5420" class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400">
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="eq_sn" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Service Tag / S/N</label>
                    <input type="text" name="service_tag_sn" id="eq_sn" required placeholder="Código Único" class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400">
                </div>
                <div>
                    <label for="eq_ip" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Dirección IP (Opcional)</label>
                    <input type="text" name="ip_direccion" id="eq_ip" placeholder="Ej: 192.168.10.X" class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400">
                </div>
            </div>
            <div>
                <label for="eq_est" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Estado Inicial</label>
                <select name="estado" id="eq_est" required class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400">
                    <option value="Disponible" selected>Disponible</option>
                    <option value="Mantenimiento">Mantenimiento</option>
                </select>
            </div>
            <div>
                <label for="eq_notes" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Notas descriptivas</label>
                <textarea name="notas" id="eq_notes" rows="2" placeholder="Detalles de hardware, licencias locales de OS, etc..." class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400"></textarea>
            </div>
            
            <!-- Opción para Asignar de inmediato durante la creación -->
            <div class="border border-slate-200 rounded-2xl p-4 bg-slate-50 space-y-4">
                <div class="flex items-center justify-between">
                    <label for="eq_asignar_inmediato" class="text-xs font-semibold text-slate-600 uppercase tracking-wider">¿Asignar de inmediato a un empleado?</label>
                    <input type="checkbox" name="asignar_inmediato" id="eq_asignar_inmediato" value="1" onchange="toggleAsignacionRegistro()" class="h-4 w-4 rounded border-gray-300 text-cyan-500 focus:ring-cyan-400 bg-slate-100">
                </div>
                <div id="div-asignacion-registro" class="hidden space-y-4">
                    <div>
                        <label for="eq_reg_empleado" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Empleado Asignado</label>
                        <select name="empleado_id" id="eq_reg_empleado" class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400">
                            <option value="" disabled selected>Selecciona un empleado...</option>
                            <?php foreach ($empleados as $emp): ?>
                                <option value="<?= $emp['id'] ?>"><?= htmlspecialchars($emp['nombre'] . ' (' . $emp['cargo'] . ')') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="eq_reg_condicion" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Condición de Entrega</label>
                        <input type="text" name="condicion_entrega" id="eq_reg_condicion" placeholder="Ej: Nuevo en caja, cargador, mouse." class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400">
                    </div>
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
<div id="modal-asignar" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-center justify-center hidden p-4">
    <div class="bg-white border border-slate-200 rounded-3xl w-full max-w-lg overflow-hidden shadow-2xl animate-fade-in">
        <div class="p-6 border-b border-slate-200 flex items-center justify-between">
            <h3 class="font-bold text-slate-800 flex items-center gap-2">
                <i class="fa-solid fa-user-plus text-cyan-400"></i> Asignación de Hardware
            </h3>
            <button onclick="cerrarModal('modal-asignar')" class="text-slate-400 hover:text-slate-800"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form action="guardar.php?action=assign" method="POST" class="p-6 space-y-4">
            <input type="hidden" name="equipo_id" id="asig_eq_id">
            <div>
                <label class="text-[10px] text-slate-400 uppercase font-bold tracking-wider">Equipo Seleccionado</label>
                <div id="asig_eq_label" class="text-sm font-semibold text-slate-800 bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 mt-1 font-mono"></div>
            </div>
            <div>
                <label for="asig_emp" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Empleado Asignado</label>
                <select name="empleado_id" id="asig_emp" required class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400">
                    <option value="" disabled selected>Selecciona el empleado que recibe el hardware...</option>
                    <?php foreach ($empleados as $emp): ?>
                        <option value="<?= $emp['id'] ?>"><?= htmlspecialchars($emp['nombre'] . ' (' . $emp['cargo'] . ' - ' . $emp['departamento'] . ')') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="asig_date" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Fecha de Entrega</label>
                <input type="date" name="fecha_entrega" id="asig_date" value="<?= date('Y-m-d') ?>" required class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400">
            </div>
            <div>
                <label for="asig_cond" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Condición de Entrega</label>
                <input type="text" name="condicion_entrega" id="asig_cond" required placeholder="Ej: Cargador, mouse, estuche. Sin golpes, pantalla limpia." class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400">
            </div>
            <div class="pt-4 flex gap-3">
                <button type="button" onclick="cerrarModal('modal-asignar')" class="flex-1 py-3 rounded-xl bg-white/5 hover:bg-white/10 text-slate-800 font-semibold text-sm transition border border-slate-200">Cancelar</button>
                <button type="submit" class="flex-1 py-3 rounded-xl bg-emerald-500 hover:bg-emerald-600 text-white font-bold text-sm tracking-wide shadow-lg shadow-emerald-500/20 transition">Confirmar Asignación</button>
            </div>
        </form>
    </div>
</div>

<!-- 3. Modal Devolver Equipo -->
<div id="modal-devolver" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-center justify-center hidden p-4">
    <div class="bg-white border border-slate-200 rounded-3xl w-full max-w-lg overflow-hidden shadow-2xl animate-fade-in">
        <div class="p-6 border-b border-slate-200 flex items-center justify-between">
            <h3 class="font-bold text-slate-800 flex items-center gap-2">
                <i class="fa-solid fa-arrow-rotate-left text-cyan-400"></i> Registrar Devolución de Activo
            </h3>
            <button onclick="cerrarModal('modal-devolver')" class="text-slate-400 hover:text-slate-800"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form action="guardar.php?action=return" method="POST" class="p-6 space-y-4">
            <input type="hidden" name="equipo_id" id="dev_eq_id">
            <input type="hidden" name="asignacion_id" id="dev_asig_id">
            <div>
                <label class="text-[10px] text-slate-400 uppercase font-bold tracking-wider">Equipo en Proceso de Devolución</label>
                <div id="dev_eq_label" class="text-sm font-semibold text-slate-800 bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 mt-1 font-mono"></div>
            </div>
            <div>
                <label for="dev_date" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Fecha de Devolución</label>
                <input type="date" name="fecha_devolucion" id="dev_date" value="<?= date('Y-m-d') ?>" required class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400">
            </div>
            <div>
                <label for="dev_cond" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Condición de Recepción (Historial hardware)</label>
                <input type="text" name="condicion_devolucion" id="dev_cond" required placeholder="Ej: Devuelto con rayón en carcasa, cargador original operativo." class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400">
            </div>
            <div class="pt-4 flex gap-3">
                <button type="button" onclick="cerrarModal('modal-devolver')" class="flex-1 py-3 rounded-xl bg-white/5 hover:bg-white/10 text-slate-800 font-semibold text-sm transition border border-slate-200">Cancelar</button>
                <button type="submit" class="flex-1 py-3 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-800 font-bold text-sm tracking-wide shadow-lg shadow-amber-500/20 transition">Registrar Retorno</button>
            </div>
        </form>
    </div>
</div>

<!-- 4. Modal Detalle Completo del Activo IT -->
<div id="modal-detalle-equipo" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[100] flex items-center justify-center hidden p-4">
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
            <div>
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
            <div>
                <h4 class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-2 flex items-center gap-2">
                    <i class="fa-solid fa-file-lines text-slate-400"></i> Observaciones del Activo
                </h4>
                <div class="bg-slate-50 rounded-xl p-3 border border-slate-200 text-xs text-slate-700 italic" id="det_notas">
                    Sin observaciones guardadas.
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
        document.getElementById(id).classList.remove('hidden');
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
        document.getElementById('det_ip').innerText = eq.ip_direccion || 'DHCP / Dinámica';
        document.getElementById('det_sucursal').innerText = eq.sucursal_nombre || '--';
        document.getElementById('det_notas').innerText = eq.notas || 'Sin observaciones guardadas.';

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

        // Enlace al historial
        document.getElementById('det_link_historial').href = '?history_id=' + eq.id;

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

    function toggleAsignacionRegistro() {
        const checkAsig = document.getElementById('eq_asignar_inmediato');
        const divAsig = document.getElementById('div-asignacion-registro');
        const estadoSel = document.getElementById('eq_est');
        const empSel = document.getElementById('eq_reg_empleado');
        const condInput = document.getElementById('eq_reg_condicion');

        if (checkAsig.checked) {
            divAsig.classList.remove('hidden');
            estadoSel.value = 'Disponible';
            estadoSel.disabled = true;
            empSel.required = true;
            condInput.required = true;
        } else {
            divAsig.classList.add('hidden');
            estadoSel.disabled = false;
            empSel.required = false;
            condInput.required = false;
            empSel.value = '';
            condInput.value = '';
        }
    }
</script>

<?php
require_once __DIR__ . '/../../includes/footer.php';
?>

