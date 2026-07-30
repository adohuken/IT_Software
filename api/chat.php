<?php
/**
 * API Endpoint: Asistente IT con DeepSeek AI
 * Si hay API Key configurada → usa DeepSeek con contexto de la BD.
 * Si no hay Key → modo búsqueda básica por LIKE en las tablas.
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

session_start();
require_once __DIR__ . '/../config/conexion.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'No autorizado.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método no permitido.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$query = isset($input['query']) ? trim(strip_tags($input['query'])) : '';

if (empty($query) || mb_strlen($query) < 2) {
    echo json_encode(['reply' => 'Por favor, escribe una consulta de al menos 2 caracteres.', 'results' => []]);
    exit;
}

// ─────────────────────────────────────────────
// Leer configuración de IA
// ─────────────────────────────────────────────
$ai_config_file = __DIR__ . '/../config/ai_config.php';
$ai_api_key  = '';
$ai_model    = 'deepseek-chat';
$ai_provider = 'deepseek';
if (file_exists($ai_config_file)) {
    $cfg = include $ai_config_file;
    $ai_api_key  = $cfg['api_key'] ?? '';
    $ai_model    = $cfg['model'] ?? 'deepseek-chat';
    $ai_provider = $cfg['provider'] ?? 'deepseek';
}

try {
    $db = Database::getConexion();

    // ─────────────────────────────────────────────
    // SIEMPRE: Obtener contexto de la BD para IA
    // ─────────────────────────────────────────────
    $context_lines = [];

    // Conteos generales
    $equipos_total   = $db->query("SELECT COUNT(*) FROM equipos")->fetchColumn();
    $equipos_disp    = $db->query("SELECT COUNT(*) FROM equipos WHERE estado='Disponible'")->fetchColumn();
    $equipos_asig    = $db->query("SELECT COUNT(*) FROM equipos WHERE estado='Asignado'")->fetchColumn();
    $equipos_mant    = $db->query("SELECT COUNT(*) FROM equipos WHERE estado='Mantenimiento'")->fetchColumn();
    $empleados_total = $db->query("SELECT COUNT(*) FROM empleados WHERE estado='Activo'")->fetchColumn();
    $m365_total      = $db->query("SELECT COUNT(*) FROM cuentas_m365 WHERE estado='Activa'")->fetchColumn();
    $m365_vencer     = $db->query("SELECT COUNT(*) FROM cuentas_m365 WHERE fecha_proximo_pago <= DATE_ADD(CURDATE(), INTERVAL 15 DAY) AND estado='Activa'")->fetchColumn();
    $tareas_pend     = $db->query("SELECT COUNT(*) FROM tareas_it WHERE estado IN ('Pendiente','En Progreso','Vencida')")->fetchColumn();
    $creds_total     = $db->query("SELECT COUNT(*) FROM credenciales_web")->fetchColumn();

    $context_lines[] = "RESUMEN DEL SISTEMA:";
    $context_lines[] = "- Equipos: $equipos_total total ($equipos_disp disponibles, $equipos_asig asignados, $equipos_mant en mantenimiento)";
    $context_lines[] = "- Empleados activos: $empleados_total";
    $context_lines[] = "- Licencias M365 activas: $m365_total ($m365_vencer vencen en los próximos 15 días)";
    $context_lines[] = "- Tareas IT pendientes/en progreso/vencidas: $tareas_pend";
    $context_lines[] = "- Credenciales registradas: $creds_total";

    // Búsqueda rápida en tablas (para resultados clicables)
    $results = [];

    // 1. Detectar intenciones de estado
    $intent_estado = null;
    $query_lower = mb_strtolower($query);
    if (strpos($query_lower, 'no asignado') !== false || strpos($query_lower, 'sin asignar') !== false || strpos($query_lower, 'disponible') !== false || strpos($query_lower, 'libre') !== false) {
        $intent_estado = 'Disponible';
    } elseif (strpos($query_lower, 'asignado') !== false || strpos($query_lower, 'ocupado') !== false) {
        $intent_estado = 'Asignado';
    } elseif (strpos($query_lower, 'mantenimiento') !== false || strpos($query_lower, 'reparacion') !== false || strpos($query_lower, 'dañado') !== false) {
        $intent_estado = 'Mantenimiento';
    }

    // 2. Detectar intenciones de tipo de equipo
    $intent_tipo = null;
    if (strpos($query_lower, 'laptop') !== false || strpos($query_lower, 'portatil') !== false || strpos($query_lower, 'computadora') !== false) {
        $intent_tipo = 'Laptop';
    } elseif (strpos($query_lower, 'switch') !== false) {
        $intent_tipo = 'Switch';
    } elseif (strpos($query_lower, 'servidor') !== false) {
        $intent_tipo = 'Servidor';
    } elseif (strpos($query_lower, 'impresora') !== false) {
        $intent_tipo = 'Impresora';
    } elseif (strpos($query_lower, 'access point') !== false || strpos($query_lower, ' ap') !== false) {
        $intent_tipo = 'AP';
    } elseif (strpos($query_lower, 'control de acceso') !== false) {
        $intent_tipo = 'Control de Acceso';
    }

    // 3. Detectar intención de categoría
    $intent_categoria = null;
    if (preg_match('/\b(correo|email|m365|office|licencia|licencias|365|microsoft)\b/i', $query_lower)) {
        $intent_categoria = 'm365';
    } elseif (preg_match('/\b(contraseña|clave|pass|password|usuario|login|acceso|plataforma|web)\b/i', $query_lower)) {
        $intent_categoria = 'credencial';
    } elseif (preg_match('/\b(equipo|equipos|laptop|laptops|portatil|portatiles|computadora|computadoras|pc|pcs|switch|switches|servidor|servidores|impresora|impresoras|ap|aps|serie|sn|s\/n|marca|modelo)\b/i', $query_lower)) {
        $intent_categoria = 'equipo';
    } elseif (preg_match('/\b(colaborador|colaboradores|empleado|empleados|persona|personas|perfil|quien|cargo|puesto|departamento)\b/i', $query_lower)) {
        $intent_categoria = 'empleado';
    } elseif (preg_match('/\b(tarea|tareas|ticket|tickets|pendiente|pendientes|incidencia|incidencias|it)\b/i', $query_lower)) {
        $intent_categoria = 'tarea';
    }

    // Limpiar y dividir la consulta en palabras clave
    $words = preg_split('/\s+/', $query_lower);
    // Excluir palabras de parada comunes (stop words)
    $stop_words = ['dame', 'la', 'contraseña', 'de', 'el', 'un', 'una', 'correo', 'del', 'los', 'las', 'que', 'en', 'para', 'con', 'por', 'sobre', 'es', 'son', 'equipo', 'colaborador', 'empleado', 'su', 'sus', 'ver', 'buscar', 'encontrar'];
    $keywords = [];
    foreach ($words as $w) {
        $w = trim($w, ",.?!\"'");
        if (mb_strlen($w) >= 2 && !in_array($w, $stop_words)) {
            $keywords[] = $w;
        }
    }
    
    // Si no quedan palabras clave después del filtro, usar la consulta original
    if (empty($keywords)) {
        $keywords = [$query_lower];
    }

    // Filtrar palabras de tipo e intención para no duplicar condiciones vacías
    $filtered_keywords = [];
    $exclude_kw = ['laptop', 'laptops', 'portatil', 'portatiles', 'computadora', 'computadoras', 'pc', 'pcs', 'switch', 'switches', 'servidor', 'servidores', 'impresora', 'impresoras', 'ap', 'aps', 'access', 'point', 'control', 'acceso', 'asignado', 'asignados', 'disponible', 'disponibles', 'libre', 'libres', 'mantenimiento', 'equipos', 'equipo'];
    foreach ($keywords as $kw) {
        if (!in_array($kw, $exclude_kw)) {
            $filtered_keywords[] = $kw;
        }
    }

    // Buscar en empleados
    if (empty($intent_categoria) || $intent_categoria === 'empleado') {
        $emp_conditions = [];
        $emp_params = [];
        foreach ($keywords as $i => $kw) {
            $emp_conditions[] = "(nombre LIKE :emp_n$i OR departamento LIKE :emp_d$i OR cargo LIKE :emp_c$i)";
            $emp_params[":emp_n$i"] = "%$kw%";
            $emp_params[":emp_d$i"] = "%$kw%";
            $emp_params[":emp_c$i"] = "%$kw%";
        }
        $emp_sql = "SELECT id, nombre, departamento AS subtitulo, estado FROM empleados WHERE " . implode(" AND ", $emp_conditions) . " LIMIT 5";
        $stmt = $db->prepare($emp_sql);
        $stmt->execute($emp_params);
        foreach ($stmt->fetchAll() as $r) {
            $results[] = ['tipo'=>'Empleado','icon'=>'fa-user-tie','color'=>'emerald','titulo'=>$r['nombre'],'sub'=>$r['subtitulo'],'estado'=>$r['estado'],'link'=>'modules/empleados/index.php'];
        }
    }

    // Buscar en equipos (Utilizando intenciones semánticas si existen)
    if (empty($intent_categoria) || $intent_categoria === 'equipo') {
        $eq_sql = "SELECT e.id, CONCAT(e.marca, ' ', e.modelo) AS nombre, e.tipo AS subtitulo, e.estado, e.service_tag_sn, emp.nombre AS asignado 
                   FROM equipos e 
                   LEFT JOIN asignaciones a ON e.id = a.equipo_id AND a.fecha_devolucion IS NULL 
                   LEFT JOIN empleados emp ON a.empleado_id = emp.id";
        
        $eq_where = [];
        $eq_params = [];
        
        if ($intent_estado) {
            $eq_where[] = "e.estado = :intent_est";
            $eq_params[':intent_est'] = $intent_estado;
        }
        if ($intent_tipo) {
            $eq_where[] = "e.tipo = :intent_tip";
            $eq_params[':intent_tip'] = $intent_tipo;
        }
        
        if (!empty($filtered_keywords)) {
            $kw_conds = [];
            foreach ($filtered_keywords as $i => $kw) {
                $kw_conds[] = "(e.marca LIKE :eq_ma$i OR e.modelo LIKE :eq_mo$i OR e.service_tag_sn LIKE :eq_sn$i OR emp.nombre LIKE :eq_em$i)";
                $eq_params[":eq_ma$i"] = "%$kw%";
                $eq_params[":eq_mo$i"] = "%$kw%";
                $eq_params[":eq_sn$i"] = "%$kw%";
                $eq_params[":eq_em$i"] = "%$kw%";
            }
            $eq_where[] = "(" . implode(" OR ", $kw_conds) . ")";
        }
        
        // Si no hay intenciones ni keywords filtradas, usamos las keywords iniciales
        if (empty($intent_estado) && empty($intent_tipo) && empty($filtered_keywords)) {
            $kw_conds = [];
            foreach ($keywords as $i => $kw) {
                $kw_conds[] = "(e.marca LIKE :eq_ma$i OR e.modelo LIKE :eq_mo$i OR e.tipo LIKE :eq_ti$i OR e.service_tag_sn LIKE :eq_sn$i OR emp.nombre LIKE :eq_em$i)";
                $eq_params[":eq_ma$i"] = "%$kw%";
                $eq_params[":eq_mo$i"] = "%$kw%";
                $eq_params[":eq_ti$i"] = "%$kw%";
                $eq_params[":eq_sn$i"] = "%$kw%";
                $eq_params[":eq_em$i"] = "%$kw%";
            }
            $eq_where[] = "(" . implode(" OR ", $kw_conds) . ")";
        }

        if (!empty($eq_where)) {
            $eq_sql .= " WHERE " . implode(" AND ", $eq_where);
        }
        $eq_sql .= " LIMIT 5";

        $stmt = $db->prepare($eq_sql);
        $stmt->execute($eq_params);
        foreach ($stmt->fetchAll() as $r) {
            $sn_text = !empty($r['service_tag_sn']) ? 'S/N: ' . $r['service_tag_sn'] : 'Sin S/N';
            $asignado_text = $r['asignado'] ? 'Asignado a: ' . $r['asignado'] : 'Sin asignar';
            $results[] = [
                'tipo' => 'Equipo (' . $r['subtitulo'] . ')',
                'icon' => 'fa-laptop',
                'color' => 'cyan',
                'titulo' => $r['nombre'],
                'sub' => $sn_text . ' | ' . $asignado_text,
                'estado' => $r['estado'],
                'link' => 'modules/equipos/index.php'
            ];
        }
    }

    // Buscar en cuentas M365
    if (empty($intent_categoria) || $intent_categoria === 'm365' || $intent_categoria === 'credencial') {
        $m365_conditions = [];
        $m365_params = [];
        foreach ($keywords as $i => $kw) {
            $m365_conditions[] = "(c.correo LIKE :m_co$i OR c.licencia_tipo LIKE :m_li$i OR emp.nombre LIKE :m_em$i)";
            $m365_params[":m_co$i"] = "%$kw%";
            $m365_params[":m_li$i"] = "%$kw%";
            $m365_params[":m_em$i"] = "%$kw%";
        }
        $m365_sql = "SELECT c.id, c.correo AS nombre, c.licencia_tipo AS subtitulo, c.estado, emp.nombre AS asignado 
                     FROM cuentas_m365 c 
                     LEFT JOIN empleados emp ON c.empleado_id = emp.id 
                     WHERE " . implode(" AND ", $m365_conditions) . " LIMIT 4";
        $stmt = $db->prepare($m365_sql);
        $stmt->execute($m365_params);
        foreach ($stmt->fetchAll() as $r) {
            $results[] = ['tipo'=>'Licencia M365','icon'=>'fa-cloud','color'=>'blue','titulo'=>$r['nombre'],'sub'=>$r['subtitulo'].($r['asignado']?' — '.$r['asignado']:''),'estado'=>$r['estado'],'link'=>'modules/credenciales/index.php?tab=m365'];
        }
    }

    // Buscar en credenciales web
    if (empty($intent_categoria) || $intent_categoria === 'credencial' || $intent_categoria === 'm365') {
        $web_conditions = [];
        $web_params = [];
        foreach ($keywords as $i => $kw) {
            $web_conditions[] = "(plataforma LIKE :w_pl$i OR usuario LIKE :w_us$i)";
            $web_params[":w_pl$i"] = "%$kw%";
            $web_params[":w_us$i"] = "%$kw%";
        }
        $web_sql = "SELECT id, plataforma AS nombre, usuario AS subtitulo FROM credenciales_web WHERE " . implode(" AND ", $web_conditions) . " LIMIT 4";
        $stmt = $db->prepare($web_sql);
        $stmt->execute($web_params);
        foreach ($stmt->fetchAll() as $r) {
            $results[] = ['tipo'=>'Credencial','icon'=>'fa-key','color'=>'amber','titulo'=>$r['nombre'],'sub'=>'Usuario: '.$r['subtitulo'],'estado'=>'Activa','link'=>'modules/credenciales/index.php'];
        }
    }

    // Buscar en credenciales de equipos
    if (empty($intent_categoria) || $intent_categoria === 'credencial') {
        $ceq_conditions = [];
        $ceq_params = [];
        foreach ($keywords as $i => $kw) {
            $ceq_conditions[] = "(e.marca LIKE :ceq_ma$i OR e.modelo LIKE :ceq_mo$i OR e.service_tag_sn LIKE :ceq_sn$i OR ce.usuario_local LIKE :ceq_us$i OR emp.nombre LIKE :ceq_em$i)";
            $ceq_params[":ceq_ma$i"] = "%$kw%";
            $ceq_params[":ceq_mo$i"] = "%$kw%";
            $ceq_params[":ceq_sn$i"] = "%$kw%";
            $ceq_params[":ceq_us$i"] = "%$kw%";
            $ceq_params[":ceq_em$i"] = "%$kw%";
        }
        $ceq_sql = "SELECT ce.id, CONCAT(e.marca, ' ', e.modelo) AS equipo_nombre, ce.usuario_local, emp.nombre AS asignado 
                    FROM credenciales_equipos ce 
                    JOIN equipos e ON ce.equipo_id = e.id 
                    LEFT JOIN asignaciones a ON a.equipo_id = e.id AND a.fecha_devolucion IS NULL
                    LEFT JOIN empleados emp ON a.empleado_id = emp.id
                    WHERE " . implode(" AND ", $ceq_conditions) . " LIMIT 4";
        $stmt = $db->prepare($ceq_sql);
        $stmt->execute($ceq_params);
        foreach ($stmt->fetchAll() as $r) {
            $results[] = [
                'tipo' => 'Credencial de Equipo',
                'icon' => 'fa-key',
                'color' => 'amber',
                'titulo' => $r['equipo_nombre'],
                'sub' => 'Usuario: ' . $r['usuario_local'] . ($r['asignado'] ? ' | ' . $r['asignado'] : ''),
                'estado' => 'Activa',
                'link' => 'modules/credenciales/index.php'
            ];
        }
    }

    // Buscar en tareas IT
    if (empty($intent_categoria) || $intent_categoria === 'tarea') {
        $t_conditions = [];
        $t_params = [];
        foreach ($keywords as $i => $kw) {
            $t_conditions[] = "(t.titulo LIKE :t_ti$i OR t.descripcion LIKE :t_de$i)";
            $t_params[":t_ti$i"] = "%$kw%";
            $t_params[":t_de$i"] = "%$kw%";
        }
        $t_sql = "SELECT t.id, t.titulo AS nombre, t.estado, ei.nombre AS asignado 
                  FROM tareas_it t 
                  LEFT JOIN equipo_it ei ON t.asignado_a = ei.id 
                  WHERE " . implode(" AND ", $t_conditions) . " LIMIT 4";
        $stmt = $db->prepare($t_sql);
        $stmt->execute($t_params);
        foreach ($stmt->fetchAll() as $r) {
            $results[] = ['tipo'=>'Tarea IT','icon'=>'fa-list-check','color'=>'fuchsia','titulo'=>$r['nombre'],'sub'=>'Asignada a: '.($r['asignado']??'Sin asignar'),'estado'=>$r['estado'],'link'=>'modules/tareas/index.php'];
        }
    }

    // ─────────────────────────────────────────────
    // MODO A: CON API KEY → llamada a DeepSeek
    // ─────────────────────────────────────────────
    if (!empty($ai_api_key)) {

        $system_prompt = "Eres un Asistente IT inteligente para el sistema CuentasIT de la empresa. Responde siempre en español, de forma clara, breve y útil. Solo respondes preguntas relacionadas con IT, equipos, credenciales, empleados, licencias Microsoft 365 y tareas del sistema. Si te preguntan algo no relacionado con el sistema IT, lo rediriges educadamente.

" . implode("\n", $context_lines) . "

Cuando el usuario haga una pregunta, usa el resumen anterior para dar respuestas precisas y actualizadas. Si el usuario pregunta por algo específico (un nombre, un equipo, etc.), indícale que los resultados de búsqueda se muestran debajo de tu respuesta.";

        $prov_name = ucfirst($ai_provider);

        if ($ai_provider === 'gemini') {
            $api_url = "https://generativelanguage.googleapis.com/v1/models/{$ai_model}:generateContent?key=" . urlencode($ai_api_key);
            $payload = json_encode([
                'systemInstruction' => [
                    'parts' => [['text' => $system_prompt]]
                ],
                'contents' => [
                    ['role' => 'user', 'parts' => [['text' => $query]]]
                ],
                'generationConfig' => [
                    'temperature' => 0.6,
                    'maxOutputTokens' => 400
                ]
            ]);
            $http_header = ['Content-Type: application/json'];
        } else {
            $base_urls = [
                'deepseek' => 'https://api.deepseek.com/chat/completions',
                'openai'   => 'https://api.openai.com/v1/chat/completions',
                'groq'     => 'https://api.groq.com/openai/v1/chat/completions'
            ];
            $api_url = $base_urls[$ai_provider] ?? $base_urls['deepseek'];
            $payload = json_encode([
                'model'    => $ai_model,
                'messages' => [
                    ['role' => 'system', 'content' => $system_prompt],
                    ['role' => 'user',   'content' => $query]
                ],
                'max_tokens'  => 400,
                'temperature' => 0.6,
            ]);
            $http_header = [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $ai_api_key,
            ];
        }

        $ch = curl_init($api_url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => $http_header,
        ]);

        $response = curl_exec($ch);
        $curl_err = curl_error($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curl_err) {
            $reply = "⚠️ No se pudo conectar con $prov_name: $curl_err. Verifica tu conexión a internet.";
        } else {
            $data = json_decode($response, true);
            if ($http_code === 200) {
                if ($ai_provider === 'gemini' && isset($data['candidates'][0]['content']['parts'][0]['text'])) {
                    $reply = $data['candidates'][0]['content']['parts'][0]['text'];
                } elseif (isset($data['choices'][0]['message']['content'])) {
                    $reply = $data['choices'][0]['message']['content'];
                } else {
                    $reply = "⚠️ Formato de respuesta no reconocido de $prov_name.";
                }
            } elseif (isset($data['error']['message'])) {
                $reply = "⚠️ Error de $prov_name: " . $data['error']['message'];
            } else {
                $reply = "⚠️ Respuesta inesperada de la API (HTTP $http_code). Verifica tu API Key en Configuración.";
            }
        }

    } else {
        // ─────────────────────────────────────────────
        // MODO B: SIN API KEY → búsqueda básica
        // ─────────────────────────────────────────────
        if (empty($results)) {
            $reply = "No encontré ningún resultado para <b>\"" . htmlspecialchars($query) . "\"</b>. Intenta con otros términos.";
        } else {
            $total = count($results);
            $reply = "Encontré <b>$total resultado(s)</b> para <b>\"" . htmlspecialchars($query) . "\"</b>. 💡 Activa la IA en <a href='modules/configuracion/index.php' class='underline text-indigo-400'>Configuración</a> para respuestas más inteligentes.";
        }
    }

    echo json_encode([
        'reply'   => $reply,
        'results' => $results
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Error interno: ' . $e->getMessage()]);
}
