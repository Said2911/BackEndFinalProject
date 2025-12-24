<?php
declare(strict_types=1);
require __DIR__ . "/config/db.php";

$weather = $pdo->query("SELECT idWeatherCond, weatherType FROM weather_conditions ORDER BY weatherType")->fetchAll();
$roads   = $pdo->query("SELECT idRoadCond, roadCondition FROM road_conditions ORDER BY roadCondition")->fetchAll();
$traffic = $pdo->query("SELECT idStateOfTraffic, traffic FROM state_of_traffic ORDER BY idStateOfTraffic")->fetchAll();
$speeds  = $pdo->query("SELECT idSpeedLimit, speedLimit_km_h FROM speed_limits ORDER BY speedLimit_km_h")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Driving Experience Assistant</title>
  <style>
    :root{
      --bg:#2c3e50;
      --panel:#34495e;
      --accent:#f39c12;
      --ink:#0b1220;
      --border:rgba(11,18,32,.28);
      --soft:rgba(52,73,94,.08);
    }
    *{box-sizing:border-box}
    body{
      font-family: Arial, sans-serif;
      background:var(--bg);
      background-image:url("https://www.transparenttextures.com/patterns/clean-gray-paper.png");
      margin:0; min-height:100vh; display:flex; flex-direction:column;
    }
    header, footer{
      background:var(--panel); color:#fff; text-align:center; padding:16px 0;
      box-shadow:0 4px 15px rgba(0,0,0,.15);
      position:relative;
    }
    header::before{
      content:""; position:absolute; top:50%; left:0; width:100%; height:10px;
      background:repeating-linear-gradient(to right,var(--accent) 0,var(--accent) 10px,transparent 30px,transparent 60px);
      transform:translateY(-50%); z-index:0; opacity:.9;
    }
    header h1{ position:relative; z-index:1; margin:0; }

    main{
      flex:1;
      display:flex;
      justify-content:center;
      align-items:center;
      padding:22px;
      overflow:hidden;
    }

    .card{
      width:900px;
      max-width:900px;

      max-height: calc(100dvh - 180px);
      overflow:auto;

      background:rgba(255,255,255,.96);
      border-radius:18px;
      box-shadow:0 10px 45px rgba(241,178,5,.32);
      padding:22px;
      padding-bottom:18px;
    }

    .formGrid{
      display:grid;
      grid-template-columns: repeat(12, minmax(0,1fr));
      gap:14px;
      align-items:start;
    }

    .field{
      background:var(--soft);
      border:1px solid rgba(0,0,0,.08);
      border-radius:16px;
      padding:14px;
    }

    .span-4{grid-column: span 4;}
    .span-12{grid-column: span 12;}

    label{
      display:block;
      margin:0 0 8px;
      color:#2c3e50;
      font-weight:800;
      font-size:.95rem;
    }

    input, select{
      width:100%;
      padding:12px;
      border:1px solid var(--border);
      border-radius:12px;
      font-size:1rem;
      outline:none;
      background:#fff;
      color:#0b1220;
    }

    input:focus, select:focus{
      border-color: rgba(243,156,18,.95);
      box-shadow: 0 0 0 3px rgba(243,156,18,.22);
    }

    .roads{
      width:100%;
      display:grid;
      grid-template-columns: repeat(3, minmax(0,1fr));
      gap:10px;
      max-height:160px;
      overflow:auto;
      padding-right:6px;
      margin-top:2px;
    }

    .roads::-webkit-scrollbar{ width:10px; }
    .roads::-webkit-scrollbar-thumb{
      background:rgba(52,73,94,.35);
      border-radius:10px;
      border:2px solid rgba(255,255,255,.7);
    }

    .roadItem{
      display:flex; align-items:center; gap:10px;
      padding:10px 12px;
      border:1px solid rgba(0,0,0,.12);
      border-radius:12px;
      background:rgba(255,255,255,.88);
      user-select:none;
    }
    .roadItem input{ width:auto; }

    .summaryBox{
      background: rgba(52,73,94,.08);
      border: 1px solid rgba(52,73,94,.18);
      border-radius: 14px;
      padding: 12px 14px;
      color:#2c3e50;
      font-weight:800;
      display:flex;
      gap:8px;
      flex-wrap:wrap;
      align-items:center;
      min-height:48px;
    }

    .actionZone{
      grid-column: span 12;
      display:flex;
      align-items:center;
      justify-content:center;
      min-height:86px;
    }

    .actions{
      display:flex;
      gap:12px;
      flex-wrap:wrap;
      margin:0;
      justify-content:center;
    }

    button, .btnlink{
      background:var(--panel);
      color:#fff;
      border:none;
      border-radius:12px;
      padding:12px 18px;
      font-size:1rem;
      cursor:pointer;
      text-decoration:none;
      display:inline-block;
      transition: transform .14s ease, box-shadow .14s ease, background .14s ease, color .14s ease;
      font-weight:900;
      transform: translateZ(0);
      backface-visibility: hidden;
      -webkit-font-smoothing: antialiased;
    }
    button:hover, .btnlink:hover{
      background:var(--accent);
      color:var(--ink);
      transform: translateY(-1px);
      box-shadow:0 10px 24px rgba(0,0,0,.18);
    }
    button:active, .btnlink:active{
      transform: translateY(0px);
    }

    .note{
      padding:12px 14px;
      border-radius:14px;
      background:rgba(52,73,94,.10);
      color:#2c3e50;
      border:1px solid rgba(52,73,94,.16);
      font-weight:900;
      display:flex;
      align-items:center;
      justify-content:center;
      text-align:center;
      min-height:52px;
    }

    @media (max-width: 940px){
      main{padding:16px;}
      .card{width:min(900px, calc(100vw - 32px));}
    }

    @media (max-width: 1100px){
      .formGrid{ grid-template-columns: repeat(6, minmax(0,1fr)); }
      .span-4{ grid-column: span 6; }
    }

    @media (max-width: 980px){
      .span-4{grid-column: span 12;}
      .roads{grid-template-columns: repeat(2, minmax(0,1fr)); max-height:220px;}
      .actionZone{min-height:74px;}
    }

    @media (max-width: 520px){
      .roads{grid-template-columns: 1fr;}
    }
  </style>
