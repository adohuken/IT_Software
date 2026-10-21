<?php
/**
 * Módulo de Automatización y Tareas IT
 * Gestión de tickets, mantenimientos preventivos y programación de cron alerts
 */

require_once __DIR__ . '/../../includes/header.php';

$db = Database::getConexion();

// Cargar catálogo de personal de IT para asignación
$equipo_it = $db->query("SELECT id, nombre, rol FROM equipo_it WHERE estado = 'Activo' ORDER BY nombre")->fetchAll();

// Obtener filtro por estado
$filtro_estado = $_GET['estado'] ?? '';

// Construir consulta de tareas
$sql = "
    SELECT t.*, e.nombre AS asignado_nombre, e.email AS asignado_email
    FROM tareas_it t
    LEFT JOIN equipo_it e ON t.asignado_a = e.id
    WHERE 1=1
";

$params = [];
if (!empty($filtro_estado)) {
    $sql .= " AND t.estado = ?";
    $params[] = $filtro_estado;
}

$sql .= " ORDER BY CASE t.estado WHEN 'Vencida' THEN 1 WHEN 'En Progreso' THEN 2 WHEN 'Pendiente' THEN 3 ELSE 4 END ASC, t.fecha_limite ASC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$tareas = $stmt->fetchAll();
?>

