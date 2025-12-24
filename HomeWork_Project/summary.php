<?php
declare(strict_types=1);
require __DIR__ . "/config/db.php";

function nice_case(?string $s): string {
    $s = trim((string)$s);
    if ($s === "") return "-";
    if (function_exists("mb_convert_case")) return mb_convert_case($s, MB_CASE_TITLE, "UTF-8");
    return ucwords(strtolower($s));
}

function clamp_sort(string $sort, string $dir): array {
    $allowed = [
        "date" => "d.date",
        "km" => "d.distance_in_km",
        "speed" => "s.speedLimit_km_h",
        "weather" => "w.weatherType",
        "traffic" => "t.traffic",
    ];
    $sortCol = $allowed[$sort] ?? "d.date";
    $dir = strtoupper($dir) === "ASC" ? "ASC" : "DESC";
    return [$sortCol, $dir];
}

function url_with(array $extra): string {
    $q = $_GET;
    foreach ($extra as $k => $v) {
        if ($v === null) unset($q[$k]);
        else $q[$k] = $v;
    }
    return "summary.php?" . http_build_query($q);
}

function render_rows_html(array $rows): string {
    ob_start(); ?>
    <?php if (!$rows): ?>
        <tr><td colspan="9">No results for these filters.</td></tr>
    <?php else: ?>
        <?php foreach ($rows as $r): ?>
        <tr>
            <td><?= htmlspecialchars((string)$r["date"]) ?></td>
            <td><?= htmlspecialchars((string)$r["startTime"]) ?></td>
            <td><?= htmlspecialchars((string)$r["endTime"]) ?></td>
            <td><?= htmlspecialchars((string)$r["distance_in_km"]) ?></td>
            <td><?= htmlspecialchars(nice_case((string)$r["weatherType"])) ?></td>
            <td><?= htmlspecialchars(nice_case((string)$r["traffic"])) ?></td>
            <td><?= (int)$r["speedLimit_km_h"] ?> km/h</td>
            <td class="col-road"><?= htmlspecialchars(nice_case((string)($r["road_conditions"] ?? "-"))) ?></td>
            <td>
                <div class="actions">
                    <a class="btn small success" href="edit.php?id=<?= (int)$r["idDrivingExp"] ?>">Edit</a>
                    <a class="btn small danger" href="delete.php?id=<?= (int)$r["idDrivingExp"] ?>" onclick="return confirm('Delete this record?');">Delete</a>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
    <?php endif; ?>
    <?php
    return (string)ob_get_clean();
}

function render_pager_html(bool $isAll, int $perPage, int $page, int $totalPages, int $totalRows): string {
    ob_start(); ?>
    <span class="muted">
        Results: <?= (int)$totalRows ?>
        <?= $isAll ? "" : " • Page $page / $totalPages • $perPage rows/page" ?>
    </span>

    <?php if (!$isAll): ?>
        <?php if ($page > 1): ?>
            <a class="btn secondary" href="<?= htmlspecialchars(url_with(["page" => $page - 1])) ?>">← Prev</a>
        <?php endif; ?>
        <?php if ($page < $totalPages): ?>
            <a class="btn secondary" href="<?= htmlspecialchars(url_with(["page" => $page + 1])) ?>">Next →</a>
        <?php endif; ?>
    <?php endif; ?>
    <?php
    return (string)ob_get_clean();
}

$f_weather = isset($_GET["weather"]) ? (int)$_GET["weather"] : 0;
$f_traffic = isset($_GET["traffic"]) ? (int)$_GET["traffic"] : 0;
$f_speed   = isset($_GET["speed"]) ? (int)$_GET["speed"] : 0;
$f_road    = isset($_GET["road"]) ? (int)$_GET["road"] : 0;
$f_from    = trim((string)($_GET["from"] ?? ""));
$f_to      = trim((string)($_GET["to"] ?? ""));

$sort = (string)($_GET["sort"] ?? "date");
$dir  = (string)($_GET["dir"] ?? "DESC");
[$sortCol, $sortDir] = clamp_sort($sort, $dir);

$page = max(1, (int)($_GET["page"] ?? 1));

$allowedPerPage = [5, 10, 15, 20];
$perPageRaw = (string)($_GET["perPage"] ?? "5");
$isAll = ($perPageRaw === "all");
$perPage = $isAll ? 5 : (int)$perPageRaw;
if (!$isAll && !in_array($perPage, $allowedPerPage, true)) {
    $perPage = 5;
}

