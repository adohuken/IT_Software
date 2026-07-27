<?php
/**
 * Módulo de Configuración e Importación Masiva de Datos (CSV)
 * Permite cargar masivamente Equipos, Personal y Credenciales desde un centro unificado.
 */

require_once __DIR__ . '/../../includes/header.php';

$tab_activa = $_GET['tab'] ?? 'todos';
?>

<div class="space-y-6">
    <!-- Encabezado de Página -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h2 class="text-2xl font-bold text-slate-800 tracking-wide flex items-center gap-2.5">
                <i class="fa-solid fa-sliders text-amber-400"></i> Configuración & Carga Masiva CSV
            </h2>
            <p class="text-slate-500 text-sm">Centro unificado para la importación masiva de activos, personal y credenciales cifradas.</p>
        </div>
    </div>

    <!-- Banner Informativo -->
    <div class="glass-panel p-5 rounded-2xl border border-slate-200 bg-black/10 flex items-start gap-4">
        <div class="h-10 w-10 rounded-xl bg-amber-500/10 border border-amber-500/20 flex items-center justify-center text-amber-400 text-xl shrink-0 mt-0.5">
            <i class="fa-solid fa-file-excel"></i>
        </div>
        <div class="space-y-1 text-xs text-slate-600">
            <h4 class="font-bold text-sm text-slate-800">Flujo de trabajo para la carga masiva CSV</h4>
            <p><span class="text-amber-400 font-bold">1.</span> Descarga la <b>Plantilla Excel/CSV</b> de la categoría que deseas importar. Incluye encabezados y filas de ejemplo.</p>
            <p><span class="text-amber-400 font-bold">2.</span> Abre el archivo en <b>Excel</b> o en un editor de texto, agrega tus datos y guárdalo (manteniendo el formato .csv).</p>
            <p><span class="text-amber-400 font-bold">3.</span> Sube el <b>archivo .csv</b> en el formulario correspondiente. Las contraseñas se cifran automáticamente con <b>AES-256</b>.</p>
        </div>
    </div>

    <!-- Grilla de Tarjetas de Importación Masiva -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">

        <!-- 1. TARJETA: EQUIPOS / HARDWARE -->
        <div class="bg-white rounded-3xl border border-slate-200 p-6 flex flex-col justify-between hover:border-cyan-500/30 transition shadow-xl">
            <div class="space-y-4">
                <div class="flex items-center justify-between">
                    <div class="h-12 w-12 rounded-2xl bg-cyan-500/10 border border-cyan-500/20 flex items-center justify-center text-cyan-400 text-xl">
                        <i class="fa-solid fa-laptop-code"></i>
                    </div>
                    <span class="text-[10px] font-bold uppercase tracking-wider px-2.5 py-1 rounded-full bg-cyan-500/10 text-cyan-400 border border-cyan-500/20">Assets</span>
                </div>
                <div>
                    <h3 class="font-bold text-lg text-slate-800">Equipos e Inventario Hardware</h3>
                    <p class="text-xs text-slate-500 mt-1">Laptops, desktops, servidores, switches, APs y maquinas virtuales vinculados a sucursales.</p>
                </div>

                
            </div>

            <form action="../equipos/guardar.php?action=import_csv&redirect=config" method="POST" enctype="multipart/form-data" class="mt-6 pt-4 border-t border-slate-200 space-y-3">
                <input type="file" name="archivo_csv" accept=".csv, .xlsx, .xls" required class="w-full bg-slate-100 border border-slate-200 rounded-xl p-2.5 text-xs text-slate-600 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-cyan-500/20 file:text-cyan-300">
                
                <div class="flex items-center justify-between pt-1">
                                    <a href="<?= BASE_URL ?>includes/plantilla.php?tipo=equipos" class="text-xs font-semibold text-cyan-400 hover:text-cyan-300 flex items-center gap-1">
                        <i class="fa-solid fa-file-excel"></i> Plantilla Excel/CSV
                    </a>
                    <button type="submit" class="px-4 py-2 rounded-xl grad-cyan-blue text-slate-800 font-bold text-xs shadow-lg shadow-cyan-500/10 hover:opacity-90 transition">
                        Importar Equipos
                    </button>
                </div>
            </form>
        </div>

        <!-- 2. TARJETA: PERSONAL / COLABORADORES -->
        <div class="bg-white rounded-3xl border border-slate-200 p-6 flex flex-col justify-between hover:border-emerald-500/30 transition shadow-xl">
            <div class="space-y-4">
                <div class="flex items-center justify-between">
                    <div class="h-12 w-12 rounded-2xl bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center text-emerald-400 text-xl">
                        <i class="fa-solid fa-user-check"></i>
                    </div>
                    <span class="text-[10px] font-bold uppercase tracking-wider px-2.5 py-1 rounded-full bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">Personal</span>
                </div>
                <div>
                    <h3 class="font-bold text-lg text-slate-800">Personal / Colaboradores</h3>
                    <p class="text-xs text-slate-500 mt-1">Carga masiva de colaboradores, asignando cargos, departamentos y sucursales corporativas.</p>
                </div>

                
            </div>

            <form action="../empleados/guardar.php?action=import_csv&redirect=config" method="POST" enctype="multipart/form-data" class="mt-6 pt-4 border-t border-slate-200 space-y-3">
                <input type="file" name="archivo_csv" accept=".csv, .xlsx, .xls" required class="w-full bg-slate-100 border border-slate-200 rounded-xl p-2.5 text-xs text-slate-600 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-emerald-500/20 file:text-emerald-300">
                
                <div class="flex items-center justify-between pt-1">
                                    <a href="<?= BASE_URL ?>includes/plantilla.php?tipo=empleados" class="text-xs font-semibold text-emerald-400 hover:text-emerald-300 flex items-center gap-1">
                        <i class="fa-solid fa-file-excel"></i> Plantilla Excel/CSV
                    </a>
                    <button type="submit" class="px-4 py-2 rounded-xl bg-emerald-500 hover:bg-emerald-600 text-white font-bold text-xs shadow-lg shadow-emerald-500/10 transition">
                        Importar Personal
                    </button>
                </div>
            </form>
        </div>



    </div>

    <!-- Sección de Mantenimiento de Base de Datos -->
    <div class="mt-10 pt-8 border-t border-slate-200">
        <div class="mb-6">
            <h2 class="text-xl font-bold text-slate-800 tracking-wide flex items-center gap-2.5">
                <i class="fa-solid fa-database text-rose-400"></i> Mantenimiento del Sistema
            </h2>
            <p class="text-slate-500 text-sm mt-1">Respalda toda la información o reinicia el sistema (borrado de datos operativos).</p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            
            <!-- TARJETA: RESPALDAR BD -->
            <div class="bg-white rounded-3xl border border-slate-200 p-6 flex flex-col justify-between hover:border-emerald-500/30 transition shadow-xl">
                <div class="space-y-4">
                    <div class="flex items-center justify-between">
                        <div class="h-12 w-12 rounded-2xl bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center text-emerald-400 text-xl">
                            <i class="fa-solid fa-download"></i>
                        </div>
                        <span class="text-[10px] font-bold uppercase tracking-wider px-2.5 py-1 rounded-full bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">Seguridad</span>
                    </div>
                    <div>
                        <h3 class="font-bold text-lg text-slate-800">Respaldar Base de Datos</h3>
                        <p class="text-xs text-slate-500 mt-1">Descarga un archivo .sql con toda la estructura y datos actuales del sistema. Recomendado antes de importar masivamente o reiniciar.</p>
                    </div>
                </div>
                
                <div class="mt-6 pt-4 border-t border-slate-200 flex justify-end">
                    <a href="backup.php" class="px-5 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white font-bold text-sm shadow-lg shadow-emerald-500/20 transition flex items-center gap-2">
                        <i class="fa-solid fa-database"></i> Descargar Respaldo
                    </a>
                </div>
            </div>

            <!-- TARJETA: RESETEAR BD -->
            <div class="bg-white rounded-3xl border border-rose-500/20 p-6 flex flex-col justify-between hover:border-rose-500/50 transition shadow-xl relative overflow-hidden">
                <div class="absolute -right-10 -top-10 w-32 h-32 bg-rose-500/10 rounded-full blur-3xl"></div>
                <div class="space-y-4 relative z-10">
                    <div class="flex items-center justify-between">
                        <div class="h-12 w-12 rounded-2xl bg-rose-500/10 border border-rose-500/20 flex items-center justify-center text-rose-400 text-xl">
                            <i class="fa-solid fa-triangle-exclamation"></i>
                        </div>
                        <span class="text-[10px] font-bold uppercase tracking-wider px-2.5 py-1 rounded-full bg-rose-500/10 text-rose-400 border border-rose-500/20">Peligro</span>
                    </div>
                    <div>
                        <h3 class="font-bold text-lg text-rose-400">Resetear Base de Datos</h3>
                        <p class="text-xs text-slate-500 mt-1">
                            Borra permanentemente <b class="text-slate-800">TODOS</b> los registros (equipos, empleados, credenciales, m365). 
                            <br><span class="text-rose-300">Nota: Tu cuenta de administrador no se borrará.</span>
                        </p>
                    </div>
                </div>
                
                <div class="mt-6 pt-4 border-t border-slate-200 flex justify-end relative z-10">
                    <button type="button" onclick="document.getElementById('resetModal').classList.remove('hidden')" class="px-5 py-2.5 rounded-xl bg-rose-600 hover:bg-rose-500 text-white font-bold text-sm shadow-lg shadow-rose-500/20 transition flex items-center gap-2">
                        <i class="fa-solid fa-trash-can"></i> Limpiar Sistema Completo
                    </button>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- Modal de Confirmación de Reseteo -->
