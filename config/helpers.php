<?php
/**
 * helpers.php - Funciones compartidas entre modulos
 * Incluir con: require_once __DIR__ . "/helpers.php";
 */

/**
 * Calcula la Fecha del Proximo Pago/Renovacion de Licencia
 * @param string $fechaInit  Fecha de inicio en formato Y-m-d
 * @param string $ciclo      "Mensual" o "Anual"
 * @return string            Fecha del proximo pago en formato Y-m-d
 */
function calcularProximoPago(string $fechaInit, string $ciclo): string {
    $init = new DateTime($fechaInit);
    $hoy  = new DateTime();
    $hoy->setTime(0, 0, 0);

    if ($ciclo === "Anual") {
        $mesInit = (int)$init->format("m");
        $diaInit = (int)$init->format("d");
        $proximo = new DateTime();
        $proximo->setDate($hoy->format("Y"), $mesInit, $diaInit);
        $proximo->setTime(0, 0, 0);
        if ($proximo <= $hoy) {
            $proximo->modify("+1 year");
        }
        return $proximo->format("Y-m-d");
    }

    // Mensual (default)
    $diaInit      = (int)$init->format("d");
    $ultimoDiaMes = (int)date("t", strtotime($hoy->format("Y-m-d")));
    $diaAjustado  = min($diaInit, $ultimoDiaMes);

    $proximo = new DateTime();
    $proximo->setDate($hoy->format("Y"), $hoy->format("m"), $diaAjustado);
    $proximo->setTime(0, 0, 0);

    if ($proximo <= $hoy) {
        $proximo->modify("+1 month");
        $ultimoDiaSig = (int)date("t", strtotime($proximo->format("Y-m-d")));
        $proximo->setDate($proximo->format("Y"), $proximo->format("m"), min($diaInit, $ultimoDiaSig));
    }

    return $proximo->format("Y-m-d");
}
