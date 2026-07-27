<?php
/**
 * Script de Alertas y Vencimientos (Cron Job / Tarea Programada)
 * Evalúa tareas IT críticas y pagos M365 próximos. Envía notificaciones vía PHPMailer
 * y genera una copia local para depuración visual en assets/alertas_log.html.
 */

// Incluir archivos de conexión y zona horaria
require_once __DIR__ . '/../../config/conexion.php';

// Cargar clases de PHPMailer si existen (para el entorno de producción)
// En caso de que se configure Composer en el futuro o se agreguen los archivos directamente.
$phpmailer_available = false;
if (file_exists(__DIR__ . '/../../vendor/autoload.php')) {
    require_once __DIR__ . '/../../vendor/autoload.php';
    $phpmailer_available = true;
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Configuración SMTP de Alertas IT (Ajustar según datos de la empresa)
define('SMTP_HOST', 'smtp.office365.com'); // Ej: Office 365
define('SMTP_PORT', 587);
define('SMTP_USER', 'alertas-it@tuempresa.com');
define('SMTP_PASS', 'TuClaveSeguraM365');
define('SMTP_FROM_EMAIL', 'alertas-it@tuempresa.com');
define('SMTP_FROM_NAME', 'Alertas CuentasIT');
define('SMTP_TO_EMAIL', 'dmartinez@mastertec.com.ni'); // Administrador destinatario

try {
    $db = Database::getConexion();
    $fecha_actual = date('Y-m-d H:i:s');
    
    // 1. Obtener Tareas IT Críticas (Vencidas o por vencer en 24 horas)
    $stmt_tareas = $db->query("
        SELECT t.*, e.nombre AS asignado_nombre, e.email AS asignado_email 
        FROM tareas_it t 
        LEFT JOIN equipo_it e ON t.asignado_a = e.id 
        WHERE t.estado IN ('Pendiente', 'En Progreso', 'Vencida')
          AND (t.fecha_limite <= DATE_ADD(CURDATE(), INTERVAL 1 DAY) OR t.estado = 'Vencida')
        ORDER BY t.fecha_limite ASC
    ");
    $tareas_alertas = $stmt_tareas->fetchAll();

    // 2. Obtener Cuentas M365 Próximas a Pagar (En los siguientes 7 días)
    $stmt_m365 = $db->query("
        SELECT c.*, emp.nombre AS empleado_nombre 
        FROM cuentas_m365 c 
        LEFT JOIN empleados emp ON c.empleado_id = emp.id 
        WHERE c.estado = 'Activa' 
          AND c.fecha_proximo_pago <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
        ORDER BY c.fecha_proximo_pago ASC
    ");
    $m365_alertas = $stmt_m365->fetchAll();

    // Si no hay alertas críticas, detener ejecución o guardar log de "sin novedades"
    $hay_alertas = (!empty($tareas_alertas) || !empty($m365_alertas));

    // 3. Generar cuerpo HTML del correo con estilo corporativo oscuro premium
    $html_body = '
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <style>
            body { font-family: Arial, sans-serif; background-color: #0B0F19; color: #F3F4F6; margin: 0; padding: 20px; }
            .container { max-width: 600px; background-color: #151D30; border: 1px solid #1E293B; border-radius: 16px; padding: 30px; margin: 0 auto; box-shadow: 0 4px 20px rgba(0,0,0,0.3); }
            .header { text-align: center; border-bottom: 1px solid #1E293B; padding-bottom: 20px; margin-bottom: 25px; }
            .logo { font-size: 24px; font-weight: bold; color: #06B6D4; text-decoration: none; }
            .title { font-size: 18px; color: #FFFFFF; font-weight: bold; margin-bottom: 5px; }
            .subtitle { font-size: 12px; color: #9CA3AF; text-transform: uppercase; letter-spacing: 1px; }
            .section-title { font-size: 14px; font-weight: bold; text-transform: uppercase; color: #9CA3AF; border-left: 3px solid #3B82F6; padding-left: 10px; margin: 25px 0 15px 0; letter-spacing: 0.5px; }
            .table { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
            .table th { background-color: #0E1524; color: #9CA3AF; font-size: 11px; text-transform: uppercase; padding: 10px; text-align: left; border-bottom: 1px solid #1E293B; }
            .table td { padding: 12px 10px; font-size: 13px; color: #E5E7EB; border-bottom: 1px solid #1E293B; }
            .table tr:last-child td { border-bottom: none; }
            .badge { display: inline-block; padding: 3px 8px; border-radius: 12px; font-size: 10px; font-weight: bold; }
            .badge-danger { background-color: rgba(244, 63, 94, 0.15); color: #FB7185; border: 1px solid rgba(244, 63, 94, 0.3); }
            .badge-warning { background-color: rgba(245, 158, 11, 0.15); color: #F59E0B; border: 1px solid rgba(245, 158, 11, 0.3); }
            .footer { font-size: 11px; color: #6B7280; text-align: center; margin-top: 30px; border-t: 1px solid #1E293B; padding-top: 15px; }
            .no-data { font-style: italic; color: #6B7280; font-size: 12px; padding: 15px 0; }
        </style>
    </head>
    <body>
        <div class="container text-gray-100">
            <div class="header">
                <span class="logo">CuentasIT</span>
                <div class="title">Reporte Diario de Alertas y Vencimientos</div>
                <div class="subtitle">Monitoreo de Infraestructura IT</div>
            </div>';

    // Sección de Tareas
    $html_body .= '<div class="section-title">Tareas IT Críticas / Mantenimientos</div>';
    if (empty($tareas_alertas)) {
        $html_body .= '<div class="no-data">No se registran tareas vencidas ni próximas a expirar en las siguientes 24 horas.</div>';
    } else {
        $html_body .= '<table class="table">
            <thead>
                <tr>
                    <th>Tarea</th>
                    <th>Asignado A</th>
                    <th>Vencimiento</th>
                    <th>Estado</th>
                </tr>
            </thead>
            <tbody>';
        foreach ($tareas_alertas as $ta) {
            $fecha_venc = date('d/m/Y', strtotime($ta['fecha_limite']));
            $badge_style = ($ta['estado'] === 'Vencida') ? 'badge-danger' : 'badge-warning';
            $html_body .= "<tr>
                <td><b>" . htmlspecialchars($ta['titulo']) . "</b></td>
                <td>" . htmlspecialchars($ta['asignado_nombre'] ?? 'Sin asignar') . "</td>
                <td>{$fecha_venc}</td>
                <td><span class='badge {$badge_style}'>" . htmlspecialchars($ta['estado']) . "</span></td>
            </tr>";
        }
        $html_body .= '</tbody></table>';
    }

    // Sección de Microsoft 365
    $html_body .= '<div class="section-title">Facturación Microsoft 365 (Próximos 7 días)</div>';
    if (empty($m365_alertas)) {
        $html_body .= '<div class="no-data">No hay renovaciones de licencias M365 pendientes en los próximos 7 días.</div>';
    } else {
        $html_body .= '<table class="table">
            <thead>
                <tr>
                    <th>Cuenta</th>
                    <th>Licencia</th>
                    <th>Renovación</th>
                    <th>Ciclo</th>
                </tr>
            </thead>
            <tbody>';
        foreach ($m365_alertas as $m3) {
            $fecha_renov = date('d/m/Y', strtotime($m3['fecha_proximo_pago']));
            $dias_restantes = (int)( (strtotime($m3['fecha_proximo_pago']) - strtotime(date('Y-m-d'))) / 86400 );
            $badge_style = ($dias_restantes <= 3) ? 'badge-danger' : 'badge-warning';
            $html_body .= "<tr>
                <td><b>" . htmlspecialchars($m3['correo']) . "</b></td>
                <td>" . htmlspecialchars($m3['licencia_tipo']) . "</td>
                <td>{$fecha_renov} <span class='badge {$badge_style}'>En {$dias_restantes} d</span></td>
                <td>" . htmlspecialchars($m3['ciclo_facturacion']) . "</td>
            </tr>";
        }
        $html_body .= '</tbody></table>';
    }

    $html_body .= '
            <div class="footer">
                Este reporte fue generado de forma automática por el sistema local CuentasIT.<br>
                Hora de generación (Nicaragua): ' . date('d/m/Y H:i:s') . '
            </div>
        </div>
    </body>
    </html>';

    // 4. Guardar copia local del log en assets/alertas_log.html para depuración inmediata
    $log_dir = __DIR__ . '/../../assets';
    if (!is_dir($log_dir)) {
        mkdir($log_dir, 0777, true);
    }
    
    file_put_contents($log_dir . '/alertas_log.html', $html_body);
    
    // 5. Intentar enviar correo con PHPMailer si está disponible
    $mail_sent = false;
    $mail_status_msg = '';

    if ($hay_alertas) {
        if ($phpmailer_available) {
            $mail = new PHPMailer(true);
            try {
                // Configuraciones del servidor SMTP
                $mail->isSMTP();
                $mail->Host       = SMTP_HOST;
                $mail->SMTPAuth   = true;
                $mail->Username   = SMTP_USER;
                $mail->Password   = SMTP_PASS;
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port       = SMTP_PORT;
                $mail->CharSet    = 'UTF-8';

                // Destinatarios
                $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
                $mail->addAddress(SMTP_TO_EMAIL);

                // Contenido
                $mail->isHTML(true);
                $mail->Subject = 'CuentasIT - Alertas Críticas de Infraestructura';
                $mail->Body    = $html_body;

                $mail->send();
                $mail_sent = true;
                $mail_status_msg = 'Correo de alerta enviado con éxito a ' . SMTP_TO_EMAIL;
            } catch (Exception $e) {
                $mail_sent = false;
                $mail_status_msg = 'Error enviando correo SMTP: ' . $mail->ErrorInfo;
            }
        } else {
            // Intentar con la función mail() nativa de PHP como alternativa
            $headers  = "MIME-Version: 1.0\r\n";
            $headers .= "Content-type: text/html; charset=UTF-8\r\n";
            $headers .= "From: " . SMTP_FROM_NAME . " <" . SMTP_FROM_EMAIL . ">\r\n";
            
            if (@mail(SMTP_TO_EMAIL, 'CuentasIT - Alertas Criticas de Infraestructura', $html_body, $headers)) {
                $mail_sent = true;
                $mail_status_msg = 'Correo enviado usando mail() nativo de PHP.';
            } else {
                $mail_sent = false;
                $mail_status_msg = 'PHPMailer no está configurado (vendor/autoload.php ausente) y la función mail() nativa falló.';
            }
        }
    } else {
        $mail_status_msg = 'No se encontraron alertas críticas pendientes. No se requiere enviar notificaciones.';
    }

    // 6. Imprimir pantalla de estado amigable para el administrador de IT en el navegador
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <title>Ejecución Script Alertas - CuentasIT</title>
        <script src="https://cdn.tailwindcss.com"></script>
    </head>
    <body class="bg-[#0B0F19] text-gray-100 font-sans p-6 min-h-screen flex flex-col items-center justify-center">
        <div class="max-w-2xl w-full bg-white border border-slate-200 rounded-3xl p-8 shadow-2xl space-y-6">
            <div class="flex items-center gap-4 border-b border-slate-200 pb-4">
                <div class="h-12 w-12 rounded-2xl bg-cyan-500/10 border border-cyan-500/20 flex items-center justify-center text-cyan-400 text-xl">
                    <i class="fa-solid fa-clock-rotate-left"></i>
                </div>
                <div>
                    <h1 class="text-xl font-bold text-slate-800">Cron Job: Procesador de Alertas</h1>
                    <p class="text-xs text-slate-500">Ejecución del script programado en tiempo real</p>
                </div>
            </div>

            <!-- Caja de Estado -->
            <div class="bg-slate-100 border border-slate-200 rounded-2xl p-5 space-y-3 text-sm">
                <div>
                    <span class="text-slate-400 font-medium">Hora de Ejecución:</span>
                    <span class="font-mono text-cyan-400 ml-2"><?= $fecha_actual ?> (Managua)</span>
                </div>
                <div>
                    <span class="text-slate-400 font-medium">Alertas Encontradas:</span>
                    <span class="<?= $hay_alertas ? 'text-rose-400 font-bold' : 'text-emerald-400' ?> ml-2">
                        <?= $hay_alertas ? 'Sí (Críticas encontradas)' : 'No se detectaron novedades críticas' ?>
                    </span>
                </div>
                <div>
                    <span class="text-slate-400 font-medium">Estado del Envío:</span>
                    <span class="ml-2 font-mono text-slate-600"><?= htmlspecialchars($mail_status_msg) ?></span>
                </div>
            </div>

            <!-- Botones de Acción -->
            <div class="flex gap-4">
                <a href="../../assets/alertas_log.html" target="_blank" class="flex-1 py-3 text-center rounded-xl bg-cyan-500 hover:bg-cyan-600 text-black font-bold text-sm tracking-wide transition shadow-lg shadow-cyan-500/15">
                    Ver Correo Generado (Pre-visualizar)
                </a>
                <button onclick="window.close();" class="flex-1 py-3 rounded-xl bg-white/5 hover:bg-white/10 text-slate-800 font-semibold text-sm transition border border-slate-200">
                    Cerrar Ventana
                </button>
            </div>
        </div>
    </body>
    </html>
    <?php

} catch (Exception $e) {
    die("Error crítico ejecutando el Cron de alertas: " . $e->getMessage());
}