<div class="space-y-6">
    <!-- Encabezado de Página -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h2 class="text-2xl font-bold text-slate-800 tracking-wide">Tareas y Automatización IT</h2>
            <p class="text-slate-500 text-sm">Organiza mantenimientos preventivos, respaldos periódicos y tickets de soporte.</p>
        </div>
        <div class="flex gap-2">
            <!-- Botón de Ejecutar Alertas Cron (Prueba Manual) -->
            <a href="alertas_cron.php" target="_blank" class="px-4 py-2.5 rounded-xl bg-amber-500/10 border border-amber-500/20 text-amber-500 hover:bg-amber-500/20 hover:text-slate-800 font-bold text-sm transition flex items-center gap-2">
                <i class="fa-solid fa-clock-rotate-left animate-spin-slow"></i> Correr Script Alertas
            </a>
            <button onclick="abrirModal('modal-nueva-tarea')" class="px-4 py-2.5 rounded-xl grad-cyan-blue hover:opacity-90 text-slate-800 font-bold text-sm tracking-wide shadow-lg shadow-cyan-500/10 flex items-center gap-2">
                <i class="fa-solid fa-plus"></i> Nueva Tarea / Ticket
            </button>
        </div>
    </div>

    <!-- Barra de Filtros Rápidos y Buscador -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 bg-white p-4 rounded-xl border border-slate-200">
        <div class="flex gap-2 flex-wrap items-center">
            <a href="index.php" class="px-4 py-2 rounded-lg text-xs font-semibold transition <?= empty($filtro_estado) ? 'bg-cyan-400 text-black' : 'bg-slate-100 text-slate-600 hover:text-slate-800' ?>">Todas</a>
            <a href="index.php?estado=Pendiente" class="px-4 py-2 rounded-lg text-xs font-semibold transition <?= ($filtro_estado === 'Pendiente') ? 'bg-amber-500/20 text-amber-600 border border-amber-500/30' : 'bg-slate-100 text-slate-600 hover:text-slate-800' ?>">Pendientes</a>
            <a href="index.php?estado=En Progreso" class="px-4 py-2 rounded-lg text-xs font-semibold transition <?= ($filtro_estado === 'En Progreso') ? 'bg-blue-500/20 text-blue-600 border border-blue-500/30' : 'bg-slate-100 text-slate-600 hover:text-slate-800' ?>">En Progreso</a>
            <a href="index.php?estado=Completada" class="px-4 py-2 rounded-lg text-xs font-semibold transition <?= ($filtro_estado === 'Completada') ? 'bg-emerald-500/20 text-emerald-600 border border-emerald-500/30' : 'bg-slate-100 text-slate-600 hover:text-slate-800' ?>">Completadas</a>
            <a href="index.php?estado=Vencida" class="px-4 py-2 rounded-lg text-xs font-semibold transition <?= ($filtro_estado === 'Vencida') ? 'bg-rose-500/20 text-rose-600 border border-rose-500/30' : 'bg-slate-100 text-slate-600 hover:text-slate-800' ?>">Vencidas</a>
        </div>
        
        <!-- Buscador Rápido de Tareas con Botón X -->
        <div class="relative w-full md:w-72 flex items-center">
            <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-xs pointer-events-none"></i>
            <input type="text" id="buscar_tarea" 
                   oninput="toggleClearBtn('buscar_tarea', 'btn_clear_tarea'); filtrarTareas();" 
                   onkeydown="if(event.key === 'Escape'){ limpiarBuscador('buscar_tarea', 'btn_clear_tarea', filtrarTareas); }" 
                   placeholder="Buscar tarea, técnico..." 
                   class="w-full pl-9 pr-9 py-2 rounded-xl bg-slate-50 border border-slate-200 text-xs text-slate-800 focus:outline-none focus:border-cyan-500 transition">
            <button type="button" id="btn_clear_tarea" 
                    onclick="limpiarBuscador('buscar_tarea', 'btn_clear_tarea', filtrarTareas)" 
                    class="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-700 hover:bg-slate-200/60 p-1 rounded-full transition-all duration-200 hidden items-center justify-center cursor-pointer" 
                    title="Limpiar búsqueda">
                <i class="fa-solid fa-circle-xmark text-sm"></i>
            </button>
        </div>
    </div>

    <!-- Listado de Tareas -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <?php if (empty($tareas)): ?>
            <div class="col-span-full glass-panel rounded-2xl p-10 text-center text-slate-400">
                No se encontraron tareas registradas.
            </div>
        <?php else: ?>
            <?php foreach ($tareas as $t): 
                // Clases de color para bordes y estados
                if ($t['estado'] == 'Vencida') {
                    $border = 'border-rose-500/20';
                    $badge = 'badge-danger';
                    $icon = '<i class="fa-solid fa-triangle-exclamation text-rose-500 text-lg"></i>';
                } elseif ($t['estado'] == 'En Progreso') {
                    $border = 'border-blue-500/20';
                    $badge = 'badge-progress';
                    $icon = '<i class="fa-solid fa-clock text-blue-400 text-lg"></i>';
                } elseif ($t['estado'] == 'Completada') {
                    $border = 'border-emerald-500/20';
                    $badge = 'badge-success';
                    $icon = '<i class="fa-solid fa-circle-check text-emerald-400 text-lg"></i>';
                } else {
                    $border = 'border-slate-200';
                    $badge = 'badge-pending';
                    $icon = '<i class="fa-solid fa-list-check text-amber-500 text-lg"></i>';
                }
            ?>
                <div class="tarjeta-tarea bg-white border <?= $border ?> rounded-2xl p-5 flex flex-col justify-between hover:border-cyan-400 hover:shadow-xl transition cursor-pointer relative overflow-hidden group" onclick='verDetalleTarea(<?= json_encode($t, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                    <?php if ($t['es_repetitiva']): ?>
                        <!-- Distintivo de Tarea Programada / Recurrente -->
                        <span class="absolute right-0 top-0 bg-cyan-500/10 text-cyan-700 border-l border-b border-cyan-500/20 text-[9px] font-bold px-2.5 py-1 rounded-bl-lg flex items-center gap-1" title="Tarea Recurrente">
                            <i class="fa-solid fa-rotate"></i> Recurrente (<?= $t['frecuencia_dias'] ?> d)
                        </span>
                    <?php endif; ?>

                    <div class="space-y-3">
                        <div class="flex items-start gap-3">
                            <div class="mt-1 shrink-0"><?= $icon ?></div>
                            <div class="overflow-hidden">
                                <h3 class="font-bold text-slate-800 group-hover:text-cyan-700 transition truncate pr-20"><?= htmlspecialchars($t['titulo']) ?></h3>
                                <span class="text-[10px] text-slate-400 font-mono block">Creada: <?= date('d/m/Y H:i', strtotime($t['fecha_creacion'])) ?></span>
                            </div>
                        </div>
                        <p class="text-xs text-slate-600 leading-relaxed text-justify line-clamp-3">
                            <?= htmlspecialchars($t['descripcion'] ?? 'Sin descripción disponible.') ?>
                        </p>
                    </div>

                    <div class="mt-5 pt-4 border-t border-slate-200 flex items-center justify-between gap-4">
                        <div class="flex items-center gap-2">
                            <div class="h-6 w-6 rounded-full bg-blue-100 border border-blue-300 flex items-center justify-center font-bold text-[10px] text-blue-700">
                                <?= strtoupper(substr($t['asignado_nombre'] ?? 'S', 0, 1)) ?>
                            </div>
                            <span class="text-xs text-slate-700 font-medium"><?= htmlspecialchars($t['asignado_nombre'] ?? 'Sin Asignar') ?></span>
                        </div>
                        
                        <div class="flex items-center gap-2">
                            <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold <?= $badge ?>"><?= $t['estado'] ?></span>
                            
                            <div class="text-[11px] font-semibold text-slate-600">
                                Límite: <span class="text-slate-800 font-bold"><?= date('d/m/Y', strtotime($t['fecha_limite'])) ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Botones de Acción Inline -->
                    <div class="mt-4 pt-3 border-t border-slate-100 flex gap-2" onclick="event.stopPropagation();">
                        <button onclick='event.stopPropagation(); verDetalleTarea(<?= json_encode($t, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' class="px-3 py-1.5 rounded-lg bg-cyan-50 hover:bg-cyan-100 text-cyan-700 font-bold text-[10px] uppercase text-center transition flex items-center justify-center gap-1">
                            <i class="fa-solid fa-eye"></i> Detalle
                        </button>
                        <?php if ($t['estado'] !== 'Completada'): ?>
                            <?php if ($t['estado'] === 'Pendiente' || $t['estado'] === 'Vencida'): ?>
                                <a href="guardar.php?action=status&id=<?= $t['id'] ?>&status=En Progreso" onclick="event.stopPropagation();" class="flex-1 py-1.5 rounded-lg bg-blue-500/10 hover:bg-blue-500/20 text-blue-600 font-bold text-[10px] uppercase text-center transition">
                                    Iniciar
                                </a>
                            <?php endif; ?>
                            <a href="guardar.php?action=status&id=<?= $t['id'] ?>&status=Completada" onclick="event.stopPropagation();" class="flex-1 py-1.5 rounded-lg bg-emerald-500/10 hover:bg-emerald-500/20 text-emerald-600 font-bold text-[10px] uppercase text-center transition">
                                Completar
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- ========================================================
     MODALES DEL MÓDULO (OCULTOS POR DEFECTO)
     ======================================================== -->