</head>
<body>
<header>
  <h1>Driving Experience Assistant</h1>
</header>

<main>
  <div class="card">
    <form id="drivingForm" class="formGrid" method="post" action="save_experience.php" autocomplete="on" novalidate>
      <div class="field span-4">
        <label for="date">Date</label>
        <input type="date" id="date" name="date" required />
      </div>

      <div class="field span-4">
        <label for="startTime">Start time</label>
        <input type="time" id="startTime" name="startTime" required />
      </div>

      <div class="field span-4">
        <label for="endTime">End time</label>
        <input type="time" id="endTime" name="endTime" required />
      </div>

      <div class="field span-4">
        <label for="weather">Weather</label>
        <select id="weather" name="idWeatherCond" required>
          <option value="">-- Choose --</option>
          <?php foreach ($weather as $w): ?>
            <option value="<?= (int)$w["idWeatherCond"] ?>"><?= htmlspecialchars($w["weatherType"]) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="field span-4">
        <label for="traffic">State of traffic</label>
        <select id="traffic" name="idStateOfTraffic" required>
          <option value="">-- Choose --</option>
          <?php foreach ($traffic as $t): ?>
            <option value="<?= (int)$t["idStateOfTraffic"] ?>"><?= htmlspecialchars($t["traffic"]) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="field span-4">
        <label for="speed">Speed limit</label>
        <select id="speed" name="idSpeedLimit" required>
          <option value="">-- Choose --</option>
          <?php foreach ($speeds as $s): ?>
            <option value="<?= (int)$s["idSpeedLimit"] ?>"><?= (int)$s["speedLimit_km_h"] ?> km/h</option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="field span-12">
        <label>Road conditions (choose one or more)</label>
        <div class="roads">
          <?php foreach ($roads as $r): ?>
            <label class="roadItem">
              <input type="checkbox" name="roadConds[]" value="<?= (int)$r["idRoadCond"] ?>">
              <span><?= htmlspecialchars($r["roadCondition"]) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="field span-12">
        <label for="distance">Distance (km)</label>
        <input type="number" step="0.1" min="0" inputmode="decimal" id="distance" name="distance_in_km" required />
      </div>

      <div class="span-12 summaryBox" id="summaryBox">
        <strong>Trip summary:</strong>
        <span id="summaryText"></span>
      </div>

      <div class="actionZone">
        <div class="actions">
          <button type="submit">Submit</button>
          <a class="btnlink" href="summary.php">Go to Summary</a>
        </div>
      </div>

      <div class="span-12 note" id="msg" style="display:none;"></div>
    </form>
  </div>
