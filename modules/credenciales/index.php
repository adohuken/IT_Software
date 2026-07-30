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
$equipos = $db->query("SELECT id, tipo, marca, modelo, service_tag_sn FROM equipos WHERE estado != 'De Baja' AND id NOT IN (SELECT equipo_id FROM credenciales_equipos) ORDER BY tipo, marca")->fetchAll();
$empleados = $db->query("SELECT id, nombre, cargo FROM empleados WHERE estado = 'Activo' ORDER BY nombre")->fetchAll();
$sucursales = $db->query("SELECT id, nombre FROM sucursales ORDER BY nombre")->fetchAll();
$empresas_list = $db->query("SELECT id, nombre FROM empresas ORDER BY nombre")->fetchAll();

// Cargar listas correspondientes con joins para obtener el nombre de la empresa
$cred_equipos_raw = $db->query("
    SELECT 
        ce.*, 
        e.tipo, 
        e.marca, 
        e.modelo, 
        e.service_tag_sn, 
        e.procesador, 
        e.disco, 
        e.ram, 
        e.ip_direccion, 
        e.estado AS equipo_estado,
        emp.id AS empleado_id,
        emp.nombre AS colaborador_nombre,
        emp.sucursal_id AS colaborador_sucursal_id,
        emp.cargo AS colaborador_cargo,
        emp_comp_direct.id AS colaborador_empresa_id,
        COALESCE(emp.departamento, s.nombre, 'Sin Área') AS area_nombre,
        emp_comp.nombre AS empresa_nombre 
    FROM credenciales_equipos ce 
    JOIN equipos e ON ce.equipo_id = e.id 
    LEFT JOIN asignaciones a ON a.equipo_id = e.id AND a.fecha_devolucion IS NULL
    LEFT JOIN empleados emp ON a.empleado_id = emp.id
    LEFT JOIN sucursales s ON s.id = COALESCE(emp.sucursal_id, e.sucursal_id)
    LEFT JOIN empresas emp_comp ON s.empresa_id = emp_comp.id
    LEFT JOIN empresas emp_comp_direct ON emp_comp_direct.nombre = emp.nombre AND emp.cargo = 'Empresa'
    ORDER BY CASE WHEN emp.nombre IS NULL OR emp.nombre = '' THEN 1 ELSE 0 END, emp.nombre ASC, e.marca ASC, e.modelo ASC
")->fetchAll();

$cred_equipos = [];
foreach ($cred_equipos_raw as $ce) {
    $ce['pass_plain'] = !empty($ce['password_cifrado']) ? Security::decrypt($ce['password_cifrado']) : '';
    $ce['bitlocker_plain'] = !empty($ce['clave_bitlocker']) ? Security::decrypt($ce['clave_bitlocker']) : '';
    $ce['equipos_lista'] = [$ce];
    $cred_equipos[] = $ce;
}

$cred_web_raw = $db->query("SELECT * FROM credenciales_web ORDER BY plataforma")->fetchAll();
$cred_web = [];
foreach ($cred_web_raw as $cw) {
    $cw['pass_plain'] = !empty($cw['password_cifrado']) ? Security::decrypt($cw['password_cifrado']) : '';
    $cred_web[] = $cw;
}

$cuentas_m365_raw = $db->query("
    SELECT c.*, emp.nombre AS empleado_nombre, emp.cargo AS empleado_cargo, emp.departamento AS empleado_depto, emp_comp.nombre AS empresa_nombre
    FROM cuentas_m365 c 
    LEFT JOIN empleados emp ON c.empleado_id = emp.id 
    LEFT JOIN sucursales s ON emp.sucursal_id = s.id
    LEFT JOIN empresas emp_comp ON s.empresa_id = emp_comp.id
    ORDER BY CASE WHEN emp.nombre IS NULL OR emp.nombre = '' THEN 1 ELSE 0 END, emp.nombre ASC, c.correo ASC
")->fetchAll();

$cuentas_m365 = [];
foreach ($cuentas_m365_raw as $m365) {
    $m365['pass_plain'] = !empty($m365['password_cifrado']) ? Security::decrypt($m365['password_cifrado']) : '';
    $m365['pass_ag_plain'] = !empty($m365['pass_ag_cifrado']) ? Security::decrypt($m365['pass_ag_cifrado']) : '';
    $m365['pass_gmail_plain'] = !empty($m365['pass_gmail_cifrado']) ? Security::decrypt($m365['pass_gmail_cifrado']) : '';
    $cuentas_m365[] = $m365;
}

// Lookup de equipos por empleado_id y por nombre (para mostrar múltiples en detalle M365)
$equipos_por_empleado_id = [];
$equipos_asignados_db = $db->query("
    SELECT a.empleado_id, eq.tipo, eq.marca, eq.modelo, eq.service_tag_sn
    FROM asignaciones a
    JOIN equipos eq ON a.equipo_id = eq.id
    WHERE a.fecha_devolucion IS NULL
")->fetchAll();
foreach ($equipos_asignados_db as $ead) {
    if (!empty($ead['empleado_id'])) {
        $equipos_por_empleado_id[$ead['empleado_id']][] = [
            'tipo' => $ead['tipo'] ?? '',
            'marca' => $ead['marca'] ?? '',
            'modelo' => $ead['modelo'] ?? '',
            'sn' => $ead['service_tag_sn'] ?? ''
        ];
    }
}

$equipos_por_nombre = [];
$equipos_lookup_raw = $db->query("
    SELECT ce.usuario_local, eq.tipo, eq.marca, eq.modelo, eq.service_tag_sn,
           emp.nombre AS colab_nombre
    FROM asignaciones a
    JOIN equipos eq ON a.equipo_id = eq.id
    LEFT JOIN credenciales_equipos ce ON ce.equipo_id = eq.id
    LEFT JOIN empleados emp ON a.empleado_id = emp.id
    WHERE a.fecha_devolucion IS NULL
")->fetchAll();

foreach ($equipos_lookup_raw as $elr) {
    $nombres_indexar = [];
    if (!empty($elr['colab_nombre'])) {
        $partes = explode(' ', mb_strtolower(trim($elr['colab_nombre']), 'UTF-8'));
        $nombres_indexar[] = $partes[0]; // primer nombre
        $nombres_indexar[] = mb_strtolower(trim($elr['colab_nombre']), 'UTF-8'); // nombre completo
    }
    if (!empty($elr['usuario_local'])) {
        $partes2 = explode(' ', mb_strtolower(trim($elr['usuario_local']), 'UTF-8'));
        $nombres_indexar[] = $partes2[0];
        $nombres_indexar[] = mb_strtolower(trim($elr['usuario_local']), 'UTF-8');
    }
    foreach (array_unique($nombres_indexar) as $key) {
        $equipos_por_nombre[$key][] = $elr;
    }
}

$equipos_por_apellido = [];
foreach ($equipos_lookup_raw as $elr) {
    $nombres_check = array_filter([
        !empty($elr['colab_nombre']) ? $elr['colab_nombre'] : null,
        !empty($elr['usuario_local']) ? $elr['usuario_local'] : null
    ]);
    foreach ($nombres_check as $nom) {
        $partes = explode(' ', mb_strtolower(trim($nom), 'UTF-8'));
        if (count($partes) >= 2) {
            $apellido = $partes[1];
            if (mb_strlen($apellido) >= 3) {
                $equipos_por_apellido[$apellido][] = $elr;
            }
        }
    }
}

foreach ($cuentas_m365 as &$m365) {
    $m365['equipos_asignados'] = [];
    if (!empty($m365['empleado_id']) && isset($equipos_por_empleado_id[$m365['empleado_id']])) {
        $m365['equipos_asignados'] = $equipos_por_empleado_id[$m365['empleado_id']];
    } else if (!empty($m365['empleado_nombre'])) {
        $nombre_lower = mb_strtolower(trim($m365['empleado_nombre']), 'UTF-8');
        $partes_m = explode(' ', $nombre_lower);
        $primer_nombre = $partes_m[0];
        $apellido_m = count($partes_m) >= 2 ? $partes_m[1] : '';
        $candidatos = [];

        // 1. Nombre completo exacto
        if (isset($equipos_por_nombre[$nombre_lower])) {
            $candidatos = $equipos_por_nombre[$nombre_lower];
        }
        // 2. Primer nombre exacto
        if (empty($candidatos) && isset($equipos_por_nombre[$primer_nombre])) {
            $candidatos = $equipos_por_nombre[$primer_nombre];
        }
        // 3. Apellido + verificar primer nombre
        if (empty($candidatos) && $apellido_m && isset($equipos_por_apellido[$apellido_m])) {
            foreach ($equipos_por_apellido[$apellido_m] as $cand) {
                $nombre_candidato = mb_strtolower(trim($cand['colab_nombre'] ?? $cand['usuario_local'] ?? ''), 'UTF-8');
                $primer_candidato = explode(' ', $nombre_candidato)[0];
                if (mb_strpos($primer_nombre, $primer_candidato) === 0 || mb_strpos($primer_candidato, $primer_nombre) === 0) {
                    $candidatos[] = $cand;
                }
            }
        }

        if (!empty($candidatos)) {
            $vistos_sn = [];
            foreach ($candidatos as $cand) {
                $sn = $cand['service_tag_sn'] ?? '';
                if (!isset($vistos_sn[$sn])) {
                    $vistos_sn[$sn] = true;
                    $m365['equipos_asignados'][] = [
                        'tipo' => $cand['tipo'] ?? '',
                        'marca' => $cand['marca'] ?? '',
                        'modelo' => $cand['modelo'] ?? '',
                        'sn' => $cand['service_tag_sn'] ?? ''
                    ];
                }
            }
        }
    }
}
unset($m365);

// Lista de sugerencias para autocompletado custom en Usuario Local
// Se deduplicará para que solo aparezca la versión más completa de cada nombre
$usuarios_raw = [];
foreach ($empleados as $emp) {
    if (!empty($emp['nombre'])) {
        $usuarios_raw[] = [
            'nombre' => $emp['nombre'],
            'sub' => !empty($emp['cargo']) ? $emp['cargo'] : 'Colaborador'
        ];
    }
}
foreach ($cuentas_m365 as $m3) {
    if (!empty($m3['empleado_nombre'])) {
        $usuarios_raw[] = [
            'nombre' => $m3['empleado_nombre'],
            'sub' => !empty($m3['empresa_nombre']) ? $m3['empresa_nombre'] : 'M365'
        ];
    }
    if (!empty($m3['correo'])) {
        $usuarios_raw[] = [
            'nombre' => $m3['correo'],
            'sub' => 'Cuenta M365'
        ];
    }
}
// Deduplicar: agrupar por nombre exacto, quedarse con una sola entrada por nombre
$usuarios_list_js = [];
$vistos_usr = [];
foreach ($usuarios_raw as $u) {
    $key = mb_strtolower(trim($u['nombre']), 'UTF-8');
    if (!isset($vistos_usr[$key])) {
        $vistos_usr[$key] = true;
        $usuarios_list_js[] = $u;
    }
}
// Ordenar alfabéticamente
usort($usuarios_list_js, function ($a, $b) {
    return strcmp(mb_strtolower($a['nombre']), mb_strtolower($b['nombre']));
});

// Cargar todas las asignaciones activas de equipos y sus credenciales
$equipos_fichas_raw = $db->query("
    SELECT a.empleado_id, eq.id AS equipo_id, eq.tipo AS eq_tipo, eq.marca AS eq_marca,
           eq.modelo AS eq_modelo, eq.service_tag_sn, eq.ip_direccion,
           eq.procesador, eq.ram, eq.disco,
           ce.usuario_local, ce.password_cifrado AS eq_pass_enc,
           ce.clave_bitlocker AS eq_bitlocker_enc, ce.notas_acceso
    FROM asignaciones a
    JOIN equipos eq ON a.equipo_id = eq.id
    LEFT JOIN credenciales_equipos ce ON ce.equipo_id = eq.id
    WHERE a.fecha_devolucion IS NULL
")->fetchAll();

$equipos_fichas_map = [];
foreach ($equipos_fichas_raw as $ef) {
    if (!empty($ef['empleado_id'])) {
        $ef['eq_pass_plain'] = !empty($ef['eq_pass_enc']) ? Security::decrypt($ef['eq_pass_enc']) : '';
        $ef['eq_bitlocker_plain'] = !empty($ef['eq_bitlocker_enc']) ? Security::decrypt($ef['eq_bitlocker_enc']) : '';
        $equipos_fichas_map[$ef['empleado_id']][] = $ef;
    }
}

// Ficha Consolidada – todo en uno por colaborador (sin JOIN que duplique filas)
$fichas_raw = $db->query("
    SELECT
        emp.id, emp.nombre, emp.cargo, emp.departamento, emp.estado AS emp_estado,
        s.nombre AS sucursal_nombre, emp_comp.nombre AS empresa_nombre,
        -- M365
        m3.id AS m365_id, m3.correo AS m365_correo, m3.licencia_tipo,
        m3.password_cifrado AS m365_pass_enc, m3.estado AS m365_estado,
        m3.ciclo_facturacion, m3.fecha_proximo_pago
    FROM empleados emp
    LEFT JOIN sucursales s         ON emp.sucursal_id = s.id
    LEFT JOIN empresas emp_comp    ON s.empresa_id = emp_comp.id
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
        } catch (Exception $e) {
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
    $f['m365_pass_plain'] = !empty($f['m365_pass_enc']) ? Security::decrypt($f['m365_pass_enc']) : '';
    $f['equipos'] = $equipos_fichas_map[$f['id']] ?? [];
    $fichas[] = $f;
}
?>

<div class="space-y-6">
    <!-- Encabezado de Página -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h2 class="text-2xl font-bold text-slate-800 tracking-wide">Gestión de Credenciales y Licencias</h2>
            <p class="text-slate-500 text-sm">Almacén cifrado seguro para accesos locales, portales web y cuentas
                Microsoft 365.</p>
        </div>

        <!-- Botones de Acción según Pestaña -->
        <div>
            <?php if ($tab === 'equipos'): ?>
                <button onclick="abrirNuevaCredencialEquipo()"
                    class="px-4 py-2.5 rounded-xl grad-cyan-blue hover:opacity-90 text-slate-800 font-bold text-sm tracking-wide shadow-lg shadow-cyan-500/10 flex items-center gap-2">
                    <i class="fa-solid fa-plus"></i> Nueva Credencial Equipo
                </button>
            <?php elseif ($tab === 'web'): ?>
                <button onclick="abrirModal('modal-web')"
                    class="px-4 py-2.5 rounded-xl grad-cyan-blue hover:opacity-90 text-slate-800 font-bold text-sm tracking-wide shadow-lg shadow-cyan-500/10 flex items-center gap-2">
                    <i class="fa-solid fa-plus"></i> Nueva Credencial Web
                </button>
            <?php elseif ($tab === 'm365'): ?>
                <button onclick="abrirModal('modal-m365')"
                    class="px-4 py-2.5 rounded-xl grad-cyan-blue hover:opacity-90 text-slate-800 font-bold text-sm tracking-wide shadow-lg shadow-cyan-500/10 flex items-center gap-2">
                    <i class="fa-solid fa-plus"></i> Nueva Cuenta M365
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Pestañas (Tabs) de navegación de submódulos -->
    <div class="border-b border-slate-200 flex gap-1 flex-wrap">
        <a href="?tab=equipos"
            class="px-5 py-3 text-sm font-semibold border-b-2 transition flex items-center gap-2 <?= ($tab === 'equipos') ? 'border-cyan-400 text-slate-800 bg-white/5 rounded-t-lg' : 'border-transparent text-slate-500 hover:text-slate-800' ?>">
            <i class="fa-solid fa-desktop text-xs"></i> Credenciales de Equipos
        </a>
        <a href="?tab=web"
            class="px-5 py-3 text-sm font-semibold border-b-2 transition flex items-center gap-2 <?= ($tab === 'web') ? 'border-cyan-400 text-slate-800 bg-white/5 rounded-t-lg' : 'border-transparent text-slate-500 hover:text-slate-800' ?>">
            <i class="fa-solid fa-globe text-xs"></i> Credenciales Web y Portales
        </a>
        <a href="?tab=m365"
            class="px-5 py-3 text-sm font-semibold border-b-2 transition flex items-center gap-2 <?= ($tab === 'm365') ? 'border-cyan-400 text-slate-800 bg-white/5 rounded-t-lg' : 'border-transparent text-slate-500 hover:text-slate-800' ?>">
            <i class="fa-solid fa-cloud text-xs text-cyan-400"></i> Microsoft 365 / Facturación
        </a>
        <a href="?tab=fichas"
            class="px-5 py-3 text-sm font-semibold border-b-2 transition flex items-center gap-2 <?= ($tab === 'fichas') ? 'border-violet-500 text-slate-800 bg-white/5 rounded-t-lg' : 'border-transparent text-slate-500 hover:text-slate-800' ?>">
            <i class="fa-solid fa-id-card text-xs <?= ($tab === 'fichas') ? 'text-violet-600' : '' ?>"></i> Fichas
            Consolidadas
        </a>
    </div>

    <!-- Barra de Filtros Dinámicos (Persistente) -->
    <div
        class="glass-panel p-4 rounded-2xl flex flex-col md:flex-row gap-4 items-center justify-between border border-slate-200 bg-black/10">
        <div class="flex-1 w-full relative">
            <i class="fa-solid fa-magnifying-glass absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"></i>
            <input type="text" id="filtro-busqueda" onkeyup="aplicarFiltros()"
                placeholder="Buscar por cuenta, usuario, plataforma, equipo, departamento..."
                class="w-full bg-slate-100 border border-slate-200 rounded-xl py-2.5 pl-11 pr-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400 focus:ring-1 focus:ring-cyan-400 placeholder-gray-500 transition">
        </div>
        <div class="w-full md:w-64 relative">
            <i class="fa-solid fa-building absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"></i>
            <select autocomplete="off" id="filtro-empresa" onchange="aplicarFiltros()"
                class="w-full bg-slate-100 border border-slate-200 rounded-xl py-2.5 pl-11 pr-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400 focus:ring-1 focus:ring-cyan-400 transition appearance-none">
                <option value="">-- Filtrar por Empresa --</option>
                <?php
                $empresas_filtro = $db->query("SELECT DISTINCT nombre FROM empresas ORDER BY nombre")->fetchAll(PDO::FETCH_COLUMN);
                foreach ($empresas_filtro as $emp_f):
                    ?>
                    <option value="<?= htmlspecialchars($emp_f) ?>"><?= htmlspecialchars($emp_f) ?></option>
                <?php endforeach; ?>
            </select>
            <i
                class="fa-solid fa-chevron-down absolute right-4 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none text-xs"></i>
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
                            <th class="py-3 px-3 text-center w-12">#</th>
                            <th class="py-3 px-4">Equipo</th>
                            <th class="py-3 px-4">Marca</th>
                            <th class="py-3 px-4">Modelo</th>
                            <th class="py-3 px-4">S/N</th>
                            <th class="py-3 px-4">¿Asignado?</th>
                            <th class="py-3 px-4 text-center">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($cred_equipos)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-10 text-slate-400">
                                    No hay credenciales de equipos registradas.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php
                            $num_eq = 1;
                            foreach ($cred_equipos as $ce):
                                $colaborador = !empty($ce['colaborador_nombre']) ? $ce['colaborador_nombre'] : 'Sin Asignar';
                                $area = !empty($ce['area_nombre']) ? $ce['area_nombre'] : 'Sin Área';
                                $asignado_txt = ($ce['equipo_estado'] === 'Asignado') ? 'Asignado' : 'No Asignado';

                                $allText = $asignado_txt . ' ' . ($ce['empresa_nombre'] ?? '') . ' ' . ($ce['tipo'] ?? '') . ' ' . ($ce['marca'] ?? '') . ' ' . ($ce['modelo'] ?? '') . ' ' . ($ce['service_tag_sn'] ?? '') . ' ' . ($ce['usuario_local'] ?? '');
                                $searchText = strtolower(str_replace(['á', 'é', 'í', 'ó', 'ú', 'ñ', 'Á', 'É', 'Í', 'Ó', 'Ú', 'Ñ'], ['a', 'e', 'i', 'o', 'u', 'n', 'a', 'e', 'i', 'o', 'u', 'n'], $allText));
                                ?>
                                <tr class="item-fila hover:bg-cyan-50/60 cursor-pointer transition border-b border-slate-100 group"
                                    data-empresa="<?= htmlspecialchars($ce['empresa_nombre'] ?? '') ?>"
                                    data-search-text="<?= htmlspecialchars($searchText) ?>"
                                    onclick='verDetalleCredEquipo(<?= json_encode($ce, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                    <!-- # Numeración -->
                                    <td
                                        class="py-3 px-3 text-center text-xs font-mono font-bold text-slate-400 group-hover:text-cyan-600 num-cell">
                                        <?= $num_eq++ ?>
                                    </td>

                                    <!-- Equipo -->
                                    <td class="py-3 px-4 text-xs font-semibold text-slate-800">
                                        <span class="inline-flex items-center gap-1.5">
                                            <?php
                                            $tipoUpper = strtoupper(trim($ce['tipo']));
                                            if ($tipoUpper === 'IMPRESORA') {
                                                echo '<i class="fa-solid fa-print text-purple-600 text-xs"></i>';
                                            } elseif ($tipoUpper === 'DESKTOP') {
                                                echo '<i class="fa-solid fa-desktop text-blue-600 text-xs"></i>';
                                            } elseif ($tipoUpper === 'UPS') {
                                                echo '<i class="fa-solid fa-plug text-emerald-600 text-xs"></i>';
                                            } else {
                                                echo '<i class="fa-solid fa-laptop text-cyan-600 text-xs"></i>';
                                            }
                                            ?>
                                            <?= htmlspecialchars($ce['tipo']) ?>
                                        </span>
                                    </td>

                                    <!-- Marca -->
                                    <td class="py-3 px-4 text-xs text-slate-700 font-medium">
                                        <?= htmlspecialchars($ce['marca'] ?: '-') ?>
                                    </td>

                                    <!-- Modelo -->
                                    <td class="py-3 px-4 text-xs text-slate-700 font-mono">
                                        <?= htmlspecialchars($ce['modelo'] ?: '-') ?>
                                    </td>

                                    <!-- S/N -->
                                    <td class="py-3 px-4 text-xs font-mono text-slate-700 select-all">
                                        <?= htmlspecialchars($ce['service_tag_sn'] ?: '-') ?>
                                    </td>

                                    <!-- ¿Asignado? -->
                                    <td class="py-3 px-4 text-xs font-semibold">
                                        <?php if ($ce['equipo_estado'] === 'Asignado'): ?>
                                            <span
                                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-700 font-bold text-xs">
                                                <i class="fa-solid fa-circle-check text-emerald-500"></i> Asignado
                                            </span>
                                        <?php else: ?>
                                            <span
                                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-slate-50 border border-slate-200 text-slate-500 font-medium text-xs">
                                                <i class="fa-solid fa-circle text-slate-400"></i> No Asignado
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Acciones -->
                                    <td class="py-3 px-4 text-center">
                                        <div class="flex items-center justify-center gap-1" onclick="event.stopPropagation();">
                                            <button
                                                onclick='event.stopPropagation(); abrirEditarCredEquipo(<?= json_encode($ce, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'
                                                class="h-8 w-8 rounded-lg hover:bg-amber-50 flex items-center justify-center text-amber-500 hover:text-amber-600 transition"
                                                title="Editar Credencial y Detalles">
                                                <i class="fa-solid fa-pen text-xs"></i>
                                            </button>
                                            <button type="button" onclick="event.stopPropagation(); confirmarEliminar('guardar.php?action=delete_eq&id=<?= $ce['id'] ?>', 'esta credencial de equipo')"
                                                class="h-8 w-8 rounded-lg hover:bg-rose-50 flex items-center justify-center text-slate-400 hover:text-rose-500 transition"
                                                title="Eliminar">
                                                <i class="fa-regular fa-trash-can text-xs"></i>
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

        <!-- 2. TABLA CREDENCIALES WEB -->
    <?php elseif ($tab === 'web'): ?>
        <div class="glass-panel rounded-2xl overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left modern-table text-sm">
                    <thead>
                        <tr>
                            <th class="py-3 px-3 text-center w-12">#</th>
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
                                <td colspan="7" class="text-center py-10 text-slate-400">
                                    No hay credenciales web registradas.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php
                            $num_web = 1;
                            foreach ($cred_web as $cw): ?>
                                <tr class="item-fila hover:bg-cyan-50/60 cursor-pointer transition border-b border-slate-100 group"
                                    data-empresa=""
                                    data-search-text="<?= htmlspecialchars(strtolower($cw['plataforma'] . ' ' . $cw['url_acceso'] . ' ' . $cw['usuario'] . ' ' . ($cw['notas'] ?? ''))) ?>"
                                    onclick='verDetalleCredWeb(<?= json_encode($cw, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                    <!-- # Numeración -->
                                    <td
                                        class="py-3 px-3 text-center text-xs font-mono font-bold text-slate-400 group-hover:text-cyan-600 num-cell">
                                        <?= $num_web++ ?>
                                    </td>
                                    <td
                                        class="py-3 px-4 font-semibold text-slate-800 group-hover:text-cyan-700 transition flex items-center gap-1.5">
                                        <i class="fa-solid fa-globe text-xs text-slate-400 group-hover:text-cyan-600"></i>
                                        <?= htmlspecialchars($cw['plataforma']) ?>
                                    </td>
                                    <td class="py-3 px-4 font-mono text-xs" onclick="event.stopPropagation();">
                                        <?php if (!empty($cw['url_acceso'])): ?>
                                            <a href="<?= htmlspecialchars($cw['url_acceso']) ?>" target="_blank"
                                                onclick="event.stopPropagation();"
                                                class="text-cyan-600 hover:underline inline-flex items-center max-w-[200px] truncate"
                                                title="<?= htmlspecialchars($cw['url_acceso']) ?>">
                                                <?= htmlspecialchars($cw['url_acceso']) ?> <i
                                                    class="fa-solid fa-arrow-up-right-from-square text-[10px] shrink-0 ml-1"></i>
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
                                            <span class="password-text text-xs text-slate-400 font-mono"
                                                data-password="<?= htmlspecialchars($cw['pass_plain']) ?>">••••••••</span>
                                            <button onclick="event.stopPropagation(); togglePasswordInline(this)"
                                                class="text-slate-500 hover:text-cyan-600 text-xs transition"
                                                title="Revelar Contraseña">
                                                <i class="fa-regular fa-eye"></i>
                                            </button>
                                            <button onclick="event.stopPropagation(); copyPasswordInline(this)"
                                                class="text-slate-500 hover:text-emerald-600 text-xs transition"
                                                title="Copiar Contraseña">
                                                <i class="fa-regular fa-copy"></i>
                                            </button>
                                        </div>
                                    </td>
                                    <td class="py-3 px-4 text-xs text-slate-500 max-w-[250px] truncate"
                                        title="<?= htmlspecialchars($cw['notas'] ?? '') ?>">
                                        <?= htmlspecialchars($cw['notas'] ?? '-') ?>
                                    </td>
                                    <td class="py-3 px-4 text-center">
                                        <div class="flex items-center justify-center gap-1" onclick="event.stopPropagation();">
                                            <button type="button" onclick="event.stopPropagation(); confirmarEliminar('guardar.php?action=delete_web&id=<?= $cw['id'] ?>', 'esta credencial web')"
                                                class="h-8 w-8 rounded-lg hover:bg-rose-50 flex items-center justify-center text-slate-400 hover:text-rose-500 transition"
                                                title="Eliminar">
                                                <i class="fa-regular fa-trash-can text-xs"></i>
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

        <!-- 3. TABLA CUENTAS MICROSOFT 365 -->
    <?php elseif ($tab === 'm365'): ?>
        <div class="glass-panel rounded-2xl overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left modern-table text-sm">
                    <thead>
                        <tr>
                            <th class="py-3 px-3 text-center w-12">#</th>
                            <th class="py-3 px-4">Usuario</th>
                            <th class="py-3 px-4">Cargo</th>
                            <th class="py-3 px-4">Empresa</th>
                            <th class="py-3 px-4">Cuenta M365 (Correo)</th>
                            <th class="py-3 px-4">Contraseña</th>
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
                            <?php
                            $num_m365 = 1;
                            foreach ($cuentas_m365 as $m365):
                                // Clases de estado
                                if ($m365['estado'] == 'Activa') {
                                    $estado_badge = 'badge-success';
                                } elseif ($m365['estado'] == 'Suspendida') {
                                    $estado_badge = 'badge-pending';
                                } else {
                                    $estado_badge = 'badge-danger';
                                }
                                $empresaNombre = !empty($m365['empresa_nombre']) ? $m365['empresa_nombre'] : '';
                                if (empty($empresaNombre) && !empty($m365['correo'])) {
                                    if (strpos($m365['correo'], '@mastertec') !== false) {
                                        $empresaNombre = 'Mastertec';
                                    } elseif (strpos($m365['correo'], '.com.hn') !== false || strpos($m365['correo'], 'honduras') !== false) {
                                        $empresaNombre = 'Suministros HN';
                                    } elseif (strpos($m365['correo'], '@suministros') !== false) {
                                        $empresaNombre = 'Suministros Integrales';
                                    } elseif (strpos($m365['correo'], '@vargascentro') !== false || strpos($m365['correo'], '@centrodigital') !== false) {
                                        $empresaNombre = 'Centro de Pintura';
                                    } else {
                                        $empresaNombre = 'Mastertec';
                                    }
                                }
                                if (empty($empresaNombre))
                                    $empresaNombre = 'Mastertec';

                                $cargoNombre = !empty($m365['empleado_cargo']) ? $m365['empleado_cargo'] : (!empty($m365['empleado_depto']) ? $m365['empleado_depto'] : '-');
                                $searchText = strtolower(str_replace(['á', 'é', 'í', 'ó', 'ú', 'ñ', 'Á', 'É', 'Í', 'Ó', 'Ú', 'Ñ'], ['a', 'e', 'i', 'o', 'u', 'n', 'a', 'e', 'i', 'o', 'u', 'n'], ($m365['correo'] ?? '') . ' ' . ($m365['empleado_nombre'] ?? '') . ' ' . $cargoNombre . ' ' . $empresaNombre . ' ' . ($m365['licencia_tipo'] ?? '')));
                                ?>
                                <tr class="item-fila hover:bg-cyan-50/60 cursor-pointer transition border-b border-slate-100 group"
                                    data-empresa="<?= htmlspecialchars($empresaNombre) ?>"
                                    data-search-text="<?= htmlspecialchars($searchText) ?>"
                                    onclick='verDetalleM365(<?= json_encode($m365, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                    <!-- # Numeración -->
                                    <td
                                        class="py-3 px-3 text-center text-xs font-mono font-bold text-slate-400 group-hover:text-cyan-600 num-cell">
                                        <?= $num_m365++ ?>
                                    </td>
                                    <!-- Usuario -->
                                    <td class="py-3 px-4 text-xs font-semibold text-slate-800 group-hover:text-cyan-700 transition">
                                        <div class="flex items-center gap-2">
                                            <div
                                                class="h-7 w-7 rounded-full bg-slate-100 text-slate-600 flex items-center justify-center text-xs font-bold shrink-0">
                                                <i class="fa-regular fa-user"></i>
                                            </div>
                                            <span><?= htmlspecialchars(!empty($m365['empleado_nombre']) ? $m365['empleado_nombre'] : 'Sin Asignar') ?></span>
                                        </div>
                                    </td>
                                    <!-- Cargo -->
                                    <td class="py-3 px-4 text-xs text-slate-700 font-medium">
                                        <?= htmlspecialchars($cargoNombre) ?>
                                    </td>
                                    <!-- Empresa -->
                                    <td class="py-3 px-4 text-xs text-slate-600 font-medium">
                                        <span
                                            class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md bg-slate-100 text-slate-700">
                                            <i class="fa-solid fa-building text-[10px] text-slate-400"></i>
                                            <?= htmlspecialchars($empresaNombre) ?>
                                        </span>
                                    </td>
                                    <!-- Cuenta M365 -->
                                    <td class="py-3 px-4 text-xs font-mono text-slate-700">
                                        <span class="inline-flex items-center gap-1.5 text-cyan-600 font-semibold">
                                            <i class="fa-solid fa-cloud text-xs text-cyan-500"></i>
                                            <?= htmlspecialchars($m365['correo']) ?>
                                        </span>
                                    </td>
                                    <td class="py-3 px-4" onclick="event.stopPropagation();">
                                        <?php if (!empty($m365['pass_plain'])): ?>
                                            <div class="flex items-center gap-2">
                                                <span class="password-text text-xs text-slate-400 font-mono"
                                                    data-password="<?= htmlspecialchars($m365['pass_plain']) ?>">••••••••</span>
                                                <button onclick="event.stopPropagation(); togglePasswordInline(this)"
                                                    class="text-slate-500 hover:text-cyan-600 text-xs transition"
                                                    title="Revelar Contraseña">
                                                    <i class="fa-regular fa-eye"></i>
                                                </button>
                                                <button onclick="event.stopPropagation(); copyPasswordInline(this)"
                                                    class="text-slate-500 hover:text-emerald-600 text-xs transition"
                                                    title="Copiar Contraseña">
                                                    <i class="fa-regular fa-copy"></i>
                                                </button>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-xs text-slate-400 font-normal">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3 px-4 text-center">
                                        <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold <?= $estado_badge ?>">
                                            <?= $m365['estado'] ?>
                                        </span>
                                    </td>
                                    <td class="py-3 px-4 text-center">
                                        <div class="flex items-center justify-center gap-1" onclick="event.stopPropagation();">
                                            <!-- Editar -->
                                            <button
                                                onclick='event.stopPropagation(); abrirEditarM365(<?= json_encode($m365, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'
                                                class="h-8 w-8 rounded-lg hover:bg-amber-50 flex items-center justify-center text-amber-500 hover:text-amber-600 transition"
                                                title="Editar">
                                                <i class="fa-solid fa-pen text-xs"></i>
                                            </button>
                                            <!-- Eliminar -->
                                            <button type="button" onclick="event.stopPropagation(); confirmarEliminar('guardar.php?action=delete_m365&id=<?= $m365['id'] ?>', 'esta cuenta de Microsoft 365')"
                                                class="h-8 w-8 rounded-lg hover:bg-rose-50 flex items-center justify-center text-slate-400 hover:text-rose-500 transition"
                                                title="Eliminar">
                                                <i class="fa-regular fa-trash-can text-xs"></i>
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
                            <th class="py-3 px-4"><i class="fa-solid fa-envelope text-blue-500 mr-1"></i> Correo Corporativo
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($cuentas_gmail)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-10 text-slate-400">
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
                                            <div
                                                class="h-8 w-8 rounded-full bg-red-100 border border-red-200 flex items-center justify-center text-red-700 font-bold text-xs shrink-0">
                                                <?= strtoupper(substr($g['nombre'], 0, 1)) ?>
                                            </div>
                                            <span
                                                class="font-bold text-slate-800 group-hover:text-red-700 transition"><?= htmlspecialchars($g['nombre']) ?></span>
                                        </div>
                                    </td>
                                    <td class="py-3 px-4 text-xs text-slate-600">
                                        <div class="font-semibold text-slate-700"><?= htmlspecialchars($g['cargo'] ?? '-') ?></div>
                                        <span
                                            class="text-[10px] text-slate-400 font-medium"><?= htmlspecialchars($g['departamento'] ?? '') ?></span>
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
                                                <span class="password-text text-xs text-slate-400 font-mono"
                                                    data-password="<?= htmlspecialchars($g['pass_gmail_plain']) ?>">••••••••</span>
                                                <button onclick="event.stopPropagation(); togglePasswordInline(this)"
                                                    class="text-slate-500 hover:text-red-600 text-xs transition"
                                                    title="Revelar Contraseña">
                                                    <i class="fa-regular fa-eye"></i>
                                                </button>
                                                <button onclick="event.stopPropagation(); copyPasswordInline(this)"
                                                    class="text-slate-500 hover:text-emerald-600 text-xs transition"
                                                    title="Copiar Contraseña">
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
                                            <div
                                                class="h-9 w-9 rounded-full bg-violet-100 border border-violet-200 flex items-center justify-center text-violet-700 font-extrabold text-sm shrink-0">
                                                <?= strtoupper(substr($f['nombre'], 0, 1)) ?>
                                            </div>
                                            <span
                                                class="font-bold text-slate-800 group-hover:text-violet-700 transition"><?= htmlspecialchars($f['nombre']) ?></span>
                                        </div>
                                    </td>
                                    <td class="py-3 px-4 text-xs">
                                        <div class="font-semibold text-slate-700"><?= htmlspecialchars($f['cargo'] ?? '-') ?></div>
                                        <span
                                            class="text-[10px] text-slate-400"><?= htmlspecialchars($f['departamento'] ?? '') ?></span>
                                    </td>
                                    <td class="py-3 px-4 text-xs font-medium text-slate-600">
                                        <?= htmlspecialchars($f['sucursal_nombre'] ?? '-') ?>
                                    </td>
                                    <td class="py-3 px-4 text-xs">
                                        <?php if (!empty($f['equipos'])): ?>
                                            <div class="space-y-2">
                                                <?php foreach ($f['equipos'] as $eq): ?>
                                                    <div>
                                                        <div class="font-semibold text-slate-700 flex items-center gap-1">
                                                            <i class="fa-solid fa-laptop text-cyan-600 text-[10px]"></i>
                                                            <?= htmlspecialchars($eq['eq_tipo'] . ' ' . $eq['eq_marca'] . ' ' . $eq['eq_modelo']) ?>
                                                        </div>
                                                        <span class="text-[10px] text-slate-400 font-mono">S/N:
                                                            <?= htmlspecialchars($eq['service_tag_sn'] ?? '') ?></span>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
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
                                            <span
                                                class="text-[10px] text-slate-400"><?= htmlspecialchars($f['licencia_tipo'] ?? '') ?></span>
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
                                        <button
                                            onclick='event.stopPropagation(); verFichaConsolidada(<?= json_encode($f, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'
                                            class="h-8 w-8 rounded-lg hover:bg-violet-50 flex items-center justify-center text-violet-600 transition mx-auto"
                                            title="Abrir Ficha Completa">
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
<div id="modal-ficha-consolidada"
    class="fixed inset-0 bg-slate-900/70 backdrop-blur-sm z-[200] flex items-center justify-center hidden p-4">
    <div
        class="bg-white border border-slate-200 rounded-3xl w-full max-w-2xl max-h-[90vh] overflow-hidden shadow-2xl flex flex-col">

        <!-- Header -->
        <div
            class="p-6 border-b border-slate-100 bg-gradient-to-r from-violet-50 to-white flex items-center justify-between shrink-0">
            <div class="flex items-center gap-3">
                <div class="h-12 w-12 rounded-2xl bg-violet-100 border border-violet-300 text-violet-700 flex items-center justify-center text-xl font-extrabold shrink-0"
                    id="fc_initials">C</div>
                <div>
                    <h3 class="font-bold text-slate-800 text-lg flex items-center gap-2">
                        <span id="fc_nombre">Colaborador</span>
                        <a href="#" id="fc_link_emp" class="text-slate-400 hover:text-violet-600 transition text-xs"
                            title="Ver ficha completa de colaborador">
                            <i class="fa-solid fa-up-right-from-square"></i>
                        </a>
                    </h3>
                    <p class="text-xs text-slate-500 font-medium" id="fc_info">Cargo · Departamento · Sucursal</p>
                </div>
            </div>
            <button onclick="cerrarModal('modal-ficha-consolidada')"
                class="h-9 w-9 rounded-full hover:bg-slate-200 text-slate-400 hover:text-slate-700 flex items-center justify-center transition text-lg">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <!-- Body scrollable -->
        <div class="overflow-y-auto flex-1 p-6 space-y-5">

            <!-- EQUIPO ASIGNADO -->
            <!-- EQUIPOS ASIGNADOS -->
            <div id="fc_equipos_container" class="space-y-4">
                <!-- Se renderizan dinámicamente en verFichaConsolidada -->
            </div>

            <!-- M365 -->
            <div id="fc_bloque_m365" class="border border-emerald-200 rounded-2xl overflow-hidden">
                <div class="bg-emerald-50 px-4 py-2.5 flex items-center gap-2 border-b border-emerald-200">
                    <i class="fa-solid fa-cloud text-emerald-600 text-sm"></i>
                    <span class="text-xs font-bold text-emerald-700 uppercase tracking-wider">Cuenta Microsoft
                        365</span>
                    <span
                        class="ml-auto px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-700 border border-emerald-200"
                        id="fc_m365_estado"></span>
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
                            <input type="password" readonly id="fc_m365_pass"
                                class="bg-slate-100 border border-slate-200 rounded-lg px-2 py-1 text-xs font-mono flex-1 text-slate-800">
                            <button type="button" onclick="toggleFieldPass('fc_m365_pass','fc_m365_pass_ico')"
                                class="px-2 py-1 rounded bg-slate-200 text-slate-500 hover:text-slate-800 text-xs">
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
                    <span class="text-xs font-bold text-red-600 uppercase tracking-wider">Cuentas Personales y
                        Acceso</span>
                </div>
                <div class="p-4" id="fc_credenciales_container">
                    <!-- Contenido dinámico generado por Javascript -->
                </div>
            </div>

        </div>

        <!-- Footer -->
        <div class="p-4 border-t border-slate-100 bg-slate-50 flex justify-end">
            <button onclick="cerrarModal('modal-det-cred-equipo')"
                class="px-5 py-2 rounded-xl bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold transition shadow-md">
                Cerrar
            </button>
        </div>
    </div>
</div>

<!-- 1. Modal Credencial Equipo -->
<div id="modal-equipo"
    class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-center justify-center hidden p-4">
    <div
        class="bg-white border border-slate-200 rounded-3xl w-full max-w-lg overflow-hidden shadow-2xl animate-fade-in">
        <div class="p-6 border-b border-slate-200 flex items-center justify-between">
            <h3 class="font-bold text-slate-800 flex items-center gap-2">
                <i class="fa-solid fa-desktop text-cyan-400"></i> Nueva Credencial de Equipo
            </h3>
            <button onclick="cerrarModal('modal-equipo')" class="text-slate-400 hover:text-slate-800"><i
                    class="fa-solid fa-xmark"></i></button>
        </div>
        <form autocomplete="off" action="guardar.php?action=add_eq" method="POST"
            class="p-6 space-y-4 max-h-[85vh] overflow-y-auto">
            <input type="hidden" name="equipo_id" value="NEW_EQUIPO">

            <!-- Campos para registrar nuevo equipo -->
            <div id="sec_nuevo_equipo" class="space-y-3">
                <h4 class="text-xs font-bold text-cyan-800 uppercase tracking-wider flex items-center gap-1.5">
                    <i class="fa-solid fa-laptop text-cyan-600"></i> Datos del Nuevo Equipo
                </h4>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="text-[11px] font-semibold text-slate-600 block mb-1">Tipo de Equipo</label>
                        <select autocomplete="off" name="nuevo_eq_tipo" id="nuevo_eq_tipo"
                            onchange="onNuevoEqTipoChange(this)"
                            class="w-full bg-white border border-slate-200 rounded-xl py-2 px-3 text-xs text-slate-800 focus:outline-none focus:border-cyan-400">
                            <option value="Laptop" selected>Laptop</option>
                            <option value="Desktop">Desktop</option>
                            <option value="Impresora">Impresora</option>
                            <option value="Celular">Celular</option>
                            <option value="Servidor">Servidor</option>
                            <option value="Switch">Switch</option>
                            <option value="AP">AP</option>
                            <option value="Control de Acceso">Control de Acceso</option>
                            <option value="__otro__">+ Agregar otro tipo...</option>
                        </select>
                        <div id="nuevo_eq_tipo_otro_container" class="hidden mt-1.5">
                            <input type="text" name="nuevo_eq_tipo_otro" id="nuevo_eq_tipo_otro"
                                placeholder="Especifica el tipo de equipo..."
                                class="w-full bg-white border border-slate-200 rounded-xl py-2 px-3 text-xs text-slate-800 focus:outline-none focus:border-cyan-400">
                        </div>
                    </div>
                    <div>
                        <label class="text-[11px] font-semibold text-slate-600 block mb-1">Marca</label>
                        <input type="text" name="nuevo_eq_marca" placeholder="Ej: Dell, HP, Lenovo"
                            class="w-full bg-white border border-slate-200 rounded-xl py-2 px-3 text-xs text-slate-800 focus:outline-none focus:border-cyan-400">
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="text-[11px] font-semibold text-slate-600 block mb-1">Modelo</label>
                        <input type="text" name="nuevo_eq_modelo" placeholder="Ej: Latitude 3420"
                            class="w-full bg-white border border-slate-200 rounded-xl py-2 px-3 text-xs text-slate-800 focus:outline-none focus:border-cyan-400">
                    </div>
                    <div>
                        <label class="text-[11px] font-semibold text-slate-600 block mb-1">Número de Serie (S/N)</label>
                        <input type="text" name="nuevo_eq_sn" placeholder="Ej: 5CD12345X"
                            class="w-full bg-white border border-slate-200 rounded-xl py-2 px-3 text-xs font-mono text-slate-800 focus:outline-none focus:border-cyan-400">
                    </div>
                </div>
                <div class="grid grid-cols-3 gap-2" id="sec_nuevo_eq_hardware">
                    <div>
                        <label class="text-[11px] font-semibold text-slate-600 block mb-1">Procesador</label>
                        <input type="text" name="nuevo_eq_cpu" placeholder="Core i5-1135G7"
                            class="w-full bg-white border border-slate-200 rounded-xl py-2 px-2.5 text-xs text-slate-800 focus:outline-none focus:border-cyan-400">
                    </div>
                    <div>
                        <label class="text-[11px] font-semibold text-slate-600 block mb-1">Disco</label>
                        <input type="text" name="nuevo_eq_disco" placeholder="512GB SSD"
                            class="w-full bg-white border border-slate-200 rounded-xl py-2 px-2.5 text-xs text-slate-800 focus:outline-none focus:border-cyan-400">
                    </div>
                    <div>
                        <label class="text-[11px] font-semibold text-slate-600 block mb-1">RAM</label>
                        <input type="text" name="nuevo_eq_ram" placeholder="16GB DDR4"
                            class="w-full bg-white border border-slate-200 rounded-xl py-2 px-2.5 text-xs text-slate-800 focus:outline-none focus:border-cyan-400">
                    </div>
                </div>
                <div id="sec_nuevo_eq_ip" class="hidden">
                    <label class="text-[11px] font-semibold text-slate-600 block mb-1">Dirección IP</label>
                    <input type="text" name="nuevo_eq_ip" placeholder="Ej: 192.168.1.50"
                        class="w-full bg-white border border-slate-200 rounded-xl py-2 px-3 text-xs font-mono text-slate-800 focus:outline-none focus:border-cyan-400">
                </div>
                <div>
                    <label class="text-[11px] font-semibold text-slate-600 block mb-1">Colaborador Asignado</label>
                    <select autocomplete="off" name="nuevo_eq_empleado_id" id="nuevo_eq_empleado_id"
                        class="w-full bg-white border border-slate-200 rounded-xl py-2 px-3 text-xs text-slate-800 focus:outline-none focus:border-cyan-400">
                        <option value="">-- Sin Asignar --</option>
                        <?php foreach ($empleados as $emp): ?>
                            <option value="<?= $emp['id'] ?>"><?= htmlspecialchars($emp['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4" id="sec_nuevo_eq_credenciales_1">
                <div>
                    <label for="eq_usr"
                        class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Usuario
                        Local</label>
                    <input type="text" name="usuario_local" id="eq_usr" required
                        placeholder="Ej: Administrador, Soporte, etc." autocomplete="off"
                        class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400">
                </div>
                <div>
                    <label for="eq_pass"
                        class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Contraseña</label>
                    <input type="password" name="password" id="eq_pass" required placeholder="••••••••"
                        class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400">
                </div>
            </div>
            <div id="sec_nuevo_eq_credenciales_2">
                <label for="eq_bit"
                    class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Clave de BitLocker
                    (Opcional)</label>
                <input type="text" name="clave_bitlocker" id="eq_bit" placeholder="Ej: 432901-230984-..."
                    class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm font-mono text-slate-800 focus:outline-none focus:border-cyan-400">
            </div>
            <div id="sec_nuevo_eq_credenciales_3">
                <label for="eq_notes"
                    class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Notas de
                    Acceso</label>
                <textarea name="notas_acceso" id="eq_notes" rows="2"
                    placeholder="Indicaciones para acceder al terminal..."
                    class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400"></textarea>
            </div>
            <div class="pt-4 flex gap-3">
                <button type="button" onclick="cerrarModal('modal-equipo')"
                    class="flex-1 py-3 rounded-xl bg-white/5 hover:bg-white/10 text-slate-800 font-semibold text-sm transition border border-slate-200">Cancelar</button>
                <button type="submit"
                    class="flex-1 py-3 rounded-xl grad-cyan-blue text-slate-800 font-bold text-sm tracking-wide shadow-lg shadow-cyan-500/20 hover:opacity-90 transition">Guardar
                    Credencial</button>
            </div>
        </form>
    </div>
</div>

<!-- 2. Modal Credencial Web -->
<div id="modal-web" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-center justify-center hidden p-4">
    <div
        class="bg-white border border-slate-200 rounded-3xl w-full max-w-lg overflow-hidden shadow-2xl animate-fade-in">
        <div class="p-6 border-b border-slate-200 flex items-center justify-between">
            <h3 class="font-bold text-slate-800 flex items-center gap-2">
                <i class="fa-solid fa-globe text-cyan-400"></i> Nueva Credencial Web / Portal
            </h3>
            <button onclick="cerrarModal('modal-web')" class="text-slate-400 hover:text-slate-800"><i
                    class="fa-solid fa-xmark"></i></button>
        </div>
        <form autocomplete="off" action="guardar.php?action=add_web" method="POST" class="p-6 space-y-4">
            <div>
                <label for="web_plat"
                    class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Plataforma / Nombre
                    del Servicio</label>
                <input type="text" name="plataforma" id="web_plat" required
                    placeholder="Ej: FortiCloud, Microsoft Partner Center"
                    class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400">
            </div>
            <div>
                <label for="web_url"
                    class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">URL de
                    Acceso</label>
                <input type="url" name="url_acceso" id="web_url" placeholder="https://admin.ejemplo.com"
                    class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400">
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="web_usr"
                        class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Usuario /
                        Email</label>
                    <input type="text" name="usuario" id="web_usr" required placeholder="soporte@empresa.com"
                        class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400">
                </div>
                <div>
                    <label for="web_pass_inp"
                        class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Contraseña</label>
                    <input type="password" name="password" id="web_pass_inp" required placeholder="••••••••"
                        class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400">
                </div>
            </div>
            <div>
                <label for="web_notes"
                    class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Notas
                    descriptivas</label>
                <textarea name="notas" id="web_notes" rows="2"
                    placeholder="Códigos MFA de recuperación, responsables, etc..."
                    class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400"></textarea>
            </div>
            <div class="pt-4 flex gap-3">
                <button type="button" onclick="cerrarModal('modal-web')"
                    class="flex-1 py-3 rounded-xl bg-white/5 hover:bg-white/10 text-slate-800 font-semibold text-sm transition border border-slate-200">Cancelar</button>
                <button type="submit"
                    class="flex-1 py-3 rounded-xl grad-cyan-blue text-slate-800 font-bold text-sm tracking-wide shadow-lg shadow-cyan-500/20 hover:opacity-90 transition">Guardar
                    Credencial</button>
            </div>
        </form>
    </div>
</div>

<!-- 3. Modal Cuenta Microsoft 365 -->
<div id="modal-m365"
    class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-center justify-center hidden p-4">
    <div
        class="bg-white border border-slate-200 rounded-3xl w-full max-w-lg overflow-hidden shadow-2xl animate-fade-in">
        <div class="p-6 border-b border-slate-200 flex items-center justify-between">
            <h3 class="font-bold text-slate-800 flex items-center gap-2">
                <i class="fa-solid fa-cloud text-cyan-400"></i> Registrar Cuenta de Microsoft 365
            </h3>
            <button onclick="cerrarModal('modal-m365')" class="text-slate-400 hover:text-slate-800"><i
                    class="fa-solid fa-xmark"></i></button>
        </div>
        <form autocomplete="off" action="guardar.php?action=add_m365" method="POST" class="p-6 space-y-4">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="m_email"
                        class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Correo
                        Corporativo</label>
                    <input type="email" name="correo" id="m_email" required placeholder="usuario@empresa.com.ni"
                        class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400">
                </div>
                <div>
                    <label for="m_pass"
                        class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Contraseña de
                        Correo</label>
                    <input type="password" name="password" id="m_pass" required placeholder="••••••••"
                        class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400">
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="m_lic"
                        class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Tipo de
                        Licencia</label>
                    <select autocomplete="off" name="licencia_tipo" id="m_lic" required
                        class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400">
                        <option value="Microsoft 365 Business Basic">M365 Business Basic</option>
                        <option value="Microsoft 365 Business Standard" selected>M365 Business Standard</option>
                        <option value="Microsoft 365 Business Premium">M365 Business Premium</option>
                        <option value="Exchange Online Plan 1">Exchange Online Plan 1</option>
                        <option value="Exchange Online Plan 2">Exchange Online Plan 2</option>
                    </select>
                </div>
                <div>
                    <label for="m_emp"
                        class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Empleado
                        Asignado</label>
                    <select autocomplete="off" name="empleado_id" id="m_emp"
                        onchange="checkNuevoEmpleado(this, 'm_nuevo_emp_div')"
                        class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400">
                        <option value="">-- Sin Asignar --</option>
                        <option value="NEW_EMP" class="font-bold text-cyan-600">+ Escribir Nuevo Empleado...</option>
                        <?php foreach ($empleados as $emp): ?>
                            <option value="<?= $emp['id'] ?>">
                                <?= htmlspecialchars($emp['nombre'] . (!empty($emp['cargo']) ? ' (' . $emp['cargo'] . ')' : '')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div id="m_nuevo_emp_div" class="hidden mt-2">
                        <input type="text" name="nuevo_empleado_nombre" placeholder="Escribe Nombre y Apellido..."
                            class="w-full bg-cyan-50/60 border border-cyan-300 rounded-xl py-2.5 px-3 text-xs text-slate-800 focus:outline-none focus:border-cyan-500 font-medium">
                    </div>
                </div>
            </div>

            <!-- Credenciales Adicionales (AG y Gmail) -->
            <div class="bg-cyan-50/50 p-4 rounded-2xl border border-cyan-200/60 space-y-3">
                <h4 class="text-xs font-bold text-cyan-800 uppercase tracking-wider flex items-center gap-1.5">
                    <i class="fa-solid fa-shield-halved text-cyan-600"></i> Credenciales Adicionales (AG y Gmail)
                </h4>
                <div>
                    <label class="text-xs font-semibold text-slate-600 block mb-1">Contraseña AG / Sistema</label>
                    <input type="password" name="pass_ag" placeholder="Opcional"
                        class="w-full bg-white border border-slate-200 rounded-xl py-2.5 px-3 text-xs text-slate-800 focus:outline-none focus:border-cyan-400">
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="text-xs font-semibold text-slate-600 block mb-1">Correo Gmail</label>
                        <input type="email" name="correo_gmail" placeholder="usuario@gmail.com"
                            class="w-full bg-white border border-slate-200 rounded-xl py-2.5 px-3 text-xs text-slate-800 focus:outline-none focus:border-cyan-400">
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-slate-600 block mb-1">Contraseña Gmail</label>
                        <input type="password" name="pass_gmail" placeholder="Opcional"
                            class="w-full bg-white border border-slate-200 rounded-xl py-2.5 px-3 text-xs text-slate-800 focus:outline-none focus:border-cyan-400">
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="m_date"
                        class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Fecha de
                        Inicialización</label>
                    <input type="date" name="fecha_inicializacion" id="m_date" value="<?= date('Y-m-d') ?>" readonly
                        onclick="return false;" tabindex="-1"
                        class="w-full bg-slate-200/70 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-600 font-semibold cursor-not-allowed pointer-events-none select-none">
                </div>
                <div>
                    <label for="m_cic"
                        class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Ciclo de
                        Facturación</label>
                    <select autocomplete="off" name="ciclo_facturacion" id="m_cic" required
                        class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400">
                        <option value="Mensual" selected>Mensual</option>
                        <option value="Anual">Anual</option>
                    </select>
                </div>
            </div>
            <div>
                <label for="m_est"
                    class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Estado</label>
                <select autocomplete="off" name="estado" id="m_est" required
                    class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400">
                    <option value="Activa">Activa</option>
                    <option value="Suspendida">Suspendida</option>
                    <option value="Eliminada">Eliminada</option>
                </select>
            </div>
            <div class="bg-slate-100 border border-cyan-500/10 rounded-xl p-3 text-xs text-slate-500 leading-normal">
                <i class="fa-solid fa-calculator text-cyan-400 mr-1"></i>
                La <b>Fecha de Próximo Pago/Renovación</b> se calculará automáticamente en base a la fecha de
                inicialización y el ciclo seleccionado.
            </div>
            <div class="pt-4 flex gap-3">
                <button type="button" onclick="cerrarModal('modal-m365')"
                    class="flex-1 py-3 rounded-xl bg-white/5 hover:bg-white/10 text-slate-800 font-semibold text-sm transition border border-slate-200">Cancelar</button>
                <button type="submit"
                    class="flex-1 py-3 rounded-xl grad-cyan-blue text-slate-800 font-bold text-sm tracking-wide shadow-lg shadow-cyan-500/20 hover:opacity-90 transition">Guardar
                    Cuenta</button>
            </div>
        </form>
    </div>
</div>

<!-- 4. Modal Editar M365 -->
<div id="modal-editar-m365"
    class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-center justify-center hidden p-4">
    <div
        class="bg-white border border-slate-200 rounded-3xl w-full max-w-lg overflow-hidden shadow-2xl animate-fade-in">
        <div class="p-6 border-b border-slate-200 flex items-center justify-between">
            <h3 class="font-bold text-slate-800 flex items-center gap-2">
                <i class="fa-solid fa-cloud text-emerald-400"></i> Editar Cuenta M365
            </h3>
            <button onclick="cerrarModal('modal-editar-m365')" class="text-slate-400 hover:text-slate-800"><i
                    class="fa-solid fa-xmark"></i></button>
        </div>
        <form autocomplete="off" action="guardar.php?action=edit_m365" method="POST" class="p-6 space-y-4">
            <input type="hidden" name="id" id="edit_m365_id">
            <div>
                <label for="edit_m365_correo"
                    class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Correo
                    Corporativo</label>
                <input type="email" name="correo" id="edit_m365_correo" required
                    class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400">
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="edit_m365_lic"
                        class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Tipo de
                        Licencia</label>
                    <select autocomplete="off" name="licencia_tipo" id="edit_m365_lic" required
                        class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400">
                        <option value="Microsoft 365 Business Basic">M365 Business Basic</option>
                        <option value="Microsoft 365 Business Standard">M365 Business Standard</option>
                        <option value="Microsoft 365 Business Premium">M365 Business Premium</option>
                        <option value="Exchange Online Plan 1">Exchange Online Plan 1</option>
                        <option value="Exchange Online Plan 2">Exchange Online Plan 2</option>
                    </select>
                </div>
                <div>
                    <label for="edit_m365_emp"
                        class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Empleado
                        Asignado</label>
                    <select autocomplete="off" name="empleado_id" id="edit_m365_emp"
                        onchange="checkNuevoEmpleado(this, 'edit_m365_nuevo_emp_div')"
                        class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400">
                        <option value="">-- Cuenta Compartida / Sin Asignar --</option>
                        <option value="NEW_EMP" class="font-bold text-cyan-600">+ Escribir Nuevo Empleado...</option>
                        <?php foreach ($empleados as $emp): ?>
                            <option value="<?= $emp['id'] ?>">
                                <?= htmlspecialchars($emp['nombre'] . (!empty($emp['cargo']) ? ' (' . $emp['cargo'] . ')' : '')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div id="edit_m365_nuevo_emp_div" class="hidden mt-2">
                        <input type="text" name="nuevo_empleado_nombre" placeholder="Escribe Nombre y Apellido..."
                            class="w-full bg-cyan-50/60 border border-cyan-300 rounded-xl py-2.5 px-3 text-xs text-slate-800 focus:outline-none focus:border-cyan-500 font-medium">
                    </div>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="edit_m365_date"
                        class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Fecha de
                        Inicialización</label>
                    <input type="date" name="fecha_inicializacion" id="edit_m365_date" required
                        class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400">
                </div>
                <div>
                    <label for="edit_m365_cic"
                        class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Ciclo de
                        Facturación</label>
                    <select autocomplete="off" name="ciclo_facturacion" id="edit_m365_cic" required
                        class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400">
                        <option value="Mensual">Mensual</option>
                        <option value="Anual">Anual</option>
                    </select>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="edit_m365_est"
                        class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Estado</label>
                    <select autocomplete="off" name="estado" id="edit_m365_est" required
                        class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400">
                        <option value="Activa">Activa</option>
                        <option value="Suspendida">Suspendida</option>
                        <option value="Eliminada">Eliminada</option>
                    </select>
                </div>
                <div>
                    <label for="edit_m365_pass"
                        class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Nueva
                        Contraseña</label>
                    <input type="password" name="password" id="edit_m365_pass"
                        placeholder="(Dejar en blanco para no cambiar)"
                        class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400">
                </div>
            </div>
            <div class="pt-4 flex gap-3">
                <button type="button" onclick="cerrarModal('modal-editar-m365')"
                    class="flex-1 py-3 rounded-xl bg-white/5 hover:bg-white/10 text-slate-800 font-semibold text-sm transition border border-slate-200">Cancelar</button>
                <button type="submit"
                    class="flex-1 py-3 rounded-xl grad-cyan-blue text-slate-800 font-bold text-sm tracking-wide shadow-lg shadow-cyan-500/20 hover:opacity-90 transition">Guardar
                    Cambios</button>
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
            const isEditOrDetail = id.includes('edit') || id.includes('det') || id.includes('ficha');
            if (!isEditOrDetail) {
                modal.querySelectorAll('form').forEach(function (f) { f.reset(); });
                modal.querySelectorAll('select').forEach(function (s) {
                    if (!s.closest('form')) s.selectedIndex = 0;
                });
            }
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

    function normStr(str) {
        return (str || '')
            .toLowerCase()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .trim();
    }

    function aplicarFiltros() {
        const queryInput = document.getElementById('filtro-busqueda');
        const empresaSelect = document.getElementById('filtro-empresa');

        if (!queryInput || !empresaSelect) return;

        const query = normStr(queryInput.value);
        const empresa = normStr(empresaSelect.value);

        // Guardar para persistir el filtro al cambiar de pestaña
        sessionStorage.setItem('filtro_query', queryInput.value);
        sessionStorage.setItem('filtro_empresa', empresaSelect.value);

        const rows = document.querySelectorAll('.item-fila');
        rows.forEach(row => {
            const searchText = normStr(row.getAttribute('data-search-text') || '');
            const rowEmpresa = normStr(row.getAttribute('data-empresa') || '');

            const matchesSearch = (query === '') || searchText.includes(query);
            const matchesEmpresa = (empresa === '') || (rowEmpresa === empresa) || (rowEmpresa === '');

            if (matchesSearch && matchesEmpresa) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });

        // Re-numerar dinámicamente las filas visibles consecutivamente (1, 2, 3...)
        const tables = document.querySelectorAll('table.modern-table');
        tables.forEach(table => {
            let count = 1;
            const tableRows = table.querySelectorAll('tbody tr.item-fila');
            tableRows.forEach(row => {
                if (row.style.display !== 'none') {
                    const numCell = row.querySelector('.num-cell');
                    if (numCell) {
                        numCell.innerText = count++;
                    }
                }
            });
        });
    }
</script>

<!-- Modal Detalle Credencial Equipo -->
<div id="modal-det-cred-equipo"
    class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[100] flex items-center justify-center hidden p-4">
    <div
        class="bg-white border border-slate-200 rounded-3xl w-full max-w-xl overflow-hidden shadow-2xl animate-fade-in relative">
        <!-- Header -->
        <div
            class="p-6 border-b border-slate-100 bg-gradient-to-r from-cyan-50 to-white flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div
                    class="h-11 w-11 rounded-2xl bg-cyan-100 border border-cyan-200 text-cyan-700 flex items-center justify-center text-xl shrink-0 font-bold">
                    <i class="fa-solid fa-laptop"></i>
                </div>
                <div>
                    <h3 class="font-bold text-slate-800 text-base" id="dce_titulo">Credencial de Equipo</h3>
                    <p class="text-xs text-slate-500 font-medium" id="dce_subtitulo">Colaborador · Área</p>
                </div>
            </div>
            <button onclick="cerrarModal('modal-det-cred-equipo')"
                class="h-8 w-8 rounded-full hover:bg-slate-200 text-slate-400 hover:text-slate-700 flex items-center justify-center transition">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <!-- Body -->
        <div class="p-6 space-y-5 text-sm max-h-[80vh] overflow-y-auto">

            <!-- Selector de Múltiples Equipos -->
            <div id="dce_tabs_container" class="hidden mb-2 border-b border-slate-200 pb-3">
                <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider block mb-2">Equipos Asignados
                    a este Colaborador</span>
                <div id="dce_tabs_list" class="flex gap-2 flex-wrap">
                    <!-- Botones generados dinámicamente en JS -->
                </div>
            </div>

            <!-- Especificaciones de Hardware -->
            <div>
                <h4 class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-2.5 flex items-center gap-1.5">
                    <i class="fa-solid fa-microchip text-cyan-600"></i> Especificaciones del Equipo
                </h4>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3 bg-slate-50 p-4 rounded-2xl border border-slate-200">
                    <div>
                        <span class="text-[11px] text-slate-400 block font-semibold">Número de Serie (S/N)</span>
                        <span class="font-mono font-bold text-slate-800 text-xs" id="dce_sn">--</span>
                    </div>
                    <div>
                        <span class="text-[11px] text-slate-400 block font-semibold">Procesador</span>
                        <span class="font-semibold text-slate-800 text-xs" id="dce_cpu">--</span>
                    </div>
                    <div>
                        <span class="text-[11px] text-slate-400 block font-semibold">Memoria RAM</span>
                        <span class="font-semibold text-slate-800 text-xs" id="dce_ram">--</span>
                    </div>
                    <div>
                        <span class="text-[11px] text-slate-400 block font-semibold">Almacenamiento (Disco)</span>
                        <span class="font-semibold text-slate-800 text-xs" id="dce_disco">--</span>
                    </div>
                </div>
            </div>

            <!-- Credenciales Locales -->
            <div>
                <h4 class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-2.5 flex items-center gap-1.5">
                    <i class="fa-solid fa-key text-amber-500"></i> Acceso y Credenciales Locales
                </h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3 bg-slate-50 p-4 rounded-2xl border border-slate-200">
                    <div>
                        <span class="text-[11px] text-slate-400 block font-semibold">Usuario Local</span>
                        <span class="font-mono font-bold text-slate-800 text-sm" id="dce_usr">--</span>
                    </div>
                    <div>
                        <span class="text-[11px] text-slate-400 block font-semibold">Contraseña</span>
                        <div class="flex items-center gap-2 mt-1">
                            <input type="password" readonly id="dce_pass"
                                class="bg-white border border-slate-200 rounded-lg px-2.5 py-1 text-xs font-mono w-full text-slate-800">
                            <button type="button" onclick="toggleFieldPass('dce_pass', 'dce_pass_icon')"
                                class="text-slate-500 hover:text-slate-800 text-xs px-2 py-1 rounded bg-slate-200 shrink-0">
                                <i class="fa-solid fa-eye" id="dce_pass_icon"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- BitLocker -->
            <div id="dce_row_bit" class="bg-slate-50 p-4 rounded-2xl border border-slate-200">
                <span class="text-[11px] text-slate-400 block font-semibold flex items-center gap-1">
                    <i class="fa-solid fa-lock text-slate-400"></i> Clave BitLocker
                </span>
                <span
                    class="font-mono font-bold text-slate-800 text-xs select-all block mt-1 bg-white p-2 rounded-lg border border-slate-200"
                    id="dce_bit">--</span>
            </div>

            <!-- Notas -->
            <div>
                <span class="text-xs font-bold text-slate-400 uppercase tracking-wider block mb-1.5">Notas de
                    Acceso</span>
                <div class="bg-slate-50 p-3 rounded-xl border border-slate-200 text-xs text-slate-700 font-medium"
                    id="dce_notas">--</div>
            </div>
        </div>

        <!-- Footer -->
        <div class="p-4 border-t border-slate-100 bg-slate-50 flex justify-end">
            <button onclick="cerrarModal('modal-det-cred-equipo')"
                class="px-5 py-2 rounded-xl bg-cyan-600 hover:bg-cyan-700 text-white text-xs font-bold transition shadow-md">
                Cerrar
            </button>
        </div>
    </div>
</div>

<!-- Modal Editar Credencial y Equipo -->
<div id="modal-edit-cred-equipo"
    class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[100] flex items-center justify-center hidden p-4">
    <div
        class="bg-white border border-slate-200 rounded-3xl w-full max-w-xl overflow-hidden shadow-2xl animate-fade-in relative max-h-[90vh] flex flex-col">
        <!-- Header -->
        <div
            class="p-6 border-b border-slate-100 bg-gradient-to-r from-amber-50 to-white flex items-center justify-between shrink-0">
            <div class="flex items-center gap-3">
                <div
                    class="h-10 w-10 rounded-xl bg-amber-500/10 border border-amber-500/20 text-amber-600 flex items-center justify-center text-lg font-bold">
                    <i class="fa-solid fa-pen"></i>
                </div>
                <div>
                    <h3 class="font-bold text-slate-800 text-base">Editar Credencial y Detalles</h3>
                    <p class="text-xs text-slate-500" id="ece_colaborador">--</p>
                </div>
            </div>
            <button onclick="cerrarModal('modal-edit-cred-equipo')"
                class="h-8 w-8 rounded-full hover:bg-slate-200 text-slate-400 hover:text-slate-700 flex items-center justify-center transition">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <!-- Form Body -->
        <form autocomplete="off" action="guardar.php?action=edit_eq" method="POST"
            class="p-6 space-y-4 text-sm overflow-y-auto flex-1">
            <input type="hidden" name="id" id="ece_id">
            <input type="hidden" name="equipo_id" id="ece_equipo_id">

            <!-- Credenciales Locales -->
            <div class="bg-amber-50/50 p-4 rounded-2xl border border-amber-200/60 space-y-3"
                id="sec_edit_eq_credenciales">
                <h4 class="text-xs font-bold text-amber-700 uppercase tracking-wider flex items-center gap-1.5">
                    <i class="fa-solid fa-key text-amber-500"></i> Credenciales de Acceso Local
                </h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div>
                        <label class="text-xs font-semibold text-slate-600 block mb-1">Usuario Local</label>
                        <input type="text" name="usuario_local" id="ece_usr" required
                            class="w-full bg-white border border-slate-200 rounded-xl py-2 px-3 text-xs text-slate-800 focus:outline-none focus:border-amber-500">
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-slate-600 block mb-1">Nueva Contraseña
                            (Opcional)</label>
                        <input type="password" name="password" id="ece_pass"
                            placeholder="Dejar en blanco para no cambiar"
                            class="w-full bg-white border border-slate-200 rounded-xl py-2 px-3 text-xs text-slate-800 focus:outline-none focus:border-amber-500">
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3 pt-2">
                    <div>
                        <label class="text-xs font-semibold text-slate-600 block mb-1">Clave BitLocker</label>
                        <input type="text" name="clave_bitlocker" id="ece_bitlocker" placeholder="Opcional"
                            class="w-full bg-white border border-slate-200 rounded-xl py-2 px-3 text-xs font-mono text-slate-800 focus:outline-none focus:border-amber-500">
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-slate-600 block mb-1">Notas de Acceso</label>
                        <input type="text" name="notas_acceso" id="ece_notas"
                            placeholder="Ej: BIOS, Puertos, Acceso admin..."
                            class="w-full bg-white border border-slate-200 rounded-xl py-2 px-3 text-xs text-slate-800 focus:outline-none focus:border-amber-500">
                    </div>
                </div>
            </div>

            <!-- Detalles de Hardware -->
            <div class="bg-slate-50 p-4 rounded-2xl border border-slate-200 space-y-3">
                <h4 class="text-xs font-bold text-slate-600 uppercase tracking-wider flex items-center gap-1.5">
                    <i class="fa-solid fa-microchip text-slate-400"></i> Especificaciones del Equipo
                </h4>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <div>
                        <label class="text-xs font-semibold text-slate-600 block mb-1">Marca</label>
                        <input type="text" name="marca" id="ece_marca" required
                            class="w-full bg-white border border-slate-200 rounded-xl py-2 px-3 text-xs text-slate-800 focus:outline-none focus:border-amber-500">
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-slate-600 block mb-1">Modelo</label>
                        <input type="text" name="modelo" id="ece_modelo" required
                            class="w-full bg-white border border-slate-200 rounded-xl py-2 px-3 text-xs text-slate-800 focus:outline-none focus:border-amber-500">
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-slate-600 block mb-1">Serie / Tag</label>
                        <input type="text" name="service_tag_sn" id="ece_serie" required
                            class="w-full bg-white border border-slate-200 rounded-xl py-2 px-3 text-xs font-mono text-slate-800 focus:outline-none focus:border-amber-500">
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3 pt-2" id="sec_edit_eq_hardware">
                    <div>
                        <label class="text-xs font-semibold text-slate-600 block mb-1">Procesador</label>
                        <input type="text" name="procesador" id="ece_cpu" placeholder="Ej: Core i5-1135G7"
                            class="w-full bg-white border border-slate-200 rounded-xl py-2 px-3 text-xs text-slate-800 focus:outline-none focus:border-amber-500">
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-slate-600 block mb-1">Disco</label>
                        <input type="text" name="disco" id="ece_disco" placeholder="Ej: 512GB NVMe"
                            class="w-full bg-white border border-slate-200 rounded-xl py-2 px-3 text-xs text-slate-800 focus:outline-none focus:border-amber-500">
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-slate-600 block mb-1">RAM</label>
                        <input type="text" name="ram" id="ece_ram" placeholder="Ej: 16GB DDR4"
                            class="w-full bg-white border border-slate-200 rounded-xl py-2 px-3 text-xs text-slate-800 focus:outline-none focus:border-amber-500">
                    </div>
                </div>
                <div class="pt-2" id="sec_edit_eq_ip">
                    <label class="text-xs font-semibold text-slate-600 block mb-1">IP de Red</label>
                    <input type="text" name="ip_direccion" id="ece_ip" placeholder="Ej: 192.168.1.50"
                        class="w-full bg-white border border-slate-200 rounded-xl py-2 px-3 text-xs text-slate-800 focus:outline-none focus:border-amber-500 font-mono">
                </div>
            </div>

            <!-- Asignación de Personal -->
            <div class="bg-indigo-50/50 p-4 rounded-2xl border border-indigo-200/60 space-y-3">
                <h4 class="text-xs font-bold text-indigo-700 uppercase tracking-wider flex items-center gap-1.5">
                    <i class="fa-solid fa-user-tag text-indigo-500"></i> Asignación de Colaborador (Desasignar/Cambiar)
                </h4>
                <div>
                    <label class="text-xs font-semibold text-slate-600 block mb-1">Colaborador Responsable</label>
                    <select autocomplete="off" name="empleado_id" id="ece_empleado_id"
                        class="w-full bg-white border border-slate-200 rounded-xl py-2 px-3 text-xs text-slate-800 focus:outline-none focus:border-indigo-500">
                        <option value="">-- Sin Asignar / Desasignar Colaborador (Liberar Equipo) --</option>
                        <?php foreach ($empleados as $emp): ?>
                            <option value="<?= $emp['id'] ?>"><?= htmlspecialchars($emp['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Footer Buttons -->
            <div class="pt-4 border-t border-slate-100 flex justify-end gap-3 shrink-0">
                <button type="button" onclick="cerrarModal('modal-edit-cred-equipo')"
                    class="px-5 py-2.5 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-600 text-xs font-bold transition">
                    Cancelar
                </button>
                <button type="submit"
                    class="px-6 py-2.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-white text-xs font-bold transition shadow-md flex items-center gap-2">
                    <i class="fa-solid fa-check"></i> Guardar Cambios
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Detalle Credencial Web -->
<div id="modal-det-cred-web"
    class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[100] flex items-center justify-center hidden p-4">
    <div
        class="bg-white border border-slate-200 rounded-3xl w-full max-w-lg overflow-hidden shadow-2xl animate-fade-in relative">
        <div class="p-6 border-b border-slate-100 bg-slate-50/80 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div
                    class="h-10 w-10 rounded-xl bg-cyan-500/10 border border-cyan-500/20 text-cyan-600 flex items-center justify-center text-lg">
                    <i class="fa-solid fa-globe"></i>
                </div>
                <div>
                    <h3 class="font-bold text-slate-800 text-base" id="dcw_titulo">Credencial Web</h3>
                    <p class="text-xs text-slate-500 font-mono" id="dcw_usr">--</p>
                </div>
            </div>
            <button onclick="cerrarModal('modal-det-cred-web')"
                class="h-8 w-8 rounded-full hover:bg-slate-200 text-slate-400 hover:text-slate-700 flex items-center justify-center transition">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div class="p-6 space-y-4 text-sm">
            <div class="bg-slate-50 p-4 rounded-2xl border border-slate-200 space-y-3">
                <div>
                    <span class="text-[11px] text-slate-400 block font-semibold">URL de Acceso</span>
                    <a id="dcw_url" href="#" target="_blank"
                        class="text-cyan-600 font-mono text-xs hover:underline break-all">--</a>
                </div>
                <div class="grid grid-cols-2 gap-3 pt-2 border-t border-slate-200">
                    <div>
                        <span class="text-[11px] text-slate-400 block font-semibold">Usuario</span>
                        <span class="font-mono font-bold text-slate-800" id="dcw_usuario_val">--</span>
                    </div>
                    <div>
                        <span class="text-[11px] text-slate-400 block font-semibold">Contraseña</span>
                        <div class="flex items-center gap-2 mt-0.5">
                            <input type="password" readonly id="dcw_pass"
                                class="bg-white border border-slate-200 rounded-lg px-2 py-1 text-xs font-mono w-full text-slate-800">
                            <button type="button" onclick="toggleFieldPass('dcw_pass', 'dcw_pass_icon')"
                                class="text-slate-500 hover:text-slate-800 text-xs px-2 py-1 rounded bg-slate-200">
                                <i class="fa-solid fa-eye" id="dcw_pass_icon"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <div>
                <span class="text-xs font-bold text-slate-400 uppercase tracking-wider block mb-1">Notas
                    Adicionales</span>
                <div class="bg-slate-50 p-3 rounded-xl border border-slate-200 text-xs text-slate-700 font-medium"
                    id="dcw_notas">--</div>
            </div>
        </div>
        <div class="p-4 border-t border-slate-100 bg-slate-50 flex justify-end">
            <button onclick="cerrarModal('modal-det-cred-web')"
                class="px-5 py-2 rounded-xl bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold transition shadow-md">
                Cerrar
            </button>
        </div>
    </div>
</div>

<!-- Modal Detalle Cuenta M365 -->
<div id="modal-det-m365"
    class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[100] flex items-center justify-center hidden p-4">
    <div
        class="bg-white border border-slate-200 rounded-3xl w-full max-w-lg overflow-hidden shadow-2xl animate-fade-in relative">
        <div class="p-6 border-b border-slate-100 bg-slate-50/80 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div
                    class="h-10 w-10 rounded-xl bg-cyan-500/10 border border-cyan-500/20 text-cyan-600 flex items-center justify-center text-lg">
                    <i class="fa-solid fa-cloud"></i>
                </div>
                <div>
                    <h3 class="font-bold text-slate-800 text-base" id="dm3_correo">Cuenta M365</h3>
                    <p class="text-xs text-slate-500" id="dm3_licencia">--</p>
                </div>
            </div>
            <button onclick="cerrarModal('modal-det-m365')"
                class="h-8 w-8 rounded-full hover:bg-slate-200 text-slate-400 hover:text-slate-700 flex items-center justify-center transition">
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
                        <span class="text-[11px] text-slate-400 block font-semibold">Empresa</span>
                        <span class="font-bold text-slate-800" id="dm3_empresa">--</span>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3 pt-2 border-t border-slate-200">
                    <div>
                        <span class="text-[11px] text-slate-400 block font-semibold">Cargo / Departamento</span>
                        <span class="font-bold text-slate-800" id="dm3_cargo">--</span>
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
                            <input type="password" readonly id="dm3_pass"
                                class="bg-white border border-slate-200 rounded-lg px-2 py-1 text-xs font-mono w-full text-slate-800">
                            <button type="button" onclick="toggleFieldPass('dm3_pass', 'dm3_pass_icon')"
                                class="text-slate-500 hover:text-slate-800 text-xs px-2 py-1 rounded bg-slate-200">
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

            <!-- Equipo Asignado -->
            <div id="dm3_sec_equipo" class="bg-indigo-50/50 p-4 rounded-2xl border border-indigo-200/60 space-y-2">
                <h4 class="text-xs font-bold text-indigo-800 uppercase tracking-wider flex items-center gap-1.5"
                    id="dm3_sec_equipo_title">
                    <i class="fa-solid fa-desktop text-indigo-600"></i> Equipo Asignado
                </h4>
                <div id="dm3_equipos_container" class="space-y-3">
                    <!-- Los equipos se renderizarán aquí de forma dinámica -->
                </div>
            </div>

            <!-- Accesos Adicionales (AG y Gmail) -->
            <div id="dm3_sec_adicionales" class="bg-cyan-50/50 p-4 rounded-2xl border border-cyan-200/60 space-y-3">
                <h4 class="text-xs font-bold text-cyan-800 uppercase tracking-wider flex items-center gap-1.5">
                    <i class="fa-solid fa-shield-halved text-cyan-600"></i> Credenciales Adicionales (AG y Gmail)
                </h4>

                <div>
                    <span class="text-[11px] text-slate-400 block font-semibold">Contraseña AG / Sistema</span>
                    <div class="flex items-center gap-2 mt-0.5">
                        <input type="password" readonly id="dm3_pass_ag"
                            class="bg-white border border-slate-200 rounded-lg px-2.5 py-1 text-xs font-mono w-full text-slate-800">
                        <button type="button" onclick="toggleFieldPass('dm3_pass_ag', 'dm3_pass_ag_icon')"
                            class="text-slate-500 hover:text-slate-800 text-xs px-2 py-1 rounded bg-slate-200 shrink-0">
                            <i class="fa-solid fa-eye" id="dm3_pass_ag_icon"></i>
                        </button>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-3 pt-2 border-t border-cyan-200/40">
                    <div>
                        <span class="text-[11px] text-slate-400 block font-semibold">Correo Gmail</span>
                        <span class="font-mono text-xs text-slate-800 font-bold block mt-1 break-all"
                            id="dm3_gmail_val">--</span>
                    </div>
                    <div>
                        <span class="text-[11px] text-slate-400 block font-semibold">Contraseña Gmail</span>
                        <div class="flex items-center gap-2 mt-0.5">
                            <input type="password" readonly id="dm3_pass_gmail"
                                class="bg-white border border-slate-200 rounded-lg px-2.5 py-1 text-xs font-mono w-full text-slate-800">
                            <button type="button" onclick="toggleFieldPass('dm3_pass_gmail', 'dm3_pass_gmail_icon')"
                                class="text-slate-500 hover:text-slate-800 text-xs px-2 py-1 rounded bg-slate-200 shrink-0">
                                <i class="fa-solid fa-eye" id="dm3_pass_gmail_icon"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="p-4 border-t border-slate-100 bg-slate-50 flex justify-end">
            <button onclick="cerrarModal('modal-det-m365')"
                class="px-5 py-2 rounded-xl bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold transition shadow-md">
                Cerrar
            </button>
        </div>
    </div>
</div>

<!-- Modal Editar Cuenta M365 y Credenciales Adicionales -->
<div id="modal-edit-m365"
    class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[100] flex items-center justify-center hidden p-4">
    <div
        class="bg-white border border-slate-200 rounded-3xl w-full max-w-xl overflow-hidden shadow-2xl animate-fade-in relative max-h-[90vh] flex flex-col">
        <!-- Header -->
        <div
            class="p-6 border-b border-slate-100 bg-gradient-to-r from-amber-50 to-white flex items-center justify-between shrink-0">
            <div class="flex items-center gap-3">
                <div
                    class="h-10 w-10 rounded-xl bg-amber-500/10 border border-amber-500/20 text-amber-600 flex items-center justify-center text-lg font-bold">
                    <i class="fa-solid fa-pen"></i>
                </div>
                <div>
                    <h3 class="font-bold text-slate-800 text-base">Editar Cuenta y Credenciales</h3>
                    <p class="text-xs text-slate-500" id="em3_subtitulo">--</p>
                </div>
            </div>
            <button onclick="cerrarModal('modal-edit-m365')"
                class="h-8 w-8 rounded-full hover:bg-slate-200 text-slate-400 hover:text-slate-700 flex items-center justify-center transition">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <!-- Form Body -->
        <form autocomplete="off" action="guardar.php?action=edit_m365" method="POST"
            class="p-6 space-y-4 text-sm overflow-y-auto flex-1">
            <input type="hidden" name="id" id="em3_id">
            <input type="hidden" name="fecha_inicializacion" id="em3_f_init">
            <input type="hidden" name="ciclo_facturacion" id="em3_ciclo">

            <!-- Datos de M365 -->
            <div class="bg-slate-50 p-4 rounded-2xl border border-slate-200 space-y-3">
                <h4 class="text-xs font-bold text-slate-600 uppercase tracking-wider flex items-center gap-1.5">
                    <i class="fa-solid fa-cloud text-slate-400"></i> Cuenta Microsoft 365
                </h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div>
                        <label class="text-xs font-semibold text-slate-600 block mb-1">Correo M365</label>
                        <input type="email" name="correo" id="em3_correo" required
                            class="w-full bg-white border border-slate-200 rounded-xl py-2 px-3 text-xs text-slate-800 focus:outline-none focus:border-amber-500 font-mono">
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-slate-600 block mb-1">Nueva Contraseña M365
                            (Opcional)</label>
                        <input type="password" name="password" id="em3_pass" placeholder="Dejar en blanco para mantener"
                            class="w-full bg-white border border-slate-200 rounded-xl py-2 px-3 text-xs text-slate-800 focus:outline-none focus:border-amber-500 font-mono">
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3 pt-2">
                    <div>
                        <label class="text-xs font-semibold text-slate-600 block mb-1">Tipo de Licencia</label>
                        <input type="text" name="licencia_tipo" id="em3_licencia" required
                            class="w-full bg-white border border-slate-200 rounded-xl py-2 px-3 text-xs text-slate-800 focus:outline-none focus:border-amber-500">
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-slate-600 block mb-1">Estado de la Cuenta</label>
                        <select autocomplete="off" name="estado" id="em3_estado" required
                            class="w-full bg-white border border-slate-200 rounded-xl py-2 px-3 text-xs text-slate-800 focus:outline-none focus:border-amber-500">
                            <option value="Activa">Activa</option>
                            <option value="Suspendida">Suspendida</option>
                            <option value="Eliminada">Eliminada</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Accesos Adicionales (AG y Gmail) -->
            <div class="bg-amber-50/50 p-4 rounded-2xl border border-amber-200/60 space-y-3">
                <h4 class="text-xs font-bold text-amber-700 uppercase tracking-wider flex items-center gap-1.5">
                    <i class="fa-solid fa-shield-halved text-amber-500"></i> Credenciales Adicionales (AG y Gmail)
                </h4>

                <div>
                    <label class="text-xs font-semibold text-slate-600 block mb-1">Contraseña AG / Sistema</label>
                    <input type="text" name="pass_ag" id="em3_pass_ag" placeholder="Ej: Vmaster#2026"
                        class="w-full bg-white border border-slate-200 rounded-xl py-2 px-3 text-xs font-mono text-slate-800 focus:outline-none focus:border-amber-500">
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-3 pt-2 border-t border-amber-200/40">
                    <div>
                        <label class="text-xs font-semibold text-slate-600 block mb-1">Correo Gmail</label>
                        <input type="text" name="correo_gmail" id="em3_correo_gmail" placeholder="Ej: usuario@gmail.com"
                            class="w-full bg-white border border-slate-200 rounded-xl py-2 px-3 text-xs font-mono text-slate-800 focus:outline-none focus:border-amber-500">
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-slate-600 block mb-1">Contraseña Gmail</label>
                        <input type="text" name="pass_gmail" id="em3_pass_gmail" placeholder="Ej: PassGmail#2026"
                            class="w-full bg-white border border-slate-200 rounded-xl py-2 px-3 text-xs font-mono text-slate-800 focus:outline-none focus:border-amber-500">
                    </div>
                </div>
            </div>

            <!-- Footer Buttons -->
            <div class="pt-4 border-t border-slate-100 flex justify-end gap-3 shrink-0">
                <button type="button" onclick="cerrarModal('modal-edit-m365')"
                    class="px-5 py-2.5 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-600 text-xs font-bold transition">
                    Cancelar
                </button>
                <button type="submit"
                    class="px-6 py-2.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-white text-xs font-bold transition shadow-md flex items-center gap-2">
                    <i class="fa-solid fa-check"></i> Guardar Cambios
                </button>
            </div>
        </form>
    </div>
</div>
</div>

<!-- Modal Eliminar Credencial/Cuenta -->
<div id="modal-eliminar-cred" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-[9999] flex items-center justify-center hidden p-4">
    <div class="bg-white border border-slate-200 rounded-3xl w-full max-w-sm overflow-hidden shadow-2xl animate-fade-in text-center p-8">
        <div class="h-16 w-16 bg-rose-100 text-rose-500 rounded-full flex items-center justify-center text-3xl mx-auto mb-4">
            <i class="fa-solid fa-triangle-exclamation"></i>
        </div>
        <h3 class="font-bold text-slate-800 text-xl mb-2">Eliminar Registro</h3>
        <p class="text-slate-500 text-sm mb-6" id="txt_eliminar_cred">¿Estás seguro que deseas eliminar este registro? Esta acción es irreversible.</p>
        <div class="flex gap-3 w-full">
            <button type="button" onclick="cerrarModal('modal-eliminar-cred')" class="flex-1 py-3 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold text-sm transition">Cancelar</button>
            <a id="btn_confirmar_eliminar_cred" href="#" class="flex-1 py-3 rounded-xl bg-rose-500 hover:bg-rose-600 text-white font-bold text-sm transition shadow-lg shadow-rose-500/30 flex items-center justify-center">Sí, eliminar</a>
        </div>
    </div>
</div>

<script>
    function confirmarEliminar(url, texto) {
        document.getElementById('btn_confirmar_eliminar_cred').href = url;
        document.getElementById('txt_eliminar_cred').innerText = `¿Seguro que deseas eliminar ${texto}? Esta acción es irreversible y los datos se perderán.`;
        abrirModal('modal-eliminar-cred');
    }

    let currentEquiposLista = [];

    function verDetalleCredEquipo(ce) {
        currentEquiposLista = ce.equipos_lista || [ce];
        const colaborador = ce.colaborador_nombre ? ce.colaborador_nombre : 'Sin Asignar';
        const area = ce.area_nombre ? ce.area_nombre : 'Sin Área';
        document.getElementById('dce_subtitulo').innerText = colaborador + ' · ' + area;

        const tabsContainer = document.getElementById('dce_tabs_container');
        const tabsList = document.getElementById('dce_tabs_list');
        tabsList.innerHTML = '';

        if (currentEquiposLista.length > 1) {
            tabsContainer.classList.remove('hidden');
            currentEquiposLista.forEach((eq, idx) => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = `px-3 py-1.5 rounded-xl text-xs font-bold transition ${idx === 0 ? 'bg-cyan-500 text-white shadow-md' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'}`;
                btn.innerText = `Equipo ${idx + 1}: ${eq.tipo} ${eq.marca} ${eq.modelo}`;
                btn.onclick = () => {
                    Array.from(tabsList.children).forEach((b, bIdx) => {
                        b.className = `px-3 py-1.5 rounded-xl text-xs font-bold transition ${bIdx === idx ? 'bg-cyan-500 text-white shadow-md' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'}`;
                    });
                    mostrarDetalleEquipoItem(eq);
                };
                tabsList.appendChild(btn);
            });
        } else {
            tabsContainer.classList.add('hidden');
        }

        mostrarDetalleEquipoItem(currentEquiposLista[0]);
        abrirModal('modal-det-cred-equipo');
    }

    function mostrarDetalleEquipoItem(eq) {
        let tipo = eq.tipo || 'Equipo';
        let marca = (eq.marca && eq.marca.trim() !== '') ? eq.marca : '';
        let modelo = (eq.modelo && eq.modelo.trim() !== '') ? eq.modelo : '';
        document.getElementById('dce_titulo').innerText = (tipo + ' ' + marca + ' ' + modelo).trim();

        let displaySn = eq.service_tag_sn || '';
        if (displaySn.indexOf('SN-FILA-') === 0) displaySn = '';

        document.getElementById('dce_sn').innerText = displaySn !== '' ? displaySn : '-';
        document.getElementById('dce_cpu').innerText = (eq.procesador && eq.procesador.trim() !== '') ? eq.procesador : '-';
        document.getElementById('dce_ram').innerText = (eq.ram && eq.ram.trim() !== '') ? eq.ram : '-';
        document.getElementById('dce_disco').innerText = (eq.disco && eq.disco.trim() !== '') ? eq.disco : '-';

        document.getElementById('dce_usr').innerText = (eq.usuario_local && eq.usuario_local.trim() !== '') ? eq.usuario_local : '-';
        document.getElementById('dce_pass').value = eq.pass_plain || '';
        document.getElementById('dce_pass').type = 'password';
        document.getElementById('dce_pass_icon').className = 'fa-solid fa-eye';

        const rowBit = document.getElementById('dce_row_bit');
        if (eq.bitlocker_plain && eq.bitlocker_plain.trim() !== '') {
            rowBit.classList.remove('hidden');
            document.getElementById('dce_bit').innerText = eq.bitlocker_plain;
        } else {
            rowBit.classList.add('hidden');
        }

        document.getElementById('dce_notas').innerText = (eq.notas_acceso && eq.notas_acceso.trim() !== '') ? eq.notas_acceso : '-';
    }

    function abrirEditarCredEquipo(ce) {
        document.getElementById('ece_id').value = ce.id || '';
        document.getElementById('ece_equipo_id').value = ce.equipo_id || '';
        document.getElementById('ece_colaborador').innerText = (ce.colaborador_nombre || 'Sin Asignar') + ' · ' + (ce.area_nombre || 'Sin Área');
        document.getElementById('ece_usr').value = ce.usuario_local || '';
        document.getElementById('ece_pass').value = '';
        document.getElementById('ece_bitlocker').value = ce.bitlocker_plain || ce.clave_bitlocker || '';
        document.getElementById('ece_notas').value = ce.notas_acceso || '';
        document.getElementById('ece_marca').value = ce.marca || '';
        document.getElementById('ece_modelo').value = ce.modelo || '';
        document.getElementById('ece_serie').value = ce.service_tag_sn || '';
        document.getElementById('ece_cpu').value = ce.procesador || '';
        document.getElementById('ece_disco').value = ce.disco || '';
        document.getElementById('ece_ram').value = ce.ram || '';
        document.getElementById('ece_ip').value = ce.ip_direccion || '';

        let selectedColab = ce.empleado_id || '';
        if (ce.colaborador_cargo === 'Sucursal' && ce.colaborador_sucursal_id) {
            selectedColab = 'S_' + ce.colaborador_sucursal_id;
        } else if (ce.colaborador_cargo === 'Empresa' && ce.colaborador_empresa_id) {
            selectedColab = 'E_' + ce.colaborador_empresa_id;
        }
        applyDynamicFields(ce.tipo, 'edit', selectedColab);

        abrirModal('modal-edit-cred-equipo');
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
        document.getElementById('dm3_emp').innerText = m3.empleado_nombre || 'Sin Asignar';
        document.getElementById('dm3_empresa').innerText = m3.empresa_nombre || 'Mastertec';
        document.getElementById('dm3_cargo').innerText = m3.empleado_cargo || m3.empleado_depto || '-';
        document.getElementById('dm3_est').innerText = m3.estado || 'Activa';
        document.getElementById('dm3_ciclo').innerText = m3.ciclo_facturacion || 'Mensual';
        document.getElementById('dm3_pass').value = m3.pass_plain || '';
        document.getElementById('dm3_pass').type = 'password';
        document.getElementById('dm3_pass_icon').className = 'fa-solid fa-eye';
        document.getElementById('dm3_f_init').innerText = m3.fecha_inicializacion || '--';
        document.getElementById('dm3_f_pago').innerText = m3.fecha_proximo_pago || '--';

        // Credenciales Adicionales (Pass AG, Correo Gmail, Pass Gmail)
        document.getElementById('dm3_pass_ag').value = m3.pass_ag_plain || '';
        document.getElementById('dm3_pass_ag').type = 'password';
        document.getElementById('dm3_pass_ag_icon').className = 'fa-solid fa-eye';

        document.getElementById('dm3_gmail_val').innerText = (m3.correo_gmail && m3.correo_gmail.trim() !== '') ? m3.correo_gmail : '-';
        document.getElementById('dm3_pass_gmail').value = m3.pass_gmail_plain || '';
        document.getElementById('dm3_pass_gmail').type = 'password';
        document.getElementById('dm3_pass_gmail_icon').className = 'fa-solid fa-eye';

        // Equipos asignados
        const secEquipo = document.getElementById('dm3_sec_equipo');
        const container = document.getElementById('dm3_equipos_container');
        const titleEl = document.getElementById('dm3_sec_equipo_title');
        container.innerHTML = '';

        const equipos = m3.equipos_asignados || [];
        if (equipos.length > 0) {
            titleEl.innerHTML = `<i class="fa-solid fa-desktop text-indigo-600"></i> ${equipos.length > 1 ? 'Equipos Asignados' : 'Equipo Asignado'}`;

            equipos.forEach(eq => {
                const tipo = (eq.tipo || '').toLowerCase();
                let iconClass = 'fa-solid fa-desktop';
                if (tipo.includes('laptop')) iconClass = 'fa-solid fa-laptop';
                else if (tipo.includes('impresora')) iconClass = 'fa-solid fa-print';
                else if (tipo.includes('servidor')) iconClass = 'fa-solid fa-server';

                const desc = `[${eq.tipo || ''}] ${eq.marca || ''} ${eq.modelo || ''}`.trim();
                const snText = eq.sn ? `S/N: ${eq.sn}` : '';

                const eqDiv = document.createElement('div');
                eqDiv.className = 'flex items-center gap-3 border-b border-indigo-100/40 pb-2 last:border-0 last:pb-0';
                eqDiv.innerHTML = `
                    <div class="h-9 w-9 rounded-xl bg-indigo-100 border border-indigo-200 text-indigo-600 flex items-center justify-center text-sm shrink-0">
                        <i class="${iconClass}"></i>
                    </div>
                    <div>
                        <span class="font-bold text-slate-800 text-sm block">${escapeHtml(desc)}</span>
                        <span class="text-[11px] text-slate-500 font-mono">${escapeHtml(snText)}</span>
                    </div>
                `;
                container.appendChild(eqDiv);
            });
            secEquipo.classList.remove('hidden');
        } else {
            titleEl.innerHTML = `<i class="fa-solid fa-desktop text-indigo-600"></i> Equipo Asignado`;
            container.innerHTML = `
                <div class="flex items-center gap-3">
                    <div class="h-9 w-9 rounded-xl bg-indigo-100 border border-indigo-200 text-indigo-600 flex items-center justify-center text-sm shrink-0">
                        <i class="fa-solid fa-desktop"></i>
                    </div>
                    <div>
                        <span class="font-bold text-slate-800 text-sm block">Sin equipo asignado</span>
                    </div>
                </div>
            `;
            secEquipo.classList.remove('hidden');
        }

        abrirModal('modal-det-m365');
    }

    function abrirEditarM365(m3) {
        document.getElementById('em3_id').value = m3.id || '';
        document.getElementById('em3_subtitulo').innerText = (m3.empleado_nombre || 'Cuenta Compartida') + ' · ' + (m3.correo || '');
        document.getElementById('em3_correo').value = m3.correo || '';
        document.getElementById('em3_pass').value = '';
        document.getElementById('em3_licencia').value = m3.licencia_tipo || '';
        document.getElementById('em3_estado').value = m3.estado || 'Activa';
        document.getElementById('em3_f_init').value = m3.fecha_inicializacion || '2026-01-27';
        document.getElementById('em3_ciclo').value = m3.ciclo_facturacion || 'Mensual';

        document.getElementById('em3_pass_ag').value = m3.pass_ag_plain || '';
        document.getElementById('em3_correo_gmail').value = m3.correo_gmail || '';
        document.getElementById('em3_pass_gmail').value = m3.pass_gmail_plain || '';

        abrirModal('modal-edit-m365');
    }

    const sucursalesLista = <?= json_encode($sucursales, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    const empleadosLista = <?= json_encode($empleados, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    const empresasLista = <?= json_encode($empresas_list, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

    function applyDynamicFields(tipo, mode, selectedColab = '') {
        const isSpecialType = ['impresora', 'control de acceso', 'ap', 'switch', 'servidor'].includes(tipo.toLowerCase());
        const isIpType = isSpecialType;
        const isNoSpecs = ['impresora', 'control de acceso', 'ap', 'switch'].includes(tipo.toLowerCase());

        let colabMode = 'normal';
        if (['impresora', 'control de acceso', 'ap', 'switch', 'servidor'].includes(tipo.toLowerCase())) {
            colabMode = 'sucursales';
        }

        if (mode === 'new') {
            // Hide/Show hardware specs
            const hwSec = document.getElementById('sec_nuevo_eq_hardware');
            if (hwSec) {
                if (isNoSpecs) hwSec.classList.add('hidden');
                else hwSec.classList.remove('hidden');
            }

            // Hide/Show credentials
            const credSec1 = document.getElementById('sec_nuevo_eq_credenciales_1');
            const credSec2 = document.getElementById('sec_nuevo_eq_credenciales_2');
            const credSec3 = document.getElementById('sec_nuevo_eq_credenciales_3');
            [credSec1, credSec2, credSec3].forEach(el => {
                if (el) {
                    if (isSpecialType) el.classList.add('hidden');
                    else el.classList.remove('hidden');
                }
            });

            // Set required false on credentials
            const usrInp = document.getElementById('eq_usr');
            const passInp = document.getElementById('eq_pass');
            if (usrInp) usrInp.required = !isSpecialType;
            if (passInp) passInp.required = !isSpecialType;

            // Hide/Show IP field
            const ipSec = document.getElementById('sec_nuevo_eq_ip');
            if (ipSec) {
                if (isIpType) ipSec.classList.remove('hidden');
                else ipSec.classList.add('hidden');
            }

            // Load Colaboradores dropdown
            actualizarColaboradorSelect('nuevo_eq_empleado_id', colabMode, selectedColab);
        } else if (mode === 'edit') {
            // Hide/Show hardware specs
            const hwSec = document.getElementById('sec_edit_eq_hardware');
            if (hwSec) {
                if (isNoSpecs) hwSec.classList.add('hidden');
                else hwSec.classList.remove('hidden');
            }

            // Hide/Show credentials
            const credSec = document.getElementById('sec_edit_eq_credenciales');
            if (credSec) {
                if (isSpecialType) credSec.classList.add('hidden');
                else credSec.classList.remove('hidden');
            }

            // Set required false on credentials
            const usrInp = document.getElementById('ece_usr');
            if (usrInp) usrInp.required = !isSpecialType;

            // Hide/Show IP field
            const ipSec = document.getElementById('sec_edit_eq_ip');
            if (ipSec) {
                if (isIpType) ipSec.classList.remove('hidden');
                else ipSec.classList.add('hidden');
            }

            // Load Colaboradores dropdown
            actualizarColaboradorSelect('ece_empleado_id', colabMode, selectedColab);
        }
    }

    function actualizarColaboradorSelect(selectId, colabMode, selectedValue = '') {
        const select = document.getElementById(selectId);
        if (!select) return;
        select.innerHTML = '';

        const defOpt = document.createElement('option');
        defOpt.value = '';
        if (colabMode === 'sucursales') {
            defOpt.innerText = '-- Sin Asignar Sucursal --';
        } else if (colabMode === 'empresas') {
            defOpt.innerText = '-- Sin Asignar Empresa --';
        } else {
            defOpt.innerText = '-- Sin Asignar / Desasignar Colaborador (Liberar Equipo) --';
        }
        select.appendChild(defOpt);

        if (colabMode === 'sucursales') {
            sucursalesLista.forEach(suc => {
                const opt = document.createElement('option');
                opt.value = 'S_' + suc.id;
                opt.innerText = suc.nombre;
                if (selectedValue === 'S_' + suc.id || selectedValue == suc.id) {
                    opt.selected = true;
                }
                select.appendChild(opt);
            });
        } else if (colabMode === 'empresas') {
            empresasLista.forEach(emp => {
                const opt = document.createElement('option');
                opt.value = 'E_' + emp.id;
                opt.innerText = emp.nombre;
                if (selectedValue === 'E_' + emp.id || selectedValue == emp.id) {
                    opt.selected = true;
                }
                select.appendChild(opt);
            });
        } else {
            empleadosLista.forEach(emp => {
                const opt = document.createElement('option');
                opt.value = emp.id;
                opt.innerText = emp.nombre + (emp.cargo ? ' (' + emp.cargo + ')' : '');
                if (selectedValue == emp.id) {
                    opt.selected = true;
                }
                select.appendChild(opt);
            });
        }
    }

    function onNuevoEqTipoChange(selectEl) {
        toggleOtroTipo(selectEl, 'nuevo_eq_tipo_otro_container');
        applyDynamicFields(selectEl.value, 'new');
    }

    function abrirNuevaCredencialEquipo() {
        const selectEl = document.getElementById('nuevo_eq_tipo');
        if (selectEl) {
            selectEl.value = 'Laptop';
            onNuevoEqTipoChange(selectEl);
        }
        abrirModal('modal-equipo');
    }

    const usuariosAutocompleteLista = <?= json_encode($usuarios_list_js, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

    function mostrarCustomUserDropdown() {
        filtrarCustomUserDropdown(document.getElementById('eq_usr').value);
    }

    function filtrarCustomUserDropdown(query) {
        const dd = document.getElementById('custom_user_dropdown');
        if (!dd) return;

        const q = normStr(query || '');
        const filtrados = usuariosAutocompleteLista.filter(u => normStr(u.nombre).includes(q) || normStr(u.sub || '').includes(q));

        if (filtrados.length === 0) {
            dd.innerHTML = `<div class="p-3 text-xs text-slate-400 italic text-center">Sin coincidencias</div>`;
            dd.classList.remove('hidden');
            return;
        }

        let html = '';
        filtrados.slice(0, 35).forEach(u => {
            const escNombre = u.nombre.replace(/'/g, "\\'");
            html += `
                <div onclick="seleccionarCustomUser('${escNombre}')" class="px-3.5 py-2.5 hover:bg-cyan-50/90 cursor-pointer flex items-center justify-between transition group">
                    <div class="flex items-center gap-2.5">
                        <div class="h-6 w-6 rounded-full bg-slate-100 text-slate-500 group-hover:bg-cyan-100 group-hover:text-cyan-700 flex items-center justify-center text-[10px] font-bold shrink-0 transition">
                            <i class="fa-regular fa-user"></i>
                        </div>
                        <span class="text-xs font-semibold text-slate-800 group-hover:text-cyan-900">${u.nombre}</span>
                    </div>
                    ${u.sub ? `<span class="text-[10px] px-2 py-0.5 rounded bg-slate-100 group-hover:bg-cyan-100/60 text-slate-500 group-hover:text-cyan-800 font-medium">${u.sub}</span>` : ''}
                </div>
            `;
        });

        dd.innerHTML = html;
        dd.classList.remove('hidden');
    }

    function seleccionarCustomUser(nombre) {
        document.getElementById('eq_usr').value = nombre;
        const dd = document.getElementById('custom_user_dropdown');
        if (dd) dd.classList.add('hidden');
    }

    document.addEventListener('click', function (e) {
        const usrInput = document.getElementById('eq_usr');
        const dd = document.getElementById('custom_user_dropdown');
        if (dd && usrInput && !usrInput.contains(e.target) && !dd.contains(e.target)) {
            dd.classList.add('hidden');
        }
    });

    function checkNuevoEquipoCampos(selectEl) {
        const sec = document.getElementById('sec_nuevo_equipo');
        if (!sec) return;
        if (selectEl.value === 'NEW_EQUIPO') {
            sec.classList.remove('hidden');
        } else {
            sec.classList.add('hidden');
        }
    }

    function checkNuevoEmpleado(selectEl, targetDivId) {
        const target = document.getElementById(targetDivId);
        if (!target) return;
        if (selectEl.value === 'NEW_EMP') {
            target.classList.remove('hidden');
            const inp = target.querySelector('input');
            if (inp) inp.focus();
        } else {
            target.classList.add('hidden');
        }
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
        const fcLink = document.getElementById('fc_link_emp');
        if (fcLink) {
            fcLink.href = '../empleados/index.php?details_id=' + f.id;
        }

        // Equipos asignados
        const containerEq = document.getElementById('fc_equipos_container');
        containerEq.innerHTML = '';
        const equipos = f.equipos || [];

        if (equipos.length > 0) {
            equipos.forEach((eq, index) => {
                const passId = `fc_eq_pass_${index}`;
                const iconId = `fc_eq_pass_ico_${index}`;
                const isBitlocker = eq.eq_bitlocker_plain && eq.eq_bitlocker_plain.trim() !== '';

                const eqDiv = document.createElement('div');
                eqDiv.className = 'border border-cyan-200 rounded-2xl overflow-hidden';
                eqDiv.innerHTML = `
                    <div class="bg-cyan-50 px-4 py-2.5 flex items-center gap-2 border-b border-cyan-200">
                        <i class="fa-solid fa-laptop text-cyan-600 text-sm"></i>
                        <span class="text-xs font-bold text-cyan-700 uppercase tracking-wider">Equipo Asignado ${equipos.length > 1 ? `#${index + 1}` : ''}</span>
                    </div>
                    <div class="p-4 grid grid-cols-2 md:grid-cols-3 gap-3 text-xs bg-white">
                        <div>
                            <span class="text-[11px] text-slate-400 block font-semibold">Tipo / Marca / Modelo</span>
                            <span class="font-bold text-slate-800">${escapeHtml((eq.eq_tipo || '') + ' ' + (eq.eq_marca || '') + ' ' + (eq.eq_modelo || ''))}</span>
                        </div>
                        <div>
                            <span class="text-[11px] text-slate-400 block font-semibold">Serie / S/N</span>
                            <span class="font-mono font-bold text-slate-700 select-all">${escapeHtml(eq.service_tag_sn || 'S/N no registrado')}</span>
                        </div>
                        <div>
                            <span class="text-[11px] text-slate-400 block font-semibold">Dirección IP</span>
                            <span class="font-mono font-bold text-slate-700">${escapeHtml(eq.ip_direccion || 'Sin IP')}</span>
                        </div>
                        <div>
                            <span class="text-[11px] text-slate-400 block font-semibold">Procesador</span>
                            <span class="font-medium text-slate-600">${escapeHtml(eq.procesador || '--')}</span>
                        </div>
                        <div>
                            <span class="text-[11px] text-slate-400 block font-semibold">RAM / Disco</span>
                            <span class="font-medium text-slate-600">${escapeHtml((eq.ram || '--') + ' RAM / ' + (eq.disco || '--'))}</span>
                        </div>
                        <div></div>
                        <div>
                            <span class="text-[11px] text-slate-400 block font-semibold">Usuario Local</span>
                            <span class="font-mono font-bold text-slate-800">${escapeHtml(eq.usuario_local || 'Sin usuario local')}</span>
                        </div>
                        <div class="col-span-2">
                            <span class="text-[11px] text-slate-400 block font-semibold mb-1">Contraseña Local</span>
                            <div class="flex gap-2">
                                <input type="password" readonly id="${passId}" value="${escapeHtml(eq.eq_pass_plain || '')}" class="bg-slate-100 border border-slate-200 rounded-lg px-2 py-1 text-xs font-mono flex-1 text-slate-800">
                                <button type="button" onclick="toggleFieldPass('${passId}','${iconId}')" class="px-2 py-1 rounded bg-slate-200 text-slate-500 hover:text-slate-800 text-xs">
                                    <i class="fa-solid fa-eye" id="${iconId}"></i>
                                </button>
                            </div>
                        </div>
                        <div class="col-span-3 ${isBitlocker ? '' : 'hidden'}">
                            <span class="text-[11px] text-slate-400 block font-semibold">Clave BitLocker</span>
                            <span class="font-mono font-bold text-slate-800 text-xs select-all bg-slate-100 px-2 py-1 rounded mt-0.5 inline-block">${escapeHtml(eq.eq_bitlocker_plain || '')}</span>
                        </div>
                    </div>
                `;
                containerEq.appendChild(eqDiv);
            });
        } else {
            const emptyDiv = document.createElement('div');
            emptyDiv.className = 'border border-cyan-100 rounded-2xl p-4 text-center bg-slate-50/50';
            emptyDiv.innerHTML = `<span class="text-slate-400 italic text-xs">Sin equipo asignado</span>`;
            containerEq.appendChild(emptyDiv);
        }

        // M365
        const bloqueM365 = document.getElementById('fc_bloque_m365');
        if (f.m365_correo) {
            bloqueM365.classList.remove('hidden');
            document.getElementById('fc_m365_correo').innerText = f.m365_correo || '--';
            document.getElementById('fc_m365_lic').innerText = f.licencia_tipo || '--';
            document.getElementById('fc_m365_ciclo').innerText = (f.ciclo_facturacion || '--') + ' · Próximo pago: ' + (f.fecha_proximo_pago || '--');
            document.getElementById('fc_m365_estado').innerText = f.m365_estado || '';
            document.getElementById('fc_m365_pass').value = f.m365_pass_plain || '';
            document.getElementById('fc_m365_pass').type = 'password';
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

    function toggleOtroTipo(select, containerId) {
        const container = document.getElementById(containerId);
        if (container) {
            if (select.value === '__otro__') {
                container.classList.remove('hidden');
                const input = container.querySelector('input');
                if (input) {
                    input.required = true;
                    input.focus();
                }
            } else {
                container.classList.add('hidden');
                const input = container.querySelector('input');
                if (input) {
                    input.required = false;
                }
            }
        }
    }
</script>


<!-- Modal Detalle Gmail / Credenciales Colaborador -->
<div id="modal-det-gmail"
    class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[100] flex items-center justify-center hidden p-4">
    <div
        class="bg-white border border-slate-200 rounded-3xl w-full max-w-lg overflow-hidden shadow-2xl animate-fade-in relative">
        <!-- Header -->
        <div
            class="p-6 border-b border-slate-100 flex items-center justify-between bg-gradient-to-r from-red-50 to-white">
            <div class="flex items-center gap-3">
                <div class="h-11 w-11 rounded-2xl bg-red-100 border border-red-200 text-red-700 flex items-center justify-center text-lg shrink-0 font-extrabold"
                    id="dg_initials">C</div>
                <div>
                    <h3 class="font-bold text-slate-800 text-base" id="dg_nombre">Colaborador</h3>
                    <p class="text-xs text-slate-500" id="dg_cargo">Cargo · Departamento · Sucursal</p>
                </div>
            </div>
            <button onclick="cerrarModal('modal-det-gmail')"
                class="h-8 w-8 rounded-full hover:bg-slate-200 text-slate-400 hover:text-slate-700 flex items-center justify-center transition">
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
            <button onclick="cerrarModal('modal-det-gmail')"
                class="px-5 py-2 rounded-xl bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold transition shadow-md">
                Cerrar
            </button>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/../../includes/footer.php';
?>