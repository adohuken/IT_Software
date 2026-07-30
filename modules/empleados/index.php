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
    }
}

$sql_empleados = "
    SELECT emp.*, s.nombre AS sucursal_nombre,
           (SELECT COUNT(*) FROM asignaciones WHERE empleado_id = emp.id AND fecha_devolucion IS NULL) AS cant_equipos,
           (SELECT GROUP_CONCAT(CONCAT(eq.id, ':', eq.tipo, ' - ', eq.marca, ' ', eq.modelo, ' (S/N: ', eq.service_tag_sn, ')') SEPARATOR '|') 
            FROM asignaciones a JOIN equipos eq ON a.equipo_id = eq.id 
            WHERE a.empleado_id = emp.id AND a.fecha_devolucion IS NULL) AS equipos_activos,
           (SELECT GROUP_CONCAT(CONCAT(eq.tipo, ' - ', eq.marca, ' ', eq.modelo, ' (S/N: ', eq.service_tag_sn, ')') SEPARATOR ', ')
            FROM asignaciones a 
            JOIN equipos eq ON a.equipo_id = eq.id 
            WHERE a.empleado_id = emp.id AND a.fecha_devolucion IS NULL) AS equipo_vinculado,
           (SELECT correo FROM cuentas_m365 WHERE empleado_id = emp.id AND estado = 'Activa' LIMIT 1) AS correo_m365,
           (SELECT licencia_tipo FROM cuentas_m365 WHERE empleado_id = emp.id AND estado = 'Activa' LIMIT 1) AS licencia_m365,
           (SELECT password_cifrado FROM credenciales_personales WHERE empleado_id = emp.id AND tipo = 'PC PIN' LIMIT 1) AS pc_pin_cifrado,
           (SELECT password_cifrado FROM credenciales_personales WHERE empleado_id = emp.id AND tipo = 'Pass AG' LIMIT 1) AS pass_ag_cifrado,
           (SELECT usuario FROM credenciales_personales WHERE empleado_id = emp.id AND tipo = 'Gmail' LIMIT 1) AS correo_gmail,
           (SELECT password_cifrado FROM credenciales_personales WHERE empleado_id = emp.id AND tipo = 'Gmail' LIMIT 1) AS password_gmail_cifrado
    FROM empleados emp
    JOIN sucursales s ON emp.sucursal_id = s.id
    WHERE emp.estado = 'Activo'
    ORDER BY emp.nombre ASC
";
$empleados = $db->query($sql_empleados)->fetchAll();

foreach ($empleados as &$eRow) {
    $eRow['pc_pin'] = !empty($eRow['pc_pin_cifrado']) ? Security::decrypt($eRow['pc_pin_cifrado']) : '';
    $eRow['pass_gmail_plain'] = !empty($eRow['password_gmail_cifrado']) ? Security::decrypt($eRow['password_gmail_cifrado']) : '';
    $eRow['pass_ag_plain'] = !empty($eRow['pass_ag_cifrado']) ? Security::decrypt($eRow['pass_ag_cifrado']) : '';
}
unset($eRow);

// 2. Cargar catálogos para el modal
$sucursales = $db->query("SELECT * FROM sucursales ORDER BY nombre")->fetchAll();
$empresas = $db->query("SELECT * FROM empresas ORDER BY nombre")->fetchAll();
$equipos_disponibles = $db->query("SELECT id, tipo, marca, modelo, service_tag_sn FROM equipos WHERE estado = 'Disponible' ORDER BY tipo, marca")->fetchAll();
?>

