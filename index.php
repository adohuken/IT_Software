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

        // 5. Datos para Gráfico Donut (Distribución de Equipos)
        $chart_tipos = [];
        $total_equipos_chart = 0;
        foreach ($equipos_resumen as $eq) {
            if (!isset($chart_tipos[$eq['tipo']])) {
                $chart_tipos[$eq['tipo']] = 0;
            }
            $chart_tipos[$eq['tipo']] += (int)$eq['total'];
            $total_equipos_chart += (int)$eq['total'];
        }
        arsort($chart_tipos); // Ordenar de mayor a menor
        
        $donut_labels = [];
        $donut_data = [];
        foreach ($chart_tipos as $tipo => $total) {
            $donut_labels[] = $tipo;
            $donut_data[] = $total;
        }

        // 6. Datos para Gráfico de Barras (Asignaciones últimos 6 meses)
        $db->query("SET lc_time_names = 'es_ES'");
        $stmt_bar = $db->query("
            SELECT LEFT(MONTHNAME(fecha_entrega), 3) as mes, COUNT(*) as total, MONTH(fecha_entrega) as m
            FROM asignaciones 
            WHERE fecha_entrega >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) 
            GROUP BY mes, m 
            ORDER BY m ASC
        ");
        $bar_results = $stmt_bar->fetchAll();
        $bar_labels = [];
        $bar_data = [];
        foreach ($bar_results as $row) {
            $bar_labels[] = ucfirst($row['mes']);
            $bar_data[] = $row['total'];
        }

        // Asegurar que no esté vacío para Chart.js
        if (empty($bar_labels)) {
            $bar_labels = ['Sin datos'];
            $bar_data = [0];
        }
        if (empty($donut_labels)) {
            $donut_labels = ['Sin equipos'];
            $donut_data = [1];
        }

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
<div class="space-y-6 animate-fade-in-up">

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

    <!-- Grid de Accesos Directos (Tarjetas/Botones) -->
    <div class="space-y-4">
        <div>
            <h2 class="text-lg font-bold text-slate-850 tracking-tight">Accesos Directos</h2>
            <p class="text-xs text-slate-400">Navega rápidamente a las distintas secciones del sistema.</p>
        </div>
        
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-6">
            <!-- 1. Equipos -->
            <a href="<?= BASE_URL ?>modules/equipos/index.php" class="bg-white border border-slate-200 rounded-3xl p-6 hover:shadow-2xl hover:border-violet-500/30 transition-all duration-300 flex flex-col justify-between group h-40">
                <div class="h-10 w-10 rounded-2xl bg-violet-500/5 border border-violet-500/10 text-violet-500 flex items-center justify-center text-lg transition-transform group-hover:scale-110 duration-300">
                    <i class="fa-solid fa-server"></i>
                </div>
                <div>
                    <h3 class="font-bold text-sm text-slate-800 group-hover:text-violet-600 transition-colors">Equipos</h3>
                    <p class="text-[10px] text-slate-400 mt-1 leading-tight">Inventario y asignación.</p>
                </div>
            </a>

            <!-- 2. Credenciales -->
            <a href="<?= BASE_URL ?>modules/credenciales/index.php" class="bg-white border border-slate-200 rounded-3xl p-6 hover:shadow-2xl hover:border-cyan-500/30 transition-all duration-300 flex flex-col justify-between group h-40">
                <div class="h-10 w-10 rounded-2xl bg-cyan-500/5 border border-cyan-500/10 text-cyan-500 flex items-center justify-center text-lg transition-transform group-hover:scale-110 duration-300">
                    <i class="fa-solid fa-key"></i>
                </div>
                <div>
                    <h3 class="font-bold text-sm text-slate-800 group-hover:text-cyan-600 transition-colors">Credenciales</h3>
                    <p class="text-[10px] text-slate-400 mt-1 leading-tight">Accesos cifrados y equipos.</p>
                </div>
            </a>

            <!-- 3. Colaboradores -->
            <a href="<?= BASE_URL ?>modules/empleados/index.php" class="bg-white border border-slate-200 rounded-3xl p-6 hover:shadow-2xl hover:border-emerald-500/30 transition-all duration-300 flex flex-col justify-between group h-40">
                <div class="h-10 w-10 rounded-2xl bg-emerald-500/5 border border-emerald-500/10 text-emerald-500 flex items-center justify-center text-lg transition-transform group-hover:scale-110 duration-300">
                    <i class="fa-solid fa-users"></i>
                </div>
                <div>
                    <h3 class="font-bold text-sm text-slate-800 group-hover:text-emerald-600 transition-colors">Colaboradores</h3>
                    <p class="text-[10px] text-slate-400 mt-1 leading-tight">Personal e historial de asignación.</p>
                </div>
            </a>

            <!-- 4. Tareas IT -->
            <a href="<?= BASE_URL ?>modules/tareas/index.php" class="bg-white border border-slate-200 rounded-3xl p-6 hover:shadow-2xl hover:border-blue-500/30 transition-all duration-300 flex flex-col justify-between group h-40">
                <div class="h-10 w-10 rounded-2xl bg-blue-500/5 border border-blue-500/10 text-blue-500 flex items-center justify-center text-lg transition-transform group-hover:scale-110 duration-300">
                    <i class="fa-solid fa-list-check"></i>
                </div>
                <div>
                    <h3 class="font-bold text-sm text-slate-800 group-hover:text-blue-600 transition-colors">Tareas IT</h3>
                    <p class="text-[10px] text-slate-400 mt-1 leading-tight">Pendientes, vencidas y progreso.</p>
                </div>
            </a>

            <!-- 5. Usuarios IT -->
            <a href="<?= BASE_URL ?>modules/usuarios/index.php" class="bg-white border border-slate-200 rounded-3xl p-6 hover:shadow-2xl hover:border-indigo-500/30 transition-all duration-300 flex flex-col justify-between group h-40">
                <div class="h-10 w-10 rounded-2xl bg-indigo-500/5 border border-indigo-500/10 text-indigo-500 flex items-center justify-center text-lg transition-transform group-hover:scale-110 duration-300">
                    <i class="fa-solid fa-user-shield"></i>
                </div>
                <div>
                    <h3 class="font-bold text-sm text-slate-800 group-hover:text-indigo-600 transition-colors">Usuarios IT</h3>
                    <p class="text-[10px] text-slate-400 mt-1 leading-tight">Roles de soporte y administración.</p>
                </div>
            </a>

            <!-- 6. Configuración -->
            <a href="<?= BASE_URL ?>modules/configuracion/index.php" class="bg-white border border-slate-200 rounded-3xl p-6 hover:shadow-2xl hover:border-amber-500/30 transition-all duration-300 flex flex-col justify-between group h-40">
                <div class="h-10 w-10 rounded-2xl bg-amber-500/5 border border-amber-500/10 text-amber-500 flex items-center justify-center text-lg transition-transform group-hover:scale-110 duration-300">
                    <i class="fa-solid fa-sliders"></i>
                </div>
                <div>
                    <h3 class="font-bold text-sm text-slate-800 group-hover:text-amber-600 transition-colors">Configuración</h3>
                    <p class="text-[10px] text-slate-400 mt-1 leading-tight">Carga masiva, BD y asistente IA.</p>
                </div>
            </a>
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
