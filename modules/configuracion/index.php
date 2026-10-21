<?php
/**
 * Módulo de Configuración e Importación Masiva de Datos (CSV)
 * Rediseño Simétrico y Profesional
 */

require_once __DIR__ . '/../../includes/header.php';

$tab_activa = $_GET['tab'] ?? 'todos';
?>

<div class="max-w-7xl mx-auto space-y-10 pb-16">
    <!-- Header Minimalista -->
    <div class="border-b border-slate-200 pb-6">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div>
                <span class="text-xs font-bold uppercase tracking-widest text-cyan-500">Panel de Control</span>
                <h1 class="text-3xl font-extrabold text-slate-900 tracking-tight mt-1 flex items-center gap-3">
                    Configuración del Sistema
                </h1>
                <p class="text-slate-500 text-sm mt-1">Administra la carga masiva de datos, marca del sistema,
                    mantenimiento e integración de IA.</p>
            </div>
            <!-- Quick status badge -->
            <div
                class="flex items-center gap-2 bg-slate-100 border border-slate-200 rounded-full px-4 py-1.5 self-start md:self-auto">
                <span class="h-2 w-2 rounded-full bg-emerald-500 animate-pulse"></span>
                <span class="text-xs font-semibold text-slate-600">Sistema Activo</span>
            </div>
        </div>
    </div>

    <!-- Guía Horizontal del Flujo de Trabajo (Sencilla y Elegante) -->
    <div class="bg-slate-50 border border-slate-200 rounded-3xl p-6">
        <div class="flex items-center gap-2 mb-4">
            <i class="fa-solid fa-circle-info text-cyan-500 text-sm"></i>
            <span class="text-xs font-extrabold uppercase tracking-wider text-slate-400">Flujo de Trabajo para
                Importación</span>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 text-xs text-slate-600">
            <div class="flex gap-3 items-start">
                <span
                    class="h-6 w-6 rounded-full bg-cyan-500/10 text-cyan-600 font-bold flex items-center justify-center shrink-0 border border-cyan-500/20">1</span>
                <div>
                    <p class="font-bold text-slate-800 mb-0.5">Descargar Plantilla</p>
                    <p class="text-[11px] leading-relaxed text-slate-400">Obtén la plantilla en Excel o CSV de la
                        categoría que deseas importar.</p>
                </div>
            </div>
            <div class="flex gap-3 items-start">
                <span
                    class="h-6 w-6 rounded-full bg-cyan-500/10 text-cyan-600 font-bold flex items-center justify-center shrink-0 border border-cyan-500/20">2</span>
                <div>
                    <p class="font-bold text-slate-800 mb-0.5">Completar Datos</p>
                    <p class="text-[11px] leading-relaxed text-slate-400">Abre el archivo, rellena las filas
                        correspondientes y guarda en formato CSV.</p>
                </div>
            </div>
            <div class="flex gap-3 items-start">
                <span
                    class="h-6 w-6 rounded-full bg-cyan-500/10 text-cyan-600 font-bold flex items-center justify-center shrink-0 border border-cyan-500/20">3</span>
                <div>
                    <p class="font-bold text-slate-800 mb-0.5">Subir y Procesar</p>
                    <p class="text-[11px] leading-relaxed text-slate-400">Sube el CSV. El servidor procesará y cifrará
                        los accesos con AES-256 local.</p>
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
            <!-- Tarjeta 1: Importación Equipos -->
            <div
                class="bg-white border border-slate-200 rounded-3xl p-6 hover:shadow-2xl hover:border-cyan-500/30 transition-all duration-300 flex flex-col justify-between group">
                <div class="space-y-4">
                    <div class="flex items-center justify-between">
                        <div
                            class="h-10 w-10 rounded-2xl bg-cyan-500/5 border border-cyan-500/10 text-cyan-500 flex items-center justify-center text-lg transition-transform group-hover:scale-110 duration-300">
                            <i class="fa-solid fa-laptop-code"></i>
                        </div>
                        <span
                            class="text-[9px] font-bold uppercase tracking-widest px-2.5 py-1 rounded-full bg-cyan-500/5 text-cyan-600 border border-cyan-500/10">Hardware</span>
                    </div>
                    <div>
                        <h3 class="font-bold text-base text-slate-800 group-hover:text-cyan-600 transition-colors">
                            Equipos e Inventario Hardware</h3>
                        <p class="text-xs text-slate-400 mt-1 leading-relaxed">Carga masiva de laptops, desktops, impresoras y asignaciones por colaborador.</p>
                    </div>
                </div>

                <form autocomplete="off" action="../equipos/guardar.php?action=import_csv&redirect=config" method="POST"
                    enctype="multipart/form-data" class="mt-8 space-y-4">
                    <div class="relative group/drop">
                        <input type="file" name="archivo_csv" accept=".csv, .xlsx, .xls" required
                            class="absolute inset-0 w-full h-full opacity-0 cursor-pointer z-10"
                            onchange="updateFilename(this, 'filename-equipos')">
                        <div
                            class="border border-dashed border-slate-200 rounded-2xl p-4 text-center group-hover/drop:border-cyan-400 transition bg-slate-50/50">
                            <i
                                class="fa-solid fa-cloud-arrow-up text-slate-400 text-lg group-hover/drop:text-cyan-500 transition mb-1"></i>
                            <p class="text-xs text-slate-500 font-medium" id="filename-equipos">Arrastra o selecciona tu archivo</p>
                            <span class="text-[9px] text-slate-400">CSV, XLSX o XLS</span>
                        </div>
                    </div>

                    <div class="flex items-center justify-between pt-2">
                        <a href="<?= BASE_URL ?>includes/plantilla.php?tipo=equipos"
                            class="text-xs font-bold text-cyan-500 hover:text-cyan-600 flex items-center gap-1.5 transition">
                            <i class="fa-solid fa-download text-[10px]"></i> Plantilla Equipos
                        </a>
                        <button type="submit"
                            class="px-4 py-2 rounded-xl bg-slate-900 text-white font-bold text-xs hover:bg-cyan-500 hover:text-slate-900 shadow-md transition duration-300">
                            Importar Equipos
                        </button>
                    </div>
                </form>
            </div>

            <!-- Tarjeta 2: Importación Infraestructura & Redes IP -->
            <div
                class="bg-white border border-slate-200 rounded-3xl p-6 hover:shadow-2xl hover:border-blue-500/30 transition-all duration-300 flex flex-col justify-between group">
                <div class="space-y-4">
                    <div class="flex items-center justify-between">
                        <div
                            class="h-10 w-10 rounded-2xl bg-blue-500/5 border border-blue-500/10 text-blue-500 flex items-center justify-center text-lg transition-transform group-hover:scale-110 duration-300">
                            <i class="fa-solid fa-network-wired"></i>
                        </div>
                        <span
                            class="text-[9px] font-bold uppercase tracking-widest px-2.5 py-1 rounded-full bg-blue-500/5 text-blue-600 border border-blue-500/10">Infraestructura IP</span>
                    </div>
                    <div>
                        <h3 class="font-bold text-base text-slate-800 group-hover:text-blue-600 transition-colors">
                            Infraestructura & Redes IP</h3>
                        <p class="text-xs text-slate-400 mt-1 leading-relaxed">Carga masiva de Switches, Access Points (AP), Servidores Físicos/Virtuales, Firewalls y Racks con credenciales cifradas.</p>
                    </div>
                </div>

                <form autocomplete="off" action="../infraestructura/guardar.php?action=import_infra_csv&redirect=config" method="POST"
                    enctype="multipart/form-data" class="mt-8 space-y-4">
                    <div class="relative group/drop">
                        <input type="file" name="archivo_csv" accept=".csv, .xlsx, .xls" required
                            class="absolute inset-0 w-full h-full opacity-0 cursor-pointer z-10"
                            onchange="updateFilename(this, 'filename-infra')">
                        <div
                            class="border border-dashed border-slate-200 rounded-2xl p-4 text-center group-hover/drop:border-blue-400 transition bg-slate-50/50">
                            <i
                                class="fa-solid fa-cloud-arrow-up text-slate-400 text-lg group-hover/drop:text-blue-500 transition mb-1"></i>
                            <p class="text-xs text-slate-500 font-medium" id="filename-infra">Arrastra o selecciona tu archivo</p>
                            <span class="text-[9px] text-slate-400">CSV, XLSX o XLS</span>
                        </div>
                    </div>

                    <div class="flex items-center justify-between pt-2">
                        <a href="<?= BASE_URL ?>includes/plantilla.php?tipo=infraestructura"
                            class="text-xs font-bold text-blue-500 hover:text-blue-600 flex items-center gap-1.5 transition">
                            <i class="fa-solid fa-download text-[10px]"></i> Plantilla Infraestructura
                        </a>
                        <button type="submit"
                            class="px-4 py-2 rounded-xl bg-slate-900 text-white font-bold text-xs hover:bg-blue-600 hover:text-white shadow-md transition duration-300">
                            Importar Redes IP
                        </button>
                    </div>
                </form>
            </div>

            <!-- Tarjeta 3: Importación Personal -->
            <div
                class="bg-white border border-slate-200 rounded-3xl p-6 hover:shadow-2xl hover:border-emerald-500/30 transition-all duration-300 flex flex-col justify-between group">
                <div class="space-y-4">
                    <div class="flex items-center justify-between">
                        <div
                            class="h-10 w-10 rounded-2xl bg-emerald-500/5 border border-emerald-500/10 text-emerald-500 flex items-center justify-center text-lg transition-transform group-hover:scale-110 duration-300">
                            <i class="fa-solid fa-user-check"></i>
                        </div>
                        <span
                            class="text-[9px] font-bold uppercase tracking-widest px-2.5 py-1 rounded-full bg-emerald-500/5 text-emerald-600 border border-emerald-500/10">Colaboradores</span>
                    </div>
                    <div>
                        <h3 class="font-bold text-base text-slate-800 group-hover:text-emerald-600 transition-colors">
                            Personal y Colaboradores</h3>
                        <p class="text-xs text-slate-400 mt-1 leading-relaxed">Carga masiva de colaboradores con sucursales, áreas, departamentos y cargos corporativos.</p>
                    </div>
                </div>

                <form autocomplete="off" action="../empleados/guardar.php?action=import_csv&redirect=config"
                    method="POST" enctype="multipart/form-data" class="mt-8 space-y-4">
                    <div class="relative group/drop">
                        <input type="file" name="archivo_csv" accept=".csv, .xlsx, .xls" required
                            class="absolute inset-0 w-full h-full opacity-0 cursor-pointer z-10"
                            onchange="updateFilename(this, 'filename-personal')">
                        <div
                            class="border border-dashed border-slate-200 rounded-2xl p-4 text-center group-hover/drop:border-emerald-400 transition bg-slate-50/50">
                            <i
                                class="fa-solid fa-cloud-arrow-up text-slate-400 text-lg group-hover/drop:text-emerald-500 transition mb-1"></i>
                            <p class="text-xs text-slate-500 font-medium" id="filename-personal">Arrastra o selecciona tu archivo</p>
                            <span class="text-[9px] text-slate-400">CSV, XLSX o XLS</span>
                        </div>
                    </div>

                    <div class="flex items-center justify-between pt-2">
                        <a href="<?= BASE_URL ?>includes/plantilla.php?tipo=empleados"
                            class="text-xs font-bold text-emerald-500 hover:text-emerald-600 flex items-center gap-1.5 transition">
                            <i class="fa-solid fa-download text-[10px]"></i> Plantilla Personal
                        </a>
                        <button type="submit"
                            class="px-4 py-2 rounded-xl bg-slate-900 text-white font-bold text-xs hover:bg-emerald-500 hover:text-white shadow-md transition duration-300">
                            Importar Personal
                        </button>
                    </div>
                </form>
            </div>

            <!-- Tarjeta 4: Importación Bóveda IT & Accesos Web -->
            <div
                class="bg-white border border-slate-200 rounded-3xl p-6 hover:shadow-2xl hover:border-indigo-500/30 transition-all duration-300 flex flex-col justify-between group">
                <div class="space-y-4">
                    <div class="flex items-center justify-between">
                        <div
                            class="h-10 w-10 rounded-2xl bg-indigo-500/5 border border-indigo-500/10 text-indigo-500 flex items-center justify-center text-lg transition-transform group-hover:scale-110 duration-300">
                            <i class="fa-solid fa-vault"></i>
                        </div>
                        <span
                            class="text-[9px] font-bold uppercase tracking-widest px-2.5 py-1 rounded-full bg-indigo-500/5 text-indigo-600 border border-indigo-500/10">Bóveda Cifrada IT</span>
                    </div>
                    <div>
                        <h3 class="font-bold text-base text-slate-800 group-hover:text-indigo-600 transition-colors">
                            Bóveda de Accesos IT & Web</h3>
                        <p class="text-xs text-slate-400 mt-1 leading-relaxed">Carga masiva de credenciales cifradas (AES-256) para portales web, routers, consolas cloud y servicios.</p>
                    </div>
                </div>

                <form autocomplete="off" action="../infraestructura/guardar.php?action=import_web_csv&redirect=config"
                    method="POST" enctype="multipart/form-data" class="mt-8 space-y-4">
                    <div class="relative group/drop">
                        <input type="file" name="archivo_csv" accept=".csv, .xlsx, .xls" required
                            class="absolute inset-0 w-full h-full opacity-0 cursor-pointer z-10"
                            onchange="updateFilename(this, 'filename-boveda')">
                        <div
                            class="border border-dashed border-slate-200 rounded-2xl p-4 text-center group-hover/drop:border-indigo-400 transition bg-slate-50/50">
                            <i
                                class="fa-solid fa-cloud-arrow-up text-slate-400 text-lg group-hover/drop:text-indigo-500 transition mb-1"></i>
                            <p class="text-xs text-slate-500 font-medium" id="filename-boveda">Arrastra o selecciona tu archivo</p>
                            <span class="text-[9px] text-slate-400">CSV, XLSX o XLS</span>
                        </div>
                    </div>

                    <div class="flex items-center justify-between pt-2">
                        <a href="<?= BASE_URL ?>includes/plantilla.php?tipo=credenciales_web"
                            class="text-xs font-bold text-indigo-500 hover:text-indigo-600 flex items-center gap-1.5 transition">
                            <i class="fa-solid fa-download text-[10px]"></i> Plantilla Bóveda IT
                        </a>
                        <button type="submit"
                            class="px-4 py-2 rounded-xl bg-slate-900 text-white font-bold text-xs hover:bg-indigo-600 hover:text-white shadow-md transition duration-300">
                            Importar Bóveda IT
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Sección 2: Ajustes y Mantenimiento -->
    <?php
    $flash_sys_ok = $_SESSION['flash_sys_ok'] ?? null;
    $flash_sys_err = $_SESSION['flash_sys_err'] ?? null;
    unset($_SESSION['flash_sys_ok'], $_SESSION['flash_sys_err']);
    ?>
    <div class="space-y-6 border-t border-slate-200 pt-10">
        <div>
            <h2 class="text-lg font-bold text-slate-800 tracking-tight">Ajustes del Sistema y Mantenimiento</h2>
            <p class="text-xs text-slate-400">Personaliza la plataforma y gestiona respaldos o reinicios de base de
                datos.</p>
        </div>

        <?php if ($flash_sys_ok): ?>
            <div
                class="flex items-center gap-3 p-3.5 bg-emerald-500/5 border border-emerald-500/10 rounded-2xl text-emerald-500 text-xs font-semibold w-full md:w-1/2">
                <i class="fa-solid fa-circle-check text-[14px]"></i>
                <span><?= htmlspecialchars($flash_sys_ok) ?></span>
            </div>
        <?php endif; ?>
        <?php if ($flash_sys_err): ?>
            <div
                class="flex items-center gap-3 p-3.5 bg-rose-500/5 border border-rose-500/10 rounded-2xl text-rose-500 text-xs font-semibold w-full md:w-1/2">
                <i class="fa-solid fa-circle-xmark text-[14px]"></i>
                <span><?= htmlspecialchars($flash_sys_err) ?></span>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
            <!-- Personalización de Marca -->
            <div
                class="bg-white border border-slate-200 rounded-3xl p-6 h-full flex flex-col justify-between hover:shadow-2xl hover:border-cyan-500/30 transition-all duration-300">
                <form autocomplete="off" action="guardar_sistema.php" method="POST"
                    class="space-y-5 flex-1 flex flex-col justify-between">
                    <div class="space-y-4">
                        <div class="flex items-center justify-between">
                            <div
                                class="h-10 w-10 rounded-2xl bg-cyan-500/5 border border-cyan-500/10 text-cyan-500 flex items-center justify-center text-lg transition-transform group-hover:scale-110 duration-300">
                                <i class="fa-solid fa-font"></i>
                            </div>
                            <span
                                class="text-[9px] font-bold uppercase tracking-widest px-2.5 py-1 rounded-full bg-cyan-500/5 text-cyan-600 border border-cyan-500/10">Marca</span>
                        </div>
                        <div>
                            <h3 class="font-bold text-base text-slate-800 transition-colors mb-4">Personalización</h3>
                            <label
                                class="text-[10px] font-bold text-slate-400 uppercase tracking-wider block mb-1.5">Nombre
                                de la Plataforma</label>
                            <input type="text" name="system_name" value="<?= htmlspecialchars($system_name) ?>" required
                                class="w-full bg-slate-50 border border-slate-200 rounded-xl py-2 px-3 text-xs text-slate-800 focus:outline-none focus:border-cyan-500 transition">
                            <p class="text-[9px] text-slate-400 mt-1.5 leading-relaxed">Aparecerá en la cabecera
                                superior y login.</p>
                        </div>
                    </div>
                    <button type="submit"
                        class="mt-6 w-full py-2.5 rounded-xl bg-slate-900 hover:bg-cyan-500 hover:text-slate-900 text-white font-bold text-xs transition duration-300 flex items-center justify-center gap-1.5">
                        <i class="fa-solid fa-floppy-disk text-[10px]"></i> Guardar Nombre
                    </button>
                </form>
            </div>
            <!-- Backup -->
            <div
                class="bg-white border border-slate-200 rounded-3xl p-6 hover:border-emerald-500/20 transition-all duration-300 flex flex-col justify-between group">
                <div class="space-y-4">
                    <div class="flex items-center justify-between">
                        <div
                            class="h-10 w-10 rounded-2xl bg-emerald-500/5 border border-emerald-500/10 text-emerald-500 flex items-center justify-center text-lg transition-transform group-hover:scale-110 duration-300">
                            <i class="fa-solid fa-download"></i>
                        </div>
                        <span
                            class="text-[9px] font-bold uppercase tracking-widest px-2.5 py-1 rounded-full bg-emerald-500/5 text-emerald-600 border border-emerald-500/10">Seguridad</span>
                    </div>
                    <div>
                        <h3 class="font-bold text-base text-slate-800 group-hover:text-emerald-600 transition-colors">
                            Respaldar Base de Datos</h3>
                        <p class="text-xs text-slate-400 mt-1 leading-relaxed">Descarga un archivo .sql con toda la
                            estructura y datos actuales del sistema. Recomendado antes de importar masivamente o
                            reiniciar.</p>
                    </div>
                </div>

                <div class="mt-8">
                    <a href="backup.php"
                        class="w-full py-2.5 rounded-xl bg-slate-100 hover:bg-emerald-550 hover:bg-emerald-500 hover:text-white text-slate-800 font-bold text-xs transition duration-300 text-center block">
                        Descargar Respaldo
                    </a>
                </div>
            </div>

            <!-- Restaurar Base de Datos -->
            <div
                class="bg-white border border-slate-200 rounded-3xl p-6 hover:border-indigo-500/20 transition-all duration-300 flex flex-col justify-between group">
                <div class="space-y-4">
                    <div class="flex items-center justify-between">
                        <div
                            class="h-10 w-10 rounded-2xl bg-indigo-500/5 border border-indigo-500/10 text-indigo-500 flex items-center justify-center text-lg transition-transform group-hover:scale-110 duration-300">
                            <i class="fa-solid fa-cloud-arrow-up"></i>
                        </div>
                        <span
                            class="text-[9px] font-bold uppercase tracking-widest px-2.5 py-1 rounded-full bg-indigo-500/5 text-indigo-600 border border-indigo-500/10">Restauración</span>
                    </div>
                    <div>
                        <h3 class="font-bold text-base text-slate-800 group-hover:text-indigo-600 transition-colors">
                            Restaurar Base de Datos</h3>
                        <p class="text-xs text-slate-400 mt-1 leading-relaxed">Sube tu archivo .sql de respaldo para restablecer toda la estructura y datos previos del sistema.</p>
                    </div>
                </div>

                <div class="mt-8">
                    <button type="button" onclick="document.getElementById('restoreModal').classList.remove('hidden')"
                        class="w-full py-2.5 rounded-xl bg-slate-100 hover:bg-indigo-600 hover:text-white text-slate-800 font-bold text-xs transition duration-300 text-center block">
                        Subir Respaldo .SQL
                    </button>
                </div>
            </div>

            <!-- Reset -->
            <div
                class="bg-white border border-rose-250 rounded-3xl p-6 hover:border-rose-500/30 transition-all duration-300 flex flex-col justify-between group relative overflow-hidden">
                <div class="space-y-4">
                    <div class="flex items-center justify-between">
                        <div
                            class="h-10 w-10 rounded-2xl bg-rose-500/5 border border-rose-500/10 text-rose-500 flex items-center justify-center text-lg transition-transform group-hover:scale-110 duration-300">
                            <i class="fa-solid fa-triangle-exclamation"></i>
                        </div>
                        <span
                            class="text-[9px] font-bold uppercase tracking-widest px-2.5 py-1 rounded-full bg-rose-500/5 text-rose-600 border border-rose-500/10">Peligro</span>
                    </div>
                    <div>
                        <h3 class="font-bold text-base text-slate-800 group-hover:text-rose-600 transition-colors">
                            Resetear Base de Datos</h3>
                        <p class="text-xs text-slate-400 mt-1 leading-relaxed">Borra permanentemente todos los registros
                            (equipos, empleados, credenciales, m365). La cuenta de administrador actual no se borrará.
                        </p>
                    </div>
                </div>

                <div class="mt-8">
                    <button type="button" onclick="document.getElementById('resetModal').classList.remove('hidden')"
                        class="w-full py-2.5 rounded-xl bg-rose-500/5 hover:bg-rose-500 hover:text-white text-rose-500 font-bold text-xs transition duration-300 border border-rose-500/10">
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
    <div class="absolute inset-0 bg-slate-950/70 backdrop-blur-sm transition-opacity"
        onclick="document.getElementById('resetModal').classList.add('hidden')"></div>

    <!-- Contenedor del Modal -->
    <div
        class="bg-white border border-rose-100 rounded-3xl p-6 w-full max-w-md shadow-2xl relative overflow-hidden animate-fade-in-up z-10">
        <div class="space-y-4">
            <div class="flex items-center gap-3">
                <div
                    class="h-10 w-10 rounded-2xl bg-rose-500/5 text-rose-500 flex items-center justify-center border border-rose-500/10">
                    <i class="fa-solid fa-triangle-exclamation text-base"></i>
                </div>
                <h3 class="text-base font-bold text-slate-800">¿Borrar base de datos?</h3>
            </div>

            <p class="text-xs text-slate-500 leading-relaxed">
                Esta acción es definitiva y <b class="text-slate-800">no se puede deshacer</b>. Se eliminarán de forma
                permanente todos los equipos, credenciales, colaboradores y licencias. Tu usuario administrador
                permanecerá a salvo.
            </p>
        </div>

        <div class="mt-6 pt-4 border-t border-slate-100 flex gap-2 justify-end">
            <button type="button" onclick="document.getElementById('resetModal').classList.add('hidden')"
                class="px-4 py-2.5 rounded-xl border border-slate-200 text-slate-600 hover:bg-slate-50 font-bold text-xs transition duration-200">
                Cancelar
            </button>
            <form autocomplete="off" action="reset.php" method="POST" class="inline">
                <button type="submit"
                    class="px-5 py-2.5 rounded-xl bg-rose-600 hover:bg-rose-500 text-white font-bold text-xs shadow-md transition duration-200">
                    Confirmar Borrado
                </button>
            </form>
        </div>
    </div>
