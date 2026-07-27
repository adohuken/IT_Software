<?php
/**
 * Generador de Plantillas CSV
 * Genera archivos .csv reales codificados en UTF-8 con BOM
 * para que sean compatibles con Excel de forma nativa.
 */

session_start();
require_once __DIR__ . '/../config/conexion.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}

$tipo = $_GET['tipo'] ?? '';

// ============================================================
// DEFINICIÓN DE PLANTILLAS
// ============================================================

switch ($tipo) {

    case 'equipos':
        $filename  = 'plantilla_equipos.csv';
        $headers   = ['Colaborador','Area','Equipo','Marca','Modelo','Serie','Procesador','Disco','Ram'];
        $instrucciones = [
            ['Colaborador',  'Nombre del colaborador para auto-asignarle el equipo'],
            ['Area',         'Área del colaborador'],
            ['Equipo',       'Laptop | Desktop | Servidor | Maquina Virtual | Switch | Firewall | Access Point'],
            ['Marca',        'Fabricante del equipo (ej: Dell, HP)'],
            ['Modelo',       'Modelo comercial del equipo'],
            ['Serie',        'OBLIGATORIO - Serial Number o Service Tag'],
            ['Procesador',   'Información del procesador'],
            ['Disco',        'Información del disco de almacenamiento'],
            ['Ram',          'Información de la memoria RAM'],
        ];
        $rows = [
            ['Carlos Antonio Mendoza Ruiz', 'Tecnología', 'Laptop', 'Dell', 'Latitude 5420', 'SN-DELL-00001', 'Core i5 11th Gen', '512GB SSD', '16GB'],
            ['María Fernanda López Cruz', 'Finanzas', 'Desktop', 'HP', 'ProDesk 400 G6', 'SN-HP-00002', 'Core i7 10th Gen', '1TB HDD', '8GB'],
            ['', '', 'Servidor', 'Dell', 'PowerEdge R740', 'SN-DELL-SRV-01', 'Dual Xeon', '4TB NVMe', '128GB'],
        ];
        break;

    case 'empleados':
        $filename  = 'plantilla_personal.csv';
        $headers   = ['Colaborador','Area','Cargo','Empresa','Sucursal','Correo','Pass Correo','Estado','PC PIN','Pass AG','Correo Gmai','Pass Gmail'];
        $instrucciones = [
            ['Colaborador',   'OBLIGATORIO - Nombre completo'],
            ['Area',          'Departamento o área funcional'],
            ['Cargo',         'Puesto de trabajo'],
            ['Empresa',       'Nombre de la empresa. Se creará si no existe.'],
            ['Sucursal',      'Sucursal asignada. Se creará si no existe.'],
            ['Correo',        'Correo corporativo principal'],
            ['Pass Correo',   'Contraseña del correo corporativo (se cifrará)'],
            ['Estado',        'Activo | Inactivo'],
            ['PC PIN',        'PIN de la computadora asignada'],
            ['Pass AG',       'Contraseña de Active Directory o similar (se cifrará)'],
            ['Correo Gmai',   'Correo Gmail o adicional'],
            ['Pass Gmail',    'Contraseña del correo Gmail (se cifrará)'],
        ];
        $rows = [
            ['Carlos Antonio Mendoza Ruiz', 'Tecnología', 'Analista de Sistemas', 'Mi Empresa', 'Sucursal Managua', 'carlos@empresa.com', 'Pass123!', 'Activo', '1234', 'AgPass12', 'carlos.mendoza@gmail.com', 'Gmail123!'],
            ['María Fernanda López Cruz', 'Finanzas', 'Contadora Senior', 'Mi Empresa', 'Sucursal León', 'maria@empresa.com', 'Pass123!', 'Activo', '0000', 'AgPass34', 'maria.lopez@gmail.com', 'Gmail456!'],
        ];
        break;

    case 'credenciales_equipos':
        $filename  = 'plantilla_credenciales_equipos.csv';
        $headers   = ['service_tag_sn','usuario_local','password','clave_bitlocker','notas_acceso'];
        $instrucciones = [
            ['service_tag_sn',  'OBLIGATORIO - Serial/Service Tag del equipo YA REGISTRADO en el sistema'],
            ['usuario_local',   'OBLIGATORIO - Usuario administrador local del equipo (ej: admin, soporte)'],
            ['password',        'OBLIGATORIO - Contraseña local. Se cifrará con AES-256 automáticamente.'],
            ['clave_bitlocker', 'Clave de recuperación BitLocker de 48 dígitos. Dejar vacio si no está activo.'],
            ['notas_acceso',    'RDP, SSH, VPN u otras indicaciones de acceso. Campo opcional.'],
        ];
        $rows = [
            ['SN-DELL-00001', 'admin_local',  'P@ssw0rd2026!',  '123456-789012-345678-901234-567890-12', 'Admin local. RDP habilitado en puerto 3389'],
            ['SN-HP-00002',   'soporte',      'Sop0rte2026#',   '',                                      'Sin BitLocker activo.'],
            ['SN-LNV-00005',  'administrador','Adm1n2026@Corp', '654321-098765-432109-876543-210987-65', 'BitLocker obligatorio. Laptop gerencia.'],
            ['SN-DELL-SRV-01','sysadmin',     'S3rv3r@Sec2026!','',                                      'Servidor Linux - acceso via SSH puerto 22'],
        ];
        break;

    case 'credenciales_web':
        $filename  = 'plantilla_credenciales_web.csv';
        $headers   = ['plataforma','url_acceso','usuario','password','notas'];
        $instrucciones = [
            ['plataforma',  'OBLIGATORIO - Nombre descriptivo del portal (ej: Portal AWS, Panel Cloudflare)'],
            ['url_acceso',  'URL completa del portal (ej: https://console.aws.amazon.com). Opcional.'],
            ['usuario',     'OBLIGATORIO - Correo o nombre de usuario de acceso'],
            ['password',    'OBLIGATORIO - Contraseña. Se cifrará con AES-256 automáticamente.'],
            ['notas',       'Descripción del uso, permisos o instrucciones adicionales. Opcional.'],
        ];
        $rows = [
            ['Portal AWS Console',       'https://console.aws.amazon.com',   'infraestructura@empresa.com',  'AWS@Corp2026!Pass',   'Cuenta root de AWS. Solo IT Senior.'],
            ['Panel Cloudflare',         'https://dash.cloudflare.com',       'dns@empresa.com',              'Cloud99!Dns2026',     'DNS, CDN y certificados SSL.'],
            ['Google Workspace Admin',   'https://admin.google.com',         'admin@empresa.com',            'GW@Admin2026Corp',   'Consola de Google Workspace.'],
            ['Portal GoDaddy',           'https://www.godaddy.com',           'dominios@empresa.com',         'GoD@ddy2026!',       'Dominios y renovaciones anuales.'],
            ['Microsoft Azure Portal',   'https://portal.azure.com',         'azure@empresa.onmicrosoft.com','Azure2026!SecOps',   'VMs y servicios cloud corporativos.'],
            ['Panel cPanel Hosting',     'https://hosting.proveedor.com:2083','cpanel_admin',                 'Host!ng2026@Panel',  'Control de hosting y correos.'],
        ];
        break;

    case 'm365':
        $filename  = 'plantilla_m365.csv';
        $headers   = ['correo','licencia_tipo','empleado_nombre','password','fecha_inicializacion','ciclo_facturacion','estado'];
        $instrucciones = [
            ['correo',               'OBLIGATORIO - Correo corporativo M365 unico (ej: juan@empresa.com)'],
            ['licencia_tipo',        'OBLIGATORIO - Plan de licencia (ej: Microsoft 365 Business Standard)'],
            ['empleado_nombre',      'Nombre del colaborador vinculado (debe existir en el sistema). Vacio = cuenta generica.'],
            ['password',             'Contraseña inicial de la cuenta M365. Se cifrará con AES-256.'],
            ['fecha_inicializacion', 'Fecha de inicio de suscripción en formato YYYY-MM-DD (ej: 2026-01-01)'],
            ['ciclo_facturacion',    'Mensual | Anual'],
            ['estado',               'Activa | Suspendida | Eliminada'],
        ];
        $rows = [
            ['carlos.mendoza@empresa.com',  'Microsoft 365 Business Standard', 'Carlos Antonio Mendoza Ruiz',  'M365!Corp2026@', date('Y-m-01'), 'Mensual', 'Activa'],
            ['maria.lopez@empresa.com',     'Microsoft 365 Business Basic',    'María Fernanda López Cruz',     'M365!Corp2026@', date('Y-m-01'), 'Mensual', 'Activa'],
            ['jose.perez@empresa.com',      'Microsoft 365 Apps for Business', 'José Alberto Pérez Sánchez',   'M365!Corp2026@', date('Y-m-01'), 'Anual',   'Activa'],
            ['ana.torres@empresa.com',      'Microsoft 365 Business Basic',    'Ana Cecilia Torres Mora',       'M365!Corp2026@', date('Y-m-01'), 'Mensual', 'Activa'],
            ['info@empresa.com',            'Microsoft 365 Business Basic',    '',                              'M365!Corp2026@', date('Y-m-01'), 'Mensual', 'Activa'],
        ];
        break;

    default:
        die("Tipo de plantilla no válido.");
}

// ============================================================
// GENERAR CSV REAL
// ============================================================

while (ob_get_level()) ob_end_clean();

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');

// Añadir BOM (Byte Order Mark) para que Excel reconozca UTF-8 y el delimitador correctamente
fputs($output, "\xEF\xBB\xBF");

// Escribir los encabezados
fputcsv($output, $headers, ';');

// Escribir las filas de ejemplo
foreach ($rows as $row) {
    fputcsv($output, $row, ';');
}

// Escribir un par de lineas en blanco de separación
fputcsv($output, [], ';');
fputcsv($output, [], ';');
fputcsv($output, ['--- INSTRUCCIONES DE COLUMNAS (PUEDES BORRAR ESTO) ---'], ';');

// Escribir las instrucciones para el usuario al final del archivo
foreach ($instrucciones as $inst) {
    fputcsv($output, [$inst[0], '=>', $inst[1]], ';');
}

fclose($output);
exit;