<div class="space-y-6">
    <!-- Encabezado de Página -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h2 class="text-2xl font-bold text-slate-800 tracking-wide">Colaboradores & Onboarding IT</h2>
            <p class="text-slate-500 text-sm">Control de personal, asignación de recursos y flujos de Onboarding IT.</p>
        </div>
        <div>
            <button onclick="abrirModal('modal-onboarding')" class="px-4 py-2.5 rounded-xl bg-emerald-500 hover:bg-emerald-600 text-white font-bold text-sm tracking-wide shadow-lg shadow-emerald-500/10 flex items-center gap-2 transition">
                <i class="fa-solid fa-user-plus"></i> Iniciar Onboarding IT
            </button>
        </div>
    </div>

    <!-- Tabla General de Personal y Recursos -->
    <div class="glass-panel rounded-2xl overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left modern-table text-sm">
                <thead>
                    <tr>
                        <th class="py-3 px-4">Colaborador</th>
                        <th class="py-3 px-4">Cargo / Departamento</th>
                        <th class="py-3 px-4">Sucursal</th>
                        <th class="py-3 px-4">Equipo Vinculado</th>
                        <th class="py-3 px-4">Cuenta M365 Activa</th>
                        <th class="py-3 px-4 text-center">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($empleados)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-10 text-slate-400">
                                No se encontraron empleados registrados en el sistema.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($empleados as $emp): ?>
                            <tr class="hover:bg-emerald-50/60 cursor-pointer transition border-b border-slate-100 group" onclick='verDetalleEmpleado(<?= json_encode($emp, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                <td class="py-4 px-4 font-bold text-slate-800 group-hover:text-emerald-700 transition flex items-center gap-2">
                                    <div class="h-8 w-8 rounded-full bg-emerald-100 border border-emerald-300 flex items-center justify-center text-emerald-700 text-xs font-bold shrink-0">
                                        <?= strtoupper(substr($emp['nombre'], 0, 1)) ?>
                                    </div>
                                    <?= htmlspecialchars($emp['nombre']) ?>
                                </td>
                                <td class="py-4 px-4 text-xs text-slate-600">
                                    <div class="font-semibold text-slate-700"><?= htmlspecialchars($emp['cargo']) ?></div>
                                    <span class="text-[10px] text-slate-400 font-medium"><?= htmlspecialchars($emp['departamento']) ?></span>
                                </td>
                                <td class="py-4 px-4 text-xs font-medium text-slate-600">
                                    <?= htmlspecialchars($emp['sucursal_nombre']) ?>
                                </td>
                                <td class="py-4 px-4 text-xs">
                                     <?php if ($emp['cant_equipos'] > 0): ?>
                                         <div class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-cyan-50 border border-cyan-200 text-cyan-700 font-bold text-xs" title="<?= htmlspecialchars($emp['equipo_vinculado']) ?>">
                                             <i class="fa-solid fa-laptop text-cyan-600 text-xs shrink-0"></i>
                                             <span><?= $emp['cant_equipos'] ?> <?= $emp['cant_equipos'] == 1 ? 'Equipo' : 'Equipos' ?></span>
                                         </div>
                                     <?php else: ?>
                                         <a href="../equipos/index.php" onclick="event.stopPropagation();" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-slate-50 border border-slate-200 text-slate-500 font-medium text-xs">
                                             <i class="fa-solid fa-link-slash text-slate-400 text-xs shrink-0"></i>
                                             <span>Sin equipos</span>
                                         </a>
                                     <?php endif; ?>
                                </td>
                                <td class="py-4 px-4 text-xs">
                                    <?php if (!empty($emp['correo_m365'])): ?>
                                        <div class="text-slate-700">
                                            <div class="font-bold text-slate-800 flex items-center gap-1.5">
                                                <i class="fa-solid fa-cloud text-emerald-500"></i>
                                                <?= htmlspecialchars($emp['correo_m365']) ?>
                                            </div>
                                            <span class="text-[10px] text-slate-400 font-medium"><?= htmlspecialchars($emp['licencia_m365']) ?></span>
                                        </div>
                                    <?php else: ?>
                                        <a href="../credenciales/index.php?tab=m365" onclick="event.stopPropagation();" class="text-slate-400 hover:text-emerald-600 flex items-center gap-1">
                                            <i class="fa-solid fa-link-slash"></i> Sin cuenta asociada
                                        </a>
                                    <?php endif; ?>
                                </td>
                                <td class="py-4 px-4 text-center">
                                    <div class="flex items-center justify-center gap-1" onclick="event.stopPropagation();">
                                        <!-- Ver Ficha de Credenciales (URL) -->
                                        <a href="?details_id=<?= $emp['id'] ?>" onclick="event.stopPropagation();" class="h-8 w-8 rounded-lg hover:bg-slate-100 flex items-center justify-center text-slate-500 hover:text-emerald-600 transition" title="Ficha de Credenciales Cifradas">
                                            <i class="fa-solid fa-key text-xs"></i>
                                        </a>
                                        <!-- Editar Empleado -->
                                        <button onclick='event.stopPropagation(); abrirEditarEmpleado(<?= json_encode($emp, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' class="h-8 w-8 rounded-lg hover:bg-cyan-50 flex items-center justify-center text-slate-500 hover:text-cyan-600 transition" title="Editar Empleado">
                                            <i class="fa-solid fa-pen text-xs"></i>
                                        </button>
                                        <!-- Dar de Baja -->
                                        <button onclick="event.stopPropagation(); confirmarBajaEmpleado('guardar.php?action=baja&id=<?= $emp['id'] ?>')" class="h-8 w-8 rounded-lg hover:bg-rose-50 flex items-center justify-center text-slate-400 hover:text-rose-500 transition" title="Dar de Baja Colaborador">
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
                            <?php foreach ($sucursales as $s): ?>
                                <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['nombre']) ?></option>
                            <?php endforeach; ?>
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
<!-- Modal de Ficha de Credenciales del Colaborador -->
<div id="modal-ficha-detalles" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-center justify-center p-4 overflow-y-auto">
    <div class="bg-white border border-emerald-500/20 rounded-3xl w-full max-w-xl overflow-hidden shadow-2xl animate-fade-in my-8">
        
        <div class="p-6 border-b border-slate-200 flex items-center justify-between">
            <h3 class="font-bold text-slate-800 flex items-center gap-2">
                <i class="fa-solid fa-id-card text-emerald-400"></i> Ficha de Credenciales IT
            </h3>
            <a href="index.php" class="text-slate-400 hover:text-slate-800"><i class="fa-solid fa-xmark"></i></a>
        </div>

        <div class="p-6 space-y-6">
            <!-- Información General -->
            <div class="flex items-center gap-4 bg-white/5 p-4 rounded-2xl border border-slate-200">
                <div class="h-12 w-12 rounded-xl bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center text-emerald-400 font-bold text-lg">
                    <?= strtoupper(substr($ficha_empleado['nombre'], 0, 2)) ?>
                </div>
                <div>
                    <h4 class="font-bold text-slate-800"><?= htmlspecialchars($ficha_empleado['nombre']) ?></h4>
                    <p class="text-xs text-slate-500"><?= htmlspecialchars($ficha_empleado['cargo'] ?? '') ?> &bull; <?= htmlspecialchars($ficha_empleado['departamento'] ?? '') ?></p>
                    <p class="text-[10px] text-slate-400 mt-0.5">Sucursal: <?= htmlspecialchars($ficha_empleado['sucursal_nombre']) ?></p>
                </div>
            </div>

            <!-- Hardware Asignado -->
            <div class="space-y-3">
                <h5 class="text-xs font-bold text-slate-500 uppercase tracking-widest flex items-center gap-1.5">
                    <i class="fa-solid fa-laptop text-cyan-400"></i> Hardware Vinculado
                </h5>
                <?php if (!empty($ficha_equipos)): ?>
                    <?php foreach ($ficha_equipos as $ficha_equipo): 
                        $password_clear = '';
                        $bitlocker_clear = 'N/A';
                        if (!empty($ficha_equipo['usuario_local'])) {
                            try {
                                $password_clear = !empty($ficha_equipo['eq_password']) ? Security::decrypt($ficha_equipo['eq_password']) : '';
                            } catch (Exception $e) {
                                $password_clear = '[Error al descifrar]';
                            }
                            try {
                                $bitlocker_clear = !empty($ficha_equipo['clave_bitlocker']) ? Security::decrypt($ficha_equipo['clave_bitlocker']) : 'N/A';
                            } catch (Exception $e) {
                                $bitlocker_clear = '[Error al descifrar]';
                            }
                        }
                    ?>
                        <div class="bg-slate-100 border border-slate-200 rounded-2xl p-4 space-y-3 mb-3 last:mb-0">
                            <div class="flex items-center justify-between text-xs pb-2 border-b border-slate-200">
                                <span class="font-bold text-slate-800"><?= htmlspecialchars($ficha_equipo['tipo'] . ' ' . $ficha_equipo['marca'] . ' ' . $ficha_equipo['modelo']) ?></span>
                                <span class="text-slate-400 font-mono">S/N: <?= htmlspecialchars($ficha_equipo['service_tag_sn']) ?></span>
                            </div>
                            <?php if (!empty($ficha_equipo['usuario_local'])): ?>
                                <div class="grid grid-cols-2 gap-3 text-xs">
                                    <div>
                                        <span class="text-[10px] text-slate-400 uppercase font-bold tracking-wider">Usuario Local</span>
                                        <div class="text-slate-600 font-mono bg-slate-100 p-2.5 rounded-lg mt-1 border border-slate-200"><?= htmlspecialchars($ficha_equipo['usuario_local']) ?></div>
                                    </div>
                                    <div>
                                        <span class="text-[10px] text-slate-400 uppercase font-bold tracking-wider">Contraseña Windows</span>
                                        <div class="text-cyan-400 font-mono bg-slate-100 p-2.5 rounded-lg mt-1 border border-slate-200 select-all"><?= htmlspecialchars($password_clear) ?></div>
                                    </div>
                                </div>
                                <?php if (!empty($ficha_equipo['clave_bitlocker'])): ?>
                                    <div class="text-xs pt-1">
                                        <span class="text-[10px] text-slate-400 uppercase font-bold tracking-wider">Clave BitLocker de Recuperación</span>
                                        <div class="text-amber-400 font-mono bg-slate-100 p-2.5 rounded-lg mt-1 border border-slate-200 select-all whitespace-pre-wrap break-all text-[11px]"><?= htmlspecialchars($bitlocker_clear) ?></div>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($ficha_equipo['notes_acceso']) || !empty($ficha_equipo['notas_acceso'])): ?>
                                    <div class="text-[11px] text-slate-500 bg-slate-50 p-2.5 rounded-lg border border-slate-200">
                                        <strong class="text-slate-800">Notas de acceso:</strong> <?= htmlspecialchars($ficha_equipo['notas_acceso'] ?? $ficha_equipo['notes_acceso']) ?>
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <p class="text-xs text-slate-400 italic">El hardware no tiene credenciales de acceso registradas.</p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-xs text-slate-400 italic bg-slate-100 border border-slate-200 rounded-2xl p-4">
                        Ningún hardware de inventario está asignado actualmente a este colaborador.
                    </div>
                <?php endif; ?>
            </div>

            <!-- Cuenta Microsoft 365 -->
            <div class="space-y-3">
                <h5 class="text-xs font-bold text-slate-500 uppercase tracking-widest flex items-center gap-1.5">
                    <i class="fa-solid fa-cloud text-emerald-400"></i> Licencia Microsoft 365
                </h5>
                <?php if ($ficha_m365): ?>
                    <div class="bg-slate-100 border border-slate-200 rounded-2xl p-4 space-y-3">
                        <div class="flex items-center justify-between text-xs pb-2 border-b border-slate-200">
                            <span class="font-bold text-slate-800 flex items-center gap-1.5">
                                <i class="fa-solid fa-envelope text-slate-500"></i> <?= htmlspecialchars($ficha_m365['correo']) ?>
                            </span>
                            <span class="text-emerald-400 bg-emerald-500/10 border border-emerald-500/20 px-2 py-0.5 rounded text-[10px] font-bold"><?= htmlspecialchars($ficha_m365['licencia_tipo']) ?></span>
                        </div>
                        <div class="grid grid-cols-2 gap-3 text-xs">
                            <div>
                                <span class="text-[10px] text-slate-400 uppercase font-bold tracking-wider">Contraseña de Correo</span>
                                <div class="text-emerald-400 font-mono bg-slate-100 p-2.5 rounded-lg mt-1 border border-slate-200 select-all">
                                    <?= !empty($ficha_m365['password_clear']) ? htmlspecialchars($ficha_m365['password_clear']) : '<span class="text-slate-400">Sin contraseña registrada</span>' ?>
                                </div>
                            </div>
                            <div>
                                <span class="text-[10px] text-slate-400 uppercase font-bold tracking-wider">Próxima Facturación</span>
                                <div class="text-slate-600 bg-slate-100 p-2.5 rounded-lg mt-1 border border-slate-200">
                                    <?= date('d/m/Y', strtotime($ficha_m365['fecha_proximo_pago'])) ?>
                                    <span class="text-[10px] text-slate-400 font-normal">(<?= htmlspecialchars($ficha_m365['ciclo_facturacion']) ?>)</span>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="text-xs text-slate-400 italic bg-slate-100 border border-slate-200 rounded-2xl p-4">
                        Ninguna cuenta de correo corporativo M365 vinculada actualmente.
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Credenciales Personales -->
            <div class="space-y-3 pt-4 border-t border-slate-100">
                <h5 class="text-xs font-bold text-slate-500 uppercase tracking-widest flex items-center gap-1.5">
                    <i class="fa-solid fa-key text-amber-400"></i> Credenciales Personales
                </h5>
                <?php if (!empty($ficha_cred_pers)): ?>
                    <div class="bg-slate-100 border border-slate-200 rounded-2xl p-4">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
                            <?php foreach ($ficha_cred_pers as $cred): ?>
                                <div class="bg-white p-3 rounded-xl border border-slate-200 shadow-sm">
                                    <span class="text-[10px] text-slate-400 uppercase font-bold tracking-wider mb-1 flex items-center gap-1.5">
                                        <?php if($cred['tipo'] === 'Gmail') echo '<i class="fa-brands fa-google text-red-500"></i>'; ?>
                                        <?php if($cred['tipo'] === 'Correo Corporativo') echo '<i class="fa-solid fa-envelope text-blue-500"></i>'; ?>
                                        <?php if($cred['tipo'] === 'PC PIN') echo '<i class="fa-solid fa-desktop text-slate-500"></i>'; ?>
                                        <?php if($cred['tipo'] === 'Pass AG') echo '<i class="fa-solid fa-server text-indigo-500"></i>'; ?>
                                        <?= htmlspecialchars($cred['tipo']) ?>
                                    </span>
                                    <?php if (!empty($cred['usuario'])): ?>
                                        <div class="font-bold text-slate-700 mb-1"><?= htmlspecialchars($cred['usuario']) ?></div>
                                    <?php endif; ?>
                                    <div class="text-amber-600 font-mono bg-slate-50 p-2 rounded border border-slate-100 select-all">
                                        <?= !empty($cred['password_clear']) ? htmlspecialchars($cred['password_clear']) : '<span class="text-slate-400">N/A</span>' ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="text-xs text-slate-400 italic bg-slate-100 border border-slate-200 rounded-2xl p-4">
                        Ninguna credencial personal registrada.
                    </div>
                <?php endif; ?>
            </div>

        </div>

        <div class="p-6 bg-slate-50 border-t border-slate-200 flex">
            <a href="index.php" class="flex-1 text-center py-3.5 rounded-xl bg-white/5 hover:bg-white/10 text-slate-800 font-semibold text-sm border border-slate-200 transition">
                Cerrar Ficha
            </a>
        </div>

    </div>
