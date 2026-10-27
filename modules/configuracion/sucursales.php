<?php
/**
 * Módulo de Gestión de Sedes, Sucursales y Empresas
 * Catálogo Maestro del Sistema
 */

require_once __DIR__ . '/../../includes/header.php';

$db = Database::getConexion();

// 1. Cargar Empresas
$empresas = $db->query("SELECT * FROM empresas ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);

// 2. Cargar Sucursales con conteos de colaboradores y equipos
$sql_sucursales = "
    SELECT 
        s.*,
        e.nombre AS empresa_nombre,
        (SELECT COUNT(*) FROM empleados WHERE sucursal_id = s.id AND estado = 'Activo') AS cant_colaboradores,
        (SELECT COUNT(*) FROM equipos WHERE sucursal_id = s.id AND estado != 'De Baja') AS cant_equipos,
        (SELECT COUNT(*) FROM infraestructura_red WHERE sucursal_id = s.id) AS cant_infra
    FROM sucursales s
    JOIN empresas e ON s.empresa_id = e.id
    ORDER BY e.nombre ASC, s.nombre ASC
";
$sucursales = $db->query($sql_sucursales)->fetchAll(PDO::FETCH_ASSOC);

// KPIs globales
$total_sucursales = count($sucursales);
$total_empresas = count($empresas);
$total_colaboradores = array_sum(array_column($sucursales, 'cant_colaboradores'));
$total_equipos = array_sum(array_column($sucursales, 'cant_equipos'));

// Detección de banderas por sede o país
function obtenerBanderaSede($sucursalNombre, $ubicacion) {
    $texto = mb_strtolower($sucursalNombre . ' ' . $ubicacion, 'UTF-8');
    if (strpos($texto, 'honduras') !== false) return ['flag' => '🇭🇳', 'pais' => 'Honduras'];
    if (strpos($texto, 'salvador') !== false) return ['flag' => '🇸🇻', 'pais' => 'El Salvador'];
    if (strpos($texto, 'dominicana') !== false) return ['flag' => '🇩🇴', 'pais' => 'Rep. Dominicana'];
    return ['flag' => '🇳🇮', 'pais' => 'Nicaragua'];
}
?>

<div class="space-y-8 pb-16">

    <!-- Encabezado de Página -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <div class="flex items-center gap-2">
                <span class="text-xs font-bold uppercase tracking-widest text-emerald-600 bg-emerald-50 px-2.5 py-0.5 rounded-full border border-emerald-200">Organización</span>
            </div>
            <h2 class="text-2xl font-bold text-slate-800 tracking-wide mt-1 flex items-center gap-2.5">
                <i class="fa-solid fa-building-user text-emerald-500"></i>
                <span>Sedes & Sucursales</span>
            </h2>
            <p class="text-slate-500 text-sm mt-0.5">Catálogo maestro de empresas, oficinas corporativas y sedes regionales.</p>
        </div>

        <!-- Botones de Acción -->
        <div class="flex items-center gap-2.5 flex-wrap">
            <button type="button" onclick="abrirModal('modal-nueva-empresa')"
                class="px-3.5 py-2.5 rounded-xl border border-slate-200 bg-white hover:bg-slate-50 text-slate-700 font-bold text-xs shadow-sm transition flex items-center gap-2">
                <i class="fa-solid fa-plus text-slate-400"></i> Nueva Empresa
            </button>
            <button type="button" onclick="abrirModal('modal-nueva-sucursal')"
                class="px-4 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs tracking-wide shadow-lg shadow-emerald-600/20 flex items-center gap-2 transition">
                <i class="fa-solid fa-plus"></i> Nueva Sucursal
            </button>
        </div>
    </div>

    <!-- Mensajes Flash -->
    <?php if (!empty($_SESSION['flash_success'])): ?>
        <div class="p-4 rounded-2xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-xs font-semibold flex items-center justify-between animate-fade-in">
            <div class="flex items-center gap-2">
                <i class="fa-solid fa-circle-check text-emerald-600 text-base"></i>
                <span><?= htmlspecialchars($_SESSION['flash_success']) ?></span>
            </div>
            <button onclick="this.parentElement.remove()" class="text-emerald-500 hover:text-emerald-800"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <?php unset($_SESSION['flash_success']); ?>
    <?php endif; ?>

    <?php if (!empty($_SESSION['flash_error'])): ?>
        <div class="p-4 rounded-2xl bg-rose-50 border border-rose-200 text-rose-800 text-xs font-semibold flex items-center justify-between animate-fade-in">
            <div class="flex items-center gap-2">
                <i class="fa-solid fa-triangle-exclamation text-rose-600 text-base"></i>
                <span><?= htmlspecialchars($_SESSION['flash_error']) ?></span>
            </div>
            <button onclick="this.parentElement.remove()" class="text-rose-500 hover:text-rose-800"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <?php unset($_SESSION['flash_error']); ?>
    <?php endif; ?>

    <!-- KPIs Dinámicos -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="bg-white p-5 rounded-3xl border border-slate-200/90 shadow-sm flex items-center gap-3.5">
            <div class="h-12 w-12 rounded-2xl bg-emerald-50 border border-emerald-200 text-emerald-600 flex items-center justify-center text-xl shrink-0">
                <i class="fa-solid fa-building"></i>
            </div>
            <div>
                <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider block">Total Sucursales</span>
                <span class="text-2xl font-extrabold text-slate-800"><?= $total_sucursales ?></span>
                <span class="text-[11px] text-slate-500 block">Sedes operativas</span>
            </div>
        </div>

        <div class="bg-white p-5 rounded-3xl border border-slate-200/90 shadow-sm flex items-center gap-3.5">
            <div class="h-12 w-12 rounded-2xl bg-indigo-50 border border-indigo-200 text-indigo-600 flex items-center justify-center text-xl shrink-0">
                <i class="fa-solid fa-briefcase"></i>
            </div>
            <div>
                <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider block">Empresas / Grupo</span>
                <span class="text-2xl font-extrabold text-indigo-600"><?= $total_empresas ?></span>
                <span class="text-[11px] text-slate-500 block">Razones sociales</span>
            </div>
        </div>

        <div class="bg-white p-5 rounded-3xl border border-slate-200/90 shadow-sm flex items-center gap-3.5">
            <div class="h-12 w-12 rounded-2xl bg-violet-50 border border-violet-200 text-violet-600 flex items-center justify-center text-xl shrink-0">
                <i class="fa-solid fa-users"></i>
            </div>
            <div>
                <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider block">Colaboradores</span>
                <span class="text-2xl font-extrabold text-violet-700"><?= $total_colaboradores ?></span>
                <span class="text-[11px] text-slate-500 block">En todas las sedes</span>
            </div>
        </div>

        <div class="bg-white p-5 rounded-3xl border border-slate-200/90 shadow-sm flex items-center gap-3.5">
            <div class="h-12 w-12 rounded-2xl bg-cyan-50 border border-cyan-200 text-cyan-600 flex items-center justify-center text-xl shrink-0">
                <i class="fa-solid fa-laptop"></i>
            </div>
            <div>
                <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider block">Equipos Registrados</span>
                <span class="text-2xl font-extrabold text-cyan-700"><?= $total_equipos ?></span>
                <span class="text-[11px] text-slate-500 block">Hardware en sedes</span>
            </div>
        </div>
    </div>

    <!-- Barra de Filtros y Búsqueda -->
    <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm flex flex-col md:flex-row gap-3 items-center justify-between">
        <div class="flex-1 w-full relative flex items-center">
            <i class="fa-solid fa-magnifying-glass absolute left-4 text-slate-400 pointer-events-none text-xs"></i>
            <input type="text" id="filtro-busqueda-suc" oninput="filtrarSucursales()"
                placeholder="Buscar por nombre de sucursal, empresa, país o ubicación..."
                class="w-full bg-slate-50 border border-slate-200 rounded-xl py-2 pl-10 pr-4 text-xs text-slate-800 focus:outline-none focus:border-emerald-500 transition">
        </div>

        <!-- Filtro Rápido por Empresa -->
        <div class="flex items-center gap-2 overflow-x-auto w-full md:w-auto pb-1 md:pb-0 scrollbar-none">
            <button type="button" onclick="filtrarPorEmpresa('', this)" class="btn-filtro-emp px-3 py-1.5 rounded-xl text-xs font-bold transition bg-slate-800 text-white shadow-sm shrink-0">
                Todas (<?= $total_sucursales ?>)
            </button>
            <?php foreach ($empresas as $emp): 
                $cant_emp_sucs = count(array_filter($sucursales, fn($s) => $s['empresa_id'] == $emp['id']));
            ?>
                <button type="button" onclick="filtrarPorEmpresa('<?= htmlspecialchars($emp['nombre']) ?>', this)" 
                    class="btn-filtro-emp px-3 py-1.5 rounded-xl text-xs font-semibold bg-white border border-slate-200 text-slate-600 hover:bg-slate-50 transition shadow-xs shrink-0 flex items-center gap-1.5">
                    <span><?= htmlspecialchars($emp['nombre']) ?></span>
                    <span class="text-[10px] bg-slate-100 text-slate-600 px-1.5 py-0.2 rounded-full font-bold font-mono"><?= $cant_emp_sucs ?></span>
                </button>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Tabla Principal de Sucursales -->
    <div class="bg-white border border-slate-200 rounded-3xl overflow-hidden shadow-sm">
        <div class="overflow-x-auto">
            <table class="w-full text-left modern-table text-sm">
                <thead>
                    <tr class="bg-slate-50/80 border-b border-slate-200 text-slate-600 text-xs font-bold uppercase tracking-wider">
                        <th class="py-3.5 px-4 text-center w-12">#</th>
                        <th class="py-3.5 px-4">Empresa</th>
                        <th class="py-3.5 px-4">Sucursal / Sede</th>
                        <th class="py-3.5 px-4">Ubicación Física</th>
                        <th class="py-3.5 px-4 text-center">Colaboradores</th>
                        <th class="py-3.5 px-4 text-center">Hardware</th>
                        <th class="py-3.5 px-4 text-center w-28">Acciones</th>
                    </tr>
                </thead>
                <tbody id="tabla-sucursales-body">
                    <?php if (empty($sucursales)): ?>
                        <tr>
                            <td colspan="7" class="py-12 text-center text-slate-400">
                                <i class="fa-solid fa-building-circle-xmark text-4xl mb-2 text-slate-300"></i>
                                <p class="text-sm font-semibold">No hay sucursales registradas aún.</p>
                                <button onclick="abrirModal('modal-nueva-sucursal')" class="mt-3 text-xs text-emerald-600 font-bold hover:underline">+ Registrar Primera Sucursal</button>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php 
                        $num = 1;
                        foreach ($sucursales as $s): 
                            $badgeEmpClass = 'bg-slate-100 text-slate-700 border-slate-200';
                            if (stripos($s['empresa_nombre'], 'Mastertec') !== false) {
                                $badgeEmpClass = 'bg-indigo-50 text-indigo-700 border-indigo-200';
                            } elseif (stripos($s['empresa_nombre'], 'Suministros') !== false) {
                                $badgeEmpClass = 'bg-emerald-50 text-emerald-700 border-emerald-200';
                            } elseif (stripos($s['empresa_nombre'], 'Centro') !== false) {
                                $badgeEmpClass = 'bg-amber-50 text-amber-700 border-amber-200';
                            }

                            $bandera = obtenerBanderaSede($s['nombre'], $s['ubicacion'] ?? '');
                            $search_str = mb_strtolower("{$s['nombre']} {$s['empresa_nombre']} {$s['ubicacion']} {$bandera['pais']}", 'UTF-8');
                        ?>
                            <tr class="fila-sucursal hover:bg-slate-50/80 transition border-b border-slate-100 group"
                                data-empresa="<?= htmlspecialchars($s['empresa_nombre']) ?>"
                                data-search="<?= htmlspecialchars($search_str) ?>">
                                
                                <td class="py-3.5 px-4 text-center text-xs font-mono font-bold text-slate-400 group-hover:text-emerald-600">
                                    <?= $num++ ?>
                                </td>

                                <td class="py-3.5 px-4">
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-xl text-xs font-bold border shadow-xs <?= $badgeEmpClass ?>">
                                        <i class="fa-solid fa-building text-[10px]"></i>
                                        <?= htmlspecialchars($s['empresa_nombre']) ?>
                                    </span>
                                </td>

                                <td class="py-3.5 px-4">
                                    <div class="flex items-center gap-2">
                                        <span class="text-base" title="<?= htmlspecialchars($bandera['pais']) ?>"><?= $bandera['flag'] ?></span>
                                        <div>
                                            <span class="font-bold text-slate-800 text-sm block group-hover:text-emerald-700 transition">
                                                <?= htmlspecialchars($s['nombre']) ?>
                                            </span>
                                            <span class="text-[10px] text-slate-400 font-medium"><?= htmlspecialchars($bandera['pais']) ?></span>
                                        </div>
                                    </div>
                                </td>

                                <td class="py-3.5 px-4 text-xs text-slate-600">
                                    <?php if (!empty($s['ubicacion'])): ?>
                                        <div class="flex items-center gap-1.5">
                                            <i class="fa-solid fa-location-dot text-slate-400 text-xs"></i>
                                            <span><?= htmlspecialchars($s['ubicacion']) ?></span>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-slate-400 italic">Sin dirección específica</span>
                                    <?php endif; ?>
                                </td>

                                <td class="py-3.5 px-4 text-center">
                                    <a href="../empleados/index.php" title="Ver colaboradores de esta sede"
                                        class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-xl text-xs font-bold bg-violet-50 text-violet-700 hover:bg-violet-100 border border-violet-200 transition">
                                        <i class="fa-solid fa-users text-[10px]"></i>
                                        <span><?= $s['cant_colaboradores'] ?></span>
                                    </a>
                                </td>

                                <td class="py-3.5 px-4 text-center">
                                    <a href="../equipos/index.php" title="Ver equipos en esta sede"
                                        class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-xl text-xs font-bold bg-cyan-50 text-cyan-700 hover:bg-cyan-100 border border-cyan-200 transition">
                                        <i class="fa-solid fa-laptop text-[10px]"></i>
                                        <span><?= $s['cant_equipos'] ?></span>
                                    </a>
                                </td>

                                <td class="py-3.5 px-4 text-center">
                                    <div class="flex items-center justify-center gap-1.5">
                                        <button type="button"
                                            onclick='abrirEditarSucursal(<?= json_encode($s, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'
                                            class="h-8 w-8 rounded-xl bg-slate-100 hover:bg-emerald-50 text-slate-600 hover:text-emerald-600 border border-slate-200 hover:border-emerald-200 flex items-center justify-center transition"
                                            title="Editar Sucursal">
                                            <i class="fa-solid fa-pen-to-square text-xs"></i>
                                        </button>
                                        <button type="button"
                                            onclick="confirmarEliminarSucursal(<?= $s['id'] ?>, '<?= addslashes($s['nombre']) ?>', <?= $s['cant_colaboradores'] ?>, <?= $s['cant_equipos'] ?>)"
                                            class="h-8 w-8 rounded-xl bg-slate-100 hover:bg-rose-50 text-slate-600 hover:text-rose-600 border border-slate-200 hover:border-rose-200 flex items-center justify-center transition"
                                            title="Eliminar Sucursal">
                                            <i class="fa-solid fa-trash text-xs"></i>
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

<!-- ================= MODALES ================= -->

<!-- 1. MODAL NUEVA SUCURSAL -->
<div id="modal-nueva-sucursal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[9999] flex items-center justify-center hidden p-4">
    <div class="bg-white border border-slate-200 rounded-3xl w-full max-w-md overflow-hidden shadow-2xl animate-fade-in">
        <div class="p-6 border-b border-slate-100 bg-gradient-to-r from-emerald-50 to-white flex items-center justify-between">
            <h3 class="font-bold text-slate-800 flex items-center gap-2">
                <i class="fa-solid fa-building text-emerald-500"></i>
                <span>Registrar Nueva Sucursal</span>
            </h3>
            <button type="button" onclick="cerrarModal('modal-nueva-sucursal')" class="text-slate-400 hover:text-slate-800"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form autocomplete="off" action="guardar_sucursales.php?action=crear_sucursal" method="POST" class="p-6 space-y-4">
            <div>
                <label for="ns_empresa" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Empresa a la que pertenece</label>
                <select name="empresa_id" id="ns_empresa" required class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-emerald-500">
                    <option value="" disabled selected>-- Selecciona Empresa --</option>
                    <?php foreach ($empresas as $emp): ?>
                        <option value="<?= $emp['id'] ?>"><?= htmlspecialchars($emp['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="ns_nombre" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Nombre de la Sucursal / Sede</label>
                <input type="text" name="nombre" id="ns_nombre" required placeholder="Ej: Chinandega, Matagalpa, Costa Rica..."
                    class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-emerald-500">
            </div>
            <div>
                <label for="ns_ubicacion" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Ubicación / Dirección Física</label>
                <input type="text" name="ubicacion" id="ns_ubicacion" placeholder="Ej: Km 12 Carretera Norte, Managua"
                    class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-emerald-500">
            </div>
            <div class="pt-3 flex gap-3">
                <button type="button" onclick="cerrarModal('modal-nueva-sucursal')" class="flex-1 py-3 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold text-xs transition">Cancelar</button>
                <button type="submit" class="flex-1 py-3 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs tracking-wide shadow-md transition">Guardar Sucursal</button>
            </div>
        </form>
    </div>
</div>

<!-- 2. MODAL EDITAR SUCURSAL -->
<div id="modal-editar-sucursal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[9999] flex items-center justify-center hidden p-4">
    <div class="bg-white border border-slate-200 rounded-3xl w-full max-w-md overflow-hidden shadow-2xl animate-fade-in">
        <div class="p-6 border-b border-slate-100 bg-gradient-to-r from-emerald-50 to-white flex items-center justify-between">
            <h3 class="font-bold text-slate-800 flex items-center gap-2">
                <i class="fa-solid fa-pen-to-square text-emerald-500"></i>
                <span>Editar Sucursal</span>
            </h3>
            <button type="button" onclick="cerrarModal('modal-editar-sucursal')" class="text-slate-400 hover:text-slate-800"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form autocomplete="off" action="guardar_sucursales.php?action=editar_sucursal" method="POST" class="p-6 space-y-4">
            <input type="hidden" name="id" id="es_id">
            <div>
                <label for="es_empresa" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Empresa</label>
                <select name="empresa_id" id="es_empresa" required class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-emerald-500">
                    <?php foreach ($empresas as $emp): ?>
                        <option value="<?= $emp['id'] ?>"><?= htmlspecialchars($emp['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="es_nombre" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Nombre de la Sucursal</label>
                <input type="text" name="nombre" id="es_nombre" required class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-emerald-500">
            </div>
            <div>
                <label for="es_ubicacion" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Ubicación / Dirección</label>
                <input type="text" name="ubicacion" id="es_ubicacion" class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-emerald-500">
            </div>
            <div class="pt-3 flex gap-3">
                <button type="button" onclick="cerrarModal('modal-editar-sucursal')" class="flex-1 py-3 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold text-xs transition">Cancelar</button>
                <button type="submit" class="flex-1 py-3 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs tracking-wide shadow-md transition">Actualizar Cambios</button>
            </div>
        </form>
    </div>
</div>

<!-- 3. MODAL NUEVA EMPRESA -->
<div id="modal-nueva-empresa" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[9999] flex items-center justify-center hidden p-4">
    <div class="bg-white border border-slate-200 rounded-3xl w-full max-w-sm overflow-hidden shadow-2xl animate-fade-in">
        <div class="p-6 border-b border-slate-100 bg-gradient-to-r from-indigo-50 to-white flex items-center justify-between">
            <h3 class="font-bold text-slate-800 flex items-center gap-2">
                <i class="fa-solid fa-briefcase text-indigo-500"></i>
                <span>Registrar Nueva Empresa</span>
            </h3>
            <button type="button" onclick="cerrarModal('modal-nueva-empresa')" class="text-slate-400 hover:text-slate-800"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form autocomplete="off" action="guardar_sucursales.php?action=crear_empresa" method="POST" class="p-6 space-y-4">
            <div>
                <label for="ne_nombre" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Nombre de la Empresa</label>
                <input type="text" name="nombre" id="ne_nombre" required placeholder="Ej: Mastertec Costa Rica S.A."
                    class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-indigo-500">
            </div>
            <div class="pt-3 flex gap-3">
                <button type="button" onclick="cerrarModal('modal-nueva-empresa')" class="flex-1 py-3 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold text-xs transition">Cancelar</button>
                <button type="submit" class="flex-1 py-3 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs tracking-wide shadow-md transition">Crear Empresa</button>
            </div>
        </form>
    </div>
</div>

<!-- 4. MODAL CONFIRMAR ELIMINAR SUCURSAL -->
<div id="modal-eliminar-sucursal" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-[9999] flex items-center justify-center hidden p-4">
    <div class="bg-white border border-slate-200 rounded-3xl w-full max-w-sm overflow-hidden shadow-2xl animate-fade-in text-center p-6 space-y-4">
        <div class="h-14 w-14 rounded-full bg-rose-100 text-rose-500 flex items-center justify-center text-2xl mx-auto">
            <i class="fa-solid fa-trash-can"></i>
        </div>
        <div>
            <h4 class="font-bold text-slate-800 text-lg">¿Eliminar Sucursal?</h4>
            <p class="text-xs text-slate-500 mt-1" id="del_suc_msg">Esta acción es irreversible.</p>
        </div>
        <div class="flex gap-2.5 pt-2">
            <button type="button" onclick="cerrarModal('modal-eliminar-sucursal')" class="flex-1 py-2.5 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold text-xs transition">Cancelar</button>
            <a href="#" id="del_suc_btn" class="flex-1 py-2.5 rounded-xl bg-rose-500 hover:bg-rose-600 text-white font-bold text-xs shadow-md transition flex items-center justify-center">Sí, eliminar</a>
        </div>
    </div>
</div>

<script>
    function abrirModal(id) {
        const m = document.getElementById(id);
        if (m) m.classList.remove('hidden');
    }

    function cerrarModal(id) {
        const m = document.getElementById(id);
        if (m) m.classList.add('hidden');
    }

    function abrirEditarSucursal(suc) {
        document.getElementById('es_id').value = suc.id;
        document.getElementById('es_empresa').value = suc.empresa_id;
        document.getElementById('es_nombre').value = suc.nombre || '';
        document.getElementById('es_ubicacion').value = suc.ubicacion || '';
        abrirModal('modal-editar-sucursal');
    }

    function confirmarEliminarSucursal(id, nombre, colabs, equipos) {
        if (colabs > 0 || equipos > 0) {
            alert(`No es posible eliminar "${nombre}" porque tiene ${colabs} colaborador(es) y ${equipos} equipo(s) asociados. Debes reasignarlos primero.`);
            return;
        }
        document.getElementById('del_suc_msg').innerText = `¿Estás seguro de que deseas eliminar permanentemente la sucursal "${nombre}"?`;
        document.getElementById('del_suc_btn').href = `guardar_sucursales.php?action=eliminar_sucursal&id=${id}`;
        abrirModal('modal-eliminar-sucursal');
    }

    let _empresaActivaFiltro = '';

    function filtrarPorEmpresa(empNombre, btn) {
        _empresaActivaFiltro = empNombre;
        document.querySelectorAll('.btn-filtro-emp').forEach(b => {
            b.className = 'btn-filtro-emp px-3 py-1.5 rounded-xl text-xs font-semibold bg-white border border-slate-200 text-slate-600 hover:bg-slate-50 transition shadow-xs shrink-0 flex items-center gap-1.5';
        });
        if (btn) {
            btn.className = 'btn-filtro-emp px-3 py-1.5 rounded-xl text-xs font-bold transition bg-slate-800 text-white shadow-sm shrink-0 flex items-center gap-1.5';
        }
        filtrarSucursales();
    }

    function filtrarSucursales() {
        const query = (document.getElementById('filtro-busqueda-suc').value || '').toLowerCase().trim();
        const filas = document.querySelectorAll('.fila-sucursal');
        
        filas.forEach(f => {
            const dataEmp = f.getAttribute('data-empresa') || '';
            const dataSearch = f.getAttribute('data-search') || '';
            
            const matchEmp = !_empresaActivaFiltro || dataEmp === _empresaActivaFiltro;
            const matchQuery = !query || dataSearch.includes(query);

            if (matchEmp && matchQuery) {
                f.style.display = '';
            } else {
                f.style.display = 'none';
            }
        });
    }
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