<!-- 1. Modal Nueva Tarea -->
<div id="modal-nueva-tarea" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-center justify-center hidden p-4">
    <div class="bg-white border border-slate-200 rounded-3xl w-full max-w-lg overflow-hidden shadow-2xl animate-fade-in">
        <div class="p-6 border-b border-slate-200 flex items-center justify-between">
            <h3 class="font-bold text-slate-800 flex items-center gap-2">
                <i class="fa-solid fa-list-check text-cyan-400"></i> Registrar Tarea / Mantenimiento IT
            </h3>
            <button onclick="cerrarModal('modal-nueva-tarea')" class="text-slate-400 hover:text-slate-800"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form autocomplete="off" action="guardar.php?action=add" method="POST" class="p-6 space-y-4">
            <div>
                <label for="t_title" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Título de la Tarea</label>
                <input type="text" name="titulo" id="t_title" required placeholder="Ej: Mantenimiento Preventivo de Firewall Kerio" class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400">
            </div>
            <div>
                <label for="t_desc" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Descripción detallada</label>
                <textarea name="descripcion" id="t_desc" rows="3" required placeholder="Pasos detallados a seguir..." class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400"></textarea>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="t_asig" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Asignar A</label>
                    <select autocomplete="off" name="asignado_a" id="t_asig" required class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400">
                        <option value="" disabled selected>Selecciona Técnico IT...</option>
                        <?php foreach ($equipo_it as $it): ?>
                            <option value="<?= $it['id'] ?>"><?= htmlspecialchars($it['nombre'] . ' (' . $it['rol'] . ')') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="t_date" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Fecha Límite</label>
                    <input type="date" name="fecha_limite" id="t_date" required class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400">
                </div>
            </div>
            <div class="border border-slate-200 rounded-2xl p-4 bg-slate-50 space-y-4">
                <div class="flex items-center justify-between">
                    <label for="t_rep" class="text-xs font-semibold text-slate-600 uppercase tracking-wider">¿Es una Tarea Repetitiva / Periódica?</label>
                    <input type="checkbox" name="es_repetitiva" id="t_rep" value="1" onchange="toggleFrecuencia()" class="h-4 w-4 rounded border-gray-300 text-cyan-500 focus:ring-cyan-400 bg-slate-100">
                </div>
                <div id="div-frecuencia" class="hidden">
                    <label for="t_frec" class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2">Frecuencia de Regeneración (Días)</label>
                    <input type="number" name="frecuencia_dias" id="t_frec" value="30" min="1" class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-cyan-400">
                    <span class="text-[10px] text-slate-400 block mt-1.5">Al completarse, se creará una copia idéntica agregando este número de días a la fecha actual.</span>
                </div>
            </div>
            <div class="pt-4 flex gap-3">
                <button type="button" onclick="cerrarModal('modal-nueva-tarea')" class="flex-1 py-3 rounded-xl bg-white/5 hover:bg-white/10 text-slate-800 font-semibold text-sm transition border border-slate-200">Cancelar</button>
                <button type="submit" class="flex-1 py-3 rounded-xl grad-cyan-blue text-slate-800 font-bold text-sm tracking-wide shadow-lg shadow-cyan-500/20 hover:opacity-90 transition">Guardar Tarea</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Detalle Tarea IT -->
