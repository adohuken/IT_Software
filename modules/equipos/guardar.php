<?php
/**
 * Controlador de Guardado para Módulo de Equipos
 * Procesa altas, bajas, asignaciones y devoluciones
 */

session_start();
require_once __DIR__ . '/../../config/conexion.php';

// Validar sesión activa
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}

$db = Database::getConexion();
$action = $_GET['action'] ?? '';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        
        // 1. Agregar Nuevo Equipo
        if ($action === 'add_eq') {
            $tipo = $_POST['tipo'];
            if ($tipo === '__otro__') {
                $tipo = trim($_POST['tipo_otro'] ?? 'Laptop');
            }
            if (empty($tipo)) $tipo = 'Laptop';
            $sucursal_id = (int)$_POST['sucursal_id'];
            $marca = trim($_POST['marca']);
            $modelo = trim($_POST['modelo']);
            $service_tag_sn = trim($_POST['service_tag_sn']);
            
            $ip_direccion = trim($_POST['ip_direccion'] ?? '') ?: null;
            $procesador = trim($_POST['procesador'] ?? '') ?: null;
            $ram = trim($_POST['ram'] ?? '') ?: null;
            $disco = trim($_POST['disco'] ?? '') ?: null;
            
            $proveedor_origen = trim($_POST['proveedor_origen'] ?? '');
            
            // Estado y Notas
            $notas = null;
            if (in_array($tipo, ['Impresora', 'Control de acceso', 'Switch', 'AP', 'Servidor'])) {
                $estado = 'Asignado'; // Ya están asignados a la sucursal al crearse
            } else {
                $estado = 'Disponible';
            }

            $usuario_local = trim($_POST['usuario_local'] ?? '');
            $password_local = trim($_POST['password_local'] ?? '');

            $db->beginTransaction();

            $stmt = $db->prepare("
                INSERT INTO equipos (sucursal_id, tipo, marca, modelo, service_tag_sn, ip_direccion, procesador, disco, ram, estado, notas, registrado_por, proveedor_origen) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$sucursal_id, $tipo, $marca, $modelo, $service_tag_sn, $ip_direccion, $procesador, $disco, $ram, $estado, $notas, $_SESSION['user_nombre'] ?? 'Sistema', $proveedor_origen]);
            $equipo_id = $db->lastInsertId();

            // Guardar Credenciales Locales si se proveen
            if (!empty($usuario_local) || !empty($password_local)) {
                $stmtCred = $db->prepare("
                    INSERT INTO credenciales_equipos (equipo_id, usuario_local, password_cifrado, clave_bitlocker, notas_acceso) 
                    VALUES (?, ?, ?, NULL, NULL)
                ");
                $stmtCred->execute([$equipo_id, $usuario_local ?: 'admin', $password_local]);
            }

            $db->commit();

            $_SESSION['flash_success'] = "Equipo registrado correctamente.";
            header('Location: index.php');
            exit;
        }

        // 1.5. Importar Equipos desde CSV o Excel (.xlsx)
        if ($action === 'import_csv') {
            if (!isset($_FILES['archivo_csv']) || $_FILES['archivo_csv']['error'] !== UPLOAD_ERR_OK) {
                $_SESSION['flash_error'] = "Debe seleccionar un archivo válido.";
                header('Location: index.php');
                exit;
            }

            $tmpPath = $_FILES['archivo_csv']['tmp_name'];
            $fileName = $_FILES['archivo_csv']['name'];
            $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

            // Cargar Mapa de Sucursales en BD (por ID y por Nombre)
            $stmtSucAll = $db->query("SELECT id, LOWER(nombre) as nombre_lower FROM sucursales");
            $sucursalesDbMap = [];
            while ($sRow = $stmtSucAll->fetch(PDO::FETCH_ASSOC)) {
                $sucursalesDbMap[$sRow['nombre_lower']] = (int)$sRow['id'];
            }

            // Sucursal por defecto
            $defaultSucursalId = 1;

            $itemsToImport = [];

            if ($ext === 'xlsx') {
                $zip = new ZipArchive();
                if ($zip->open($tmpPath) === TRUE) {
                    $wbXml = $zip->getFromName('xl/workbook.xml');
                    $wb = simplexml_load_string($wbXml);

                    $sheets = [];
                    foreach ($wb->sheets->sheet as $s) {
                        $sheets[] = [
                            'id' => (string)$s['sheetId'],
                            'rId' => (string)$s->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'],
                            'name' => (string)$s['name']
                        ];
                    }

                    $sharedStrings = [];
                    if (($ssXml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
                        $xml = simplexml_load_string($ssXml);
                        foreach ($xml->si as $val) {
                            if (isset($val->t)) {
                                $sharedStrings[] = (string)$val->t;
                            } elseif (isset($val->r)) {
                                $tStr = '';
                                foreach ($val->r as $r) $tStr .= (string)$r->t;
                                $sharedStrings[] = $tStr;
                            } else {
                                $sharedStrings[] = '';
                            }
                        }
                    }

                    $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
                    $rels = simplexml_load_string($relsXml);
                    $ridMap = [];
                    foreach ($rels->Relationship as $r) {
                        $ridMap[(string)$r['Id']] = (string)$r['Target'];
                    }

                    // Mapeo automático de nombres de Hojas a sucursales
                    $sheetSucursalMap = [
                        'master managua'      => 1, // Mastertec Managua
                        'master leon'         => 2, // Mastertec León
                        'centro'              => 8, // Centro de Pinturas Managua
                        'suministros managua' => 4, // Suministros Managua
                        'suministros sebaco'  => 6, // Suministros Sébaco
                        'suministros leon'    => 5  // Suministros León
                    ];

                    foreach ($sheets as $sh) {
                        $shName = trim($sh['name']);
                        $shLower = strtolower($shName);
                        $sucId = $sheetSucursalMap[$shLower] ?? $defaultSucursalId;

                        if (!isset($sheetSucursalMap[$shLower])) {
                            foreach ($sucursalesDbMap as $dbSucName => $dbSucId) {
                                if (strpos($dbSucName, $shLower) !== false || strpos($shLower, $dbSucName) !== false) {
                                    $sucId = $dbSucId;
                                    break;
                                }
                            }
                        }

                        $targetFile = 'xl/' . ($ridMap[$sh['rId']] ?? '');
                        $sheetXml = $zip->getFromName($targetFile);
                        if (!$sheetXml) continue;

                        $xml = simplexml_load_string($sheetXml);
                        foreach ($xml->sheetData->row as $r) {
                            $rowCells = [];
                            foreach ($r->c as $c) {
                                $rRef = (string)$c['r'];
                                $colLetter = preg_replace('/[0-9]/', '', $rRef);
                                $type = (string)$c['t'];
                                $val = (string)$c->v;
                                if ($type === 's' && isset($sharedStrings[(int)$val])) {
                                    $val = $sharedStrings[(int)$val];
                                }
                                $rowCells[$colLetter] = trim($val);
                            }

                            $colab  = $rowCells['A'] ?? '';
                            $area   = $rowCells['B'] ?? '';
                            $equipo = $rowCells['C'] ?? '';
                            $marca  = $rowCells['D'] ?? '';
                            $modelo = $rowCells['E'] ?? '';
                            $serie  = $rowCells['F'] ?? '';
                            $proc   = $rowCells['G'] ?? '';
                            $disco  = $rowCells['H'] ?? '';
                            $ram    = $rowCells['I'] ?? '';

                            if (strcasecmp($colab, 'Colaborador') === 0 && strcasecmp($area, 'Area') === 0) continue;
                            if (strcasecmp($colab, 'AREA') === 0 && strcasecmp($area, 'EQUIPO') === 0) continue;
                            if ($modelo === 'IMPRESORAS') continue;

                            if (!empty($modelo) && (strpos(strtolower($modelo), 'impresora') !== false || strpos(strtolower($modelo), 'laser') !== false)) {
                                $colab  = !empty($rowCells['F']) ? $rowCells['F'] : ($colab ?: 'Área Facturación');
                                $area   = $rowCells['G'] ?? 'Facturación / Cartera';
                                $equipo = 'Impresora';
                                $marca  = (strpos(strtolower($modelo), 'hp') !== false) ? 'HP' : ((strpos(strtolower($modelo), 'epson') !== false) ? 'Epson' : 'Impresora');
                                $serie  = '';
                            }

                            if ($area === 'MATRICIAL' || ($area === 'IMPRESORA' && empty($proc))) {
                                $colab  = $colab ?: 'Área Facturación';
                                $area   = 'Facturación';
                                $equipo = 'Impresora';
                                $marca  = $rowCells['C'] ?? ($rowCells['D'] ?? 'Epson');
                                $modelo = $rowCells['D'] ?? 'FX-890';
                                $serie  = $rowCells['E'] ?? '';
                            }

                            if (empty($colab) && empty($area) && empty($equipo) && empty($marca) && empty($serie) && empty($proc)) {
                                continue;
                            }

                            $itemsToImport[] = [
                                'sucursal_id' => $sucId,
                                'colaborador' => $colab,
                                'area' => $area,
                                'equipo' => $equipo,
                                'marca' => $marca,
                                'modelo' => $modelo,
                                'serie' => $serie,
                                'procesador' => $proc,
                                'disco' => $disco,
                                'ram' => $ram
                            ];
                        }
                    }
                    $zip->close();
                }
            } else {
                // CSV Parsing
                $rawContent = file_get_contents($tmpPath);
                $rawContent = str_replace(["\r\n", "\r"], "\n", $rawContent);
                $rawContent = preg_replace('/\xEF\xBB\xBF/', '', $rawContent);
                $allLines = explode("\n", $rawContent);

                $firstLine = '';
                foreach ($allLines as $line) {
                    if (trim($line) !== '' && strpos(trim($line), '#') !== 0) {
                        $firstLine = trim($line);
                        break;
                    }
                }

                $delimiter = (substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';
                $rawHeader = null;
                $dataLines = [];

                foreach ($allLines as $line) {
                    $line = trim($line);
                    if ($line === '' || strpos($line, '#') === 0) continue;
                    $rowTmp = str_getcsv($line, $delimiter);
                    if (empty($rowTmp) || (count($rowTmp) === 1 && trim($rowTmp[0]) === '')) continue;
                    if ($rawHeader === null) {
                        $rawHeader = $rowTmp;
                    } else {
                        $dataLines[] = $rowTmp;
                    }
                }

                if ($rawHeader) {
                    $header = array_map(function($col) {
                        return strtolower(trim(str_replace(' ', '_', $col)));
                    }, $rawHeader);

                    $colIdx = array_flip($header);
                    $findCol = function($candidates) use ($colIdx) {
                        foreach ($candidates as $cand) {
                            if (isset($colIdx[$cand])) return $colIdx[$cand];
                        }
                        return null;
                    };

                    $idxSerie = $findCol(['serie', 'service_tag_sn', 'service_tag', 'sn', 'numero_serie', 'n_serie', 's_n', 's/n']);
                    $idxMarca = $findCol(['marca', 'brand', 'fabricante']);
                    $idxModelo = $findCol(['modelo', 'model']);
                    $idxEquipo = $findCol(['equipo', 'tipo', 'tipo_equipo', 'dispositivo']);
                    $idxColab = $findCol(['colaborador', 'empleado', 'usuario', 'asignado_a', 'nombre']);
                    $idxProc  = $findCol(['procesador', 'cpu']);
                    $idxDisco = $findCol(['disco', 'almacenamiento', 'hd', 'ssd']);
                    $idxRam   = $findCol(['ram', 'memoria']);
                    $idxSuc   = $findCol(['sucursal', 'sucursal_nombre', 'branch']);

                    foreach ($dataLines as $data) {
                        $colaboradorVal = ($idxColab !== null) ? trim($data[$idxColab] ?? '') : '';
                        $tipoVal        = ($idxEquipo !== null) ? trim($data[$idxEquipo] ?? '') : '';
                        $marcaVal       = ($idxMarca !== null) ? trim($data[$idxMarca] ?? '') : '';
                        $modeloVal      = ($idxModelo !== null) ? trim($data[$idxModelo] ?? '') : '';
                        $tagVal         = ($idxSerie !== null) ? trim($data[$idxSerie] ?? '') : '';
                        $procVal        = ($idxProc !== null) ? trim($data[$idxProc] ?? '') : '';
                        $discoVal       = ($idxDisco !== null) ? trim($data[$idxDisco] ?? '') : '';
                        $ramVal         = ($idxRam !== null) ? trim($data[$idxRam] ?? '') : '';
                        $sucVal         = ($idxSuc !== null) ? trim($data[$idxSuc] ?? '') : '';

                        if (empty($colaboradorVal) && empty($tipoVal) && empty($marcaVal) && empty($modeloVal) && empty($tagVal)) continue;

                        $rowSucId = $defaultSucursalId;
                        if (!empty($sucVal)) {
                            $sucValLower = strtolower($sucVal);
                            if (isset($sucursalesDbMap[$sucValLower])) {
                                $rowSucId = $sucursalesDbMap[$sucValLower];
                            }
                        }

                        $itemsToImport[] = [
                            'sucursal_id' => $rowSucId,
                            'colaborador' => $colaboradorVal,
                            'area' => '',
                            'equipo' => $tipoVal,
                            'marca' => $marcaVal,
                            'modelo' => $modeloVal,
                            'serie' => $tagVal,
                            'procesador' => $procVal,
                            'disco' => $discoVal,
                            'ram' => $ramVal
                        ];
                    }
                }
            }

            if (empty($itemsToImport)) {
                $_SESSION['flash_error'] = "El archivo no contiene filas o datos válidos para importar.";
                header('Location: index.php');
                exit;
            }

            $db->beginTransaction();

            $exitos = 0;
            $actualizados = 0;
            $asignados = 0;
            $rowCounter = 1;
            $usedTagsInSession = [];

            $tiposPermitidos = ['Laptop', 'Desktop', 'Servidor', 'Maquina Virtual', 'Switch', 'Firewall', 'Access Point', 'AP', 'Impresora', 'Control de acceso', 'Otro'];
            $mapTipos = [
                'cpu'               => 'Desktop',
                'pc'                => 'Desktop',
                'computadora'       => 'Desktop',
                'desktop'           => 'Desktop',
                'laptop'            => 'Laptop',
                'portatil'          => 'Laptop',
                'servidor'          => 'Servidor',
                'server'            => 'Servidor',
                'maquina virtual'   => 'Maquina Virtual',
                'switch'            => 'Switch',
                'firewall'          => 'Firewall',
                'access point'      => 'AP',
                'ap'                => 'AP',
                'impresora'         => 'Impresora',
                'printer'           => 'Impresora',
                'matricial'         => 'Impresora',
                'celular'           => 'Celular',
                'telefono'          => 'Celular',
                'control de acceso' => 'Control de acceso',
                'ups'               => 'Otro'
            ];

            $stmtCheckTag = $db->prepare("SELECT id FROM equipos WHERE service_tag_sn = ?");
            $stmtInsertEq = $db->prepare("
                INSERT INTO equipos (sucursal_id, tipo, marca, modelo, service_tag_sn, procesador, disco, ram, estado, registrado_por) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmtUpdateEq = $db->prepare("
                UPDATE equipos 
                SET sucursal_id = COALESCE(?, sucursal_id),
                    tipo = COALESCE(NULLIF(?, ''), tipo),
                    marca = COALESCE(NULLIF(?, ''), marca),
                    modelo = COALESCE(NULLIF(?, ''), modelo),
                    procesador = COALESCE(?, procesador),
                    disco = COALESCE(?, disco),
                    ram = COALESCE(?, ram)
                WHERE id = ?
            ");

            foreach ($itemsToImport as $item) {
                $colaboradorVal = trim($item['colaborador']);
                $areaVal        = trim($item['area']);
                $tipoVal        = trim($item['equipo']);
                $marcaVal       = trim($item['marca']);
                $modeloVal      = trim($item['modelo']);
                $tagVal         = trim($item['serie']);
                $procVal        = trim($item['procesador']);
                $discoVal       = trim($item['disco']);
                $ramVal         = trim($item['ram']);
                $sucursalId     = (int)$item['sucursal_id'];

                $tipoNormalizado = 'Laptop';
                $tipoValLower = strtolower($tipoVal);
                if (isset($mapTipos[$tipoValLower])) {
                    $tipoNormalizado = $mapTipos[$tipoValLower];
                } else {
                    foreach ($tiposPermitidos as $t) {
                        if (strcasecmp($t, $tipoVal) === 0) {
                            $tipoNormalizado = $t;
                            break;
                        }
                    }
                }

                $tagValTrim = trim($tagVal);
                $tagValLower = strtolower($tagValTrim);
                $isClon = (empty($tagValTrim) || in_array($tagValLower, ['clon', 'clones', 'armado', 'sin serie', 'n/a', 'na', '-', 'none', 's/n']));

                if ($isClon) {
                    if (empty($marcaVal) || strcasecmp($marcaVal, 'Genérica') === 0) $marcaVal = 'Clon';
                    if (empty($modeloVal) || strcasecmp($modeloVal, 'Estándar') === 0) $modeloVal = 'Ensamblado';
                    
                    $tagVal = 'CLON-' . str_pad($rowCounter, 4, '0', STR_PAD_LEFT);
                    $stmtCheckTag->execute([$tagVal]);
                    while ($stmtCheckTag->fetchColumn()) {
                        $rowCounter++;
                        $tagVal = 'CLON-' . str_pad($rowCounter, 4, '0', STR_PAD_LEFT);
                        $stmtCheckTag->execute([$tagVal]);
                    }
                } else {
                    if (empty($marcaVal))  $marcaVal = 'Genérica';
                    if (empty($modeloVal)) $modeloVal = 'Estándar';

                    if (isset($usedTagsInSession[$tagVal])) {
                        $usedTagsInSession[$tagVal]++;
                        $tagVal = $tagVal . '-' . $usedTagsInSession[$tagVal];
                    } else {
                        $usedTagsInSession[$tagVal] = 1;
                    }
                }

                $stmtCheckTag->execute([$tagVal]);
                $equipoId = $stmtCheckTag->fetchColumn();

                $estadoNormalizado = !empty($colaboradorVal) ? 'Asignado' : 'Disponible';

                if ($equipoId) {
                    $stmtUpdateEq->execute([
                        $sucursalId,
                        $tipoNormalizado,
                        $marcaVal,
                        $modeloVal,
                        $procVal,
                        $discoVal,
                        $ramVal,
                        $equipoId
                    ]);
                    $actualizados++;
                } else {
                    $stmtInsertEq->execute([
                        $sucursalId,
                        $tipoNormalizado,
                        $marcaVal,
                        $modeloVal,
                        $tagVal,
                        $procVal,
                        $discoVal,
                        $ramVal,
                        $estadoNormalizado,
                        $_SESSION['user_nombre'] ?? 'Sistema / Importador'
                    ]);
                    $equipoId = $db->lastInsertId();
                    $exitos++;
                }

                if (!empty($colaboradorVal)) {
                    $stmtEmp = $db->prepare("SELECT id FROM empleados WHERE LOWER(TRIM(nombre)) = LOWER(TRIM(?)) LIMIT 1");
                    $stmtEmp->execute([$colaboradorVal]);
                    $empId = $stmtEmp->fetchColumn();

                    if (!$empId) {
                        $stmtInsEmp = $db->prepare("INSERT INTO empleados (sucursal_id, nombre, cargo, departamento, estado) VALUES (?, ?, 'Colaborador', ?, 'Activo')");
                        $stmtInsEmp->execute([$sucursalId, $colaboradorVal, $areaVal ?: 'General']);
                        $empId = $db->lastInsertId();
                    }

                    $stmtCheckAsig = $db->prepare("SELECT id, empleado_id FROM asignaciones WHERE equipo_id = ? AND fecha_devolucion IS NULL LIMIT 1");
                    $stmtCheckAsig->execute([$equipoId]);
                    $asigActiva = $stmtCheckAsig->fetch();

                    if ($asigActiva) {
                        if ((int)$asigActiva['empleado_id'] !== (int)$empId) {
                            $db->prepare("UPDATE asignaciones SET fecha_devolucion = CURDATE(), condicion_devolucion = 'Reasignado por importación' WHERE id = ?")->execute([$asigActiva['id']]);
                            $stmtAsig = $db->prepare("INSERT INTO asignaciones (equipo_id, empleado_id, fecha_entrega, condicion_entrega, asignado_por) VALUES (?, ?, ?, 'Buen Estado', ?)");
                            $stmtAsig->execute([$equipoId, $empId, date('Y-m-d'), $_SESSION['user_nombre'] ?? 'Sistema / Importador']);
                            $asignados++;
                        }
                    } else {
                        $stmtAsig = $db->prepare("INSERT INTO asignaciones (equipo_id, empleado_id, fecha_entrega, condicion_entrega, asignado_por) VALUES (?, ?, ?, 'Buen Estado', ?)");
                        $stmtAsig->execute([$equipoId, $empId, date('Y-m-d'), $_SESSION['user_nombre'] ?? 'Sistema / Importador']);
                        $asignados++;
                    }

                    $db->prepare("UPDATE equipos SET estado = 'Asignado' WHERE id = ?")->execute([$equipoId]);
                }

                $rowCounter++;
            }

            $db->commit();

            $totalProcesados = count($itemsToImport);
            $msg = "Importación procesada ($totalProcesados filas procesadas): $exitos equipos nuevos creados, $actualizados equipos actualizados con su sucursal.";
            if ($asignados > 0) $msg .= " $asignados asignaciones de colaborador vinculadas.";

            $_SESSION['flash_success'] = $msg;
            $redirectUrl = (isset($_GET['redirect']) && $_GET['redirect'] === 'config') ? '../configuracion/index.php' : 'index.php';
            header('Location: ' . $redirectUrl);
            exit;
        }

                // 2. Asignar Equipo a Empleado
        if ($action === 'assign') {
            $equipo_id = (int)$_POST['equipo_id'];
            $empleado_id = (int)$_POST['empleado_id'];
            $fecha_entrega = $_POST['fecha_entrega'];
            $condicion_entrega = trim($_POST['condicion_entrega']);

            // Iniciar Transacción para asegurar consistencia
            $db->beginTransaction();

            // VERIFICACIÓN CRÍTICA: Asegurarse de que el equipo esté "Disponible"
            $stmt_check = $db->prepare("SELECT estado FROM equipos WHERE id = ? FOR UPDATE");
            $stmt_check->execute([$equipo_id]);
            $estado_actual = $stmt_check->fetchColumn();

            if ($estado_actual !== 'Disponible') {
                $db->rollBack();
                $_SESSION['flash_error'] = "Error: El equipo ya se encuentra asignado o no está disponible.";
                header('Location: index.php');
                exit;
            }

            $asignado_por_val = $_SESSION['user_nombre'] ?? 'Sistema';
            $entregado_a_val = trim($_POST['entregado_a'] ?? '');

            // Insertar asignación
            $stmt_asig = $db->prepare("
                INSERT INTO asignaciones (equipo_id, empleado_id, fecha_entrega, condicion_entrega, asignado_por, entregado_a) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt_asig->execute([$equipo_id, $empleado_id, $fecha_entrega, $condicion_entrega, $asignado_por_val, $entregado_a_val]);
            
            $asig_id = $db->lastInsertId();

            // Actualizar estado del equipo a 'Asignado'
            $stmt_eq = $db->prepare("UPDATE equipos SET estado = 'Asignado' WHERE id = ?");
            $stmt_eq->execute([$equipo_id]);

            $db->commit();
            
            // Redirigir a la vista de impresión con el ID de la asignación generada
            header('Location: imprimir_asignacion.php?id=' . $asig_id);
            exit;
        }

        // 3. Devolver Equipo / Registrar Retorno
        if ($action === 'return') {
            $equipo_id = (int)$_POST['equipo_id'];
            $asignacion_id = (int)$_POST['asignacion_id'];
            $fecha_devolucion = $_POST['fecha_devolucion'];
            $condicion_devolucion = trim($_POST['condicion_devolucion']);

            $db->beginTransaction();

            $devuelto_por_val = !empty(trim($_POST['devuelto_por'])) ? trim($_POST['devuelto_por']) : 'No especificado';
            $recibido_por_val = $_SESSION['user_nombre'] ?? 'Sistema';

            // Actualizar la asignación activa agregando la fecha de devolución y la nota de retorno
            $stmt_asig = $db->prepare("
                UPDATE asignaciones 
                SET fecha_devolucion = ?, 
                    condicion_devolucion = ?,
                    devuelto_por = ?,
                    recibido_por = ?
                WHERE id = ?
            ");
            $stmt_asig->execute([$fecha_devolucion, $condicion_devolucion, $devuelto_por_val, $recibido_por_val, $asignacion_id]);

            // Regresar el equipo a 'Disponible'
            $stmt_eq = $db->prepare("UPDATE equipos SET estado = 'Disponible' WHERE id = ?");
            $stmt_eq->execute([$equipo_id]);

            $db->commit();
            header('Location: index.php');
            exit;
        }

        // 3.5 Cambiar Estado Directo (Disponible / Stock, Mantenimiento)
        if ($action === 'cambiar_estado') {
            $equipo_id = (int)($_POST['equipo_id'] ?? 0);
            $nuevo_estado = $_POST['nuevo_estado'] ?? '';
            $motivo = trim($_POST['motivo'] ?? '');

            if ($equipo_id > 0 && in_array($nuevo_estado, ['Disponible', 'Mantenimiento', 'Asignado', 'De Baja'])) {
                $db->beginTransaction();

                if (in_array($nuevo_estado, ['Disponible', 'Mantenimiento', 'De Baja'])) {
                    $stmt_close = $db->prepare("
                        UPDATE asignaciones 
                        SET fecha_devolucion = CURDATE(), 
                            condicion_devolucion = ?,
                            recibido_por = ?
                        WHERE equipo_id = ? AND fecha_devolucion IS NULL
                    ");
                    $descCierre = !empty($motivo) ? $motivo : ($nuevo_estado === 'Mantenimiento' ? 'Ingreso a Mantenimiento' : 'Liberado a Stock / Bodega');
                    $stmt_close->execute([$descCierre, $_SESSION['user_nombre'] ?? 'Sistema', $equipo_id]);
                }

                $stmt_up = $db->prepare("UPDATE equipos SET estado = ? WHERE id = ?");
                $stmt_up->execute([$nuevo_estado, $equipo_id]);

                $db->commit();
                $_SESSION['flash_success'] = "Estado del equipo actualizado a '{$nuevo_estado}'.";
            }
            header('Location: index.php');
            exit;
        }
    } else {
        // Acciones GET
        
        // 4. Dar de Baja un Equipo (Descomisionar)
        if ($action === 'baja') {
            $id = (int)$_GET['id'];
            
            $db->beginTransaction();
            
            // Si estaba asignado, cerrar la asignación pendiente primero
            $stmt_close = $db->prepare("
                UPDATE asignaciones 
                SET fecha_devolucion = CURDATE(), 
                    condicion_devolucion = 'Cierre por Baja',
                    recibido_por = ?
                WHERE equipo_id = ? AND fecha_devolucion IS NULL
            ");
            $stmt_close->execute([$_SESSION['user_nombre'] ?? 'Sistema / Baja', $id]);

            // Marcar equipo como de baja
            $stmt_eq = $db->prepare("UPDATE equipos SET estado = 'De Baja' WHERE id = ?");
            $stmt_eq->execute([$id]);

            $db->commit();
            header('Location: index.php');
            exit;
        }
    }
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    die("Error procesando inventario: " . $e->getMessage());
}
