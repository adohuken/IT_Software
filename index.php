<?php
/**
 * Dashboard Principal
 * Muestra métricas de alto nivel, alertas de facturación de M365 y tareas de IT pendientes.
 */

// Intentar incluir archivos requeridos
require_once __DIR__ . '/config/conexion.php';
require_once __DIR__ . '/config/security.php';

$db_connected = false;
$setup_needed = false;
$error_msg = '';

$kpis = [
    'total_credenciales' => 0,
    'total_colaboradores' => 0,
    'total_equipos' => 0,
    'tareas_pendientes' => 0
];

$tareas_urgentes = [];
$m365_proximos = [];
$equipos_resumen = [];

try {
    $db = Database::getConexion();
    $db_connected = true;

    // Verificar si las tablas principales existen haciendo una consulta simple
    $stmt = $db->query("SHOW TABLES LIKE 'equipos'");
    if ($stmt->rowCount() == 0) {
        $setup_needed = true;
    } else {
        // 1. Obtener KPIs
        // Total Credenciales (Equipos + Web + M365)
        $eq_cred = (int) $db->query("SELECT COUNT(*) FROM credenciales_equipos")->fetchColumn();
        $web_cred = (int) $db->query("SELECT COUNT(*) FROM credenciales_web")->fetchColumn();
        $m365_cred = (int) $db->query("SELECT COUNT(*) FROM cuentas_m365")->fetchColumn();
        $kpis['total_credenciales'] = $eq_cred + $web_cred + $m365_cred;

        // Total Colaboradores (Excluyendo marcadores Sucursal/Empresa)
        $kpis['total_colaboradores'] = (int) $db->query("SELECT COUNT(*) FROM empleados WHERE cargo NOT IN ('Sucursal', 'Empresa')")->fetchColumn();

        // Total Equipos
        $kpis['total_equipos'] = (int) $db->query("SELECT COUNT(*) FROM equipos")->fetchColumn();

        // Tareas IT pendientes o en progreso
        $kpis['tareas_pendientes'] = (int) $db->query("SELECT COUNT(*) FROM tareas_it WHERE estado IN ('Pendiente', 'En Progreso')")->fetchColumn();

        // 2. Obtener 5 Tareas Urgentes
        $stmt_tareas = $db->query("
            SELECT t.id, t.titulo, t.descripcion, t.fecha_limite, t.estado, e.nombre AS asignado_nombre 
            FROM tareas_it t 
            LEFT JOIN equipo_it e ON t.asignado_a = e.id 
            WHERE t.estado IN ('Pendiente', 'En Progreso', 'Vencida') 
            ORDER BY CASE t.estado WHEN 'Vencida' THEN 1 WHEN 'En Progreso' THEN 2 WHEN 'Pendiente' THEN 3 END ASC, t.fecha_limite ASC 
            LIMIT 5
        ");
        $tareas_urgentes = $stmt_tareas->fetchAll();

        // 3. Obtener 5 Cuentas M365 Próximas a Pagar
        $stmt_m365 = $db->query("
            SELECT c.id, c.correo, c.licencia_tipo, c.fecha_proximo_pago, c.ciclo_facturacion, emp.nombre AS empleado_nombre 
            FROM cuentas_m365 c 
            LEFT JOIN empleados emp ON c.empleado_id = emp.id 
            WHERE c.estado = 'Activa' 
            ORDER BY c.fecha_proximo_pago ASC 
            LIMIT 5
        ");
        $m365_proximos = $stmt_m365->fetchAll();

        // 4. Resumen de Inventario por Tipo y Estado
        $stmt_eq = $db->query("
            SELECT tipo, estado, COUNT(*) as total 
            FROM equipos 
            GROUP BY tipo, estado 
            ORDER BY tipo ASC
        ");
        $equipos_resumen = $stmt_eq->fetchAll();

        // 5. Últimos Equipos Registrados
        $stmt_ultimos_equipos = $db->query("
            SELECT id, tipo, marca, modelo, estado 
            FROM equipos 
            ORDER BY id DESC 
            LIMIT 5
        ");
        $ultimos_equipos = $stmt_ultimos_equipos->fetchAll();

        // 6. Últimos Colaboradores Registrados
        $stmt_ultimos_colaboradores = $db->query("
            SELECT id, nombre, cargo, departamento 
            FROM empleados 
            WHERE cargo NOT IN ('Sucursal', 'Empresa')
            ORDER BY id DESC 
            LIMIT 5
        ");
        $ultimos_colaboradores = $stmt_ultimos_colaboradores->fetchAll();

    }
} catch (Exception $e) {
    $db_connected = false;
    $error_msg = $e->getMessage();
}

// Cargar Cabecera
require_once __DIR__ . '/includes/header.php';
?>

<!-- Si no hay conexión o no está configurada la base de datos -->
<?php if (!$db_connected || $setup_needed): ?>
<div class="max-w-3xl mx-auto my-12">
    <div class="glass-panel rounded-2xl p-8 border-l-4 border-amber-500 shadow-2xl relative overflow-hidden">
        <!-- Decoración trasera -->
        <div class="absolute -right-16 -top-16 h-40 w-40 rounded-full bg-amber-500/10 blur-3xl"></div>
        
        <div class="flex items-start gap-5">
            <div class="h-14 w-14 rounded-2xl bg-amber-500/10 border border-amber-500/20 flex items-center justify-center text-amber-500 text-2xl shrink-0">
                <i class="fa-solid fa-triangle-exclamation animate-bounce"></i>
            </div>
            <div>
                <h2 class="text-xl font-bold text-white mb-2">Base de Datos no Inicializada</h2>
                <p class="text-gray-400 text-sm leading-relaxed mb-6">
                    El sistema detectó que el servidor local de base de datos no está listo o las tablas de infraestructura no han sido creadas. 
                    Por favor, asegúrate de iniciar el servicio MySQL en tu panel de control de XAMPP.
                </p>

                <?php if ($error_msg): ?>
                <div class="bg-black/40 border border-white/5 rounded-lg p-4 mb-6 font-mono text-xs text-rose-400 overflow-x-auto">
                    <strong>Detalle del Error:</strong><br>
                    <?= htmlspecialchars($error_msg) ?>
                </div>
                <?php endif; ?>

                <div class="flex flex-col sm:flex-row gap-4 items-stretch sm:items-center">
                    <a href="config/setup_db.php" target="_blank" class="px-5 py-3 rounded-xl bg-amber-500 hover:bg-amber-600 text-black font-semibold text-sm transition flex items-center justify-center gap-2 shadow-lg shadow-amber-500/10">
                        <i class="fa-solid fa-screwdriver-wrench"></i>
                        Inicializar Base de Datos (setup_db.php)
                    </a>
                    <button onclick="window.location.reload();" class="px-5 py-3 rounded-xl bg-white/5 hover:bg-white/10 text-white font-medium text-sm transition border border-white/10 flex items-center justify-center gap-2">
                        <i class="fa-solid fa-arrows-rotate"></i>
                        Reintentar Conexión
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
<?php else: ?>

<!-- DASHBOARD ACTIVO -->
<!-- DASHBOARD PREMIUM REDISEÑADO -->
<div class="max-w-[1400px] mx-auto space-y-6 animate-fade-in-up">



    <!-- Top 4 KPIs -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        <!-- KPI 1: Credenciales -->
        <div class="bg-white rounded-3xl p-6 shadow-sm flex items-center justify-between border border-slate-100">
            <div>
                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">Credenciales Totales</p>
                <h3 class="text-3xl font-extrabold text-slate-800"><?= $kpis['total_credenciales'] ?></h3>
                <p class="text-xs text-cyan-500 font-bold mt-2"><i class="fa-solid fa-key"></i> Claves & M365</p>
            </div>
            <div class="h-12 w-12 rounded-full bg-cyan-50 text-cyan-500 flex items-center justify-center text-xl">
                <i class="fa-solid fa-lock"></i>
            </div>
        </div>
        <!-- KPI 2: Colaboradores -->
        <div class="bg-white rounded-3xl p-6 shadow-sm flex items-center justify-between border border-slate-100">
            <div>
                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">Colaboradores</p>
                <h3 class="text-3xl font-extrabold text-slate-800"><?= $kpis['total_colaboradores'] ?></h3>
                <p class="text-xs text-emerald-500 font-bold mt-2"><i class="fa-solid fa-circle-check"></i> Personal Activo</p>
            </div>
            <div class="h-12 w-12 rounded-full bg-emerald-50 text-emerald-500 flex items-center justify-center text-xl">
                <i class="fa-solid fa-users"></i>
            </div>
        </div>
        <!-- KPI 3: Equipos -->
        <div class="bg-white rounded-3xl p-6 shadow-sm flex items-center justify-between border border-slate-100">
            <div>
                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">Equipos Registrados</p>
                <h3 class="text-3xl font-extrabold text-slate-800"><?= $kpis['total_equipos'] ?></h3>
                <p class="text-xs text-indigo-500 font-bold mt-2"><i class="fa-solid fa-laptop"></i> Activos de Red/PC</p>
            </div>
            <div class="h-12 w-12 rounded-full bg-indigo-50 text-indigo-500 flex items-center justify-center text-xl">
                <i class="fa-solid fa-server"></i>
            </div>
        </div>
        <!-- KPI 4: Tareas IT -->
        <div class="bg-white rounded-3xl p-6 shadow-sm flex items-center justify-between border border-slate-100">
            <div>
                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">Tareas Pendientes</p>
                <h3 class="text-3xl font-extrabold text-slate-800"><?= $kpis['tareas_pendientes'] ?></h3>
                <p class="text-xs text-amber-500 font-bold mt-2"><i class="fa-solid fa-triangle-exclamation"></i> En curso</p>
            </div>
            <div class="h-12 w-12 rounded-full bg-amber-50 text-amber-500 flex items-center justify-center text-xl">
                <i class="fa-solid fa-list-check"></i>
            </div>
        </div>
    </div>

    <!-- SECCIÓN DE ACTIVIDAD RECIENTE -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Últimos Equipos -->
        <div class="bg-white rounded-3xl p-6 shadow-sm border border-slate-100 flex flex-col h-80 overflow-hidden">
            <h3 class="text-sm font-bold text-slate-800 mb-4 flex items-center gap-2">
                <i class="fa-solid fa-server text-violet-500"></i> Equipos Recientes
            </h3>
            <div class="overflow-y-auto pr-2 custom-scrollbar flex-1">
                <?php if (empty($ultimos_equipos)): ?>
                    <div class="text-center text-slate-400 py-8 text-sm">No hay equipos registrados.</div>
                <?php else: ?>
                    <div class="space-y-3">
                        <?php foreach ($ultimos_equipos as $eq): 
                            $badge_color = 'bg-slate-100 text-slate-600 border-slate-200';
                            if ($eq['estado'] == 'Asignado') $badge_color = 'bg-blue-50 text-blue-600 border-blue-200';
                            if ($eq['estado'] == 'Disponible') $badge_color = 'bg-emerald-50 text-emerald-600 border-emerald-200';
                            if (in_array($eq['estado'], ['Dañado', 'En Reparación'])) $badge_color = 'bg-rose-50 text-rose-600 border-rose-200';
                        ?>
                            <div class="flex items-center justify-between p-3 rounded-2xl border border-slate-100 hover:border-violet-200 hover:shadow-sm transition bg-white">
                                <div class="flex items-center gap-3">
                                    <div class="h-10 w-10 rounded-xl bg-violet-50 text-violet-500 flex items-center justify-center shrink-0">
                                        <i class="fa-solid fa-laptop"></i>
                                    </div>
                                    <div>
                                        <p class="text-xs font-bold text-slate-800"><?= htmlspecialchars($eq['marca'] . ' ' . $eq['modelo']) ?></p>
                                        <p class="text-[11px] text-slate-400 font-medium"><?= htmlspecialchars($eq['tipo']) ?></p>
                                    </div>
                                </div>
                                <span class="px-2.5 py-1.5 rounded-xl text-[10px] font-bold border <?= $badge_color ?>">
                                    <?= htmlspecialchars($eq['estado']) ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Últimos Colaboradores -->
        <div class="bg-white rounded-3xl p-6 shadow-sm border border-slate-100 flex flex-col h-80 overflow-hidden">
            <h3 class="text-sm font-bold text-slate-800 mb-4 flex items-center gap-2">
                <i class="fa-solid fa-users text-emerald-500"></i> Colaboradores Recientes
            </h3>
            <div class="overflow-y-auto pr-2 custom-scrollbar flex-1">
                <?php if (empty($ultimos_colaboradores)): ?>
                    <div class="text-center text-slate-400 py-8 text-sm">No hay colaboradores registrados.</div>
                <?php else: ?>
                    <div class="space-y-3">
                        <?php foreach ($ultimos_colaboradores as $col): ?>
                            <div class="flex items-center justify-between p-3 rounded-2xl border border-slate-100 hover:border-emerald-200 hover:shadow-sm transition bg-white">
                                <div class="flex items-center gap-3">
                                    <div class="h-10 w-10 rounded-xl bg-emerald-50 text-emerald-500 flex items-center justify-center shrink-0">
                                        <i class="fa-solid fa-user"></i>
                                    </div>
                                    <div>
                                        <p class="text-xs font-bold text-slate-800"><?= htmlspecialchars($col['nombre']) ?></p>
                                        <p class="text-[11px] text-slate-400 font-medium"><?= htmlspecialchars($col['cargo']) ?></p>
                                    </div>
                                </div>
                                <span class="text-[10px] font-bold text-slate-400 bg-slate-50 px-2.5 py-1.5 rounded-xl border border-slate-100">
                                    <?= htmlspecialchars($col['departamento'] ?? 'N/A') ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- SECCIÓN DE ALERTAS Y TAREAS -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Tareas Urgentes -->
        <div class="bg-white rounded-3xl p-6 shadow-sm border border-slate-100">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-sm font-bold text-slate-800 flex items-center gap-2">
                    <i class="fa-solid fa-triangle-exclamation text-amber-500"></i> Tareas Urgentes
                </h3>
                <a href="<?= BASE_URL ?>modules/tareas/index.php" class="text-[10px] font-bold uppercase tracking-widest text-cyan-500 hover:text-cyan-600 transition">Ver todas</a>
            </div>
            
            <?php if (empty($tareas_urgentes)): ?>
                <div class="p-6 text-center text-slate-400 text-xs">No hay tareas urgentes.</div>
            <?php else: ?>
                <div class="space-y-3">
                    <?php foreach ($tareas_urgentes as $tu): ?>
                        <div class="flex items-start justify-between gap-3 p-3 rounded-2xl bg-slate-50 border border-slate-100 hover:border-amber-200 transition">
                            <div>
                                <p class="text-xs font-bold text-slate-700"><?= htmlspecialchars($tu['titulo']) ?></p>
                                <p class="text-[10px] text-slate-500 mt-1"><i class="fa-regular fa-user mr-1"></i> <?= htmlspecialchars($tu['asignado_nombre'] ?? 'Sin Asignar') ?></p>
                            </div>
                            <span class="text-[9px] font-bold uppercase px-2 py-1 rounded-md shrink-0 <?= $tu['estado'] === 'Vencida' ? 'bg-rose-100 text-rose-600' : 'bg-amber-100 text-amber-600' ?>"><?= $tu['estado'] ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Licencias M365 Próximas a Pagar -->
        <div class="bg-white rounded-3xl p-6 shadow-sm border border-slate-100">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-sm font-bold text-slate-800 flex items-center gap-2">
                    <i class="fa-brands fa-microsoft text-blue-500"></i> Renovaciones M365
                </h3>
                <a href="<?= BASE_URL ?>modules/credenciales/index.php?tab=m365" class="text-[10px] font-bold uppercase tracking-widest text-cyan-500 hover:text-cyan-600 transition">Ver todas</a>
            </div>
            
            <?php if (empty($m365_proximos)): ?>
                <div class="p-6 text-center text-slate-400 text-xs">No hay renovaciones próximas.</div>
            <?php else: ?>
                <div class="space-y-3">
                    <?php foreach ($m365_proximos as $m): ?>
                        <div class="flex items-center justify-between gap-3 p-3 rounded-2xl bg-slate-50 border border-slate-100 hover:border-blue-200 transition">
                            <div class="overflow-hidden">
                                <p class="text-xs font-bold text-slate-700 truncate"><?= htmlspecialchars($m['correo']) ?></p>
                                <p class="text-[10px] text-slate-500 mt-1 truncate"><i class="fa-regular fa-user mr-1"></i> <?= htmlspecialchars($m['empleado_nombre'] ?? 'N/A') ?></p>
                            </div>
                            <div class="text-right shrink-0 bg-white border border-slate-200 px-3 py-1.5 rounded-xl">
                                <p class="text-[10px] font-bold text-slate-800"><?= date('d M, Y', strtotime($m['fecha_proximo_pago'])) ?></p>
                                <p class="text-[9px] text-blue-500 font-bold uppercase mt-0.5"><?= $m['ciclo_facturacion'] ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    </div>

</div>

<style>
    @keyframes fade-in-up {
        from { opacity: 0; transform: translateY(15px); }
        to { opacity: 1; transform: translateY(0); }
    }
    .animate-fade-in-up {
        animation: fade-in-up 0.5s cubic-bezier(0.16, 1, 0.3, 1) forwards;
    }
</style>

<?php endif; ?>



<?php
// Cargar Pie de Página
require_once __DIR__ . '/includes/footer.php';
?>
