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
    $q = '%' . $query . '%';

    $stmt = $db->prepare("SELECT id, nombre, departamento AS subtitulo, estado FROM empleados WHERE nombre LIKE ? OR departamento LIKE ? OR cargo LIKE ? LIMIT 5");
    $stmt->execute([$q, $q, $q]);
    foreach ($stmt->fetchAll() as $r) {
        $results[] = ['tipo'=>'Empleado','icon'=>'fa-user-tie','color'=>'emerald','titulo'=>$r['nombre'],'sub'=>$r['subtitulo'],'estado'=>$r['estado'],'link'=>'modules/empleados/index.php'];
    }

    $stmt = $db->prepare("SELECT e.id, CONCAT(e.marca, ' ', e.modelo) AS nombre, e.tipo AS subtitulo, e.estado, emp.nombre AS asignado FROM equipos e LEFT JOIN asignaciones a ON e.id = a.equipo_id AND a.fecha_devolucion IS NULL LEFT JOIN empleados emp ON a.empleado_id = emp.id WHERE CONCAT(e.marca, ' ', e.modelo) LIKE ? OR e.tipo LIKE ? OR e.service_tag_sn LIKE ? OR emp.nombre LIKE ? OR e.marca LIKE ? LIMIT 5");
    $stmt->execute([$q, $q, $q, $q, $q]);
    foreach ($stmt->fetchAll() as $r) {
        $results[] = ['tipo'=>'Equipo ('.$r['subtitulo'].')','icon'=>'fa-laptop','color'=>'cyan','titulo'=>$r['nombre'],'sub'=>$r['asignado']?'Asignado a: '.$r['asignado']:'Sin asignar','estado'=>$r['estado'],'link'=>'modules/equipos/index.php'];
    }

    $stmt = $db->prepare("SELECT c.id, c.correo AS nombre, c.licencia_tipo AS subtitulo, c.estado, emp.nombre AS asignado FROM cuentas_m365 c LEFT JOIN empleados emp ON c.empleado_id=emp.id WHERE c.correo LIKE ? OR c.licencia_tipo LIKE ? OR emp.nombre LIKE ? LIMIT 4");
    $stmt->execute([$q, $q, $q]);
    foreach ($stmt->fetchAll() as $r) {
        $results[] = ['tipo'=>'Licencia M365','icon'=>'fa-cloud','color'=>'blue','titulo'=>$r['nombre'],'sub'=>$r['subtitulo'].($r['asignado']?' — '.$r['asignado']:''),'estado'=>$r['estado'],'link'=>'modules/credenciales/index.php?tab=m365'];
    }

    $stmt = $db->prepare("SELECT id, plataforma AS nombre, usuario AS subtitulo FROM credenciales_web WHERE plataforma LIKE ? OR usuario LIKE ? LIMIT 4");
    $stmt->execute([$q, $q]);
    foreach ($stmt->fetchAll() as $r) {
        $results[] = ['tipo'=>'Credencial','icon'=>'fa-key','color'=>'amber','titulo'=>$r['nombre'],'sub'=>'Usuario: '.$r['subtitulo'],'estado'=>'Activa','link'=>'modules/credenciales/index.php'];
    }

    $stmt = $db->prepare("SELECT t.id, t.titulo AS nombre, t.estado, ei.nombre AS asignado FROM tareas_it t LEFT JOIN equipo_it ei ON t.asignado_a=ei.id WHERE t.titulo LIKE ? OR t.descripcion LIKE ? LIMIT 4");
    $stmt->execute([$q, $q]);
    foreach ($stmt->fetchAll() as $r) {
        $results[] = ['tipo'=>'Tarea IT','icon'=>'fa-list-check','color'=>'fuchsia','titulo'=>$r['nombre'],'sub'=>'Asignada a: '.($r['asignado']??'Sin asignar'),'estado'=>$r['estado'],'link'=>'modules/tareas/index.php'];
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
            $api_url = "https://generativelanguage.googleapis.com/v1beta/models/{$ai_model}:generateContent?key=" . urlencode($ai_api_key);
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
