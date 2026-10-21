<?php
/**
 * Hoja de Impresión: Constancia de Entrega de Equipo
 */
session_start();
require_once __DIR__ . '/../../config/conexion.php';

// Validar sesión activa
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}

if (!isset($_GET['id'])) {
    die("ID de asignación no proporcionado.");
}

$id = (int)$_GET['id'];
$db = Database::getConexion();

$stmt = $db->prepare("
    SELECT 
        a.fecha_entrega,
        a.condicion_entrega,
        a.entregado_a,
        e.tipo,
        e.marca,
        e.modelo,
        e.service_tag_sn,
        emp.nombre AS empleado_nombre
    FROM asignaciones a
    JOIN equipos e ON a.equipo_id = e.id
    JOIN empleados emp ON a.empleado_id = emp.id
    WHERE a.id = ?
");
$stmt->execute([$id]);
$asig = $stmt->fetch();

if (!$asig) {
    die("No se encontró la asignación solicitada.");
}

// Configurar idioma español para fechas
$meses = ["Enero", "Febrero", "Marzo", "Abril", "Mayo", "Junio", "Julio", "Agosto", "Septiembre", "Octubre", "Noviembre", "Diciembre"];
$fecha_unix = strtotime($asig['fecha_entrega']);
$dia = date('d', $fecha_unix);
$mes = $meses[date('n', $fecha_unix) - 1];
$anio = date('Y', $fecha_unix);
$fecha_formateada = "{$dia} de {$mes} del {$anio}";
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Constancia de Entrega - <?= htmlspecialchars($asig['empleado_nombre']) ?></title>
    <link rel="icon" type="image/svg+xml" href="<?= BASE_URL ?>assets/images/favicon.svg">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= BASE_URL ?>assets/images/favicon-32x32.png">
    <link rel="shortcut icon" href="<?= BASE_URL ?>favicon.ico">
    <style>
        body {
            font-family: Arial, sans-serif;
            color: #000;
            margin: 0;
            font-size: 14px;
            line-height: 1.6;
        }
        @media screen {
            body {
                background-color: #f1f5f9;
                padding: 40px 20px;
                display: flex;
                justify-content: center;
            }
            .page-container {
                background: #ffffff;
                width: 21.59cm;
                min-height: 27.94cm;
                padding: 2cm;
                box-shadow: 0 10px 30px rgba(0,0,0,0.1);
                box-sizing: border-box;
                position: relative;
            }
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 60px;
        }
        .logo {
            display: flex;
            align-items: center;
        }
        .logo img {
            max-height: 60px;
        }
        .fecha {
            font-weight: bold;
            margin-top: 20px;
        }
        .title {
            text-align: center;
            font-weight: bold;
            font-size: 16px;
            margin-bottom: 40px;
        }
        .content {
            margin-bottom: 40px;
        }
        .equipment-list {
            list-style-type: disc;
            margin-left: 40px;
            margin-bottom: 30px;
        }
        .equipment-list li {
            margin-bottom: 5px;
        }
        .observations {
            margin-bottom: 20px;
        }
        .signatures {
            display: flex;
            justify-content: space-between;
            margin-top: 250px;
            padding: 0 40px;
        }
        .signature-box {
            text-align: center;
            width: 250px;
        }
        .signature-line {
            border-bottom: 1px solid #000;
            margin-bottom: 5px;
            height: 40px;
        }
        .footer {
            margin-top: 80px;
            font-weight: bold;
        }
        
        @media print {
            .no-print { display: none !important; }
            body {
                padding: 0;
                font-size: 12pt;
                background: #fff;
                display: block;
            }
            .page-container {
                width: 100%;
                min-height: auto;
                padding: 0;
                box-shadow: none;
            }
            .header {
                margin-bottom: 40px;
            }
            .signatures {
                margin-top: 300px;
            }
            .footer {
                position: fixed;
                bottom: 20px;
                left: 0;
            }
            @page {
                margin: 2cm;
            }
        }
    </style>
</head>
<body onload="window.print()">

<div class="page-container">
    <div class="header">
        <div class="logo">
            <img src="../../assets/images/logo_master.png" alt="Master Technologies">
        </div>
        <div class="fecha">
            Fecha: <?= $fecha_formateada ?>
        </div>
    </div>

    <div class="title">
        CONSTANCIA DE ENTREGA DE EQUIPO
    </div>

    <div class="content">
        <?php $receptor_fisico = !empty($asig['entregado_a']) ? $asig['entregado_a'] : $asig['empleado_nombre']; ?>
        <p>Por la presente, se hace entrega formal a <?= htmlspecialchars($receptor_fisico) ?> de los siguientes equipos:</p>
        
        <ul class="equipment-list">
            <li>Equipo: <?= htmlspecialchars($asig['marca'] . ' ' . $asig['modelo']) ?></li>
            <li>S/N: <?= htmlspecialchars($asig['service_tag_sn']) ?></li>
            <li>Accesorios/Condición: <?= htmlspecialchars($asig['condicion_entrega']) ?></li>
        </ul>

        <div class="observations">
            <strong>Observaciones:</strong> El equipo se entrega configurado con los datos y accesos correspondientes para la persona asignada: <?= htmlspecialchars($asig['empleado_nombre']) ?>.
        </div>

        <p>Al firmar, el receptor confirma que recibe el equipo en buen estado y se hace responsable de su resguardo y correcta asignación.</p>

        <div class="signatures">
            <div class="signature-box">
                <div class="signature-line"></div>
                Entrega
            </div>
            <div class="signature-box">
                <div class="signature-line"></div>
                Recibe
            </div>
        </div>

        <div class="footer">
            Departamento de IT
        </div>

        <div class="no-print" style="margin-top: 40px; text-align: center;">
            <button onclick="window.location.href='index.php'" style="padding: 10px 20px; background: #0ea5e9; color: white; border: none; border-radius: 8px; font-weight: bold; cursor: pointer;">
                Volver al Inventario
            </button>
            <button onclick="window.print()" style="padding: 10px 20px; background: #64748b; color: white; border: none; border-radius: 8px; font-weight: bold; cursor: pointer; margin-left: 10px;">
                Re-imprimir
            </button>
        </div>
    </div>
</div>

</body>
</html>
