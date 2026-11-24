<?php
/**
 * Módulo de Empleados y Alta Consolidada (Onboarding IT)
 * Muestra personal, equipos y cuentas asociadas. Formulario de onboarding.
 */

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../config/security.php';

$db = Database::getConexion();

// 3. Si se solicita la Ficha de Credenciales de un Colaborador
$details_id = $_GET['details_id'] ?? 0;
$ficha_empleado = null;
$ficha_equipo = null;
$ficha_m365 = null;

if ($details_id > 0) {
    // A. Obtener datos del Colaborador
    $stmt = $db->prepare("
        SELECT emp.*, s.nombre AS sucursal_nombre 
        FROM empleados emp 
        JOIN sucursales s ON emp.sucursal_id = s.id 
        WHERE emp.id = ? AND emp.estado = 'Activo'
    ");
    $stmt->execute([$details_id]);
    $ficha_empleado = $stmt->fetch();

    if ($ficha_empleado) {
        // B. Buscar equipos asignados activos y sus credenciales
        $stmt_eq = $db->prepare("
            SELECT eq.id, eq.tipo, eq.marca, eq.modelo, eq.service_tag_sn, eq.ip_direccion,
                   ce.usuario_local, ce.password_cifrado AS eq_password, ce.clave_bitlocker, ce.notas_acceso
            FROM asignaciones a
            JOIN equipos eq ON a.equipo_id = eq.id
            LEFT JOIN credenciales_equipos ce ON ce.equipo_id = eq.id
            WHERE a.empleado_id = ? AND a.fecha_devolucion IS NULL
        ");
        $stmt_eq->execute([$details_id]);
        $ficha_equipos = $stmt_eq->fetchAll();

        // C. Buscar cuenta M365 activa y su contraseña
        $stmt_m365 = $db->prepare("
            SELECT id, correo, licencia_tipo, password_cifrado, fecha_inicializacion, ciclo_facturacion, fecha_proximo_pago
            FROM cuentas_m365
            WHERE empleado_id = ? AND estado = 'Activa'
            LIMIT 1
        ");
        $stmt_m365->execute([$details_id]);
        $ficha_m365 = $stmt_m365->fetch();

        // Descifrar contraseña de M365 si tiene
        if ($ficha_m365 && !empty($ficha_m365['password_cifrado'])) {
            try {
                $ficha_m365['password_clear'] = Security::decrypt($ficha_m365['password_cifrado']);
            } catch (Exception $e) {
                $ficha_m365['password_clear'] = '[Error al descifrar]';
            }
        }

        // D. Buscar credenciales personales
        $stmt_cred_pers = $db->prepare("
            SELECT id, tipo, usuario, password_cifrado 
            FROM credenciales_personales 
            WHERE empleado_id = ?
            ORDER BY tipo ASC
        ");
        $stmt_cred_pers->execute([$details_id]);
        $ficha_cred_pers = $stmt_cred_pers->fetchAll();

        foreach ($ficha_cred_pers as &$cred) {
            if (!empty($cred['password_cifrado'])) {
                try {
                    $cred['password_clear'] = Security::decrypt($cred['password_cifrado']);
                } catch (Exception $e) {
                    $cred['password_clear'] = '[Error]';
                }
            } else {
                $cred['password_clear'] = '';
            }
        }
        $ficha_pc_pin = '';
        foreach ($ficha_cred_pers as $fcp) {
            if ($fcp['tipo'] === 'PC PIN') {
                $ficha_pc_pin = $fcp['password_clear'];
                break;
            }
        }
    }
}

$sql_empleados = "
    SELECT emp.*, s.nombre AS sucursal_nombre, e_corp.nombre AS empresa_nombre,
           (SELECT COUNT(*) FROM asignaciones WHERE empleado_id = emp.id AND fecha_devolucion IS NULL) AS cant_equipos,
           (SELECT GROUP_CONCAT(CONCAT(eq.id, ':', eq.tipo, ' - ', eq.marca, ' ', eq.modelo, ' (S/N: ', eq.service_tag_sn, ')') SEPARATOR '|') 
            FROM asignaciones a JOIN equipos eq ON a.equipo_id = eq.id 
            WHERE a.empleado_id = emp.id AND a.fecha_devolucion IS NULL) AS equipos_activos,
           (SELECT GROUP_CONCAT(CONCAT(eq.tipo, ' - ', eq.marca, ' ', eq.modelo, ' (S/N: ', eq.service_tag_sn, ')') SEPARATOR ', ')
            FROM asignaciones a 
            JOIN equipos eq ON a.equipo_id = eq.id 
            WHERE a.empleado_id = emp.id AND a.fecha_devolucion IS NULL) AS equipo_vinculado,
           (SELECT id FROM cuentas_m365 WHERE empleado_id = emp.id AND estado = 'Activa' LIMIT 1) AS m365_id,
           (SELECT correo FROM cuentas_m365 WHERE empleado_id = emp.id AND estado = 'Activa' LIMIT 1) AS correo_m365,
           (SELECT licencia_tipo FROM cuentas_m365 WHERE empleado_id = emp.id AND estado = 'Activa' LIMIT 1) AS licencia_m365,
           (SELECT password_cifrado FROM cuentas_m365 WHERE empleado_id = emp.id AND estado = 'Activa' LIMIT 1) AS m365_pass_cifrado,
           (SELECT fecha_inicializacion FROM cuentas_m365 WHERE empleado_id = emp.id AND estado = 'Activa' LIMIT 1) AS m365_fecha_init,
           (SELECT ciclo_facturacion FROM cuentas_m365 WHERE empleado_id = emp.id AND estado = 'Activa' LIMIT 1) AS m365_ciclo,
           (SELECT fecha_proximo_pago FROM cuentas_m365 WHERE empleado_id = emp.id AND estado = 'Activa' LIMIT 1) AS m365_proximo_pago,
           (SELECT estado FROM cuentas_m365 WHERE empleado_id = emp.id AND estado = 'Activa' LIMIT 1) AS m365_estado,
           (SELECT password_cifrado FROM credenciales_personales WHERE empleado_id = emp.id AND tipo = 'PC PIN' LIMIT 1) AS pc_pin_cifrado,
           (SELECT password_cifrado FROM credenciales_personales WHERE empleado_id = emp.id AND tipo = 'Pass AG' LIMIT 1) AS pass_ag_cifrado,
           (SELECT usuario FROM credenciales_personales WHERE empleado_id = emp.id AND tipo = 'Gmail' LIMIT 1) AS correo_gmail,
           (SELECT password_cifrado FROM credenciales_personales WHERE empleado_id = emp.id AND tipo = 'Gmail' LIMIT 1) AS password_gmail_cifrado
    FROM empleados emp
    JOIN sucursales s ON emp.sucursal_id = s.id
    JOIN empresas e_corp ON s.empresa_id = e_corp.id
    WHERE emp.estado = 'Activo'
    ORDER BY emp.nombre ASC
";
$empleados = $db->query($sql_empleados)->fetchAll();

// Cargar todos los equipos asignados con sus especificaciones de hardware
$equipos_asignados_db = $db->query("
    SELECT a.empleado_id, eq.id, eq.tipo, eq.marca, eq.modelo, eq.service_tag_sn, eq.procesador, eq.ram, eq.disco, eq.ip_direccion,
           ce.usuario_local, ce.password_cifrado AS eq_pass_cifrado, ce.clave_bitlocker, ce.notas_acceso
    FROM asignaciones a
    JOIN equipos eq ON a.equipo_id = eq.id
    LEFT JOIN credenciales_equipos ce ON ce.equipo_id = eq.id
    WHERE a.fecha_devolucion IS NULL
")->fetchAll();

$equipos_por_empleado = [];
foreach ($equipos_asignados_db as $eqItem) {
    $eqItem['eq_pass_plain'] = !empty($eqItem['eq_pass_cifrado']) ? Security::decrypt($eqItem['eq_pass_cifrado']) : '';
    $eqItem['eq_bitlocker_plain'] = !empty($eqItem['clave_bitlocker']) ? Security::decrypt($eqItem['clave_bitlocker']) : '';
    $equipos_por_empleado[$eqItem['empleado_id']][] = $eqItem;
}

foreach ($empleados as &$eRow) {
    $eRow['pc_pin'] = !empty($eRow['pc_pin_cifrado']) ? Security::decrypt($eRow['pc_pin_cifrado']) : '';
    $eRow['pass_gmail_plain'] = !empty($eRow['password_gmail_cifrado']) ? Security::decrypt($eRow['password_gmail_cifrado']) : '';
    $eRow['pass_ag_plain'] = !empty($eRow['pass_ag_cifrado']) ? Security::decrypt($eRow['pass_ag_cifrado']) : '';
    $eRow['m365_pass_plain'] = !empty($eRow['m365_pass_cifrado']) ? Security::decrypt($eRow['m365_pass_cifrado']) : '';
    $eRow['equipos_lista'] = $equipos_por_empleado[$eRow['id']] ?? [];
}
unset($eRow);

// 2. Cargar catálogos agrupados por Empresa para los filtros
$empresas = $db->query("SELECT * FROM empresas ORDER BY nombre")->fetchAll();
$sucursales_raw = $db->query("
    SELECT s.*, e.nombre AS empresa_nombre 
    FROM sucursales s 
    JOIN empresas e ON s.empresa_id = e.id 
    ORDER BY e.nombre, s.nombre
")->fetchAll();
$sucursales = $sucursales_raw;
$equipos_disponibles = $db->query("SELECT id, tipo, marca, modelo, service_tag_sn FROM equipos WHERE estado = 'Disponible' ORDER BY tipo, marca")->fetchAll();
$m365_disponibles = $db->query("SELECT id, correo, licencia_tipo FROM cuentas_m365 WHERE estado = 'Activa' AND (empleado_id IS NULL OR empleado_id = 0) ORDER BY correo")->fetchAll();
$list_deptos = $db->query("SELECT DISTINCT departamento FROM empleados WHERE departamento IS NOT NULL AND departamento != '' ORDER BY departamento")->fetchAll(PDO::FETCH_COLUMN);
?>

<div class="space-y-6">
    <!-- Encabezado de Página -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h2 class="text-xl font-bold text-slate-900 tracking-tight">Colaboradores & Onboarding IT</h2>
            <p class="text-slate-500 text-xs">Directorio de personal, vinculación de hardware y cuentas corporativas.</p>
        </div>
        <div>
            <button onclick="abrirModal('modal-onboarding')" class="px-4 py-2 rounded-xl bg-blue-600 hover:bg-blue-700 text-white font-semibold text-xs shadow-xs flex items-center gap-2 transition duration-150">
                <i class="fa-solid fa-user-plus text-xs"></i> Iniciar Onboarding IT
            </button>
        </div>
    </div>

    <!-- PANEL DE FILTROS ROBUSTO DE NIVELES ORGANIZACIONALES -->
    <div class="bg-white p-5 rounded-2xl shadow-xs border border-slate-200/80 space-y-4">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100">
            <h3 class="text-xs font-bold uppercase tracking-wider text-slate-700 flex items-center gap-2">
                <i class="fa-solid fa-filter text-blue-600 text-xs"></i> Filtros por Empresa, Sucursal y Área
            </h3>
            <button onclick="limpiarFiltros()" class="text-xs text-rose-500 hover:text-rose-700 font-semibold flex items-center gap-1 transition">
                <i class="fa-solid fa-arrow-rotate-left"></i> Limpiar Filtros
            </button>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
            <!-- 1. Buscador General por Texto -->
            <div class="lg:col-span-1 relative">
                <label class="block text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1">Buscador General</label>
                <div class="relative flex items-center">
                    <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs pointer-events-none"></i>
                    <input type="text" id="filterText" 
                           oninput="toggleClearBtn('filterText', 'btn_clear_empleado'); aplicarFiltros();" 
                           onkeydown="if(event.key === 'Escape'){ limpiarBuscador('filterText', 'btn_clear_empleado', aplicarFiltros); }" 
                           placeholder="Nombre, serial..." 
                           class="w-full pl-8 pr-9 py-2 rounded-xl bg-slate-50 border border-slate-200 text-xs text-slate-800 font-medium focus:outline-none focus:border-blue-500 focus:bg-white transition">
                    <button type="button" id="btn_clear_empleado" 
                            onclick="limpiarBuscador('filterText', 'btn_clear_empleado', aplicarFiltros)" 
                            class="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-700 hover:bg-slate-200/60 p-1 rounded-full transition-all duration-200 hidden items-center justify-center cursor-pointer" 
                            title="Limpiar búsqueda">
                        <i class="fa-solid fa-circle-xmark text-sm"></i>
                    </button>
                </div>
            </div>

            <!-- 2. Filtro por Empresa -->
            <div>
                <label class="block text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1">Empresa</label>
                <select id="filterEmpresa" onchange="aplicarFiltros()" class="w-full px-3 py-2 rounded-xl bg-slate-50 border border-slate-200 text-xs font-medium text-slate-800 focus:outline-none focus:border-blue-500 transition">
                    <option value="">-- Todas las Empresas --</option>
                    <?php foreach ($empresas as $emp_c): ?>
                        <option value="<?= htmlspecialchars($emp_c['nombre']) ?>"><?= htmlspecialchars($emp_c['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- 3. Filtro por Sucursal (Agrupado en Optgroup sin duplicados) -->
            <div>
                <label class="block text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1">Sucursal / Sede</label>
                <select id="filterSucursal" onchange="aplicarFiltros()" class="w-full px-3 py-2 rounded-xl bg-slate-50 border border-slate-200 text-xs font-medium text-slate-800 focus:outline-none focus:border-blue-500 transition">
                    <option value="">-- Todas las Sucursales --</option>
                    <?php 
                    $curr_emp = '';
                    foreach ($sucursales_raw as $suc):
                        if ($suc['empresa_nombre'] !== $curr_emp):
                            if ($curr_emp !== '') echo '</optgroup>';
                            $curr_emp = $suc['empresa_nombre'];
                            echo '<optgroup label="' . htmlspecialchars($curr_emp) . '">';
                        endif;
                    ?>
                        <option value="<?= htmlspecialchars($suc['nombre']) ?>" data-empresa="<?= htmlspecialchars($suc['empresa_nombre']) ?>">
                            <?= htmlspecialchars($suc['nombre']) ?>
                        </option>
                    <?php endforeach; 
                    if ($curr_emp !== '') echo '</optgroup>';
                    ?>
                </select>
            </div>

            <!-- 4. Filtro por Departamento -->
            <div>
                <label class="block text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1">Área / Departamento</label>
                <select id="filterDepto" onchange="aplicarFiltros()" class="w-full px-3 py-2 rounded-xl bg-slate-50 border border-slate-200 text-xs font-medium text-slate-800 focus:outline-none focus:border-blue-500 transition">
                    <option value="">-- Todos los Departamentos --</option>
                    <?php foreach ($list_deptos as $dep): ?>
                        <option value="<?= htmlspecialchars($dep) ?>"><?= htmlspecialchars($dep) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- 5. Filtro por Estado de Recursos -->
            <div>
                <label class="block text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1">Recursos Vinculados</label>
                <select id="filterRecursos" onchange="aplicarFiltros()" class="w-full px-3 py-2 rounded-xl bg-slate-50 border border-slate-200 text-xs font-medium text-slate-800 focus:outline-none focus:border-blue-500 transition">
                    <option value="">-- Todos los Recursos --</option>
                    <option value="con_equipo">Con Equipo Asignado</option>
                    <option value="sin_equipo">Sin Equipo Asignado</option>
                    <option value="con_m365">Con Cuenta M365</option>
                    <option value="sin_m365">Sin Cuenta M365</option>
                </select>
            </div>
        </div>

        <!-- Indicador Contador -->
        <div class="flex items-center justify-between text-xs text-slate-500 pt-2 border-t border-slate-100">
            <span>Mostrando <strong id="countVisibles" class="text-blue-600 font-bold"><?= count($empleados) ?></strong> de <strong class="text-slate-800"><?= count($empleados) ?></strong> colaboradores</span>
        </div>
    </div>

    <!-- Tabla General de Personal y Recursos -->
    <div class="bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left modern-table text-xs" id="tablaEmpleados">
                <thead>
                    <tr>
                        <th class="py-3 px-4 font-semibold text-slate-600">Colaborador</th>
                        <th class="py-3 px-4 font-semibold text-slate-600">Cargo / Área</th>
                        <th class="py-3 px-4 font-semibold text-slate-600">Sucursal</th>
                        <th class="py-3 px-4 font-semibold text-slate-600">Equipo Vinculado</th>
                        <th class="py-3 px-4 font-semibold text-slate-600">Cuenta M365 Activa</th>
                        <th class="py-3 px-4 text-center font-semibold text-slate-600">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($empleados)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-10 text-slate-400">
                                No se encontraron colaboradores registrados en el sistema.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($empleados as $emp): ?>
                            <tr class="hover:bg-blue-50/30 cursor-pointer transition border-b border-slate-100 group" 
                                data-search="<?= htmlspecialchars(strtolower($emp['nombre'] . ' ' . ($emp['cargo'] ?? '') . ' ' . ($emp['departamento'] ?? '') . ' ' . $emp['empresa_nombre'] . ' ' . $emp['sucursal_nombre'] . ' ' . ($emp['equipo_vinculado'] ?? '') . ' ' . ($emp['correo_m365'] ?? ''))) ?>"
                                data-empresa="<?= htmlspecialchars(strtolower($emp['empresa_nombre'])) ?>"
                                data-sucursal="<?= htmlspecialchars(strtolower($emp['sucursal_nombre'])) ?>"
                                data-depto="<?= htmlspecialchars(strtolower($emp['departamento'] ?? '')) ?>"
                                data-equipos="<?= (int)$emp['cant_equipos'] ?>"
                                data-m365="<?= !empty($emp['correo_m365']) ? 'true' : 'false' ?>"
                                onclick='verFicha360(<?= json_encode($emp, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                <td class="py-3.5 px-4 font-semibold text-slate-800 group-hover:text-blue-600 transition flex items-center gap-2.5">
                                    <div class="h-8 w-8 rounded-lg bg-blue-50 border border-blue-200 text-blue-600 flex items-center justify-center text-xs font-bold shrink-0">
                                        <?= strtoupper(substr($emp['nombre'], 0, 1)) ?>
                                    </div>
                                    <span class="truncate"><?= htmlspecialchars($emp['nombre']) ?></span>
                                </td>
                                <td class="py-3.5 px-4 text-xs text-slate-600">
                                    <div class="font-semibold text-slate-800"><?= htmlspecialchars($emp['cargo']) ?></div>
                                    <span class="text-[11px] text-slate-400"><?= htmlspecialchars($emp['departamento']) ?></span>
                                </td>
                                <td class="py-3.5 px-4 text-xs font-medium text-slate-600">
                                    <?= htmlspecialchars($emp['sucursal_nombre']) ?>
                                </td>
                                <td class="py-3.5 px-4 text-xs">
                                     <?php if ($emp['cant_equipos'] > 0): ?>
                                         <div class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md bg-blue-50 border border-blue-200/80 text-blue-700 font-semibold text-[11px]" title="<?= htmlspecialchars($emp['equipo_vinculado']) ?>">
                                             <i class="fa-solid fa-laptop text-blue-600 text-[10px] shrink-0"></i>
                                             <span><?= $emp['cant_equipos'] ?> <?= $emp['cant_equipos'] == 1 ? 'Equipo' : 'Equipos' ?></span>
                                         </div>
                                     <?php else: ?>
                                         <a href="../equipos/index.php" onclick="event.stopPropagation();" class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md bg-slate-50 border border-slate-200 text-slate-400 hover:text-slate-600 text-[11px]">
                                             <i class="fa-solid fa-link-slash text-[10px] shrink-0"></i>
                                             <span>Sin equipos</span>
                                         </a>
                                     <?php endif; ?>
                                </td>
                                <td class="py-3.5 px-4 text-xs">
                                    <?php if (!empty($emp['correo_m365'])): ?>
                                        <div class="text-slate-700">
                                            <div class="font-semibold text-slate-800 flex items-center gap-1.5 truncate">
                                                <i class="fa-solid fa-cloud text-sky-600 text-xs"></i>
                                                <span class="truncate"><?= htmlspecialchars($emp['correo_m365']) ?></span>
                                            </div>
                                            <span class="text-[10px] text-slate-400"><?= htmlspecialchars($emp['licencia_m365']) ?></span>
                                        </div>
                                    <?php else: ?>
                                        <a href="../credenciales/index.php?tab=m365" onclick="event.stopPropagation();" class="text-slate-400 hover:text-blue-600 flex items-center gap-1 text-[11px]">
                                            <i class="fa-solid fa-link-slash text-[10px]"></i> Sin M365
                                        </a>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3.5 px-4 text-center">
                                    <div class="flex items-center justify-center gap-1" onclick="event.stopPropagation();">
                                        <!-- Ver Ficha 360° Integral -->
                                        <button type="button" onclick='event.stopPropagation(); verFicha360(<?= json_encode($emp, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' class="h-8 w-8 rounded-lg hover:bg-blue-50 flex items-center justify-center text-slate-400 hover:text-blue-600 transition" title="Ficha 360° Integral">
                                            <i class="fa-solid fa-id-card text-xs"></i>
                                        </button>
                                        <!-- Editar Empleado -->
                                        <button onclick='event.stopPropagation(); abrirEditarEmpleado(<?= json_encode($emp, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' class="h-8 w-8 rounded-lg hover:bg-slate-100 flex items-center justify-center text-slate-400 hover:text-slate-700 transition" title="Editar Empleado">
                                            <i class="fa-solid fa-pen text-xs"></i>
                                        </button>
                                        <!-- Dar de Baja -->
                                        <button onclick="event.stopPropagation(); confirmarBajaEmpleado('guardar.php?action=baja&id=<?= $emp['id'] ?>')" class="h-8 w-8 rounded-lg hover:bg-rose-50 flex items-center justify-center text-slate-400 hover:text-rose-600 transition" title="Dar de Baja Colaborador">
                                            <i class="fa-solid fa-user-minus text-xs"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <!-- Paginación de Colaboradores -->
        <div id="paginacionEmpleados"></div>
    </div>
</div>

<!-- ========================================================
     MODAL DE ALTA CONSOLIDADA (ONBOARDING IT)
     ======================================================== -->
<div id="modal-onboarding" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-center justify-center hidden p-4 overflow-y-auto">
    <div class="bg-white border border-slate-200 rounded-3xl w-full max-w-2xl overflow-hidden shadow-2xl animate-fade-in my-8">
        
        <div class="p-6 border-b border-slate-200 flex items-center justify-between">
            <h3 class="font-bold text-slate-800 flex items-center gap-2">
                <i class="fa-solid fa-user-check text-emerald-400 animate-pulse"></i> Registrar Onboarding de Colaborador
            </h3>
            <button onclick="cerrarModal('modal-onboarding')" class="text-slate-400 hover:text-slate-800"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <form autocomplete="off" action="guardar.php?action=onboard" method="POST" class="p-6 space-y-6">
            
            <!-- SECCIÓN 1: DATOS GENERALES DEL EMPLEADO -->
            <div class="space-y-4">
                <h4 class="text-xs font-bold text-slate-500 uppercase tracking-widest border-b border-slate-200 pb-2">1. Datos Personales del Colaborador</h4>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label for="emp_nombre" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Nombre Completo</label>
                        <input type="text" name="nombre" id="emp_nombre" required placeholder="Juan Pérez Alvarado" 
                               class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-emerald-400 focus:ring-1 focus:ring-emerald-400">
                    </div>
                    <div>
                        <label for="emp_suc" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Sucursal</label>
                        <select autocomplete="off" name="sucursal_id" id="emp_suc" required 
                                class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-emerald-400 focus:ring-1 focus:ring-emerald-400">
                            <option value="" disabled selected>Selecciona sucursal...</option>
                            <?php 
                            $cur_emp = "";
                            foreach ($sucursales as $s): 
                                if ($s["empresa_nombre"] !== $cur_emp):
                                    if ($cur_emp !== "") echo "</optgroup>";
                                    $cur_emp = $s["empresa_nombre"];
                            ?>
                                <optgroup label="<?= htmlspecialchars($cur_emp) ?>">
                            <?php endif; ?>
                                <option value="<?= $s["id"] ?>"><?= htmlspecialchars($s["nombre"]) ?></option>
                            <?php endforeach; ?>
                            <?php if ($cur_emp !== "") echo "</optgroup>"; ?>
                            <option value="NEW_SUC" class="font-bold text-blue-600 bg-blue-50">+ Agregar nueva sucursal...</option>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label for="emp_cargo" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Cargo / Puesto</label>
                        <input type="text" name="cargo" id="emp_cargo" required placeholder="Analista de Crédito" 
                               class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-emerald-400 focus:ring-1 focus:ring-emerald-400">
                    </div>
                    <div>
                        <label for="emp_depto" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Departamento</label>
                        <select autocomplete="off" name="departamento" id="emp_depto" required
                               class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-emerald-400 focus:ring-1 focus:ring-emerald-400">
                            <option value="" disabled selected>Selecciona departamento...</option>
                            <option value="Gerencia">Gerencia</option>
                            <option value="Administración">Administración</option>
                            <option value="IT">IT</option>
                            <option value="Ventas">Ventas</option>
                            <option value="Logística">Logística</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- SECCIÓN 2: ASIGNACIÓN DE HARDWARE (OPCIONAL) -->
            <div class="border border-slate-200 rounded-2xl p-4 bg-slate-50 space-y-4">
                <div class="flex items-center justify-between">
                    <h4 class="text-xs font-bold text-slate-600 uppercase tracking-wider flex items-center gap-1.5">
                        <i class="fa-solid fa-laptop text-cyan-400"></i> 2. Vincular Equipo de Inventario
                    </h4>
                    <input type="checkbox" name="vincular_equipo" id="chk_vin_eq" value="1" onchange="toggleOnboardingEq()" 
                           class="h-4 w-4 rounded border-gray-300 text-emerald-500 focus:ring-emerald-400 bg-slate-100">
                </div>
                
                <div id="div-onboarding-eq" class="hidden space-y-4 pt-2 border-t border-slate-200">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="onb_eq" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Equipo Disponible</label>
                            <select autocomplete="off" name="equipo_id" id="onb_eq" 
                                    class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400">
                                <option value="" disabled selected>Selecciona un equipo libre...</option>
                                <?php foreach ($equipos_disponibles as $eq): ?>
                                    <option value="<?= $eq['id'] ?>"><?= htmlspecialchars("[{$eq['tipo']}] {$eq['marca']} {$eq['modelo']} ({$eq['service_tag_sn']})") ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="onb_eq_cond" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Condición de Entrega</label>
                            <input type="text" name="condicion_entrega" id="onb_eq_cond" placeholder="Ej: Cargador original, estuche, mouse. Sin rayones." 
                                   class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400">
                        </div>
                    </div>
                </div>
            </div>

            <!-- SECCIÓN 3: CUENTA MICROSOFT 365 (OPCIONAL) -->
            <div class="border border-slate-200 rounded-2xl p-4 bg-slate-50 space-y-4">
                <div class="flex items-center justify-between">
                    <h4 class="text-xs font-bold text-slate-600 uppercase tracking-wider flex items-center gap-1.5">
                        <i class="fa-solid fa-cloud text-emerald-400"></i> 3. Cuenta Microsoft 365
                    </h4>
                    <input type="checkbox" name="vincular_m365" id="chk_vin_m365" value="1" onchange="toggleOnboardingM365()" 
                           class="h-4 w-4 rounded border-gray-300 text-emerald-500 focus:ring-emerald-400 bg-slate-100">
                </div>
                
                <div id="div-onboarding-m365" class="hidden space-y-4 pt-2 border-t border-slate-200">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label for="onb_m_correo" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Correo Corporativo</label>
                            <input type="email" name="m365_correo" id="onb_m_correo" placeholder="correo@empresa.com.ni" 
                                   class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-emerald-400">
                        </div>
                        <div>
                            <label for="onb_m_lic" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Tipo de Licencia</label>
                            <select autocomplete="off" name="m365_licencia" id="onb_m_lic" 
                                    class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-emerald-400">
                                <option value="Microsoft 365 Business Basic">M365 Business Basic</option>
                                <option value="Microsoft 365 Business Standard" selected>M365 Business Standard</option>
                                <option value="Microsoft 365 Business Premium">M365 Business Premium</option>
                                <option value="Exchange Online Plan 1">Exchange Online Plan 1</option>
                            </select>
                        </div>
                        <div>
                            <label for="onb_m_pass" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Contraseña de Correo</label>
                            <input type="password" name="m365_password" id="onb_m_pass" placeholder="Contraseña de la cuenta" 
                                   class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-emerald-400">
                        </div>
                    </div>
                    <!-- Fecha de inicialización automática (hoy) -->
                    <input type="hidden" name="m365_fecha_init" value="<?= date('Y-m-d') ?>">
                    <input type="hidden" name="m365_ciclo" value="Mensual">
                </div>
            </div>

            <!-- SECCIÓN 4: CREDENCIALES PERSONALES (GMAIL, CORREO, PIN, AG) -->
            <div class="border border-slate-200 rounded-2xl p-4 bg-slate-50 space-y-4">
                <div class="flex items-center justify-between">
                    <h4 class="text-xs font-bold text-slate-600 uppercase tracking-wider flex items-center gap-1.5">
                        <i class="fa-solid fa-key text-amber-400"></i> 4. Credenciales Personales del Colaborador
                    </h4>
                    <input type="checkbox" name="vincular_creds" id="chk_vin_creds" value="1" onchange="toggleOnboardingCreds()"
                           class="h-4 w-4 rounded border-gray-300 text-emerald-500 focus:ring-emerald-400 bg-slate-100">
                </div>

                <div id="div-onboarding-creds" class="hidden space-y-4 pt-2 border-t border-slate-200">

                    <!-- Gmail -->
                    <div>
                        <p class="text-[11px] font-bold text-red-500 uppercase tracking-wider flex items-center gap-1 mb-2">
                            <i class="fa-brands fa-google"></i> Gmail
                        </p>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label for="onb_gmail" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Correo Gmail</label>
                                <input type="email" name="correo_gmail" id="onb_gmail" placeholder="colaborador@gmail.com"
                                       class="w-full bg-slate-100 border border-slate-200 rounded-xl py-2.5 px-4 text-sm text-slate-800 focus:outline-none focus:border-red-400">
                            </div>
                            <div>
                                <label for="onb_pass_gmail" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Contraseña Gmail</label>
                                <input type="password" name="pass_gmail" id="onb_pass_gmail" placeholder="Contraseña de Gmail"
                                       class="w-full bg-slate-100 border border-slate-200 rounded-xl py-2.5 px-4 text-sm text-slate-800 focus:outline-none focus:border-red-400">
                            </div>
                        </div>
                    </div>



                    <!-- PC / AG -->
                    <div>
                        <p class="text-[11px] font-bold text-slate-500 uppercase tracking-wider flex items-center gap-1 mb-2">
                            <i class="fa-solid fa-computer"></i> Acceso PC y Sistema AG
                        </p>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label for="onb_pc_pin" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">PIN de PC</label>
                                <input type="password" name="pc_pin" id="onb_pc_pin" placeholder="PIN numérico de Windows"
                                       class="w-full bg-slate-100 border border-slate-200 rounded-xl py-2.5 px-4 text-sm text-slate-800 focus:outline-none focus:border-slate-400">
                            </div>
                            <div>
                                <label for="onb_pass_ag" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Contraseña AG / Sistema</label>
                                <input type="password" name="pass_ag" id="onb_pass_ag" placeholder="Contraseña del sistema AG"
                                       class="w-full bg-slate-100 border border-slate-200 rounded-xl py-2.5 px-4 text-sm text-slate-800 focus:outline-none focus:border-slate-400">
                            </div>
                        </div>
                    </div>

                </div>
            </div>

            <!-- Botones de Guardar / Cancelar -->
            <div class="pt-2 flex gap-4">
                <button type="button" onclick="cerrarModal('modal-onboarding')" 
                        class="flex-1 py-3.5 rounded-xl bg-white/5 hover:bg-white/10 text-slate-800 font-semibold text-sm border border-slate-200 transition">
                    Cancelar
                </button>
                <button type="submit" 
                        class="flex-1 py-3.5 rounded-xl bg-emerald-500 hover:bg-emerald-600 text-white font-bold text-sm tracking-wide shadow-lg shadow-emerald-500/20 transition">
                    Completar Onboarding IT
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    function abrirModal(id) {
        const modal = document.getElementById(id);
        modal.querySelectorAll('form').forEach(function(f) { f.reset(); });
        modal.querySelectorAll('select').forEach(function(s) {
            if (!s.closest('form')) s.selectedIndex = 0;
        });
        modal.classList.remove('hidden');
    }
    
    function cerrarModal(id) {
        document.getElementById(id).classList.add('hidden');
    }

    function toggleOnboardingEq() {
        const check = document.getElementById('chk_vin_eq');
        const container = document.getElementById('div-onboarding-eq');
        const eqSelect = document.getElementById('onb_eq');
        const eqCond = document.getElementById('onb_eq_cond');

        if (check.checked) {
            container.classList.remove('hidden');
            eqSelect.required = true;
            eqCond.required = true;
        } else {
            container.classList.add('hidden');
            eqSelect.required = false;
            eqCond.required = false;
            eqSelect.value = '';
            eqCond.value = '';
        }
    }

    function toggleOnboardingM365() {
        const check = document.getElementById('chk_vin_m365');
        const container = document.getElementById('div-onboarding-m365');
        const mCorreo = document.getElementById('onb_m_correo');
        const mLic = document.getElementById('onb_m_lic');
        const mDate = document.getElementById('onb_m_date');
        const mPass = document.getElementById('onb_m_pass');

        if (check.checked) {
            container.classList.remove('hidden');
            mCorreo.required = true;
            mLic.required = true;
            mDate.required = true;
            mPass.required = true;
        } else {
            container.classList.add('hidden');
            mCorreo.required = false;
            mLic.required = false;
            mDate.required = false;
            mPass.required = false;
            mCorreo.value = '';
            mPass.value = '';
            mDate.value = '<?= date('Y-m-d') ?>';
        }
    }

    function toggleOnboardingCreds() {
        const check = document.getElementById('chk_vin_creds');
        const container = document.getElementById('div-onboarding-creds');
        if (check.checked) {
            container.classList.remove('hidden');
        } else {
            container.classList.add('hidden');
            ['onb_gmail','onb_pass_gmail','onb_correo','onb_pass_correo','onb_pc_pin','onb_pass_ag'].forEach(id => {
                const el = document.getElementById(id);
                if (el) el.value = '';
            });
        }
    }
</script>

<?php if ($ficha_empleado): ?>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const targetId = <?= (int)$ficha_empleado['id'] ?>;
        const allEmps = <?= json_encode($empleados, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
        const empFound = allEmps.find(e => parseInt(e.id) === targetId);
        if (empFound) {
            verFicha360(empFound);
        }
    });
</script>
<?php endif; ?>

<script>
    function abrirModal(id) {
        const modal = document.getElementById(id);
        const isEditOrDetail = id.includes('edit') || id.includes('det') || id.includes('ficha');
        if (!isEditOrDetail) {
            // Reset all forms inside the modal to prevent browser-cached values
            modal.querySelectorAll('form').forEach(function(f) { f.reset(); });
            // Reset standalone selects (not in forms) to their first option
            modal.querySelectorAll('select').forEach(function(s) {
                if (!s.closest('form')) s.selectedIndex = 0;
            });
        }
        modal.classList.remove('hidden');
    }
    
    function cerrarModal(id) {
        document.getElementById(id).classList.add('hidden');
    }

    const equiposDisponibles = <?= json_encode($equipos_disponibles, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const m365Disponibles = <?= json_encode($m365_disponibles, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    function abrirEditarEmpleado(emp) {
        document.getElementById('edit_emp_id').value = emp.id || '';
        document.getElementById('edit_emp_nombre').value = emp.nombre || '';
        document.getElementById('edit_emp_cargo').value = emp.cargo || '';
        document.getElementById('edit_emp_departamento').value = emp.departamento || '';
        document.getElementById('edit_emp_sucursal').value = emp.sucursal_id || '';

        // Popular credenciales personales
        document.getElementById('edit_emp_pin').value = emp.pc_pin || '';
        document.getElementById('edit_emp_pass_ag').value = emp.pass_ag_plain || '';
        document.getElementById('edit_emp_gmail').value = emp.correo_gmail || '';
        document.getElementById('edit_emp_pass_gmail').value = emp.pass_gmail_plain || '';

        // Popular equipos actualmente asignados
        const eqContainer = document.getElementById('edit_emp_equipos_actuales_container');
        if (eqContainer) {
            eqContainer.innerHTML = '';
            const eqActivosStr = emp.equipos_activos || '';
            const eqActivos = eqActivosStr ? eqActivosStr.split('|').map(item => {
                const idx = item.indexOf(':');
                if (idx === -1) return { id: '', desc: item };
                return { id: item.substring(0, idx), desc: item.substring(idx + 1) };
            }) : [];

            if (eqActivos.length === 0) {
                eqContainer.innerHTML = '<span class="text-xs text-slate-400 font-medium italic block py-1">Sin equipos vinculados actualmente.</span>';
            } else {
                eqActivos.forEach(eq => {
                    const label = document.createElement('label');
                    label.className = "flex items-center gap-2 text-xs font-semibold text-slate-700 bg-slate-100 border border-slate-200 rounded-xl p-2.5 cursor-pointer hover:bg-slate-200 transition";
                    
                    const chk = document.createElement('input');
                    chk.type = 'checkbox';
                    chk.name = 'desasignar_equipos[]';
                    chk.value = eq.id;
                    chk.className = 'h-4 w-4 text-rose-500 focus:ring-rose-400 rounded border-gray-300';
                    
                    const spanMark = document.createElement('span');
                    spanMark.className = 'text-rose-600 font-bold';
                    spanMark.innerText = '[Marcar para Desasignar]';
                    
                    const spanDesc = document.createElement('span');
                    spanDesc.className = 'text-slate-600 font-mono';
                    spanDesc.innerText = eq.desc;
                    
                    label.appendChild(chk);
                    label.appendChild(spanMark);
                    label.appendChild(spanDesc);
                    eqContainer.appendChild(label);
                });
            }
        }

        // Popular select para asignar nuevo equipo
        const newEqSelect = document.getElementById('edit_emp_nuevo_equipo_id');
        if (newEqSelect) {
            newEqSelect.innerHTML = '<option value="">-- Seleccionar equipo para agregar... --</option>';
            equiposDisponibles.forEach(eq => {
                const opt = document.createElement('option');
                opt.value = eq.id;
                opt.innerText = `[${eq.tipo}] ${eq.marca} ${eq.modelo} (${eq.service_tag_sn})`;
                newEqSelect.appendChild(opt);
            });
        }

        
        // Popular Cuenta Microsoft 365
        const m365ActualContainer = document.getElementById('edit_emp_m365_actual_container');
        const m365CorreoText = document.getElementById('edit_emp_m365_correo_text');
        const m365LicText = document.getElementById('edit_emp_m365_lic_text');
        const m365Badge = document.getElementById('edit_emp_m365_badge');
        const chkDesasigM365 = document.getElementById('edit_emp_desasignar_m365');
        const m365Select = document.getElementById('edit_emp_m365_id');
        const nuevaM365Div = document.getElementById('edit_emp_nueva_m365_div');
        
        if (chkDesasigM365) chkDesasigM365.checked = false;
        if (nuevaM365Div) nuevaM365Div.classList.add('hidden');

        if (emp.correo_m365) {
            if (m365ActualContainer) m365ActualContainer.classList.remove('hidden');
            if (m365CorreoText) m365CorreoText.innerText = emp.correo_m365;
            if (m365LicText) m365LicText.innerText = emp.licencia_m365 || 'Licencia Activa';
            if (m365Badge) {
                m365Badge.className = 'text-[11px] font-bold px-2.5 py-0.5 rounded-full bg-emerald-100 text-emerald-700';
                m365Badge.innerText = 'Activa';
            }
        } else {
            if (m365ActualContainer) m365ActualContainer.classList.add('hidden');
            if (m365Badge) {
                m365Badge.className = 'text-[11px] font-bold px-2.5 py-0.5 rounded-full bg-slate-200 text-slate-600';
                m365Badge.innerText = 'Sin Cuenta';
            }
        }

        // Popular select de M365 disponibles
        if (m365Select) {
            m365Select.innerHTML = '<option value="">-- ' + (emp.correo_m365 ? 'Mantener cuenta actual' : 'Sin asignar / Seleccionar cuenta libre...') + ' --</option>';
            
            if (typeof m365Disponibles !== 'undefined' && m365Disponibles.length > 0) {
                const optGroup = document.createElement('optgroup');
                optGroup.label = 'Cuentas M365 Disponibles en Pool (' + m365Disponibles.length + ')';
                m365Disponibles.forEach(m => {
                    const opt = document.createElement('option');
                    opt.value = m.id;
                    opt.innerText = `${m.correo} (${m.licencia_tipo || 'Standard'})`;
                    optGroup.appendChild(opt);
                });
                m365Select.appendChild(optGroup);
            }
        }

        abrirModal('modal-editar-empleado');
    }
</script>

<!-- Modal Editar Empleado -->
<div id="modal-editar-empleado" class="fixed inset-0 z-[100] hidden">
    <!-- Backdrop -->
    <div class="absolute inset-0 bg-slate-900/40 backdrop-blur-sm transition-opacity" onclick="cerrarModal('modal-editar-empleado')"></div>
    
    <!-- Modal Content -->
    <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-full max-w-lg bg-white rounded-[2rem] shadow-2xl shadow-slate-900/20 overflow-hidden flex flex-col max-h-[90vh]">
        <!-- Header -->
        <div class="p-6 bg-slate-50 border-b border-slate-100 flex items-center justify-between">
            <h3 class="text-lg font-black text-slate-800 tracking-tight flex items-center gap-2">
                <i class="fa-solid fa-pen text-cyan-400"></i> Editar Información General
            </h3>
            <button onclick="cerrarModal('modal-editar-empleado')" class="h-8 w-8 bg-white border border-slate-200 rounded-full flex items-center justify-center text-slate-400 hover:text-rose-400 hover:border-rose-200 hover:bg-rose-50 transition shadow-sm">
                <i class="fa-solid fa-xmark text-sm"></i>
            </button>
        </div>

        <form autocomplete="off" action="guardar.php?action=editar" method="POST" class="p-6 overflow-y-auto custom-scrollbar space-y-6">
            <input type="hidden" name="id" id="edit_emp_id">
            
            <div class="space-y-4">
                <div>
                    <label class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Nombre Completo</label>
                    <input type="text" name="nombre" id="edit_emp_nombre" required
                           class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400 focus:ring-1 focus:ring-cyan-400">
                </div>
                
                <div>
                    <label class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Sucursal</label>
                    <select autocomplete="off" name="sucursal_id" id="edit_emp_sucursal" required class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400 focus:ring-1 focus:ring-cyan-400">
                        <?php 
                        $cur_emp2 = "";
                        foreach ($sucursales as $suc): 
                            if ($suc["empresa_nombre"] !== $cur_emp2):
                                if ($cur_emp2 !== "") echo "</optgroup>";
                                $cur_emp2 = $suc["empresa_nombre"];
                        ?>
                            <optgroup label="<?= htmlspecialchars($cur_emp2) ?>">
                        <?php endif; ?>
                            <option value="<?= $suc["id"] ?>"><?= htmlspecialchars($suc["nombre"]) ?></option>
                        <?php endforeach; ?>
                        <?php if ($cur_emp2 !== "") echo "</optgroup>"; ?>
                        <option value="NEW_SUC" class="font-bold text-blue-600 bg-blue-50">+ Agregar nueva sucursal...</option>
                    </select>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Cargo / Puesto</label>
                        <input type="text" name="cargo" id="edit_emp_cargo" required
                               class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400 focus:ring-1 focus:ring-cyan-400">
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Departamento</label>
                        <select autocomplete="off" name="departamento" id="edit_emp_departamento" required
                               class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400 focus:ring-1 focus:ring-cyan-400">
                            <option value="Gerencia">Gerencia</option>
                            <option value="Administración">Administración</option>
                            <option value="IT">IT</option>
                            <option value="Ventas">Ventas</option>
                            <option value="Logística">Logística</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- ASIGNACIÓN DE HARDWARE -->
            <div class="border border-slate-200 rounded-2xl p-4 bg-slate-50 space-y-4">
                <h4 class="text-xs font-bold text-slate-600 uppercase tracking-wider flex items-center gap-1.5 border-b border-slate-200 pb-2">
                    <i class="fa-solid fa-laptop text-cyan-500"></i> Asignación de Equipo
                </h4>
                <div>
                    <label class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Equipos Actualmente Asignados</label>
                    <div id="edit_emp_equipos_actuales_container" class="space-y-2 mb-3">
                        <!-- Populated dynamically via JS -->
                    </div>
                </div>
                <div>
                    <label for="edit_emp_nuevo_equipo_id" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Asignar Nuevo Equipo (Opcional)</label>
                    <select autocomplete="off" name="nuevo_equipo_id" id="edit_emp_nuevo_equipo_id" 
                            class="w-full bg-white border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400">
                        <!-- Populated dynamically via JS -->
                    </select>
                </div>
            </div>

            <!-- ASIGNACIÓN / GESTIÓN DE CUENTA MICROSOFT 365 -->
            <div class="border border-slate-200 rounded-2xl p-4 bg-slate-50 space-y-4">
                <div class="flex items-center justify-between border-b border-slate-200 pb-2">
                    <h4 class="text-xs font-bold text-slate-600 uppercase tracking-wider flex items-center gap-1.5">
                        <i class="fa-solid fa-cloud text-emerald-500"></i> Cuenta Microsoft 365
                    </h4>
                    <span id="edit_emp_m365_badge" class="text-[11px] font-bold px-2.5 py-0.5 rounded-full bg-slate-200 text-slate-600">Sin Cuenta</span>
                </div>
                
                <div id="edit_emp_m365_actual_container" class="hidden bg-emerald-50/60 p-3 rounded-xl border border-emerald-200/80 flex items-center justify-between">
                    <div>
                        <span class="text-xs font-bold text-slate-800 block" id="edit_emp_m365_correo_text">--</span>
                        <span class="text-[11px] text-emerald-700 font-medium" id="edit_emp_m365_lic_text">--</span>
                    </div>
                    <label class="flex items-center gap-1.5 text-xs text-rose-500 font-semibold cursor-pointer hover:text-rose-700 transition bg-white px-2.5 py-1 rounded-lg border border-rose-200">
                        <input type="checkbox" name="desasignar_m365" id="edit_emp_desasignar_m365" value="1" class="rounded text-rose-500 focus:ring-rose-400">
                        <span>Desvincular</span>
                    </label>
                </div>

                <div id="edit_emp_m365_select_container">
                    <div class="flex items-center justify-between mb-2">
                        <label for="edit_emp_m365_id" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block">Asignar o Cambiar Cuenta M365 (Disponible)</label>
                        <a href="../credenciales/index.php?tab=m365" target="_blank" class="text-[11px] text-emerald-600 hover:text-emerald-700 font-bold flex items-center gap-1 transition">
                            <i class="fa-solid fa-arrow-up-right-from-square text-[10px]"></i> Administrar Licencias
                        </a>
                    </div>
                    <select autocomplete="off" name="m365_id" id="edit_emp_m365_id"
                            class="w-full bg-white border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400">
                        <option value="">-- Mantener actual / Sin cambios --</option>
                    </select>
                </div>
            </div>

            <!-- CREDENCIALES PERSONALES -->
            <div class="border border-slate-200 rounded-2xl p-4 bg-slate-50 space-y-4">
                <h4 class="text-xs font-bold text-slate-600 uppercase tracking-wider flex items-center gap-1.5 border-b border-slate-200 pb-2">
                    <i class="fa-solid fa-key text-amber-500"></i> Credenciales Personales
                </h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label for="edit_emp_pin" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">PC PIN / Acceso</label>
                        <input type="text" name="pc_pin" id="edit_emp_pin" placeholder="Ej: 1234 o N/A"
                               class="w-full bg-white border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400">
                    </div>
                    <div>
                        <label for="edit_emp_pass_ag" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Contraseña AG / Sistema</label>
                        <input type="text" name="pass_ag" id="edit_emp_pass_ag" placeholder="Ej: Vmaster#2026"
                               class="w-full bg-white border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400 font-mono">
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 pt-2">
                    <div>
                        <label for="edit_emp_gmail" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Correo Gmail</label>
                        <input type="email" name="correo_gmail" id="edit_emp_gmail" placeholder="usuario@gmail.com"
                               class="w-full bg-white border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400 font-mono">
                    </div>
                    <div>
                        <label for="edit_emp_pass_gmail" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Contraseña Gmail</label>
                        <input type="text" name="pass_gmail" id="edit_emp_pass_gmail" placeholder="Ej: PassGmail#2026"
                               class="w-full bg-white border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400 font-mono">
                    </div>
                </div>
            </div>

            <!-- Botones -->
            <div class="pt-2 flex gap-4">
                <button type="button" onclick="cerrarModal('modal-editar-empleado')" class="flex-1 py-3.5 rounded-xl bg-white/5 hover:bg-white/10 text-slate-800 font-semibold text-sm border border-slate-200 transition">
                    Cancelar
                </button>
                <button type="submit" class="flex-1 py-3.5 rounded-xl bg-cyan-500 hover:bg-cyan-600 text-white font-bold text-sm tracking-wide shadow-lg shadow-cyan-500/20 transition">
                    Guardar Cambios
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================
     MODAL FICHA 360° INTEGRAL DEL COLABORADOR
     ======================================================== -->
<div id="modal-ficha-360" class="fixed inset-0 bg-slate-900/70 backdrop-blur-sm z-[150] flex items-center justify-center hidden p-4">
    <div class="bg-white border border-slate-200 rounded-3xl w-full max-w-2xl max-h-[90vh] overflow-hidden shadow-2xl flex flex-col animate-fade-in">

        <!-- Header -->
        <div class="p-6 border-b border-slate-100 bg-gradient-to-r from-violet-50 to-white flex items-center justify-between shrink-0">
            <div class="flex items-center gap-3">
                <div class="h-12 w-12 rounded-2xl bg-violet-100 border border-violet-300 text-violet-700 flex items-center justify-center text-xl font-extrabold shrink-0" id="f360_initials">C</div>
                <div>
                    <h3 class="font-bold text-slate-800 text-lg flex items-center gap-2">
                        <span id="f360_nombre">Colaborador</span>
                    </h3>
                    <p class="text-xs text-slate-500 font-medium" id="f360_info">Cargo · Departamento · Sucursal</p>
                </div>
            </div>
            <button onclick="cerrarModal('modal-ficha-360')" class="h-9 w-9 rounded-full hover:bg-slate-200 text-slate-400 hover:text-slate-700 flex items-center justify-center transition text-lg">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <!-- Body scrollable -->
        <div class="overflow-y-auto flex-1 p-6 space-y-5">

            <!-- BLOQUE 1: HARDWARE Y EQUIPOS ASIGNADOS -->
            <div id="f360_equipos_container" class="space-y-4">
                <!-- Se renderiza dinámicamente -->
            </div>

            <!-- BLOQUE 2: CUENTA MICROSOFT 365 -->
            <div id="f360_bloque_m365" class="border border-emerald-200 rounded-2xl overflow-hidden">
                <div class="bg-emerald-50 px-4 py-2.5 flex items-center gap-2 border-b border-emerald-200">
                    <i class="fa-solid fa-cloud text-emerald-600 text-sm"></i>
                    <span class="text-xs font-bold text-emerald-700 uppercase tracking-wider">Cuenta Microsoft 365</span>
                    <span class="ml-auto px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-700 border border-emerald-200" id="f360_m365_estado">Activa</span>
                </div>
                <div class="p-4 grid grid-cols-1 md:grid-cols-2 gap-3 text-xs bg-white">
                    <div>
                        <span class="text-[11px] text-slate-400 block font-semibold">Correo M365</span>
                        <div class="flex items-center gap-2 mt-0.5">
                            <span class="font-mono font-bold text-slate-800 text-xs truncate" id="f360_m365_correo">--</span>
                            <button type="button" onclick="copiarTexto(document.getElementById('f360_m365_correo').innerText, this)" class="text-slate-400 hover:text-emerald-600 p-0.5" title="Copiar correo"><i class="fa-regular fa-copy text-xs"></i></button>
                        </div>
                    </div>
                    <div>
                        <span class="text-[11px] text-slate-400 block font-semibold">Tipo de Licencia</span>
                        <span class="font-bold text-slate-700 block mt-0.5" id="f360_m365_lic">--</span>
                    </div>
                    <div>
                        <span class="text-[11px] text-slate-400 block font-semibold">Ciclo / Renovación</span>
                        <span class="font-bold text-slate-700 block mt-0.5" id="f360_m365_ciclo">--</span>
                    </div>
                    <div>
                        <span class="text-[11px] text-slate-400 block font-semibold mb-1">Contraseña M365</span>
                        <div class="flex items-center gap-2">
                            <input type="password" readonly id="f360_m365_pass" class="bg-slate-50 border border-slate-200 rounded-lg px-2.5 py-1 text-xs font-mono flex-1 text-slate-800">
                            <button type="button" onclick="toggleFieldPass('f360_m365_pass','f360_m365_pass_ico')" class="px-2 py-1 rounded bg-slate-200 text-slate-600 hover:text-slate-800 text-xs" title="Revelar">
                                <i class="fa-solid fa-eye" id="f360_m365_pass_ico"></i>
                            </button>
                            <button type="button" onclick="copiarTexto(document.getElementById('f360_m365_pass').value, this)" class="px-2 py-1 rounded bg-slate-200 text-slate-600 hover:text-slate-800 text-xs" title="Copiar contraseña">
                                <i class="fa-regular fa-copy"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- BLOQUE 3: CUENTAS PERSONALES Y ACCESO -->
            <div class="border border-amber-200 rounded-2xl overflow-hidden">
                <div class="bg-amber-50 px-4 py-2.5 flex items-center gap-2 border-b border-amber-200">
                    <i class="fa-solid fa-key text-amber-600 text-sm"></i>
                    <span class="text-xs font-bold text-amber-700 uppercase tracking-wider">Cuentas Personales y Acceso</span>
                </div>
                <div class="p-4 space-y-3 bg-white" id="f360_credenciales_container">
                    <!-- Se renderiza dinámicamente -->
                </div>
            </div>

        </div>

        <!-- Footer -->
        <div class="p-4 border-t border-slate-100 bg-slate-50 flex items-center justify-between shrink-0">
            <button type="button" onclick="cerrarModal('modal-ficha-360'); if (window.currentEmp360) abrirEditarEmpleado(window.currentEmp360);" class="px-4 py-2.5 rounded-xl bg-violet-600 hover:bg-violet-700 text-white text-xs font-bold transition shadow-sm flex items-center gap-2">
                <i class="fa-solid fa-pen"></i> Editar Colaborador
            </button>
            <button type="button" onclick="cerrarModal('modal-ficha-360')" class="px-5 py-2.5 rounded-xl bg-slate-200 hover:bg-slate-300 text-slate-700 text-xs font-bold transition shadow-sm">
                Cerrar
            </button>
        </div>
    </div>
</div>

<script>
    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    window.currentEmp360 = null;

    function copiarTexto(texto, btn) {
        if (!texto) return;
        navigator.clipboard.writeText(texto).then(() => {
            if (btn) {
                const icon = btn.querySelector('i');
                if (icon) {
                    const originalClass = icon.className;
                    icon.className = 'fa-solid fa-check text-emerald-500';
                    setTimeout(() => { icon.className = originalClass; }, 1500);
                }
            }
        });
    }

    function toggleFieldPass(inputId, iconId) {
        const inp = document.getElementById(inputId);
        const ico = document.getElementById(iconId);
        if (!inp || !ico) return;
        if (inp.type === 'password') {
            inp.type = 'text';
            ico.className = 'fa-solid fa-eye-slash text-slate-700';
        } else {
            inp.type = 'password';
            ico.className = 'fa-solid fa-eye text-slate-500';
        }
    }

    function verFicha360(emp) {
        window.currentEmp360 = emp;

        document.getElementById('f360_nombre').innerText = emp.nombre || 'Colaborador';
        document.getElementById('f360_info').innerText = [emp.cargo, emp.departamento, emp.empresa_nombre, emp.sucursal_nombre].filter(Boolean).join(' • ');
        document.getElementById('f360_initials').innerText = (emp.nombre || 'E').charAt(0).toUpperCase();

        // 1. Hardware y Equipos Asignados
        const containerEq = document.getElementById('f360_equipos_container');
        containerEq.innerHTML = '';
        const equipos = emp.equipos_lista || [];

        if (equipos.length > 0) {
            equipos.forEach((eq, idx) => {
                let iconClass = 'fa-laptop';
                const t = (eq.tipo || '').toLowerCase();
                if (t.includes('desktop') || t.includes('computadora')) iconClass = 'fa-desktop';
                else if (t.includes('celular') || t.includes('móvil') || t.includes('movil')) iconClass = 'fa-mobile-screen-button';
                else if (t.includes('servidor') || t.includes('server')) iconClass = 'fa-server';
                else if (t.includes('impresora')) iconClass = 'fa-print';

                const eqPassId = 'f360_eq_pass_' + idx;
                const eqPassIcoId = 'f360_eq_pass_ico_' + idx;

                const eqDiv = document.createElement('div');
                eqDiv.className = 'border border-cyan-200 rounded-2xl overflow-hidden shadow-sm';
                eqDiv.innerHTML = `
                    <div class="bg-cyan-50 px-4 py-2.5 flex items-center justify-between border-b border-cyan-200">
                        <div class="flex items-center gap-2">
                            <i class="fa-solid ${iconClass} text-cyan-600 text-sm"></i>
                            <span class="text-xs font-bold text-cyan-800 uppercase tracking-wider">${escapeHtml(eq.tipo || 'Equipo')}</span>
                            <span class="text-xs font-bold text-slate-800">• ${escapeHtml(eq.marca || '')} ${escapeHtml(eq.modelo || '')}</span>
                        </div>
                        <span class="text-[11px] font-mono text-cyan-700 bg-cyan-100 px-2 py-0.5 rounded-full font-bold">
                            S/N: ${escapeHtml(eq.service_tag_sn || 'Sin Serial')}
                        </span>
                    </div>
                    <div class="p-4 grid grid-cols-2 md:grid-cols-3 gap-3 text-xs bg-white">
                        <div>
                            <span class="text-[11px] text-slate-400 block font-semibold">Procesador</span>
                            <span class="font-medium text-slate-700 block mt-0.5">${escapeHtml(eq.procesador || 'N/A')}</span>
                        </div>
                        <div>
                            <span class="text-[11px] text-slate-400 block font-semibold">RAM / Disco</span>
                            <span class="font-medium text-slate-700 block mt-0.5">${escapeHtml(eq.ram || 'N/A')} / ${escapeHtml(eq.disco || 'N/A')}</span>
                        </div>
                        <div>
                            <span class="text-[11px] text-slate-400 block font-semibold">Dirección IP</span>
                            <span class="font-mono text-slate-700 block mt-0.5">${escapeHtml(eq.ip_direccion || 'Sin IP asignada')}</span>
                        </div>
                        <div>
                            <span class="text-[11px] text-slate-400 block font-semibold">Acceso a Windows</span>
                            <span class="font-bold text-indigo-600 block mt-0.5"><i class="fa-brands fa-windows text-blue-500"></i> Azure AD / PIN</span>
                        </div>
                        <div>
                            <span class="text-[11px] text-slate-400 block font-semibold">PIN de Seguridad PC</span>
                            <div class="flex items-center gap-1.5 mt-0.5">
                                <span class="font-mono font-bold text-slate-800 bg-slate-100 px-2 py-0.5 rounded border border-slate-200">${escapeHtml(emp.pc_pin || 'No registrado')}</span>
                                ${emp.pc_pin ? `<button type="button" onclick="copiarTexto('${escapeHtml(emp.pc_pin)}', this)" class="text-slate-400 hover:text-cyan-600 p-0.5" title="Copiar PIN"><i class="fa-regular fa-copy text-xs"></i></button>` : ''}
                            </div>
                        </div>
                        ${eq.usuario_local ? `
                        <div>
                            <span class="text-[11px] text-slate-400 block font-semibold">Usuario Local Admin</span>
                            <span class="font-mono text-slate-700 block mt-0.5">${escapeHtml(eq.usuario_local)}</span>
                        </div>
                        <div class="col-span-2">
                            <span class="text-[11px] text-slate-400 block font-semibold mb-1">Contraseña Local Admin</span>
                            <div class="flex items-center gap-2">
                                <input type="password" readonly id="${eqPassId}" value="${escapeHtml(eq.eq_pass_plain || '')}" class="bg-slate-50 border border-slate-200 rounded-lg px-2 py-1 text-xs font-mono flex-1 text-slate-800">
                                <button type="button" onclick="toggleFieldPass('${eqPassId}','${eqPassIcoId}')" class="px-2 py-1 rounded bg-slate-200 text-slate-600 text-xs"><i class="fa-solid fa-eye" id="${eqPassIcoId}"></i></button>
                                <button type="button" onclick="copiarTexto('${escapeHtml(eq.eq_pass_plain || '')}', this)" class="px-2 py-1 rounded bg-slate-200 text-slate-600 text-xs"><i class="fa-regular fa-copy"></i></button>
                            </div>
                        </div>` : ''}
                        ${eq.eq_bitlocker_plain ? `
                        <div class="col-span-2 md:col-span-3 pt-2 border-t border-slate-100">
                            <span class="text-[11px] text-slate-400 block font-semibold">Clave BitLocker de Recuperación</span>
                            <div class="flex items-center gap-2 mt-0.5">
                                <span class="font-mono text-xs font-bold text-amber-700 bg-amber-50 px-2.5 py-1 rounded-lg border border-amber-200 select-all break-all">${escapeHtml(eq.eq_bitlocker_plain)}</span>
                                <button type="button" onclick="copiarTexto('${escapeHtml(eq.eq_bitlocker_plain)}', this)" class="text-slate-400 hover:text-amber-600 p-1" title="Copiar BitLocker"><i class="fa-regular fa-copy text-xs"></i></button>
                            </div>
                        </div>` : ''}
                    </div>
                `;
                containerEq.appendChild(eqDiv);
            });
        } else if (emp.pc_pin || emp.pass_ag_plain) {
            const remoteDiv = document.createElement('div');
            remoteDiv.className = 'border border-indigo-200 rounded-2xl overflow-hidden bg-white shadow-sm';
            remoteDiv.innerHTML = `
                <div class="bg-indigo-50 px-4 py-2.5 flex items-center justify-between border-b border-indigo-200">
                    <div class="flex items-center gap-2">
                        <i class="fa-solid fa-network-wired text-indigo-600 text-sm"></i>
                        <span class="text-xs font-bold text-indigo-800 uppercase tracking-wider">Terminal Remoto / Credencial Local</span>
                    </div>
                    <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-indigo-100 text-indigo-700 border border-indigo-200">
                        ${escapeHtml(emp.sucursal_nombre || 'Remoto')}
                    </span>
                </div>
                <div class="p-4 grid grid-cols-1 md:grid-cols-2 gap-3 text-xs">
                    <div>
                        <span class="text-[11px] text-slate-400 block font-semibold">PIN de Inicio de Sesión PC</span>
                        <div class="flex items-center gap-2 mt-1">
                            <span class="font-mono font-bold text-slate-800 text-sm bg-slate-100 px-3 py-1 rounded-lg border border-slate-200">${escapeHtml(emp.pc_pin || 'No registrado')}</span>
                            ${emp.pc_pin ? `<button type="button" onclick="copiarTexto('${escapeHtml(emp.pc_pin)}', this)" class="text-slate-400 hover:text-indigo-600 p-1" title="Copiar PIN"><i class="fa-regular fa-copy text-xs"></i></button>` : ''}
                        </div>
                    </div>
                    <div>
                        <span class="text-[11px] text-slate-400 block font-semibold">Contraseña AG / Sistema</span>
                        <div class="flex items-center gap-2 mt-1">
                            <span class="font-mono font-bold text-indigo-700 text-sm bg-indigo-50 px-3 py-1 rounded-lg border border-indigo-200">${escapeHtml(emp.pass_ag_plain || 'No registrada')}</span>
                            ${emp.pass_ag_plain ? `<button type="button" onclick="copiarTexto('${escapeHtml(emp.pass_ag_plain)}', this)" class="text-slate-400 hover:text-indigo-600 p-1" title="Copiar AG"><i class="fa-regular fa-copy text-xs"></i></button>` : ''}
                        </div>
                    </div>
                </div>
            `;
            containerEq.appendChild(remoteDiv);
        } else {
            const emptyDiv = document.createElement('div');
            emptyDiv.className = 'border border-cyan-100 rounded-2xl p-4 text-center bg-slate-50/50';
            emptyDiv.innerHTML = `<span class="text-slate-400 italic text-xs">Sin equipo de cómputo asignado en inventario</span>`;
            containerEq.appendChild(emptyDiv);
        }

        // 2. Cuenta Microsoft 365
        const bloqueM365 = document.getElementById('f360_bloque_m365');
        if (emp.correo_m365) {
            bloqueM365.classList.remove('hidden');
            document.getElementById('f360_m365_correo').innerText = emp.correo_m365 || '--';
            document.getElementById('f360_m365_lic').innerText = emp.licencia_m365 || 'Licencia Estándar';
            document.getElementById('f360_m365_ciclo').innerText = (emp.m365_ciclo || 'Mensual') + (emp.m365_proximo_pago ? ' · Próximo pago: ' + emp.m365_proximo_pago : '');
            document.getElementById('f360_m365_estado').innerText = emp.m365_estado || 'Activa';
            document.getElementById('f360_m365_pass').value = emp.m365_pass_plain || '';
            document.getElementById('f360_m365_pass').type = 'password';
            document.getElementById('f360_m365_pass_ico').className = 'fa-solid fa-eye text-slate-500';
        } else {
            bloqueM365.classList.add('hidden');
        }

        // 3. Cuentas Personales y Acceso
        const containerCreds = document.getElementById('f360_credenciales_container');
        containerCreds.innerHTML = '';
        let hasCreds = false;

        // Gmail
        if (emp.correo_gmail || emp.pass_gmail_plain) {
            hasCreds = true;
            containerCreds.innerHTML += `
                <div class="bg-slate-50 border border-slate-200 p-3.5 rounded-xl space-y-2">
                    <div class="flex items-center gap-2 text-xs font-bold text-slate-700 uppercase tracking-wider">
                        <i class="fa-brands fa-google text-red-500"></i> Cuenta Gmail
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-xs">
                        <div>
                            <span class="text-[11px] text-slate-400 block font-semibold">Correo Gmail</span>
                            <div class="flex items-center gap-1.5 mt-0.5">
                                <span class="font-mono font-bold text-slate-800 text-xs">${escapeHtml(emp.correo_gmail || 'Sin correo')}</span>
                                ${emp.correo_gmail ? `<button type="button" onclick="copiarTexto('${escapeHtml(emp.correo_gmail)}', this)" class="text-slate-400 hover:text-red-500 p-0.5"><i class="fa-regular fa-copy text-xs"></i></button>` : ''}
                            </div>
                        </div>
                        <div>
                            <span class="text-[11px] text-slate-400 block font-semibold mb-1">Contraseña Gmail</span>
                            <div class="flex items-center gap-2">
                                <input type="password" readonly id="f360_gmail_pass" value="${escapeHtml(emp.pass_gmail_plain || '')}" class="bg-white border border-slate-200 rounded-lg px-2.5 py-1 text-xs font-mono flex-1 text-slate-800">
                                <button type="button" onclick="toggleFieldPass('f360_gmail_pass','f360_gmail_pass_ico')" class="px-2 py-1 rounded bg-slate-200 text-slate-600 text-xs"><i class="fa-solid fa-eye" id="f360_gmail_pass_ico"></i></button>
                                <button type="button" onclick="copiarTexto('${escapeHtml(emp.pass_gmail_plain || '')}', this)" class="px-2 py-1 rounded bg-slate-200 text-slate-600 text-xs"><i class="fa-regular fa-copy"></i></button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        }

        // PC PIN
        if (emp.pc_pin) {
            hasCreds = true;
            containerCreds.innerHTML += `
                <div class="bg-slate-50 border border-slate-200 p-3.5 rounded-xl space-y-2">
                    <div class="flex items-center gap-2 text-xs font-bold text-slate-700 uppercase tracking-wider">
                        <i class="fa-solid fa-desktop text-slate-500"></i> PIN de Seguridad PC (Windows)
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-xs">
                        <div>
                            <span class="text-[11px] text-slate-400 block font-semibold">Usuario / ID</span>
                            <span class="font-mono font-bold text-slate-800 text-xs block mt-0.5">${escapeHtml(emp.nombre || 'N/A')}</span>
                        </div>
                        <div>
                            <span class="text-[11px] text-slate-400 block font-semibold mb-1">PIN de Acceso</span>
                            <div class="flex items-center gap-2">
                                <input type="password" readonly id="f360_pin_pass" value="${escapeHtml(emp.pc_pin || '')}" class="bg-white border border-slate-200 rounded-lg px-2.5 py-1 text-xs font-mono flex-1 text-slate-800">
                                <button type="button" onclick="toggleFieldPass('f360_pin_pass','f360_pin_pass_ico')" class="px-2 py-1 rounded bg-slate-200 text-slate-600 text-xs"><i class="fa-solid fa-eye" id="f360_pin_pass_ico"></i></button>
                                <button type="button" onclick="copiarTexto('${escapeHtml(emp.pc_pin || '')}', this)" class="px-2 py-1 rounded bg-slate-200 text-slate-600 text-xs"><i class="fa-regular fa-copy"></i></button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        }

        // Pass AG
        if (emp.pass_ag_plain) {
            hasCreds = true;
            containerCreds.innerHTML += `
                <div class="bg-slate-50 border border-slate-200 p-3.5 rounded-xl space-y-2">
                    <div class="flex items-center gap-2 text-xs font-bold text-slate-700 uppercase tracking-wider">
                        <i class="fa-solid fa-server text-indigo-500"></i> Contraseña AG / Sistema ERP
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-xs">
                        <div>
                            <span class="text-[11px] text-slate-400 block font-semibold">Sistema</span>
                            <span class="font-bold text-slate-800 text-xs block mt-0.5">Acceso Corporativo AG</span>
                        </div>
                        <div>
                            <span class="text-[11px] text-slate-400 block font-semibold mb-1">Contraseña AG</span>
                            <div class="flex items-center gap-2">
                                <input type="password" readonly id="f360_ag_pass" value="${escapeHtml(emp.pass_ag_plain || '')}" class="bg-white border border-slate-200 rounded-lg px-2.5 py-1 text-xs font-mono flex-1 text-slate-800">
                                <button type="button" onclick="toggleFieldPass('f360_ag_pass','f360_ag_pass_ico')" class="px-2 py-1 rounded bg-slate-200 text-slate-600 text-xs"><i class="fa-solid fa-eye" id="f360_ag_pass_ico"></i></button>
                                <button type="button" onclick="copiarTexto('${escapeHtml(emp.pass_ag_plain || '')}', this)" class="px-2 py-1 rounded bg-slate-200 text-slate-600 text-xs"><i class="fa-regular fa-copy"></i></button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        }

        if (!hasCreds) {
            containerCreds.innerHTML = `<span class="text-slate-400 italic text-xs">Ninguna credencial personal registrada</span>`;
        }

        abrirModal('modal-ficha-360');
    }

    // Alias para compatibilidad hacia atrás
    function verDetalleEmpleado(emp) {
        verFicha360(emp);
    }
</script>



<!-- Modal Agregar Nueva Sucursal (inline) -->
<div id="modal-nueva-sucursal" class="fixed inset-0 z-[200] hidden">
    <div class="absolute inset-0 bg-slate-900/50 backdrop-blur-sm" onclick="cancelarNuevaSucursal()"></div>
    <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-full max-w-md bg-white rounded-2xl shadow-2xl overflow-hidden">
        <div class="p-5 bg-gradient-to-r from-emerald-50 to-cyan-50 border-b border-slate-200 flex items-center justify-between">
            <h3 class="font-bold text-slate-800 flex items-center gap-2 text-sm">
                <i class="fa-solid fa-building text-emerald-500"></i> Registrar Nueva Sucursal
            </h3>
            <button onclick="cancelarNuevaSucursal()" class="h-7 w-7 bg-white border border-slate-200 rounded-full flex items-center justify-center text-slate-400 hover:text-rose-400 transition shadow-sm text-xs">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div class="p-5 space-y-4">
            <div>
                <label class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Empresa</label>
                <select autocomplete="off" id="ns_empresa_id" class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-emerald-400 focus:ring-1 focus:ring-emerald-400">
                    <option value="" disabled selected>Selecciona empresa...</option>
                    <?php foreach ($empresas as $emp): ?>
                        <option value="<?= $emp['id'] ?>"><?= htmlspecialchars($emp['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Nombre de la Sucursal</label>
                <input type="text" id="ns_nombre" placeholder="Ej: Mastertec Estelí" class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-emerald-400 focus:ring-1 focus:ring-emerald-400">
            </div>
            <div class="flex gap-3 pt-1">
                <button type="button" onclick="cancelarNuevaSucursal()" class="flex-1 py-3 rounded-xl bg-white border border-slate-200 text-slate-700 font-semibold text-sm hover:bg-slate-50 transition">
                    Cancelar
                </button>
                <button type="button" onclick="confirmarNuevaSucursal()" class="flex-1 py-3 rounded-xl bg-emerald-500 hover:bg-emerald-600 text-white font-bold text-sm tracking-wide shadow-lg shadow-emerald-500/20 transition">
                    <i class="fa-solid fa-check mr-1"></i> Confirmar
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal Dar de Baja Empleado -->
<div id="modal-baja-empleado" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-[9999] flex items-center justify-center hidden p-4">
    <div class="bg-white border border-slate-200 rounded-3xl w-full max-w-sm overflow-hidden shadow-2xl animate-fade-in text-center p-8">
        <div class="h-16 w-16 bg-rose-100 text-rose-500 rounded-full flex items-center justify-center text-3xl mx-auto mb-4">
            <i class="fa-solid fa-user-minus"></i>
        </div>
        <h3 class="font-bold text-slate-800 text-xl mb-2">Dar de baja empleado</h3>
        <p class="text-slate-500 text-sm mb-6">¿Seguro que deseas dar de baja a este empleado? Esto no eliminará los equipos del sistema, pero los liberará de su asignación.</p>
        <div class="flex gap-3 w-full">
            <button type="button" onclick="cerrarModal('modal-baja-empleado')" class="flex-1 py-3 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold text-sm transition">Cancelar</button>
            <a id="btn_confirmar_baja_emp" href="#" class="flex-1 py-3 rounded-xl bg-rose-500 hover:bg-rose-600 text-white font-bold text-sm transition shadow-lg shadow-rose-500/30 flex items-center justify-center">Sí, dar de baja</a>
        </div>
    </div>
</div>

<script>
    function confirmarBajaEmpleado(url) {
        document.getElementById('btn_confirmar_baja_emp').href = url;
        abrirModal('modal-baja-empleado');
    }
    // Track which select triggered the "new sucursal" modal
    let _sucSelectOrigin = null;
    let _previousSucValue = '';

    // Reset all form fields on page load to prevent browser caching
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('form').forEach(f => f.reset());
        // Also reset standalone selects not inside forms
        const nsEmpresa = document.getElementById('ns_empresa_id');
        if (nsEmpresa) nsEmpresa.selectedIndex = 0;
        const nsNombre = document.getElementById('ns_nombre');
        if (nsNombre) nsNombre.value = '';
    });

    function abrirModalNuevaSucursal(originSelect) {
        _sucSelectOrigin = originSelect;
        _previousSucValue = '';
        // Reset modal fields every time it opens
        document.getElementById('ns_empresa_id').selectedIndex = 0;
        document.getElementById('ns_nombre').value = '';
        document.getElementById('modal-nueva-sucursal').classList.remove('hidden');
    }

    // Listen for "NEW_SUC" selection on both selects
    document.getElementById('emp_suc').addEventListener('change', function() {
        if (this.value === 'NEW_SUC') abrirModalNuevaSucursal(this);
    });
    document.getElementById('edit_emp_sucursal').addEventListener('change', function() {
        if (this.value === 'NEW_SUC') abrirModalNuevaSucursal(this);
    });

    function cancelarNuevaSucursal() {
        document.getElementById('modal-nueva-sucursal').classList.add('hidden');
        if (_sucSelectOrigin) {
            // Reset to first real option
            _sucSelectOrigin.selectedIndex = 0;
        }
        document.getElementById('ns_nombre').value = '';
        _sucSelectOrigin = null;
    }

    function confirmarNuevaSucursal() {
        const nombre = document.getElementById('ns_nombre').value.trim();
        const empresaSelect = document.getElementById('ns_empresa_id');
        const empresaId = empresaSelect.value;

        if (!empresaId) {
            empresaSelect.focus();
            empresaSelect.classList.add('border-rose-400', 'ring-1', 'ring-rose-400');
            setTimeout(() => { empresaSelect.classList.remove('border-rose-400', 'ring-1', 'ring-rose-400'); }, 2000);
            return;
        }

        if (!nombre) {
            document.getElementById('ns_nombre').focus();
            document.getElementById('ns_nombre').classList.add('border-rose-400', 'ring-1', 'ring-rose-400');
            setTimeout(() => { document.getElementById('ns_nombre').classList.remove('border-rose-400', 'ring-1', 'ring-rose-400'); }, 2000);
            return;
        }

        if (_sucSelectOrigin) {
            // Keep NEW_SUC selected — the backend will read the hidden fields
            // Inject hidden fields into the same form
            const form = _sucSelectOrigin.closest('form');

            // Remove any previous hidden fields
            form.querySelectorAll('.nueva-suc-hidden').forEach(el => el.remove());

            const hiddenNombre = document.createElement('input');
            hiddenNombre.type = 'hidden';
            hiddenNombre.name = 'nueva_sucursal_nombre';
            hiddenNombre.value = nombre;
            hiddenNombre.className = 'nueva-suc-hidden';
            form.appendChild(hiddenNombre);

            const hiddenEmpresa = document.createElement('input');
            hiddenEmpresa.type = 'hidden';
            hiddenEmpresa.name = 'nueva_sucursal_empresa';
            hiddenEmpresa.value = empresaId;
            hiddenEmpresa.className = 'nueva-suc-hidden';
            form.appendChild(hiddenEmpresa);

            // Show a visual badge next to the select
            let badge = _sucSelectOrigin.parentElement.querySelector('.nueva-suc-badge');
            if (!badge) {
                badge = document.createElement('div');
                badge.className = 'nueva-suc-badge mt-1.5 text-xs font-semibold text-emerald-700 bg-emerald-50 border border-emerald-200 rounded-lg px-3 py-1.5 flex items-center gap-1.5';
                _sucSelectOrigin.parentElement.appendChild(badge);
            }
            badge.innerHTML = '<i class="fa-solid fa-circle-check text-emerald-500"></i> Nueva: <strong>' + escapeHtml(nombre) + '</strong>';
        }

        document.getElementById('modal-nueva-sucursal').classList.add('hidden');
        document.getElementById('ns_nombre').value = '';
        _sucSelectOrigin = null;
    }

    // ========================================================
    // LÓGICA DE FILTRADO ROBUSTO EN TIEMPO REAL
    // ========================================================
    function aplicarFiltros() {
        const textQuery = (document.getElementById('filterText').value || '').toLowerCase().trim();
        const empresaVal = (document.getElementById('filterEmpresa').value || '').toLowerCase().trim();
        const sucursalSelect = document.getElementById('filterSucursal');
        const sucursalVal = (sucursalSelect ? sucursalSelect.value : '').toLowerCase().trim();
        const selectedOpt = sucursalSelect && sucursalSelect.selectedIndex >= 0 ? sucursalSelect.options[sucursalSelect.selectedIndex] : null;
        const optEmpresa = (selectedOpt && sucursalVal) ? (selectedOpt.getAttribute('data-empresa') || '').toLowerCase().trim() : '';
        const deptoVal = (document.getElementById('filterDepto').value || '').toLowerCase().trim();
        const recursosVal = document.getElementById('filterRecursos').value;

        // Filtrar dinámicamente las opciones del select de sucursales según la empresa seleccionada
        if (sucursalSelect) {
            const optgroups = sucursalSelect.querySelectorAll('optgroup');
            optgroups.forEach(group => {
                const label = (group.getAttribute('label') || '').toLowerCase();
                if (!empresaVal || label.includes(empresaVal)) {
                    group.style.display = '';
                } else {
                    group.style.display = 'none';
                }
            });
        }

        if (window.paginadorEmpleados) {
            window.paginadorEmpleados.setFilter(row => {
                const textAll = (row.getAttribute('data-search') || '').toLowerCase();
                const empresa = (row.getAttribute('data-empresa') || '').toLowerCase();
                const sucursal = (row.getAttribute('data-sucursal') || '').toLowerCase();
                const depto = (row.getAttribute('data-depto') || '').toLowerCase();
                const cantEquipos = parseInt(row.getAttribute('data-equipos') || '0', 10);
                const tieneM365 = row.getAttribute('data-m365') === 'true';

                let matchesText = !textQuery || textAll.includes(textQuery);
                let matchesEmpresa = (!empresaVal || empresa.includes(empresaVal)) && (!optEmpresa || empresa === optEmpresa);
                let matchesSucursal = !sucursalVal || sucursal.includes(sucursalVal);
                let matchesDepto = !deptoVal || depto.includes(deptoVal);

                let matchesRecursos = true;
                if (recursosVal === 'con_equipo') matchesRecursos = cantEquipos > 0;
                if (recursosVal === 'sin_equipo') matchesRecursos = cantEquipos === 0;
                if (recursosVal === 'con_m365') matchesRecursos = tieneM365;
                if (recursosVal === 'sin_m365') matchesRecursos = !tieneM365;

                return matchesText && matchesEmpresa && matchesSucursal && matchesDepto && matchesRecursos;
            });
        }
    }

    function limpiarFiltros() {
        if (document.getElementById('filterText')) document.getElementById('filterText').value = '';
        if (document.getElementById('filterEmpresa')) document.getElementById('filterEmpresa').value = '';
        if (document.getElementById('filterSucursal')) document.getElementById('filterSucursal').value = '';
        if (document.getElementById('filterDepto')) document.getElementById('filterDepto').value = '';
        if (document.getElementById('filterRecursos')) document.getElementById('filterRecursos').value = '';
        toggleClearBtn('filterText', 'btn_clear_empleado');
        aplicarFiltros();
    }

    let paginadorEmpleados = null;
    function initPaginacionEmpleados() {
        if (typeof TablePaginator !== 'undefined') {
            paginadorEmpleados = new TablePaginator({
                table: '#tablaEmpleados',
                container: '#paginacionEmpleados',
                itemLabel: 'colaboradores',
                pageSize: 15,
                counterEl: '#countVisibles'
            });
            window.paginadorEmpleados = paginadorEmpleados;
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initPaginacionEmpleados);
    } else {
        initPaginacionEmpleados();
    }
</script>

<?php
require_once __DIR__ . '/../../includes/footer.php';
?>
