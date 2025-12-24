<?php
declare(strict_types=1);
header("Content-Type: application/json");

require __DIR__ . "/config/db.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Method not allowed"]);
    exit;
}

$date            = $_POST["date"] ?? null;
$startTime       = $_POST["startTime"] ?? null;
$endTime         = $_POST["endTime"] ?? null;
$distance        = $_POST["distance_in_km"] ?? null;
$idWeatherCond   = $_POST["idWeatherCond"] ?? null;
$idStateTraffic  = $_POST["idStateOfTraffic"] ?? null;
$idSpeedLimit    = $_POST["idSpeedLimit"] ?? null;
$roadConds       = $_POST["roadConds"] ?? [];

if (
    !$date || !$startTime || !$endTime ||
    !$distance || !$idWeatherCond ||
    !$idStateTraffic || !$idSpeedLimit ||
    !is_array($roadConds) || count($roadConds) === 0
) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Missing or invalid fields"]);
    exit;
}

try {

    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        "INSERT INTO driving_experiences
        (date, startTime, endTime, distance_in_km, idWeatherCond, idStateOfTraffic, idSpeedLimit)
        VALUES (?, ?, ?, ?, ?, ?, ?)"
    );

    $stmt->execute([
        $date,
        $startTime,
        $endTime,
        (float)$distance,
        (int)$idWeatherCond,
        (int)$idStateTraffic,
        (int)$idSpeedLimit
    ]);

    $idDrivingExp = (int)$pdo->lastInsertId();

    $stmtRoad = $pdo->prepare(
        "INSERT INTO driving_experience_road (idDrivingExp, idRoadCond)
         VALUES (?, ?)"
    );

    foreach ($roadConds as $roadId) {
        $stmtRoad->execute([
            $idDrivingExp,
            (int)$roadId
        ]);
    }

    $pdo->commit();

    echo json_encode(["status" => "success"]);
} catch (Throwable $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "Database error"
    ]);
}
