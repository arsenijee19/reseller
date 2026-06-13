<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

start_secure_session();

$action = h_string($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'];

function require_post(): void {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
  }
}

function fetch_resellers(PDO $pdo): array {
  $stmt = $pdo->query('SELECT id, email, status, balance_rsd FROM resellers ORDER BY id DESC');
  return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function fetch_products(PDO $pdo): array {
  $stmt = $pdo->query('SELECT * FROM product_prices ORDER BY product_name ASC, account_type ASC, product_id ASC');
  return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function fetch_orders(PDO $pdo, array $filters): array {
  $where = [];
  $params = [];

  if (h_string($filters['reseller_id'] ?? '') !== '') {
    $where[] = 'o.reseller_id = ?';
    $params[] = (int)$filters['reseller_id'];
  }
  if (h_string($filters['status'] ?? '') !== '') {
    $where[] = 'o.status = ?';
    $params[] = h_string($filters['status']);
  }
  if (h_string($filters['product_id'] ?? '') !== '') {
    $where[] = 'o.product_id = ?';
    $params[] = h_string($filters['product_id']);
  }
  if (h_string($filters['date_from'] ?? '') !== '') {
    $where[] = 'DATE(o.created_at) >= ?';
    $params[] = h_string($filters['date_from']);
  }
  if (h_string($filters['date_to'] ?? '') !== '') {
    $where[] = 'DATE(o.created_at) <= ?';
    $params[] = h_string($filters['date_to']);
  }

  $sql = "
    SELECT o.*, pp.product_name, pp.account_type
    FROM orders o
    LEFT JOIN product_prices pp ON pp.product_id = o.product_id
  ";
  if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
  }
  $sql .= ' ORDER BY o.created_at DESC, o.id DESC LIMIT 250';

  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function dashboard_payload(PDO $pdo, array $filters = []): array {
  $orderStatuses = [];
  if (has_column($pdo, 'orders', 'status')) {
    $orderStatuses = $pdo->query("SELECT DISTINCT status FROM orders WHERE status IS NOT NULL AND status <> '' ORDER BY status")->fetchAll(PDO::FETCH_COLUMN);
  }

  return [
    'ok' => true,
    'csrf_token' => csrf_token(),
    'schema' => [
      'resellers' => table_columns($pdo, 'resellers'),
      'products' => table_columns($pdo, 'product_prices'),
      'orders' => table_columns($pdo, 'orders'),
    ],
    'resellers' => fetch_resellers($pdo),
    'products' => fetch_products($pdo),
    'orders' => fetch_orders($pdo, $filters),
    'order_statuses' => $orderStatuses,
  ];
}

try {
  $pdo = db();

  if ($action === 'session') {
    json_response([
      'ok' => true,
      'logged_in' => isset($_SESSION['admin_id']),
      'csrf_token' => csrf_token(),
    ]);
  }

  if ($action === 'login') {
    require_post();
    $input = read_json_body();
    $password = (string)($input['password'] ?? '');

    if ($password === '') {
      json_response(['ok' => false, 'error' => 'Unesi admin šifru.'], 400);
    }

    $stmt = $pdo->prepare("SELECT id, username, password_hash, status FROM admin_users WHERE username = 'admin' LIMIT 1");
    $stmt->execute();
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$admin || $admin['status'] !== 'active' || !password_verify($password, (string)$admin['password_hash'])) {
      json_response(['ok' => false, 'error' => 'Pogrešna admin šifra.'], 401);
    }

    session_regenerate_id(true);
    $_SESSION['admin_id'] = (int)$admin['id'];
    $_SESSION['admin_username'] = (string)$admin['username'];
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    json_response(['ok' => true, 'csrf_token' => csrf_token()]);
  }

  require_admin();

  if ($action === 'logout') {
    require_post();
    require_csrf();
    unset($_SESSION['admin_id'], $_SESSION['admin_username']);
    json_response(['ok' => true]);
  }

  if ($action === 'dashboard') {
    json_response(dashboard_payload($pdo, $_GET));
  }

  require_post();
  require_csrf();
  $input = read_json_body();

  if ($action === 'update_reseller') {
    $id = (int)($input['id'] ?? 0);
    $email = h_string($input['email'] ?? '');
    $status = h_string($input['status'] ?? 'active');
    $balance = (int)($input['balance_rsd'] ?? 0);
    $newToken = (string)($input['new_token'] ?? '');

    if ($id <= 0 || !valid_email($email)) {
      json_response(['ok' => false, 'error' => 'Neispravan reseller.'], 400);
    }

    $pdo->beginTransaction();
    $old = $pdo->prepare('SELECT balance_rsd FROM resellers WHERE id = ? LIMIT 1');
    $old->execute([$id]);
    $oldBalance = $old->fetchColumn();
    if ($oldBalance === false) {
      throw new RuntimeException('Reseller nije pronađen.');
    }

    $fields = ['email = ?', 'status = ?', 'balance_rsd = ?'];
    $params = [$email, $status, $balance];
    if ($newToken !== '') {
      if (strlen($newToken) < 8) {
        throw new RuntimeException('Nova šifra/token mora imati najmanje 8 karaktera.');
      }
      $fields[] = 'token_hash = ?';
      $params[] = password_hash($newToken, PASSWORD_DEFAULT);
    }
    if (has_column($pdo, 'resellers', 'updated_at')) {
      $fields[] = 'updated_at = NOW()';
    }
    $params[] = $id;

    $stmt = $pdo->prepare('UPDATE resellers SET ' . implode(', ', $fields) . ' WHERE id = ?');
    $stmt->execute($params);

    $diff = $balance - (int)$oldBalance;
    if ($diff !== 0) {
      $tx = $pdo->prepare("INSERT INTO wallet_transactions (reseller_id, type, amount_rsd, description) VALUES (?, 'ADMIN_ADJUSTMENT', ?, ?)");
      $tx->execute([$id, $diff, 'Admin balance adjustment']);
    }

    $pdo->commit();
    json_response(dashboard_payload($pdo));
  }

  if ($action === 'save_product') {
    $mode = h_string($input['mode'] ?? 'update');
    $originalId = h_string($input['original_product_id'] ?? '');
    $fields = is_array($input['fields'] ?? null) ? $input['fields'] : [];
    $columns = table_columns($pdo, 'product_prices');
    $allowed = array_column($columns, 'COLUMN_NAME');
    $auto = [];
    foreach ($columns as $column) {
      if (str_contains((string)$column['EXTRA'], 'auto_increment')) {
        $auto[] = $column['COLUMN_NAME'];
      }
    }

    $clean = [];
    foreach ($fields as $key => $value) {
      if (in_array($key, $allowed, true) && !in_array($key, $auto, true)) {
        $clean[$key] = is_string($value) ? trim($value) : $value;
      }
    }

    if (empty($clean['product_id'])) {
      json_response(['ok' => false, 'error' => 'Product ID je obavezan.'], 400);
    }

    if ($mode === 'create') {
      foreach ($columns as $column) {
        $name = (string)$column['COLUMN_NAME'];
        if (($clean[$name] ?? '') === '' && ($column['IS_NULLABLE'] === 'YES' || $column['COLUMN_DEFAULT'] !== null || $name === 'updated_at')) {
          unset($clean[$name]);
        }
      }
      $keys = array_keys($clean);
      $sql = 'INSERT INTO product_prices (`' . implode('`,`', $keys) . '`) VALUES (' . implode(',', array_fill(0, count($keys), '?')) . ')';
      $stmt = $pdo->prepare($sql);
      $stmt->execute(array_values($clean));
    } else {
      if ($originalId === '') {
        json_response(['ok' => false, 'error' => 'Nedostaje originalni Product ID.'], 400);
      }
      $sets = [];
      $params = [];
      foreach ($clean as $key => $value) {
        $sets[] = "`$key` = ?";
        $params[] = $value;
      }
      $params[] = $originalId;
      $stmt = $pdo->prepare('UPDATE product_prices SET ' . implode(', ', $sets) . ' WHERE product_id = ?');
      $stmt->execute($params);
    }

    json_response(dashboard_payload($pdo));
  }

  if ($action === 'deactivate_product') {
    $productId = h_string($input['product_id'] ?? '');
    if ($productId === '') json_response(['ok' => false, 'error' => 'Nedostaje proizvod.'], 400);

    if (has_column($pdo, 'product_prices', 'status')) {
      $stmt = $pdo->prepare("UPDATE product_prices SET status = 'inactive' WHERE product_id = ?");
      $stmt->execute([$productId]);
    } elseif (has_column($pdo, 'product_prices', 'is_active')) {
      $stmt = $pdo->prepare('UPDATE product_prices SET is_active = 0 WHERE product_id = ?');
      $stmt->execute([$productId]);
    } else {
      $stmt = $pdo->prepare('DELETE FROM product_prices WHERE product_id = ?');
      $stmt->execute([$productId]);
    }
    json_response(dashboard_payload($pdo));
  }

  if ($action === 'delete_product') {
    $productId = h_string($input['product_id'] ?? '');
    if ($productId === '') json_response(['ok' => false, 'error' => 'Nedostaje proizvod.'], 400);
    $stmt = $pdo->prepare('DELETE FROM product_prices WHERE product_id = ?');
    $stmt->execute([$productId]);
    json_response(dashboard_payload($pdo));
  }

  if ($action === 'update_order') {
    $id = (int)($input['id'] ?? 0);
    $fields = is_array($input['fields'] ?? null) ? $input['fields'] : [];
    if ($id <= 0) json_response(['ok' => false, 'error' => 'Nedostaje porudžbina.'], 400);

    $allowed = array_diff(column_names($pdo, 'orders'), ['id']);
    $clean = [];
    foreach ($fields as $key => $value) {
      if (in_array($key, $allowed, true)) {
        $clean[$key] = is_string($value) ? trim($value) : $value;
      }
    }
    if (!$clean) json_response(['ok' => false, 'error' => 'Nema polja za izmenu.'], 400);

    $sets = [];
    $params = [];
    foreach ($clean as $key => $value) {
      $sets[] = "`$key` = ?";
      $params[] = $value;
    }
    if (has_column($pdo, 'orders', 'updated_at')) {
      $sets[] = 'updated_at = NOW()';
    }
    $params[] = $id;
    $stmt = $pdo->prepare('UPDATE orders SET ' . implode(', ', $sets) . ' WHERE id = ?');
    $stmt->execute($params);

    json_response(dashboard_payload($pdo, $input['filters'] ?? []));
  }

  json_response(['ok' => false, 'error' => 'Unknown action'], 404);
} catch (Throwable $e) {
  if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
  json_response(['ok' => false, 'error' => $e->getMessage()], 500);
}
