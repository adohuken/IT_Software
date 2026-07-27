<?php
/**
 * Script de inicialización y poblamiento de la Base de Datos
 * Ejecutar localmente para crear las tablas e insertar datos de prueba.
 */

// Incluir archivos de conexión y seguridad
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/helpers.php';

try {
    // 1. Conexión inicial sin seleccionar base de datos para poder crearla si no existe
    $dsn_inicial = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";charset=utf8mb4";
    $pdo_inicial = new PDO($dsn_inicial, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    echo "Conectado al servidor MySQL...<br>";

    // Leer archivo SQL del esquema
    $sql_path = dirname(__DIR__) . '/database/schema.sql';
    if (!file_exists($sql_path)) {
        throw new Exception("No se encontró el archivo de esquema SQL en: $sql_path");
    }

    $sql_schema = file_get_contents($sql_path);
    
    // Ejecutar el esquema SQL (Creará la base de datos y todas las tablas)
    echo "Inicializando el esquema de base de datos...<br>";
    $pdo_inicial->exec($sql_schema);
    echo "Base de datos y tablas creadas con éxito.<br><br>";

    // 2. Conectar a la base de datos ya creada a través de la clase Database
    $db = Database::getConexion();

    // Limpiar tablas para evitar duplicados en la reinicialización
    echo "Limpiando tablas para poblamiento limpio...<br>";
    $db->exec("SET FOREIGN_KEY_CHECKS = 0;");
    $db->exec("TRUNCATE TABLE tareas_it;");
    $db->exec("TRUNCATE TABLE cuentas_m365;");
    $db->exec("TRUNCATE TABLE credenciales_web;");
    $db->exec("TRUNCATE TABLE credenciales_equipos;");
    $db->exec("TRUNCATE TABLE asignaciones;");
    $db->exec("TRUNCATE TABLE equipos;");
    $db->exec("TRUNCATE TABLE empleados;");
    $db->exec("TRUNCATE TABLE equipo_it;");
    $db->exec("TRUNCATE TABLE sucursales;");
    $db->exec("TRUNCATE TABLE empresas;");
    $db->exec("SET FOREIGN_KEY_CHECKS = 1;");

    // Cargar datos reales de Excel
    $excel_data = require __DIR__ . '/datos_excel.php';

    // A. Insertar Empresas y Sucursales dinámicamente
    $empresas_db = [
        'Mastertec' => null,
        'Suministros' => null,
        'Centro de Pinturas' => null
    ];
    
    // 3. Poblar Empresas
    echo "Insertando empresas reales...<br>";
    $stmt_emp = $db->prepare("INSERT INTO empresas (nombre) VALUES (?)");
    foreach (array_keys($empresas_db) as $emp_nombre) {
        $stmt_emp->execute([$emp_nombre]);
        $empresas_db[$emp_nombre] = $db->lastInsertId();
    }

    $sucursales_db = []; // Llave: 'Nombre de Hoja' => id_sucursal
    echo "Insertando sucursales reales...<br>";
    $stmt_suc = $db->prepare("INSERT INTO sucursales (empresa_id, nombre, ubicacion) VALUES (?, ?, ?)");
    
    $hojas_mapeo = [
        'Mastertec (Nicaragua)' => ['Mastertec', 'Central Managua', 'Sede Principal, Managua'],
        'Suministros (Nicaragua)' => ['Suministros', 'Suministros Nicaragua', 'Oficinas Suministros, Managua'],
        'Centro (Nicaragua)' => ['Centro de Pinturas', 'Centro Nicaragua', 'Oficinas Centro, Managua'],
        'Mastertec (Honduras)' => ['Mastertec', 'Honduras Branch', 'Oficinas Mastertec, Tegucigalpa'],
        'Suministros (Honduras)' => ['Suministros', 'Suministros Honduras', 'Bodegas Suministros, San Pedro Sula'],
        'Centro (El Salvador)' => ['Centro de Pinturas', 'Centro El Salvador', 'Sucursal San Salvador'],
        'Centro (Dominicana)' => ['Centro de Pinturas', 'Centro Dominicana', 'Sucursal Santo Domingo']
    ];

    foreach ($hojas_mapeo as $hoja => $info) {
        $emp_id = $empresas_db[$info[0]];
        $suc_nombre = $info[1];
        $suc_ubi = $info[2];
        $stmt_suc->execute([$emp_id, $suc_nombre, $suc_ubi]);
        $sucursales_db[$hoja] = $db->lastInsertId();
    }

    // 4. Poblar Personal de IT (mantenemos los administradores de pruebas)
    echo "Insertando personal de IT...<br>";
    $stmt_it = $db->prepare("INSERT INTO equipo_it (nombre, email, password_hash, rol, estado) VALUES (?, ?, ?, ?, ?)");
    $pass_hash = password_hash('AdminIT2026!', PASSWORD_BCRYPT);
    $stmt_it->execute(['Deyvi J. Martinez Abarca', 'dmartinez@mastertec.com.ni', $pass_hash, 'Administrador', 'Activo']);
    $stmt_it->execute(['Glendom Aleman', 'galeman@mastertec.com.ni', $pass_hash, 'Soporte', 'Activo']);
    $stmt_it->execute(['Bryan Rivas', 'brivas@mastertec.com.ni', $pass_hash, 'Desarrollo', 'Activo']);
    $stmt_it->execute(['Juan Campos', 'jcampos@mastertec.com.ni', $pass_hash, 'Soporte', 'Activo']);

    // 5. Poblar Empleados, Equipos, Asignaciones y Credenciales desde Excel
    echo "Poblando base de datos desde Excel...<br>";
    $stmt_emp_ins = $db->prepare("INSERT INTO empleados (sucursal_id, nombre, cargo, departamento, estado) VALUES (?, ?, ?, ?, 'Activo')");
    $stmt_eq_ins = $db->prepare("INSERT INTO equipos (sucursal_id, tipo, marca, modelo, service_tag_sn, ip_direccion, estado, notas) VALUES (?, ?, ?, ?, ?, null, ?, ?)");
    $stmt_asig_ins = $db->prepare("INSERT INTO asignaciones (equipo_id, empleado_id, fecha_entrega, condicion_entrega) VALUES (?, ?, ?, ?)");
    $stmt_cre_ins = $db->prepare("INSERT INTO credenciales_equipos (equipo_id, usuario_local, password_cifrado, notas_acceso) VALUES (?, ?, ?, ?)");
    $stmt_m365_ins = $db->prepare("INSERT INTO cuentas_m365 (empleado_id, correo, licencia_tipo, password_cifrado, fecha_inicializacion, ciclo_facturacion, fecha_proximo_pago, estado) VALUES (?, ?, ?, ?, ?, ?, ?, 'Activa')");

    $inserted_emails = [];

    foreach ($excel_data as $hoja => $empleados_excel) {
        $suc_id = $sucursales_db[$hoja] ?? null;
        if (!$suc_id) continue;

        foreach ($empleados_excel as $data) {
            // A. Insertar Empleado
            $cargo_emp = $data['departamento'] ?: 'Colaborador';
            $stmt_emp_ins->execute([$suc_id, $data['nombre'], $cargo_emp, $data['departamento']]);
            $empleado_id = $db->lastInsertId();

            // B. Crear y Vincular Equipo de Cómputo (si tiene credenciales o modelo específico)
            $tiene_eq = !empty($data['pin']) || !empty($data['bios']) || !empty($data['password_local']) || $data['equipo_modelo'] !== 'Generico';
            if ($tiene_eq) {
                $eq_modelo = $data['equipo_modelo'] ?: 'Latitude Generica';
                $eq_marca = $data['equipo_marca'] ?: 'Dell';
                $eq_sn = $data['equipo_sn'];
                $notas_eq = "Equipo importado desde Excel. " . ($data['notas'] ?? '');
                
                // Asegurar serial único
                try {
                    $stmt_eq_ins->execute([$suc_id, $data['equipo_tipo'], $eq_marca, $eq_modelo, $eq_sn, 'Asignado', $notas_eq]);
                } catch (Exception $e) {
                    $eq_sn = 'SN-' . rand(1000, 9999) . '-' . substr($eq_sn, -4);
                    $stmt_eq_ins->execute([$suc_id, $data['equipo_tipo'], $eq_marca, $eq_modelo, $eq_sn, 'Asignado', $notas_eq]);
                }
                $equipo_id = $db->lastInsertId();

                // Crear Asignación
                $stmt_asig_ins->execute([$equipo_id, $empleado_id, date('Y-m-d', strtotime('-3 months')), 'Carga inicial desde listado de Excel']);

                // Registrar credenciales del equipo cifradas
                $usr_local = $data['usuario_local'] ?: 'admin_local';
                $pwd_local_cifrado = Security::encrypt($data['password_local'] ?: 'PassLocal2026!');
                
                $notas_acceso = '';
                if (!empty($data['bios'])) $notas_acceso .= "BIOS: " . $data['bios'] . " | ";
                $notas_acceso = rtrim($notas_acceso, ' | ');

                $stmt_cre_ins->execute([$equipo_id, $usr_local, $pwd_local_cifrado, $notas_acceso]);
                
                // Si hay PIN de Windows, guardarlo como Credencial Personal
                if (!empty($data['pin'])) {
                    $pin_cifrado = Security::encrypt((string)$data['pin']);
                    $stmt_cp_ins = $db->prepare("INSERT INTO credenciales_personales (empleado_id, tipo, password_cifrado) VALUES (?, 'PC PIN', ?)");
                    $stmt_cp_ins->execute([$empleado_id, $pin_cifrado]);
                }
            }

            // C. Insertar Cuenta Microsoft 365
            if (!empty($data['correo_m365'])) {
                $correo = $data['correo_m365'];
                
                // Evitar duplicados exactos en M365
                if (!in_array($correo, $inserted_emails)) {
                    $inserted_emails[] = $correo;

                    $licencia = 'Microsoft 365 Business Standard';
                    if (strpos(strtolower($data['departamento']), 'geren') !== false || strpos(strtolower($data['nombre']), 'geren') !== false) {
                        $licencia = 'Microsoft 365 Business Premium';
                    }

                    $pwd_m365_cifrado = !empty($data['password_m365']) ? Security::encrypt($data['password_m365']) : null;
                    $fecha_init = date('Y-m-d', strtotime('-6 months'));
                    $fecha_pago = calcularProximoPago($fecha_init, 'Mensual');

                    $stmt_m365_ins->execute([
                        $empleado_id, 
                        $correo, 
                        $licencia, 
                        $pwd_m365_cifrado, 
                        $fecha_init, 
                        'Mensual', 
                        $fecha_pago
                    ]);
                }
            }
        }
    }

    // 10. Poblar Credenciales Web y Portales (IT Generales)
    echo "Insertando credenciales web cifradas...<br>";
    $stmt = $db->prepare("INSERT INTO credenciales_web (plataforma, url_acceso, usuario, password_cifrado, notas) VALUES (?, ?, ?, ?, ?)");
    
    $pass_m365_admin = Security::encrypt('M365Admin2026!');
    $stmt->execute([
        'Microsoft 365 Admin Center', 
        'https://admin.microsoft.com', 
        'admin-m365@mastertec.com.ni', 
        $pass_m365_admin, 
        'Requiere Microsoft Authenticator (MFA) para inicio de sesión.'
    ]);

    $pass_forticloud = Security::encrypt('FortiCloudPass!');
    $stmt->execute([
        'FortiCloud Support', 
        'https://support.fortinet.com', 
        'it-support@mastertec.com.ni', 
        $pass_forticloud, 
        'Para registros de garantías y tickets con fabricante.'
    ]);

    // 12. Poblar Tareas IT
    echo "Insertando tareas de IT...<br>";
    $stmt = $db->prepare("INSERT INTO tareas_it (titulo, descripcion, asignado_a, fecha_creacion, fecha_limite, es_repetitiva, frecuencia_dias, estado) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    
    // Tarea 1: Respaldo de BD. Vence mañana
    $stmt->execute([
        'Respaldo mensual de base de datos',
        'Generar respaldo completo del servidor SQL de producción y subir al almacenamiento offline.',
        1, // Deyvi
        date('Y-m-d H:i:s', strtotime('-5 days')),
        date('Y-m-d', strtotime('+1 days')),
        true,
        30,
        'Pendiente'
    ]);

    // Tarea 2: Logs del firewall. Vence hoy (En Progreso)
    $stmt->execute([
        'Revisión periódica de logs del Firewall',
        'Auditar accesos denegados y alertas de intrusión en el FortiGate de Managua.',
        2, // Glendom
        date('Y-m-d H:i:s', strtotime('-2 days')),
        date('Y-m-d'),
        true,
        7,
        'En Progreso'
    ]);

    // Tarea 3: Rack de comunicaciones. Vencida hace 3 días
    $stmt->execute([
        'Mantenimiento preventivo a Rack de Comunicaciones',
        'Limpieza física, revisión de UPS y etiquetado de patch panels.',
        4, // Juan
        date('Y-m-d H:i:s', strtotime('-10 days')),
        date('Y-m-d', strtotime('-3 days')),
        false,
        null,
        'Vencida'
    ]);

    // Tarea 4: Actualización SO. Vence en 5 días
    $stmt->execute([
        'Actualización de Sistema Operativo en Servidores VM',
        'Aplicar parches acumulativos mensuales en Windows Server y Ubuntu.',
        3, // Bryan
        date('Y-m-d H:i:s', strtotime('-1 day')),
        date('Y-m-d', strtotime('+5 days')),
        true,
        30,
        'Pendiente'
    ]);

    // Tarea 5: Completada
    $stmt->execute([
        'Renovación de Licencia de Antivirus',
        'Gestionar orden de compra de ESET Endpoint Security.',
        1, // Deyvi
        date('Y-m-d H:i:s', strtotime('-15 days')),
        date('Y-m-d', strtotime('-1 day')),
        false,
        null,
        'Completada'
    ]);

    echo "<br><b>Poblamiento de base de datos finalizado correctamente.</b>";

} catch (Exception $e) {
    echo "<br><b style='color:red;'>Error en inicialización: " . $e->getMessage() . "</b>";
}
