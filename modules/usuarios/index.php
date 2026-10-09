<?php
/**
 * Módulo de Usuarios y Bóveda Compartida del Equipo de IT
 * Dashboard del equipo de sistemas, administración de operadores IT y almacén de accesos web cifrados.
 */

session_start();
require_once __DIR__ . '/../../config/conexion.php';
require_once __DIR__ . '/../../config/security.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}

$db = Database::getConexion();
$tab = $_GET['tab'] ?? 'equipo';

// 1. Cargar Usuarios de IT
try {
    $stmt = $db->query("SELECT * FROM equipo_it ORDER BY id ASC");
    $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    die("Error al consultar equipo de IT: " . $e->getMessage());
}

// 2. Cargar Credenciales Web y Portales de IT
try {
    $stmtWeb = $db->query("SELECT * FROM credenciales_web ORDER BY plataforma ASC");
    $cred_web_raw = $stmtWeb->fetchAll(PDO::FETCH_ASSOC);
    
    $cred_web = [];
    foreach ($cred_web_raw as $cw) {
        $cw['pass_plain'] = !empty($cw['password_cifrado']) ? Security::decrypt($cw['password_cifrado']) : '';
        $cred_web[] = $cw;
    }
} catch (Exception $e) {
    $cred_web = [];
}

