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
        $headers   = ['Colaborador','Area','Cargo','Empresa','Sucursal','Correo','Pass Correo','Estado','PC PIN','Pass AG','Correo Gmail','Pass Gmail'];
        $rows = [
            ['Carlos Antonio Mendoza Ruiz', 'Tecnología', 'Analista de Sistemas', 'Mastertec', 'Sucursal Managua', 'carlos@empresa.com', 'Pass123!', 'Activo', '1234', 'AgPass12', 'carlos.mendoza@gmail.com', 'Gmail123!'],
            ['María Fernanda López Cruz', 'Finanzas', 'Contadora Senior', 'Mastertec', 'Sucursal León', 'maria@empresa.com', 'Pass123!', 'Activo', '0000', 'AgPass34', 'maria.lopez@gmail.com', 'Gmail456!'],
        ];
        break;

    case 'credenciales_equipos':
        $filename  = 'plantilla_credenciales_equipos.csv';
        $headers   = ['service_tag_sn','usuario_local','password','clave_bitlocker','notas_acceso'];
        $rows = [
            ['SN-DELL-00001', 'admin_local',  'P@ssw0rd2026!',  '123456-789012-345678-901234-567890-12', 'Admin local. RDP habilitado en puerto 3389'],
            ['SN-HP-00002',   'soporte',      'Sop0rte2026#',   '',                                      'Sin BitLocker activo.'],
            ['SN-LNV-00005',  'administrador','Adm1n2026@Corp', '654321-098765-432109-876543-210987-65', 'BitLocker obligatorio. Laptop gerencia.'],
        ];
        break;

    case 'credenciales_web':
        $filename  = 'plantilla_credenciales_web.csv';
        $headers   = ['plataforma','url_acceso','usuario','password','notas'];
        $rows = [
            ['Portal AWS Console',       'https://console.aws.amazon.com',   'infraestructura@empresa.com',  'AWS@Corp2026!Pass',   'Cuenta root de AWS. Solo IT Senior.'],
            ['Panel Cloudflare',         'https://dash.cloudflare.com',       'dns@empresa.com',              'Cloud99!Dns2026',     'DNS, CDN y certificados SSL.'],
            ['Google Workspace Admin',   'https://admin.google.com',         'admin@empresa.com',            'GW@Admin2026Corp',   'Consola de Google Workspace.'],
        ];
        break;

    case 'm365':
        $filename  = 'plantilla_m365.csv';
        $headers   = ['correo','licencia_tipo','empleado_nombre','password','fecha_inicializacion','ciclo_facturacion','estado'];
        $rows = [
            ['carlos.mendoza@empresa.com',  'Microsoft 365 Business Standard', 'Carlos Antonio Mendoza Ruiz',  'M365!Corp2026@', date('Y-m-01'), 'Mensual', 'Activa'],
            ['maria.lopez@empresa.com',     'Microsoft 365 Business Basic',    'María Fernanda López Cruz',     'M365!Corp2026@', date('Y-m-01'), 'Mensual', 'Activa'],
        ];
        break;

    case 'infraestructura':
    case 'infraestructura_red':
    case 'infra':
        $filename  = 'plantilla_infraestructura_red.csv';
        $headers   = ['nombre_dispositivo','tipo','empresa','sucursal','ip_direccion','mascara_vlan','marca','modelo','serie_mac','puertos','usuario_admin','password_admin','url_gestion','ubicacion_rack','estado','notas'];
        $rows = [
            ['Switch-Core-Managua', 'Switch Managed', 'Mastertec', 'Sucursal Managua', '192.168.10.2', 'VLAN 10 (Datos)', 'Cisco', 'Catalyst 2960X-48FPS', 'FCW2145L09A', '48 PoE+', 'admin', 'Cisco2026!', 'https://192.168.10.2', 'Rack Principal - Piso 1', 'Operativo', 'Switch Core Principal'],
            ['AP-Recepcion-Leon', 'Access Point (AP)', 'Mastertec', 'Sucursal León', '192.168.20.15', 'VLAN 20 (WiFi)', 'Ubiquiti', 'UniFi U6-Pro', '74:83:C2:11:44:99', '1 GbE', 'admin', 'UniFiPass2026', 'https://192.168.20.1:8443', 'Cielo Falso', 'Operativo', 'AP Wi-Fi 6 alta densidad'],
            ['Server-ActiveDirectory', 'Servidor Físico', 'Mastertec', 'Sucursal Managua', '192.168.10.200', '255.255.255.0', 'Dell', 'PowerEdge R740', 'SN-DELL-SRV-01', '4x 10GbE', 'Administrator', 'ServerPass2026!', 'https://192.168.10.200', 'Gabinete Server', 'Operativo', 'Servidor de Dominio'],
            ['Firewall-FortiGate', 'Firewall / Router', 'Mastertec', 'Sucursal Managua', '192.168.10.1', 'WAN1 / VLAN 10,20', 'Fortinet', 'FortiGate 60F', 'FGT60FTK200912', '10 Ports', 'admin', 'FortiAdmin2026!', 'https://192.168.10.1:10443', 'Rack Principal U1', 'Operativo', 'Firewall Gateway'],
        ];
        break;

    default:
        die("Tipo de plantilla no válido.");
}

// ============================================================
// GENERAR CSV REAL Y LIMPIO
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

fclose($output);
exit;
