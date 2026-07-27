<?php
/**
 * Módulo de Usuarios
 * Administra las cuentas de acceso al sistema (equipo_it)
 */

session_start();
require_once __DIR__ . '/../../config/conexion.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}

// Obtener todos los usuarios registrados
try {
    $db = Database::getConexion();
    $stmt = $db->query("SELECT * FROM equipo_it ORDER BY id ASC");
    $usuarios = $stmt->fetchAll();
} catch (Exception $e) {
    die("Error al consultar usuarios: " . $e->getMessage());
}

$current_page = 'usuarios';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="space-y-6">
    <!-- Encabezado del Módulo -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <h2 class="text-2xl font-bold text-slate-800 tracking-wide flex items-center gap-3">
                <i class="fa-solid fa-users-gear text-blue-600"></i>
                Usuarios del Sistema
            </h2>
            <p class="text-sm text-slate-500 mt-1">Gestión de cuentas administrativas y acceso al panel.</p>
        </div>
        
        <button onclick="document.getElementById('modalNuevoUsuario').classList.remove('hidden')" class="px-5 py-2.5 rounded-xl bg-blue-600 hover:bg-blue-700 text-white font-bold text-sm shadow-lg shadow-blue-500/20 transition flex items-center gap-2">
            <i class="fa-solid fa-user-plus"></i> Nuevo Usuario
        </button>
    </div>

    <!-- Tabla de Usuarios -->
    <div class="bg-white rounded-3xl border border-slate-200 shadow-xl overflow-hidden relative">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-slate-600">
                <thead class="bg-slate-50 text-xs uppercase font-semibold text-slate-500 border-b border-slate-200">
                    <tr>
                        <th class="px-6 py-4">ID</th>
                        <th class="px-6 py-4">Operador IT</th>
                        <th class="px-6 py-4">Correo Electrónico</th>
                        <th class="px-6 py-4 text-center">Rol</th>
                        <th class="px-6 py-4 text-center">Estado</th>
                        <th class="px-6 py-4 text-center">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if(empty($usuarios)): ?>
                        <tr>
                            <td colspan="6" class="px-6 py-8 text-center text-slate-400">
                                No se encontraron usuarios registrados en el sistema.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach($usuarios as $user): ?>
                            <tr class="hover:bg-slate-50/80 transition">
                                <td class="px-6 py-4 font-mono text-xs text-slate-400 font-bold">
                                    #<?= htmlspecialchars(str_pad($user['id'], 3, '0', STR_PAD_LEFT)) ?>
                                </td>
                                
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-3">
                                        <?php 
                                            $palabras = explode(' ', $user['nombre']);
                                            $iniciales = isset($palabras[0]) ? strtoupper(substr($palabras[0], 0, 1)) : 'U';
                                            if (isset($palabras[1])) {
                                                $iniciales .= strtoupper(substr($palabras[1], 0, 1));
                                            }
                                        ?>
                                        <div class="h-9 w-9 rounded-full bg-blue-100 border border-blue-300 flex items-center justify-center text-blue-700 text-xs font-bold shrink-0">
                                            <?= $iniciales ?>
                                        </div>
                                        <div>
                                            <div class="font-bold text-slate-800">
                                                <?= htmlspecialchars($user['nombre']) ?>
                                            </div>
                                            <?php if($user['id'] == $_SESSION['user_id']): ?>
                                                <span class="text-[10px] text-blue-600 font-semibold bg-blue-50 px-1.5 py-0.5 rounded">Sesión Activa</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                
                                <td class="px-6 py-4 font-medium text-slate-700">
                                    <?= htmlspecialchars($user['email']) ?>
                                </td>
                                
                                <td class="px-6 py-4 text-center">
                                    <?php
                                        $badge_class = 'bg-slate-100 text-slate-600 border-slate-200';
                                        if ($user['rol'] === 'Administrador') {
                                            $badge_class = 'bg-blue-100 text-blue-700 border-blue-200';
                                        } elseif ($user['rol'] === 'Desarrollo') {
                                            $badge_class = 'bg-purple-100 text-purple-700 border-purple-200';
                                        } elseif ($user['rol'] === 'Soporte') {
                                            $badge_class = 'bg-emerald-100 text-emerald-700 border-emerald-200';
                                        }
                                    ?>
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-md text-[10px] font-bold uppercase tracking-wider border <?= $badge_class ?>">
                                        <?= htmlspecialchars($user['rol']) ?>
                                    </span>
                                </td>
                                
                                <td class="px-6 py-4 text-center">
                                    <?php if($user['estado'] === 'Activo'): ?>
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-600 border border-emerald-200">
                                            <span class="h-1.5 w-1.5 rounded-full bg-emerald-500 animate-pulse"></span> Activo
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-rose-50 text-rose-600 border border-rose-200">
                                            <span class="h-1.5 w-1.5 rounded-full bg-rose-500"></span> Inactivo
                                        </span>
                                    <?php endif; ?>
                                </td>
                                
                                <td class="px-6 py-4 text-center">
                                    <div class="flex items-center justify-center gap-2">
                                        <!-- Botón Editar -->
                                        <button onclick='abrirModalEditar(<?= json_encode($user, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' 
                                                class="p-2 rounded-lg text-blue-600 hover:bg-blue-50 transition border border-transparent hover:border-blue-200" 
                                                title="Editar Usuario">
                                            <i class="fa-solid fa-pen-to-square"></i>
                                        </button>

                                        <!-- Botón Cambiar Estado -->
                                        <?php if($user['id'] != $_SESSION['user_id']): ?>
                                            <form action="estado.php" method="POST" class="inline" onsubmit="return confirm('¿Estás seguro de cambiar el estado de este usuario?');">
                                                <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                                <input type="hidden" name="nuevo_estado" value="<?= $user['estado'] === 'Activo' ? 'Inactivo' : 'Activo' ?>">
                                                <button type="submit" 
                                                        class="p-2 rounded-lg transition border border-transparent <?= $user['estado'] === 'Activo' ? 'text-rose-500 hover:bg-rose-50 hover:border-rose-200' : 'text-emerald-600 hover:bg-emerald-50 hover:border-emerald-200' ?>"
                                                        title="<?= $user['estado'] === 'Activo' ? 'Desactivar Usuario' : 'Activar Usuario' ?>">
                                                    <i class="fa-solid <?= $user['estado'] === 'Activo' ? 'fa-ban' : 'fa-check' ?>"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
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

