<?php
/**
 * Módulo de Configuración e Importación Masiva de Datos (CSV)
 * Rediseño Simétrico y Profesional
 */

require_once __DIR__ . '/../../includes/header.php';

$tab_activa = $_GET['tab'] ?? 'todos';
?>

<div class="max-w-7xl mx-auto space-y-10 pb-16 animate-fade-in">
    <!-- Header Minimalista -->
    <div class="border-b border-slate-200 pb-6">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div>
                <span class="text-xs font-bold uppercase tracking-widest text-cyan-500">Panel de Control</span>
                <h1 class="text-3xl font-extrabold text-slate-900 tracking-tight mt-1 flex items-center gap-3">
                    Configuración del Sistema
                </h1>
                <p class="text-slate-500 text-sm mt-1">Administra la carga masiva de datos, marca del sistema, mantenimiento e integración de IA.</p>
            </div>
            <!-- Quick status badge -->
            <div class="flex items-center gap-2 bg-slate-100 border border-slate-200 rounded-full px-4 py-1.5 self-start md:self-auto">
                <span class="h-2 w-2 rounded-full bg-emerald-500 animate-pulse"></span>
                <span class="text-xs font-semibold text-slate-600">Sistema Activo</span>
            </div>
        </div>
    </div>

    <!-- Guía Horizontal del Flujo de Trabajo (Sencilla y Elegante) -->
    <div class="bg-slate-50 border border-slate-200 rounded-3xl p-6">
        <div class="flex items-center gap-2 mb-4">
            <i class="fa-solid fa-circle-info text-cyan-500 text-sm"></i>
            <span class="text-xs font-extrabold uppercase tracking-wider text-slate-400">Flujo de Trabajo para Importación</span>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 text-xs text-slate-600">
            <div class="flex gap-3 items-start">
                <span class="h-6 w-6 rounded-full bg-cyan-500/10 text-cyan-600 font-bold flex items-center justify-center shrink-0 border border-cyan-500/20">1</span>
                <div>
                    <p class="font-bold text-slate-800 mb-0.5">Descargar Plantilla</p>
                    <p class="text-[11px] leading-relaxed text-slate-400">Obtén la plantilla en Excel o CSV de la categoría que deseas importar.</p>
                </div>
            </div>
            <div class="flex gap-3 items-start">
                <span class="h-6 w-6 rounded-full bg-cyan-500/10 text-cyan-600 font-bold flex items-center justify-center shrink-0 border border-cyan-500/20">2</span>
                <div>
                    <p class="font-bold text-slate-800 mb-0.5">Completar Datos</p>
                    <p class="text-[11px] leading-relaxed text-slate-400">Abre el archivo, rellena las filas correspondientes y guarda en formato CSV.</p>
                </div>
            </div>
            <div class="flex gap-3 items-start">
                <span class="h-6 w-6 rounded-full bg-cyan-500/10 text-cyan-600 font-bold flex items-center justify-center shrink-0 border border-cyan-500/20">3</span>
                <div>
                    <p class="font-bold text-slate-800 mb-0.5">Subir y Procesar</p>
                    <p class="text-[11px] leading-relaxed text-slate-400">Sube el CSV. El servidor procesará y cifrará los accesos con AES-256 local.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Sección 1: Carga Masiva (2 Columnas Simétricas) -->
    <div class="space-y-6">
        <div>
            <h2 class="text-lg font-bold text-slate-800 tracking-tight">Carga Masiva de Datos</h2>
            <p class="text-xs text-slate-400">Importa tus registros de manera rápida desde archivos Excel o CSV.</p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
            <!-- Tarjeta: Importación Equipos -->
            <div class="bg-white border border-slate-200 rounded-3xl p-6 hover:shadow-2xl hover:border-cyan-500/30 transition-all duration-300 flex flex-col justify-between group">
                <div class="space-y-4">
                    <div class="flex items-center justify-between">
                        <div class="h-10 w-10 rounded-2xl bg-cyan-500/5 border border-cyan-500/10 text-cyan-500 flex items-center justify-center text-lg transition-transform group-hover:scale-110 duration-300">
                            <i class="fa-solid fa-laptop-code"></i>
                        </div>
                        <span class="text-[9px] font-bold uppercase tracking-widest px-2.5 py-1 rounded-full bg-cyan-500/5 text-cyan-600 border border-cyan-500/10">Equipos & Red</span>
                    </div>
                    <div>
                        <h3 class="font-bold text-base text-slate-800 group-hover:text-cyan-600 transition-colors">Equipos e Inventario Hardware</h3>
                        <p class="text-xs text-slate-400 mt-1 leading-relaxed">Carga laptops, desktops, servidores, switches, APs y máquinas virtuales en lote.</p>
                    </div>
                </div>

                <form autocomplete="off" action="../equipos/guardar.php?action=import_csv&redirect=config" method="POST" enctype="multipart/form-data" class="mt-8 space-y-4">
                    <!-- Dropzone Minimalista -->
                    <div class="relative group/drop">
                        <input type="file" name="archivo_csv" accept=".csv, .xlsx, .xls" required 
                               class="absolute inset-0 w-full h-full opacity-0 cursor-pointer z-10"
                               onchange="updateFilename(this, 'filename-equipos')">
                        <div class="border border-dashed border-slate-200 rounded-2xl p-4 text-center group-hover/drop:border-cyan-400 transition bg-slate-50/50">
                            <i class="fa-solid fa-cloud-arrow-up text-slate-400 text-lg group-hover/drop:text-cyan-500 transition mb-1"></i>
                            <p class="text-xs text-slate-500 font-medium" id="filename-equipos">Arrastra o selecciona tu archivo</p>
                            <span class="text-[9px] text-slate-400">CSV, XLSX o XLS</span>
                        </div>
                    </div>

                    <div class="flex items-center justify-between pt-2">
                        <a href="<?= BASE_URL ?>includes/plantilla.php?tipo=equipos" class="text-xs font-bold text-cyan-500 hover:text-cyan-600 flex items-center gap-1.5 transition">
                            <i class="fa-solid fa-download text-[10px]"></i> Descargar Plantilla
                        </a>
                        <button type="submit" class="px-4 py-2 rounded-xl bg-slate-900 text-white font-bold text-xs hover:bg-cyan-500 hover:text-slate-900 shadow-md transition duration-300">
                            Importar Equipos
                        </button>
                    </div>
                </form>
            </div>

            <!-- Tarjeta: Importación Personal -->
            <div class="bg-white border border-slate-200 rounded-3xl p-6 hover:shadow-2xl hover:border-emerald-500/30 transition-all duration-300 flex flex-col justify-between group">
                <div class="space-y-4">
                    <div class="flex items-center justify-between">
                        <div class="h-10 w-10 rounded-2xl bg-emerald-500/5 border border-emerald-500/10 text-emerald-500 flex items-center justify-center text-lg transition-transform group-hover:scale-110 duration-300">
                            <i class="fa-solid fa-user-check"></i>
                        </div>
                        <span class="text-[9px] font-bold uppercase tracking-widest px-2.5 py-1 rounded-full bg-emerald-500/5 text-emerald-600 border border-emerald-500/10">Colaboradores</span>
                    </div>
                    <div>
                        <h3 class="font-bold text-base text-slate-800 group-hover:text-emerald-600 transition-colors">Personal y Colaboradores</h3>
                        <p class="text-xs text-slate-400 mt-1 leading-relaxed">Carga masiva de colaboradores con sucursales, áreas, departamentos y cargos corporativos.</p>
                    </div>
                </div>

                <form autocomplete="off" action="../empleados/guardar.php?action=import_csv&redirect=config" method="POST" enctype="multipart/form-data" class="mt-8 space-y-4">
                    <!-- Dropzone Minimalista -->
                    <div class="relative group/drop">
                        <input type="file" name="archivo_csv" accept=".csv, .xlsx, .xls" required 
                               class="absolute inset-0 w-full h-full opacity-0 cursor-pointer z-10"
                               onchange="updateFilename(this, 'filename-personal')">
                        <div class="border border-dashed border-slate-200 rounded-2xl p-4 text-center group-hover/drop:border-emerald-400 transition bg-slate-50/50">
                            <i class="fa-solid fa-cloud-arrow-up text-slate-400 text-lg group-hover/drop:text-emerald-500 transition mb-1"></i>
                            <p class="text-xs text-slate-500 font-medium" id="filename-personal">Arrastra o selecciona tu archivo</p>
                            <span class="text-[9px] text-slate-400">CSV, XLSX o XLS</span>
                        </div>
                    </div>

                    <div class="flex items-center justify-between pt-2">
                        <a href="<?= BASE_URL ?>includes/plantilla.php?tipo=empleados" class="text-xs font-bold text-emerald-500 hover:text-emerald-600 flex items-center gap-1.5 transition">
                            <i class="fa-solid fa-download text-[10px]"></i> Descargar Plantilla
                        </a>
                        <button type="submit" class="px-4 py-2 rounded-xl bg-slate-900 text-white font-bold text-xs hover:bg-emerald-500 hover:text-white shadow-md transition duration-300">
                            Importar Personal
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Sección 2: Personalización & IA (2 Columnas Simétricas) -->
    <?php
    $flash_sys_ok = $_SESSION['flash_sys_ok'] ?? null;
    $flash_sys_err = $_SESSION['flash_sys_err'] ?? null;
    unset($_SESSION['flash_sys_ok'], $_SESSION['flash_sys_err']);
    ?>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-8 border-t border-slate-200 pt-10">
        <!-- Personalización de Marca -->
        <div class="space-y-6 flex flex-col justify-between">
            <div>
                <h2 class="text-lg font-bold text-slate-800 tracking-tight">Personalización de Marca</h2>
                <p class="text-xs text-slate-400">Modifica los detalles visuales y la identidad de la plataforma.</p>
            </div>

            <?php if ($flash_sys_ok): ?>
                <div class="flex items-center gap-3 p-3.5 bg-emerald-500/5 border border-emerald-500/10 rounded-2xl text-emerald-500 text-xs font-semibold">
                    <i class="fa-solid fa-circle-check text-[14px]"></i>
                    <span><?= htmlspecialchars($flash_sys_ok) ?></span>
                </div>
            <?php endif; ?>
            <?php if ($flash_sys_err): ?>
                <div class="flex items-center gap-3 p-3.5 bg-rose-500/5 border border-rose-500/10 rounded-2xl text-rose-500 text-xs font-semibold">
                    <i class="fa-solid fa-circle-xmark text-[14px]"></i>
                    <span><?= htmlspecialchars($flash_sys_err) ?></span>
                </div>
            <?php endif; ?>

            <div class="bg-white border border-slate-200 rounded-3xl p-6 h-full flex flex-col justify-between">
                <form autocomplete="off" action="guardar_sistema.php" method="POST" class="space-y-5 flex-1 flex flex-col justify-between">
                    <div class="space-y-4">
                        <div class="flex items-center justify-between">
                            <div class="h-10 w-10 rounded-2xl bg-cyan-500/5 border border-cyan-500/10 text-cyan-500 flex items-center justify-center text-lg">
                                <i class="fa-solid fa-font"></i>
                            </div>
                            <span class="text-[9px] font-bold uppercase tracking-widest px-2.5 py-1 rounded-full bg-cyan-500/5 text-cyan-600 border border-cyan-500/10">Marca</span>
                        </div>
                        <div>
                            <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider block mb-1.5">Nombre de la Plataforma</label>
                            <input type="text" name="system_name" value="<?= htmlspecialchars($system_name) ?>" required
                                   class="w-full bg-slate-50 border border-slate-200 rounded-xl py-2 px-3 text-xs text-slate-800 focus:outline-none focus:border-cyan-500 transition">
                            <p class="text-[9px] text-slate-400 mt-1.5">Este nombre aparecerá en la cabecera superior y en la pantalla de inicio de sesión.</p>
                        </div>
                    </div>
                    <button type="submit" class="mt-6 w-full py-2.5 rounded-xl bg-slate-900 hover:bg-cyan-500 hover:text-slate-900 text-white font-bold text-xs transition duration-300 flex items-center justify-center gap-1.5">
                        <i class="fa-solid fa-floppy-disk text-[10px]"></i> Guardar Nombre
                    </button>
                </form>
            </div>
        </div>

        <!-- Asistente de IA (DeepSeek Config) -->
        <?php
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
        $flash_ai_ok  = $_SESSION['flash_ai_ok'] ?? null;
        $flash_ai_err = $_SESSION['flash_ai_err'] ?? null;
        unset($_SESSION['flash_ai_ok'], $_SESSION['flash_ai_err']);
        ?>

        <div class="space-y-6 flex flex-col justify-between">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-lg font-bold text-slate-800 tracking-tight">Asistente de IA</h2>
                    <p class="text-xs text-slate-400">Conecta el chatbot a modelos inteligentes.</p>
                </div>
                
                <!-- Status Badge -->
                <div class="flex items-center gap-1.5 px-3 py-1 rounded-full border text-[10px] font-bold <?= $ai_status === 'configurada' ? 'bg-indigo-500/5 border-indigo-500/20 text-indigo-500' : 'bg-slate-100 border-slate-200 text-slate-400' ?>">
                    <span class="h-1.5 w-1.5 rounded-full <?= $ai_status === 'configurada' ? 'bg-indigo-500 animate-pulse' : 'bg-slate-400' ?>"></span>
                    <?= $ai_status === 'configurada' ? 'CONECTADO' : 'SIN CONFIGURAR' ?>
                </div>
            </div>

            <?php if ($flash_ai_ok): ?>
                <div class="flex items-center gap-3 p-3.5 bg-emerald-500/5 border border-emerald-500/10 rounded-2xl text-emerald-500 text-xs font-semibold">
                    <i class="fa-solid fa-circle-check text-[14px]"></i>
                    <span><?= htmlspecialchars($flash_ai_ok) ?></span>
                </div>
            <?php endif; ?>
            <?php if ($flash_ai_err): ?>
                <div class="flex items-center gap-3 p-3.5 bg-rose-500/5 border border-rose-500/10 rounded-2xl text-rose-500 text-xs font-semibold">
                    <i class="fa-solid fa-circle-xmark text-[14px]"></i>
                    <span><?= htmlspecialchars($flash_ai_err) ?></span>
                </div>
            <?php endif; ?>

            <div class="bg-white border border-slate-200 rounded-3xl p-6 h-full flex flex-col justify-between">
                <form autocomplete="off" action="guardar_ai.php" method="POST" class="space-y-4 flex-1 flex flex-col justify-between">
                    <div class="space-y-3">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider block mb-1.5">Proveedor</label>
                                <select autocomplete="off" name="provider" id="ai-provider" onchange="updateModels()" class="w-full bg-slate-50 border border-slate-200 rounded-xl py-2 px-3 text-xs text-slate-800 focus:outline-none focus:border-indigo-500 transition">
                                    <option value="deepseek" <?= $ai_provider === 'deepseek' ? 'selected' : '' ?>>DeepSeek (Recomendado)</option>
                                    <option value="openai"   <?= $ai_provider === 'openai'   ? 'selected' : '' ?>>OpenAI</option>
                                    <option value="groq"     <?= $ai_provider === 'groq'     ? 'selected' : '' ?>>Groq</option>
                                    <option value="gemini"   <?= $ai_provider === 'gemini'   ? 'selected' : '' ?>>Google Gemini</option>
                                </select>
                            </div>
                            <div>
                                <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider block mb-1.5">API Key</label>
                                <div class="relative">
                                    <input type="password" name="api_key" id="ai-key-input"
                                           value="<?= htmlspecialchars($ai_api_key) ?>"
                                           placeholder="sk-xxxxxxxxxxxxxxxx"
                                           class="w-full bg-slate-50 border border-slate-200 rounded-xl py-2 pl-3 pr-10 text-xs text-slate-800 font-mono focus:outline-none focus:border-indigo-500 transition">
                                    <button type="button" onclick="toggleKeyVisibility()" 
                                            class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-indigo-500 transition">
                                        <i id="eye-icon" class="fa-solid fa-eye text-[12px]"></i>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div>
                            <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider block mb-1.5">Modelo de IA</label>
                            <select autocomplete="off" name="model" id="ai-model" data-selected="<?= htmlspecialchars($ai_model) ?>" class="w-full bg-slate-50 border border-slate-200 rounded-xl py-2 px-3 text-xs text-slate-800 focus:outline-none focus:border-indigo-500 transition">
                                <!-- Generado dinámicamente -->
                            </select>
                        </div>
                    </div>

                    <div class="pt-4 flex items-center gap-2.5 mt-auto">
                        <button type="submit" class="flex-1 py-2.5 rounded-xl bg-slate-900 text-white hover:bg-indigo-600 text-xs font-bold transition duration-300 flex items-center justify-center gap-1.5">
                            <i class="fa-solid fa-floppy-disk text-[10px]"></i> Guardar Configuración
                        </button>
                        
                        <?php if (!empty($ai_api_key)): ?>
                        </form>
                        <form autocomplete="off" action="guardar_ai.php" method="POST" class="inline shrink-0">
                            <input type="hidden" name="api_key" value="">
                            <input type="hidden" name="model" value="deepseek-chat">
                            <input type="hidden" name="action" value="delete">
                            <button type="submit" class="px-4 py-2.5 rounded-xl border border-rose-500/30 text-rose-500 hover:bg-rose-500/5 text-xs font-semibold transition flex items-center gap-1.5">
                                <i class="fa-solid fa-trash-can text-[10px]"></i>
                            </button>
                        </form>
                        <?php else: ?>
                        </form>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Sección 3: Mantenimiento (2 Columnas Simétricas) -->
    <div class="space-y-6 border-t border-slate-200 pt-10">
        <div>
            <h2 class="text-lg font-bold text-slate-800 tracking-tight">Mantenimiento del Sistema</h2>
            <p class="text-xs text-slate-400">Herramientas de respaldo preventivo y limpieza de la base de datos.</p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
            <!-- Backup -->
            <div class="bg-white border border-slate-200 rounded-3xl p-6 hover:border-emerald-500/20 transition-all duration-300 flex flex-col justify-between group">
                <div class="space-y-4">
                    <div class="flex items-center justify-between">
                        <div class="h-10 w-10 rounded-2xl bg-emerald-500/5 border border-emerald-500/10 text-emerald-500 flex items-center justify-center text-lg transition-transform group-hover:scale-110 duration-300">
                            <i class="fa-solid fa-download"></i>
                        </div>
                        <span class="text-[9px] font-bold uppercase tracking-widest px-2.5 py-1 rounded-full bg-emerald-500/5 text-emerald-600 border border-emerald-500/10">Seguridad</span>
                    </div>
                    <div>
                        <h3 class="font-bold text-base text-slate-800 group-hover:text-emerald-600 transition-colors">Respaldar Base de Datos</h3>
                        <p class="text-xs text-slate-400 mt-1 leading-relaxed">Descarga un archivo .sql con toda la estructura y datos actuales del sistema. Recomendado antes de importar masivamente o reiniciar.</p>
                    </div>
                </div>
                
                <div class="mt-8">
                    <a href="backup.php" class="w-full py-2.5 rounded-xl bg-slate-100 hover:bg-emerald-550 hover:bg-emerald-500 hover:text-white text-slate-800 font-bold text-xs transition duration-300 text-center block">
                        Descargar Respaldo
                    </a>
                </div>
            </div>

            <!-- Reset -->
            <div class="bg-white border border-rose-250 rounded-3xl p-6 hover:border-rose-500/30 transition-all duration-300 flex flex-col justify-between group relative overflow-hidden">
                <div class="space-y-4">
                    <div class="flex items-center justify-between">
                        <div class="h-10 w-10 rounded-2xl bg-rose-500/5 border border-rose-500/10 text-rose-500 flex items-center justify-center text-lg transition-transform group-hover:scale-110 duration-300">
                            <i class="fa-solid fa-triangle-exclamation"></i>
                        </div>
                        <span class="text-[9px] font-bold uppercase tracking-widest px-2.5 py-1 rounded-full bg-rose-500/5 text-rose-600 border border-rose-500/10">Peligro</span>
                    </div>
                    <div>
                        <h3 class="font-bold text-base text-slate-800 group-hover:text-rose-600 transition-colors">Resetear Base de Datos</h3>
                        <p class="text-xs text-slate-400 mt-1 leading-relaxed">Borra permanentemente todos los registros (equipos, empleados, credenciales, m365). La cuenta de administrador actual no se borrará.</p>
                    </div>
                </div>
                
                <div class="mt-8">
                    <button type="button" onclick="document.getElementById('resetModal').classList.remove('hidden')" class="w-full py-2.5 rounded-xl bg-rose-500/5 hover:bg-rose-500 hover:text-white text-rose-500 font-bold text-xs transition duration-300 border border-rose-500/10">
                        Limpiar Sistema Completo
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Confirmación de Reseteo (Diseño Premium) -->
<div id="resetModal" class="fixed inset-0 z-[100] hidden flex items-center justify-center p-4">
    <!-- Overlay oscurecido con desenfoque -->
    <div class="absolute inset-0 bg-slate-950/70 backdrop-blur-sm transition-opacity" onclick="document.getElementById('resetModal').classList.add('hidden')"></div>
    
    <!-- Contenedor del Modal -->
    <div class="bg-white border border-rose-100 rounded-3xl p-6 w-full max-w-md shadow-2xl relative overflow-hidden animate-fade-in-up z-10">
        <div class="space-y-4">
            <div class="flex items-center gap-3">
                <div class="h-10 w-10 rounded-2xl bg-rose-500/5 text-rose-500 flex items-center justify-center border border-rose-500/10">
                    <i class="fa-solid fa-triangle-exclamation text-base"></i>
                </div>
                <h3 class="text-base font-bold text-slate-800">¿Borrar base de datos?</h3>
            </div>
            
            <p class="text-xs text-slate-500 leading-relaxed">
                Esta acción es definitiva y <b class="text-slate-800">no se puede deshacer</b>. Se eliminarán de forma permanente todos los equipos, credenciales, colaboradores y licencias. Tu usuario administrador permanecerá a salvo.
            </p>
        </div>
        
        <div class="mt-6 pt-4 border-t border-slate-100 flex gap-2 justify-end">
            <button type="button" onclick="document.getElementById('resetModal').classList.add('hidden')" class="px-4 py-2.5 rounded-xl border border-slate-200 text-slate-600 hover:bg-slate-50 font-bold text-xs transition duration-200">
                Cancelar
            </button>
            <form autocomplete="off" action="reset.php" method="POST" class="inline">
                <button type="submit" class="px-5 py-2.5 rounded-xl bg-rose-600 hover:bg-rose-500 text-white font-bold text-xs shadow-md transition duration-200">
                    Confirmar Borrado
                </button>
            </form>
        </div>
    </div>
