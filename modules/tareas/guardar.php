<?php
/**
 * Controlador de Guardado para Módulo de Tareas IT
 * Procesa la creación de tareas y la lógica de regeneración por recurrencia
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
        
        // 1. Agregar Nueva Tarea / Ticket
        if ($action === 'add') {
            $titulo = trim($_POST['titulo']);
            $descripcion = trim($_POST['descripcion']);
            $asignado_a = !empty($_POST['asignado_a']) ? (int)$_POST['asignado_a'] : null;
            $fecha_limite = $_POST['fecha_limite'];
            $es_repetitiva = isset($_POST['es_repetitiva']) ? 1 : 0;
            $frecuencia_dias = ($es_repetitiva && !empty($_POST['frecuencia_dias'])) ? (int)$_POST['frecuencia_dias'] : null;

            $stmt = $db->prepare("
                INSERT INTO tareas_it (titulo, descripcion, asignado_a, fecha_limite, es_repetitiva, frecuencia_dias, estado) 
                VALUES (?, ?, ?, ?, ?, ?, 'Pendiente')
            ");
            $stmt->execute([$titulo, $descripcion, $asignado_a, $fecha_limite, $es_repetitiva, $frecuencia_dias]);

            header('Location: index.php');
            exit;
        }
    } else {
        // Acciones GET
        
        // 2. Cambiar Estado de una Tarea (Iniciar o Completar con lógica de recurrencia)
        if ($action === 'status') {
            $id = (int)$_GET['id'];
            $nuevo_estado = $_GET['status']; // 'En Progreso' o 'Completada'

            if (!in_array($nuevo_estado, ['En Progreso', 'Completada'])) {
                throw new Exception("Estado de tarea no válido.");
            }

            $db->beginTransaction();

            // Obtener datos actuales de la tarea
            $stmt_get = $db->prepare("SELECT * FROM tareas_it WHERE id = ?");
            $stmt_get->execute([$id]);
            $tarea = $stmt_get->fetch();

            if (!$tarea) {
                throw new Exception("No se encontró la tarea especificada.");
            }

            // Si se está marcando como Completada y es una tarea repetitiva, generar la siguiente
            if ($nuevo_estado === 'Completada' && $tarea['es_repetitiva'] && !empty($tarea['frecuencia_dias'])) {
                
                // Calcular la fecha límite de la siguiente recurrencia (fecha actual + frecuencia en días)
                $frecuencia = (int)$tarea['frecuencia_dias'];
                $nueva_fecha_limite = date('Y-m-d', strtotime('+' . $frecuencia . ' days'));
                
                // Insertar nueva tarea copia
                $stmt_new = $db->prepare("
                    INSERT INTO tareas_it (titulo, descripcion, asignado_a, fecha_limite, es_repetitiva, frecuencia_dias, estado) 
                    VALUES (?, ?, ?, ?, 1, ?, 'Pendiente')
                ");
                $stmt_new->execute([
                    $tarea['titulo'], 
                    $tarea['descripcion'], 
                    $tarea['asignado_a'], 
                    $nueva_fecha_limite, 
                    $frecuencia
                ]);
            }

            // Actualizar el estado de la tarea actual
            $stmt_update = $db->prepare("UPDATE tareas_it SET estado = ? WHERE id = ?");
            $stmt_update->execute([$nuevo_estado, $id]);

            $db->commit();
            
            header('Location: index.php');
            exit;
        }
    }
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    die("Error procesando tareas: " . $e->getMessage());
}