<div id="resetModal" class="fixed inset-0 z-[100] hidden">
    <!-- Overlay oscuro -->
    <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" onclick="document.getElementById('resetModal').classList.add('hidden')"></div>
    
    <!-- Contenedor del Modal -->
    <div class="absolute inset-0 flex items-center justify-center p-4">
        <div class="bg-[#111827] border border-rose-500/30 rounded-2xl p-6 w-full max-w-md shadow-2xl relative overflow-hidden animate-fade-in-up">
            <!-- Brillo de fondo -->
            <div class="absolute -right-10 -top-10 w-32 h-32 bg-rose-500/10 rounded-full blur-3xl"></div>
            
            <div class="flex items-start gap-4 relative z-10">
                <div class="h-12 w-12 rounded-full bg-rose-500/10 flex items-center justify-center text-rose-500 shrink-0 border border-rose-500/20">
                    <i class="fa-solid fa-triangle-exclamation text-xl"></i>
                </div>
                
                <div class="space-y-2">
                    <h3 class="text-xl font-bold text-slate-800">¡Advertencia Crítica!</h3>
                    <p class="text-sm text-slate-600">
                        ¿Estás absolutamente seguro de que deseas borrar <b class="text-rose-400">TODOS</b> los datos del sistema?
                    </p>
                    <p class="text-xs text-slate-400">
                        Esta acción <b class="text-slate-800">NO</b> se puede deshacer. Se eliminarán los equipos, credenciales, empleados y cuentas M365. Solo se conservará tu cuenta de administrador.
                    </p>
                </div>
            </div>
            
            <div class="mt-8 flex gap-3 justify-end relative z-10">
                <button type="button" onclick="document.getElementById('resetModal').classList.add('hidden')" class="px-4 py-2 rounded-xl border border-slate-200 text-slate-600 hover:bg-white/5 hover:text-slate-800 font-semibold text-sm transition">
                    Cancelar
                </button>
                <form action="reset.php" method="POST">
                    <button type="submit" class="px-5 py-2 rounded-xl bg-rose-600 hover:bg-rose-500 text-white font-bold text-sm shadow-lg shadow-rose-500/20 transition flex items-center gap-2">
                        <i class="fa-solid fa-check"></i> Sí, borrar todo
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     SECCIÓN: CONFIGURACIÓN DEL ASISTENTE IA
═══════════════════════════════════════════════════════ -->
<?php
// Leer API Key guardada (si existe)
$ai_config_file = __DIR__ . '/../../config/ai_config.php';
$ai_api_key = '';
$ai_model   = 'deepseek-chat';
$ai_provider= 'deepseek';
$ai_status  = 'no_configurada';
if (file_exists($ai_config_file)) {
    $ai_cfg = include $ai_config_file;
    $ai_api_key = $ai_cfg['api_key'] ?? '';
    $ai_model   = $ai_cfg['model'] ?? 'deepseek-chat';
    $ai_provider= $ai_cfg['provider'] ?? 'deepseek';
    $ai_status  = !empty($ai_api_key) ? 'configurada' : 'no_configurada';
}
// Mensaje flash
$flash_ai_ok  = $_SESSION['flash_ai_ok'] ?? null;
$flash_ai_err = $_SESSION['flash_ai_err'] ?? null;
unset($_SESSION['flash_ai_ok'], $_SESSION['flash_ai_err']);
?>