<div id="modal-detalle-tarea" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[100] flex items-center justify-center hidden p-4">
    <div class="bg-white border border-slate-200 rounded-3xl w-full max-w-lg overflow-hidden shadow-2xl animate-fade-in relative">
        <div class="p-6 border-b border-slate-100 bg-slate-50/80 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="h-11 w-11 rounded-2xl bg-cyan-500/10 border border-cyan-500/20 text-cyan-600 flex items-center justify-center text-lg shrink-0">
                    <i class="fa-solid fa-list-check" id="dt_icon"></i>
                </div>
                <div>
                    <h3 class="font-bold text-slate-800 text-base leading-tight" id="dt_titulo">Detalle de Tarea</h3>
                    <p class="text-xs text-slate-500 mt-0.5" id="dt_creada">Creada: --</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <span id="dt_badge_estado" class="px-3 py-1 rounded-full text-xs font-bold bg-amber-100 text-amber-700">Pendiente</span>
                <button onclick="cerrarModal('modal-detalle-tarea')" class="h-8 w-8 rounded-full hover:bg-slate-200 text-slate-400 hover:text-slate-700 flex items-center justify-center transition">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
        </div>

        <div class="p-6 space-y-4 text-sm">
            <div>
                <span class="text-xs font-bold text-slate-400 uppercase tracking-wider block mb-1">Descripción de la Tarea</span>
                <div class="bg-slate-50 p-4 rounded-2xl border border-slate-200 text-xs text-slate-700 leading-relaxed font-medium whitespace-pre-wrap" id="dt_descripcion">
                    --
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3 bg-slate-50 p-4 rounded-2xl border border-slate-200">
                <div>
                    <span class="text-[11px] text-slate-400 block font-semibold">Responsable Asignado</span>
                    <span class="font-bold text-slate-800" id="dt_asignado">--</span>
                </div>
                <div>
                    <span class="text-[11px] text-slate-400 block font-semibold">Fecha Límite</span>
                    <span class="font-bold text-slate-800" id="dt_limite">--</span>
                </div>
                <div id="dt_row_frec" class="col-span-2 pt-2 border-t border-slate-200 flex items-center justify-between">
                    <span class="text-[11px] text-slate-400 font-semibold">Frecuencia Recurrente</span>
                    <span class="font-bold text-cyan-600 text-xs" id="dt_frecuencia">--</span>
                </div>
            </div>
        </div>

        <div class="p-4 border-t border-slate-100 bg-slate-50 flex items-center justify-between gap-3" id="dt_actions">
            <button onclick="cerrarModal('modal-detalle-tarea')" class="px-5 py-2 rounded-xl bg-slate-200 hover:bg-slate-300 text-slate-700 text-xs font-bold transition">
                Cerrar
            </button>
            <div class="flex gap-2" id="dt_action_btns">
                <!-- Action buttons populated dynamically -->
            </div>
        </div>
    </div>