$weatherOpts = $pdo->query("SELECT idWeatherCond, weatherType FROM weather_conditions ORDER BY weatherType")->fetchAll();
$trafficOpts = $pdo->query("SELECT idStateOfTraffic, traffic FROM state_of_traffic ORDER BY idStateOfTraffic")->fetchAll();
$speedOpts   = $pdo->query("SELECT idSpeedLimit, speedLimit_km_h FROM speed_limits ORDER BY speedLimit_km_h")->fetchAll();
$roadOpts    = $pdo->query("SELECT idRoadCond, roadCondition FROM road_conditions ORDER BY roadCondition")->fetchAll();

$where = [];
$params = [];

if ($f_weather > 0) {
    $where[] = "d.idWeatherCond = :weather";
    $params[":weather"] = $f_weather;
}
if ($f_traffic > 0) {
    $where[] = "d.idStateOfTraffic = :traffic";
    $params[":traffic"] = $f_traffic;
}
if ($f_speed > 0) {
    $where[] = "d.idSpeedLimit = :speed";
    $params[":speed"] = $f_speed;
}
if ($f_from !== "") {
    $where[] = "d.date >= :from";
    $params[":from"] = $f_from;
}
if ($f_to !== "") {
    $where[] = "d.date <= :to";
    $params[":to"] = $f_to;
}
if ($f_road > 0) {
    $where[] = "EXISTS (
        SELECT 1 FROM driving_experience_road dr2
        WHERE dr2.idDrivingExp = d.idDrivingExp AND dr2.idRoadCond = :road
    )";
    $params[":road"] = $f_road;
}

$whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : "";