</div>

<!-- Modal Confirmación de Restauración de Base de Datos -->
<div id="restoreModal" class="fixed inset-0 z-[100] hidden flex items-center justify-center p-4">
    <!-- Overlay oscurecido con desenfoque -->
    <div class="absolute inset-0 bg-slate-950/70 backdrop-blur-sm transition-opacity"
        onclick="document.getElementById('restoreModal').classList.add('hidden')"></div>

    <!-- Contenedor del Modal -->
    <div
        class="bg-white border border-indigo-100 rounded-3xl p-6 w-full max-w-md shadow-2xl relative overflow-hidden animate-fade-in-up z-10">
        <?php if (defined('IS_LOCAL') && !IS_LOCAL): ?>
            <!-- Información para entorno en línea (InfinityFree) -->
            <div class="space-y-4">
                <div class="flex items-center gap-3">
                    <div
                        class="h-10 w-10 rounded-2xl bg-indigo-500/5 text-indigo-500 flex items-center justify-center border border-indigo-500/10">
                        <i class="fa-solid fa-cloud text-base"></i>
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-slate-800">Restauración en la Nube</h3>
                        <p class="text-[11px] text-slate-400">Hosting InfinityFree (gestionit.xo.je)</p>
                    </div>
                </div>

                <div class="p-4 bg-amber-500/10 border border-amber-500/20 rounded-2xl space-y-2 text-xs text-amber-900 leading-relaxed">
                    <div class="flex items-center gap-2 font-bold text-amber-700">
                        <i class="fa-solid fa-shield-halved"></i>
                        <span>Firewall de Seguridad (OpenResty)</span>
                    </div>
                    <p class="text-[11px] text-slate-600">Por políticas de seguridad de InfinityFree, el servidor bloquea la carga de archivos <code>.sql</code> a través de formularios web directos (error <em>403 Forbidden</em>).</p>
                    <p class="font-bold text-slate-800 text-[11px] pt-1">Para restaurar en línea sigue estos pasos:</p>
                    <ol class="list-decimal pl-4 space-y-1.5 text-[11px] text-slate-700">
                        <li>Ingresa a tu panel en <a href="https://dash.infinityfree.com" target="_blank" class="underline text-indigo-600 font-bold hover:text-indigo-800">dash.infinityfree.com</a>.</li>
                        <li>Abre tu cuenta y entra a <strong>MySQL Databases</strong>.</li>
                        <li>Haz clic en el botón <strong>phpMyAdmin</strong> al lado de tu base de datos.</li>
                        <li>Entra a la pestaña <strong>Importar</strong>, selecciona tu archivo <code>.sql</code> y haz clic en <strong>Importar</strong>.</li>
                    </ol>
                </div>

                <div class="mt-6 pt-4 border-t border-slate-100 flex gap-2 justify-end">
                    <button type="button" onclick="document.getElementById('restoreModal').classList.add('hidden')"
                        class="px-5 py-2.5 rounded-xl bg-slate-900 hover:bg-slate-800 text-white font-bold text-xs transition duration-200">
                        Entendido
                    </button>
                </div>
            </div>
        <?php else: ?>
            <form autocomplete="off" action="restore.php" method="POST" enctype="multipart/form-data" onsubmit="document.getElementById('btnConfirmRestore').disabled = true; document.getElementById('btnConfirmRestore').innerHTML = '<i class=\'fa-solid fa-spinner fa-spin\'></i> Restaurando...';">
                <div class="space-y-4">
                    <div class="flex items-center gap-3">
                        <div
                            class="h-10 w-10 rounded-2xl bg-indigo-500/5 text-indigo-500 flex items-center justify-center border border-indigo-500/10">
                            <i class="fa-solid fa-cloud-arrow-up text-base"></i>
                        </div>
                        <div>
                            <h3 class="text-base font-bold text-slate-800">Restaurar Base de Datos</h3>
                            <p class="text-[11px] text-slate-400">Selecciona tu archivo .sql de respaldo</p>
                        </div>
                    </div>

                    <div class="p-3.5 bg-amber-500/5 border border-amber-500/15 rounded-2xl flex items-start gap-2.5">
                        <i class="fa-solid fa-triangle-exclamation text-amber-500 text-xs mt-0.5"></i>
                        <p class="text-[11px] text-amber-700 leading-relaxed">
                            Esta acción sobrescribirá las tablas y datos actuales con la información contenida en el archivo de respaldo seleccionado.
                        </p>
                    </div>

                    <div class="space-y-1.5">
                        <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">Archivo SQL de Respaldo</label>
                        <div class="relative border-2 border-dashed border-slate-200 hover:border-indigo-400 rounded-2xl p-5 text-center cursor-pointer transition bg-slate-50/50 group" onclick="document.getElementById('sql_file_input').click()">
                            <input type="file" id="sql_file_input" name="sql_file" accept=".sql" required class="hidden" onchange="updateSqlFilename(this)">
                            <div class="flex flex-col items-center justify-center gap-1.5">
                                <i class="fa-solid fa-file-invoice text-2xl text-slate-300 group-hover:text-indigo-500 transition-colors"></i>
                                <span id="sql_file_name_label" class="text-xs font-semibold text-slate-600 group-hover:text-indigo-600 transition-colors">Selecciona o arrastra tu archivo .sql</span>
                                <span class="text-[10px] text-slate-400">Ejemplo: gestion_it.sql</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-6 pt-4 border-t border-slate-100 flex gap-2 justify-end">
                    <button type="button" onclick="document.getElementById('restoreModal').classList.add('hidden')"
                        class="px-4 py-2.5 rounded-xl border border-slate-200 text-slate-600 hover:bg-slate-50 font-bold text-xs transition duration-200">
                        Cancelar
                    </button>
                    <button type="submit" id="btnConfirmRestore"
                        class="px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white font-bold text-xs shadow-md transition duration-200 flex items-center gap-1.5">
                        <i class="fa-solid fa-rotate text-[11px]"></i> Restaurar Ahora
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<script>
    function updateFilename(input, elementId) {
        const file = input.files[0];
        const el = document.getElementById(elementId);
        if (file && el) {
            el.innerText = file.name;
        }
    }

    function updateSqlFilename(input) {
        const file = input.files[0];
        const el = document.getElementById('sql_file_name_label');
        if (file && el) {
            const sizeKb = (file.size / 1024).toFixed(1);
            el.innerText = file.name + ' (' + sizeKb + ' KB)';
            el.classList.remove('text-slate-600');
            el.classList.add('text-indigo-600', 'font-bold');
        }
    }
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
                    reader.onload = function (e) {
                        try {
                            const data = new Uint8Array(e.target.result);
                            const workbook = XLSX.read(data, { type: 'array' });
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
        from {
            opacity: 0;
            transform: translateY(8px) scale(0.98);
        }

        to {
            opacity: 1;
            transform: translateY(0) scale(1);
        }
    }

    .animate-fade-in-up {
        animation: fade-in-up 0.2s cubic-bezier(0.16, 1, 0.3, 1) forwards;
    }

    @keyframes fade-in {
        from {
            opacity: 0;
        }

        to {
            opacity: 1;
        }
    }

    .animate-fade-in {
        animation: fade-in 0.4s ease-out forwards;
    }
</style>

<?php
require_once __DIR__ . '/../../includes/footer.php';
?>