</div>

<script>
    function abrirModal(id) {
        const modal = document.getElementById(id);
        const isEditOrDetail = id.includes('edit') || id.includes('det') || id.includes('ficha');
        if (!isEditOrDetail) {
            modal.querySelectorAll('form').forEach(function(f) { f.reset(); });
            modal.querySelectorAll('select').forEach(function(s) {
                if (!s.closest('form')) s.selectedIndex = 0;
            });
        }
        modal.classList.remove('hidden');
    }
    
    function cerrarModal(id) {
        document.getElementById(id).classList.add('hidden');
    }

    function toggleFrecuencia() {
        const checkRep = document.getElementById('t_rep');
        const divFrec = document.getElementById('div-frecuencia');
        if (checkRep.checked) {
            divFrec.classList.remove('hidden');
        } else {
            divFrec.classList.add('hidden');
        }
    }

    function verDetalleTarea(t) {
        document.getElementById('dt_titulo').innerText = t.titulo || 'Detalle de Tarea';
        document.getElementById('dt_creada').innerText = 'Creada: ' + (t.fecha_creacion || '--');
        document.getElementById('dt_descripcion').innerText = t.descripcion || 'Sin descripción guardada.';
        document.getElementById('dt_asignado').innerText = t.asignado_nombre || 'Sin Asignar';
        document.getElementById('dt_limite').innerText = t.fecha_limite || '--';

        const badgeEl = document.getElementById('dt_badge_estado');
        badgeEl.innerText = t.estado;
        if (t.estado === 'Completada') {
            badgeEl.className = 'px-3 py-1 rounded-full text-xs font-bold bg-emerald-100 text-emerald-700 border border-emerald-200';
        } else if (t.estado === 'En Progreso') {
            badgeEl.className = 'px-3 py-1 rounded-full text-xs font-bold bg-blue-100 text-blue-700 border border-blue-200';
        } else if (t.estado === 'Vencida') {
            badgeEl.className = 'px-3 py-1 rounded-full text-xs font-bold bg-rose-100 text-rose-700 border border-rose-200';
        } else {
            badgeEl.className = 'px-3 py-1 rounded-full text-xs font-bold bg-amber-100 text-amber-700 border border-amber-200';
        }

        const rowFrec = document.getElementById('dt_row_frec');
        if (t.es_repetitiva) {
            rowFrec.classList.remove('hidden');
            document.getElementById('dt_frecuencia').innerText = 'Cada ' + t.frecuencia_dias + ' días';
        } else {
            rowFrec.classList.add('hidden');
        }

        const actionBtns = document.getElementById('dt_action_btns');
        let btnsHtml = '';
        if (t.estado !== 'Completada') {
            if (t.estado === 'Pendiente' || t.estado === 'Vencida') {
                btnsHtml += '<a href="guardar.php?action=status&id=' + t.id + '&status=En Progreso" class="px-4 py-2 rounded-xl bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold transition shadow-md">Iniciar Tarea</a>';
            }
            btnsHtml += '<a href="guardar.php?action=status&id=' + t.id + '&status=Completada" class="px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold transition shadow-md">Completar Tarea</a>';
        }
        actionBtns.innerHTML = btnsHtml;

        abrirModal('modal-detalle-tarea');
    }

    function filtrarTareas() {
        const input = document.getElementById('buscar_tarea');
        if (!input) return;
        toggleClearBtn('buscar_tarea', 'btn_clear_tarea');
        const filter = input.value.toLowerCase().trim();
        document.querySelectorAll('.tarjeta-tarea').forEach(card => {
            if (!filter || card.innerText.toLowerCase().includes(filter)) {
                card.style.display = '';
            } else {
                card.style.display = 'none';
            }
        });
    }
</script>

<?php
require_once __DIR__ . '/../../includes/footer.php';
?>

