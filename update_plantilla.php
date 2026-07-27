<?php
$file = 'c:/xampp/htdocs/CuentasIT/includes/plantilla.php';
$content = file_get_contents($file);

$startMarkerEq = "case 'equipos':";
$endMarkerEq = "case 'empleados':";

$startMarkerEmp = "case 'empleados':";
$endMarkerEmp = "case 'credenciales_equipos':";

$posStartEq = strpos($content, $startMarkerEq);
$posEndEq = strpos($content, $endMarkerEq, $posStartEq);

if ($posStartEq === false || $posEndEq === false) {
    die("Eq Markers not found");
}

$newEqLogic = <<<PHP
case 'equipos':
        \$filename  = 'plantilla_equipos.csv';
        \$headers   = ['Colaborador','Area','Equipo','Marca','Modelo','Serie','Procesador','Disco','Ram'];
        \$instrucciones = [
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
        \$rows = [
            ['Carlos Antonio Mendoza Ruiz', 'Tecnología', 'Laptop', 'Dell', 'Latitude 5420', 'SN-DELL-00001', 'Core i5 11th Gen', '512GB SSD', '16GB'],
            ['María Fernanda López Cruz', 'Finanzas', 'Desktop', 'HP', 'ProDesk 400 G6', 'SN-HP-00002', 'Core i7 10th Gen', '1TB HDD', '8GB'],
            ['', '', 'Servidor', 'Dell', 'PowerEdge R740', 'SN-DELL-SRV-01', 'Dual Xeon', '4TB NVMe', '128GB'],
        ];
        break;

    
PHP;

$content = substr($content, 0, $posStartEq) . $newEqLogic . substr($content, $posEndEq);

$posStartEmp = strpos($content, $startMarkerEmp);
$posEndEmp = strpos($content, $endMarkerEmp, $posStartEmp);

if ($posStartEmp === false || $posEndEmp === false) {
    die("Emp Markers not found");
}

$newEmpLogic = <<<PHP
case 'empleados':
        \$filename  = 'plantilla_personal.csv';
        \$headers   = ['Colaborador','Area','Cargo','Empresa','Sucursal','Correo','Pass Correo','Estado','PC PIN','Pass AG','Correo Gmai','Pass Gmail'];
        \$instrucciones = [
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
        \$rows = [
            ['Carlos Antonio Mendoza Ruiz', 'Tecnología', 'Analista de Sistemas', 'Mi Empresa', 'Sucursal Managua', 'carlos@empresa.com', 'Pass123!', 'Activo', '1234', 'AgPass12', 'carlos.mendoza@gmail.com', 'Gmail123!'],
            ['María Fernanda López Cruz', 'Finanzas', 'Contadora Senior', 'Mi Empresa', 'Sucursal León', 'maria@empresa.com', 'Pass123!', 'Activo', '0000', 'AgPass34', 'maria.lopez@gmail.com', 'Gmail456!'],
        ];
        break;

    
PHP;

$content = substr($content, 0, $posStartEmp) . $newEmpLogic . substr($content, $posEndEmp);
file_put_contents($file, $content);
echo "Plantillas updated.\n";
?>