</div>
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
                        <?php foreach ($sucursales as $suc): ?>
                            <option value="<?= $suc['id'] ?>"><?= htmlspecialchars($suc['nombre']) ?></option>
                        <?php endforeach; ?>
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

<!-- Modal Detalle Colaborador IT -->
<div id="modal-detalle-empleado" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[100] flex items-center justify-center hidden p-4">
    <div class="bg-white border border-slate-200 rounded-3xl w-full max-w-md overflow-hidden shadow-2xl animate-fade-in relative">
        <div class="p-6 border-b border-slate-100 bg-slate-50/80 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="h-11 w-11 rounded-2xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-600 flex items-center justify-center text-lg shrink-0 font-bold" id="demp_initials">
                    E
                </div>
                <div>
                    <h3 class="font-bold text-slate-800 text-base" id="demp_nombre">Nombre del Empleado</h3>
                    <p class="text-xs text-slate-500" id="demp_cargo_depto">Cargo • Departamento</p>
                </div>
            </div>
            <button onclick="cerrarModal('modal-detalle-empleado')" class="h-8 w-8 rounded-full hover:bg-slate-200 text-slate-400 hover:text-slate-700 flex items-center justify-center transition">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <div class="p-6 space-y-4 text-sm">
            <div class="bg-slate-50 p-4 rounded-2xl border border-slate-200 space-y-2">
                <div>
                    <span class="text-[11px] text-slate-400 block font-semibold uppercase tracking-wider">Ubicación / Sucursal</span>
                    <span class="font-bold text-slate-800" id="demp_sucursal">--</span>
                </div>
            </div>

            <div>
                <span class="text-xs font-bold text-slate-400 uppercase tracking-wider block mb-2">Recursos Vinculados</span>
                <div class="space-y-2">
                    <div class="bg-slate-50 p-3 rounded-xl border border-slate-200 text-xs">
                        <span class="text-[11px] text-slate-400 block font-semibold">Equipo de Cómputo</span>
                        <div class="font-bold text-slate-800 mt-0.5 flex items-center gap-1.5" id="demp_equipo">
                            <i class="fa-solid fa-laptop text-cyan-600"></i> --
                        </div>
                    </div>
                    <div class="bg-slate-50 p-3 rounded-xl border border-slate-200 text-xs">
                        <span class="text-[11px] text-slate-400 block font-semibold">Cuenta Microsoft 365</span>
                        <div class="font-bold text-slate-800 mt-0.5 flex items-center gap-1.5" id="demp_m365">
                            <i class="fa-solid fa-cloud text-emerald-500"></i> --
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="p-4 border-t border-slate-100 bg-slate-50 flex items-center justify-between">
            <a id="demp_link_ficha" href="#" class="px-4 py-2 rounded-xl bg-slate-200 hover:bg-slate-300 text-slate-700 text-xs font-bold transition flex items-center gap-1.5">
                <i class="fa-solid fa-key"></i> Ficha de Credenciales
            </a>
            <button onclick="cerrarModal('modal-detalle-empleado')" class="px-5 py-2 rounded-xl bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold transition shadow-md">
                Cerrar
            </button>
        </div>
    </div>
