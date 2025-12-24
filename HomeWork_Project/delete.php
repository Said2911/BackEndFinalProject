<?php
declare(strict_types=1);
require __DIR__ . "/config/db.php";

$id = isset($_GET["id"]) ? (int)$_GET["id"] : 0;
if ($id <= 0) { http_response_code(400); echo "Bad request"; exit; }

$pdo->beginTransaction();
try {
    $st = $pdo->prepare("DELETE FROM driving_experience_road WHERE idDrivingExp = :id");
    $st->execute([":id" => $id]);

    $st = $pdo->prepare("DELETE FROM driving_experiences WHERE idDrivingExp = :id");
    $st->execute([":id" => $id]);

    $pdo->commit();
    header("Location: summary.php");
    exit;
} catch (Throwable $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo "Server error";
    exit;
}
