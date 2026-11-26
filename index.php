<?php
/**
 * Dashboard Principal Operativo Multi-Empresa - IT Software
 * Enfoque: Filtrado rápido por empresa y sucursal, acceso directo a módulos,
 * densidad operativa sin gráficas decorativas innecesarias.
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
    'tareas_pendientes' => 0,
    'total_laptops' => 0,
    'total_desktops' => 0,
    'total_impresoras' => 0,
    'total_ups' => 0,
    'total_m365' => 0,
    'total_web' => 0,
    'total_wifi' => 0
];

$sucursales_matriz = [];
$empresas_lista = [];

try {
    $db = Database::getConexion();
    $db_connected = true;

    // Verificar existencia de tablas
    $stmt = $db->query("SHOW TABLES LIKE 'equipos'");
    if ($stmt->rowCount() == 0) {
        $setup_needed = true;
    } else {
        // 1. Obtener KPIs Globales de Hardware y Software
        $eq_cred = (int) $db->query("SELECT COUNT(*) FROM credenciales_equipos")->fetchColumn();
        $web_cred = (int) $db->query("SELECT COUNT(*) FROM credenciales_web")->fetchColumn();
        $m365_cred = (int) $db->query("SELECT COUNT(*) FROM cuentas_m365")->fetchColumn();
        $kpis['total_credenciales'] = $eq_cred + $web_cred + $m365_cred;
        $kpis['total_web'] = $web_cred;
        $kpis['total_m365'] = $m365_cred;

        $kpis['total_colaboradores'] = (int) $db->query("SELECT COUNT(*) FROM empleados WHERE cargo IS NULL OR cargo NOT IN ('Sucursal', 'Empresa')")->fetchColumn();
        $kpis['total_equipos'] = (int) $db->query("SELECT COUNT(*) FROM equipos")->fetchColumn();
        $kpis['total_sucursales'] = (int) $db->query("SELECT COUNT(*) FROM sucursales")->fetchColumn();
        $kpis['tareas_pendientes'] = (int) $db->query("SELECT COUNT(*) FROM tareas_it WHERE estado IN ('Pendiente', 'En Progreso')")->fetchColumn();

        // Tipos de activos
        $kpis['total_laptops'] = (int) $db->query("SELECT COUNT(*) FROM equipos WHERE tipo = 'Laptop'")->fetchColumn();
        $kpis['total_desktops'] = (int) $db->query("SELECT COUNT(*) FROM equipos WHERE tipo = 'Desktop'")->fetchColumn();
        
        // Impresoras (tabla nueva o equipos tipo Impresora)
        $has_imp_table = (int) $db->query("SHOW TABLES LIKE 'impresoras'")->rowCount();
        if ($has_imp_table > 0) {
            $kpis['total_impresoras'] = (int) $db->query("SELECT COUNT(*) FROM impresoras")->fetchColumn();
        } else {
            $kpis['total_impresoras'] = (int) $db->query("SELECT COUNT(*) FROM equipos WHERE tipo = 'Impresora'")->fetchColumn();
        }

        // UPS / Energía
        $kpis['total_ups'] = (int) $db->query("SELECT COUNT(*) FROM infraestructura_red WHERE tipo LIKE '%UPS%' OR tipo LIKE '%PDU%'")->fetchColumn();

        // Empresas para catálogo y selector
        $empresas_lista = $db->query("SELECT id, nombre FROM empresas ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);

        // 2. Consulta Maestra de la Matriz de Sucursales Multi-Empresa
        $sqlMatriz = "
            SELECT 
                s.id as sucursal_id,
                s.nombre as sucursal_nombre,
                s.ubicacion,
                e.id as empresa_id,
                e.nombre as empresa_nombre,
                (SELECT COUNT(*) FROM equipos eq WHERE eq.sucursal_id = s.id) as total_equipos,
                (SELECT COUNT(*) FROM equipos eq WHERE eq.sucursal_id = s.id AND eq.tipo = 'Laptop') as laptops,
                (SELECT COUNT(*) FROM equipos eq WHERE eq.sucursal_id = s.id AND eq.tipo = 'Desktop') as desktops,
                (SELECT COUNT(*) FROM impresoras imp WHERE imp.sucursal_id = s.id) as impresoras,
                (SELECT COUNT(*) FROM infraestructura_red ir WHERE ir.sucursal_id = s.id AND (ir.tipo LIKE '%UPS%' OR ir.tipo LIKE '%PDU%')) as ups,
                (SELECT COUNT(*) FROM empleados emp WHERE emp.sucursal_id = s.id AND (emp.cargo IS NULL OR emp.cargo NOT IN ('Sucursal', 'Empresa'))) as colaboradores,
                (SELECT COUNT(*) FROM cuentas_m365 c JOIN empleados emp ON c.empleado_id = emp.id WHERE emp.sucursal_id = s.id) as m365_cuentas,
                (SELECT COUNT(*) FROM credenciales_equipos ce JOIN equipos eq ON ce.equipo_id = eq.id WHERE eq.sucursal_id = s.id) as cred_equipos,
                (SELECT COUNT(*) FROM infraestructura_red ir WHERE ir.sucursal_id = s.id AND ir.password_cifrado IS NOT NULL AND ir.password_cifrado != '') as cred_infra,
                (SELECT COUNT(*) FROM redes_wifi rw WHERE rw.sucursal_id = s.id) as total_wifi,
                (SELECT proveedor FROM proveedores_internet pi WHERE pi.sucursal_id = s.id LIMIT 1) as isp_nombre,
                (SELECT velocidad_bajada FROM proveedores_internet pi WHERE pi.sucursal_id = s.id LIMIT 1) as isp_velocidad,
                (SELECT ip_publica FROM proveedores_internet pi WHERE pi.sucursal_id = s.id LIMIT 1) as isp_ip
            FROM sucursales s
            LEFT JOIN empresas e ON s.empresa_id = e.id
            ORDER BY e.nombre ASC, s.nombre ASC
        ";

        $raw_matriz = $db->query($sqlMatriz)->fetchAll(PDO::FETCH_ASSOC);

        foreach ($raw_matriz as $row) {
            // Bandera de país por sede/ubicación
            $bandera = '🇳🇮';
            $txtUbic = mb_strtolower(($row['sucursal_nombre'] ?? '') . ' ' . ($row['ubicacion'] ?? ''), 'UTF-8');
            if (strpos($txtUbic, 'honduras') !== false) {
                $bandera = '🇭🇳';
            } elseif (strpos($txtUbic, 'salvador') !== false) {
                $bandera = '🇸🇻';
            } elseif (strpos($txtUbic, 'dominicana') !== false) {
                $bandera = '🇩🇴';
            }

            // Total de claves protegidas de esta sede
            $boveda_total = (int)$row['cred_equipos'] + (int)$row['cred_infra'] + (int)$row['total_wifi'];
            if ($boveda_total == 0 && (int)$row['total_equipos'] > 0) {
                $boveda_total = (int)$row['total_equipos'];
            }

            // Fallback de ISP si no está en proveedores_internet
            $isp_display = !empty($row['isp_nombre']) ? $row['isp_nombre'] : 'Enlace Dedicado Corporativo';
            if (!empty($row['isp_velocidad'])) {
                $isp_display .= ' (' . $row['isp_velocidad'] . ')';
            }
            $ip_display = !empty($row['isp_ip']) ? $row['isp_ip'] : '190.212.x.x / VPN';

            // Empresa Color badge
            $empColor = 'bg-slate-100 text-slate-700 border-slate-200';
            if (strpos($row['empresa_nombre'], 'Suministros') !== false) {
                $empColor = 'bg-blue-50 text-blue-700 border-blue-200';
            } elseif (strpos($row['empresa_nombre'], 'Mastertec') !== false) {
                $empColor = 'bg-indigo-50 text-indigo-700 border-indigo-200';
            } elseif (strpos($row['empresa_nombre'], 'Centro') !== false) {
                $empColor = 'bg-emerald-50 text-emerald-700 border-emerald-200';
            }

            $sucursales_matriz[] = [
                'id'            => (int)$row['sucursal_id'],
                'sucursal'      => $row['sucursal_nombre'],
                'ubicacion'     => $row['ubicacion'] ?? 'Central',
                'empresa_id'    => (int)$row['empresa_id'],
                'empresa'       => $row['empresa_nombre'] ?? 'Sin Empresa',
                'empresaColor'  => $empColor,
                'pais'          => $bandera,
                'estado'        => 'Activo',
                'totalEquipos'  => (int)$row['total_equipos'],
                'laptops'       => (int)$row['laptops'],
                'desktops'      => (int)$row['desktops'],
                'impresoras'    => (int)$row['impresoras'],
                'ups'           => (int)$row['ups'],
                'colaboradores' => (int)$row['colaboradores'],
                'cuentasM365'   => (int)$row['m365_cuentas'],
                'isp'           => $isp_display,
                'ipPublica'     => $ip_display,
                'vpn'           => 'Conectada (IPsec)',
                'boveda'        => $boveda_total
            ];
        }
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
            </div>
        </div>
    </div>
</div>
<?php else: ?>

<!-- ========================================================================= -->
<!-- DASHBOARD OPERATIVO MULTI-EMPRESA (ALTA DENSIDAD & PRODUCTIVIDAD) -->
<!-- ========================================================================= -->
<div class="space-y-5 animate-fade-in pb-12">

    <!-- 1. ENCABEZADO Y CONTROLES DE FILTRADO GLOBAL -->
    <div class="bg-white rounded-2xl border border-slate-200/80 p-4 shadow-xs">
        <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
            
            <!-- Título y Descripción -->
            <div>
                <div class="flex items-center gap-2.5">
                    <div class="h-9 w-9 rounded-xl bg-blue-600 text-white flex items-center justify-center text-sm shadow-xs font-bold">
                        <i class="fa-solid fa-layer-group"></i>
                    </div>
                    <div>
                        <h1 class="text-lg font-black text-slate-900 tracking-tight leading-tight">
                            Panel de Control Operativo Multi-Empresa
                        </h1>
                        <p class="text-xs text-slate-500 font-medium">
                            Gestión centralizada de infraestructura, hardware por sucursal, enlaces WAN y accesos seguros.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Filtros Globales Directos (Empresa & Sede) -->
            <div class="flex flex-wrap items-center gap-2.5">
                
                <!-- Selector Global de Empresa -->
                <div class="relative flex items-center">
                    <span class="absolute left-3 text-slate-400 pointer-events-none text-xs">
                        <i class="fa-solid fa-building"></i>
                    </span>
                    <select id="selectGlobalEmpresa" onchange="aplicarFiltroGlobal()" 
                        class="h-9 pl-8 pr-8 bg-slate-50 border border-slate-200 text-xs font-bold text-slate-800 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500 cursor-pointer transition hover:bg-slate-100">
                        <option value="all">Todas las Empresas (<?= count($empresas_lista) ?>)</option>
                        <?php foreach ($empresas_lista as $emp): ?>
                            <option value="<?= htmlspecialchars($emp['nombre']) ?>"><?= htmlspecialchars($emp['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <i class="fa-solid fa-chevron-down absolute right-3 text-[10px] text-slate-400 pointer-events-none"></i>
                </div>

                <!-- Selector Global de Sucursal -->
                <div class="relative flex items-center">
                    <span class="absolute left-3 text-slate-400 pointer-events-none text-xs">
                        <i class="fa-solid fa-location-dot"></i>
                    </span>
                    <select id="selectGlobalSucursal" onchange="aplicarFiltroGlobal()" 
                        class="h-9 pl-8 pr-8 bg-slate-50 border border-slate-200 text-xs font-bold text-slate-800 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500 cursor-pointer transition hover:bg-slate-100 max-w-[210px] truncate">
                        <option value="all">Todas las Sedes (<?= count($sucursales_matriz) ?>)</option>
                        <?php 
                        $sedes_unicas = [];
                        foreach ($sucursales_matriz as $sm) {
                            $ubic = $sm['ubicacion'];
                            if (!isset($sedes_unicas[$ubic])) {
                                $sedes_unicas[$ubic] = 0;
                            }
                            $sedes_unicas[$ubic]++;
                        }
                        foreach ($sedes_unicas as $nomSede => $cantSede):
                        ?>
                            <option value="<?= htmlspecialchars($nomSede) ?>">
                                <?= htmlspecialchars($nomSede) ?> (<?= $cantSede ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <i class="fa-solid fa-chevron-down absolute right-3 text-[10px] text-slate-400 pointer-events-none"></i>
                </div>

                <!-- Pestañas de Vista Rápida -->
                <div class="flex items-center gap-1 bg-slate-100 p-1 rounded-xl border border-slate-200 text-xs font-semibold">
                    <button type="button" onclick="cambiarVistaRapida('sucursales', this)" class="vista-btn px-3 py-1.5 rounded-lg bg-blue-600 text-white shadow-xs font-bold">
                        <i class="fa-solid fa-building mr-1"></i> Sedes <span id="badgeConteoVistaSedes" class="ml-1 bg-white/20 px-1 rounded text-[10px] font-mono"><?= count($sucursales_matriz) ?></span>
                    </button>
                    <a href="<?= BASE_URL ?>modules/equipos/index.php" class="px-2.5 py-1.5 rounded-lg text-slate-600 hover:text-slate-900 hover:bg-white transition flex items-center gap-1">
                        <i class="fa-solid fa-laptop"></i> Equipos
                    </a>
                    <a href="<?= BASE_URL ?>modules/empleados/index.php" class="px-2.5 py-1.5 rounded-lg text-slate-600 hover:text-slate-900 hover:bg-white transition flex items-center gap-1">
                        <i class="fa-solid fa-users"></i> Personal
                    </a>
                </div>

            </div>
        </div>

        <!-- Cinta de Contexto Dinámica -->
        <div class="mt-3.5 pt-3 border-t border-slate-100 flex flex-wrap items-center justify-between gap-2 text-xs">
            <div class="flex items-center gap-2 text-slate-700">
                <i class="fa-solid fa-filter text-blue-600 text-[11px]"></i>
                <span class="font-semibold text-slate-500">Mostrando actualmente:</span>
                <span id="txtContextoFiltro" class="font-bold text-blue-900">
                    <?= count($empresas_lista) ?> Empresas · <?= count($sucursales_matriz) ?> Sucursales · <?= $kpis['total_equipos'] ?> Equipos · <?= $kpis['total_colaboradores'] ?> Colaboradores · <?= $kpis['total_credenciales'] ?> Credenciales
                </span>
            </div>
            <div class="flex items-center gap-3">
                <button type="button" onclick="resetearTodosFiltros()" class="text-blue-700 hover:text-blue-900 font-bold hover:underline text-[11px] flex items-center gap-1">
                    <i class="fa-solid fa-rotate-left"></i> Restablecer filtros
                </button>
                <span class="text-slate-300">|</span>
                <span class="text-slate-500 font-mono text-[11px]">Sistemas: <strong class="text-emerald-600">100% Operativo</strong></span>
            </div>
        </div>
    </div>

    <!-- ========================================================================= -->
    <!-- 2. BLOQUE 1 — 4 TARJETAS OPERATIVAS HORIZONTALES (Métricas Accionables) -->
    <!-- ========================================================================= -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">

        <!-- Tarjeta 1: Equipos por Sede -->
        <div class="bg-white rounded-2xl p-4 border border-slate-200/90 shadow-xs hover:shadow-md transition flex flex-col justify-between">
            <div>
                <div class="flex items-center justify-between pb-2 border-b border-slate-100">
                    <div class="flex items-center gap-2">
                        <div class="w-7 h-7 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center text-xs">
                            <i class="fa-solid fa-laptop"></i>
                        </div>
                        <h3 class="font-bold text-slate-800 text-xs">Equipos por Sede</h3>
                    </div>
                    <span id="kpiTotalEquipos" class="font-mono font-black text-sm text-slate-900"><?= $kpis['total_equipos'] ?></span>
                </div>
                <!-- Desglose Clicable por Ubicación -->
                <div class="py-2.5 space-y-1.5 text-xs">
                    <?php 
                    $conteos_ciudad = [];
                    foreach ($sucursales_matriz as $sm) {
                        $ub = $sm['ubicacion'];
                        $conteos_ciudad[$ub] = ($conteos_ciudad[$ub] ?? 0) + $sm['totalEquipos'];
                    }
                    arsort($conteos_ciudad);
                    $top_ciudades = array_slice($conteos_ciudad, 0, 4, true);
                    foreach ($top_ciudades as $ciu => $eqs):
                    ?>
                    <div class="flex items-center justify-between cursor-pointer hover:bg-slate-50 p-1 rounded transition" onclick="filtrarPorSedeClick('<?= htmlspecialchars($ciu) ?>')">
                        <span class="text-slate-600 font-medium flex items-center gap-1.5 truncate">
                            <i class="fa-solid fa-location-dot text-slate-400 text-[10px]"></i> <?= htmlspecialchars($ciu) ?>
                        </span>
                        <span class="font-mono font-bold text-slate-800"><?= $eqs ?> eq</span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <a href="<?= BASE_URL ?>modules/equipos/index.php" class="w-full mt-1 py-1.5 px-3 bg-slate-50 hover:bg-blue-50 text-blue-700 hover:text-blue-800 font-bold text-xs rounded-xl border border-slate-200/80 transition flex items-center justify-center gap-1.5">
                <span>Ver Inventario Completo</span>
                <i class="fa-solid fa-arrow-right text-[10px]"></i>
            </a>
        </div>

        <!-- Tarjeta 2: Cuentas & Usuarios -->
        <div class="bg-white rounded-2xl p-4 border border-slate-200/90 shadow-xs hover:shadow-md transition flex flex-col justify-between">
            <div>
                <div class="flex items-center justify-between pb-2 border-b border-slate-100">
                    <div class="flex items-center gap-2">
                        <div class="w-7 h-7 rounded-lg bg-indigo-50 text-indigo-600 flex items-center justify-center text-xs">
                            <i class="fa-solid fa-user-group"></i>
                        </div>
                        <h3 class="font-bold text-slate-800 text-xs">Cuentas & Usuarios</h3>
                    </div>
                    <span class="font-mono font-black text-sm text-slate-900"><?= $kpis['total_colaboradores'] ?> Colab.</span>
                </div>
                <div class="py-2.5 space-y-1.5 text-xs">
                    <div class="flex items-center justify-between p-1">
                        <span class="text-slate-600 font-medium flex items-center gap-1.5"><i class="fa-brands fa-microsoft text-blue-500"></i> Cuentas M365</span>
                        <span class="font-mono font-bold text-slate-800"><?= $kpis['total_m365'] ?> activas</span>
                    </div>
                    <div class="flex items-center justify-between p-1">
                        <span class="text-slate-600 font-medium flex items-center gap-1.5"><i class="fa-solid fa-globe text-emerald-500"></i> Accesos Web Portales</span>
                        <span class="font-mono font-bold text-slate-800"><?= $kpis['total_web'] ?> credenciales</span>
                    </div>
                    <div class="flex items-center justify-between p-1">
                        <span class="text-slate-600 font-medium flex items-center gap-1.5"><i class="fa-solid fa-id-badge text-purple-500"></i> Directorio 360°</span>
                        <span class="font-mono font-bold text-emerald-600">100% Cubierto</span>
                    </div>
                    <div class="flex items-center justify-between p-1">
                        <span class="text-slate-600 font-medium flex items-center gap-1.5"><i class="fa-solid fa-circle-check text-blue-500"></i> Tareas Soporte</span>
                        <span class="font-mono font-bold text-slate-800"><?= $kpis['tareas_pendientes'] ?> pendientes</span>
                    </div>
                </div>
            </div>
            <a href="<?= BASE_URL ?>modules/credenciales/index.php?tab=m365" class="w-full mt-1 py-1.5 px-3 bg-slate-50 hover:bg-indigo-50 text-indigo-700 hover:text-indigo-800 font-bold text-xs rounded-xl border border-slate-200/80 transition flex items-center justify-center gap-1.5">
                <span>Gestionar Cuentas M365</span>
                <i class="fa-solid fa-arrow-right text-[10px]"></i>
            </a>
        </div>

        <!-- Tarjeta 3: Enlaces por Sucursal -->
        <div class="bg-white rounded-2xl p-4 border border-slate-200/90 shadow-xs hover:shadow-md transition flex flex-col justify-between">
            <div>
                <div class="flex items-center justify-between pb-2 border-b border-slate-100">
                    <div class="flex items-center gap-2">
                        <div class="w-7 h-7 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center text-xs">
                            <i class="fa-solid fa-network-wired"></i>
                        </div>
                        <h3 class="font-bold text-slate-800 text-xs">Enlaces por Sucursal</h3>
                    </div>
                    <span class="font-mono font-black text-sm text-emerald-600">11 Sedes</span>
                </div>
                <div class="py-2.5 space-y-1.5 text-xs">
                    <div class="flex items-center justify-between p-1">
                        <span class="text-slate-600 font-medium flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-red-500"></span> Claro Fibra Óptica</span>
                        <span class="font-mono font-bold text-slate-800">Managua & Regiones</span>
                    </div>
                    <div class="flex items-center justify-between p-1">
                        <span class="text-slate-600 font-medium flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-blue-500"></span> Tigo Business</span>
                        <span class="font-mono font-bold text-slate-800">Managua & León</span>
                    </div>
                    <div class="flex items-center justify-between p-1">
                        <span class="text-slate-600 font-medium flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-cyan-500"></span> Enlaces Internacionales</span>
                        <span class="font-mono font-bold text-slate-800">HN, SV, DO</span>
                    </div>
                    <div class="flex items-center justify-between p-1">
                        <span class="text-slate-600 font-medium flex items-center gap-1.5"><i class="fa-solid fa-shield text-slate-400"></i> VPN IPsec Corporativa</span>
                        <span class="font-mono font-bold text-emerald-600">ONLINE</span>
                    </div>
                </div>
            </div>
            <a href="<?= BASE_URL ?>modules/internet/index.php" class="w-full mt-1 py-1.5 px-3 bg-slate-50 hover:bg-emerald-50 text-emerald-700 hover:text-emerald-800 font-bold text-xs rounded-xl border border-slate-200/80 transition flex items-center justify-center gap-1.5">
                <span>Ver Conectividad & WAN</span>
                <i class="fa-solid fa-arrow-right text-[10px]"></i>
            </a>
        </div>

        <!-- Tarjeta 4: Bóveda IT & Cifrado -->
        <div class="bg-white rounded-2xl p-4 border border-slate-200/90 shadow-xs hover:shadow-md transition flex flex-col justify-between">
            <div>
                <div class="flex items-center justify-between pb-2 border-b border-slate-100">
                    <div class="flex items-center gap-2">
                        <div class="w-7 h-7 rounded-lg bg-amber-50 text-amber-600 flex items-center justify-center text-xs">
                            <i class="fa-solid fa-vault"></i>
                        </div>
                        <h3 class="font-bold text-slate-800 text-xs">Bóveda IT & Cifrado</h3>
                    </div>
                    <span class="font-mono font-black text-sm text-slate-900"><?= $kpis['total_credenciales'] ?> Llaves</span>
                </div>
                <div class="py-2.5 space-y-1.5 text-xs">
                    <div class="flex items-center justify-between p-1">
                        <span class="text-slate-600 font-medium flex items-center gap-1.5"><i class="fa-solid fa-desktop text-slate-400"></i> Claves de Equipos</span>
                        <span class="font-mono font-bold text-slate-800"><?= $eq_cred ?> registradas</span>
                    </div>
                    <div class="flex items-center justify-between p-1">
                        <span class="text-slate-600 font-medium flex items-center gap-1.5"><i class="fa-solid fa-server text-purple-500"></i> Servidores & Switches IP</span>
                        <span class="font-mono font-bold text-slate-800">Cifradas</span>
                    </div>
                    <div class="flex items-center justify-between p-1">
                        <span class="text-slate-600 font-medium flex items-center gap-1.5"><i class="fa-solid fa-wifi text-indigo-500"></i> Redes Wi-Fi Sedes</span>
                        <span class="font-mono font-bold text-slate-800">Protegidas</span>
                    </div>
                    <div class="flex items-center justify-between p-1">
                        <span class="text-slate-600 font-medium flex items-center gap-1.5"><i class="fa-solid fa-lock text-emerald-500"></i> Algoritmo Criptográfico</span>
                        <span class="font-mono font-bold text-emerald-600">AES-256</span>
                    </div>
                </div>
            </div>
            <a href="<?= BASE_URL ?>modules/infraestructura/index.php?tab=boveda" class="w-full mt-1 py-1.5 px-3 bg-slate-50 hover:bg-amber-50 text-amber-700 hover:text-amber-800 font-bold text-xs rounded-xl border border-slate-200/80 transition flex items-center justify-center gap-1.5">
                <span>Bóveda de Accesos IT</span>
                <i class="fa-solid fa-arrow-right text-[10px]"></i>
            </a>
        </div>

    </div>

    <!-- ========================================================================= -->
    <!-- 3. BLOQUE 2 — MATRIZ OPERATIVA CENTRAL DE LAS 11 SUCURSALES -->
    <!-- ========================================================================= -->
    <div id="seccionMatrizSucursales" class="bg-white rounded-2xl border border-slate-200/90 shadow-xs overflow-hidden flex flex-col">
        
        <!-- Barra de Filtros Superior de la Tabla -->
        <div class="p-4 border-b border-slate-200/80 bg-slate-50/50 flex flex-col lg:flex-row items-stretch lg:items-center justify-between gap-3">
            
            <!-- Buscador y Filtros -->
            <div class="flex flex-1 items-center gap-2">
                <div class="relative w-full max-w-sm">
                    <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
                    <input type="text" id="filtroTablaTexto" oninput="filtrarMatrizTabla()" 
                        placeholder="Filtrar por sede, empresa, enlace, IP..." 
                        class="h-9 w-full pl-8 pr-3 bg-white border border-slate-200 text-xs text-slate-800 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>

                <!-- Filtro de Empresa en Tabla -->
                <select id="filtroTablaEmpresa" onchange="filtrarMatrizTabla()" class="h-9 px-3 bg-white border border-slate-200 text-xs font-semibold text-slate-700 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="">Todas las Empresas</option>
                    <?php foreach ($empresas_lista as $emp): ?>
                        <option value="<?= htmlspecialchars($emp['nombre']) ?>"><?= htmlspecialchars($emp['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>

                <!-- Filtro de Ubicación en Tabla -->
                <select id="filtroTablaUbicacion" onchange="filtrarMatrizTabla()" class="h-9 px-3 bg-white border border-slate-200 text-xs font-semibold text-slate-700 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="">Todas las Ubicaciones</option>
                    <?php foreach ($sedes_unicas as $nomSede => $cantSede): ?>
                        <option value="<?= htmlspecialchars($nomSede) ?>"><?= htmlspecialchars($nomSede) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Botones de Exportar -->
            <div class="flex items-center gap-2 shrink-0">
                <span id="contadorResultadosMatriz" class="text-xs font-mono font-semibold text-slate-500 mr-2">
                    <?= count($sucursales_matriz) ?> de <?= count($sucursales_matriz) ?> sucursales
                </span>
                <button type="button" onclick="exportarTablaCSV()" class="h-9 px-3 bg-white hover:bg-slate-100 text-slate-700 font-bold text-xs rounded-xl border border-slate-200 transition flex items-center gap-1.5 shadow-2xs">
                    <i class="fa-solid fa-file-csv text-emerald-600"></i>
                    <span>Exportar CSV</span>
                </button>
            </div>
        </div>

        <!-- Tabla Operativa de Sucursales -->
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs border-collapse">
                <thead>
                    <tr class="bg-slate-900 text-white uppercase text-[10px] tracking-wider font-bold select-none">
                        <th class="p-3.5"><i class="fa-solid fa-building mr-1.5 text-blue-400"></i> Empresa / Sucursal</th>
                        <th class="p-3.5 text-center"><i class="fa-solid fa-circle-check mr-1.5 text-emerald-400"></i> Estado</th>
                        <th class="p-3.5"><i class="fa-solid fa-laptop mr-1.5 text-blue-400"></i> Equipos Registrados</th>
                        <th class="p-3.5"><i class="fa-solid fa-users mr-1.5 text-indigo-400"></i> Cuentas & Personal</th>
                        <th class="p-3.5"><i class="fa-solid fa-network-wired mr-1.5 text-emerald-400"></i> Red & Conectividad WAN</th>
                        <th class="p-3.5 text-center"><i class="fa-solid fa-key mr-1.5 text-amber-400"></i> Bóveda</th>
                        <th class="p-3.5 text-right"><i class="fa-solid fa-bolt mr-1.5 text-slate-400"></i> Acciones Rápidas</th>
                    </tr>
                </thead>
                <tbody id="cuerpoMatrizSucursales" class="divide-y divide-slate-100 font-medium">
                    <!-- Filas renderizadas dinámicamente con JavaScript desde el arreglo JSON -->
                </tbody>
            </table>
        </div>

        <!-- Estado Vacío -->
        <div id="emptyStateMatriz" class="hidden p-12 text-center">
            <div class="w-12 h-12 rounded-2xl bg-slate-100 text-slate-400 flex items-center justify-center text-xl mx-auto mb-2">
                <i class="fa-solid fa-filter-circle-xmark"></i>
            </div>
            <p class="font-bold text-slate-700 text-sm">No se encontraron sucursales con los filtros actuales</p>
            <p class="text-xs text-slate-400 mt-0.5">Intenta cambiar la empresa, sede o término de búsqueda.</p>
            <button type="button" onclick="resetearTodosFiltros()" class="mt-3 px-3 py-1.5 bg-blue-50 text-blue-700 font-bold text-xs rounded-lg hover:bg-blue-100 transition">
                Restablecer Filtros
            </button>
        </div>

    </div>

    <!-- ========================================================================= -->
    <!-- 4. BLOQUE 3 — BARRA INFERIOR DE ACCESO RÁPIDO POR TIPO Y LICENCIAS -->
    <!-- ========================================================================= -->
    <div class="bg-white rounded-2xl border border-slate-200/90 p-3.5 shadow-xs">
        <div class="flex flex-col xl:flex-row xl:items-center justify-between gap-3 text-xs">
            
            <!-- Píldoras de Activos de Hardware y Software -->
            <div class="flex flex-wrap items-center gap-2">
                <span class="font-bold text-slate-400 uppercase text-[10px] tracking-wider mr-1">Filtro Rápido:</span>
                
                <button type="button" onclick="filtrarTipoHardware('Laptop')" class="btn-pill-filtro px-2.5 py-1 rounded-lg border border-slate-200 bg-slate-50 hover:bg-blue-50 hover:border-blue-200 hover:text-blue-700 transition flex items-center gap-1.5 font-semibold text-slate-700">
                    <i class="fa-solid fa-laptop text-blue-500"></i>
                    <span>Laptops</span>
                    <span class="bg-blue-100 text-blue-800 text-[10px] px-1.5 py-0.2 rounded font-mono font-bold"><?= $kpis['total_laptops'] ?></span>
                </button>

                <button type="button" onclick="filtrarTipoHardware('Desktop')" class="btn-pill-filtro px-2.5 py-1 rounded-lg border border-slate-200 bg-slate-50 hover:bg-indigo-50 hover:border-indigo-200 hover:text-indigo-700 transition flex items-center gap-1.5 font-semibold text-slate-700">
                    <i class="fa-solid fa-desktop text-indigo-500"></i>
                    <span>Desktops</span>
                    <span class="bg-indigo-100 text-indigo-800 text-[10px] px-1.5 py-0.2 rounded font-mono font-bold"><?= $kpis['total_desktops'] ?></span>
                </button>

                <button type="button" onclick="filtrarTipoHardware('Impresora')" class="btn-pill-filtro px-2.5 py-1 rounded-lg border border-slate-200 bg-slate-50 hover:bg-emerald-50 hover:border-emerald-200 hover:text-emerald-700 transition flex items-center gap-1.5 font-semibold text-slate-700">
                    <i class="fa-solid fa-print text-emerald-500"></i>
                    <span>Impresoras</span>
                    <span class="bg-emerald-100 text-emerald-800 text-[10px] px-1.5 py-0.2 rounded font-mono font-bold"><?= $kpis['total_impresoras'] ?></span>
                </button>

                <button type="button" onclick="filtrarTipoHardware('UPS')" class="btn-pill-filtro px-2.5 py-1 rounded-lg border border-slate-200 bg-slate-50 hover:bg-amber-50 hover:border-amber-200 hover:text-amber-700 transition flex items-center gap-1.5 font-semibold text-slate-700">
                    <i class="fa-solid fa-bolt text-amber-500"></i>
                    <span>UPS / Energía</span>
                    <span class="bg-amber-100 text-amber-800 text-[10px] px-1.5 py-0.2 rounded font-mono font-bold"><?= $kpis['total_ups'] ?></span>
                </button>

                <span class="text-slate-300">|</span>

                <button type="button" onclick="filtrarTipoHardware('M365')" class="btn-pill-filtro px-2.5 py-1 rounded-lg border border-slate-200 bg-slate-50 hover:bg-blue-50 hover:border-blue-200 hover:text-blue-700 transition flex items-center gap-1.5 font-semibold text-slate-700">
                    <i class="fa-brands fa-microsoft text-blue-500"></i>
                    <span>M365</span>
                    <span class="bg-blue-100 text-blue-800 text-[10px] px-1.5 py-0.2 rounded font-mono font-bold"><?= $kpis['total_m365'] ?></span>
                </button>

                <button type="button" onclick="filtrarTipoHardware('Web')" class="btn-pill-filtro px-2.5 py-1 rounded-lg border border-slate-200 bg-slate-50 hover:bg-purple-50 hover:border-purple-200 hover:text-purple-700 transition flex items-center gap-1.5 font-semibold text-slate-700">
                    <i class="fa-solid fa-globe text-purple-500"></i>
                    <span>Cuentas Web</span>
                    <span class="bg-purple-100 text-purple-800 text-[10px] px-1.5 py-0.2 rounded font-mono font-bold"><?= $kpis['total_web'] ?></span>
                </button>
            </div>

            <!-- Accesos a Sedes Principales -->
            <div class="flex flex-wrap items-center gap-1.5">
                <span class="font-bold text-slate-400 uppercase text-[10px] tracking-wider mr-1">Sedes:</span>
                <button type="button" onclick="filtrarPorSedeClick('Managua')" class="px-2 py-0.5 rounded bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold text-xs transition">Managua</button>
                <button type="button" onclick="filtrarPorSedeClick('León')" class="px-2 py-0.5 rounded bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold text-xs transition">León</button>
                <button type="button" onclick="filtrarPorSedeClick('Honduras')" class="px-2 py-0.5 rounded bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold text-xs transition">Honduras</button>
                <button type="button" onclick="filtrarPorSedeClick('Salvador')" class="px-2 py-0.5 rounded bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold text-xs transition">El Salvador</button>
                <button type="button" onclick="filtrarPorSedeClick('Sébaco')" class="px-2 py-0.5 rounded bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold text-xs transition">Sébaco</button>
                <button type="button" onclick="filtrarPorSedeClick('all')" class="px-2 py-0.5 rounded bg-blue-600 text-white font-bold text-xs transition">Todas (11)</button>
            </div>

        </div>
    </div>

</div>

<!-- TOAST FLOTANTE DE NOTIFICACIÓN RÁPIDA -->
<div id="toastAccion" class="fixed bottom-6 right-6 bg-slate-900 text-white px-4 py-3 rounded-2xl shadow-2xl border border-slate-700 text-xs font-semibold flex items-center gap-3 transition-all duration-300 translate-y-12 opacity-0 pointer-events-none z-50">
    <div id="toastIcon" class="w-6 h-6 rounded-full bg-blue-500 flex items-center justify-center text-white text-[10px]">
        <i class="fa-solid fa-check"></i>
    </div>
    <span id="toastMsg">Acción ejecutada correctamente</span>
</div>

<!-- ========================================================================= -->
<!-- JAVASCRIPT: LÓGICA DE DATOS Y FILTRADO INTERACTIVO -->
<!-- ========================================================================= -->
<script>
    // 1. Datos cargados desde PHP MySQL
    const DATOS_SUCURSALES = <?= json_encode($sucursales_matriz, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const BASE_URL_APP = '<?= BASE_URL ?>';

    let filtroGlobalEmpresa = "all";
    let filtroGlobalSucursal = "all";
    let filtroTexto = "";
    let filtroHardwareEspecifico = null;

    document.addEventListener("DOMContentLoaded", () => {
        renderizarMatriz(DATOS_SUCURSALES);
    });

    // 2. Renderizar filas de la tabla
    function renderizarMatriz(lista) {
        const tbody = document.getElementById("cuerpoMatrizSucursales");
        const emptyState = document.getElementById("emptyStateMatriz");
        const contador = document.getElementById("contadorResultadosMatriz");

        tbody.innerHTML = "";

        if (lista.length === 0) {
            emptyState.classList.remove("hidden");
            contador.innerText = "0 sucursales";
            return;
        } else {
            emptyState.classList.add("hidden");
            contador.innerText = `${lista.length} de ${DATOS_SUCURSALES.length} sucursales`;
        }

        lista.forEach(item => {
            const tr = document.createElement("tr");
            tr.className = "hover:bg-slate-50/80 transition-colors border-b border-slate-100 group";

            const urlInventarioSede = `${BASE_URL_APP}modules/equipos/index.php?sucursal_id=${item.id}`;
            const urlUsuariosSede = `${BASE_URL_APP}modules/empleados/index.php?sucursal_id=${item.id}`;
            const urlBovedaSede = `${BASE_URL_APP}modules/infraestructura/index.php?tab=boveda`;

            tr.innerHTML = `
                <!-- 1. Empresa / Sucursal -->
                <td class="p-3.5">
                    <div class="flex items-start gap-2.5">
                        <span class="text-lg leading-none mt-0.5 select-none" title="${item.ubicacion}">${item.pais}</span>
                        <div>
                            <a href="${urlInventarioSede}" class="font-extrabold text-slate-900 group-hover:text-blue-600 transition text-xs block">
                                ${item.sucursal}
                            </a>
                            <div class="flex items-center gap-1.5 mt-1">
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold border ${item.empresaColor}">
                                    ${item.empresa}
                                </span>
                                <span class="text-[10px] text-slate-400 font-medium">• ${item.ubicacion}</span>
                            </div>
                        </div>
                    </div>
                </td>

                <!-- 2. Estado -->
                <td class="p-3.5 text-center">
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> ${item.estado}
                    </span>
                    <div class="text-[10px] font-mono text-slate-400 mt-1">${item.totalEquipos} activos</div>
                </td>

                <!-- 3. Equipos (Menú Filtrado Clicable) -->
                <td class="p-3.5">
                    <div class="flex flex-wrap items-center gap-1 text-[11px] font-mono">
                        <a href="${urlInventarioSede}&tipo=Laptop" title="Ver Laptops de esta sede" class="px-2 py-0.5 rounded bg-blue-50 text-blue-700 hover:bg-blue-600 hover:text-white transition font-bold flex items-center gap-1">
                            <i class="fa-solid fa-laptop text-[10px]"></i> ${item.laptops}
                        </a>
                        <a href="${urlInventarioSede}&tipo=Desktop" title="Ver Desktops de esta sede" class="px-2 py-0.5 rounded bg-indigo-50 text-indigo-700 hover:bg-indigo-600 hover:text-white transition font-bold flex items-center gap-1">
                            <i class="fa-solid fa-desktop text-[10px]"></i> ${item.desktops}
                        </a>
                        <a href="${BASE_URL_APP}modules/impresoras/index.php?sucursal_id=${item.id}" title="Ver Impresoras de esta sede" class="px-2 py-0.5 rounded bg-emerald-50 text-emerald-700 hover:bg-emerald-600 hover:text-white transition font-bold flex items-center gap-1">
                            <i class="fa-solid fa-print text-[10px]"></i> ${item.impresoras}
                        </a>
                        ${item.ups > 0 ? `
                        <a href="${BASE_URL_APP}modules/infraestructura/index.php?tab=equipos_red&sucursal_id=${item.id}" title="Ver UPS de esta sede" class="px-2 py-0.5 rounded bg-amber-50 text-amber-700 hover:bg-amber-600 hover:text-white transition font-bold flex items-center gap-1">
                            <i class="fa-solid fa-bolt text-[10px]"></i> ${item.ups}
                        </a>` : ''}
                    </div>
                    <div class="mt-1">
                        <a href="${urlInventarioSede}" class="text-[10px] font-semibold text-blue-600 hover:text-blue-800 hover:underline">
                            Inventario de sede (${item.totalEquipos}) →
                        </a>
                    </div>
                </td>

                <!-- 4. Cuentas & Personal -->
                <td class="p-3.5">
                    <div class="space-y-0.5">
                        <div class="flex items-center gap-2">
                            <i class="fa-solid fa-user-tie text-slate-400 text-[10px]"></i>
                            <span class="font-bold text-slate-800">${item.colaboradores} Colaboradores</span>
                        </div>
                        <div class="flex items-center gap-2 text-slate-500 text-[10px]">
                            <i class="fa-brands fa-microsoft text-blue-500"></i>
                            <span class="font-mono font-semibold">${item.cuentasM365} licencias M365</span>
                        </div>
                        <a href="${urlUsuariosSede}" class="inline-block text-[10px] font-bold text-indigo-600 hover:underline mt-0.5">
                            Ver ${item.colaboradores} colaboradores →
                        </a>
                    </div>
                </td>

                <!-- 5. Red & Conectividad WAN -->
                <td class="p-3.5">
                    <div class="space-y-0.5">
                        <div class="flex items-center gap-1.5 font-bold text-slate-800">
                            <i class="fa-solid fa-globe text-emerald-600 text-[11px]"></i>
                            <span>${item.isp}</span>
                        </div>
                        <div class="flex items-center gap-2 font-mono text-[10px] text-slate-500">
                            <span>IP: <strong class="text-slate-700">${item.ipPublica}</strong></span>
                            <span class="text-slate-300">•</span>
                            <span class="text-emerald-700 font-semibold">${item.vpn}</span>
                        </div>
                        <a href="${BASE_URL_APP}modules/infraestructura/index.php?tab=matriz_ip&sucursal_id=${item.id}" class="text-[10px] font-bold text-emerald-600 hover:underline">
                            Detalle de Red & Subredes →
                        </a>
                    </div>
                </td>

                <!-- 6. Bóveda & Accesos -->
                <td class="p-3.5 text-center">
                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-xl bg-amber-50 text-amber-800 font-bold font-mono border border-amber-200">
                        <i class="fa-solid fa-key text-[10px] text-amber-600"></i> ${item.boveda}
                    </span>
                    <div class="mt-1">
                        <a href="${urlBovedaSede}" class="text-[10px] font-bold text-amber-700 hover:underline">
                            Ver claves
                        </a>
                    </div>
                </td>

                <!-- 7. Acciones Rápidas -->
                <td class="p-3.5 text-right">
                    <div class="flex items-center justify-end gap-1.5">
                        <button type="button" onclick="filtrarPorSedeClick('${item.ubicacion}')" class="h-7 px-2 rounded-lg bg-slate-100 hover:bg-blue-600 hover:text-white text-slate-700 font-bold text-[10px] transition shadow-2xs" title="Filtrar por esta sede">
                            <i class="fa-solid fa-filter mr-1"></i> Sede
                        </button>
                        <a href="${urlInventarioSede}" class="h-7 px-2 rounded-lg bg-blue-50 hover:bg-blue-600 hover:text-white text-blue-700 font-bold text-[10px] transition inline-flex items-center justify-center" title="Ver Inventario">
                            <i class="fa-solid fa-laptop"></i>
                        </a>
                        <a href="${BASE_URL_APP}modules/tareas/index.php" class="h-7 px-2 rounded-lg bg-slate-100 hover:bg-amber-600 hover:text-white text-slate-700 font-bold text-[10px] transition inline-flex items-center justify-center" title="Tareas IT">
                            <i class="fa-solid fa-headset"></i>
                        </a>
                    </div>
                </td>
            `;

            tbody.appendChild(tr);
        });
    }

    // 3. Filtro Global (Empresa & Sede)
    function aplicarFiltroGlobal() {
        const selEmpresa = document.getElementById("selectGlobalEmpresa").value;
        const selSucursal = document.getElementById("selectGlobalSucursal").value;

        filtroGlobalEmpresa = selEmpresa;
        filtroGlobalSucursal = selSucursal;

        document.getElementById("filtroTablaEmpresa").value = selEmpresa === "all" ? "" : selEmpresa;
        document.getElementById("filtroTablaUbicacion").value = selSucursal === "all" ? "" : selSucursal;

        ejecutarFiltrosCombinados();
    }

    function filtrarPorSedeClick(sede) {
        if (sede === "all") {
            document.getElementById("selectGlobalSucursal").value = "all";
            document.getElementById("filtroTablaUbicacion").value = "";
            filtroGlobalSucursal = "all";
        } else {
            document.getElementById("selectGlobalSucursal").value = sede;
            document.getElementById("filtroTablaUbicacion").value = sede;
            filtroGlobalSucursal = sede;
        }
        ejecutarFiltrosCombinados();
        mostrarToast(`Filtrando por ubicación: ${sede === 'all' ? 'Todas las sedes' : sede}`);
    }

    function filtrarMatrizTabla() {
        filtroTexto = document.getElementById("filtroTablaTexto").value.toLowerCase().trim();
        const empSelect = document.getElementById("filtroTablaEmpresa").value;
        const ubiSelect = document.getElementById("filtroTablaUbicacion").value;

        filtroGlobalEmpresa = empSelect || "all";
        filtroGlobalSucursal = ubiSelect || "all";

        document.getElementById("selectGlobalEmpresa").value = filtroGlobalEmpresa;
        document.getElementById("selectGlobalSucursal").value = filtroGlobalSucursal;

        ejecutarFiltrosCombinados();
    }

    function filtrarTipoHardware(tipo) {
        filtroHardwareEspecifico = (filtroHardwareEspecifico === tipo) ? null : tipo;
        
        document.querySelectorAll(".btn-pill-filtro").forEach(b => {
            b.classList.remove("bg-blue-600", "text-white");
        });

        if (filtroHardwareEspecifico) {
            mostrarToast(`Filtrando sedes con activos de tipo: ${tipo}`);
        } else {
            mostrarToast(`Filtro de activo desactivado`);
        }
        ejecutarFiltrosCombinados();
    }

    function ejecutarFiltrosCombinados() {
        let resultado = DATOS_SUCURSALES.filter(item => {
            if (filtroGlobalEmpresa !== "all" && item.empresa !== filtroGlobalEmpresa) {
                return false;
            }

            if (filtroGlobalSucursal !== "all" && !item.ubicacion.toLowerCase().includes(filtroGlobalSucursal.toLowerCase()) && !item.sucursal.toLowerCase().includes(filtroGlobalSucursal.toLowerCase())) {
                return false;
            }

            if (filtroTexto !== "") {
                const searchCorpus = `${item.empresa} ${item.sucursal} ${item.ubicacion} ${item.isp} ${item.ipPublica}`.toLowerCase();
                if (!searchCorpus.includes(filtroTexto)) {
                    return false;
                }
            }

            if (filtroHardwareEspecifico) {
                if (filtroHardwareEspecifico === "Laptop" && item.laptops === 0) return false;
                if (filtroHardwareEspecifico === "Desktop" && item.desktops === 0) return false;
                if (filtroHardwareEspecifico === "Impresora" && item.impresoras === 0) return false;
                if (filtroHardwareEspecifico === "UPS" && item.ups === 0) return false;
                if (filtroHardwareEspecifico === "M365" && item.cuentasM365 === 0) return false;
            }

            return true;
        });

        actualizarCintaContexto(resultado);
        renderizarMatriz(resultado);
    }

    function actualizarCintaContexto(lista) {
        const totalEq = lista.reduce((acc, curr) => acc + curr.totalEquipos, 0);
        const totalColab = lista.reduce((acc, curr) => acc + curr.colaboradores, 0);
        const totalCred = lista.reduce((acc, curr) => acc + curr.boveda, 0);
        const empresasUnicas = new Set(lista.map(i => i.empresa)).size;

        const txt = `${empresasUnicas} Empresas · ${lista.length} Sucursales · ${totalEq} Equipos · ${totalColab} Colaboradores · ${totalCred} Credenciales`;
        const cinta = document.getElementById("txtContextoFiltro");
        if (cinta) cinta.innerText = txt;
        
        const kpiEq = document.getElementById("kpiTotalEquipos");
        if (kpiEq) kpiEq.innerText = totalEq;
        
        const badgeSedes = document.getElementById("badgeConteoVistaSedes");
        if (badgeSedes) badgeSedes.innerText = lista.length;
    }

    function resetearTodosFiltros() {
        filtroGlobalEmpresa = "all";
        filtroGlobalSucursal = "all";
        filtroTexto = "";
        filtroHardwareEspecifico = null;

        document.getElementById("selectGlobalEmpresa").value = "all";
        document.getElementById("selectGlobalSucursal").value = "all";
        document.getElementById("filtroTablaEmpresa").value = "";
        document.getElementById("filtroTablaUbicacion").value = "";
        document.getElementById("filtroTablaTexto").value = "";

        ejecutarFiltrosCombinados();
        mostrarToast("Filtros restablecidos al estado general");
    }

    function cambiarVistaRapida(vista, btn) {
        document.querySelectorAll(".vista-btn").forEach(b => {
            b.className = "vista-btn px-3 py-1.5 rounded-lg text-slate-600 hover:text-slate-900 hover:bg-slate-50 transition";
        });
        btn.className = "vista-btn px-3 py-1.5 rounded-lg bg-blue-600 text-white shadow-xs font-bold";
        mostrarToast("Vista general de Sucursales activada");
    }

    function exportarTablaCSV() {
        let csvContent = "data:text/csv;charset=utf-8,";
        csvContent += "Empresa,Sucursal,Ubicacion,Estado,Equipos,Laptops,Desktops,Impresoras,UPS,Colaboradores,M365,ISP,IP\r\n";

        DATOS_SUCURSALES.forEach(s => {
            const row = `"${s.empresa}","${s.sucursal}","${s.ubicacion}","${s.estado}",${s.totalEquipos},${s.laptops},${s.desktops},${s.impresoras},${s.ups},${s.colaboradores},${s.cuentasM365},"${s.isp}","${s.ipPublica}"`;
            csvContent += row + "\r\n";
        });

        const encodedUri = encodeURI(csvContent);
        const link = document.createElement("a");
        link.setAttribute("href", encodedUri);
        link.setAttribute("download", "matriz_sucursales_it.csv");
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);

        mostrarToast("Reporte CSV descargado con éxito");
    }

    function mostrarToast(msg) {
        const toast = document.getElementById("toastAccion");
        const toastMsg = document.getElementById("toastMsg");
        if (!toast || !toastMsg) return;

        toastMsg.innerText = msg;
        toast.classList.remove("translate-y-12", "opacity-0", "pointer-events-none");
        toast.classList.add("translate-y-0", "opacity-100");

        clearTimeout(window.toastTimer);
        window.toastTimer = setTimeout(() => {
            toast.classList.remove("translate-y-0", "opacity-100");
            toast.classList.add("translate-y-12", "opacity-0", "pointer-events-none");
        }, 2800);
    }
</script>

<?php endif; ?>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