</main>

<footer>
  <p style="margin:0;"><strong>MirSaid Hasanzada. All rights reserved</strong></p>
  <p style="margin:6px 0 0;">Email: <i>m.hasanzada@ufaz.az</i></p>
</footer>

<script>
  const now = new Date();
  const pad = (n) => String(n).padStart(2, "0");
  const fresh = new Date();
  document.getElementById("date").value = now.toISOString().slice(0,10);
  document.getElementById("startTime").value = `${pad(now.getHours())}:${pad(now.getMinutes())}`;

  const form = document.getElementById("drivingForm");
  const msg  = document.getElementById("msg");
  const summaryText = document.getElementById("summaryText");

  const pickTextIfChosen = (selectId) => {
    const el = document.getElementById(selectId);
    const val = el.value;
    if (!val) return "";
    const txt = el.selectedOptions[0]?.text || "";
    if (!txt || txt.includes("Choose")) return "";
    return txt;
  };

  const updateSummary = () => {
    const d = document.getElementById("date").value;
    const s = document.getElementById("startTime").value;
    const e = document.getElementById("endTime").value;
    const dist = document.getElementById("distance").value;

    const weather = pickTextIfChosen("weather");
    const traffic = pickTextIfChosen("traffic");
    const speed   = pickTextIfChosen("speed");

    const checkedRoads = [...document.querySelectorAll('input[name="roadConds[]"]:checked')]
      .map(x => x.parentElement?.innerText?.trim())
      .filter(Boolean);

    const parts = [];

    if (d) parts.push(`Date: ${d}`);
    if (s && e) parts.push(`Time: ${s}–${e}`);
    if (dist) parts.push(`Distance: ${dist} km`);
    if (weather) parts.push(`Weather: ${weather}`);
    if (traffic) parts.push(`Traffic: ${traffic}`);
    if (speed) parts.push(`Speed: ${speed}`);
    if (checkedRoads.length) parts.push(`Roads: ${checkedRoads.join(", ")}`);

    summaryText.textContent = parts.join(" · ");
  };

  form.addEventListener("input", updateSummary);
  updateSummary();

  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    msg.style.display = "none";
    msg.textContent = "";

    const fd = new FormData(form);
    const roads = fd.getAll("roadConds[]");
    if (!roads.length) {
      msg.style.display = "flex";
      msg.textContent = "Please select at least one road condition.";
      return;
    }

    const res = await fetch("save_experience.php", { method: "POST", body: fd });
    const data = await res.json().catch(() => null);

    msg.style.display = "flex";
    if (data && data.status === "success") {
      msg.textContent = "Saved ✅";
      form.reset();
      document.getElementById("date").value = now.toISOString().slice(0,10);
      document.getElementById("startTime").value = `${pad(now.getHours())}:${pad(now.getMinutes())}`;
      updateSummary();
    } else {
      msg.textContent = (data && data.message) ? data.message : "Error saving data.";
    }
  });
</script>
</body>
</html>