<!-- Modal Nuevo Usuario -->
<div id="modalNuevoUsuario" class="fixed inset-0 z-[100] hidden">
    <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm" onclick="document.getElementById('modalNuevoUsuario').classList.add('hidden')"></div>
    <div class="absolute inset-0 flex items-center justify-center p-4">
        <div class="bg-white border border-slate-200 rounded-2xl p-6 w-full max-w-md shadow-2xl relative animate-fade-in-up">
            <div class="flex items-center justify-between mb-4 border-b border-slate-100 pb-3">
                <h3 class="text-lg font-bold text-slate-800 flex items-center gap-2">
                    <i class="fa-solid fa-user-plus text-blue-600"></i> Registrar Nuevo Usuario
                </h3>
                <button onclick="document.getElementById('modalNuevoUsuario').classList.add('hidden')" class="text-slate-400 hover:text-slate-600">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            
            <form action="guardar.php" method="POST" class="space-y-4">
                <div>
                    <label class="text-xs font-semibold text-slate-600 uppercase tracking-wider block mb-1.5">Nombre Completo</label>
                    <input type="text" name="nombre" required placeholder="Ej. Ana Martínez"
                           class="w-full bg-slate-50 border border-slate-200 rounded-xl py-2.5 px-3.5 text-sm text-slate-800 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                </div>
                
                <div>
                    <label class="text-xs font-semibold text-slate-600 uppercase tracking-wider block mb-1.5">Correo Electrónico</label>
                    <input type="email" name="email" required placeholder="correo@empresa.com.ni"
                           class="w-full bg-slate-50 border border-slate-200 rounded-xl py-2.5 px-3.5 text-sm text-slate-800 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                </div>

                <div>
                    <label class="text-xs font-semibold text-slate-600 uppercase tracking-wider block mb-1.5">Contraseña de Acceso</label>
                    <input type="password" name="password" required placeholder="Mínimo 8 caracteres"
                           class="w-full bg-slate-50 border border-slate-200 rounded-xl py-2.5 px-3.5 text-sm text-slate-800 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                </div>
                
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="text-xs font-semibold text-slate-600 uppercase tracking-wider block mb-1.5">Rol de Sistema</label>
                        <select name="rol" required
                                class="w-full bg-slate-50 border border-slate-200 rounded-xl py-2.5 px-3 text-sm text-slate-800 focus:outline-none focus:border-blue-500">
                            <option value="Administrador">Administrador</option>
                            <option value="Soporte">Soporte IT</option>
                            <option value="Desarrollo">Desarrollo</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-slate-600 uppercase tracking-wider block mb-1.5">Estado Inicial</label>
                        <select name="estado" required
                                class="w-full bg-slate-50 border border-slate-200 rounded-xl py-2.5 px-3 text-sm text-slate-800 focus:outline-none focus:border-blue-500">
                            <option value="Activo">Activo</option>
                            <option value="Inactivo">Inactivo</option>
                        </select>
                    </div>
                </div>
                
                <div class="mt-6 flex gap-3 justify-end pt-4 border-t border-slate-100">
                    <button type="button" onclick="document.getElementById('modalNuevoUsuario').classList.add('hidden')" class="px-4 py-2 rounded-xl text-slate-600 hover:bg-slate-100 font-semibold text-sm transition">
                        Cancelar
                    </button>
                    <button type="submit" class="px-5 py-2 rounded-xl bg-blue-600 hover:bg-blue-700 text-white font-bold text-sm shadow-lg shadow-blue-500/20 transition">
                        Registrar Usuario
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Editar Usuario -->
<div id="modalEditarUsuario" class="fixed inset-0 z-[100] hidden">
    <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm" onclick="document.getElementById('modalEditarUsuario').classList.add('hidden')"></div>
    <div class="absolute inset-0 flex items-center justify-center p-4">
        <div class="bg-white border border-slate-200 rounded-2xl p-6 w-full max-w-md shadow-2xl relative animate-fade-in-up">
            <div class="flex items-center justify-between mb-4 border-b border-slate-100 pb-3">
                <h3 class="text-lg font-bold text-slate-800 flex items-center gap-2">
                    <i class="fa-solid fa-pen-to-square text-blue-600"></i> Editar Usuario
                </h3>
                <button onclick="document.getElementById('modalEditarUsuario').classList.add('hidden')" class="text-slate-400 hover:text-slate-600">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            
            <form action="guardar.php" method="POST" class="space-y-4">
                <input type="hidden" name="user_id" id="edit_user_id">

                <div>
                    <label class="text-xs font-semibold text-slate-600 uppercase tracking-wider block mb-1.5">Nombre Completo</label>
                    <input type="text" name="nombre" id="edit_nombre" required placeholder="Ej. Ana Martínez"
                           class="w-full bg-slate-50 border border-slate-200 rounded-xl py-2.5 px-3.5 text-sm text-slate-800 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                </div>
                
                <div>
                    <label class="text-xs font-semibold text-slate-600 uppercase tracking-wider block mb-1.5">Correo Electrónico</label>
                    <input type="email" name="email" id="edit_email" required placeholder="correo@empresa.com.ni"
                           class="w-full bg-slate-50 border border-slate-200 rounded-xl py-2.5 px-3.5 text-sm text-slate-800 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                </div>

                <div>
                    <label class="text-xs font-semibold text-slate-600 uppercase tracking-wider block mb-1.5">Nueva Contraseña</label>
                    <input type="password" name="password" id="edit_password" placeholder="Dejar en blanco para mantener actual"
                           class="w-full bg-slate-50 border border-slate-200 rounded-xl py-2.5 px-3.5 text-sm text-slate-800 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                    <span class="text-[11px] text-slate-400 mt-1 block">Opcional: llena este campo únicamente si deseas cambiar la clave.</span>
                </div>
                
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="text-xs font-semibold text-slate-600 uppercase tracking-wider block mb-1.5">Rol de Sistema</label>
                        <select name="rol" id="edit_rol" required
                                class="w-full bg-slate-50 border border-slate-200 rounded-xl py-2.5 px-3 text-sm text-slate-800 focus:outline-none focus:border-blue-500">
                            <option value="Administrador">Administrador</option>
                            <option value="Soporte">Soporte IT</option>
                            <option value="Desarrollo">Desarrollo</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-slate-600 uppercase tracking-wider block mb-1.5">Estado</label>
                        <select name="estado" id="edit_estado" required
                                class="w-full bg-slate-50 border border-slate-200 rounded-xl py-2.5 px-3 text-sm text-slate-800 focus:outline-none focus:border-blue-500">
                            <option value="Activo">Activo</option>
                            <option value="Inactivo">Inactivo</option>
                        </select>
                    </div>
                </div>
                
                <div class="mt-6 flex gap-3 justify-end pt-4 border-t border-slate-100">
                    <button type="button" onclick="document.getElementById('modalEditarUsuario').classList.add('hidden')" class="px-4 py-2 rounded-xl text-slate-600 hover:bg-slate-100 font-semibold text-sm transition">
                        Cancelar
                    </button>
                    <button type="submit" class="px-5 py-2 rounded-xl bg-blue-600 hover:bg-blue-700 text-white font-bold text-sm shadow-lg shadow-blue-500/20 transition">
                        Guardar Cambios
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
    document.getElementById('edit_password').value = '';
    document.getElementById('modalEditarUsuario').classList.remove('hidden');
}
</script>

<style>
    @keyframes fade-in-up {
        from { opacity: 0; transform: translateY(10px) scale(0.95); }
        to { opacity: 1; transform: translateY(0) scale(1); }
    }
    .animate-fade-in-up { animation: fade-in-up 0.2s ease-out forwards; }
</style>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>