$st = $pdo->prepare("SELECT COALESCE(SUM(d.distance_in_km),0) AS total_km
                     FROM driving_experiences d
                     $whereSql");
$st->execute($params);
$totalKm = (float)$st->fetch()["total_km"];

$st = $pdo->prepare("SELECT COUNT(*) AS cnt FROM driving_experiences d $whereSql");
$st->execute($params);
$totalRows = (int)$st->fetch()["cnt"];

if ($isAll) {
    $totalPages = 1;
    $page = 1;
    $offset = 0;
    $limitSql = "";
} else {
    $totalPages = max(1, (int)ceil($totalRows / $perPage));
    $page = max(1, min($page, $totalPages));
    $offset = ($page - 1) * $perPage;
    $limitSql = "LIMIT :limit OFFSET :offset";
}

$sqlRows = "
SELECT
  d.idDrivingExp,
  d.date, d.startTime, d.endTime, d.distance_in_km,
  w.weatherType,
  t.traffic,
  s.speedLimit_km_h,
  GROUP_CONCAT(r.roadCondition ORDER BY r.roadCondition SEPARATOR ', ') AS road_conditions
FROM driving_experiences d
JOIN weather_conditions w ON d.idWeatherCond = w.idWeatherCond
JOIN state_of_traffic t ON d.idStateOfTraffic = t.idStateOfTraffic
JOIN speed_limits s ON d.idSpeedLimit = s.idSpeedLimit
LEFT JOIN driving_experience_road dr ON d.idDrivingExp = dr.idDrivingExp
LEFT JOIN road_conditions r ON dr.idRoadCond = r.idRoadCond
$whereSql
GROUP BY d.idDrivingExp
ORDER BY $sortCol $sortDir
$limitSql
";

$st = $pdo->prepare($sqlRows);
foreach ($params as $k => $v) $st->bindValue($k, $v);
if (!$isAll) {
    $st->bindValue(":limit", $perPage, PDO::PARAM_INT);
    $st->bindValue(":offset", $offset, PDO::PARAM_INT);
}
$st->execute();
$rows = $st->fetchAll();

$st = $pdo->prepare("
SELECT w.weatherType AS label, COUNT(*) AS cnt
FROM driving_experiences d
JOIN weather_conditions w ON d.idWeatherCond = w.idWeatherCond
$whereSql
GROUP BY w.weatherType
ORDER BY cnt DESC
");
$st->execute($params);
$weatherAgg = $st->fetchAll();

$st = $pdo->prepare("
SELECT t.traffic AS label, COUNT(*) AS cnt
FROM driving_experiences d
JOIN state_of_traffic t ON d.idStateOfTraffic = t.idStateOfTraffic
$whereSql
GROUP BY t.traffic
ORDER BY cnt DESC
");
$st->execute($params);
$trafficAgg = $st->fetchAll();

$st = $pdo->prepare("
SELECT r.roadCondition AS label, COUNT(*) AS cnt
FROM driving_experiences d
JOIN driving_experience_road dr ON dr.idDrivingExp = d.idDrivingExp
JOIN road_conditions r ON r.idRoadCond = dr.idRoadCond
$whereSql
GROUP BY r.roadCondition
ORDER BY cnt DESC
");
$st->execute($params);
$roadAgg = $st->fetchAll();

$st = $pdo->prepare("
SELECT d.date AS label, SUM(d.distance_in_km) AS km
FROM driving_experiences d
$whereSql
GROUP BY d.date
ORDER BY d.date ASC
");
$st->execute($params);
$evoAgg = $st->fetchAll();

$weatherLabels = array_map(fn($x) => nice_case($x["label"]), $weatherAgg);
$weatherCounts = array_map(fn($x) => (int)$x["cnt"], $weatherAgg);
$trafficLabels = array_map(fn($x) => nice_case($x["label"]), $trafficAgg);
$trafficCounts = array_map(fn($x) => (int)$x["cnt"], $trafficAgg);
$roadLabels    = array_map(fn($x) => nice_case($x["label"]), $roadAgg);
$roadCounts    = array_map(fn($x) => (int)$x["cnt"], $roadAgg);
$evoLabels     = array_map(fn($x) => (string)$x["label"], $evoAgg);
$evoKm         = array_map(fn($x) => (float)$x["km"], $evoAgg);
n 
$isAjax = ((string)($_GET["ajax"] ?? "0")) === "1";
if ($isAjax) {
    header("Content-Type: application/json; charset=utf-8");

    echo json_encode([
        "totalKm" => number_format($totalKm, 1),
        "totalRows" => $totalRows,
        "page" => $page,
        "totalPages" => $totalPages,
        "isAll" => $isAll,
        "perPage" => $isAll ? "all" : $perPage,
        "tbodyHtml" => render_rows_html($rows),
        "pagerHtml" => render_pager_html($isAll, $perPage, $page, $totalPages, $totalRows),
        "charts" => [
            "weatherLabels" => $weatherLabels,
            "weatherCounts" => $weatherCounts,
            "trafficLabels" => $trafficLabels,
            "trafficCounts" => $trafficCounts,
            "roadLabels" => $roadLabels,
            "roadCounts" => $roadCounts,
            "evoLabels" => $evoLabels,
            "evoKm" => $evoKm,
        ],
    ], JSON_UNESCAPED_UNICODE);

    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Driving Experience Summary</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <style>
  :root{
    --accent1: #ff8a00;
    --accent2: #ffb347;
    --accentGlow: rgba(255,138,0,.45);
    --accentGlowSoft: rgba(255,138,0,.22);
    --cardBorder: rgba(255,255,255,.14);
  }

  .btn{
    transition: transform .14s ease, box-shadow .14s ease, filter .14s ease, background .14s ease, color .14s ease;
    transform: translateZ(0);
    will-change: transform;
  }
  .btn:hover{
    transform: translateY(-1px);
    box-shadow: 0 10px 24px rgba(0,0,0,.28), 0 0 0 2px var(--accentGlowSoft), 0 0 20px var(--accentGlow);
  }
  .btn:active{ transform: translateY(0px); }

  .btn:not(.secondary):hover{
    background: linear-gradient(135deg, var(--accent1), var(--accent2));
    color: #1f2c38;
  }
  .btn.secondary:hover{
    background: rgba(255,255,255,.14);
    box-shadow: 0 10px 24px rgba(0,0,0,.22), 0 0 0 2px rgba(255,138,0,.25), 0 0 18px rgba(255,138,0,.25);
  }

  .filters select:focus,
  .filters input:focus{
    outline: none;
    border-color: rgba(255,138,0,.45);
    box-shadow: 0 0 0 2px rgba(255,138,0,.25), 0 0 18px rgba(255,138,0,.18);
  }

  body{
    font-family: Arial, sans-serif;
    background:#2c3e50;
    background-image:url("https://www.transparenttextures.com/patterns/clean-gray-paper.png");
    color:#ecf0f1;
    margin:0;
    min-height:100vh;
    display:flex;
    flex-direction:column;
  }
  header,footer{
    background:#34495e;
    padding:16px;
    text-align:center;
    box-shadow:0 4px 15px rgba(0,0,0,.15);
  }
  main{
    flex:1;
    width: min(95vw, 1600px);
    margin: 0 auto;
    padding: 16px;
    box-sizing:border-box;
  }

  .card{
    background: rgba(52,73,94,.55);
    border: 1px solid rgba(255,255,255,.10);
    border-radius: 16px;
    padding: 18px;
    box-shadow: none;
  }

  h2{margin:0 0 10px 0;color:#f39c12;}
  .muted{opacity:.85;}

  .topRow{
    display:grid;
    grid-template-columns:1fr 260px;
    gap:16px;
    align-items:stretch;
    margin-bottom:12px;
  }
  .totalBox{
    background:rgba(255,255,255,.08);
    border:1px solid rgba(255,255,255,.12);
    border-radius:14px;
    padding:16px;
    display:flex;
    flex-direction:column;
    justify-content:center;
  }
  .totalBox .value{
    font-size:1.8rem;
    color:#f39c12;
    font-weight:800;
    margin-top:8px;
    line-height:1.1;
  }

  .filters{
    display:grid;
    grid-template-columns:repeat(6,minmax(0,1fr));
    gap:10px;
    margin:14px 0 8px;
  }
  .filters label{display:block;margin-bottom:6px;font-size:.9rem;opacity:.9;}
  .filters select,.filters input{
    width:100%;
    padding:10px;
    border-radius:10px;
    border:1px solid rgba(255,255,255,.15);
    background:rgba(255,255,255,.08);
    color:#fff;
    box-sizing:border-box;
  }
  .filters option{color:#000;}
  .filterActions{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    margin:10px 0 16px;
  }

  .btn{
    background:#f39c12;
    color:#2c3e50;
    padding:10px 14px;
    border-radius:10px;
    text-decoration:none;
    font-weight:800;
    border:none;
    cursor:pointer;
    display:inline-block;
  }
  .btn.secondary{
    background:rgba(255,255,255,.12);
    color:#fff;
    font-weight:700;
  }

  .btn.small{
    padding:7px 10px;
    border-radius:9px;
    font-weight:800;
  }
  .actions .btn.small{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-width:78px;
    padding:4px 0;
    line-height:1.1;
    font-size:12px;
  }

  .btn.success{ background:#2ecc71; color:#fff; }
  .btn.danger{ background:#e74c3c; color:#fff; }

  .charts{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:16px;
    margin:10px 0 12px;
  }
  .chartCard{
    background:rgba(255,255,255,.06);
    border:1px solid rgba(255,255,255,.12);
    border-radius:14px;
    padding:14px;
  }
  .chartTitle{
    margin:0 0 10px 0;
    font-weight:700;
    color:#f39c12;
  }
  .chartWrap{
    position:relative;
    width:100%;
    height:220px;
  }
  canvas{
    width:100% !important;
    height:100% !important;
  }

  table{
    width:100%;
    margin-top:16px;
    border-collapse:collapse;
    background:rgba(255,255,255,.95);
    color:#2c3e50;
    border-radius:12px;
    overflow:hidden;
    table-layout:fixed;
  }

  th,td{
    padding:4px 6px;
    line-height:1.25;
    font-size:13px;
    border:1px solid #ddd;
    text-align:center;
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap;
  }
  th{background:#2c3e50;color:#f39c12;}

  tbody tr:nth-child(odd){ background:#f2f4f6; }
  tbody tr:nth-child(even){ background:#e4e8ec; }

  td.col-road{
    white-space:normal;
    word-break:break-word;
  }

  .actions{
    display:flex;
    justify-content:center;
    gap:8px;
    flex-wrap:wrap;
  }

  .pager{
    margin-top:12px;
    display:flex;
    gap:10px;
    align-items:center;
    flex-wrap:wrap;
  }

  @media (max-width: 980px){
    .topRow{grid-template-columns:1fr;}
    .filters{grid-template-columns:repeat(2,minmax(0,1fr));}
    .charts{grid-template-columns:1fr;}
    .chartWrap{height:200px;}
  }

  @media (max-width: 820px){
    table colgroup{display:none;}
    table{
      table-layout:auto;
    }
    thead{display:none;}
    table, tbody, tr, td{
      display:block;
      width:100%;
    }
    tbody tr{
      background:rgba(255,255,255,.95);
      margin-top:12px;
      border-radius:12px;
      overflow:hidden;
      border:1px solid rgba(0,0,0,.10);
    }
    tbody tr:nth-child(odd),
    tbody tr:nth-child(even){
      background:rgba(255,255,255,.95);
    }
    td{
      border:none;
      border-bottom:1px solid rgba(0,0,0,.08);
      text-align:left;
      padding:10px 12px;
      font-size:14px;
      white-space:normal;
      overflow:visible;
      text-overflow:clip;
      line-height:1.35;
    }
    td:last-child{ border-bottom:none; }
    td::before{
      content: attr(data-label);
      display:block;
      font-weight:800;
      color:#2c3e50;
      opacity:.85;
      margin-bottom:4px;
      font-size:.85rem;
    }
    td.col-road{
      word-break:break-word;
    }
    .actions{
      justify-content:flex-start;
      gap:10px;
    }
    .actions .btn.small{
      min-width:92px;
      padding:10px 12px;
      font-size:14px;
      line-height:1;
    }
  }

  @media (max-width: 420px){
    tbody tr{ margin-top:10px; }
    td{ padding:10px; }
    .actions .btn.small{ width:calc(50% - 6px); min-width:0; justify-content:center; }
  }
  </style>
</head>
<body>
<header><h1 style="margin:0;">Driving Experience – Summary</h1></header>

<main>
  <div class="card">
    <div class="topRow">
      <div>
        <h2>Statistics & Summary</h2>
      </div>
      <div class="totalBox">
        <div class="muted">Total Distance (filtered)</div>
        <div class="value"><span id="totalKmValue"><?= number_format($totalKm, 1) ?></span> km</div>
      </div>
    </div>

    <form method="get" id="filtersForm">
      <div class="filters">
        <div>
          <label>Weather</label>
          <select name="weather">
            <option value="0">All</option>
            <?php foreach ($weatherOpts as $o): ?>
              <option value="<?= (int)$o["idWeatherCond"] ?>" <?= $f_weather==(int)$o["idWeatherCond"]?"selected":"" ?>>
                <?= htmlspecialchars(nice_case($o["weatherType"])) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label>Road condition</label>
          <select name="road">
            <option value="0">All</option>
            <?php foreach ($roadOpts as $o): ?>
              <option value="<?= (int)$o["idRoadCond"] ?>" <?= $f_road==(int)$o["idRoadCond"]?"selected":"" ?>>
                <?= htmlspecialchars(nice_case($o["roadCondition"])) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label>Traffic</label>
          <select name="traffic">
            <option value="0">All</option>
            <?php foreach ($trafficOpts as $o): ?>
              <option value="<?= (int)$o["idStateOfTraffic"] ?>" <?= $f_traffic==(int)$o["idStateOfTraffic"]?"selected":"" ?>>
                <?= htmlspecialchars(nice_case($o["traffic"])) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label>Speed limit</label>
          <select name="speed">
            <option value="0">All</option>
            <?php foreach ($speedOpts as $o): ?>
              <option value="<?= (int)$o["idSpeedLimit"] ?>" <?= $f_speed==(int)$o["idSpeedLimit"]?"selected":"" ?>>
                <?= (int)$o["speedLimit_km_h"] ?> km/h
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label>From</label>
          <input type="date" name="from" value="<?= htmlspecialchars($f_from) ?>">
        </div>

        <div>
          <label>To</label>
          <input type="date" name="to" value="<?= htmlspecialchars($f_to) ?>">
        </div>
      </div>

      <div class="filters" style="grid-template-columns:repeat(3,minmax(0,1fr));">
        <div>
          <label>Sort by</label>
          <select name="sort">
            <option value="date" <?= $sort==="date"?"selected":"" ?>>Date</option>
            <option value="km" <?= $sort==="km"?"selected":"" ?>>Distance (km)</option>
            <option value="speed" <?= $sort==="speed"?"selected":"" ?>>Speed limit</option>
            <option value="weather" <?= $sort==="weather"?"selected":"" ?>>Weather</option>
            <option value="traffic" <?= $sort==="traffic"?"selected":"" ?>>Traffic</option>
          </select>
        </div>

        <div>
          <label>Direction</label>
          <select name="dir">
            <option value="DESC" <?= strtoupper($dir)==="DESC"?"selected":"" ?>>DESC</option>
            <option value="ASC" <?= strtoupper($dir)==="ASC"?"selected":"" ?>>ASC</option>
          </select>
        </div>

        <div>
          <label>Rows per page</label>
          <select name="perPage">
            <option value="5"  <?= (!$isAll && $perPage===5)  ? "selected" : "" ?>>5</option>
            <option value="10" <?= (!$isAll && $perPage===10) ? "selected" : "" ?>>10</option>
            <option value="15" <?= (!$isAll && $perPage===15) ? "selected" : "" ?>>15</option>
            <option value="20" <?= (!$isAll && $perPage===20) ? "selected" : "" ?>>20</option>
            <option value="all" <?= $isAll ? "selected" : "" ?>>All</option>
          </select>
        </div>
      </div>

      <div class="filterActions">
        <button class="btn" type="submit">Apply</button>
        <a class="btn secondary" href="summary.php" id="resetBtn">Reset</a>
        <a class="btn secondary" href="index.php">← Back to form</a>
      </div>
    </form>

    <div class="charts">
      <div class="chartCard">
        <div class="chartTitle">Weather Distribution</div>
        <div class="chartWrap"><canvas id="weatherChart"></canvas></div>
      </div>
      <div class="chartCard">
        <div class="chartTitle">Traffic Distribution</div>
        <div class="chartWrap"><canvas id="trafficChart"></canvas></div>
      </div>
      <div class="chartCard">
        <div class="chartTitle">Road Conditions Distribution</div>
        <div class="chartWrap"><canvas id="roadChart"></canvas></div>
      </div>
      <div class="chartCard">
        <div class="chartTitle">Total KM Evolution (per date)</div>
        <div class="chartWrap"><canvas id="evoChart"></canvas></div>
      </div>
    </div>

    <table>
      <colgroup>
        <col style="width: 10%;">
        <col style="width: 8%;">
        <col style="width: 8%;">
        <col style="width: 10%;">
        <col style="width: 14%;">
        <col style="width: 14%;">
        <col style="width: 10%;">
        <col style="width: 16%;">
        <col style="width: 10%;">
      </colgroup>

      <thead>
        <tr>
          <th>Date</th><th>Start</th><th>End</th><th>Distance (km)</th>
          <th>Weather</th><th>Traffic</th><th>Speed limit</th><th>Road conditions</th><th>Actions</th>
        </tr>
      </thead>
      <tbody id="resultsTbody">
        <?= render_rows_html($rows) ?>
      </tbody>
    </table>

    <div class="pager" id="pager">
      <?= render_pager_html($isAll, $perPage, $page, $totalPages, $totalRows) ?>
    </div>
  </div>
</main>

<footer>
  <p style="margin:0;"><strong>MirSaid Hasanzada</strong> · Supervised Driving Experience</p>
</footer>

<script>
  console.log("Chart.js version:", (window.Chart && Chart.version) ? Chart.version : "Chart not loaded");

  const initialCharts = {
    weatherLabels: <?= json_encode($weatherLabels, JSON_UNESCAPED_UNICODE) ?>,
    weatherCounts: <?= json_encode($weatherCounts) ?>,
    trafficLabels: <?= json_encode($trafficLabels, JSON_UNESCAPED_UNICODE) ?>,
    trafficCounts: <?= json_encode($trafficCounts) ?>,
    roadLabels: <?= json_encode($roadLabels, JSON_UNESCAPED_UNICODE) ?>,
    roadCounts: <?= json_encode($roadCounts) ?>,
    evoLabels: <?= json_encode($evoLabels) ?>,
    evoKm: <?= json_encode($evoKm) ?>
  };

  if ("scrollRestoration" in history) history.scrollRestoration = "manual";

  Chart.defaults.color = "white";
  Chart.defaults.font.family = "Arial, system-ui, -apple-system, Segoe UI, Roboto, sans-serif";
  Chart.defaults.font.size = 12;
  Chart.defaults.font.weight = "600";

  const DPR = Math.max(2, Math.min(4, window.devicePixelRatio || 2));
  Chart.defaults.devicePixelRatio = DPR;

  function hexToRgba(hex, a){
    const h = hex.replace("#","").trim();
    const full = h.length === 3 ? h.split("").map(x=>x+x).join("") : h;
    const n = parseInt(full, 16);
    const r = (n >> 16) & 255;
    const g = (n >> 8) & 255;
    const b = n & 255;
    return `rgba(${r},${g},${b},${a})`;
  }

  function makeGradient(ctx, area, c1, c2, a1=0.85, a2=0.2){
    const g = ctx.createLinearGradient(0, area.top, 0, area.bottom);
    g.addColorStop(0, hexToRgba(c1, a1));
    g.addColorStop(1, hexToRgba(c2, a2));
    return g;
  }

  const softShadowPlugin = {
    id: "softShadowPlugin",
    beforeDatasetDraw(chart, args, pluginOptions){
      const { ctx } = chart;
      ctx.save();
      ctx.shadowColor = "rgba(0,0,0,.35)";
      ctx.shadowBlur = 14;
      ctx.shadowOffsetY = 6;
    },
    afterDatasetDraw(chart){
      chart.ctx.restore();
    }
  };

  const cleanLegendPlugin = {
    id: "cleanLegendPlugin",
    beforeInit(chart){
      chart.options.plugins.legend.labels.usePointStyle = true;
      chart.options.plugins.legend.labels.pointStyle = "circle";
      chart.options.plugins.legend.labels.boxWidth = 10;
    }
  };

  Chart.register(softShadowPlugin, cleanLegendPlugin);

  const baseOptions = {
    responsive: true,
    maintainAspectRatio: false,
    devicePixelRatio: DPR,
    animation: { duration: 1200, easing: "easeOutQuart" },
    plugins: {
      legend: {
        position: "top",
        labels: {
          color: "rgba(255,255,255,.92)",
          padding: 18,
          font: { size: 12, weight: "700" }
        }
      },
      tooltip: {
        backgroundColor: "rgba(15,20,30,.92)",
        borderColor: "rgba(255,138,0,.45)",
        borderWidth: 1,
        titleColor: "#fff",
        bodyColor: "rgba(255,255,255,.92)",
        padding: 12,
        cornerRadius: 12,
        displayColors: true
      }
    }
  };

  function zeros(len){ return Array.from({length: len}, () => 0); }

  const PALETTE = [
    "#ff8a00","#ffb347","#2ecc71","#3498db","#9b59b6",
    "#1abc9c","#e74c3c","#f1c40f","#e67e22","#95a5a6",
    "#16a085","#2980b9"
  ];

  function paletteFor(n){
    const out = [];
    for(let i=0;i<n;i++) out.push(PALETTE[i % PALETTE.length]);
    return out;
  }

  function createDonutChart(canvasId, labels, realData){
    const ctx = document.getElementById(canvasId).getContext("2d");
    const colors = paletteFor(realData.length);

    return new Chart(ctx, {
      type: "doughnut",
      data: {
        labels,
        datasets: [{
          label: "Count",
          data: realData,
          backgroundColor: colors,
          borderColor: "rgba(255,255,255,.10)",
          borderWidth: 2,
          hoverOffset: 10,
          spacing: 3,
          cutout: "68%"
        }]
      },
      options: {
        ...baseOptions,
        animation: { duration: 1200, easing: "easeOutQuart", animateRotate: true, animateScale: true }
      }
    });
  }

  function createProBarChart(canvasId, labels, realData){
    const ctx = document.getElementById(canvasId).getContext("2d");

    return new Chart(ctx, {
      type: "bar",
      data: {
        labels,
        datasets: [{
          label: "Count",
          data: realData,
          backgroundColor: "rgba(255,138,0,.75)",
          borderWidth: 0,
          borderRadius: 12,
          barThickness: 18,
          maxBarThickness: 22
        }]
      },
      options: {
        ...baseOptions,
        plugins: {
          ...baseOptions.plugins,
          legend: { display: false }
        },
        scales: {
          x: { ticks: { color: "rgba(255,255,255,.90)" }, grid: { display: false } },
          y: { beginAtZero: true, ticks: { color: "rgba(255,255,255,.85)" }, grid: { color: "rgba(255,255,255,.08)" } }
        }
      }
    });
  }

  function createProLineChart(canvasId, labels, realData){
    const ctx = document.getElementById(canvasId).getContext("2d");

    return new Chart(ctx, {
      type: "line",
      data: {
        labels,
        datasets: [{
          label: "KM",
          data: realData,
          tension: 0.35,
          borderWidth: 2,
          borderColor: "#ff8a00",
          backgroundColor: "rgba(255,138,0,.20)",
          fill: true,
          pointRadius: 3,
          pointHoverRadius: 5,
          pointBackgroundColor: "rgba(255,255,255,.95)",
          pointBorderColor: "rgba(255,138,0,.85)",
          pointBorderWidth: 2
        }]
      },
      options: {
        ...baseOptions,
        plugins: {
          ...baseOptions.plugins,
          legend: { display: false }
        },
        scales: {
          x: { ticks: { color: "rgba(255,255,255,.90)" }, grid: { color: "rgba(255,255,255,.06)" } },
          y: { beginAtZero: true, ticks: { color: "rgba(255,255,255,.85)" }, grid: { color: "rgba(255,255,255,.08)" } }
        }
      }
    });
  }

  const weatherChart = createDonutChart("weatherChart", initialCharts.weatherLabels, initialCharts.weatherCounts);
  const trafficChart = createDonutChart("trafficChart", initialCharts.trafficLabels, initialCharts.trafficCounts);
  const roadChart    = createProBarChart("roadChart", initialCharts.roadLabels, initialCharts.roadCounts);
  const evoChart     = createProLineChart("evoChart", initialCharts.evoLabels, initialCharts.evoKm);

  function updateChart(chart, labels, data){
    chart.data.labels = labels;
    chart.data.datasets[0].data = data;
    chart.options.animation = { duration: 800, easing: "easeOutQuart" };
    chart.update();
  }

  function toAjaxUrl(url){
    const u = new URL(url, window.location.href);
    u.searchParams.set("ajax", "1");
    return u.toString();
  }

  function setLoading(isLoading){
    document.body.style.cursor = isLoading ? "progress" : "default";
  }

  function applyMobileTableLabels(){
    const isMobile = window.matchMedia("(max-width: 820px)").matches;
    if(!isMobile) return;

    const labels = ["Date","Start","End","Distance (km)","Weather","Traffic","Speed limit","Road conditions","Actions"];
    document.querySelectorAll("#resultsTbody tr").forEach(tr => {
      const tds = tr.querySelectorAll("td");
      if(!tds || !tds.length) return;
      tds.forEach((td, i) => {
        if(!td.getAttribute("data-label")) td.setAttribute("data-label", labels[i] || "");
      });
    });
  }

  async function loadAndPatch(url, pushState = true){
    const keepY = window.scrollY;
    setLoading(true);
    try{
      const res = await fetch(toAjaxUrl(url), { headers: { "X-Requested-With": "fetch" } });
      if(!res.ok) throw new Error("HTTP " + res.status);
      const data = await res.json();

      document.getElementById("totalKmValue").textContent = data.totalKm;
      document.getElementById("resultsTbody").innerHTML = data.tbodyHtml;
      document.getElementById("pager").innerHTML = data.pagerHtml;

      applyMobileTableLabels();

      updateChart(weatherChart, data.charts.weatherLabels, data.charts.weatherCounts);
      updateChart(trafficChart, data.charts.trafficLabels, data.charts.trafficCounts);
      updateChart(roadChart, data.charts.roadLabels, data.charts.roadCounts);
      updateChart(evoChart, data.charts.evoLabels, data.charts.evoKm);

      if(pushState){
        const u = new URL(url, window.location.href);
        u.searchParams.delete("ajax");
        history.pushState({ url: u.toString() }, "", u.toString());
      }

      requestAnimationFrame(() => window.scrollTo({ top: keepY, left: 0, behavior: "auto" }));
    } catch(e){
      console.error(e);
      window.location.href = url;
    } finally {
      setLoading(false);
    }
  }

  applyMobileTableLabels();
  window.addEventListener("resize", applyMobileTableLabels);

  document.addEventListener("click", (e) => {
    const a = e.target.closest("#pager a");
    if(!a) return;
    e.preventDefault();
    loadAndPatch(a.href, true);
  });

  document.getElementById("filtersForm").addEventListener("submit", (e) => {
    e.preventDefault();
    const form = e.target;
    const qs = new URLSearchParams(new FormData(form));
    qs.set("page", "1");
    loadAndPatch("summary.php?" + qs.toString(), true);
  });

  document.getElementById("resetBtn").addEventListener("click", (e) => {
    e.preventDefault();
    loadAndPatch("summary.php", true);
  });

  window.addEventListener("popstate", (ev) => {
    const url = (ev.state && ev.state.url) ? ev.state.url : window.location.href;
    loadAndPatch(url, false);
  });
</script>
</body>
</html>
