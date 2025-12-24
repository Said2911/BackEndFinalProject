<?php
declare(strict_types=1);
require __DIR__ . "/config/db.php";

$id = isset($_GET["id"]) ? (int)$_GET["id"] : 0;
if ($id <= 0) { http_response_code(400); echo "Bad request"; exit; }

$weatherOpts = $pdo->query("SELECT idWeatherCond, weatherType FROM weather_conditions ORDER BY weatherType")->fetchAll();
$trafficOpts = $pdo->query("SELECT idStateOfTraffic, traffic FROM state_of_traffic ORDER BY idStateOfTraffic")->fetchAll();
$speedOpts   = $pdo->query("SELECT idSpeedLimit, speedLimit_km_h FROM speed_limits ORDER BY speedLimit_km_h")->fetchAll();
$roadOpts    = $pdo->query("SELECT idRoadCond, roadCondition FROM road_conditions ORDER BY roadCondition")->fetchAll();

$st = $pdo->prepare("
SELECT d.*
FROM driving_experiences d
WHERE d.idDrivingExp = :id
LIMIT 1
");
$st->execute([":id" => $id]);
$row = $st->fetch();
if (!$row) { http_response_code(404); echo "Not found"; exit; }

$st = $pdo->prepare("SELECT idRoadCond FROM driving_experience_road WHERE idDrivingExp = :id");
$st->execute([":id" => $id]);
$selectedRoads = array_map(fn($x) => (int)$x["idRoadCond"], $st->fetchAll());

$errors = [];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $date = trim((string)($_POST["date"] ?? ""));
    $startTime = trim((string)($_POST["startTime"] ?? ""));
    $endTime = trim((string)($_POST["endTime"] ?? ""));
    $distance = trim((string)($_POST["distance_in_km"] ?? ""));
    $idWeather = (int)($_POST["idWeatherCond"] ?? 0);
    $idTraffic = (int)($_POST["idStateOfTraffic"] ?? 0);
    $idSpeed = (int)($_POST["idSpeedLimit"] ?? 0);
    $roads = $_POST["roads"] ?? [];
    if (!is_array($roads)) $roads = [];
    $roads = array_values(array_unique(array_filter(array_map("intval", $roads), fn($v) => $v > 0)));

    if ($date === "") $errors[] = "Date is required";
    if ($startTime === "") $errors[] = "Start time is required";
    if ($endTime === "") $errors[] = "End time is required";
    if ($distance === "" || !is_numeric($distance) || (float)$distance < 0) $errors[] = "Distance must be a non-negative number";
    if ($idWeather <= 0) $errors[] = "Weather is required";
    if ($idTraffic <= 0) $errors[] = "Traffic is required";
    if ($idSpeed <= 0) $errors[] = "Speed limit is required";

    if (!$errors) {
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare("
                UPDATE driving_experiences
                SET date = :date,
                    startTime = :startTime,
                    endTime = :endTime,
                    distance_in_km = :dist,
                    idWeatherCond = :w,
                    idStateOfTraffic = :t,
                    idSpeedLimit = :s
                WHERE idDrivingExp = :id
            ");
            $st->execute([
                ":date" => $date,
                ":startTime" => $startTime,
                ":endTime" => $endTime,
                ":dist" => (float)$distance,
                ":w" => $idWeather,
                ":t" => $idTraffic,
                ":s" => $idSpeed,
                ":id" => $id,
            ]);

            $st = $pdo->prepare("DELETE FROM driving_experience_road WHERE idDrivingExp = :id");
            $st->execute([":id" => $id]);

            if ($roads) {
                $st = $pdo->prepare("INSERT INTO driving_experience_road (idDrivingExp, idRoadCond) VALUES (:id, :r)");
                foreach ($roads as $r) {
                    $st->execute([":id" => $id, ":r" => $r]);
                }
            }

            $pdo->commit();
            header("Location: summary.php");
            exit;
        } catch (Throwable $e) {
            $pdo->rollBack();
            http_response_code(500);
            echo "Server error";
            exit;
        }
    }

    $row["date"] = $date;
    $row["startTime"] = $startTime;
    $row["endTime"] = $endTime;
    $row["distance_in_km"] = $distance;
    $row["idWeatherCond"] = $idWeather;
    $row["idStateOfTraffic"] = $idTraffic;
    $row["idSpeedLimit"] = $idSpeed;
    $selectedRoads = $roads;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Edit Driving Experience</title>
  <style>
    body{font-family:Arial,sans-serif;background:#2c3e50;color:#ecf0f1;margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;box-sizing:border-box}
    .card{width:100%;max-width:780px;background:#34495e;border-radius:14px;padding:18px;box-shadow:0 4px 30px rgba(241,178,5,.25)}
    h1{margin:0 0 14px;color:#f39c12}
    .grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
    label{display:block;font-size:.9rem;opacity:.92;margin-bottom:6px}
    input,select{width:100%;padding:10px;border-radius:10px;border:1px solid rgba(255,255,255,.15);background:rgba(255,255,255,.08);color:#fff;box-sizing:border-box}
    option{color:#000}
    .row{margin-bottom:12px}
    .roads{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
    .roadItem{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);border-radius:12px;padding:10px;display:flex;gap:10px;align-items:center}
    .roadItem input{width:auto}
    .actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px}
    .btn{background:#f39c12;color:#2c3e50;padding:10px 14px;border-radius:10px;text-decoration:none;font-weight:800;border:none;cursor:pointer;display:inline-block}
    .btn.secondary{background:rgba(255,255,255,.12);color:#fff;font-weight:700}
    .btn:hover{transform:scale(1.03)}
    .err{background:rgba(231,76,60,.18);border:1px solid rgba(231,76,60,.35);padding:12px;border-radius:12px;margin-bottom:12px}
    @media(max-width:720px){.grid{grid-template-columns:1fr}.roads{grid-template-columns:1fr}}
  </style>
</head>
<body>
  <div class="card">
    <h1>Edit record #<?= (int)$id ?></h1>

    <?php if ($errors): ?>
      <div class="err">
        <?php foreach ($errors as $e): ?>
          <div><?= htmlspecialchars($e) ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <form method="post">
      <div class="grid">
        <div class="row">
          <label>Date</label>
          <input type="date" name="date" value="<?= htmlspecialchars((string)$row["date"]) ?>">
        </div>

        <div class="row">
          <label>Distance (km)</label>
          <input type="number" step="0.1" name="distance_in_km" value="<?= htmlspecialchars((string)$row["distance_in_km"]) ?>">
        </div>

        <div class="row">
          <label>Start time</label>
          <input type="time" name="startTime" value="<?= htmlspecialchars((string)$row["startTime"]) ?>">
        </div>

        <div class="row">
          <label>End time</label>
          <input type="time" name="endTime" value="<?= htmlspecialchars((string)$row["endTime"]) ?>">
        </div>

        <div class="row">
          <label>Weather</label>
          <select name="idWeatherCond">
            <option value="0">Select</option>
            <?php foreach ($weatherOpts as $o): ?>
              <option value="<?= (int)$o["idWeatherCond"] ?>" <?= (int)$row["idWeatherCond"]===(int)$o["idWeatherCond"]?"selected":"" ?>>
                <?= htmlspecialchars((string)$o["weatherType"]) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="row">
          <label>Traffic</label>
          <select name="idStateOfTraffic">
            <option value="0">Select</option>
            <?php foreach ($trafficOpts as $o): ?>
              <option value="<?= (int)$o["idStateOfTraffic"] ?>" <?= (int)$row["idStateOfTraffic"]===(int)$o["idStateOfTraffic"]?"selected":"" ?>>
                <?= htmlspecialchars((string)$o["traffic"]) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="row" style="grid-column:1/-1">
          <label>Speed limit</label>
          <select name="idSpeedLimit">
            <option value="0">Select</option>
            <?php foreach ($speedOpts as $o): ?>
              <option value="<?= (int)$o["idSpeedLimit"] ?>" <?= (int)$row["idSpeedLimit"]===(int)$o["idSpeedLimit"]?"selected":"" ?>>
                <?= (int)$o["speedLimit_km_h"] ?> km/h
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="row" style="grid-column:1/-1">
          <label>Road conditions</label>
          <div class="roads">
            <?php foreach ($roadOpts as $o): $rid = (int)$o["idRoadCond"]; ?>
              <label class="roadItem">
                <input type="checkbox" name="roads[]" value="<?= $rid ?>" <?= in_array($rid, $selectedRoads, true) ? "checked" : "" ?>>
                <span><?= htmlspecialchars((string)$o["roadCondition"]) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <div class="actions">
        <button class="btn" type="submit">Save</button>
        <a class="btn secondary" href="summary.php">Cancel</a>
      </div>
    </form>
  </div>
</body>
</html>
