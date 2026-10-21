<?php
/**
 * Hoja Imprimible de "CONSTANCIA DE ENTREGA DE EQUIPO"
 * Réplica exacta del formato corporativo del Acta de Entrega
 */
session_start();
require_once __DIR__ . '/../../config/conexion.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}

if (!isset($_GET['id'])) {
    die("ID de registro no proporcionado.");
}

$id = (int)$_GET['id'];
$db = Database::getConexion();

$stmt = $db->prepare("SELECT * FROM recepciones_equipos WHERE id = ?");
$stmt->execute([$id]);
$rec = $stmt->fetch();

if (!$rec) {
    die("No se encontró el registro de entrega solicitado.");
}

// Formatear Fecha en Español (Ej: 27 de Agosto del 2026)
$meses = ["Enero", "Febrero", "Marzo", "Abril", "Mayo", "Junio", "Julio", "Agosto", "Septiembre", "Octubre", "Noviembre", "Diciembre"];
$fecha_unix = strtotime($rec['fecha_entrega'] ?: $rec['fecha_ingreso']);
$dia = (int)date('d', $fecha_unix);
$mes = $meses[date('n', $fecha_unix) - 1];
$anio = date('Y', $fecha_unix);
$fecha_formateada = "{$dia} de {$mes} del {$anio}";
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Constancia de Entrega de Equipo - <?= htmlspecialchars($rec['receptor_final']) ?></title>
    <link rel="icon" type="image/svg+xml" href="<?= BASE_URL ?>assets/images/favicon.svg">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= BASE_URL ?>assets/images/favicon-32x32.png">
    <link rel="shortcut icon" href="<?= BASE_URL ?>favicon.ico">
    <style>
        @page {
            size: letter;
            margin: 2.5cm 2.5cm 2.5cm 2.5cm;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            color: #000000;
            margin: 0;
            padding: 0;
            font-size: 13px;
            line-height: 1.6;
            background-color: #ffffff;
        }

        @media screen {
            body {
                background-color: #f1f5f9;
                padding: 40px 20px;
                display: flex;
                justify-content: center;
            }
            .document-container {
                background: #ffffff;
                width: 21.59cm;
                min-height: 27.94cm;
                padding: 2.5cm;
                box-shadow: 0 10px 30px rgba(0,0,0,0.12);
                box-sizing: border-box;
                position: relative;
            }
        }

        @media print {
            .no-print {
                display: none !important;
            }
            body {
                background-color: #ffffff;
                padding: 0;
            }
            .document-container {
                width: 100%;
                min-height: auto;
                padding: 0;
                box-shadow: none;
            }
        }

        /* Encabezado Logo y Fecha */
        .top-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 50px;
        }

        .logo-box img {
            max-height: 55px;
            width: auto;
        }

        .date-box {
            font-size: 14px;
            font-weight: bold;
            color: #000000;
            margin-top: 15px;
        }

        /* Título Principal */
        .main-title {
            text-align: center;
            font-size: 15px;
            font-weight: bold;
            color: #000000;
            letter-spacing: 0.5px;
            margin-bottom: 50px;
            text-transform: uppercase;
        }

        /* Contenido del Documento */
        .content-body {
            font-size: 13px;
            color: #000000;
            margin-bottom: 30px;
        }

        .intro-text {
            margin-bottom: 20px;
            font-size: 13.5px;
        }

        .equipment-list {
            list-style: none;
            padding-left: 30px;
            margin-top: 10px;
            margin-bottom: 30px;
        }

        .equipment-list li {
            position: relative;
            margin-bottom: 8px;
            font-size: 13px;
        }

        .equipment-list li::before {
            content: "•";
            position: absolute;
            left: -18px;
            font-weight: bold;
            font-size: 14px;
        }

        .observations-box {
            margin-top: 25px;
            margin-bottom: 25px;
            font-size: 13px;
        }

        .legal-clause {
            margin-top: 25px;
            font-size: 13px;
            line-height: 1.5;
        }

        /* Firmas */
        .signatures-section {
            display: flex;
            justify-content: space-between;
            margin-top: 220px;
            padding: 0 50px;
        }

        .signature-box {
            text-align: center;
            width: 220px;
        }

        .signature-line {
            border-top: 1.5px solid #000000;
            margin-bottom: 6px;
        }

        .signature-label {
            font-size: 13px;
            font-weight: bold;
            color: #000000;
        }

        /* Pie de página */
        .document-footer {
            margin-top: 100px;
            font-size: 13px;
            font-weight: bold;
            color: #000000;
        }
    </style>
</head>
<body onload="window.print()">

<div class="document-container">

    <!-- Encabezado: Logo e Indicador de Fecha -->
    <div class="top-header">
        <div class="logo-box">
            <img src="../../assets/images/logo_master.png" alt="Master Technologies">
        </div>
        <div class="date-box">
            Fecha: <?= htmlspecialchars($fecha_formateada) ?>
        </div>
    </div>

    <!-- Título Principal -->
    <div class="main-title">
        CONSTANCIA DE ENTREGA DE EQUIPO
    </div>

    <!-- Cuerpo del Documento -->
    <div class="content-body">
        <div class="intro-text">
            Por la presente, se hace entrega formal a <strong><?= htmlspecialchars($rec['receptor_final']) ?></strong> del siguiente equipo:
        </div>

        <ul class="equipment-list">
            <li><strong>Equipo:</strong> <?= htmlspecialchars($rec['equipo_tipo'] . ' ' . $rec['equipo_marca'] . ' ' . $rec['equipo_modelo']) ?></li>
            <li><strong>S/N:</strong> <?= htmlspecialchars($rec['service_tag_sn']) ?></li>
            <li><strong>Accesorios:</strong> <?= htmlspecialchars($rec['accesorios'] ?: 'Cargador.+') ?></li>
        </ul>

        <?php if (!empty($rec['observaciones'])): ?>
        <div class="observations-box">
            <strong>Observaciones:</strong> <?= htmlspecialchars($rec['observaciones']) ?>
        </div>
        <?php endif; ?>

        <div class="legal-clause">
            Al firmar, el receptor confirma que recibe el equipo en buen estado y se hace responsable de su resguardo y correcta asignación.
        </div>

        <!-- Sección de Firmas -->
        <div class="signatures-section">
            <div class="signature-box">
                <div class="signature-line"></div>
                <div class="signature-label">Entrega</div>
            </div>
            <div class="signature-box">
                <div class="signature-line"></div>
                <div class="signature-label">Recibe</div>
            </div>
        </div>

        <!-- Pie de página -->
        <div class="document-footer">
            Departamento de IT
        </div>
    </div>

    <!-- Botones de Acción de Pantalla (Ocultos en impresión) -->
    <div class="no-print" style="margin-top: 50px; text-align: center; border-top: 1px solid #e2e8f0; padding-top: 25px;">
        <button onclick="window.print()" style="padding: 12px 24px; background: #0284c7; color: white; border: none; border-radius: 10px; font-weight: bold; font-size: 13px; cursor: pointer; margin-right: 12px; box-shadow: 0 4px 12px rgba(2,132,199,0.2);">
            <i class="fa-solid fa-print"></i> Imprimir Constancia (PDF)
        </button>
        <button onclick="window.location.href='index.php'" style="padding: 12px 24px; background: #475569; color: white; border: none; border-radius: 10px; font-weight: bold; font-size: 13px; cursor: pointer;">
            Volver a Recepción y Entregas
        </button>
    </div>

</div>

</body>
</html>
