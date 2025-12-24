<?php
declare(strict_types=1);
require __DIR__ . "/../config/db.php";

header("Content-Type: text/plain; charset=utf-8");

$token = $_GET["token"] ?? "";
if ($token !== "CHANGE_ME_123") {
    http_response_code(403);
    exit("Forbidden");
}

function loadJson(string $path): array {
    if (!file_exists($path)) {
        throw new RuntimeException("Missing file: $path");
    }
    $raw = file_get_contents($path);
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new RuntimeException("Bad JSON: $path");
    }
    return $data;
}

$base = dirname(__DIR__);

$weather = loadJson($base . "/HomeworkProject.weatherConditions.json");
$road    = loadJson($base . "/HomeworkProject.roadConditions.json");
$traffic = loadJson($base . "/HomeworkProject.stateOfTraffic.json");
$speed   = loadJson($base . "/HomeworkProject.speedLimits.json");
$driving = loadJson($base . "/HomeworkProject.drivingExperiences.json");

$pdo->beginTransaction();

try {

    $pdo->exec("DELETE FROM driving_experience_road");
    $pdo->exec("DELETE FROM driving_experiences");
    $pdo->exec("DELETE FROM weather_conditions");
    $pdo->exec("DELETE FROM road_conditions");
    $pdo->exec("DELETE FROM state_of_traffic");
    $pdo->exec("DELETE FROM speed_limits");

    $st = $pdo->prepare("INSERT INTO weather_conditions (idWeatherCond, weatherType) VALUES (?, ?)");
    foreach ($weather as $w) {
        $st->execute([(int)$w["idWeatherCond"], (string)$w["weatherType"]]);
    }

    $st = $pdo->prepare("INSERT INTO road_conditions (idRoadCond, roadCondition) VALUES (?, ?)");
    foreach ($road as $r) {
        $st->execute([(int)$r["idRoadCond"], (string)$r["roadCondition"]]);
    }

    $st = $pdo->prepare("INSERT INTO state_of_traffic (idStateOfTraffic, traffic) VALUES (?, ?)");
    foreach ($traffic as $t) {
        $st->execute([(int)$t["idStateOfTraffic"], (string)$t["traffic"]]);
    }

    $st = $pdo->prepare("INSERT INTO speed_limits (idSpeedLimit, speedLimit_km_h) VALUES (?, ?)");
    foreach ($speed as $s) {
        $st->execute([(int)$s["idSpeedLimit"], (int)$s["speedLimit_km_h"]]);
    }

    $insExp = $pdo->prepare(
        "INSERT INTO driving_experiences
         (idDrivingExp, date, startTime, endTime, distance_in_km, idWeatherCond, idStateOfTraffic, idSpeedLimit)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );

    $insRoad = $pdo->prepare(
        "INSERT INTO driving_experience_road (idDrivingExp, idRoadCond) VALUES (?, ?)"
    );

    foreach ($driving as $d) {
        $id = (int)$d["idDrivingExp"];

        $insExp->execute([
            $id,
            (string)$d["date"],
            (string)$d["startTime"],
            (string)$d["endTime"],
            (float)$d["distance_in_km"],
            (int)$d["idWeatherCond"],
            (int)$d["idStateOfTraffic"],
            (int)$d["idSpeedLimit"],
        ]);

        $insRoad->execute([$id, (int)$d["idRoadCond"]]);
    }

    $pdo->commit();
    echo "Import OK\n";
    echo "Now delete this file: /driving/admin/import_json.php\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo "Import failed: " . $e->getMessage() . "\n";
}