</div>

<script>
const modelsByProvider = {
    'deepseek': [
        { value: 'deepseek-chat', label: 'deepseek-chat (Recomendado)' },
        { value: 'deepseek-reasoner', label: 'deepseek-reasoner' }
    ],
    'openai': [
        { value: 'gpt-4o-mini', label: 'gpt-4o-mini (Recomendado)' },
        { value: 'gpt-4o', label: 'gpt-4o' }
    ],
    'groq': [
        { value: 'llama3-70b-8192', label: 'Llama 3 70B (Recomendado)' },
        { value: 'llama3-8b-8192', label: 'Llama 3 8B' }
    ],
    'gemini': [
        { value: 'gemini-1.5-flash', label: 'Gemini 1.5 Flash (Recomendado)' },
        { value: 'gemini-1.5-pro', label: 'Gemini 1.5 Pro' }
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
        icon.className = 'fa-solid fa-eye-slash text-[12px]';
    } else {
        input.type = 'password';
        icon.className = 'fa-solid fa-eye text-[12px]';
    }
}

function updateFilename(input, elementId) {
    const file = input.files[0];
    const el = document.getElementById(elementId);
    if (file && el) {
        el.innerText = file.name;
    }
}

document.addEventListener('DOMContentLoaded', updateModels);
</script>