<div class="mt-10 pt-8 border-t border-slate-200">
    <div class="mb-6">
        <h2 class="text-xl font-bold text-slate-800 tracking-wide flex items-center gap-2.5">
            <i class="fa-solid fa-robot text-indigo-400"></i>
            Asistente de Inteligencia Artificial
        </h2>
        <p class="text-sm text-slate-500 mt-1">Conecta el chatbot flotante a DeepSeek AI para obtener respuestas inteligentes sobre el sistema.</p>
    </div>

    <?php if ($flash_ai_ok): ?>
        <div class="mb-4 flex items-center gap-3 p-4 bg-emerald-500/10 border border-emerald-500/20 rounded-xl text-emerald-400 text-sm">
            <i class="fa-solid fa-circle-check shrink-0"></i>
            <span><?= htmlspecialchars($flash_ai_ok) ?></span>
        </div>
    <?php endif; ?>
    <?php if ($flash_ai_err): ?>
        <div class="mb-4 flex items-center gap-3 p-4 bg-rose-500/10 border border-rose-500/20 rounded-xl text-rose-400 text-sm">
            <i class="fa-solid fa-circle-xmark shrink-0"></i>
            <span><?= htmlspecialchars($flash_ai_err) ?></span>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">

        <!-- Formulario de API Key -->
        <div class="lg:col-span-2 bg-white rounded-3xl border border-slate-200 hover:border-indigo-500/30 transition shadow-xl p-6">
            <form action="guardar_ai.php" method="POST" class="space-y-5">

                <div class="grid grid-cols-1 md:grid-cols-2 gap-5 mb-5">
                    <div>
                        <label class="text-xs font-bold text-slate-500 uppercase tracking-widest block mb-2">
                            Proveedor de IA
                        </label>
                        <select name="provider" id="ai-provider" onchange="updateModels()" class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-indigo-400 focus:ring-1 focus:ring-indigo-400/40 transition">
                            <option value="deepseek" <?= $ai_provider === 'deepseek' ? 'selected' : '' ?>>DeepSeek (Recomendado)</option>
                            <option value="openai"   <?= $ai_provider === 'openai'   ? 'selected' : '' ?>>OpenAI</option>
                            <option value="groq"     <?= $ai_provider === 'groq'     ? 'selected' : '' ?>>Groq (Ultrarrápido)</option>
                            <option value="gemini"   <?= $ai_provider === 'gemini'   ? 'selected' : '' ?>>Google Gemini</option>
                        </select>
                    </div>

                    <div>
                        <label class="text-xs font-bold text-slate-500 uppercase tracking-widest block mb-2">
                            API Key
                        </label>
                        <div class="relative">
                            <input type="password" name="api_key" id="ai-key-input"
                                   value="<?= htmlspecialchars($ai_api_key) ?>"
                                   placeholder="sk-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
                                   class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 pr-12 text-sm text-slate-800 font-mono placeholder-gray-600 focus:outline-none focus:border-indigo-400 focus:ring-1 focus:ring-indigo-400/40 transition">
                            <button type="button" onclick="toggleKeyVisibility()" title="Mostrar/Ocultar Key"
                                    class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-indigo-400 transition">
                                <i id="eye-icon" class="fa-solid fa-eye text-sm"></i>
                            </button>
                        </div>
                    </div>
                </div>
                
                <p class="text-[11px] text-slate-400 mt-1.5 -translate-y-3">
                    Obtén tu clave en el panel de tu proveedor. Se guarda de forma segura en tu servidor local, nunca se expone al navegador.
                </p>

                <div>
                    <label class="text-xs font-bold text-slate-500 uppercase tracking-widest block mb-2">
                        Modelo de IA
                    </label>
                    <select name="model" id="ai-model" data-selected="<?= htmlspecialchars($ai_model) ?>" class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm text-slate-800 focus:outline-none focus:border-indigo-400 focus:ring-1 focus:ring-indigo-400/40 transition">
                        <!-- Generado por JS -->
                    </select>
                </div>

                <div class="pt-2 flex items-center gap-3">
                    <button type="submit" class="px-6 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-slate-800 font-bold text-sm shadow-lg shadow-indigo-500/20 transition flex items-center gap-2">
                        <i class="fa-solid fa-floppy-disk"></i> Guardar Configuración
                    </button>
                    <?php if (!empty($ai_api_key)): ?>
                    <form action="guardar_ai.php" method="POST" class="inline">
                        <input type="hidden" name="api_key" value="">
                        <input type="hidden" name="model" value="deepseek-chat">
                        <input type="hidden" name="action" value="delete">
                        <button type="submit" class="px-4 py-2.5 rounded-xl border border-rose-500/30 text-rose-400 hover:bg-rose-500/10 font-semibold text-sm transition flex items-center gap-2">
                            <i class="fa-solid fa-trash"></i> Eliminar Key
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Estado actual de la IA -->
        <div class="bg-white rounded-3xl border border-slate-200 p-6 shadow-xl flex flex-col gap-4">
            <h4 class="text-sm font-bold text-slate-600 flex items-center gap-2">
                <i class="fa-solid fa-circle-info text-indigo-400"></i>
                Estado del Asistente
            </h4>
            <?php if ($ai_status === 'configurada'): ?>
                <div class="flex items-center gap-3 p-3 bg-emerald-500/5 border border-emerald-500/20 rounded-xl">
                    <span class="h-2.5 w-2.5 rounded-full bg-emerald-500 animate-pulse shrink-0"></span>
                    <div>
                        <p class="text-xs font-bold text-emerald-400">API Key Activa</p>
                        <p class="text-[10px] text-slate-400 mt-0.5">Proveedor: <?= ucfirst(htmlspecialchars($ai_provider)) ?> | Modelo: <?= htmlspecialchars($ai_model) ?></p>
                    </div>
                </div>
                <p class="text-[11px] text-slate-400 leading-relaxed">El chatbot flotante está conectado a su proveedor. Tiene acceso al contexto de tu base de datos para responder preguntas sobre equipos, empleados, licencias y más.</p>
            <?php else: ?>
                <div class="flex items-center gap-3 p-3 bg-amber-500/5 border border-amber-500/20 rounded-xl">
                    <span class="h-2.5 w-2.5 rounded-full bg-amber-500 shrink-0"></span>
                    <div>
                        <p class="text-xs font-bold text-amber-400">Sin API Key</p>
                        <p class="text-[10px] text-slate-400 mt-0.5">Modo búsqueda básica activo</p>
                    </div>
                </div>
                <p class="text-[11px] text-slate-400 leading-relaxed">Agrega tu API Key para activar respuestas inteligentes en lenguaje natural. Sin ella, el asistente funciona como buscador básico.</p>
            <?php endif; ?>

            <div class="mt-auto pt-4 border-t border-slate-200">
                <p class="text-[10px] text-slate-400 uppercase tracking-widest font-bold mb-2">Capacidades con IA activa</p>
                <ul class="space-y-1.5">
                    <li class="text-[11px] text-slate-500 flex items-center gap-2"><i class="fa-solid fa-check text-indigo-400 text-[10px]"></i> Preguntas en lenguaje natural</li>
                    <li class="text-[11px] text-slate-500 flex items-center gap-2"><i class="fa-solid fa-check text-indigo-400 text-[10px]"></i> Resúmenes del estado del sistema</li>
                    <li class="text-[11px] text-slate-500 flex items-center gap-2"><i class="fa-solid fa-check text-indigo-400 text-[10px]"></i> Contexto de tus datos reales</li>
                    <li class="text-[11px] text-slate-500 flex items-center gap-2"><i class="fa-solid fa-check text-indigo-400 text-[10px]"></i> Ayuda para registrar información</li>
                </ul>
            </div>
        </div>

    </div>