</div>

<script>
    function verDetalleEmpleado(emp) {
        document.getElementById('demp_nombre').innerText = emp.nombre || '';
        document.getElementById('demp_cargo_depto').innerText = (emp.cargo || '') + ' • ' + (emp.departamento || '');
        document.getElementById('demp_sucursal').innerText = emp.sucursal_nombre || '--';
        document.getElementById('demp_initials').innerText = (emp.nombre || 'E').charAt(0).toUpperCase();

        let equipoHtml = '<span class="text-slate-400 font-normal italic">Sin equipo asignado</span>';
        if (emp.equipo_vinculado) {
            const eqList = emp.equipo_vinculado.split(', ');
            equipoHtml = eqList.map(eq => `<div class="flex items-center gap-1.5"><i class="fa-solid fa-laptop text-cyan-600 text-xs shrink-0"></i> <span>${escapeHtml(eq)}</span></div>`).join('');
        }
        document.getElementById('demp_equipo').innerHTML = equipoHtml;

        document.getElementById('demp_m365').innerHTML = emp.correo_m365 
            ? '<i class="fa-solid fa-cloud text-emerald-500"></i> ' + emp.correo_m365 + ' <span class="text-[10px] text-slate-400 font-normal">(' + (emp.licencia_m365 || '') + ')</span>' 
            : '<span class="text-slate-400 font-normal italic">Sin cuenta M365 asociada</span>';

        document.getElementById('demp_link_ficha').href = '?details_id=' + emp.id;

        abrirModal('modal-detalle-empleado');
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
</script>

<?php
require_once __DIR__ . '/../../includes/footer.php';
?>
