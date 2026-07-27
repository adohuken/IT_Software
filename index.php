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
    'tareas_pendientes' => 0,
    'm365_por_vencer'   => 0,
    'equipos_asignados' => 0,
    'equipos_totales'   => 0,
    'equipos_mantenimiento' => 0
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
        // Tareas IT pendientes o en progreso
        $kpis['tareas_pendientes'] = $db->query("SELECT COUNT(*) FROM tareas_it WHERE estado IN ('Pendiente', 'En Progreso', 'Vencida')")->fetchColumn();
        
        // M365 por vencer en los siguientes 15 días
        $kpis['m365_por_vencer'] = $db->query("SELECT COUNT(*) FROM cuentas_m365 WHERE fecha_proximo_pago <= DATE_ADD(CURDATE(), INTERVAL 15 DAY) AND estado = 'Activa'")->fetchColumn();
        
        // Equipos asignados
        $kpis['equipos_asignados'] = $db->query("SELECT COUNT(*) FROM equipos WHERE estado = 'Asignado'")->fetchColumn();
        
        // Datos adicionales
        $kpis['equipos_totales'] = $db->query("SELECT COUNT(*) FROM equipos")->fetchColumn();
        $kpis['equipos_mantenimiento'] = $db->query("SELECT COUNT(*) FROM equipos WHERE estado = 'Mantenimiento'")->fetchColumn();

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
        <!-- KPI 1 -->
        <div class="bg-white rounded-3xl p-6 shadow-sm flex items-center justify-between border border-slate-100">
            <div>
                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">Tareas Pendientes</p>
                <h3 class="text-3xl font-extrabold text-slate-800"><?= $kpis['tareas_pendientes'] ?></h3>
                <p class="text-xs text-rose-500 font-bold mt-2"><i class="fa-solid fa-triangle-exclamation"></i> Requieren acción</p>
            </div>
            <div class="h-12 w-12 rounded-full bg-blue-50 text-blue-500 flex items-center justify-center text-xl">
                <i class="fa-solid fa-list-check"></i>
            </div>
        </div>
        <!-- KPI 2 -->
        <div class="bg-white rounded-3xl p-6 shadow-sm flex items-center justify-between border border-slate-100">
            <div>
                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">M365 x Vencer</p>
                <h3 class="text-3xl font-extrabold text-slate-800"><?= $kpis['m365_por_vencer'] ?></h3>
                <p class="text-xs text-emerald-500 font-bold mt-2"><i class="fa-solid fa-caret-up"></i> Próximos 15 días</p>
            </div>
            <div class="h-12 w-12 rounded-full bg-indigo-50 text-indigo-500 flex items-center justify-center text-xl">
                <i class="fa-brands fa-microsoft"></i>
            </div>
        </div>
        <!-- KPI 3 -->
        <div class="bg-white rounded-3xl p-6 shadow-sm flex items-center justify-between border border-slate-100">
            <div>
                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">Equipos Asignados</p>
                <h3 class="text-3xl font-extrabold text-slate-800"><?= $kpis['equipos_asignados'] ?></h3>
                <p class="text-xs text-rose-500 font-bold mt-2"><i class="fa-solid fa-caret-down"></i> <?= $kpis['equipos_totales'] ?> Totales</p>
            </div>
            <div class="h-12 w-12 rounded-full bg-rose-50 text-rose-500 flex items-center justify-center text-xl">
                <i class="fa-solid fa-laptop"></i>
            </div>
        </div>
        <!-- KPI 4 -->
        <div class="bg-white rounded-3xl p-6 shadow-sm flex items-center justify-between border border-slate-100">
            <div>
                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">En Mantenimiento</p>
                <h3 class="text-3xl font-extrabold text-slate-800"><?= $kpis['equipos_mantenimiento'] ?></h3>
                <p class="text-xs text-emerald-500 font-bold mt-2"><i class="fa-solid fa-caret-up"></i> Equipos reparando</p>
            </div>
            <div class="h-12 w-12 rounded-full bg-amber-50 text-amber-500 flex items-center justify-center text-xl">
                <i class="fa-solid fa-wrench"></i>
            </div>
        </div>
    </div>

    <!-- Charts Row -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Bar Chart -->
        <div class="bg-white rounded-3xl p-6 shadow-sm border border-slate-100 lg:col-span-2">
            <div class="flex items-center justify-between mb-4">
                <h3 class="font-bold text-slate-800 text-lg">Ventas mensuales <span class="text-xs font-normal text-slate-400 block sm:inline sm:ml-2">(Asignaciones)</span></h3>
                <span class="text-xs text-blue-400 font-medium">últimos 6 meses</span>
            </div>
            <div class="relative h-64 w-full">
                <canvas id="barChart"></canvas>
            </div>
        </div>
        
        <!-- Donut Chart -->
        <div class="bg-white rounded-3xl p-6 shadow-sm border border-slate-100">
            <h3 class="font-bold text-slate-800 text-lg mb-4">Distribución</h3>
            <div class="relative h-48 w-full flex items-center justify-center">
                <canvas id="donutChart"></canvas>
            </div>
            <div class="mt-4 flex flex-wrap justify-center gap-x-4 gap-y-2 text-[11px] font-medium text-slate-500" id="donutLegend">
                <!-- Legend will be generated by JS -->
            </div>
        </div>
    </div>

    <!-- Table Row -->
    <div class="bg-white rounded-3xl p-6 shadow-sm border border-slate-100">
        <div class="flex items-center justify-between mb-6">
            <h3 class="font-bold text-slate-800 text-lg">Últimas transacciones <span class="text-xs font-normal text-slate-400">(Tareas recientes)</span></h3>
            <a href="<?= BASE_URL ?>modules/tareas/index.php" class="text-sm font-medium text-blue-500 hover:text-blue-600">Ver todas</a>
        </div>
        
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse min-w-[600px]">
                <thead>
                    <tr class="text-[10px] font-bold text-slate-400 uppercase tracking-widest border-b border-slate-100">
                        <th class="pb-3 font-semibold px-2">Cliente (Asignado)</th>
                        <th class="pb-3 font-semibold px-2">Producto (Tarea)</th>
                        <th class="pb-3 font-semibold px-2">Fecha</th>
                        <th class="pb-3 font-semibold px-2 text-right">Estado</th>
                    </tr>
                </thead>
                <tbody class="text-sm text-slate-600">
                    <?php if (empty($tareas_urgentes)): ?>
                        <tr><td colspan="4" class="py-4 text-center text-slate-400">No hay tareas recientes</td></tr>
                    <?php else: ?>
                        <?php foreach ($tareas_urgentes as $tarea): 
                            $fecha_fin = new DateTime($tarea['fecha_limite']);
                            $hoy = new DateTime(date('Y-m-d'));
                            $dias_restantes = (int)$hoy->diff($fecha_fin)->format('%r%a');

                            if ($tarea['estado'] == 'Vencida' || $dias_restantes < 0) {
                                $color = 'rose'; $estado = 'Cancelado';
                            } elseif ($dias_restantes <= 2) {
                                $color = 'amber'; $estado = 'Pendiente';
                            } else {
                                $color = 'emerald'; $estado = 'Completado';
                            }
                        ?>
                        <tr class="border-b border-slate-50 last:border-0 hover:bg-slate-50/50 transition">
                            <td class="py-4 font-bold text-slate-800 px-2"><?= htmlspecialchars($tarea['asignado_nombre'] ?? 'Sin Asignar') ?></td>
                            <td class="py-4 px-2"><?= htmlspecialchars($tarea['titulo']) ?></td>
                            <td class="py-4 px-2"><?= date('d/m/Y', strtotime($tarea['fecha_limite'])) ?></td>
                            <td class="py-4 px-2 text-right">
                                <span class="px-3 py-1 rounded-full text-xs font-bold bg-<?= $color ?>-50 text-<?= $color ?>-500 border border-<?= $color ?>-100">
                                    <?= $estado ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- Chart.js Integration -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        
        // Data injected from PHP
        const barLabels = <?= json_encode($bar_labels ?? ['Sin datos']) ?>;
        const barData   = <?= json_encode($bar_data ?? [0]) ?>;
        const donutLabels = <?= json_encode($donut_labels ?? ['Sin equipos']) ?>;
        const donutData   = <?= json_encode($donut_data ?? [1]) ?>;
        const totalEquipos = <?= isset($total_equipos_chart) ? $total_equipos_chart : 1 ?>;

        // Bar Chart (Asignaciones mensuales)
        const barCtx = document.getElementById('barChart').getContext('2d');
        new Chart(barCtx, {
            type: 'bar',
            data: {
                labels: barLabels,
                datasets: [{
                    label: 'Registros',
                    data: barData,
                    backgroundColor: '#60a5fa',
                    borderRadius: 4,
                    barThickness: 35
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { 
                        beginAtZero: true, 
                        grid: { borderDash: [5, 5], color: '#f1f5f9', drawBorder: false }, 
                        border: { display: false },
                        ticks: { color: '#94a3b8', font: { size: 10 }, stepSize: 1 }
                    },
                    x: { 
                        grid: { display: false }, 
                        border: { display: false },
                        ticks: { color: '#94a3b8', font: { size: 11 } }
                    }
                }
            }
        });

        // Donut Chart (Distribución)
        const donutCtx = document.getElementById('donutChart').getContext('2d');
        const donutColors = ['#60a5fa', '#34d399', '#fbbf24', '#f87171', '#a78bfa', '#f472b6', '#38bdf8'];
        new Chart(donutCtx, {
            type: 'doughnut',
            data: {
                labels: donutLabels,
                datasets: [{
                    data: donutData,
                    backgroundColor: donutColors.slice(0, donutLabels.length),
                    borderWidth: 0,
                    cutout: '70%'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } }
            }
        });

        // Custom Legend for Donut
        const legendContainer = document.getElementById('donutLegend');
        donutLabels.forEach((label, i) => {
            const val = donutData[i] || 0;
            const pct = Math.round((val / (totalEquipos || 1)) * 100);
            const color = donutColors[i % donutColors.length];
            legendContainer.innerHTML += `<div class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-full" style="background-color: ${color}"></span> ${label} ${pct}%</div>`;
        });
    });
</script>

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
