<?php
declare(strict_types=1);

require __DIR__ . "/db.php";
header("Content-Type: application/json; charset=utf-8");

/* ✅ Tajni admin ključ */
$ADMIN_KEY = "arsotester";

/* ✅ Uzimamo parametre iz URL-a */
$key   = trim((string)($_GET["key"] ?? ""));
$email = trim((string)($_GET["email"] ?? ""));
$amount = (int)($_GET["amount"] ?? 0);

/* ✅ Zaštita */
if ($key !== $ADMIN_KEY) {
  http_response_code(401);
  echo json_encode(["ok"=>false,"error"=>"Unauthorized"]);
  exit;
}

if ($email === "" || $amount === 0) {
  http_response_code(400);
  echo json_encode(["ok"=>false,"error"=>"Missing email or amount"]);
  exit;
}

try {
  $pdo = db();
  $pdo->beginTransaction();

  $st = $pdo->prepare("SELECT id FROM resellers WHERE email=? LIMIT 1");
  $st->execute([$email]);
  $rid = (int)$st->fetchColumn();

  if (!$rid) throw new Exception("Reseller not found");

  $pdo->prepare("UPDATE resellers SET balance_rsd = balance_rsd + ? WHERE id=?")
      ->execute([$amount, $rid]);

  $pdo->prepare("INSERT INTO wallet_transactions (reseller_id,type,amount_rsd,description)
                 VALUES (?, 'TOPUP', ?, ?)")
      ->execute([$rid, $amount, "Browser topup"]);

  $newBal = (int)$pdo->query("SELECT balance_rsd FROM resellers WHERE id=$rid")->fetchColumn();

  $pdo->commit();
  echo json_encode(["ok"=>true,"new_balance"=>$newBal]);

} catch(Throwable $e){
  if ($pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  echo json_encode(["ok"=>false,"error"=>$e->getMessage()]);
}