</div>

<script>
const modelsByProvider = {
    'deepseek': [
        { value: 'deepseek-chat', label: 'deepseek-chat — Rápido y eficiente (Recomendado)' },
        { value: 'deepseek-reasoner', label: 'deepseek-reasoner — Razonamiento profundo (Más lento)' }
    ],
    'openai': [
        { value: 'gpt-4o-mini', label: 'gpt-4o-mini — Rápido y económico (Recomendado)' },
        { value: 'gpt-4o', label: 'gpt-4o — Máxima inteligencia' },
        { value: 'gpt-3.5-turbo', label: 'gpt-3.5-turbo — Legacy' }
    ],
    'groq': [
        { value: 'llama3-8b-8192', label: 'Llama 3 8B — Extremadamente rápido' },
        { value: 'llama3-70b-8192', label: 'Llama 3 70B — Potente y rápido (Recomendado)' },
        { value: 'mixtral-8x7b-32768', label: 'Mixtral 8x7B' }
    ],
    'gemini': [
        { value: 'gemini-3.5-flash', label: 'Gemini 3.5 Flash — Rápido y eficiente' },
        { value: 'gemini-3.6-flash', label: 'Gemini 3.6 Flash — Máxima capacidad (Recomendado)' },
        { value: 'gemini-2.5-flash', label: 'Gemini 2.5 Flash' }
    ]
};

