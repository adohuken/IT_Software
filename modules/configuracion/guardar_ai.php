<?php
session_start();
require_once __DIR__ . '/../../config/conexion.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$api_key  = trim($_POST['api_key'] ?? '');
$model    = $_POST['model'] ?? 'deepseek-chat';
$provider = $_POST['provider'] ?? 'deepseek';
$action   = $_POST['action'] ?? 'save';

// Modelos permitidos (whitelist)
$modelos_validos = [
    'deepseek-chat', 'deepseek-reasoner',
    'gpt-4o-mini', 'gpt-4o', 'gpt-3.5-turbo',
    'llama3-8b-8192', 'llama3-70b-8192', 'mixtral-8x7b-32768',
    'gemini-3.5-flash', 'gemini-3.6-flash', 'gemini-2.5-flash'
];
if (!in_array($model, $modelos_validos)) {
    $model = 'deepseek-chat';
    $provider = 'deepseek';
}

// Si es eliminación, limpiar la key
if ($action === 'delete') {
    $api_key = '';
}

// Validación de la API Key mediante cURL
if (!empty($api_key) && $action !== 'delete') {
    $valid_key = false;
    $error_msg = 'Error al verificar la clave API.';

    if ($provider === 'gemini') {
        $url = "https://generativelanguage.googleapis.com/v1beta/models?key=" . urlencode($api_key);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $res = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($http === 200) {
            $valid_key = true;
        } else {
            $error_msg = "Clave de Gemini inválida. Verifica que esté bien copiada.";
        }
    } else {
        $base_urls = [
            'deepseek' => 'https://api.deepseek.com/models',
            'openai'   => 'https://api.openai.com/v1/models',
            'groq'     => 'https://api.groq.com/openai/v1/models'
        ];
        $url = $base_urls[$provider] ?? $base_urls['deepseek'];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ["Authorization: Bearer $api_key"]
        ]);
        $res = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http === 200) {
            $valid_key = true;
        } else {
            $error_msg = "Clave de " . ucfirst($provider) . " inválida o sin fondos (HTTP $http).";
        }
    }

    if (!$valid_key) {
        $_SESSION['flash_ai_err'] = $error_msg;
        header('Location: index.php');
        exit;
    }
}

// Construir el contenido del archivo de configuración
$config_content = "<?php\n// Archivo de configuración del Asistente IA\n// ADVERTENCIA: No compartir este archivo. Contiene credenciales sensibles.\nreturn [\n    'api_key' => " . var_export($api_key, true) . ",\n    'model'   => " . var_export($model, true) . ",\n    'provider' => " . var_export($provider, true) . ",\n];\n";

$config_path = __DIR__ . '/../../config/ai_config.php';

if (file_put_contents($config_path, $config_content) !== false) {
    if ($action === 'delete') {
        $_SESSION['flash_ai_ok'] = 'La API Key fue eliminada correctamente. El asistente volvió al modo de búsqueda básica.';
    } else if (empty($api_key)) {
        $_SESSION['flash_ai_ok'] = 'Configuración guardada. Recuerda agregar tu API Key para activar la IA.';
    } else {
        $_SESSION['flash_ai_ok'] = 'API Key guardada correctamente. El Asistente IT ya está conectado a ' . ucfirst(htmlspecialchars($provider)) . ' (' . htmlspecialchars($model) . ').';
    }
} else {
    $_SESSION['flash_ai_err'] = 'No se pudo escribir el archivo de configuración. Verifica que el directorio /config/ tenga permisos de escritura.';
}

header('Location: index.php');
exit;