<!-- Libreria SheetJS para procesar Excel localmente -->
<script src="https://cdn.sheetjs.com/xlsx-latest/package/dist/xlsx.full.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('input[type="file"]').forEach(input => {
        input.addEventListener('change', async (e) => {
            const file = e.target.files[0];
            if (!file) return;
            
            if (file.name.endsWith('.xlsx') || file.name.endsWith('.xls')) {
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
                        
                        const csvFile = new File([csv], file.name.replace(/\.xlsx?$/, '.csv'), { type: 'text/csv' });
                        
                        const dataTransfer = new DataTransfer();
                        dataTransfer.items.add(csvFile);
                        input.files = dataTransfer.files;
                        
                        btn.innerHTML = '<i class="fa-solid fa-check"></i> Listo';
                        btn.disabled = false;
                    } catch (error) {
                        alert("Error al procesar archivo Excel.");
                        btn.innerHTML = originalText;
                        btn.disabled = false;
                        input.value = "";
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
        from { opacity: 0; transform: translateY(8px) scale(0.98); }
        to { opacity: 1; transform: translateY(0) scale(1); }
    }
    .animate-fade-in-up { animation: fade-in-up 0.2s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
    
    @keyframes fade-in {
        from { opacity: 0; }
        to { opacity: 1; }
    }
    .animate-fade-in { animation: fade-in 0.4s ease-out forwards; }
</style>

<?php
require_once __DIR__ . '/../../includes/footer.php';
?>
