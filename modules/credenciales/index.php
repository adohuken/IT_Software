<?php
/**
 * Módulo de Gestión de Credenciales y Cuentas
 * Permite administrar credenciales de equipos, portales web y cuentas M365
 */

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../config/security.php';

$db = Database::getConexion();

// Determinar pestaña activa
$tab = $_GET['tab'] ?? 'equipos';

// Cargar catálogos para formularios de creación
$equipos = $db->query("SELECT id, tipo, marca, modelo, service_tag_sn FROM equipos WHERE estado != 'De Baja' ORDER BY tipo, marca")->fetchAll();
$empleados = $db->query("SELECT id, nombre, cargo FROM empleados WHERE estado = 'Activo' ORDER BY nombre")->fetchAll();

// Cargar listas correspondientes con joins para obtener el nombre de la empresa
$cred_equipos_raw = $db->query("
    SELECT ce.*, e.tipo, e.marca, e.modelo, e.service_tag_sn, emp_comp.nombre AS empresa_nombre 
    FROM credenciales_equipos ce 
    JOIN equipos e ON ce.equipo_id = e.id 
    LEFT JOIN sucursales s ON e.sucursal_id = s.id
    LEFT JOIN empresas emp_comp ON s.empresa_id = emp_comp.id
    ORDER BY e.tipo, ce.usuario_local
")->fetchAll();

$cred_equipos = [];
foreach ($cred_equipos_raw as $ce) {
    $ce['pass_plain'] = !empty($ce['password_cifrado']) ? Security::decrypt($ce['password_cifrado']) : '';
    $ce['bitlocker_plain'] = !empty($ce['clave_bitlocker']) ? Security::decrypt($ce['clave_bitlocker']) : '';
    $cred_equipos[] = $ce;
}

$cred_web_raw = $db->query("SELECT * FROM credenciales_web ORDER BY plataforma")->fetchAll();
$cred_web = [];
foreach ($cred_web_raw as $cw) {
    $cw['pass_plain'] = !empty($cw['password_cifrado']) ? Security::decrypt($cw['password_cifrado']) : '';
    $cred_web[] = $cw;
}

$cuentas_m365_raw = $db->query("
    SELECT c.*, emp.nombre AS empleado_nombre, emp_comp.nombre AS empresa_nombre 
    FROM cuentas_m365 c 
    LEFT JOIN empleados emp ON c.empleado_id = emp.id 
    LEFT JOIN sucursales s ON emp.sucursal_id = s.id
    LEFT JOIN empresas emp_comp ON s.empresa_id = emp_comp.id
    ORDER BY c.fecha_proximo_pago ASC
")->fetchAll();

$cuentas_m365 = [];
foreach ($cuentas_m365_raw as $m365) {
    $m365['pass_plain'] = !empty($m365['password_cifrado']) ? Security::decrypt($m365['password_cifrado']) : '';
    $cuentas_m365[] = $m365;
}

// Ficha Consolidada – todo en uno por colaborador
$fichas_raw = $db->query("
    SELECT
        emp.id, emp.nombre, emp.cargo, emp.departamento, emp.estado AS emp_estado,
        s.nombre AS sucursal_nombre, emp_comp.nombre AS empresa_nombre,
        -- Equipo asignado
        eq.id AS equipo_id, eq.tipo AS eq_tipo, eq.marca AS eq_marca,
        eq.modelo AS eq_modelo, eq.service_tag_sn, eq.ip_direccion,
        eq.procesador, eq.ram, eq.disco,
        -- Credenciales del equipo
        ce.usuario_local, ce.password_cifrado AS eq_pass_enc,
        ce.clave_bitlocker AS eq_bitlocker_enc, ce.notas_acceso,
        -- M365
        m3.id AS m365_id, m3.correo AS m365_correo, m3.licencia_tipo,
        m3.password_cifrado AS m365_pass_enc, m3.estado AS m365_estado,
        m3.ciclo_facturacion, m3.fecha_proximo_pago
    FROM empleados emp
    LEFT JOIN sucursales s         ON emp.sucursal_id = s.id
    LEFT JOIN empresas emp_comp    ON s.empresa_id = emp_comp.id
    LEFT JOIN asignaciones a       ON a.empleado_id = emp.id AND a.fecha_devolucion IS NULL
    LEFT JOIN equipos eq           ON eq.id = a.equipo_id
    LEFT JOIN credenciales_equipos ce ON ce.equipo_id = eq.id
    LEFT JOIN cuentas_m365 m3      ON m3.empleado_id = emp.id AND m3.estado = 'Activa'
    WHERE emp.estado = 'Activo'
    ORDER BY emp.nombre
")->fetchAll();

// Cargar todas las credenciales personales de empleados activos
$cred_pers_raw = $db->query("
    SELECT cp.empleado_id, cp.tipo, cp.usuario, cp.password_cifrado 
    FROM credenciales_personales cp
    JOIN empleados e ON cp.empleado_id = e.id
    WHERE e.estado = 'Activo'
")->fetchAll();

$cred_pers_map = [];
foreach ($cred_pers_raw as $cp) {
    if (!empty($cp['password_cifrado'])) {
        try {
            $cp['password_clear'] = Security::decrypt($cp['password_cifrado']);
        } catch(Exception $e) {
            $cp['password_clear'] = '[Error]';
        }
    } else {
        $cp['password_clear'] = '';
    }
    $cred_pers_map[$cp['empleado_id']][] = $cp;
}

$fichas = [];
foreach ($fichas_raw as $f) {
    // Adjuntar las credenciales personales al arreglo de la ficha
    $f['credenciales_personales'] = $cred_pers_map[$f['id']] ?? [];
    
    $f['eq_pass_plain']      = !empty($f['eq_pass_enc'])      ? Security::decrypt($f['eq_pass_enc'])      : '';
    $f['eq_bitlocker_plain'] = !empty($f['eq_bitlocker_enc']) ? Security::decrypt($f['eq_bitlocker_enc']) : '';
    $f['m365_pass_plain']    = !empty($f['m365_pass_enc'])    ? Security::decrypt($f['m365_pass_enc'])    : '';
    $fichas[] = $f;
}
?>

<div class="space-y-6">
    <!-- Encabezado de Página -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h2 class="text-2xl font-bold text-slate-800 tracking-wide">Gestión de Credenciales y Licencias</h2>
            <p class="text-slate-500 text-sm">Almacén cifrado seguro para accesos locales, portales web y cuentas Microsoft 365.</p>
        </div>
        
        <!-- Botones de Acción según Pestaña -->
        <div>
            <?php if ($tab === 'equipos'): ?>
                <button onclick="abrirModal('modal-equipo')" class="px-4 py-2.5 rounded-xl grad-cyan-blue hover:opacity-90 text-slate-800 font-bold text-sm tracking-wide shadow-lg shadow-cyan-500/10 flex items-center gap-2">
                    <i class="fa-solid fa-plus"></i> Nueva Credencial Equipo
                </button>
            <?php elseif ($tab === 'web'): ?>
                <button onclick="abrirModal('modal-web')" class="px-4 py-2.5 rounded-xl grad-cyan-blue hover:opacity-90 text-slate-800 font-bold text-sm tracking-wide shadow-lg shadow-cyan-500/10 flex items-center gap-2">
                    <i class="fa-solid fa-plus"></i> Nueva Credencial Web
                </button>
            <?php elseif ($tab === 'm365'): ?>
                <button onclick="abrirModal('modal-m365')" class="px-4 py-2.5 rounded-xl grad-cyan-blue hover:opacity-90 text-slate-800 font-bold text-sm tracking-wide shadow-lg shadow-cyan-500/10 flex items-center gap-2">
                    <i class="fa-solid fa-plus"></i> Nueva Cuenta M365
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Pestañas (Tabs) de navegación de submódulos -->
    <div class="border-b border-slate-200 flex gap-1 flex-wrap">
        <a href="?tab=equipos" class="px-5 py-3 text-sm font-semibold border-b-2 transition flex items-center gap-2 <?= ($tab === 'equipos') ? 'border-cyan-400 text-slate-800 bg-white/5 rounded-t-lg' : 'border-transparent text-slate-500 hover:text-slate-800' ?>">
            <i class="fa-solid fa-desktop text-xs"></i> Credenciales de Equipos
        </a>
        <a href="?tab=web" class="px-5 py-3 text-sm font-semibold border-b-2 transition flex items-center gap-2 <?= ($tab === 'web') ? 'border-cyan-400 text-slate-800 bg-white/5 rounded-t-lg' : 'border-transparent text-slate-500 hover:text-slate-800' ?>">
            <i class="fa-solid fa-globe text-xs"></i> Credenciales Web y Portales
        </a>
        <a href="?tab=m365" class="px-5 py-3 text-sm font-semibold border-b-2 transition flex items-center gap-2 <?= ($tab === 'm365') ? 'border-cyan-400 text-slate-800 bg-white/5 rounded-t-lg' : 'border-transparent text-slate-500 hover:text-slate-800' ?>">
            <i class="fa-solid fa-cloud text-xs text-cyan-400"></i> Microsoft 365 / Facturación
        </a>
        <a href="?tab=fichas" class="px-5 py-3 text-sm font-semibold border-b-2 transition flex items-center gap-2 <?= ($tab === 'fichas') ? 'border-violet-500 text-slate-800 bg-white/5 rounded-t-lg' : 'border-transparent text-slate-500 hover:text-slate-800' ?>">
            <i class="fa-solid fa-id-card text-xs <?= ($tab === 'fichas') ? 'text-violet-600' : '' ?>"></i> Fichas Consolidadas
        </a>
    </div>

    <!-- Barra de Filtros Dinámicos (Persistente) -->
    <div class="glass-panel p-4 rounded-2xl flex flex-col md:flex-row gap-4 items-center justify-between border border-slate-200 bg-black/10">
        <div class="flex-1 w-full relative">
            <i class="fa-solid fa-magnifying-glass absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"></i>
            <input type="text" id="filtro-busqueda" onkeyup="aplicarFiltros()" placeholder="Buscar por cuenta, usuario, plataforma, equipo, departamento..." 
                   class="w-full bg-slate-100 border border-slate-200 rounded-xl py-2.5 pl-11 pr-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400 focus:ring-1 focus:ring-cyan-400 placeholder-gray-500 transition">
        </div>
        <div class="w-full md:w-64 relative">
            <i class="fa-solid fa-building absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"></i>
            <select id="filtro-empresa" onchange="aplicarFiltros()" 
                    class="w-full bg-slate-100 border border-slate-200 rounded-xl py-2.5 pl-11 pr-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400 focus:ring-1 focus:ring-cyan-400 transition appearance-none">
                <option value="">-- Filtrar por Empresa --</option>
                <?php 
                $empresas_filtro = $db->query("SELECT DISTINCT nombre FROM empresas ORDER BY nombre")->fetchAll(PDO::FETCH_COLUMN);
                foreach ($empresas_filtro as $emp_f): 
                ?>
                    <option value="<?= htmlspecialchars($emp_f) ?>"><?= htmlspecialchars($emp_f) ?></option>
                <?php endforeach; ?>
            </select>
            <i class="fa-solid fa-chevron-down absolute right-4 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none text-xs"></i>
        </div>
    </div>

    <!-- CONTENIDOS DE PESTAÑAS -->
    
    <!-- 1. TABLA CREDENCIALES EQUIPOS -->
    <?php if ($tab === 'equipos'): ?>
        <div class="glass-panel rounded-2xl overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left modern-table text-sm">
                    <thead>
                        <tr>
                            <th class="py-3 px-4">Equipo Asociado</th>
                            <th class="py-3 px-4">Usuario Local</th>
                            <th class="py-3 px-4">Contraseña Cifrada</th>
                            <th class="py-3 px-4">BitLocker Key</th>
                            <th class="py-3 px-4">Notas / Acceso</th>
                            <th class="py-3 px-4 text-center">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($cred_equipos)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-10 text-slate-400">
                                    No hay credenciales de equipos registradas.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($cred_equipos as $ce): ?>
                                <tr class="item-fila hover:bg-cyan-50/60 cursor-pointer transition border-b border-slate-100 group" data-empresa="<?= htmlspecialchars($ce['empresa_nombre'] ?? '') ?>" data-search-text="<?= htmlspecialchars(strtolower($ce['tipo'] . ' ' . $ce['marca'] . ' ' . $ce['modelo'] . ' ' . $ce['service_tag_sn'] . ' ' . $ce['usuario_local'] . ' ' . ($ce['notas_acceso'] ?? ''))) ?>" onclick='verDetalleCredEquipo(<?= json_encode($ce, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                    <td class="py-3 px-4">
                                        <div class="font-semibold text-slate-800 group-hover:text-cyan-700 transition flex items-center gap-1.5">
                                            <i class="fa-solid fa-laptop text-xs text-slate-400 group-hover:text-cyan-600"></i>
                                            <?= htmlspecialchars($ce['tipo'] . ' ' . $ce['marca'] . ' ' . $ce['modelo']) ?>
                                        </div>
                                        <div class="text-xs text-slate-400 font-mono">S/N: <?= htmlspecialchars($ce['service_tag_sn']) ?></div>
                                    </td>
                                    <td class="py-3 px-4 font-mono text-xs text-slate-700 font-bold">
                                        <?= htmlspecialchars($ce['usuario_local']) ?>
                                    </td>
                                    <td class="py-3 px-4" onclick="event.stopPropagation();">
                                        <div class="flex items-center gap-2">
                                            <span class="password-text text-xs text-slate-400 font-mono" data-password="<?= htmlspecialchars($ce['pass_plain']) ?>">••••••••</span>
                                            <button onclick="event.stopPropagation(); togglePasswordInline(this)" class="text-slate-500 hover:text-cyan-600 text-xs transition" title="Revelar Contraseña">
                                                <i class="fa-regular fa-eye"></i>
                                            </button>
                                            <button onclick="event.stopPropagation(); copyPasswordInline(this)" class="text-slate-500 hover:text-emerald-600 text-xs transition" title="Copiar Contraseña">
                                                <i class="fa-regular fa-copy"></i>
                                            </button>
                                        </div>
                                    </td>
                                    <td class="py-3 px-4" onclick="event.stopPropagation();">
                                        <?php if (!empty($ce['bitlocker_plain'])): ?>
                                            <div class="flex items-center gap-2">
                                                <span class="password-text text-xs text-slate-400 font-mono" data-password="<?= htmlspecialchars($ce['bitlocker_plain']) ?>">••••••••</span>
                                                <button onclick="event.stopPropagation(); togglePasswordInline(this)" class="text-slate-500 hover:text-cyan-600 text-xs transition" title="Revelar BitLocker">
                                                    <i class="fa-regular fa-eye"></i>
                                                </button>
                                                <button onclick="event.stopPropagation(); copyPasswordInline(this)" class="text-slate-500 hover:text-emerald-600 text-xs transition" title="Copiar BitLocker">
                                                    <i class="fa-regular fa-copy"></i>
                                                </button>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-xs text-slate-400">No aplica / N/A</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3 px-4 text-xs text-slate-500 max-w-[200px] truncate" title="<?= htmlspecialchars($ce['notas_acceso'] ?? '') ?>">
                                        <?= htmlspecialchars($ce['notas_acceso'] ?? '-') ?>
                                    </td>
                                    <td class="py-3 px-4 text-center">
                                        <div class="flex items-center justify-center gap-1" onclick="event.stopPropagation();">
                                            <button onclick='event.stopPropagation(); verDetalleCredEquipo(<?= json_encode($ce, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' class="h-8 w-8 rounded-lg hover:bg-cyan-50 flex items-center justify-center text-cyan-600 transition" title="Ver Detalle Completo">
                                                <i class="fa-solid fa-eye text-xs"></i>
                                            </button>
                                            <a href="guardar.php?action=delete_eq&id=<?= $ce['id'] ?>" onclick="event.stopPropagation(); return confirm('¿Seguro que deseas eliminar esta credencial?');" class="h-8 w-8 rounded-lg hover:bg-rose-50 flex items-center justify-center text-slate-400 hover:text-rose-500 transition" title="Eliminar">
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

    <!-- 2. TABLA CREDENCIALES WEB -->
    <?php elseif ($tab === 'web'): ?>
        <div class="glass-panel rounded-2xl overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left modern-table text-sm">
                    <thead>
                        <tr>
                            <th class="py-3 px-4">Plataforma / Portal</th>
                            <th class="py-3 px-4">Enlace</th>
                            <th class="py-3 px-4">Usuario</th>
                            <th class="py-3 px-4">Contraseña Cifrada</th>
                            <th class="py-3 px-4">Notas</th>
                            <th class="py-3 px-4 text-center">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($cred_web)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-10 text-slate-400">
                                    No hay credenciales web registradas.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($cred_web as $cw): ?>
                                <tr class="item-fila hover:bg-cyan-50/60 cursor-pointer transition border-b border-slate-100 group" data-empresa="" data-search-text="<?= htmlspecialchars(strtolower($cw['plataforma'] . ' ' . $cw['url_acceso'] . ' ' . $cw['usuario'] . ' ' . ($cw['notas'] ?? ''))) ?>" onclick='verDetalleCredWeb(<?= json_encode($cw, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                    <td class="py-3 px-4 font-semibold text-slate-800 group-hover:text-cyan-700 transition flex items-center gap-1.5">
                                        <i class="fa-solid fa-globe text-xs text-slate-400 group-hover:text-cyan-600"></i>
                                        <?= htmlspecialchars($cw['plataforma']) ?>
                                    </td>
                                    <td class="py-3 px-4 font-mono text-xs" onclick="event.stopPropagation();">
                                        <?php if (!empty($cw['url_acceso'])): ?>
                                            <a href="<?= htmlspecialchars($cw['url_acceso']) ?>" target="_blank" onclick="event.stopPropagation();" class="text-cyan-600 hover:underline inline-flex items-center max-w-[200px] truncate" title="<?= htmlspecialchars($cw['url_acceso']) ?>">
                                                <?= htmlspecialchars($cw['url_acceso']) ?> <i class="fa-solid fa-arrow-up-right-from-square text-[10px] shrink-0 ml-1"></i>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-slate-400">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3 px-4 font-mono text-xs text-slate-700 font-bold">
                                        <?= htmlspecialchars($cw['usuario']) ?>
                                    </td>
                                    <td class="py-3 px-4" onclick="event.stopPropagation();">
                                        <div class="flex items-center gap-2">
                                            <span class="password-text text-xs text-slate-400 font-mono" data-password="<?= htmlspecialchars($cw['pass_plain']) ?>">••••••••</span>
                                            <button onclick="event.stopPropagation(); togglePasswordInline(this)" class="text-slate-500 hover:text-cyan-600 text-xs transition" title="Revelar Contraseña">
                                                <i class="fa-regular fa-eye"></i>
                                            </button>
                                            <button onclick="event.stopPropagation(); copyPasswordInline(this)" class="text-slate-500 hover:text-emerald-600 text-xs transition" title="Copiar Contraseña">
                                                <i class="fa-regular fa-copy"></i>
                                            </button>
                                        </div>
                                    </td>
                                    <td class="py-3 px-4 text-xs text-slate-500 max-w-[250px] truncate" title="<?= htmlspecialchars($cw['notas'] ?? '') ?>">
                                        <?= htmlspecialchars($cw['notas'] ?? '-') ?>
                                    </td>
                                    <td class="py-3 px-4 text-center">
                                        <div class="flex items-center justify-center gap-1" onclick="event.stopPropagation();">
                                            <button onclick='event.stopPropagation(); verDetalleCredWeb(<?= json_encode($cw, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' class="h-8 w-8 rounded-lg hover:bg-cyan-50 flex items-center justify-center text-cyan-600 transition" title="Ver Detalle Completo">
                                                <i class="fa-solid fa-eye text-xs"></i>
                                            </button>
                                            <a href="guardar.php?action=delete_web&id=<?= $cw['id'] ?>" onclick="event.stopPropagation(); return confirm('¿Seguro que deseas eliminar esta credencial?');" class="h-8 w-8 rounded-lg hover:bg-rose-50 flex items-center justify-center text-slate-400 hover:text-rose-500 transition" title="Eliminar">
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

    <!-- 3. TABLA CUENTAS MICROSOFT 365 -->
    <?php elseif ($tab === 'm365'): ?>
        <div class="glass-panel rounded-2xl overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left modern-table text-sm">
                    <thead>
                        <tr>
                            <th class="py-3 px-4">Cuenta M365 (Correo)</th>
                            <th class="py-3 px-4">Licencia</th>
                            <th class="py-3 px-4">Usuario Asignado</th>
                            <th class="py-3 px-4">Contraseña</th>
                            <th class="py-3 px-4">Inicialización</th>
                            <th class="py-3 px-4">Ciclo</th>
                            <th class="py-3 px-4">Próxima Renovación</th>
                            <th class="py-3 px-4 text-center">Estado</th>
                            <th class="py-3 px-4 text-center">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($cuentas_m365)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-10 text-slate-400">
                                    No hay licencias M365 registradas.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($cuentas_m365 as $m365): 
                                // Calcular días para el pago
                                $fecha_pago = new DateTime($m365['fecha_proximo_pago']);
                                $hoy = new DateTime(date('Y-m-d'));
                                $intervalo = $hoy->diff($fecha_pago);
                                $dias = (int)$intervalo->format('%r%a');

                                // Clases de estado
                                if ($m365['estado'] == 'Activa') {
                                    $estado_badge = 'badge-success';
                                } elseif ($m365['estado'] == 'Suspendida') {
                                    $estado_badge = 'badge-pending';
                                } else {
                                    $estado_badge = 'badge-danger';
                                }

                                // Alertas de fecha de renovación
                                if ($dias <= 5 && $m365['estado'] == 'Activa') {
                                    $fecha_style = 'text-rose-500 font-bold';
                                    $dias_lbl = 'En ' . $dias . ' d (Urgente)';
                                } elseif ($dias <= 15 && $m365['estado'] == 'Activa') {
                                    $fecha_style = 'text-amber-600 font-bold';
                                    $dias_lbl = 'En ' . $dias . ' d';
                                } else {
                                    $fecha_style = 'text-slate-600';
                                    $dias_lbl = 'En ' . $dias . ' d';
                                }
                            ?>
                                <tr class="hover:bg-cyan-50/60 cursor-pointer transition border-b border-slate-100 group" onclick='verDetalleM365(<?= json_encode($m365, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                    <td class="py-3 px-4 font-semibold text-slate-800 group-hover:text-cyan-700 transition flex items-center gap-1.5">
                                        <i class="fa-solid fa-cloud text-xs text-cyan-500"></i>
                                        <?= htmlspecialchars($m365['correo']) ?>
                                    </td>
                                    <td class="py-3 px-4 text-xs text-slate-600">
                                        <?= htmlspecialchars($m365['licencia_tipo']) ?>
                                    </td>
                                    <td class="py-3 px-4 text-xs text-slate-700 font-medium">
                                        <?= htmlspecialchars($m365['empleado_nombre'] ?? 'Cuenta Compartida') ?>
                                    </td>
                                    <td class="py-3 px-4" onclick="event.stopPropagation();">
                                        <?php if (!empty($m365['pass_plain'])): ?>
                                            <div class="flex items-center gap-2">
                                                <span class="password-text text-xs text-slate-400 font-mono" data-password="<?= htmlspecialchars($m365['pass_plain']) ?>">••••••••</span>
                                                <button onclick="event.stopPropagation(); togglePasswordInline(this)" class="text-slate-500 hover:text-cyan-600 text-xs transition" title="Revelar Contraseña">
                                                    <i class="fa-regular fa-eye"></i>
                                                </button>
                                                <button onclick="event.stopPropagation(); copyPasswordInline(this)" class="text-slate-500 hover:text-emerald-600 text-xs transition" title="Copiar Contraseña">
                                                    <i class="fa-regular fa-copy"></i>
                                                </button>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-xs text-slate-400 font-normal">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3 px-4 text-xs text-slate-500">
                                        <?= date('d/m/Y', strtotime($m365['fecha_inicializacion'])) ?>
                                    </td>
                                    <td class="py-3 px-4">
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold <?= ($m365['ciclo_facturacion'] == 'Anual') ? 'bg-cyan-100 text-cyan-700' : 'bg-slate-100 text-slate-700' ?>">
                                            <?= $m365['ciclo_facturacion'] ?>
                                        </span>
                                    </td>
                                    <td class="py-3 px-4 text-xs">
                                        <div class="<?= $fecha_style ?>"><?= date('d/m/Y', strtotime($m365['fecha_proximo_pago'])) ?></div>
                                        <div class="text-[10px] text-slate-400"><?= $dias_lbl ?></div>
                                    </td>
                                    <td class="py-3 px-4 text-center">
                                        <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold <?= $estado_badge ?>">
                                            <?= $m365['estado'] ?>
                                        </span>
                                    </td>
                                    <td class="py-3 px-4 text-center">
                                        <div class="flex items-center justify-center gap-1" onclick="event.stopPropagation();">
                                            <!-- Ver Detalle Completo -->
                                            <button onclick='event.stopPropagation(); verDetalleM365(<?= json_encode($m365, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' class="h-8 w-8 rounded-lg hover:bg-cyan-50 flex items-center justify-center text-cyan-600 transition" title="Ver Detalle Completo">
                                                <i class="fa-solid fa-eye text-xs"></i>
                                            </button>
                                            <!-- Editar -->
                                            <button onclick="event.stopPropagation(); abrirEditarM365(<?= $m365['id'] ?>, '<?= htmlspecialchars($m365['correo'], ENT_QUOTES) ?>', '<?= htmlspecialchars($m365['licencia_tipo'], ENT_QUOTES) ?>', '<?= $m365['empleado_id'] ?? '' ?>', '<?= date('Y-m-d', strtotime($m365['fecha_inicializacion'])) ?>', '<?= htmlspecialchars($m365['ciclo_facturacion'], ENT_QUOTES) ?>', '<?= htmlspecialchars($m365['estado'], ENT_QUOTES) ?>')" class="h-8 w-8 rounded-lg hover:bg-cyan-50 flex items-center justify-center text-slate-400 hover:text-cyan-600 transition" title="Editar">
                                                <i class="fa-solid fa-pen text-xs"></i>
                                            </button>
                                            <!-- Eliminar -->
                                            <a href="guardar.php?action=delete_m365&id=<?= $m365['id'] ?>" onclick="event.stopPropagation(); return confirm('¿Seguro que deseas eliminar esta cuenta?');" class="h-8 w-8 rounded-lg hover:bg-rose-50 flex items-center justify-center text-slate-400 hover:text-rose-500 transition" title="Eliminar">
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
    <?php endif; ?>

    <!-- 4. TABLA GMAIL / CREDENCIALES POR COLABORADOR -->
    <?php if ($tab === 'gmail'): ?>
        <div class="glass-panel rounded-2xl overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left modern-table text-sm">
                    <thead>
                        <tr>
                            <th class="py-3 px-4">Colaborador</th>
                            <th class="py-3 px-4">Cargo / Departamento</th>
                            <th class="py-3 px-4">Sucursal</th>
                            <th class="py-3 px-4"><i class="fa-brands fa-google text-red-500 mr-1"></i> Correo Gmail</th>
                            <th class="py-3 px-4">Contraseña Gmail</th>
                            <th class="py-3 px-4"><i class="fa-solid fa-envelope text-blue-500 mr-1"></i> Correo Corporativo</th>
                            <th class="py-3 px-4 text-center">Más Info</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($cuentas_gmail)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-10 text-slate-400">
                                    No hay colaboradores activos registrados.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($cuentas_gmail as $g): ?>
                                <tr class="item-fila hover:bg-red-50/40 cursor-pointer transition border-b border-slate-100 group"
                                    data-empresa="<?= htmlspecialchars($g['empresa_nombre'] ?? '') ?>"
                                    data-search-text="<?= htmlspecialchars(strtolower($g['nombre'] . ' ' . $g['cargo'] . ' ' . $g['departamento'] . ' ' . ($g['correo_gmail'] ?? '') . ' ' . ($g['correo'] ?? '') . ' ' . $g['sucursal_nombre'])) ?>"
                                    onclick='verDetalleGmail(<?= json_encode($g, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>

                                    <td class="py-3 px-4">
                                        <div class="flex items-center gap-2">
                                            <div class="h-8 w-8 rounded-full bg-red-100 border border-red-200 flex items-center justify-center text-red-700 font-bold text-xs shrink-0">
                                                <?= strtoupper(substr($g['nombre'], 0, 1)) ?>
                                            </div>
                                            <span class="font-bold text-slate-800 group-hover:text-red-700 transition"><?= htmlspecialchars($g['nombre']) ?></span>
                                        </div>
                                    </td>
                                    <td class="py-3 px-4 text-xs text-slate-600">
                                        <div class="font-semibold text-slate-700"><?= htmlspecialchars($g['cargo'] ?? '-') ?></div>
                                        <span class="text-[10px] text-slate-400 font-medium"><?= htmlspecialchars($g['departamento'] ?? '') ?></span>
                                    </td>
                                    <td class="py-3 px-4 text-xs font-medium text-slate-600">
                                        <?= htmlspecialchars($g['sucursal_nombre'] ?? '-') ?>
                                    </td>
                                    <td class="py-3 px-4 text-xs font-mono font-bold text-slate-700">
                                        <?php if (!empty($g['correo_gmail'])): ?>
                                            <div class="flex items-center gap-1.5">
                                                <i class="fa-brands fa-google text-red-500 text-[10px]"></i>
                                                <?= htmlspecialchars($g['correo_gmail']) ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-slate-400 italic">Sin Gmail registrado</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3 px-4" onclick="event.stopPropagation();">
                                        <?php if (!empty($g['pass_gmail_plain'])): ?>
                                            <div class="flex items-center gap-2">
                                                <span class="password-text text-xs text-slate-400 font-mono" data-password="<?= htmlspecialchars($g['pass_gmail_plain']) ?>">••••••••</span>
                                                <button onclick="event.stopPropagation(); togglePasswordInline(this)" class="text-slate-500 hover:text-red-600 text-xs transition" title="Revelar Contraseña">
                                                    <i class="fa-regular fa-eye"></i>
                                                </button>
                                                <button onclick="event.stopPropagation(); copyPasswordInline(this)" class="text-slate-500 hover:text-emerald-600 text-xs transition" title="Copiar Contraseña">
                                                    <i class="fa-regular fa-copy"></i>
                                                </button>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-xs text-slate-400 italic">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3 px-4 text-xs font-mono text-slate-600">
                                        <?php if (!empty($g['correo'])): ?>
                                            <div class="flex items-center gap-1.5">
                                                <i class="fa-solid fa-envelope text-blue-500 text-[10px]"></i>
                                                <?= htmlspecialchars($g['correo']) ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-slate-400 italic">Sin correo</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3 px-4 text-center">
                                        <button onclick='event.stopPropagation(); verDetalleGmail(<?= json_encode($g, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' class="h-8 w-8 rounded-lg hover:bg-red-50 flex items-center justify-center text-red-500 transition mx-auto" title="Ver Todas las Credenciales">
                                            <i class="fa-solid fa-eye text-xs"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
    <?php if ($tab === 'fichas'): ?>
        <div class="glass-panel rounded-2xl overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left modern-table text-sm">
                    <thead>
                        <tr>
                            <th class="py-3 px-4">Colaborador</th>
                            <th class="py-3 px-4">Cargo / Departamento</th>
                            <th class="py-3 px-4">Sucursal</th>
                            <th class="py-3 px-4"><i class="fa-solid fa-laptop text-cyan-600 mr-1"></i> Equipo Asignado</th>
                            <th class="py-3 px-4"><i class="fa-solid fa-cloud text-emerald-500 mr-1"></i> M365</th>
                            <th class="py-3 px-4"><i class="fa-brands fa-google text-red-500 mr-1"></i> Gmail</th>
                            <th class="py-3 px-4 text-center">Ficha Completa</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($fichas)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-10 text-slate-400">
                                    No hay colaboradores activos registrados.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($fichas as $f): ?>
                                <tr class="item-fila hover:bg-violet-50/40 cursor-pointer transition border-b border-slate-100 group"
                                    data-empresa="<?= htmlspecialchars($f['empresa_nombre'] ?? '') ?>"
                                    data-search-text="<?= htmlspecialchars(strtolower($f['nombre'] . ' ' . $f['cargo'] . ' ' . $f['departamento'] . ' ' . ($f['correo_gmail'] ?? '') . ' ' . ($f['correo_corp'] ?? '') . ' ' . ($f['m365_correo'] ?? '') . ' ' . ($f['sucursal_nombre'] ?? ''))) ?>"
                                    onclick='verFichaConsolidada(<?= json_encode($f, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>

                                    <td class="py-3 px-4">
                                        <div class="flex items-center gap-2">
                                            <div class="h-9 w-9 rounded-full bg-violet-100 border border-violet-200 flex items-center justify-center text-violet-700 font-extrabold text-sm shrink-0">
                                                <?= strtoupper(substr($f['nombre'], 0, 1)) ?>
                                            </div>
                                            <span class="font-bold text-slate-800 group-hover:text-violet-700 transition"><?= htmlspecialchars($f['nombre']) ?></span>
                                        </div>
                                    </td>
                                    <td class="py-3 px-4 text-xs">
                                        <div class="font-semibold text-slate-700"><?= htmlspecialchars($f['cargo'] ?? '-') ?></div>
                                        <span class="text-[10px] text-slate-400"><?= htmlspecialchars($f['departamento'] ?? '') ?></span>
                                    </td>
                                    <td class="py-3 px-4 text-xs font-medium text-slate-600">
                                        <?= htmlspecialchars($f['sucursal_nombre'] ?? '-') ?>
                                    </td>
                                    <td class="py-3 px-4 text-xs">
                                        <?php if (!empty($f['equipo_id'])): ?>
                                            <div class="font-semibold text-slate-700 flex items-center gap-1">
                                                <i class="fa-solid fa-laptop text-cyan-600 text-[10px]"></i>
                                                <?= htmlspecialchars($f['eq_tipo'] . ' ' . $f['eq_marca'] . ' ' . $f['eq_modelo']) ?>
                                            </div>
                                            <span class="text-[10px] text-slate-400 font-mono"><?= htmlspecialchars($f['service_tag_sn'] ?? '') ?></span>
                                        <?php else: ?>
                                            <span class="text-slate-400 italic text-[11px]">Sin equipo asignado</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3 px-4 text-xs">
                                        <?php if (!empty($f['m365_correo'])): ?>
                                            <div class="font-mono text-emerald-700 font-bold text-[11px] flex items-center gap-1">
                                                <i class="fa-solid fa-cloud text-emerald-500 text-[9px]"></i>
                                                <?= htmlspecialchars($f['m365_correo']) ?>
                                            </div>
                                            <span class="text-[10px] text-slate-400"><?= htmlspecialchars($f['licencia_tipo'] ?? '') ?></span>
                                        <?php else: ?>
                                            <span class="text-slate-400 italic text-[11px]">Sin cuenta M365</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3 px-4 text-xs">
                                        <?php if (!empty($f['correo_gmail'])): ?>
                                            <div class="font-mono text-red-600 font-bold text-[11px] flex items-center gap-1">
                                                <i class="fa-brands fa-google text-[9px]"></i>
                                                <?= htmlspecialchars($f['correo_gmail']) ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-slate-400 italic text-[11px]">Sin Gmail</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3 px-4 text-center">
                                        <button onclick='event.stopPropagation(); verFichaConsolidada(<?= json_encode($f, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'
                                            class="h-8 w-8 rounded-lg hover:bg-violet-50 flex items-center justify-center text-violet-600 transition mx-auto" title="Abrir Ficha Completa">
                                            <i class="fa-solid fa-id-card text-xs"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

</div>


<!-- MODAL FICHA CONSOLIDADA -->
<div id="modal-ficha-consolidada" class="fixed inset-0 bg-slate-900/70 backdrop-blur-sm z-[200] flex items-center justify-center hidden p-4">
    <div class="bg-white border border-slate-200 rounded-3xl w-full max-w-2xl max-h-[90vh] overflow-hidden shadow-2xl flex flex-col">

        <!-- Header -->
        <div class="p-6 border-b border-slate-100 bg-gradient-to-r from-violet-50 to-white flex items-center justify-between shrink-0">
            <div class="flex items-center gap-3">
                <div class="h-12 w-12 rounded-2xl bg-violet-100 border border-violet-300 text-violet-700 flex items-center justify-center text-xl font-extrabold shrink-0" id="fc_initials">C</div>
                <div>
                    <h3 class="font-bold text-slate-800 text-lg" id="fc_nombre">Colaborador</h3>
                    <p class="text-xs text-slate-500 font-medium" id="fc_info">Cargo · Departamento · Sucursal</p>
                </div>
            </div>
            <button onclick="cerrarModal('modal-ficha-consolidada')" class="h-9 w-9 rounded-full hover:bg-slate-200 text-slate-400 hover:text-slate-700 flex items-center justify-center transition text-lg">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <!-- Body scrollable -->
        <div class="overflow-y-auto flex-1 p-6 space-y-5">

            <!-- EQUIPO ASIGNADO -->
            <div id="fc_bloque_equipo" class="border border-cyan-200 rounded-2xl overflow-hidden">
                <div class="bg-cyan-50 px-4 py-2.5 flex items-center gap-2 border-b border-cyan-200">
                    <i class="fa-solid fa-laptop text-cyan-600 text-sm"></i>
                    <span class="text-xs font-bold text-cyan-700 uppercase tracking-wider">Equipo Asignado</span>
                </div>
                <div class="p-4 grid grid-cols-2 md:grid-cols-3 gap-3 text-xs">
                    <div>
                        <span class="text-[11px] text-slate-400 block font-semibold">Tipo / Marca / Modelo</span>
                        <span class="font-bold text-slate-800" id="fc_eq_modelo">--</span>
                    </div>
                    <div>
                        <span class="text-[11px] text-slate-400 block font-semibold">Serie / S/N</span>
                        <span class="font-mono font-bold text-slate-700 select-all" id="fc_eq_sn">--</span>
                    </div>
                    <div>
                        <span class="text-[11px] text-slate-400 block font-semibold">Dirección IP</span>
                        <span class="font-mono font-bold text-slate-700" id="fc_eq_ip">--</span>
                    </div>
                    <div>
                        <span class="text-[11px] text-slate-400 block font-semibold">Procesador</span>
                        <span class="font-medium text-slate-600" id="fc_eq_cpu">--</span>
                    </div>
                    <div>
                        <span class="text-[11px] text-slate-400 block font-semibold">RAM / Disco</span>
                        <span class="font-medium text-slate-600" id="fc_eq_specs">--</span>
                    </div>
                    <div></div>
                    <div>
                        <span class="text-[11px] text-slate-400 block font-semibold">Usuario Local</span>
                        <span class="font-mono font-bold text-slate-800" id="fc_eq_usr">--</span>
                    </div>
                    <div class="col-span-2">
                        <span class="text-[11px] text-slate-400 block font-semibold mb-1">Contraseña Local</span>
                        <div class="flex gap-2">
                            <input type="password" readonly id="fc_eq_pass" class="bg-slate-100 border border-slate-200 rounded-lg px-2 py-1 text-xs font-mono flex-1 text-slate-800">
                            <button type="button" onclick="toggleFieldPass('fc_eq_pass','fc_eq_pass_ico')" class="px-2 py-1 rounded bg-slate-200 text-slate-500 hover:text-slate-800 text-xs">
                                <i class="fa-solid fa-eye" id="fc_eq_pass_ico"></i>
                            </button>
                        </div>
                    </div>
                    <div id="fc_row_bit" class="col-span-3">
                        <span class="text-[11px] text-slate-400 block font-semibold">Clave BitLocker</span>
                        <span class="font-mono font-bold text-slate-800 text-xs select-all bg-slate-100 px-2 py-1 rounded mt-0.5 inline-block" id="fc_bit">--</span>
                    </div>
                </div>
            </div>

            <!-- M365 -->
            <div id="fc_bloque_m365" class="border border-emerald-200 rounded-2xl overflow-hidden">
                <div class="bg-emerald-50 px-4 py-2.5 flex items-center gap-2 border-b border-emerald-200">
                    <i class="fa-solid fa-cloud text-emerald-600 text-sm"></i>
                    <span class="text-xs font-bold text-emerald-700 uppercase tracking-wider">Cuenta Microsoft 365</span>
                    <span class="ml-auto px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-700 border border-emerald-200" id="fc_m365_estado"></span>
                </div>
                <div class="p-4 grid grid-cols-2 gap-3 text-xs">
                    <div>
                        <span class="text-[11px] text-slate-400 block font-semibold">Correo M365</span>
                        <span class="font-mono font-bold text-slate-800" id="fc_m365_correo">--</span>
                    </div>
                    <div>
                        <span class="text-[11px] text-slate-400 block font-semibold">Tipo de Licencia</span>
                        <span class="font-bold text-slate-700" id="fc_m365_lic">--</span>
                    </div>
                    <div>
                        <span class="text-[11px] text-slate-400 block font-semibold">Ciclo / Renovación</span>
                        <span class="font-bold text-slate-700" id="fc_m365_ciclo">--</span>
                    </div>
                    <div>
                        <span class="text-[11px] text-slate-400 block font-semibold mb-1">Contraseña M365</span>
                        <div class="flex gap-2">
                            <input type="password" readonly id="fc_m365_pass" class="bg-slate-100 border border-slate-200 rounded-lg px-2 py-1 text-xs font-mono flex-1 text-slate-800">
                            <button type="button" onclick="toggleFieldPass('fc_m365_pass','fc_m365_pass_ico')" class="px-2 py-1 rounded bg-slate-200 text-slate-500 hover:text-slate-800 text-xs">
                                <i class="fa-solid fa-eye" id="fc_m365_pass_ico"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- GMAIL + CORREO CORPORATIVO + PC -->
            <div class="border border-red-100 rounded-2xl overflow-hidden">
                <div class="bg-red-50 px-4 py-2.5 flex items-center gap-2 border-b border-red-100">
                    <i class="fa-solid fa-key text-red-500 text-sm"></i>
                    <span class="text-xs font-bold text-red-600 uppercase tracking-wider">Cuentas Personales y Acceso</span>
                </div>
                <div class="p-4" id="fc_credenciales_container">
                    <!-- Contenido dinámico generado por Javascript -->
                </div>
            </div>

        </div>

        <!-- Footer -->
        <div class="p-4 border-t border-slate-100 bg-slate-50 flex justify-between items-center shrink-0">
            <a id="fc_link_emp" href="#" class="px-4 py-2 rounded-xl bg-violet-100 hover:bg-violet-200 text-violet-700 text-xs font-bold transition flex items-center gap-1.5">
                <i class="fa-solid fa-user"></i> Ver Ficha de Empleado
            </a>
            <button onclick="cerrarModal('modal-ficha-consolidada')" class="px-5 py-2 rounded-xl bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold transition shadow-md">
                Cerrar
            </button>
        </div>
    </div>
</div>

<!-- 1. Modal Credencial Equipo -->
<div id="modal-equipo" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-center justify-center hidden p-4">
    <div class="bg-white border border-slate-200 rounded-3xl w-full max-w-lg overflow-hidden shadow-2xl animate-fade-in">
        <div class="p-6 border-b border-slate-200 flex items-center justify-between">
            <h3 class="font-bold text-slate-800 flex items-center gap-2">
                <i class="fa-solid fa-desktop text-cyan-400"></i> Nueva Credencial de Equipo
            </h3>
            <button onclick="cerrarModal('modal-equipo')" class="text-slate-400 hover:text-slate-800"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form action="guardar.php?action=add_eq" method="POST" class="p-6 space-y-4">
            <div>
                <label for="eq_id" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Equipo Asociado</label>
                <select name="equipo_id" id="eq_id" required class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400">
                    <option value="" disabled selected>Selecciona un equipo de inventario...</option>
                    <?php foreach ($equipos as $eq): ?>
                        <option value="<?= $eq['id'] ?>"><?= htmlspecialchars("[{$eq['tipo']}] {$eq['marca']} {$eq['modelo']} (S/N: {$eq['service_tag_sn']})") ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="eq_usr" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Usuario Local</label>
                    <input type="text" name="usuario_local" id="eq_usr" required placeholder="admin_local" class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400">
                </div>
                <div>
                    <label for="eq_pass" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Contraseña</label>
                    <input type="password" name="password" id="eq_pass" required placeholder="••••••••" class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400">
                </div>
            </div>
            <div>
                <label for="eq_bit" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Clave de BitLocker (Opcional)</label>
                <input type="text" name="clave_bitlocker" id="eq_bit" placeholder="Ej: 432901-230984-..." class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm font-mono text-slate-800 focus:outline-none focus:border-cyan-400">
            </div>
            <div>
                <label for="eq_notes" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Notas de Acceso</label>
                <textarea name="notas_acceso" id="eq_notes" rows="2" placeholder="Indicaciones para acceder al terminal..." class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400"></textarea>
            </div>
            <div class="pt-4 flex gap-3">
                <button type="button" onclick="cerrarModal('modal-equipo')" class="flex-1 py-3 rounded-xl bg-white/5 hover:bg-white/10 text-slate-800 font-semibold text-sm transition border border-slate-200">Cancelar</button>
                <button type="submit" class="flex-1 py-3 rounded-xl grad-cyan-blue text-slate-800 font-bold text-sm tracking-wide shadow-lg shadow-cyan-500/20 hover:opacity-90 transition">Guardar Credencial</button>
            </div>
        </form>
    </div>
</div>

<!-- 2. Modal Credencial Web -->
<div id="modal-web" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-center justify-center hidden p-4">
    <div class="bg-white border border-slate-200 rounded-3xl w-full max-w-lg overflow-hidden shadow-2xl animate-fade-in">
        <div class="p-6 border-b border-slate-200 flex items-center justify-between">
            <h3 class="font-bold text-slate-800 flex items-center gap-2">
                <i class="fa-solid fa-globe text-cyan-400"></i> Nueva Credencial Web / Portal
            </h3>
            <button onclick="cerrarModal('modal-web')" class="text-slate-400 hover:text-slate-800"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form action="guardar.php?action=add_web" method="POST" class="p-6 space-y-4">
            <div>
                <label for="web_plat" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Plataforma / Nombre del Servicio</label>
                <input type="text" name="plataforma" id="web_plat" required placeholder="Ej: FortiCloud, Microsoft Partner Center" class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400">
            </div>
            <div>
                <label for="web_url" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">URL de Acceso</label>
                <input type="url" name="url_acceso" id="web_url" placeholder="https://admin.ejemplo.com" class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400">
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="web_usr" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Usuario / Email</label>
                    <input type="text" name="usuario" id="web_usr" required placeholder="soporte@empresa.com" class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400">
                </div>
                <div>
                    <label for="web_pass_inp" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Contraseña</label>
                    <input type="password" name="password" id="web_pass_inp" required placeholder="••••••••" class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400">
                </div>
            </div>
            <div>
                <label for="web_notes" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Notas descriptivas</label>
                <textarea name="notas" id="web_notes" rows="2" placeholder="Códigos MFA de recuperación, responsables, etc..." class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400"></textarea>
            </div>
            <div class="pt-4 flex gap-3">
                <button type="button" onclick="cerrarModal('modal-web')" class="flex-1 py-3 rounded-xl bg-white/5 hover:bg-white/10 text-slate-800 font-semibold text-sm transition border border-slate-200">Cancelar</button>
                <button type="submit" class="flex-1 py-3 rounded-xl grad-cyan-blue text-slate-800 font-bold text-sm tracking-wide shadow-lg shadow-cyan-500/20 hover:opacity-90 transition">Guardar Credencial</button>
            </div>
        </form>
    </div>
</div>

<!-- 3. Modal Cuenta Microsoft 365 -->
<div id="modal-m365" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-center justify-center hidden p-4">
    <div class="bg-white border border-slate-200 rounded-3xl w-full max-w-lg overflow-hidden shadow-2xl animate-fade-in">
        <div class="p-6 border-b border-slate-200 flex items-center justify-between">
            <h3 class="font-bold text-slate-800 flex items-center gap-2">
                <i class="fa-solid fa-cloud text-cyan-400"></i> Registrar Cuenta de Microsoft 365
            </h3>
            <button onclick="cerrarModal('modal-m365')" class="text-slate-400 hover:text-slate-800"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form action="guardar.php?action=add_m365" method="POST" class="p-6 space-y-4">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="m_email" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Correo Corporativo</label>
                    <input type="email" name="correo" id="m_email" required placeholder="usuario@empresa.com.ni" class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400">
                </div>
                <div>
                    <label for="m_pass" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Contraseña de Correo</label>
                    <input type="password" name="password" id="m_pass" required placeholder="••••••••" class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400">
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="m_lic" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Tipo de Licencia</label>
                    <select name="licencia_tipo" id="m_lic" required class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400">
                        <option value="Microsoft 365 Business Basic">M365 Business Basic</option>
                        <option value="Microsoft 365 Business Standard" selected>M365 Business Standard</option>
                        <option value="Microsoft 365 Business Premium">M365 Business Premium</option>
                        <option value="Exchange Online Plan 1">Exchange Online Plan 1</option>
                        <option value="Exchange Online Plan 2">Exchange Online Plan 2</option>
                    </select>
                </div>
                <div>
                    <label for="m_emp" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Empleado Asignado (Responsable)</label>
                    <select name="empleado_id" id="m_emp" class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400">
                        <option value="">-- Cuenta Compartida / Sin Asignar --</option>
                        <?php foreach ($empleados as $emp): ?>
                            <option value="<?= $emp['id'] ?>"><?= htmlspecialchars($emp['nombre'] . ' (' . $emp['cargo'] . ')') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="m_date" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Fecha de Inicialización</label>
                    <input type="date" name="fecha_inicializacion" id="m_date" required class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400">
                </div>
                <div>
                    <label for="m_cic" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Ciclo de Facturación</label>
                    <select name="ciclo_facturacion" id="m_cic" required class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400">
                        <option value="Mensual" selected>Mensual</option>
                        <option value="Anual">Anual</option>
                    </select>
                </div>
            </div>
            <div>
                <label for="m_est" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Estado</label>
                <select name="estado" id="m_est" required class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400">
                    <option value="Activa">Activa</option>
                    <option value="Suspendida">Suspendida</option>
                    <option value="Eliminada">Eliminada</option>
                </select>
            </div>
            <div class="bg-slate-100 border border-cyan-500/10 rounded-xl p-3 text-xs text-slate-500 leading-normal">
                <i class="fa-solid fa-calculator text-cyan-400 mr-1"></i>
                La <b>Fecha de Próximo Pago/Renovación</b> se calculará automáticamente en base a la fecha de inicialización y el ciclo seleccionado.
            </div>
            <div class="pt-4 flex gap-3">
                <button type="button" onclick="cerrarModal('modal-m365')" class="flex-1 py-3 rounded-xl bg-white/5 hover:bg-white/10 text-slate-800 font-semibold text-sm transition border border-slate-200">Cancelar</button>
                <button type="submit" class="flex-1 py-3 rounded-xl grad-cyan-blue text-slate-800 font-bold text-sm tracking-wide shadow-lg shadow-cyan-500/20 hover:opacity-90 transition">Guardar Cuenta</button>
            </div>
        </form>
    </div>
</div>

<!-- 4. Modal Editar M365 -->
<div id="modal-editar-m365" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-center justify-center hidden p-4">
    <div class="bg-white border border-slate-200 rounded-3xl w-full max-w-lg overflow-hidden shadow-2xl animate-fade-in">
        <div class="p-6 border-b border-slate-200 flex items-center justify-between">
            <h3 class="font-bold text-slate-800 flex items-center gap-2">
                <i class="fa-solid fa-cloud text-emerald-400"></i> Editar Cuenta M365
            </h3>
            <button onclick="cerrarModal('modal-editar-m365')" class="text-slate-400 hover:text-slate-800"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form action="guardar.php?action=edit_m365" method="POST" class="p-6 space-y-4">
            <input type="hidden" name="id" id="edit_m365_id">
            <div>
                <label for="edit_m365_correo" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Correo Corporativo</label>
                <input type="email" name="correo" id="edit_m365_correo" required class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400">
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="edit_m365_lic" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Tipo de Licencia</label>
                    <select name="licencia_tipo" id="edit_m365_lic" required class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400">
                        <option value="Microsoft 365 Business Basic">M365 Business Basic</option>
                        <option value="Microsoft 365 Business Standard">M365 Business Standard</option>
                        <option value="Microsoft 365 Business Premium">M365 Business Premium</option>
                        <option value="Exchange Online Plan 1">Exchange Online Plan 1</option>
                        <option value="Exchange Online Plan 2">Exchange Online Plan 2</option>
                    </select>
                </div>
                <div>
                    <label for="edit_m365_emp" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Empleado Asignado</label>
                    <select name="empleado_id" id="edit_m365_emp" class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400">
                        <option value="">-- Cuenta Compartida / Sin Asignar --</option>
                        <?php foreach ($empleados as $emp): ?>
                            <option value="<?= $emp['id'] ?>"><?= htmlspecialchars($emp['nombre'] . ' (' . $emp['cargo'] . ')') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="edit_m365_date" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Fecha de Inicialización</label>
                    <input type="date" name="fecha_inicializacion" id="edit_m365_date" required class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400">
                </div>
                <div>
                    <label for="edit_m365_cic" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Ciclo de Facturación</label>
                    <select name="ciclo_facturacion" id="edit_m365_cic" required class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400">
                        <option value="Mensual">Mensual</option>
                        <option value="Anual">Anual</option>
                    </select>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="edit_m365_est" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Estado</label>
                    <select name="estado" id="edit_m365_est" required class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400">
                        <option value="Activa">Activa</option>
                        <option value="Suspendida">Suspendida</option>
                        <option value="Eliminada">Eliminada</option>
                    </select>
                </div>
                <div>
                    <label for="edit_m365_pass" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Nueva Contraseña</label>
                    <input type="password" name="password" id="edit_m365_pass" placeholder="(Dejar en blanco para no cambiar)" class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400">
                </div>
            </div>
            <div class="pt-4 flex gap-3">
                <button type="button" onclick="cerrarModal('modal-editar-m365')" class="flex-1 py-3 rounded-xl bg-white/5 hover:bg-white/10 text-slate-800 font-semibold text-sm transition border border-slate-200">Cancelar</button>
                <button type="submit" class="flex-1 py-3 rounded-xl grad-cyan-blue text-slate-800 font-bold text-sm tracking-wide shadow-lg shadow-cyan-500/20 hover:opacity-90 transition">Guardar Cambios</button>
            </div>
        </form>
    </div>
</div>
<!-- Script específico para abrir/cerrar modales con transiciones -->
<script>
    function abrirEditarM365(id, correo, licencia, empleadoId, fecha, ciclo, estado) {
        document.getElementById('edit_m365_id').value = id;
        document.getElementById('edit_m365_correo').value = correo;
        document.getElementById('edit_m365_lic').value = licencia;
        document.getElementById('edit_m365_emp').value = empleadoId;
        document.getElementById('edit_m365_date').value = fecha;
        document.getElementById('edit_m365_cic').value = ciclo;
        document.getElementById('edit_m365_est').value = estado;
        document.getElementById('edit_m365_pass').value = '';
        abrirModal('modal-editar-m365');
    }

    function abrirModal(id) {
        const modal = document.getElementById(id);
        if (modal) {
            modal.classList.remove('hidden');
        }
    }

    function cerrarModal(id) {
        const modal = document.getElementById(id);
        if (modal) {
            modal.classList.add('hidden');
        }
    }

    function togglePasswordInline(btn) {
        const span = btn.parentElement.querySelector('.password-text');
        const plainPass = span.getAttribute('data-password');
        const icon = btn.querySelector('i');
        
        if (span.innerText === '••••••••') {
            span.innerText = plainPass;
            span.classList.remove('text-slate-400');
            span.classList.add('text-cyan-400');
            icon.className = 'fa-regular fa-eye-slash';
        } else {
            span.innerText = '••••••••';
            span.classList.remove('text-cyan-400');
            span.classList.add('text-slate-400');
            icon.className = 'fa-regular fa-eye';
        }
    }

    function copyPasswordInline(btn) {
        const span = btn.parentElement.querySelector('.password-text');
        const plainPass = span.getAttribute('data-password');
        const icon = btn.querySelector('i');
        
        navigator.clipboard.writeText(plainPass).then(() => {
            const originalClass = icon.className;
            icon.className = 'fa-solid fa-check text-emerald-400';
            setTimeout(() => {
                icon.className = originalClass;
            }, 1200);
        }).catch(err => {
            console.error('Error al copiar: ', err);
        });
    }

    // Filtros de búsqueda dinámicos y persistencia entre pestañas
    document.addEventListener('DOMContentLoaded', () => {
        const savedQuery = sessionStorage.getItem('filtro_query') || '';
        const savedEmpresa = sessionStorage.getItem('filtro_empresa') || '';
        
        const busquedaInput = document.getElementById('filtro-busqueda');
        const empresaSelect = document.getElementById('filtro-empresa');
        
        if (busquedaInput) busquedaInput.value = savedQuery;
        if (empresaSelect) empresaSelect.value = savedEmpresa;
        
        aplicarFiltros();
    });

    function aplicarFiltros() {
        const queryInput = document.getElementById('filtro-busqueda');
        const empresaSelect = document.getElementById('filtro-empresa');
        
        if (!queryInput || !empresaSelect) return;
        
        const query = queryInput.value.toLowerCase().trim();
        const empresa = empresaSelect.value;
        
        // Guardar para persistir el filtro al cambiar de pestaña
        sessionStorage.setItem('filtro_query', query);
        sessionStorage.setItem('filtro_empresa', empresa);
        
        const rows = document.querySelectorAll('.item-fila');
        rows.forEach(row => {
            const searchText = row.getAttribute('data-search-text') || '';
            const rowEmpresa = row.getAttribute('data-empresa') || '';
            
            const matchesSearch = searchText.includes(query);
            const matchesEmpresa = (empresa === '') || (rowEmpresa === empresa) || (rowEmpresa === '');
            
            if (matchesSearch && matchesEmpresa) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    }
</script>

<!-- Modal Detalle Credencial Equipo -->
<div id="modal-det-cred-equipo" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[100] flex items-center justify-center hidden p-4">
    <div class="bg-white border border-slate-200 rounded-3xl w-full max-w-lg overflow-hidden shadow-2xl animate-fade-in relative">
        <div class="p-6 border-b border-slate-100 bg-slate-50/80 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="h-10 w-10 rounded-xl bg-cyan-500/10 border border-cyan-500/20 text-cyan-600 flex items-center justify-center text-lg">
                    <i class="fa-solid fa-desktop"></i>
                </div>
                <div>
                    <h3 class="font-bold text-slate-800 text-base" id="dce_titulo">Credencial de Equipo</h3>
                    <p class="text-xs text-slate-500 font-mono" id="dce_sn">S/N: --</p>
                </div>
            </div>
            <button onclick="cerrarModal('modal-det-cred-equipo')" class="h-8 w-8 rounded-full hover:bg-slate-200 text-slate-400 hover:text-slate-700 flex items-center justify-center transition">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div class="p-6 space-y-4 text-sm">
            <div class="grid grid-cols-2 gap-3 bg-slate-50 p-4 rounded-2xl border border-slate-200">
                <div>
                    <span class="text-[11px] text-slate-400 block font-semibold">Usuario Local</span>
                    <span class="font-mono font-bold text-slate-800" id="dce_usr">--</span>
                </div>
                <div>
                    <span class="text-[11px] text-slate-400 block font-semibold">Contraseña</span>
                    <div class="flex items-center gap-2 mt-0.5">
                        <input type="password" readonly id="dce_pass" class="bg-white border border-slate-200 rounded-lg px-2 py-1 text-xs font-mono w-full text-slate-800">
                        <button type="button" onclick="toggleFieldPass('dce_pass', 'dce_pass_icon')" class="text-slate-500 hover:text-slate-800 text-xs px-2 py-1 rounded bg-slate-200">
                            <i class="fa-solid fa-eye" id="dce_pass_icon"></i>
                        </button>
                    </div>
                </div>
            </div>
            <div id="dce_row_bit" class="bg-slate-50 p-4 rounded-2xl border border-slate-200">
                <span class="text-[11px] text-slate-400 block font-semibold">Clave BitLocker</span>
                <span class="font-mono font-bold text-slate-800 text-xs select-all block mt-1 bg-white p-2 rounded border border-slate-200" id="dce_bit">--</span>
            </div>
            <div>
                <span class="text-xs font-bold text-slate-400 uppercase tracking-wider block mb-1">Notas de Acceso</span>
                <div class="bg-slate-50 p-3 rounded-xl border border-slate-200 text-xs text-slate-700 font-medium" id="dce_notas">--</div>
            </div>
        </div>
        <div class="p-4 border-t border-slate-100 bg-slate-50 flex justify-end">
            <button onclick="cerrarModal('modal-det-cred-equipo')" class="px-5 py-2 rounded-xl bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold transition shadow-md">
                Cerrar
            </button>
        </div>
    </div>
</div>

<!-- Modal Detalle Credencial Web -->
<div id="modal-det-cred-web" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[100] flex items-center justify-center hidden p-4">
    <div class="bg-white border border-slate-200 rounded-3xl w-full max-w-lg overflow-hidden shadow-2xl animate-fade-in relative">
        <div class="p-6 border-b border-slate-100 bg-slate-50/80 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="h-10 w-10 rounded-xl bg-cyan-500/10 border border-cyan-500/20 text-cyan-600 flex items-center justify-center text-lg">
                    <i class="fa-solid fa-globe"></i>
                </div>
                <div>
                    <h3 class="font-bold text-slate-800 text-base" id="dcw_titulo">Credencial Web</h3>
                    <p class="text-xs text-slate-500 font-mono" id="dcw_usr">--</p>
                </div>
            </div>
            <button onclick="cerrarModal('modal-det-cred-web')" class="h-8 w-8 rounded-full hover:bg-slate-200 text-slate-400 hover:text-slate-700 flex items-center justify-center transition">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div class="p-6 space-y-4 text-sm">
            <div class="bg-slate-50 p-4 rounded-2xl border border-slate-200 space-y-3">
                <div>
                    <span class="text-[11px] text-slate-400 block font-semibold">URL de Acceso</span>
                    <a id="dcw_url" href="#" target="_blank" class="text-cyan-600 font-mono text-xs hover:underline break-all">--</a>
                </div>
                <div class="grid grid-cols-2 gap-3 pt-2 border-t border-slate-200">
                    <div>
                        <span class="text-[11px] text-slate-400 block font-semibold">Usuario</span>
                        <span class="font-mono font-bold text-slate-800" id="dcw_usuario_val">--</span>
                    </div>
                    <div>
                        <span class="text-[11px] text-slate-400 block font-semibold">Contraseña</span>
                        <div class="flex items-center gap-2 mt-0.5">
                            <input type="password" readonly id="dcw_pass" class="bg-white border border-slate-200 rounded-lg px-2 py-1 text-xs font-mono w-full text-slate-800">
                            <button type="button" onclick="toggleFieldPass('dcw_pass', 'dcw_pass_icon')" class="text-slate-500 hover:text-slate-800 text-xs px-2 py-1 rounded bg-slate-200">
                                <i class="fa-solid fa-eye" id="dcw_pass_icon"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <div>
                <span class="text-xs font-bold text-slate-400 uppercase tracking-wider block mb-1">Notas Adicionales</span>
                <div class="bg-slate-50 p-3 rounded-xl border border-slate-200 text-xs text-slate-700 font-medium" id="dcw_notas">--</div>
            </div>
        </div>
        <div class="p-4 border-t border-slate-100 bg-slate-50 flex justify-end">
            <button onclick="cerrarModal('modal-det-cred-web')" class="px-5 py-2 rounded-xl bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold transition shadow-md">
                Cerrar
            </button>
        </div>
    </div>
</div>

<!-- Modal Detalle Cuenta M365 -->
<div id="modal-det-m365" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[100] flex items-center justify-center hidden p-4">
    <div class="bg-white border border-slate-200 rounded-3xl w-full max-w-lg overflow-hidden shadow-2xl animate-fade-in relative">
        <div class="p-6 border-b border-slate-100 bg-slate-50/80 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="h-10 w-10 rounded-xl bg-cyan-500/10 border border-cyan-500/20 text-cyan-600 flex items-center justify-center text-lg">
                    <i class="fa-solid fa-cloud"></i>
                </div>
                <div>
                    <h3 class="font-bold text-slate-800 text-base" id="dm3_correo">Cuenta M365</h3>
                    <p class="text-xs text-slate-500" id="dm3_licencia">--</p>
                </div>
            </div>
            <button onclick="cerrarModal('modal-det-m365')" class="h-8 w-8 rounded-full hover:bg-slate-200 text-slate-400 hover:text-slate-700 flex items-center justify-center transition">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div class="p-6 space-y-4 text-sm">
            <div class="bg-slate-50 p-4 rounded-2xl border border-slate-200 space-y-3">
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <span class="text-[11px] text-slate-400 block font-semibold">Usuario Asignado</span>
                        <span class="font-bold text-slate-800" id="dm3_emp">--</span>
                    </div>
                    <div>
                        <span class="text-[11px] text-slate-400 block font-semibold">Estado Cuenta</span>
                        <span class="font-bold text-emerald-600" id="dm3_est">--</span>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3 pt-2 border-t border-slate-200">
                    <div>
                        <span class="text-[11px] text-slate-400 block font-semibold">Ciclo de Facturación</span>
                        <span class="font-bold text-slate-800" id="dm3_ciclo">--</span>
                    </div>
                    <div>
                        <span class="text-[11px] text-slate-400 block font-semibold">Contraseña</span>
                        <div class="flex items-center gap-2 mt-0.5">
                            <input type="password" readonly id="dm3_pass" class="bg-white border border-slate-200 rounded-lg px-2 py-1 text-xs font-mono w-full text-slate-800">
                            <button type="button" onclick="toggleFieldPass('dm3_pass', 'dm3_pass_icon')" class="text-slate-500 hover:text-slate-800 text-xs px-2 py-1 rounded bg-slate-200">
                                <i class="fa-solid fa-eye" id="dm3_pass_icon"></i>
                            </button>
                        </div>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3 pt-2 border-t border-slate-200 text-xs">
                    <div>
                        <span class="text-[11px] text-slate-400 block font-semibold">Fecha Inicialización</span>
                        <span class="font-medium text-slate-800" id="dm3_f_init">--</span>
                    </div>
                    <div>
                        <span class="text-[11px] text-slate-400 block font-semibold">Próxima Renovación</span>
                        <span class="font-bold text-slate-800" id="dm3_f_pago">--</span>
                    </div>
                </div>
            </div>
        </div>
        <div class="p-4 border-t border-slate-100 bg-slate-50 flex justify-end">
            <button onclick="cerrarModal('modal-det-m365')" class="px-5 py-2 rounded-xl bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold transition shadow-md">
                Cerrar
            </button>
        </div>
    </div>
</div>

<script>
    function verDetalleCredEquipo(ce) {
        document.getElementById('dce_titulo').innerText = (ce.tipo || '') + ' ' + (ce.marca || '') + ' ' + (ce.modelo || '');
        document.getElementById('dce_sn').innerText = 'S/N: ' + (ce.service_tag_sn || 'N/A');
        document.getElementById('dce_usr').innerText = ce.usuario_local || 'admin_local';
        document.getElementById('dce_pass').value = ce.pass_plain || '';
        document.getElementById('dce_pass').type = 'password';
        document.getElementById('dce_pass_icon').className = 'fa-solid fa-eye';

        const rowBit = document.getElementById('dce_row_bit');
        if (ce.bitlocker_plain) {
            rowBit.classList.remove('hidden');
            document.getElementById('dce_bit').innerText = ce.bitlocker_plain;
        } else {
            rowBit.classList.add('hidden');
        }

        document.getElementById('dce_notas').innerText = ce.notas_acceso || 'Sin notas de acceso.';
        abrirModal('modal-det-cred-equipo');
    }

    function verDetalleCredWeb(cw) {
        document.getElementById('dcw_titulo').innerText = cw.plataforma || 'Credencial Web';
        document.getElementById('dcw_usr').innerText = cw.usuario || '';
        document.getElementById('dcw_url').innerText = cw.url_acceso || 'Sin URL';
        document.getElementById('dcw_url').href = cw.url_acceso || '#';
        document.getElementById('dcw_usuario_val').innerText = cw.usuario || '';
        document.getElementById('dcw_pass').value = cw.pass_plain || '';
        document.getElementById('dcw_pass').type = 'password';
        document.getElementById('dcw_pass_icon').className = 'fa-solid fa-eye';
        document.getElementById('dcw_notas').innerText = cw.notas || 'Sin notas adicionales.';
        abrirModal('modal-det-cred-web');
    }

    function verDetalleM365(m3) {
        document.getElementById('dm3_correo').innerText = m3.correo || 'Cuenta M365';
        document.getElementById('dm3_licencia').innerText = m3.licencia_tipo || '';
        document.getElementById('dm3_emp').innerText = m3.empleado_nombre || 'Cuenta Compartida / Generica';
        document.getElementById('dm3_est').innerText = m3.estado || 'Activa';
        document.getElementById('dm3_ciclo').innerText = m3.ciclo_facturacion || 'Mensual';
        document.getElementById('dm3_pass').value = m3.pass_plain || '';
        document.getElementById('dm3_pass').type = 'password';
        document.getElementById('dm3_pass_icon').className = 'fa-solid fa-eye';
        document.getElementById('dm3_f_init').innerText = m3.fecha_inicializacion || '--';
        document.getElementById('dm3_f_pago').innerText = m3.fecha_proximo_pago || '--';
        abrirModal('modal-det-m365');
    }

    function toggleFieldPass(inputId, iconId) {
        const inp = document.getElementById(inputId);
        const ico = document.getElementById(iconId);
        if (inp.type === 'password') {
            inp.type = 'text';
            ico.className = 'fa-solid fa-eye-slash';
        } else {
            inp.type = 'password';
            ico.className = 'fa-solid fa-eye';
        }
    }

    function verDetalleGmail(g) {
        document.getElementById('dg_nombre').innerText = g.nombre || '';
        document.getElementById('dg_cargo').innerText = (g.cargo || '') + ' · ' + (g.departamento || '') + ' · ' + (g.sucursal_nombre || '');
        document.getElementById('dg_initials').innerText = (g.nombre || 'C').charAt(0).toUpperCase();

        // Gmail
        document.getElementById('dg_gmail').innerText = g.correo_gmail || 'Sin Gmail registrado';
        document.getElementById('dg_pass_gmail').value = g.pass_gmail_plain || '';
        document.getElementById('dg_pass_gmail').type = 'password';
        document.getElementById('dg_pass_gmail_icon').className = 'fa-solid fa-eye';

        // Correo corporativo
        const rowCorrCorp = document.getElementById('dg_row_corp');
        if (g.correo) {
            rowCorrCorp.classList.remove('hidden');
            document.getElementById('dg_correo_corp').innerText = g.correo;
            document.getElementById('dg_pass_correo').value = g.pass_correo_plain || '';
            document.getElementById('dg_pass_correo').type = 'password';
            document.getElementById('dg_pass_correo_icon').className = 'fa-solid fa-eye';
        } else {
            rowCorrCorp.classList.add('hidden');
        }

        // PC PIN y Pass AG
        document.getElementById('dg_pc_pin').innerText = g.pc_pin || 'N/A';
        document.getElementById('dg_pass_ag').value = g.pass_ag_plain || '';
        document.getElementById('dg_pass_ag').type = 'password';
        document.getElementById('dg_pass_ag_icon').className = 'fa-solid fa-eye';

        abrirModal('modal-det-gmail');
    }

    function verFichaConsolidada(f) {
        // Header
        document.getElementById('fc_initials').innerText = (f.nombre || 'C').charAt(0).toUpperCase();
        document.getElementById('fc_nombre').innerText = f.nombre || 'Colaborador';
        document.getElementById('fc_info').innerText = (f.cargo || '') + ' · ' + (f.departamento || '') + ' · ' + (f.sucursal_nombre || '');
        document.getElementById('fc_link_emp').href = '../empleados/index.php?details_id=' + f.id;

        // Equipo asignado
        const bloqueEq = document.getElementById('fc_bloque_equipo');
        if (f.equipo_id) {
            bloqueEq.classList.remove('hidden');
            document.getElementById('fc_eq_modelo').innerText = (f.eq_tipo || '') + ' ' + (f.eq_marca || '') + ' ' + (f.eq_modelo || '');
            document.getElementById('fc_eq_sn').innerText  = f.service_tag_sn || 'S/N no registrado';
            document.getElementById('fc_eq_ip').innerText  = f.ip_direccion || 'Sin IP';
            document.getElementById('fc_eq_cpu').innerText = f.procesador || '--';
            document.getElementById('fc_eq_specs').innerText = (f.ram || '--') + ' RAM / ' + (f.disco || '--');
            document.getElementById('fc_eq_usr').innerText = f.usuario_local || 'Sin usuario local';
            document.getElementById('fc_eq_pass').value    = f.eq_pass_plain || '';
            document.getElementById('fc_eq_pass').type     = 'password';
            document.getElementById('fc_eq_pass_ico').className = 'fa-solid fa-eye';
            const rowBit = document.getElementById('fc_row_bit');
            if (f.eq_bitlocker_plain) {
                rowBit.classList.remove('hidden');
                document.getElementById('fc_bit').innerText = f.eq_bitlocker_plain;
            } else {
                rowBit.classList.add('hidden');
            }
        } else {
            bloqueEq.classList.add('hidden');
        }

        // M365
        const bloqueM365 = document.getElementById('fc_bloque_m365');
        if (f.m365_correo) {
            bloqueM365.classList.remove('hidden');
            document.getElementById('fc_m365_correo').innerText = f.m365_correo || '--';
            document.getElementById('fc_m365_lic').innerText    = f.licencia_tipo || '--';
            document.getElementById('fc_m365_ciclo').innerText  = (f.ciclo_facturacion || '--') + ' · Próximo pago: ' + (f.fecha_proximo_pago || '--');
            document.getElementById('fc_m365_estado').innerText = f.m365_estado || '';
            document.getElementById('fc_m365_pass').value       = f.m365_pass_plain || '';
            document.getElementById('fc_m365_pass').type        = 'password';
            document.getElementById('fc_m365_pass_ico').className = 'fa-solid fa-eye';
        } else {
            bloqueM365.classList.add('hidden');
        }

        // Credenciales Personales
        const container = document.getElementById('fc_credenciales_container');
        container.innerHTML = '';
        if (f.credenciales_personales && f.credenciales_personales.length > 0) {
            f.credenciales_personales.forEach((cred, idx) => {
                let iconHtml = '<i class="fa-solid fa-key text-slate-400"></i>';
                if (cred.tipo === 'Gmail') iconHtml = '<i class="fa-brands fa-google text-red-500"></i>';
                if (cred.tipo === 'Correo Corporativo') iconHtml = '<i class="fa-solid fa-envelope text-blue-500"></i>';
                if (cred.tipo === 'PC PIN') iconHtml = '<i class="fa-solid fa-desktop text-slate-500"></i>';
                if (cred.tipo === 'Pass AG') iconHtml = '<i class="fa-solid fa-server text-indigo-500"></i>';

                const passId = 'fc_pass_dyn_' + idx;
                const passIconId = 'fc_pass_ico_dyn_' + idx;

                container.innerHTML += `
                    <div class="bg-slate-50 border border-slate-200 p-4 rounded-2xl space-y-3">
                        <div class="flex items-center gap-2 text-xs font-bold text-slate-600 uppercase tracking-wider mb-1">
                            ${iconHtml} ${cred.tipo}
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <div>
                                <span class="text-[11px] text-slate-400 block font-semibold">Usuario / ID</span>
                                <span class="font-mono font-bold text-slate-800 text-sm">${cred.usuario || 'N/A'}</span>
                            </div>
                            <div>
                                <span class="text-[11px] text-slate-400 block font-semibold mb-1">Contraseña / PIN</span>
                                <div class="flex items-center gap-2">
                                    <input type="password" readonly id="${passId}" value="${cred.password_clear || ''}" class="bg-white border border-slate-200 rounded-lg px-3 py-1.5 text-xs font-mono w-full text-slate-800">
                                    <button type="button" onclick="toggleFieldPass('${passId}', '${passIconId}')" class="text-slate-500 hover:text-slate-800 text-xs px-2 py-1.5 rounded bg-slate-200 whitespace-nowrap">
                                        <i class="fa-solid fa-eye" id="${passIconId}"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            });
        } else {
            container.innerHTML = `
                <div class="text-xs text-slate-400 italic bg-slate-100 border border-slate-200 rounded-2xl p-4">
                    Ninguna credencial personal registrada.
                </div>
            `;
        }

        abrirModal('modal-ficha-consolidada');
    }
</script>


<!-- Modal Detalle Gmail / Credenciales Colaborador -->
<div id="modal-det-gmail" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[100] flex items-center justify-center hidden p-4">
    <div class="bg-white border border-slate-200 rounded-3xl w-full max-w-lg overflow-hidden shadow-2xl animate-fade-in relative">
        <!-- Header -->
        <div class="p-6 border-b border-slate-100 flex items-center justify-between bg-gradient-to-r from-red-50 to-white">
            <div class="flex items-center gap-3">
                <div class="h-11 w-11 rounded-2xl bg-red-100 border border-red-200 text-red-700 flex items-center justify-center text-lg shrink-0 font-extrabold" id="dg_initials">C</div>
                <div>
                    <h3 class="font-bold text-slate-800 text-base" id="dg_nombre">Colaborador</h3>
                    <p class="text-xs text-slate-500" id="dg_cargo">Cargo · Departamento · Sucursal</p>
                </div>
            </div>
            <button onclick="cerrarModal('modal-det-gmail')" class="h-8 w-8 rounded-full hover:bg-slate-200 text-slate-400 hover:text-slate-700 flex items-center justify-center transition">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <!-- Body -->
        <div class="p-6 space-y-4 text-sm">

            <div id="fc_credenciales_container" class="space-y-4">
                <!-- Se llenará dinámicamente -->
            </div>
        </div>

        <!-- Footer -->
        <div class="p-4 border-t border-slate-100 bg-slate-50 flex justify-end">
            <button onclick="cerrarModal('modal-det-gmail')" class="px-5 py-2 rounded-xl bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold transition shadow-md">
                Cerrar
            </button>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/../../includes/footer.php';
?>