$current_page = 'usuarios';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="space-y-6 animate-fade-in-up">
    <!-- Encabezado Principal del Módulo -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 bg-white/80 backdrop-blur-xl p-6 rounded-3xl border border-slate-200/80 shadow-sm">
        <div>
            <h2 class="text-2xl font-black text-slate-800 tracking-wide flex items-center gap-3">
                <i class="fa-solid fa-users-gear text-blue-600"></i>
                Dashboard del Equipo de IT & Bóveda de Accesos
            </h2>
            <p class="text-xs text-slate-500 mt-1">Gestión unificada de operadores del sistema, asignación de roles y boveda compartida de portales administrativos.</p>
        </div>
        
        <div class="flex items-center gap-2">
            <button onclick="document.getElementById('modalNuevoUsuario').classList.remove('hidden')" class="px-4 py-2.5 rounded-xl bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs shadow-lg shadow-blue-500/20 transition flex items-center gap-2">
                <i class="fa-solid fa-user-plus"></i> + Nuevo Integrante IT
            </button>
            <button onclick="document.getElementById('modalNuevaCredWeb').classList.remove('hidden')" class="px-4 py-2.5 rounded-xl bg-cyan-600 hover:bg-cyan-700 text-white font-bold text-xs shadow-lg shadow-cyan-500/20 transition flex items-center gap-2">
                <i class="fa-solid fa-globe"></i> + Nueva Credencial Web
            </button>
        </div>
    </div>

    <!-- Pestañas de Navegación del Módulo -->
    <div class="flex border-b border-slate-200 gap-6 text-sm font-bold">
        <a href="?tab=equipo" class="pb-3 flex items-center gap-2 border-b-2 transition <?= $tab === 'equipo' ? 'border-blue-600 text-blue-600' : 'border-transparent text-slate-400 hover:text-slate-600' ?>">
            <i class="fa-solid fa-user-shield"></i> Operadores de IT (<?= count($usuarios) ?>)
        </a>
        <a href="?tab=boveda" class="pb-3 flex items-center gap-2 border-b-2 transition <?= $tab === 'boveda' ? 'border-cyan-600 text-cyan-600' : 'border-transparent text-slate-400 hover:text-slate-600' ?>">
            <i class="fa-solid fa-vault"></i> Bóveda de Portales Web & Claves (<?= count($cred_web) ?>)
        </a>
    </div>

    <?php if ($tab === 'equipo'): ?>
        <!-- VISTA DE TARJETAS DE INTEGRANTES DEL EQUIPO DE IT -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php foreach ($usuarios as $user): 
                $isMainAdmin = ($user['id'] == 1 || strtolower(trim($user['nombre'])) === 'administrador it' || $user['email'] === 'admin@mastertec.com.ni');
                $isSelf = ($user['id'] == $_SESSION['user_id']);
                
                $palabras = explode(' ', $user['nombre']);
                $iniciales = isset($palabras[0]) ? strtoupper(substr($palabras[0], 0, 1)) : 'U';
                if (isset($palabras[1])) $iniciales .= strtoupper(substr($palabras[1], 0, 1));

                $roleBadge = 'bg-slate-100 text-slate-700 border-slate-200';
                if ($user['rol'] === 'Administrador') $roleBadge = 'bg-blue-50 text-blue-600 border-blue-200';
                elseif ($user['rol'] === 'Desarrollo') $roleBadge = 'bg-purple-50 text-purple-600 border-purple-200';
                elseif ($user['rol'] === 'Soporte') $roleBadge = 'bg-emerald-50 text-emerald-600 border-emerald-200';
            ?>
                <div class="bg-white/90 backdrop-blur-xl rounded-3xl p-6 border border-slate-200/80 shadow-sm hover:shadow-md hover:border-blue-300 transition-all duration-300 flex flex-col justify-between relative group overflow-hidden">
                    <div class="absolute -right-8 -top-8 w-24 h-24 rounded-full bg-blue-500/5 blur-xl group-hover:scale-150 transition-transform"></div>
                    
                    <div>
                        <div class="flex items-start justify-between gap-3 mb-4">
                            <div class="flex items-center gap-3">
                                <div class="h-12 w-12 rounded-2xl bg-gradient-to-tr from-blue-600 to-cyan-500 text-white flex items-center justify-center font-black text-sm shadow-md shadow-blue-500/20 shrink-0">
                                    <?= $iniciales ?>
                                </div>
                                <div>
                                    <h3 class="font-bold text-base text-slate-800 leading-snug flex items-center gap-2">
                                        <?= htmlspecialchars($user['nombre']) ?>
                                    </h3>
                                    <p class="text-xs text-slate-400 font-mono mt-0.5"><?= htmlspecialchars($user['email']) ?></p>
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center gap-2 mb-4">
                            <span class="px-2.5 py-1 rounded-xl text-[10px] font-bold uppercase tracking-wider border <?= $roleBadge ?>">
                                <i class="fa-solid fa-user-shield text-[9px] mr-1"></i> <?= htmlspecialchars($user['rol']) ?>
                            </span>
                            <?php if ($isSelf): ?>
                                <span class="px-2.5 py-1 rounded-xl text-[10px] font-bold bg-blue-50 text-blue-600 border border-blue-200">
                                    Sesión Activa
                                </span>
                            <?php endif; ?>
                            <?php if ($user['estado'] === 'Activo'): ?>
                                <span class="px-2.5 py-1 rounded-xl text-[10px] font-bold bg-emerald-50 text-emerald-600 border border-emerald-200 flex items-center gap-1">
                                    <span class="h-1.5 w-1.5 rounded-full bg-emerald-500 animate-pulse"></span> Activo
                                </span>
                            <?php else: ?>
                                <span class="px-2.5 py-1 rounded-xl text-[10px] font-bold bg-rose-50 text-rose-600 border border-rose-200">
                                    Inactivo
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="pt-4 border-t border-slate-100 flex items-center justify-between text-xs">
                        <span class="text-slate-400 font-mono text-[11px]">ID #<?= str_pad($user['id'], 3, '0', STR_PAD_LEFT) ?></span>
                        
                        <div class="flex items-center gap-1">
                            <?php if (!$isMainAdmin): ?>
                                <button onclick='abrirModalEditar(<?= json_encode($user, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' 
                                        class="px-3 py-1.5 rounded-xl text-blue-600 hover:bg-blue-50 border border-slate-200 text-xs font-bold transition flex items-center gap-1">
                                    <i class="fa-solid fa-pen-to-square"></i> Editar
                                </button>
                                <?php if (!$isSelf): ?>
                                    <form autocomplete="off" action="estado.php" method="POST" class="inline" onsubmit="return confirm('¿Desea cambiar el estado operativo de este usuario?');">
                                        <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                        <input type="hidden" name="nuevo_estado" value="<?= $user['estado'] === 'Activo' ? 'Inactivo' : 'Activo' ?>">
                                        <button type="submit" class="p-1.5 rounded-xl border border-slate-200 text-slate-500 hover:text-rose-600 hover:bg-rose-50 transition" title="Cambiar Estado">
                                            <i class="fa-solid fa-ban"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400 bg-slate-100 px-2 py-1 rounded-lg border border-slate-200">
                                    <i class="fa-solid fa-lock text-[9px]"></i> Protegido
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

    <?php else: ?>
        <!-- VISTA DE BÓVEDA DE CREDENCIALES DE PORTALES WEB Y SERVICIOS IT -->
        <div class="bg-white/90 backdrop-blur-xl rounded-3xl border border-slate-200/80 shadow-sm overflow-hidden">
            <div class="p-5 border-b border-slate-100 flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div>
                    <h3 class="font-bold text-slate-800 text-base flex items-center gap-2">
                        <i class="fa-solid fa-vault text-cyan-600"></i> Bóveda Compartida de Credenciales de IT
                    </h3>
                    <p class="text-xs text-slate-400 mt-0.5">Acceso centralizado a portales web, routers, proveedores y paneles de administración cifrados con AES-256.</p>
                </div>

                <div class="relative w-full md:w-72">
                    <input type="text" id="buscar_web" onkeyup="filtrarTablaWeb()" placeholder="Buscar portal, usuario..." class="w-full bg-slate-100 border border-slate-200 rounded-xl py-2 px-3.5 text-xs text-slate-800 focus:outline-none focus:border-cyan-400">
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs modern-table" id="tabla_web">
                    <thead class="bg-slate-50 border-b border-slate-200 text-slate-500 font-bold uppercase tracking-wider">
                        <tr>
                            <th class="py-3.5 px-4 w-12 text-center">#</th>
                            <th class="py-3.5 px-4">Plataforma / Portal</th>
                            <th class="py-3.5 px-4">Enlace de Acceso</th>
                            <th class="py-3.5 px-4">Usuario Admin</th>
                            <th class="py-3.5 px-4">Contraseña (AES-256)</th>
                            <th class="py-3.5 px-4">Notas</th>
                            <th class="py-3.5 px-4 text-center">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 text-slate-700">
                        <?php if (empty($cred_web)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-10 text-slate-400">
                                    No hay credenciales web o portales registrados en la bóveda de IT.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php $cwNum = 1; foreach ($cred_web as $cw): ?>
                                <tr class="hover:bg-cyan-50/40 transition">
                                    <td class="py-3.5 px-4 text-center font-mono font-bold text-slate-400"><?= $cwNum++ ?></td>
                                    
                                    <td class="py-3.5 px-4 font-bold text-slate-800">
                                        <div class="flex items-center gap-2">
                                            <i class="fa-solid fa-globe text-cyan-600 text-xs"></i>
                                            <?= htmlspecialchars($cw['plataforma']) ?>
                                        </div>
                                    </td>

                                    <td class="py-3.5 px-4 font-mono text-slate-600">
                                        <?php if (!empty($cw['url_acceso'])): ?>
                                            <a href="<?= htmlspecialchars($cw['url_acceso']) ?>" target="_blank" class="text-cyan-600 hover:underline flex items-center gap-1">
                                                <?= htmlspecialchars(mb_strimwidth($cw['url_acceso'], 0, 30, '...')) ?>
                                                <i class="fa-solid fa-arrow-up-right-from-square text-[9px]"></i>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-slate-400 italic">No definida</span>
                                        <?php endif; ?>
                                    </td>

                                    <td class="py-3.5 px-4 font-bold text-slate-800 select-all">
                                        <?= htmlspecialchars($cw['usuario']) ?>
                                    </td>

                                    <td class="py-3.5 px-4">
                                        <div class="flex items-center gap-2">
                                            <input type="password" readonly value="<?= htmlspecialchars($cw['pass_plain']) ?>" id="web_pass_<?= $cw['id'] ?>" class="bg-slate-100 border border-slate-200 rounded-lg py-1 px-2.5 text-xs font-mono text-slate-800 w-32 focus:outline-none">
                                            <button onclick="togglePassVisibility('web_pass_<?= $cw['id'] ?>', this)" class="p-1 text-slate-400 hover:text-slate-600" title="Mostrar/Ocultar">
                                                <i class="fa-solid fa-eye text-xs"></i>
                                            </button>
                                            <button onclick="copiarAlPortapapeles('<?= htmlspecialchars($cw['pass_plain'], ENT_QUOTES) ?>')" class="p-1 text-slate-400 hover:text-cyan-600" title="Copiar Contraseña">
                                                <i class="fa-solid fa-copy text-xs"></i>
                                            </button>
                                        </div>
                                    </td>

                                    <td class="py-3.5 px-4 text-slate-500 max-w-[150px] truncate" title="<?= htmlspecialchars($cw['notas'] ?? '') ?>">
                                        <?= htmlspecialchars($cw['notas'] ?: '-') ?>
                                    </td>

                                    <td class="py-3.5 px-4 text-center">
                                        <div class="flex items-center justify-center gap-1">
                                            <button onclick='abrirModalEditarWeb(<?= json_encode($cw, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' class="p-1.5 rounded-lg text-cyan-600 hover:bg-cyan-50 transition" title="Editar">
                                                <i class="fa-solid fa-pen-to-square"></i>
                                            </button>
                                            <a href="guardar.php?action=delete_web&id=<?= $cw['id'] ?>" onclick="return confirm('¿Desea eliminar esta credencial de la bóveda de IT?');" class="p-1.5 rounded-lg text-rose-500 hover:bg-rose-50 transition" title="Eliminar">
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
        </div>
    <?php endif; ?>
</div>

<!-- Modal Nuevo Integrante IT -->
<div id="modalNuevoUsuario" class="fixed inset-0 z-[100] hidden">
    <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm" onclick="document.getElementById('modalNuevoUsuario').classList.add('hidden')"></div>
    <div class="absolute inset-0 flex items-center justify-center p-4">
        <div class="bg-white border border-slate-200 rounded-3xl p-6 w-full max-w-md shadow-2xl relative animate-fade-in-up">
            <div class="flex items-center justify-between mb-4 border-b border-slate-100 pb-3">
                <h3 class="text-base font-bold text-slate-800 flex items-center gap-2">
                    <i class="fa-solid fa-user-plus text-blue-600"></i> Registrar Integrante del Equipo de IT
                </h3>
                <button onclick="document.getElementById('modalNuevoUsuario').classList.add('hidden')" class="text-slate-400 hover:text-slate-600">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            
            <form autocomplete="off" action="guardar.php" method="POST" class="space-y-4">
                <div>
                    <label class="text-xs font-semibold text-slate-600 uppercase tracking-wider block mb-1">Nombre Completo</label>
                    <input type="text" name="nombre" required placeholder="Ej. Bryan Rivas"
                           class="w-full bg-slate-50 border border-slate-200 rounded-xl py-2.5 px-3.5 text-xs text-slate-800 focus:outline-none focus:border-blue-500">
                </div>
                
                <div>
                    <label class="text-xs font-semibold text-slate-600 uppercase tracking-wider block mb-1">Correo Electrónico Corporativo</label>
                    <input type="email" name="email" required placeholder="correo@mastertec.com.ni"
                           class="w-full bg-slate-50 border border-slate-200 rounded-xl py-2.5 px-3.5 text-xs text-slate-800 focus:outline-none focus:border-blue-500">
                </div>

                <div>
                    <label class="text-xs font-semibold text-slate-600 uppercase tracking-wider block mb-1">Contraseña de Acceso</label>
                    <input type="password" name="password" required placeholder="Mínimo 8 caracteres"
                           class="w-full bg-slate-50 border border-slate-200 rounded-xl py-2.5 px-3.5 text-xs text-slate-800 focus:outline-none focus:border-blue-500">
                </div>
                
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="text-xs font-semibold text-slate-600 uppercase tracking-wider block mb-1">Rol Operativo IT</label>
                        <select autocomplete="off" name="rol" required class="w-full bg-slate-50 border border-slate-200 rounded-xl py-2.5 px-3 text-xs text-slate-800 focus:outline-none focus:border-blue-500">
                            <option value="Administrador">Administrador</option>
                            <option value="Soporte">Soporte IT</option>
                            <option value="Desarrollo">Desarrollo</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-slate-600 uppercase tracking-wider block mb-1">Estado Inicial</label>
                        <select autocomplete="off" name="estado" required class="w-full bg-slate-50 border border-slate-200 rounded-xl py-2.5 px-3 text-xs text-slate-800 focus:outline-none focus:border-blue-500">
                            <option value="Activo">Activo</option>
                            <option value="Inactivo">Inactivo</option>
                        </select>
                    </div>
                </div>
                
                <div class="mt-6 flex gap-3 justify-end pt-4 border-t border-slate-100">
                    <button type="button" onclick="document.getElementById('modalNuevoUsuario').classList.add('hidden')" class="px-4 py-2 rounded-xl text-slate-600 hover:bg-slate-100 font-semibold text-xs transition">
                        Cancelar
                    </button>
                    <button type="submit" class="px-5 py-2 rounded-xl bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs shadow-lg shadow-blue-500/20 transition">
                        Registrar Integrante
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Editar Integrante IT -->
<div id="modalEditarUsuario" class="fixed inset-0 z-[100] hidden">
    <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm" onclick="document.getElementById('modalEditarUsuario').classList.add('hidden')"></div>
    <div class="absolute inset-0 flex items-center justify-center p-4">
        <div class="bg-white border border-slate-200 rounded-3xl p-6 w-full max-w-md shadow-2xl relative animate-fade-in-up">
            <div class="flex items-center justify-between mb-4 border-b border-slate-100 pb-3">
                <h3 class="text-base font-bold text-slate-800 flex items-center gap-2">
                    <i class="fa-solid fa-pen-to-square text-blue-600"></i> Editar Integrante IT
                </h3>
                <button onclick="document.getElementById('modalEditarUsuario').classList.add('hidden')" class="text-slate-400 hover:text-slate-600">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            
            <form autocomplete="off" action="guardar.php" method="POST" class="space-y-4">
                <input type="hidden" name="user_id" id="edit_user_id">

                <div>
                    <label class="text-xs font-semibold text-slate-600 uppercase tracking-wider block mb-1">Nombre Completo</label>
                    <input type="text" name="nombre" id="edit_nombre" required class="w-full bg-slate-50 border border-slate-200 rounded-xl py-2.5 px-3.5 text-xs text-slate-800 focus:outline-none">
                </div>
                
                <div>
                    <label class="text-xs font-semibold text-slate-600 uppercase tracking-wider block mb-1">Correo Electrónico</label>
                    <input type="email" name="email" id="edit_email" required class="w-full bg-slate-50 border border-slate-200 rounded-xl py-2.5 px-3.5 text-xs text-slate-800 focus:outline-none">
                </div>

                <div>
                    <label class="text-xs font-semibold text-slate-600 uppercase tracking-wider block mb-1">Nueva Contraseña</label>
                    <input type="password" name="password" id="edit_password" placeholder="Opcional: Dejar en blanco para no cambiar" class="w-full bg-slate-50 border border-slate-200 rounded-xl py-2.5 px-3.5 text-xs text-slate-800 focus:outline-none">
                </div>
                
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="text-xs font-semibold text-slate-600 uppercase tracking-wider block mb-1">Rol</label>
                        <select autocomplete="off" name="rol" id="edit_rol" required class="w-full bg-slate-50 border border-slate-200 rounded-xl py-2.5 px-3 text-xs text-slate-800 focus:outline-none">
                            <option value="Administrador">Administrador</option>
                            <option value="Soporte">Soporte IT</option>
                            <option value="Desarrollo">Desarrollo</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-slate-600 uppercase tracking-wider block mb-1">Estado</label>
                        <select autocomplete="off" name="estado" id="edit_estado" required class="w-full bg-slate-50 border border-slate-200 rounded-xl py-2.5 px-3 text-xs text-slate-800 focus:outline-none">
                            <option value="Activo">Activo</option>
                            <option value="Inactivo">Inactivo</option>
                        </select>
                    </div>
                </div>
                
                <div class="mt-6 flex gap-3 justify-end pt-4 border-t border-slate-100">
                    <button type="button" onclick="document.getElementById('modalEditarUsuario').classList.add('hidden')" class="px-4 py-2 rounded-xl text-slate-600 hover:bg-slate-100 font-semibold text-xs transition">
                        Cancelar
                    </button>
                    <button type="submit" class="px-5 py-2 rounded-xl bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs shadow-lg shadow-blue-500/20 transition">
                        Guardar Cambios
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Nueva Credencial Web / IT -->
<div id="modalNuevaCredWeb" class="fixed inset-0 z-[100] hidden">
    <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm" onclick="document.getElementById('modalNuevaCredWeb').classList.add('hidden')"></div>
    <div class="absolute inset-0 flex items-center justify-center p-4">
        <div class="bg-white border border-slate-200 rounded-3xl p-6 w-full max-w-lg shadow-2xl relative animate-fade-in-up">
            <div class="flex items-center justify-between mb-4 border-b border-slate-100 pb-3">
                <h3 class="text-base font-bold text-slate-800 flex items-center gap-2">
                    <i class="fa-solid fa-globe text-cyan-600"></i> Registrar Credencial en Bóveda IT
                </h3>
                <button onclick="document.getElementById('modalNuevaCredWeb').classList.add('hidden')" class="text-slate-400 hover:text-slate-600">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            
            <form autocomplete="off" action="guardar.php?action=save_web" method="POST" class="space-y-4">
                <input type="hidden" name="web_id" id="web_edit_id">

                <div>
                    <label class="text-xs font-semibold text-slate-600 uppercase tracking-wider block mb-1">Plataforma / Portal Administrado</label>
                    <input type="text" name="plataforma" id="web_edit_plataforma" required placeholder="Ej. Portal cPanel Hostgator / Mikrotik Router"
                           class="w-full bg-slate-50 border border-slate-200 rounded-xl py-2.5 px-3.5 text-xs text-slate-800 focus:outline-none focus:border-cyan-500">
                </div>

                <div>
                    <label class="text-xs font-semibold text-slate-600 uppercase tracking-wider block mb-1">Enlace de Acceso (URL)</label>
                    <input type="url" name="url_acceso" id="web_edit_url" placeholder="https://admin.portal.com"
                           class="w-full bg-slate-50 border border-slate-200 rounded-xl py-2.5 px-3.5 text-xs text-slate-800 focus:outline-none focus:border-cyan-500">
                </div>
                
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="text-xs font-semibold text-slate-600 uppercase tracking-wider block mb-1">Usuario Admin</label>
                        <input type="text" name="usuario" id="web_edit_usuario" required placeholder="admin_it"
                               class="w-full bg-slate-50 border border-slate-200 rounded-xl py-2.5 px-3.5 text-xs text-slate-800 focus:outline-none focus:border-cyan-500">
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-slate-600 uppercase tracking-wider block mb-1">Contraseña (AES-256)</label>
                        <input type="password" name="password" id="web_edit_password" placeholder="Clave cifrada"
                               class="w-full bg-slate-50 border border-slate-200 rounded-xl py-2.5 px-3.5 text-xs text-slate-800 focus:outline-none focus:border-cyan-500">
                    </div>
                </div>

                <div>
                    <label class="text-xs font-semibold text-slate-600 uppercase tracking-wider block mb-1">Notas / Instrucciones de Uso</label>
                    <textarea name="notas" id="web_edit_notas" rows="2" placeholder="Acceso principal para administración de servidores..."
                              class="w-full bg-slate-50 border border-slate-200 rounded-xl py-2 px-3 text-xs text-slate-800 focus:outline-none focus:border-cyan-500"></textarea>
                </div>
                
                <div class="mt-6 flex gap-3 justify-end pt-4 border-t border-slate-100">
                    <button type="button" onclick="document.getElementById('modalNuevaCredWeb').classList.add('hidden')" class="px-4 py-2 rounded-xl text-slate-600 hover:bg-slate-100 font-semibold text-xs transition">
                        Cancelar
                    </button>
                    <button type="submit" class="px-5 py-2 rounded-xl bg-cyan-600 hover:bg-cyan-700 text-white font-bold text-xs shadow-lg shadow-cyan-500/20 transition">
                        Guardar en Bóveda IT
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    function abrirModalEditar(user) {
        document.getElementById('edit_user_id').value = user.id;
        document.getElementById('edit_nombre').value = user.nombre;
        document.getElementById('edit_email').value = user.email;
        document.getElementById('edit_rol').value = user.rol;
        document.getElementById('edit_estado').value = user.estado;
        document.getElementById('modalEditarUsuario').classList.remove('hidden');
    }

    function abrirModalEditarWeb(cw) {
        document.getElementById('web_edit_id').value = cw.id;
        document.getElementById('web_edit_plataforma').value = cw.plataforma;
        document.getElementById('web_edit_url').value = cw.url_acceso || '';
        document.getElementById('web_edit_usuario').value = cw.usuario;
        document.getElementById('web_edit_password').value = '';
        document.getElementById('web_edit_notas').value = cw.notas || '';
        document.getElementById('modalNuevaCredWeb').classList.remove('hidden');
    }

    function togglePassVisibility(id, btn) {
        const inp = document.getElementById(id);
        if (inp.type === 'password') {
            inp.type = 'text';
            btn.innerHTML = '<i class="fa-solid fa-eye-slash text-xs"></i>';
        } else {
            inp.type = 'password';
            btn.innerHTML = '<i class="fa-solid fa-eye text-xs"></i>';
        }
    }

    function copiarAlPortapapeles(txt) {
        navigator.clipboard.writeText(txt).then(() => {
            alert('¡Contraseña copiada al portapapeles!');
        });
    }

    function filtrarTablaWeb() {
        const input = document.getElementById('buscar_web');
        if (!input) return;
        const filter = input.value.toLowerCase().trim();
        const trs = document.querySelectorAll('#tabla_web tbody tr');
        trs.forEach(tr => {
            if (tr.innerText.toLowerCase().includes(filter)) tr.style.display = '';
            else tr.style.display = 'none';
        });
    }
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