function updateModels() {
    const provider = document.getElementById('ai-provider').value;
    const modelSelect = document.getElementById('ai-model');
    const selectedModel = modelSelect.getAttribute('data-selected');
    
    modelSelect.innerHTML = '';
    
    if (modelsByProvider[provider]) {
        modelsByProvider[provider].forEach(model => {
            const option = document.createElement('option');
            option.value = model.value;
            option.textContent = model.label;
            if (model.value === selectedModel) {
                option.selected = true;
            }
            modelSelect.appendChild(option);
        });
    }
}

function toggleKeyVisibility() {
    const input = document.getElementById('ai-key-input');
    const icon  = document.getElementById('eye-icon');
    if (input.type === 'password') {
        input.type = 'text';
        icon.className = 'fa-solid fa-eye-slash text-sm';
    } else {
        input.type = 'password';
        icon.className = 'fa-solid fa-eye text-sm';
    }
}

// Inicializar modelos al cargar
document.addEventListener('DOMContentLoaded', updateModels);
</script>


<!-- Libreria SheetJS para procesar Excel localmente -->
<script src="https://cdn.sheetjs.com/xlsx-latest/package/dist/xlsx.full.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    // Interceptar inputs de archivo
    document.querySelectorAll('input[type="file"]').forEach(input => {
        input.addEventListener('change', async (e) => {
            const file = e.target.files[0];
            if (!file) return;
            
            // Si el archivo es excel, lo leemos y convertimos a CSV antes de enviarlo
            if (file.name.endsWith('.xlsx') || file.name.endsWith('.xls')) {
                // Deshabilitar botón temporalmente y mostrar estado
                const form = input.closest('form');
                const btn = form.querySelector('button[type="submit"]');
                const originalText = btn.innerHTML;
                btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Convirtiendo...';
                btn.disabled = true;

                const reader = new FileReader();
                reader.onload = function(e) {
                    try {
                        const data = new Uint8Array(e.target.result);
                        const workbook = XLSX.read(data, {type: 'array'});
                        const firstSheetName = workbook.SheetNames[0];
                        const worksheet = workbook.Sheets[firstSheetName];
                        const csv = XLSX.utils.sheet_to_csv(worksheet);
                        
                        // Crear un nuevo archivo CSV en memoria
                        const csvFile = new File([csv], file.name.replace(/\.xlsx?$/, '.csv'), { type: 'text/csv' });
                        
                        // Reemplazar el archivo en el input
                        const dataTransfer = new DataTransfer();
                        dataTransfer.items.add(csvFile);
                        input.files = dataTransfer.files;
                        
                        // Restaurar botón
                        btn.innerHTML = '<i class="fa-solid fa-check"></i> Listo para Importar';
                        btn.classList.add('bg-emerald-500', 'text-white');
                        btn.classList.remove('grad-cyan-blue', 'text-slate-800');
                        btn.disabled = false;
                    } catch (error) {
                        alert("Error al procesar el archivo Excel. Asegúrate de que el formato sea correcto.");
                        btn.innerHTML = originalText;
                        btn.disabled = false;
                        input.value = ""; // Limpiar input
                    }
                };
                reader.readAsArrayBuffer(file);
            }
        });
    });
});
</script>

<style>
    @keyframes fade-in-up {
        from { opacity: 0; transform: translateY(10px) scale(0.95); }
        to { opacity: 1; transform: translateY(0) scale(1); }
    }
    .animate-fade-in-up { animation: fade-in-up 0.2s ease-out forwards; }
</style>

<?php
require_once __DIR__ . '/../../includes/footer.php';
?>
