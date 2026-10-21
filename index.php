<?php
/**
 * Dashboard Principal - IT Software
 * Métricas de alto nivel con estética Glassmorphic Premium y monitoreo en tiempo real
 */

require_once __DIR__ . '/config/conexion.php';
require_once __DIR__ . '/config/security.php';

$db_connected = false;
$setup_needed = false;
$error_msg = '';

$kpis = [
    'total_credenciales' => 0,
    'total_colaboradores' => 0,
    'total_equipos' => 0,
    'total_sucursales' => 0,
    'tareas_pendientes' => 0
];

$laptops_count = 0;
$desktops_count = 0;
$impresoras_count = 0;
$otros_count = 0;
$hw_detalle = [];
$suc_detalles = [];
$kpi_empresas = [];
$kpi_colab_ciudades = [];

$tareas_urgentes = [];
$m365_proximos = [];
$equipos_por_sucursal = [];
$equipos_por_tipo = [];
$ultimos_equipos = [];
$ultimos_colaboradores = [];

try {
    $db = Database::getConexion();
    $db_connected = true;

    // Verificar si las tablas principales existen
    $stmt = $db->query("SHOW TABLES LIKE 'equipos'");
    if ($stmt->rowCount() == 0) {
        $setup_needed = true;
    } else {
        // 1. Obtener KPIs Principales
        $eq_cred = (int) $db->query("SELECT COUNT(*) FROM credenciales_equipos")->fetchColumn();
        $web_cred = (int) $db->query("SELECT COUNT(*) FROM credenciales_web")->fetchColumn();
        $m365_cred = (int) $db->query("SELECT COUNT(*) FROM cuentas_m365")->fetchColumn();
        $kpis['total_credenciales'] = $eq_cred + $web_cred + $m365_cred;

        $kpis['total_colaboradores'] = (int) $db->query("SELECT COUNT(*) FROM empleados WHERE cargo IS NULL OR cargo NOT IN ('Sucursal', 'Empresa')")->fetchColumn();
        $kpis['total_equipos'] = (int) $db->query("SELECT COUNT(*) FROM equipos")->fetchColumn();
        $kpis['total_sucursales'] = (int) $db->query("SELECT COUNT(DISTINCT sucursal_id) FROM empleados WHERE sucursal_id IS NOT NULL AND (cargo IS NULL OR cargo NOT IN ('Sucursal', 'Empresa'))")->fetchColumn();
        $kpis['tareas_pendientes'] = (int) $db->query("SELECT COUNT(*) FROM tareas_it WHERE estado IN ('Pendiente', 'En Progreso')")->fetchColumn();

        // 2. Desglose de tipos de hardware
        $laptops_count = (int) $db->query("SELECT COUNT(*) FROM equipos WHERE tipo = 'Laptop'")->fetchColumn();
        $desktops_count = (int) $db->query("SELECT COUNT(*) FROM equipos WHERE tipo = 'Desktop'")->fetchColumn();
        $impresoras_count = (int) $db->query("SELECT COUNT(*) FROM equipos WHERE tipo = 'Impresora'")->fetchColumn();
        $otros_count = (int) $db->query("SELECT COUNT(*) FROM equipos WHERE tipo NOT IN ('Laptop', 'Desktop', 'Impresora')")->fetchColumn();

        // 2b. Desglose detallado por categoría (panel interactivo de Composición de Hardware)
        $hw_map_cat = ['Laptop' => 'laptop', 'Desktop' => 'desktop', 'Impresora' => 'impresora'];
        foreach (['laptop', 'desktop', 'impresora', 'otros'] as $k) {
            $hw_detalle[$k] = ['estado' => [], 'marca' => [], 'sucursal' => [], 'tipo' => []];
        }
        $rows_det = $db->query("
            SELECT eq.tipo AS tipo,
                   eq.estado AS estado,
                   COALESCE(NULLIF(TRIM(eq.marca), ''), 'Sin marca') AS marca,
                   COALESCE(s.nombre, 'Sin sucursal') AS sucursal,
                   COUNT(*) AS total
            FROM equipos eq
            LEFT JOIN sucursales s ON eq.sucursal_id = s.id
            WHERE eq.tipo IS NOT NULL
            GROUP BY 1, 2, 3, 4
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows_det as $r) {
            $cat = $hw_map_cat[$r['tipo']] ?? 'otros';
            $n = (int) $r['total'];
            foreach (['estado', 'marca', 'sucursal', 'tipo'] as $dim) {
                $val = ($r[$dim] !== null && $r[$dim] !== '') ? $r[$dim] : 'Sin definir';
                $hw_detalle[$cat][$dim][$val] = ($hw_detalle[$cat][$dim][$val] ?? 0) + $n;
            }
        }
        foreach ($hw_detalle as $k => $dims) {
            foreach ($dims as $dk => $vals) {
                arsort($vals);
                $hw_detalle[$k][$dk] = $vals;
            }
        }

        // 2c. Totales por Empresa para KPI de Red Corporativa
        $kpi_empresas = $db->query("
            SELECT e.nombre, COUNT(eq.id) as total
            FROM empresas e
            JOIN sucursales s ON s.empresa_id = e.id
            LEFT JOIN equipos eq ON eq.sucursal_id = s.id
            GROUP BY e.id, e.nombre
            ORDER BY total DESC
        ")->fetchAll(PDO::FETCH_ASSOC);

        // 2d. Totales de Colaboradores por Zona / País / Sede
        $kpi_colab_ciudades = $db->query("
            SELECT 
                CASE 
                    WHEN s.nombre LIKE '%Managua%' OR s.ubicacion LIKE '%Managua%' THEN 'Managua'
                    WHEN s.nombre LIKE '%Honduras%' OR s.ubicacion LIKE '%Honduras%' THEN 'Honduras'
                    WHEN s.nombre LIKE '%Le%' OR s.ubicacion LIKE '%Le%' THEN 'León'
                    WHEN s.nombre LIKE '%Salvador%' OR s.ubicacion LIKE '%Salvador%' THEN 'El Salvador'
                    WHEN s.nombre LIKE '%Dominicana%' OR s.ubicacion LIKE '%Dominicana%' THEN 'Rep. Dom.'
                    WHEN s.nombre LIKE '%S_baco%' OR s.ubicacion LIKE '%S_baco%' THEN 'Sébaco'
                    ELSE 'Otras'
                END AS ciudad,
                COUNT(emp.id) as total
            FROM empleados emp
            JOIN sucursales s ON emp.sucursal_id = s.id
            WHERE emp.cargo IS NULL OR emp.cargo NOT IN ('Sucursal', 'Empresa')
            GROUP BY 1
            ORDER BY total DESC
            LIMIT 5
        ")->fetchAll(PDO::FETCH_ASSOC);

        // 3. Equipos por Sucursal y Empresa
        $sqlSuc = "
            SELECT s.id as sucursal_id, s.nombre as sucursal_nombre, s.ubicacion, e.nombre as empresa_nombre, COUNT(eq.id) as total
            FROM sucursales s
            JOIN empresas e ON s.empresa_id = e.id
            LEFT JOIN equipos eq ON eq.sucursal_id = s.id
            WHERE s.id IN (SELECT DISTINCT sucursal_id FROM equipos WHERE sucursal_id IS NOT NULL)
            GROUP BY s.id, s.nombre, s.ubicacion, e.nombre
            ORDER BY total DESC
        ";
        $equipos_por_sucursal = $db->query($sqlSuc)->fetchAll(PDO::FETCH_ASSOC);

        // 3b. Desglose detallado por Sucursal (para acordeón interactivo de Distribución de Equipos)
        $suc_detalles = [];
        $rows_suc_det = $db->query("
            SELECT eq.sucursal_id,
                   COALESCE(NULLIF(TRIM(eq.tipo), ''), 'Otro') AS tipo,
                   COALESCE(NULLIF(TRIM(eq.estado), ''), 'Sin estado') AS estado,
                   COALESCE(NULLIF(TRIM(eq.marca), ''), 'Sin marca') AS marca,
                   COUNT(*) AS total
            FROM equipos eq
            WHERE eq.sucursal_id IS NOT NULL
            GROUP BY eq.sucursal_id, eq.tipo, eq.estado, marca
        ")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows_suc_det as $rd) {
            $sid = (int) $rd['sucursal_id'];
            if (!isset($suc_detalles[$sid])) {
                $suc_detalles[$sid] = ['tipo' => [], 'estado' => [], 'marca' => []];
            }
            $n = (int) $rd['total'];
            $t = $rd['tipo'];
            $e = $rd['estado'];
            $m = $rd['marca'];
            
            $suc_detalles[$sid]['tipo'][$t] = ($suc_detalles[$sid]['tipo'][$t] ?? 0) + $n;
            $suc_detalles[$sid]['estado'][$e] = ($suc_detalles[$sid]['estado'][$e] ?? 0) + $n;
            $suc_detalles[$sid]['marca'][$m] = ($suc_detalles[$sid]['marca'][$m] ?? 0) + $n;
        }

        foreach ($suc_detalles as $sid => $dims) {
            foreach ($dims as $dk => $vals) {
                arsort($vals);
                $suc_detalles[$sid][$dk] = $vals;
            }
        }

        // 4. Equipos por Tipo
        $sqlTipo = "SELECT tipo, COUNT(*) as total FROM equipos GROUP BY tipo ORDER BY total DESC";
        $equipos_por_tipo = $db->query($sqlTipo)->fetchAll(PDO::FETCH_ASSOC);

        // 5. Tareas Urgentes (5)
        $stmt_tareas = $db->query("
            SELECT t.id, t.titulo, t.descripcion, t.fecha_limite, t.estado, e.nombre AS asignado_nombre 
            FROM tareas_it t 
            LEFT JOIN equipo_it e ON t.asignado_a = e.id 
            WHERE t.estado IN ('Pendiente', 'En Progreso', 'Vencida') 
            ORDER BY CASE t.estado WHEN 'Vencida' THEN 1 WHEN 'En Progreso' THEN 2 WHEN 'Pendiente' THEN 3 END ASC, t.fecha_limite ASC 
            LIMIT 5
        ");
        $tareas_urgentes = $stmt_tareas->fetchAll();

        // 6. Cuentas M365 Próximas a Pagar (5)
        $stmt_m365 = $db->query("
            SELECT c.id, c.correo, c.licencia_tipo, c.fecha_proximo_pago, c.ciclo_facturacion, emp.nombre AS empleado_nombre 
            FROM cuentas_m365 c 
            LEFT JOIN empleados emp ON c.empleado_id = emp.id 
            WHERE c.estado = 'Activa' 
            ORDER BY c.fecha_proximo_pago ASC 
            LIMIT 5
        ");
        $m365_proximos = $stmt_m365->fetchAll();

        // 7. Últimos Equipos Registrados
        $stmt_ultimos_equipos = $db->query("
            SELECT eq.id, eq.tipo, eq.marca, eq.modelo, eq.service_tag_sn, eq.estado, s.nombre as sucursal_nombre
            FROM equipos eq
            LEFT JOIN sucursales s ON eq.sucursal_id = s.id
            ORDER BY eq.id DESC 
            LIMIT 5
        ");
        $ultimos_equipos = $stmt_ultimos_equipos->fetchAll();

        // 8. Últimos Colaboradores
        $stmt_ultimos_colaboradores = $db->query("
            SELECT emp.id, emp.nombre, emp.cargo, emp.departamento, s.nombre as sucursal_nombre
            FROM empleados emp
            LEFT JOIN sucursales s ON emp.sucursal_id = s.id
            WHERE emp.cargo NOT IN ('Sucursal', 'Empresa')
            ORDER BY emp.id DESC 
            LIMIT 5
        ");
        $ultimos_colaboradores = $stmt_ultimos_colaboradores->fetchAll();
    }
} catch (Exception $e) {
    $db_connected = false;
    $error_msg = $e->getMessage();
}

require_once __DIR__ . '/includes/header.php';
?>

<!-- Si la Base de datos no está inicializada -->
<?php if (!$db_connected || $setup_needed): ?>
<div class="max-w-3xl mx-auto my-12">
    <div class="glass-panel rounded-3xl p-8 border-l-4 border-amber-500 shadow-2xl relative overflow-hidden bg-white/80 backdrop-blur-xl">
        <div class="absolute -right-16 -top-16 h-40 w-40 rounded-full bg-amber-500/10 blur-3xl"></div>
        <div class="flex items-start gap-5">
            <div class="h-14 w-14 rounded-2xl bg-amber-500/10 border border-amber-500/20 flex items-center justify-center text-amber-500 text-2xl shrink-0">
                <i class="fa-solid fa-triangle-exclamation animate-bounce"></i>
            </div>
            <div>
                <h2 class="text-xl font-bold text-slate-800 mb-2">Base de Datos no Inicializada</h2>
                <p class="text-slate-500 text-sm leading-relaxed mb-6">
                    El sistema detectó que el servidor local de base de datos no está listo o las tablas de infraestructura no han sido creadas. 
                    Por favor, asegúrate de iniciar el servicio MySQL en tu panel de control de XAMPP.
                </p>

                <?php if ($error_msg): ?>
                <div class="bg-slate-900 border border-slate-800 rounded-xl p-4 mb-6 font-mono text-xs text-rose-400 overflow-x-auto">
                    <strong>Detalle del Error:</strong><br>
                    <?= htmlspecialchars($error_msg) ?>
                </div>
                <?php endif; ?>

                <div class="flex flex-col sm:flex-row gap-4 items-stretch sm:items-center">
                    <a href="config/setup_db.php" target="_blank" class="px-5 py-3 rounded-xl bg-amber-500 hover:bg-amber-600 text-black font-semibold text-sm transition flex items-center justify-center gap-2 shadow-lg shadow-amber-500/10">
                        <i class="fa-solid fa-screwdriver-wrench"></i> Inicializar Base de Datos (setup_db.php)
                    </a>
                    <button onclick="window.location.reload();" class="px-5 py-3 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 font-medium text-sm transition border border-slate-200 flex items-center justify-center gap-2">
                        <i class="fa-solid fa-arrows-rotate"></i> Reintentar Conexión
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
<?php else: ?>

<!-- DASHBOARD ACTIVO - ESTÉTICA GLASSMORPHISM PREMIUM -->
<div class="max-w-[1400px] mx-auto space-y-8 animate-fade-in-up">

    <!-- BARRA DE ACCESOS RÁPIDOS Y RESUMEN -->
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
        <!-- 1. Equipos -->
        <a href="<?= BASE_URL ?>modules/equipos/index.php" class="flex items-center gap-3 bg-white/80 backdrop-blur-xl p-3.5 rounded-2xl shadow-sm border border-slate-100 hover:shadow-md hover:border-violet-300 transition group">
            <div class="h-10 w-10 rounded-xl bg-violet-50 text-violet-600 flex items-center justify-center shrink-0 group-hover:bg-violet-600 group-hover:text-white transition-colors duration-300 shadow-inner">
                <i class="fa-solid fa-server"></i>
            </div>
            <div>
                <h3 class="font-bold text-xs text-slate-800 group-hover:text-violet-700 transition">Equipos</h3>
                <p class="text-[10px] text-slate-400">Inventario 1:1</p>
            </div>
        </a>
        <!-- 2. Recepción & Entregas -->
        <a href="<?= BASE_URL ?>modules/entregas/index.php" class="flex items-center gap-3 bg-white/80 backdrop-blur-xl p-3.5 rounded-2xl shadow-sm border border-slate-100 hover:shadow-md hover:border-emerald-300 transition group">
            <div class="h-10 w-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center shrink-0 group-hover:bg-emerald-600 group-hover:text-white transition-colors duration-300 shadow-inner">
                <i class="fa-solid fa-file-signature"></i>
            </div>
            <div>
                <h3 class="font-bold text-xs text-slate-800 group-hover:text-emerald-700 transition">Recepción</h3>
                <p class="text-[10px] text-slate-400">Actas & Ingresos</p>
            </div>
        </a>
        <!-- 3. Credenciales -->
        <a href="<?= BASE_URL ?>modules/credenciales/index.php" class="flex items-center gap-3 bg-white/80 backdrop-blur-xl p-3.5 rounded-2xl shadow-sm border border-slate-100 hover:shadow-md hover:border-cyan-300 transition group">
            <div class="h-10 w-10 rounded-xl bg-cyan-50 text-cyan-600 flex items-center justify-center shrink-0 group-hover:bg-cyan-500 group-hover:text-white transition-colors duration-300 shadow-inner">
                <i class="fa-solid fa-key"></i>
            </div>
            <div>
                <h3 class="font-bold text-xs text-slate-800 group-hover:text-cyan-700 transition">Credenciales</h3>
                <p class="text-[10px] text-slate-400">Accesos y M365</p>
            </div>
        </a>
        <!-- 4. Colaboradores -->
        <a href="<?= BASE_URL ?>modules/empleados/index.php" class="flex items-center gap-3 bg-white/80 backdrop-blur-xl p-3.5 rounded-2xl shadow-sm border border-slate-100 hover:shadow-md hover:border-blue-300 transition group">
            <div class="h-10 w-10 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center shrink-0 group-hover:bg-blue-600 group-hover:text-white transition-colors duration-300 shadow-inner">
                <i class="fa-solid fa-users"></i>
            </div>
            <div>
                <h3 class="font-bold text-xs text-slate-800 group-hover:text-blue-700 transition">Colaboradores</h3>
                <p class="text-[10px] text-slate-400">Personal Activo</p>
            </div>
        </a>
        <!-- 5. Tareas IT -->
        <a href="<?= BASE_URL ?>modules/tareas/index.php" class="flex items-center gap-3 bg-white/80 backdrop-blur-xl p-3.5 rounded-2xl shadow-sm border border-slate-100 hover:shadow-md hover:border-amber-300 transition group">
            <div class="h-10 w-10 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center shrink-0 group-hover:bg-amber-500 group-hover:text-white transition-colors duration-300 shadow-inner">
                <i class="fa-solid fa-list-check"></i>
            </div>
            <div>
                <h3 class="font-bold text-xs text-slate-800 group-hover:text-amber-700 transition">Tareas IT</h3>
                <p class="text-[10px] text-slate-400">Soporte & Manten</p>
            </div>
        </a>
        <!-- 6. Ajustes / Config -->
        <a href="<?= BASE_URL ?>modules/configuracion/index.php" class="flex items-center gap-3 bg-white/80 backdrop-blur-xl p-3.5 rounded-2xl shadow-sm border border-slate-100 hover:shadow-md hover:border-indigo-300 transition group">
            <div class="h-10 w-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center shrink-0 group-hover:bg-indigo-600 group-hover:text-white transition-colors duration-300 shadow-inner">
                <i class="fa-solid fa-sliders"></i>
            </div>
            <div>
                <h3 class="font-bold text-xs text-slate-800 group-hover:text-indigo-700 transition">Configuración</h3>
                <p class="text-[10px] text-slate-400">Sistema & Sucursales</p>
            </div>
        </a>
    </div>

    <!-- SECCIÓN KPIS GLASSMORPHISM (SUPERIOR) -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">

        <!-- KPI 1: EQUIPOS TOTALES EN INVENTARIO -->
        <div class="relative overflow-hidden rounded-3xl p-6 bg-white/80 backdrop-blur-xl border border-cyan-500/20 shadow-xl shadow-cyan-500/5 group hover:-translate-y-1 hover:shadow-cyan-500/15 transition-all duration-300">
            <div class="absolute -right-6 -bottom-6 w-32 h-32 rounded-full bg-cyan-500/15 blur-2xl group-hover:scale-150 transition-transform duration-500"></div>
            <div class="flex items-start justify-between relative z-10">
                <a href="<?= BASE_URL ?>modules/equipos/index.php" class="block group/link">
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-extrabold uppercase tracking-wider bg-cyan-500/10 text-cyan-600 border border-cyan-500/20 mb-3 group-hover/link:bg-cyan-500/20 transition">
                        <span class="h-1.5 w-1.5 rounded-full bg-cyan-500 animate-ping"></span>
                        Inventario Activo
                    </span>
                    <h3 class="text-4xl font-black text-slate-900 tracking-tight mb-1 group-hover/link:text-cyan-600 transition"><?= number_format($kpis['total_equipos']) ?></h3>
                    <p class="text-xs font-semibold text-slate-600">Equipos Registrados</p>
                </a>
                <a href="<?= BASE_URL ?>modules/equipos/index.php" class="h-13 w-13 rounded-2xl bg-gradient-to-tr from-cyan-500 to-blue-600 text-white flex items-center justify-center text-2xl shadow-lg shadow-cyan-500/30 shrink-0 group-hover:scale-110 transition-transform" title="Ver inventario de equipos">
                    <i class="fa-solid fa-laptop-code"></i>
                </a>
            </div>
            <div class="mt-4 pt-3 border-t border-slate-200/60 flex items-center justify-between text-[11px] text-slate-500 font-medium relative z-10">
                <a href="<?= BASE_URL ?>modules/equipos/index.php?tipo=Laptop" class="hover:text-cyan-600 transition underline-offset-2 hover:underline" title="Ver Laptops"><strong class="text-slate-800"><?= $laptops_count ?></strong> Laptops</a>
                <span>•</span>
                <a href="<?= BASE_URL ?>modules/equipos/index.php?tipo=Desktop" class="hover:text-cyan-600 transition underline-offset-2 hover:underline" title="Ver Desktops"><strong class="text-slate-800"><?= $desktops_count ?></strong> Desktops</a>
                <span>•</span>
                <a href="<?= BASE_URL ?>modules/equipos/index.php?tipo=Impresora" class="hover:text-cyan-600 transition underline-offset-2 hover:underline" title="Ver Impresoras"><strong class="text-slate-800"><?= $impresoras_count ?></strong> Impresoras</a>
            </div>
        </div>

        <!-- KPI 2: COBERTURA POR SUCURSALES -->
        <div class="relative overflow-hidden rounded-3xl p-6 bg-white/80 backdrop-blur-xl border border-violet-500/20 shadow-xl shadow-violet-500/5 group hover:-translate-y-1 hover:shadow-violet-500/15 transition-all duration-300">
            <div class="absolute -right-6 -bottom-6 w-32 h-32 rounded-full bg-violet-500/15 blur-2xl group-hover:scale-150 transition-transform duration-500"></div>
            <div class="flex items-start justify-between relative z-10">
                <a href="<?= BASE_URL ?>modules/configuracion/index.php" class="block group/link">
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-extrabold uppercase tracking-wider bg-violet-500/10 text-violet-600 border border-violet-500/20 mb-3 group-hover/link:bg-violet-500/20 transition">
                        <i class="fa-solid fa-building text-[9px]"></i> Red Corporativa
                    </span>
                    <h3 class="text-4xl font-black text-slate-900 tracking-tight mb-1 group-hover/link:text-violet-600 transition"><?= $kpis['total_sucursales'] ?></h3>
                    <p class="text-xs font-semibold text-slate-600">Sucursales Activas</p>
                </a>
                <a href="<?= BASE_URL ?>modules/configuracion/index.php" class="h-13 w-13 rounded-2xl bg-gradient-to-tr from-violet-500 to-purple-600 text-white flex items-center justify-center text-2xl shadow-lg shadow-violet-500/30 shrink-0 group-hover:scale-110 transition-transform" title="Gestionar sucursales">
                    <i class="fa-solid fa-sitemap"></i>
                </a>
            </div>
            <div class="mt-4 pt-3 border-t border-slate-200/60 flex items-center justify-between text-[11px] text-slate-500 font-medium relative z-10">
                <?php if (!empty($kpi_empresas)): ?>
                    <?php foreach (array_slice($kpi_empresas, 0, 3) as $idx => $ke): ?>
                        <?php if ($idx > 0): ?><span>•</span><?php endif; ?>
                        <a href="<?= BASE_URL ?>modules/equipos/index.php" class="hover:text-violet-600 transition underline-offset-2 hover:underline" title="Ver equipos de <?= htmlspecialchars($ke['nombre']) ?>">
                            <strong class="text-slate-800"><?= htmlspecialchars($ke['nombre']) ?></strong> (<?= (int)$ke['total'] ?>)
                        </a>
                    <?php endforeach; ?>
                <?php else: ?>
                    <span>Mastertec (36)</span><span>•</span><span>Suministros (42)</span><span>•</span><span>Centro (20)</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- KPI 3: COLABORADORES ACTIVOS -->
        <div class="relative overflow-hidden rounded-3xl p-6 bg-white/80 backdrop-blur-xl border border-emerald-500/20 shadow-xl shadow-emerald-500/5 group hover:-translate-y-1 hover:shadow-emerald-500/15 transition-all duration-300">
            <div class="absolute -right-6 -bottom-6 w-32 h-32 rounded-full bg-emerald-500/15 blur-2xl group-hover:scale-150 transition-transform duration-500"></div>
            <div class="flex items-start justify-between relative z-10">
                <a href="<?= BASE_URL ?>modules/empleados/index.php" class="block group/link">
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-extrabold uppercase tracking-wider bg-emerald-500/10 text-emerald-600 border border-emerald-500/20 mb-3 group-hover/link:bg-emerald-500/20 transition">
                        <i class="fa-solid fa-check-circle text-[9px]"></i> Asignaciones 100%
                    </span>
                    <h3 class="text-4xl font-black text-slate-900 tracking-tight mb-1 group-hover/link:text-emerald-600 transition"><?= number_format($kpis['total_colaboradores']) ?></h3>
                    <p class="text-xs font-semibold text-slate-600">Colaboradores Asignados</p>
                </a>
                <a href="<?= BASE_URL ?>modules/empleados/index.php" class="h-13 w-13 rounded-2xl bg-gradient-to-tr from-emerald-500 to-teal-600 text-white flex items-center justify-center text-2xl shadow-lg shadow-emerald-500/30 shrink-0 group-hover:scale-110 transition-transform" title="Ver colaboradores">
                    <i class="fa-solid fa-users-gear"></i>
                </a>
            </div>
            <div class="mt-4 pt-3 border-t border-slate-200/60 flex items-center justify-between text-[11px] text-slate-500 font-medium relative z-10">
                <?php if (!empty($kpi_colab_ciudades)): ?>
                    <?php foreach ($kpi_colab_ciudades as $idx => $kcc): ?>
                        <?php if ($idx > 0): ?><span>•</span><?php endif; ?>
                        <a href="<?= BASE_URL ?>modules/empleados/index.php" class="hover:text-emerald-600 transition underline-offset-2 hover:underline" title="Ver colaboradores en <?= htmlspecialchars($kcc['ciudad']) ?>">
                            <?= htmlspecialchars($kcc['ciudad']) ?>: <strong class="text-slate-800"><?= (int)$kcc['total'] ?></strong>
                        </a>
                    <?php endforeach; ?>
                <?php else: ?>
                    <span>Managua: <strong class="text-slate-800">72</strong></span><span>•</span><span>León: <strong class="text-slate-800">10</strong></span><span>•</span><span>Sébaco: <strong class="text-slate-800">4</strong></span>
                <?php endif; ?>
            </div>
        </div>

        <!-- KPI 4: CREDENCIALES & SEGURIDAD -->
        <div class="relative overflow-hidden rounded-3xl p-6 bg-white/80 backdrop-blur-xl border border-amber-500/20 shadow-xl shadow-amber-500/5 group hover:-translate-y-1 hover:shadow-amber-500/15 transition-all duration-300">
            <div class="absolute -right-6 -bottom-6 w-32 h-32 rounded-full bg-amber-500/15 blur-2xl group-hover:scale-150 transition-transform duration-500"></div>
            <div class="flex items-start justify-between relative z-10">
                <a href="<?= BASE_URL ?>modules/credenciales/index.php" class="block group/link">
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-extrabold uppercase tracking-wider bg-amber-500/10 text-amber-600 border border-amber-500/20 mb-3 group-hover/link:bg-amber-500/20 transition">
                        <i class="fa-solid fa-shield-halved text-[9px]"></i> Accesos & M365
                    </span>
                    <h3 class="text-4xl font-black text-slate-900 tracking-tight mb-1 group-hover/link:text-amber-600 transition"><?= number_format($kpis['total_credenciales']) ?></h3>
                    <p class="text-xs font-semibold text-slate-600">Credenciales Protegidas</p>
                </a>
                <a href="<?= BASE_URL ?>modules/credenciales/index.php" class="h-13 w-13 rounded-2xl bg-gradient-to-tr from-amber-500 to-orange-600 text-white flex items-center justify-center text-2xl shadow-lg shadow-amber-500/30 shrink-0 group-hover:scale-110 transition-transform" title="Ver bóveda de credenciales">
                    <i class="fa-solid fa-lock"></i>
                </a>
            </div>
            <div class="mt-4 pt-3 border-t border-slate-200/60 flex items-center justify-between text-[11px] text-slate-500 font-medium relative z-10">
                <a href="<?= BASE_URL ?>modules/credenciales/index.php?tab=m365" class="hover:text-amber-600 transition underline-offset-2 hover:underline" title="Ver credenciales M365">M365: <strong class="text-slate-800"><?= $m365_cred ?></strong></a>
                <span>•</span>
                <a href="<?= BASE_URL ?>modules/credenciales/index.php?tab=equipos" class="hover:text-amber-600 transition underline-offset-2 hover:underline" title="Ver credenciales de Equipos">Equipos: <strong class="text-slate-800"><?= $eq_cred ?></strong></a>
                <span>•</span>
                <a href="<?= BASE_URL ?>modules/credenciales/index.php?tab=web" class="hover:text-amber-600 transition underline-offset-2 hover:underline" title="Ver credenciales Web">Web: <strong class="text-slate-800"><?= $web_cred ?></strong></a>
            </div>
        </div>

    </div>

    <!-- SECCIÓN DE DISTRIBUCIÓN POR SUCURSAL Y TIPO DE HARDWARE -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Tarjeta: Equipos por Sucursal -->
        <div class="lg:col-span-2 bg-white/80 backdrop-blur-xl rounded-3xl p-6 shadow-sm border border-slate-200/80 flex flex-col justify-between">
            <div>
                <div class="flex items-center justify-between mb-5">
                    <div>
                        <h3 class="text-base font-extrabold text-slate-800 flex items-center gap-2">
                            <i class="fa-solid fa-chart-column text-cyan-500"></i> Distribución de Equipos por Sucursal
                        </h3>
                        <p class="text-xs text-slate-400 mt-0.5">Desglose de hardware activo según sede y empresa corporativa · <span class="text-cyan-600 font-semibold">clic para ver el detalle</span></p>
                    </div>
                    <a href="<?= BASE_URL ?>modules/equipos/index.php" class="text-xs font-bold text-cyan-600 hover:text-cyan-700 bg-cyan-50 px-3 py-1.5 rounded-xl border border-cyan-100 transition">
                        Ver Lista Completa <i class="fa-solid fa-arrow-right text-[10px] ml-1"></i>
                    </a>
                </div>

                <div class="space-y-3" id="suc-distribucion">
                    <?php 
                    $maxSucVal = !empty($equipos_por_sucursal) ? max(array_column($equipos_por_sucursal, 'total')) : 1;
                    $sucColors = [
                        'Suministros Managua' => 'from-cyan-500 to-blue-600',
                        'Mastertec Managua'   => 'from-violet-500 to-purple-600',
                        'Centro de Pinturas Managua' => 'from-emerald-500 to-teal-600',
                        'Mastertec León'      => 'from-amber-500 to-orange-600',
                        'Suministros León'    => 'from-blue-500 to-indigo-600',
                        'Suministros Sébaco'  => 'from-rose-500 to-pink-600'
                    ];
                    $tipoIcons = [
                        'Laptop'    => 'fa-laptop text-cyan-500',
                        'Desktop'   => 'fa-desktop text-violet-500',
                        'Impresora' => 'fa-print text-emerald-500',
                        'Otro'      => 'fa-plug text-amber-500',
                        'UPS'       => 'fa-plug text-amber-500',
                    ];
                    $estadoBadges = [
                        'Asignado'      => 'bg-blue-50 text-blue-600 border-blue-200',
                        'Disponible'    => 'bg-emerald-50 text-emerald-600 border-emerald-200',
                        'En Reparación' => 'bg-amber-50 text-amber-600 border-amber-200',
                        'Reparación'    => 'bg-amber-50 text-amber-600 border-amber-200',
                        'De Baja'       => 'bg-rose-50 text-rose-600 border-rose-200',
                    ];

                    foreach ($equipos_por_sucursal as $suc):
                        $sid = (int) $suc['sucursal_id'];
                        $pct = round(($suc['total'] / max(1, $kpis['total_equipos'])) * 100);
                        $grad = $sucColors[$suc['sucursal_nombre']] ?? 'from-cyan-500 to-blue-600';
                        $sdet = $suc_detalles[$sid] ?? ['tipo' => [], 'estado' => [], 'marca' => []];
                    ?>
                        <div class="suc-item rounded-2xl bg-slate-50/80 border border-slate-100 hover:border-cyan-300 hover:shadow-md transition-all duration-300 overflow-hidden" data-suc="<?= $sid ?>">
                            <button type="button" onclick="toggleSucDetalle(<?= $sid ?>)" aria-expanded="false" aria-controls="suc-panel-<?= $sid ?>"
                                    class="w-full p-3.5 text-left cursor-pointer group focus:outline-none transition-colors">
                                <div class="flex items-center justify-between text-xs font-semibold text-slate-800 mb-2">
                                    <span class="flex items-center gap-2">
                                        <span class="h-2.5 w-2.5 rounded-full bg-gradient-to-r <?= $grad ?> shadow-sm shrink-0"></span>
                                        <span class="font-bold text-slate-800 group-hover:text-cyan-600 transition-colors"><?= htmlspecialchars($suc['sucursal_nombre']) ?></span>
                                        <span class="text-[10px] font-bold text-slate-400 bg-white/80 px-2 py-0.5 rounded-md border border-slate-200/60">🏢 <?= htmlspecialchars($suc['empresa_nombre']) ?></span>
                                    </span>
                                    <div class="flex items-center gap-3">
                                        <span class="font-bold text-slate-700 font-mono"><?= $suc['total'] ?> equipos <span class="text-slate-400 font-normal">(<?= $pct ?>%)</span></span>
                                        <i class="suc-chevron fa-solid fa-chevron-down text-xs text-slate-400 group-hover:text-cyan-600 transition-transform duration-300"></i>
                                    </div>
                                </div>
                                <div class="w-full h-2 rounded-full bg-slate-200/80 overflow-hidden">
                                    <div class="h-full rounded-full bg-gradient-to-r <?= $grad ?> transition-all duration-500" style="width: <?= $pct ?>%"></div>
                                </div>
                            </button>

                            <!-- Panel Expandible de la Sucursal -->
                            <div id="suc-panel-<?= $sid ?>" class="suc-panel overflow-hidden transition-all duration-300 ease-out" style="max-height:0">
                                <div class="px-4 pb-4 pt-2 space-y-4 border-t border-slate-200/60 bg-white/70">
                                    
                                    <!-- 1. Desglose por Tipo de Equipo -->
                                    <div class="pt-2">
                                        <div class="flex items-center justify-between mb-2">
                                            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider flex items-center gap-1.5">
                                                <i class="fa-solid fa-layer-group text-cyan-500"></i> Tipos de Hardware
                                            </p>
                                            <span class="text-[10px] text-slate-400">Clic en un tipo para filtrar</span>
                                        </div>
                                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                                            <?php foreach ($sdet['tipo'] as $tNombre => $tCant): 
                                                $tIconClass = $tipoIcons[$tNombre] ?? 'fa-microchip text-slate-500';
                                                $tFilterVal = in_array($tNombre, ['Laptop', 'Desktop', 'Impresora']) ? $tNombre : '__otros__';
                                            ?>
                                                <a href="<?= BASE_URL ?>modules/equipos/index.php?sucursal_id=<?= $sid ?>&tipo=<?= urlencode($tFilterVal) ?>"
                                                   class="p-2.5 rounded-xl bg-slate-50 border border-slate-200/80 hover:border-cyan-400 hover:bg-cyan-50/50 hover:shadow-sm transition group/tipo flex items-center justify-between">
                                                    <div class="flex items-center gap-2 min-w-0">
                                                        <i class="fa-solid <?= $tIconClass ?> text-xs shrink-0"></i>
                                                        <span class="text-xs font-semibold text-slate-700 group-hover/tipo:text-cyan-700 truncate"><?= htmlspecialchars($tNombre) ?></span>
                                                    </div>
                                                    <span class="font-mono font-black text-slate-900 text-xs ml-1"><?= (int)$tCant ?></span>
                                                </a>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>

                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <!-- 2. Por Estado Operativo -->
                                        <div>
                                            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-2 flex items-center gap-1.5">
                                                <i class="fa-solid fa-signal text-emerald-500"></i> Estado Operativo
                                            </p>
                                            <div class="flex flex-wrap gap-1.5">
                                                <?php foreach ($sdet['estado'] as $eNombre => $eCant): ?>
                                                    <span class="px-2.5 py-1 rounded-lg border text-[11px] font-semibold <?= $estadoBadges[$eNombre] ?? 'bg-slate-50 text-slate-600 border-slate-200' ?>">
                                                        <?= htmlspecialchars($eNombre) ?> <strong class="font-black font-mono ml-0.5"><?= (int)$eCant ?></strong>
                                                    </span>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>

                                        <!-- 3. Top Marcas -->
                                        <div>
                                            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-2 flex items-center gap-1.5">
                                                <i class="fa-solid fa-tag text-violet-500"></i> Principales Marcas
                                            </p>
                                            <div class="space-y-1.5">
                                                <?php 
                                                $iMarcas = 0;
                                                foreach ($sdet['marca'] as $mNombre => $mCant): 
                                                    if ($iMarcas++ >= 3) break;
                                                    $pctMarca = round(($mCant / max(1, $suc['total'])) * 100);
                                                ?>
                                                    <div class="space-y-0.5">
                                                        <div class="flex justify-between text-[11px]">
                                                            <span class="text-slate-600 font-medium truncate"><?= htmlspecialchars((string)$mNombre) ?></span>
                                                            <span class="font-bold text-slate-800 font-mono"><?= (int)$mCant ?> <span class="text-slate-400 font-normal">(<?= $pctMarca ?>%)</span></span>
                                                        </div>
                                                        <div class="h-1 rounded-full bg-slate-100 overflow-hidden">
                                                            <div class="h-full rounded-full bg-gradient-to-r <?= $grad ?>" style="width: <?= $pctMarca ?>%"></div>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- 4. Botón Ver en Inventario -->
                                    <div class="pt-1">
                                        <a href="<?= BASE_URL ?>modules/equipos/index.php?sucursal_id=<?= $sid ?>"
                                           class="flex items-center justify-center gap-2 w-full py-2.5 rounded-xl bg-white border border-slate-300 hover:border-cyan-500 hover:bg-cyan-500 hover:text-white text-slate-700 text-xs font-bold transition-all duration-300 shadow-sm">
                                            <span>Ver todos los <?= $suc['total'] ?> equipos de <?= htmlspecialchars($suc['sucursal_nombre']) ?></span>
                                            <i class="fa-solid fa-arrow-right text-[10px]"></i>
                                        </a>
                                    </div>

                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <script>
                    // Acordeón interactivo para la Distribución por Sucursales
                    function toggleSucDetalle(sucId) {
                        document.querySelectorAll('#suc-distribucion .suc-item').forEach(function (item) {
                            var btn = item.querySelector('button[aria-controls]');
                            var panel = item.querySelector('.suc-panel');
                            var chev = item.querySelector('.suc-chevron');
                            var abrir = parseInt(item.dataset.suc) === parseInt(sucId) && btn.getAttribute('aria-expanded') !== 'true';

                            btn.setAttribute('aria-expanded', abrir ? 'true' : 'false');
                            chev.classList.toggle('rotate-180', abrir);
                            item.classList.toggle('shadow-md', abrir);
                            item.classList.toggle('border-cyan-400', abrir);
                            panel.style.maxHeight = abrir ? panel.scrollHeight + 'px' : '0';
                        });
                    }
                </script>
            </div>
        </div>


        <!-- Tarjeta: Composición de Hardware -->
        <div class="bg-white/80 backdrop-blur-xl rounded-3xl p-6 shadow-sm border border-slate-200/80 flex flex-col justify-between">
            <div>
                <h3 class="text-base font-extrabold text-slate-800 mb-1 flex items-center gap-2">
                    <i class="fa-solid fa-pie-chart text-violet-500"></i> Composición de Hardware
                </h3>
                <p class="text-xs text-slate-400 mb-5">Proporción por tipo de tecnología · <span class="text-violet-500 font-semibold">clic para ver el detalle</span></p>

                <?php
                $hw_cats = [
                    ['key' => 'laptop',    'titulo' => 'Laptops Corporativas',   'sub' => 'Portátiles de trabajo',       'icon' => 'fa-laptop',  'color' => 'cyan',    'total' => $laptops_count,    'filtro' => 'Laptop'],
                    ['key' => 'desktop',   'titulo' => 'Desktops & Clones',      'sub' => 'Computadoras de escritorio',  'icon' => 'fa-desktop', 'color' => 'violet',  'total' => $desktops_count,   'filtro' => 'Desktop'],
                    ['key' => 'impresora', 'titulo' => 'Impresoras & Térmicas',  'sub' => 'Láser, Inyección y Matricial', 'icon' => 'fa-print',   'color' => 'emerald', 'total' => $impresoras_count, 'filtro' => 'Impresora'],
                    ['key' => 'otros',     'titulo' => 'UPS & Energía',          'sub' => 'Respaldo, redes y otros',     'icon' => 'fa-plug',    'color' => 'amber',   'total' => $otros_count,      'filtro' => '__otros__'],
                ];
                $hw_total_general = max(1, $laptops_count + $desktops_count + $impresoras_count + $otros_count);
                $hw_estado_colores = [
                    'Asignado'      => 'bg-blue-50 text-blue-600 border-blue-200',
                    'Disponible'    => 'bg-emerald-50 text-emerald-600 border-emerald-200',
                    'En Reparación' => 'bg-amber-50 text-amber-600 border-amber-200',
                    'Reparación'    => 'bg-amber-50 text-amber-600 border-amber-200',
                    'De Baja'       => 'bg-rose-50 text-rose-600 border-rose-200',
                ];
                // Lista de barras horizontales (top N + "N más")
                $hw_render_barras = function (array $items, int $total, string $color, int $limite = 5) {
                    $total = max(1, $total);
                    $i = 0;
                    foreach ($items as $nombre => $cant) {
                        if ($i++ >= $limite) break;
                        $pct = round(($cant / $total) * 100);
                        echo '<div class="space-y-1">'
                           . '<div class="flex justify-between text-[11px]"><span class="text-slate-600 font-medium truncate pr-2">' . htmlspecialchars((string) $nombre) . '</span>'
                           . '<span class="font-bold text-slate-800 font-mono shrink-0">' . (int) $cant . ' <span class="text-slate-400 font-normal">(' . $pct . '%)</span></span></div>'
                           . '<div class="h-1.5 rounded-full bg-white/80 overflow-hidden"><div class="h-full rounded-full bg-' . $color . '-500 transition-all duration-700 ease-out" data-w="' . $pct . '" style="width:0"></div></div>'
                           . '</div>';
                    }
                    $resto = count($items) - $limite;
                    if ($resto > 0) {
                        echo '<p class="text-[10px] text-slate-400 font-semibold">+ ' . $resto . ' más…</p>';
                    }
                };
                ?>

                <div class="space-y-4" id="hw-composicion">
                <?php foreach ($hw_cats as $c):
                    $k = $c['key'];
                    $col = $c['color'];
                    $det = $hw_detalle[$k] ?? ['estado' => [], 'marca' => [], 'sucursal' => [], 'tipo' => []];
                    $pct_cat = round(($c['total'] / $hw_total_general) * 100);
                ?>
                    <div class="hw-item rounded-2xl bg-<?= $col ?>-50/60 border border-<?= $col ?>-100 hover:border-<?= $col ?>-300 hover:shadow-md transition-all duration-300" data-hw="<?= $k ?>">
                        <button type="button" id="hw-toggle-<?= $k ?>" onclick="toggleHwDetalle('<?= $k ?>')" aria-expanded="false" aria-controls="hw-panel-<?= $k ?>"
                                class="w-full flex items-center justify-between p-3.5 text-left cursor-pointer group focus:outline-none">
                            <div class="flex items-center gap-3">
                                <div class="h-10 w-10 rounded-xl bg-<?= $col ?>-500 text-white flex items-center justify-center text-lg shadow-md shadow-<?= $col ?>-500/20 transition-transform duration-300 group-hover:scale-110">
                                    <i class="fa-solid <?= $c['icon'] ?>"></i>
                                </div>
                                <div>
                                    <p class="text-xs font-bold text-slate-800"><?= htmlspecialchars($c['titulo']) ?></p>
                                    <p class="text-[11px] text-slate-500"><?= htmlspecialchars($c['sub']) ?></p>
                                </div>
                            </div>
                            <div class="flex items-center gap-3">
                                <div class="text-right leading-tight">
                                    <span class="text-lg font-black text-slate-900 font-mono"><?= $c['total'] ?></span>
                                    <p class="text-[10px] text-slate-400 font-semibold"><?= $pct_cat ?>%</p>
                                </div>
                                <i class="hw-chevron fa-solid fa-chevron-down text-xs text-slate-400 transition-transform duration-300"></i>
                            </div>
                        </button>

                        <div id="hw-panel-<?= $k ?>" class="hw-panel overflow-hidden transition-all duration-300 ease-out" style="max-height:0">
                            <div class="px-3.5 pb-4 pt-1 space-y-4 border-t border-<?= $col ?>-100/80">
                            <?php if ($c['total'] === 0): ?>
                                <p class="text-[11px] text-slate-400 text-center py-3">No hay equipos registrados en esta categoría.</p>
                            <?php else: ?>
                                <!-- Por Estado -->
                                <div class="pt-3">
                                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-2"><i class="fa-solid fa-signal mr-1"></i>Por estado</p>
                                    <div class="flex flex-wrap gap-1.5">
                                        <?php foreach ($det['estado'] as $est => $cant): ?>
                                            <span class="px-2.5 py-1 rounded-lg border text-[11px] font-semibold <?= $hw_estado_colores[$est] ?? 'bg-slate-50 text-slate-600 border-slate-200' ?>">
                                                <?= htmlspecialchars($est) ?> <span class="font-black font-mono ml-0.5"><?= (int) $cant ?></span>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <?php if ($k === 'otros' && count($det['tipo']) > 0): ?>
                                <!-- Subtipos (solo en Otros) -->
                                <div>
                                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-2"><i class="fa-solid fa-layer-group mr-1"></i>Por tipo</p>
                                    <div class="space-y-2"><?php $hw_render_barras($det['tipo'], $c['total'], $col, 6); ?></div>
                                </div>
                                <?php endif; ?>

                                <!-- Por Marca -->
                                <div>
                                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-2"><i class="fa-solid fa-tag mr-1"></i>Top marcas</p>
                                    <div class="space-y-2"><?php $hw_render_barras($det['marca'], $c['total'], $col); ?></div>
                                </div>

                                <!-- Por Sucursal -->
                                <div>
                                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-2"><i class="fa-solid fa-building mr-1"></i>Por sucursal</p>
                                    <div class="space-y-2"><?php $hw_render_barras($det['sucursal'], $c['total'], $col); ?></div>
                                </div>
                            <?php endif; ?>

                                <a href="<?= BASE_URL ?>modules/equipos/index.php?tipo=<?= urlencode($c['filtro']) ?>" id="hw-link-<?= $k ?>"
                                   class="flex items-center justify-center gap-2 w-full py-2 rounded-xl bg-white border border-<?= $col ?>-200 text-<?= $col ?>-600 hover:bg-<?= $col ?>-500 hover:text-white hover:border-<?= $col ?>-500 text-[11px] font-bold transition-all duration-300">
                                    Ver en inventario <i class="fa-solid fa-arrow-right text-[10px]"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                </div>

                <script>
                    // Acordeón: abre una categoría a la vez y anima las barras al desplegar
                    function toggleHwDetalle(key) {
                        document.querySelectorAll('#hw-composicion .hw-item').forEach(function (item) {
                            var btn = item.querySelector('button[aria-controls]');
                            var panel = item.querySelector('.hw-panel');
                            var chev = item.querySelector('.hw-chevron');
                            var abrir = item.dataset.hw === key && btn.getAttribute('aria-expanded') !== 'true';

                            btn.setAttribute('aria-expanded', abrir ? 'true' : 'false');
                            chev.classList.toggle('rotate-180', abrir);
                            item.classList.toggle('shadow-lg', abrir);
                            panel.style.maxHeight = abrir ? panel.scrollHeight + 'px' : '0';

                            panel.querySelectorAll('[data-w]').forEach(function (bar) {
                                bar.style.width = '0';
                                if (abrir) {
                                    requestAnimationFrame(function () {
                                        requestAnimationFrame(function () { bar.style.width = bar.dataset.w + '%'; });
                                    });
                                }
                            });
                        });
                    }
                </script>
            </div>
        </div>
    </div>

    <!-- SECCIÓN DE ACTIVIDAD RECIENTE -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Últimos Equipos -->
        <div class="bg-white/80 backdrop-blur-xl rounded-3xl p-6 shadow-sm border border-slate-200/80 flex flex-col h-80 overflow-hidden">
            <h3 class="text-sm font-bold text-slate-800 mb-4 flex items-center gap-2">
                <i class="fa-solid fa-server text-violet-500"></i> Últimos Equipos Registrados
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
                        ?>
                            <div class="flex items-center justify-between p-3 rounded-2xl border border-slate-100 hover:border-violet-200 hover:shadow-sm transition bg-white/90">
                                <div class="flex items-center gap-3">
                                    <div class="h-10 w-10 rounded-xl bg-violet-50 text-violet-500 flex items-center justify-center shrink-0">
                                        <i class="fa-solid fa-laptop"></i>
                                    </div>
                                    <div>
                                        <p class="text-xs font-bold text-slate-800"><?= htmlspecialchars($eq['marca'] . ' ' . $eq['modelo']) ?></p>
                                        <p class="text-[11px] text-slate-400 font-medium"><?= htmlspecialchars($eq['tipo']) ?> • <?= htmlspecialchars($eq['sucursal_nombre'] ?? 'N/A') ?></p>
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
        <div class="bg-white/80 backdrop-blur-xl rounded-3xl p-6 shadow-sm border border-slate-200/80 flex flex-col h-80 overflow-hidden">
            <h3 class="text-sm font-bold text-slate-800 mb-4 flex items-center gap-2">
                <i class="fa-solid fa-users text-emerald-500"></i> Últimos Colaboradores Asignados
            </h3>
            <div class="overflow-y-auto pr-2 custom-scrollbar flex-1">
                <?php if (empty($ultimos_colaboradores)): ?>
                    <div class="text-center text-slate-400 py-8 text-sm">No hay colaboradores registrados.</div>
                <?php else: ?>
                    <div class="space-y-3">
                        <?php foreach ($ultimos_colaboradores as $col): ?>
                            <div class="flex items-center justify-between p-3 rounded-2xl border border-slate-100 hover:border-emerald-200 hover:shadow-sm transition bg-white/90">
                                <div class="flex items-center gap-3">
                                    <div class="h-10 w-10 rounded-xl bg-emerald-50 text-emerald-500 flex items-center justify-center shrink-0">
                                        <i class="fa-solid fa-user"></i>
                                    </div>
                                    <div>
                                        <p class="text-xs font-bold text-slate-800"><?= htmlspecialchars($col['nombre']) ?></p>
                                        <p class="text-[11px] text-slate-400 font-medium"><?= htmlspecialchars($col['cargo']) ?> • <?= htmlspecialchars($col['sucursal_nombre'] ?? 'Managua') ?></p>
                                    </div>
                                </div>
                                <span class="text-[10px] font-bold text-slate-500 bg-slate-50 px-2.5 py-1.5 rounded-xl border border-slate-100">
                                    <?= htmlspecialchars($col['departamento'] ?? 'General') ?>
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
        <div class="bg-white/80 backdrop-blur-xl rounded-3xl p-6 shadow-sm border border-slate-200/80">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-sm font-bold text-slate-800 flex items-center gap-2">
                    <i class="fa-solid fa-triangle-exclamation text-amber-500"></i> Tareas IT Urgentes
                </h3>
                <a href="<?= BASE_URL ?>modules/tareas/index.php" class="text-[10px] font-bold uppercase tracking-widest text-cyan-600 hover:text-cyan-700 transition">Ver todas</a>
            </div>
            
            <?php if (empty($tareas_urgentes)): ?>
                <div class="p-6 text-center text-slate-400 text-xs">No hay tareas pendientes.</div>
            <?php else: ?>
                <div class="space-y-3">
                    <?php foreach ($tareas_urgentes as $tu): ?>
                        <div class="flex items-start justify-between gap-3 p-3 rounded-2xl bg-slate-50/80 border border-slate-100 hover:border-amber-200 transition">
                            <div>
                                <p class="text-xs font-bold text-slate-800"><?= htmlspecialchars($tu['titulo']) ?></p>
                                <p class="text-[10px] text-slate-500 mt-1"><i class="fa-regular fa-user mr-1"></i> <?= htmlspecialchars($tu['asignado_nombre'] ?? 'Sin Asignar') ?></p>
                            </div>
                            <span class="text-[9px] font-bold uppercase px-2 py-1 rounded-md shrink-0 <?= $tu['estado'] === 'Vencida' ? 'bg-rose-100 text-rose-600' : 'bg-amber-100 text-amber-600' ?>"><?= $tu['estado'] ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Renovaciones M365 -->
        <div class="bg-white/80 backdrop-blur-xl rounded-3xl p-6 shadow-sm border border-slate-200/80">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-sm font-bold text-slate-800 flex items-center gap-2">
                    <i class="fa-brands fa-microsoft text-blue-500"></i> Renovaciones M365 Próximas
                </h3>
                <a href="<?= BASE_URL ?>modules/credenciales/index.php?tab=m365" class="text-[10px] font-bold uppercase tracking-widest text-cyan-600 hover:text-cyan-700 transition">Ver todas</a>
            </div>
            
            <?php if (empty($m365_proximos)): ?>
                <div class="p-6 text-center text-slate-400 text-xs">No hay renovaciones próximas.</div>
            <?php else: ?>
                <div class="space-y-3">
                    <?php foreach ($m365_proximos as $m): ?>
                        <div class="flex items-center justify-between gap-3 p-3 rounded-2xl bg-slate-50/80 border border-slate-100 hover:border-blue-200 transition">
                            <div class="overflow-hidden">
                                <p class="text-xs font-bold text-slate-800 truncate"><?= htmlspecialchars($m['correo']) ?></p>
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
require_once __DIR__ . '/includes/footer.php';
?>
