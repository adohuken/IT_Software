<?php
/**
 * Endpoint para Diagnóstico de Conectividad y Ping ICMP
 * Permite probar conectividad hacia IPs públicas, gateways, DNS y hosts.
 */

session_start();
header('Content-Type: application/json; charset=utf-8');

// 1. Validar sesión de usuario
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error'   => 'Sesión expirada o no autorizada.'
    ]);
    exit;
}

// 2. Obtener y validar el destino (IP o Hostname)
$host = trim($_POST['host'] ?? $_GET['host'] ?? '');
$targetLabel = trim($_POST['label'] ?? $_GET['label'] ?? '');

if (empty($host)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => 'Debe especificar una dirección IP o nombre de host.'
    ]);
    exit;
}

// Limpiar posibles prefijos http:// o barras
$hostClean = preg_replace('#^https?://#', '', $host);
$hostClean = trim(explode('/', $hostClean)[0]);
$hostClean = trim(explode(':', $hostClean)[0]);

// Validar que sea una IP válida o un hostname seguro (sin caracteres maliciosos)
$esIp = filter_var($hostClean, FILTER_VALIDATE_IP);
$esHost = filter_var($hostClean, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME);

if (!$esIp && !$esHost) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => "La dirección '$hostClean' no tiene un formato de IP o Host válido."
    ]);
    exit;
}

// 3. Preparar comando según el Sistema Operativo
$isWindows = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');
$escapedHost = escapeshellarg($hostClean);

if ($isWindows) {
    // 4 paquetes, tiempo de espera 1200ms por paquete
    $cmd = "ping -n 4 -w 1200 " . $escapedHost;
} else {
    // Linux / Unix: 4 paquetes, espera 1s
    $cmd = "ping -c 4 -W 2 " . $escapedHost;
}

// 4. Ejecutar el comando de manera segura
$startTime = microtime(true);
$outputLines = [];
$returnCode = 0;

exec($cmd, $outputLines, $returnCode);
$duration = round((microtime(true) - $startTime) * 1000); // ms totales de ejecución

// 5. Procesar y normalizar la codificación de la salida a UTF-8
$salidaUtf8 = [];
$textoCompleto = '';

foreach ($outputLines as $line) {
    // Convertir de CP850 o ISO-8859-1 a UTF-8 si es Windows
    if ($isWindows) {
        $lineClean = mb_check_encoding($line, 'UTF-8') ? $line : mb_convert_encoding($line, 'UTF-8', 'CP850');
    } else {
        $lineClean = $line;
    }
    $salidaUtf8[] = $lineClean;
    $textoCompleto .= $lineClean . "\n";
}

// 6. Analizar métricas de conectividad de forma robusta y universal
$status = 'offline';
$loss = '100%';
$avgLatency = 'Sin respuesta';
$minLatency = 'N/A';
$maxLatency = 'N/A';
$respuestasOk = 0;
$paquetesEnviados = 4;
$paquetesPerdidos = 4;
$lossNum = 100;

// A. Extraer estadísticas de paquetes directamente del resumen del sistema
// Windows: "Paquetes: enviados = 4, recibidos = 4, perdidos = 0"
if (preg_match('/(?:enviados|sent)\s*=\s*(\d+)[,\s]+(?:recibidos|received)\s*=\s*(\d+)[,\s]+(?:perdidos|lost)\s*=\s*(\d+)/i', $textoCompleto, $pktMatch)) {
    $paquetesEnviados = (int)$pktMatch[1];
    $respuestasOk     = (int)$pktMatch[2];
    $paquetesPerdidos = (int)$pktMatch[3];
} elseif (preg_match('/(\d+)\s+packets transmitted,\s*(\d+)\s+(?:packets\s+)?received/i', $textoCompleto, $pktMatch)) {
    // Linux: "4 packets transmitted, 4 received, 0% packet loss"
    $paquetesEnviados = (int)$pktMatch[1];
    $respuestasOk     = (int)$pktMatch[2];
    $paquetesPerdidos = max(0, $paquetesEnviados - $respuestasOk);
} else {
    // Fallback: contar líneas individuales de respuesta exitosa
    $okCount = 0;
    foreach ($salidaUtf8 as $linea) {
        if (preg_match('/(?:Respuesta desde|Reply from|\d+\s+bytes from)/i', $linea) &&
            !preg_match('/(?:inaccesible|unreachable|agotado|timed out|error)/i', $linea)) {
            $okCount++;
        }
    }
    if ($okCount > 0) {
        $respuestasOk = $okCount;
        $paquetesPerdidos = max(0, $paquetesEnviados - $respuestasOk);
    }
}

// B. Extraer porcentaje de pérdida
if (preg_match('/\(?([0-9]+)%\s*(?:perdidos|loss|packet loss|de p[ée]rdida)/i', $textoCompleto, $lossMatch)) {
    $lossNum = (int)$lossMatch[1];
} else {
    $lossNum = ($paquetesEnviados > 0) ? round(($paquetesPerdidos / $paquetesEnviados) * 100) : 100;
}
$loss = $lossNum . '%';

// C. Determinar estado de la conexión
if ($respuestasOk > 0 && $lossNum === 0) {
    $status = 'online';
} elseif ($respuestasOk > 0 && $lossNum < 100) {
    $status = 'degraded';
} else {
    $status = 'offline';
}

// D. Calcular latencia promedio
if ($respuestasOk > 0) {
    // Windows: Media = 0ms o Media = 41ms
    if (preg_match('/(?:Media|Average|avg)\s*=\s*([0-9.]+)\s*ms/i', $textoCompleto, $avgMatch)) {
        $val = round((float)$avgMatch[1]);
        if ($val == 0 && preg_match('/(?:tiempo|time)\s*<\s*1m/i', $textoCompleto)) {
            $avgLatency = '< 1 ms';
        } else {
            $avgLatency = $val . ' ms';
        }
    } elseif (preg_match('/min\/avg\/max[^\n=]*=\s*([0-9.]+)\/([0-9.]+)\/([0-9.]+)/i', $textoCompleto, $rttMatch)) {
        // Linux rtt
        $avgLatency = round((float)$rttMatch[2]) . ' ms';
        $minLatency = round((float)$rttMatch[1]) . ' ms';
        $maxLatency = round((float)$rttMatch[3]) . ' ms';
    } elseif (preg_match_all('/(?:tiempo|time)\s*[<=]\s*([0-9.]+)\s*m/i', $textoCompleto, $tMatches)) {
        $tiempos = array_map('floatval', $tMatches[1]);
        $avgLatency = round(array_sum($tiempos) / count($tiempos)) . ' ms';
    } elseif (preg_match('/(?:tiempo|time)\s*<\s*1m/i', $textoCompleto)) {
        $avgLatency = '< 1 ms';
    } else {
        $avgLatency = '0 ms';
    }
} else {
    $status = 'offline';
    $loss = '100%';
    $avgLatency = 'Sin respuesta';
}

// 7. Retornar respuesta enriquecida
echo json_encode([
    'success'      => true,
    'host'         => $hostClean,
    'label'        => $targetLabel,
    'status'       => $status,          // 'online', 'degraded', 'offline'
    'loss'         => $loss,
    'avg_latency'  => $avgLatency,
    'packets_ok'   => $respuestasOk,
    'packets_sent' => 4,
    'duration_ms'  => $duration,
    'raw_output'   => trim($textoCompleto),
    'lines'        => $salidaUtf8,
    'timestamp'    => date('Y-m-d H:i:s')
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
exit;
