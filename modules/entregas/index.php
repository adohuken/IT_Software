<?php
/**
 * Módulo de Recepción y Entrega de Equipos (Actas de Entrega y Trabajos IT)
 */

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../config/security.php';

$db = Database::getConexion();

// Asegurar que la tabla recepciones_equipos exista
$db->exec("
    CREATE TABLE IF NOT EXISTS `recepciones_equipos` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `equipo_id` INT NULL,
        `equipo_tipo` VARCHAR(50) NOT NULL,
        `equipo_marca` VARCHAR(50) NOT NULL,
        `equipo_modelo` VARCHAR(100) NOT NULL,
        `service_tag_sn` VARCHAR(100) NOT NULL,
        `entregado_por` VARCHAR(150) NOT NULL,
        `recibido_por` VARCHAR(150) NOT NULL,
        `receptor_final` VARCHAR(150) NOT NULL,
        `trabajo_realizar` TEXT,
        `accesorios` VARCHAR(255) DEFAULT 'Cargador',
        `observaciones` TEXT,
        `fecha_ingreso` DATETIME DEFAULT CURRENT_TIMESTAMP,
        `fecha_entrega` DATETIME NULL,
        `estado` ENUM('En Proceso', 'Listo para Entrega', 'Entregado') DEFAULT 'En Proceso',
        `creado_por` VARCHAR(100) DEFAULT 'IT',
        CONSTRAINT `fk_recepcion_eq` FOREIGN KEY (`equipo_id`) REFERENCES `equipos`(`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

// Insertar datos de prueba iniciales si la tabla está vacía para probar de inmediato
$check_empty = $db->query("SELECT COUNT(*) FROM recepciones_equipos")->fetchColumn();
if ($check_empty == 0) {
    $db->exec("
        INSERT INTO `recepciones_equipos` 
        (`equipo_tipo`, `equipo_marca`, `equipo_modelo`, `service_tag_sn`, `entregado_por`, `recibido_por`, `receptor_final`, `trabajo_realizar`, `accesorios`, `observaciones`, `fecha_ingreso`, `fecha_entrega`, `estado`)
        VALUES 
        ('Laptop', 'Dell', 'Latitude 3420', 'G6725B3', 'Departamento de Soporte', 'IT Support', 'Arllin Alemán', 'Configuración de equipo e instalación de accesos corporativos', 'Cargador.+', 'El equipo se entrega configurado con los datos y accesos correspondientes para la persona asignada: Judith Castillo.', '2026-08-27 10:00:00', '2026-08-27 14:30:00', 'Entregado'),
        ('Laptop', 'HP', 'ProBook 450 G8', 'SN-HP-9921', 'María López', 'Deyvi Martinez', 'María López', 'Mantenimiento preventivo y limpieza de sistema', 'Cargador y Mouse Wireless', 'Se realizó reemplazo de pasta térmica y optimización de software.', NOW(), NULL, 'En Proceso');
    ");
}

// Catálogos para el formulario
$equipos_list = $db->query("SELECT id, tipo, marca, modelo, service_tag_sn FROM equipos WHERE estado != 'De Baja' ORDER BY marca, modelo")->fetchAll();
$empleados_list = $db->query("SELECT id, nombre, cargo, departamento FROM empleados WHERE estado = 'Activo' ORDER BY nombre")->fetchAll();

// Filtro por estado
$filtro_estado = $_GET['estado'] ?? 'todos';

$sql = "SELECT * FROM recepciones_equipos WHERE 1=1";
$params = [];

if ($filtro_estado !== 'todos' && !empty($filtro_estado)) {
    $sql .= " AND estado = ?";
    $params[] = $filtro_estado;
}

$sql .= " ORDER BY id DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$recepciones = $stmt->fetchAll();

// Métricas KPI
$total_recepciones = (int)$db->query("SELECT COUNT(*) FROM recepciones_equipos")->fetchColumn();
$total_proceso     = (int)$db->query("SELECT COUNT(*) FROM recepciones_equipos WHERE estado = 'En Proceso'")->fetchColumn();
$total_listos      = (int)$db->query("SELECT COUNT(*) FROM recepciones_equipos WHERE estado = 'Listo para Entrega'")->fetchColumn();
$total_entregados  = (int)$db->query("SELECT COUNT(*) FROM recepciones_equipos WHERE estado = 'Entregado'")->fetchColumn();
?>

<div class="max-w-[1400px] mx-auto space-y-6">

    <!-- Encabezado y Acción Principal -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 bg-white p-6 rounded-3xl shadow-sm border border-slate-100">
        <div>
            <h1 class="text-xl font-bold text-slate-800 tracking-tight flex items-center gap-2">
                <i class="fa-solid fa-file-signature text-cyan-500"></i> Recepción y Entregas de Equipos
            </h1>
            <p class="text-xs text-slate-400 mt-1">
                Gestión de equipos ingresados a IT para mantenimiento/configuración y emisión de Actas de Entrega oficiales.
            </p>
        </div>
        <button onclick="openModalNuevo()" class="px-5 py-3 rounded-2xl bg-gradient-to-r from-cyan-500 to-blue-600 hover:from-cyan-600 hover:to-blue-700 text-white font-bold text-xs shadow-lg shadow-cyan-500/20 transition flex items-center gap-2 shrink-0">
            <i class="fa-solid fa-plus text-sm"></i>
            <span>Nuevo Ingreso de Equipo</span>
        </button>
    </div>

    <!-- Métricas KPIs -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="bg-white p-5 rounded-2xl border border-slate-100 shadow-sm flex items-center justify-between">
            <div>
                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">Total Registros</p>
                <h3 class="text-2xl font-extrabold text-slate-800"><?= $total_recepciones ?></h3>
            </div>
            <div class="h-10 w-10 rounded-xl bg-slate-100 text-slate-600 flex items-center justify-center text-lg">
                <i class="fa-solid fa-boxes-stacked"></i>
            </div>
        </div>

        <a href="index.php?estado=En Proceso" class="bg-white p-5 rounded-2xl border border-slate-100 shadow-sm flex items-center justify-between hover:border-amber-200 transition group">
            <div>
                <p class="text-[10px] font-bold text-amber-500 uppercase tracking-widest mb-1">En Proceso / Trabajo IT</p>
                <h3 class="text-2xl font-extrabold text-slate-800"><?= $total_proceso ?></h3>
            </div>
            <div class="h-10 w-10 rounded-xl bg-amber-50 text-amber-500 flex items-center justify-center text-lg group-hover:bg-amber-500 group-hover:text-white transition">
                <i class="fa-solid fa-screws-tilting"></i>
            </div>
        </a>

        <a href="index.php?estado=Listo para Entrega" class="bg-white p-5 rounded-2xl border border-slate-100 shadow-sm flex items-center justify-between hover:border-blue-200 transition group">
            <div>
                <p class="text-[10px] font-bold text-blue-500 uppercase tracking-widest mb-1">Listos para Entrega</p>
                <h3 class="text-2xl font-extrabold text-slate-800"><?= $total_listos ?></h3>
            </div>
            <div class="h-10 w-10 rounded-xl bg-blue-50 text-blue-500 flex items-center justify-center text-lg group-hover:bg-blue-500 group-hover:text-white transition">
                <i class="fa-solid fa-box-open"></i>
            </div>
        </a>

        <a href="index.php?estado=Entregado" class="bg-white p-5 rounded-2xl border border-slate-100 shadow-sm flex items-center justify-between hover:border-emerald-200 transition group">
            <div>
                <p class="text-[10px] font-bold text-emerald-500 uppercase tracking-widest mb-1">Entregados con Acta</p>
                <h3 class="text-2xl font-extrabold text-slate-800"><?= $total_entregados ?></h3>
            </div>
            <div class="h-10 w-10 rounded-xl bg-emerald-50 text-emerald-500 flex items-center justify-center text-lg group-hover:bg-emerald-500 group-hover:text-white transition">
                <i class="fa-solid fa-circle-check"></i>
            </div>
        </a>
    </div>

    <!-- Pestañas y Filtros -->
    <div class="bg-white rounded-3xl p-6 shadow-sm border border-slate-100 space-y-4">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 border-b border-slate-100 pb-4">
            <!-- Pestañas por Estado -->
            <div class="flex flex-wrap gap-2">
                <a href="index.php?estado=todos" class="px-4 py-2 rounded-xl text-xs font-bold transition <?= $filtro_estado === 'todos' ? 'bg-slate-800 text-white shadow-sm' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">
                    Todos (<?= $total_recepciones ?>)
                </a>
                <a href="index.php?estado=En Proceso" class="px-4 py-2 rounded-xl text-xs font-bold transition <?= $filtro_estado === 'En Proceso' ? 'bg-amber-500 text-white shadow-sm' : 'bg-amber-50 text-amber-700 hover:bg-amber-100' ?>">
                    <i class="fa-solid fa-clock mr-1"></i> En Proceso (<?= $total_proceso ?>)
                </a>
                <a href="index.php?estado=Listo para Entrega" class="px-4 py-2 rounded-xl text-xs font-bold transition <?= $filtro_estado === 'Listo para Entrega' ? 'bg-blue-600 text-white shadow-sm' : 'bg-blue-50 text-blue-700 hover:bg-blue-100' ?>">
                    <i class="fa-solid fa-thumbs-up mr-1"></i> Listos para Entrega (<?= $total_listos ?>)
                </a>
                <a href="index.php?estado=Entregado" class="px-4 py-2 rounded-xl text-xs font-bold transition <?= $filtro_estado === 'Entregado' ? 'bg-emerald-600 text-white shadow-sm' : 'bg-emerald-50 text-emerald-700 hover:bg-emerald-100' ?>">
                    <i class="fa-solid fa-check-circle mr-1"></i> Entregados (<?= $total_entregados ?>)
                </a>
            </div>

            <!-- Buscador Dinámico -->
            <div class="relative w-full md:w-72 flex items-center">
                <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-xs pointer-events-none"></i>
                <input type="text" id="searchInput" 
                       oninput="toggleClearBtn('searchInput', 'btn_clear_entregas'); filtrarTabla();" 
                       onkeydown="if(event.key === 'Escape'){ limpiarBuscador('searchInput', 'btn_clear_entregas', filtrarTabla); }" 
                       placeholder="Buscar equipo, serie, receptor..." 
                       class="w-full pl-9 pr-9 py-2 rounded-xl bg-slate-50 border border-slate-200 text-xs text-slate-800 focus:outline-none focus:border-cyan-500 transition">
                <button type="button" id="btn_clear_entregas" 
                        onclick="limpiarBuscador('searchInput', 'btn_clear_entregas', filtrarTabla)" 
                        class="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-700 hover:bg-slate-200/60 p-1 rounded-full transition-all duration-200 hidden items-center justify-center cursor-pointer" 
                        title="Limpiar búsqueda">
                    <i class="fa-solid fa-circle-xmark text-sm"></i>
                </button>
            </div>
        </div>

        <!-- Tabla de Registros -->
        <div class="overflow-x-auto custom-scrollbar">
            <table class="w-full text-left text-xs text-slate-700 border-collapse" id="tablaRecepciones">
                <thead>
                    <tr class="bg-slate-50 border-b border-slate-200 text-slate-500 font-bold uppercase tracking-wider">
                        <th class="py-3.5 px-4">F. Ingreso</th>
                        <th class="py-3.5 px-4">Equipo / Marca / Modelo</th>
                        <th class="py-3.5 px-4">S/N (Serial)</th>
                        <th class="py-3.5 px-4">Entregado Por</th>
                        <th class="py-3.5 px-4">Receptor Final</th>
                        <th class="py-3.5 px-4">Accesorios</th>
                        <th class="py-3.5 px-4 text-center">Estado</th>
                        <th class="py-3.5 px-4 text-right">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (empty($recepciones)): ?>
                        <tr>
                            <td colspan="8" class="py-8 text-center text-slate-400">
                                <i class="fa-solid fa-inbox text-3xl mb-2 block"></i>
                                No se encontraron registros de recepción o entregas.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($recepciones as $rec): 
                            $badge_class = 'bg-slate-100 text-slate-600 border-slate-200';
                            if ($rec['estado'] === 'En Proceso') $badge_class = 'bg-amber-50 text-amber-700 border-amber-200';
                            if ($rec['estado'] === 'Listo para Entrega') $badge_class = 'bg-blue-50 text-blue-700 border-blue-200';
                            if ($rec['estado'] === 'Entregado') $badge_class = 'bg-emerald-50 text-emerald-700 border-emerald-200';
                        ?>
                            <tr class="hover:bg-slate-50/80 transition">
                                <td class="py-3.5 px-4 font-medium text-slate-500 whitespace-nowrap">
                                    <?= date('d/m/Y', strtotime($rec['fecha_ingreso'])) ?>
                                    <span class="block text-[10px] text-slate-400"><?= date('H:i A', strtotime($rec['fecha_ingreso'])) ?></span>
                                </td>
                                <td class="py-3.5 px-4">
                                    <div class="font-bold text-slate-800"><?= htmlspecialchars($rec['equipo_marca'] . ' ' . $rec['equipo_modelo']) ?></div>
                                    <span class="text-[10px] text-slate-400 font-medium"><?= htmlspecialchars($rec['equipo_tipo']) ?></span>
                                </td>
                                <td class="py-3.5 px-4 font-mono font-bold text-slate-700">
                                    <?= htmlspecialchars($rec['service_tag_sn']) ?>
                                </td>
                                <td class="py-3.5 px-4 font-medium text-slate-600">
                                    <?= htmlspecialchars($rec['entregado_por']) ?>
                                </td>
                                <td class="py-3.5 px-4">
                                    <div class="font-bold text-cyan-700 flex items-center gap-1.5">
                                        <i class="fa-solid fa-user text-[10px]"></i>
                                        <?= htmlspecialchars($rec['receptor_final']) ?>
                                    </div>
                                </td>
                                <td class="py-3.5 px-4 text-slate-600 max-w-xs truncate" title="<?= htmlspecialchars($rec['accesorios']) ?>">
                                    <?= htmlspecialchars($rec['accesorios'] ?: 'Ninguno') ?>
                                </td>
                                <td class="py-3.5 px-4 text-center">
                                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-[10px] font-bold border <?= $badge_class ?>">
                                        <span class="h-1.5 w-1.5 rounded-full <?= $rec['estado'] === 'Entregado' ? 'bg-emerald-500' : ($rec['estado'] === 'Listo para Entrega' ? 'bg-blue-500' : 'bg-amber-500') ?>"></span>
                                        <?= $rec['estado'] ?>
                                    </span>
                                </td>
                                <td class="py-3.5 px-4 text-right whitespace-nowrap">
                                    <div class="flex items-center justify-end gap-1.5">
                                        <!-- Imprimir Acta / Constancia -->
                                        <a href="imprimir_acta.php?id=<?= $rec['id'] ?>" target="_blank" class="h-8 px-3 rounded-lg bg-cyan-50 text-cyan-600 hover:bg-cyan-500 hover:text-white transition flex items-center gap-1.5 text-[11px] font-bold shadow-sm" title="Imprimir Constancia de Entrega">
                                            <i class="fa-solid fa-print"></i>
                                            <span>Acta</span>
                                        </a>

                                        <?php if ($rec['estado'] !== 'Entregado'): ?>
                                            <!-- Registrar Entrega -->
                                            <button onclick='openModalEntregar(<?= json_encode($rec, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' class="h-8 px-3 rounded-lg bg-emerald-50 text-emerald-600 hover:bg-emerald-600 hover:text-white transition flex items-center gap-1.5 text-[11px] font-bold shadow-sm" title="Registrar Entrega de Equipo">
                                                <i class="fa-solid fa-handshake"></i>
                                                <span>Entregar</span>
                                            </button>
                                        <?php endif; ?>

                                        <!-- Editar -->
                                        <button onclick='openModalEditar(<?= json_encode($rec, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' class="h-8 w-8 rounded-lg bg-slate-100 text-slate-500 hover:bg-slate-200 transition flex items-center justify-center text-xs" title="Editar Registro">
                                            <i class="fa-solid fa-pen"></i>
                                        </button>

                                        <!-- Eliminar -->
                                        <a href="guardar.php?accion=eliminar&id=<?= $rec['id'] ?>" onclick="return confirm('¿Estás seguro de eliminar este registro de recepción?')" class="h-8 w-8 rounded-lg bg-slate-100 text-slate-400 hover:bg-rose-50 hover:text-rose-600 transition flex items-center justify-center text-xs" title="Eliminar">
                                            <i class="fa-solid fa-trash"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <!-- Paginación de Entregas y Recepciones -->
        <div id="paginacionEntregas"></div>
    </div>
</div>

<!-- ========================================================= -->
<!-- MODAL 1: NUEVO INGRESO DE EQUIPO                          -->
<!-- ========================================================= -->
<div id="modalNuevo" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 flex items-center justify-center hidden p-4 overflow-y-auto">
    <div class="bg-white rounded-3xl max-w-2xl w-full shadow-2xl overflow-hidden animate-fade-in-up border border-slate-100 my-8">
        <div class="px-6 py-5 bg-slate-900 text-white flex items-center justify-between">
            <h3 class="font-bold text-base flex items-center gap-2">
                <i class="fa-solid fa-boxes-packing text-cyan-400"></i> Registro de Ingreso de Equipo a IT
            </h3>
            <button onclick="closeModalNuevo()" class="h-8 w-8 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-400 hover:text-white flex items-center justify-center transition">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form action="guardar.php" method="POST" class="p-6 space-y-4">
            <input type="hidden" name="accion" value="crear">

            <!-- Seleccionar de Inventario Existente -->
            <div class="bg-slate-50 p-4 rounded-2xl border border-slate-200 space-y-3">
                <label class="block text-xs font-bold text-slate-700">
                    <i class="fa-solid fa-laptop text-cyan-500 mr-1"></i> Seleccionar Equipo del Inventario (Opcional)
                </label>
                <select id="select_equipo_inventario" onchange="autocompletarEquipo(this)" class="w-full searchable-select text-xs">
                    <option value="">-- Seleccionar Equipo Registrado (O bien ingresa los datos abajo) --</option>
                    <?php foreach ($equipos_list as $eq): ?>
                        <option value="<?= $eq['id'] ?>" data-tipo="<?= htmlspecialchars($eq['tipo']) ?>" data-marca="<?= htmlspecialchars($eq['marca']) ?>" data-modelo="<?= htmlspecialchars($eq['modelo']) ?>" data-sn="<?= htmlspecialchars($eq['service_tag_sn']) ?>">
                            <?= htmlspecialchars($eq['marca'] . ' ' . $eq['modelo'] . ' (SN: ' . $eq['service_tag_sn'] . ')') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Datos del Equipo -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div>
                    <label class="block text-[11px] font-bold text-slate-600 mb-1">Tipo de Equipo *</label>
                    <select name="equipo_tipo" id="equipo_tipo" required class="w-full px-3.5 py-2.5 rounded-xl bg-slate-50 border border-slate-200 text-xs font-medium text-slate-800 focus:outline-none focus:border-cyan-500">
                        <option value="Laptop">Laptop</option>
                        <option value="Desktop">Desktop</option>
                        <option value="Servidor">Servidor</option>
                        <option value="Impresora">Impresora</option>
                        <option value="Switch">Switch / Red</option>
                        <option value="Celular">Celular / Tablet</option>
                        <option value="Otro">Otro</option>
                    </select>
                </div>
                <div>
                    <label class="block text-[11px] font-bold text-slate-600 mb-1">Marca *</label>
                    <input type="text" name="equipo_marca" id="equipo_marca" required placeholder="Ej: Dell, HP, Lenovo" class="w-full px-3.5 py-2.5 rounded-xl bg-slate-50 border border-slate-200 text-xs font-medium text-slate-800 focus:outline-none focus:border-cyan-500">
                </div>
                <div>
                    <label class="block text-[11px] font-bold text-slate-600 mb-1">Modelo *</label>
                    <input type="text" name="equipo_modelo" id="equipo_modelo" required placeholder="Ej: Latitude 3420" class="w-full px-3.5 py-2.5 rounded-xl bg-slate-50 border border-slate-200 text-xs font-medium text-slate-800 focus:outline-none focus:border-cyan-500">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-[11px] font-bold text-slate-600 mb-1">S/N (Serial Number / Service Tag) *</label>
                    <input type="text" name="service_tag_sn" id="service_tag_sn" required placeholder="Ej: G6725B3" class="w-full px-3.5 py-2.5 rounded-xl bg-slate-50 border border-slate-200 text-xs font-mono font-bold text-slate-800 focus:outline-none focus:border-cyan-500">
                </div>
                <div>
                    <label class="block text-[11px] font-bold text-slate-600 mb-1">Accesorios Acompañantes *</label>
                    <input type="text" name="accesorios" placeholder="Ej: Cargador.+, Mouse, Mochila" value="Cargador.+" required class="w-full px-3.5 py-2.5 rounded-xl bg-slate-50 border border-slate-200 text-xs font-medium text-slate-800 focus:outline-none focus:border-cyan-500">
                </div>
            </div>

            <!-- Personas involucradas -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-[11px] font-bold text-slate-600 mb-1">Entregado Por (Quien trae el equipo a IT) *</label>
                    <input type="text" name="entregado_por" placeholder="Ej: Arllin Alemán / Soporte" required class="w-full px-3.5 py-2.5 rounded-xl bg-slate-50 border border-slate-200 text-xs font-medium text-slate-800 focus:outline-none focus:border-cyan-500">
                </div>
                <div>
                    <label class="block text-[11px] font-bold text-slate-600 mb-1">Persona que Recibe / Destinatario Final *</label>
                    <input type="text" name="receptor_final" placeholder="Ej: Arllin Alemán / Judith Castillo" required class="w-full px-3.5 py-2.5 rounded-xl bg-slate-50 border border-slate-200 text-xs font-bold text-cyan-700 focus:outline-none focus:border-cyan-500">
                </div>
            </div>

            <!-- Observaciones -->
            <div>
                <label class="block text-[11px] font-bold text-slate-600 mb-1">Observaciones / Detalles de Configuración</label>
                <textarea name="observaciones" rows="3" placeholder="Ej: El equipo se entrega configurado con los datos y accesos correspondientes para la persona asignada: Judith Castillo." class="w-full p-3 rounded-xl bg-slate-50 border border-slate-200 text-xs text-slate-800 focus:outline-none focus:border-cyan-500">El equipo se entrega configurado con los datos y accesos correspondientes para la persona asignada.</textarea>
            </div>

            <div class="flex justify-end gap-3 pt-4 border-t border-slate-100">
                <button type="button" onclick="closeModalNuevo()" class="px-5 py-2.5 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-600 text-xs font-bold transition">Cancelar</button>
                <button type="submit" class="px-6 py-2.5 rounded-xl bg-gradient-to-r from-cyan-500 to-blue-600 hover:from-cyan-600 hover:to-blue-700 text-white text-xs font-bold transition shadow-md shadow-cyan-500/20">
                    Guardar Ingreso de Equipo
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================= -->
<!-- MODAL 2: CONFIRMAR / REGISTRAR ENTREGA                    -->
<!-- ========================================================= -->
<div id="modalEntregar" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 flex items-center justify-center hidden p-4">
    <div class="bg-white rounded-3xl max-w-lg w-full shadow-2xl overflow-hidden border border-slate-100">
        <div class="px-6 py-5 bg-emerald-600 text-white flex items-center justify-between">
            <h3 class="font-bold text-base flex items-center gap-2">
                <i class="fa-solid fa-handshake text-white"></i> Confirmar Entrega de Equipo
            </h3>
            <button onclick="closeModalEntregar()" class="h-8 w-8 rounded-xl bg-emerald-700 hover:bg-emerald-800 text-white flex items-center justify-center transition">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form action="guardar.php" method="POST" class="p-6 space-y-4">
            <input type="hidden" name="accion" value="entregar">
            <input type="hidden" name="id" id="entregar_id">

            <div class="bg-emerald-50 p-4 rounded-2xl border border-emerald-200 text-xs text-emerald-800">
                <p class="font-bold mb-1" id="entregar_equipo_info">Equipo: Laptop Dell Latitude 3420</p>
                <p class="text-[11px]" id="entregar_sn_info">S/N: G6725B3</p>
            </div>

            <div>
                <label class="block text-[11px] font-bold text-slate-600 mb-1">Nombre Completo del Receptor (Quien Firmará el Acta) *</label>
                <input type="text" name="receptor_final" id="entregar_receptor_final" required class="w-full px-3.5 py-2.5 rounded-xl bg-slate-50 border border-slate-200 text-xs font-bold text-slate-800 focus:outline-none focus:border-emerald-500">
            </div>

            <div>
                <label class="block text-[11px] font-bold text-slate-600 mb-1">Observaciones Finales de Entrega</label>
                <textarea name="observaciones" id="entregar_observaciones" rows="3" class="w-full p-3 rounded-xl bg-slate-50 border border-slate-200 text-xs text-slate-800 focus:outline-none focus:border-emerald-500"></textarea>
            </div>

            <div class="flex justify-end gap-3 pt-4 border-t border-slate-100">
                <button type="button" onclick="closeModalEntregar()" class="px-5 py-2.5 rounded-xl bg-slate-100 text-slate-600 text-xs font-bold">Cancelar</button>
                <button type="submit" class="px-6 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold shadow-md shadow-emerald-600/20 flex items-center gap-2">
                    <i class="fa-solid fa-print"></i>
                    <span>Confirmar y Emitir Acta</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================= -->
<!-- MODAL 3: EDITAR REGISTRO                                  -->
<!-- ========================================================= -->
<div id="modalEditar" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 flex items-center justify-center hidden p-4 overflow-y-auto">
    <div class="bg-white rounded-3xl max-w-2xl w-full shadow-2xl overflow-hidden border border-slate-100 my-8">
        <div class="px-6 py-5 bg-slate-800 text-white flex items-center justify-between">
            <h3 class="font-bold text-base flex items-center gap-2">
                <i class="fa-solid fa-pen-to-square text-cyan-400"></i> Editar Registro de Recepción
            </h3>
            <button onclick="closeModalEditar()" class="h-8 w-8 rounded-xl bg-slate-700 hover:bg-slate-600 text-white flex items-center justify-center transition">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form action="guardar.php" method="POST" class="p-6 space-y-4">
            <input type="hidden" name="accion" value="editar">
            <input type="hidden" name="id" id="edit_id">

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div>
                    <label class="block text-[11px] font-bold text-slate-600 mb-1">Tipo *</label>
                    <input type="text" name="equipo_tipo" id="edit_equipo_tipo" required class="w-full px-3.5 py-2.5 rounded-xl bg-slate-50 border border-slate-200 text-xs font-medium text-slate-800">
                </div>
                <div>
                    <label class="block text-[11px] font-bold text-slate-600 mb-1">Marca *</label>
                    <input type="text" name="equipo_marca" id="edit_equipo_marca" required class="w-full px-3.5 py-2.5 rounded-xl bg-slate-50 border border-slate-200 text-xs font-medium text-slate-800">
                </div>
                <div>
                    <label class="block text-[11px] font-bold text-slate-600 mb-1">Modelo *</label>
                    <input type="text" name="equipo_modelo" id="edit_equipo_modelo" required class="w-full px-3.5 py-2.5 rounded-xl bg-slate-50 border border-slate-200 text-xs font-medium text-slate-800">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-[11px] font-bold text-slate-600 mb-1">S/N *</label>
                    <input type="text" name="service_tag_sn" id="edit_service_tag_sn" required class="w-full px-3.5 py-2.5 rounded-xl bg-slate-50 border border-slate-200 text-xs font-mono font-bold text-slate-800">
                </div>
                <div>
                    <label class="block text-[11px] font-bold text-slate-600 mb-1">Accesorios</label>
                    <input type="text" name="accesorios" id="edit_accesorios" class="w-full px-3.5 py-2.5 rounded-xl bg-slate-50 border border-slate-200 text-xs font-medium text-slate-800">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-[11px] font-bold text-slate-600 mb-1">Entregado Por *</label>
                    <input type="text" name="entregado_por" id="edit_entregado_por" required class="w-full px-3.5 py-2.5 rounded-xl bg-slate-50 border border-slate-200 text-xs font-medium text-slate-800">
                </div>
                <div>
                    <label class="block text-[11px] font-bold text-slate-600 mb-1">Receptor Final *</label>
                    <input type="text" name="receptor_final" id="edit_receptor_final" required class="w-full px-3.5 py-2.5 rounded-xl bg-slate-50 border border-slate-200 text-xs font-bold text-slate-800">
                </div>
            </div>

            <div>
                <label class="block text-[11px] font-bold text-slate-600 mb-1">Estado de Trabajo</label>
                <select name="estado" id="edit_estado" class="w-full px-3.5 py-2.5 rounded-xl bg-slate-50 border border-slate-200 text-xs font-bold text-slate-800">
                    <option value="En Proceso">En Proceso</option>
                    <option value="Listo para Entrega">Listo para Entrega</option>
                    <option value="Entregado">Entregado</option>
                </select>
            </div>

            <div>
                <label class="block text-[11px] font-bold text-slate-600 mb-1">Observaciones</label>
                <textarea name="observaciones" id="edit_observaciones" rows="3" class="w-full p-3 rounded-xl bg-slate-50 border border-slate-200 text-xs text-slate-800"></textarea>
            </div>

            <div class="flex justify-end gap-3 pt-4 border-t border-slate-100">
                <button type="button" onclick="closeModalEditar()" class="px-5 py-2.5 rounded-xl bg-slate-100 text-slate-600 text-xs font-bold">Cancelar</button>
                <button type="submit" class="px-6 py-2.5 rounded-xl bg-slate-800 hover:bg-slate-900 text-white text-xs font-bold">Guardar Cambios</button>
            </div>
        </form>
    </div>
</div>

<script>
function openModalNuevo() {
    document.getElementById('modalNuevo').classList.remove('hidden');
}
function closeModalNuevo() {
    document.getElementById('modalNuevo').classList.add('hidden');
}

function openModalEntregar(data) {
    document.getElementById('entregar_id').value = data.id;
    document.getElementById('entregar_equipo_info').innerText = 'Equipo: ' + data.equipo_tipo + ' ' + data.equipo_marca + ' ' + data.equipo_modelo;
    document.getElementById('entregar_sn_info').innerText = 'S/N: ' + data.service_tag_sn;
    document.getElementById('entregar_receptor_final').value = data.receptor_final;
    document.getElementById('entregar_observaciones').value = data.observaciones;
    document.getElementById('modalEntregar').classList.remove('hidden');
}
function closeModalEntregar() {
    document.getElementById('modalEntregar').classList.add('hidden');
}

function openModalEditar(data) {
    document.getElementById('edit_id').value = data.id;
    document.getElementById('edit_equipo_tipo').value = data.equipo_tipo;
    document.getElementById('edit_equipo_marca').value = data.equipo_marca;
    document.getElementById('edit_equipo_modelo').value = data.equipo_modelo;
    document.getElementById('edit_service_tag_sn').value = data.service_tag_sn;
    document.getElementById('edit_accesorios').value = data.accesorios;
    document.getElementById('edit_entregado_por').value = data.entregado_por;
    document.getElementById('edit_receptor_final').value = data.receptor_final;
    document.getElementById('edit_estado').value = data.estado;
    document.getElementById('edit_observaciones').value = data.observaciones;
    document.getElementById('modalEditar').classList.remove('hidden');
}
function closeModalEditar() {
    document.getElementById('modalEditar').classList.add('hidden');
}

function autocompletarEquipo(select) {
    const option = select.options[select.selectedIndex];
    if (option.value) {
        document.getElementById('equipo_tipo').value = option.getAttribute('data-tipo');
        document.getElementById('equipo_marca').value = option.getAttribute('data-marca');
        document.getElementById('equipo_modelo').value = option.getAttribute('data-modelo');
        document.getElementById('service_tag_sn').value = option.getAttribute('data-sn');
    }
}

let paginadorEntregas = null;

function filtrarTabla() {
    const input = document.getElementById('searchInput');
    if (!input) return;
    toggleClearBtn('searchInput', 'btn_clear_entregas');
    const filter = input.value.toLowerCase().trim();

    if (paginadorEntregas) {
        paginadorEntregas.setFilter(tr => {
            let text = tr.textContent || tr.innerText;
            return !filter || text.toLowerCase().indexOf(filter) > -1;
        });
    }
}

function initPaginacionEntregas() {
    if (typeof TablePaginator !== 'undefined') {
        paginadorEntregas = new TablePaginator({
            table: '#tablaRecepciones',
            container: '#paginacionEntregas',
            itemLabel: 'registros',
            pageSize: 15
        });
        window.paginadorEntregas = paginadorEntregas;
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initPaginacionEntregas);
} else {
    initPaginacionEntregas();
}
</script>

<?php
require_once __DIR__ . '/../../includes/footer.php';
?>
