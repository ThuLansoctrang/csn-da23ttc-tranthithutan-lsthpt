<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

/* =========================
   ADMIN.PHP — SaaS CRUD (1 FILE)
   No Bootstrap • Match index.css theme
   ========================= */

/* ========= 0) OPTIONAL AUTH =========
   - Nếu project anh có login rồi: set true để chặn người không phải admin.
   - Điều kiện: $_SESSION['role'] === 'admin'
*/
$REQUIRE_ADMIN = false;

/* ========= 1) DB CONFIG ========= */
$dbHost = '127.0.0.1';
$dbName = 'db_history';
$dbUser = 'root';
$dbPass = '';
$dbCharset = 'utf8mb4';

/* ========= 2) SESSION + CSRF ========= */
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)$_SESSION['csrf_token'];

function isAdminUser(): bool {
  return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function normalizeName(string $s): string {
  $s = trim($s);
  if ($s === '') return '';
  if (!preg_match('/^[a-zA-Z0-9_]+$/', $s)) return '';
  return $s;
}

function mysqli_fetch_all_assoc(mysqli_result $res): array {
  $rows = [];
  while ($row = $res->fetch_assoc()) $rows[] = $row;
  return $rows;
}

function stmt_bind_execute(mysqli_stmt $stmt, array $params): void {
  if (!$params) { $stmt->execute(); return; }
  $types = '';
  $bind = [];
  foreach ($params as $p) { $types .= 's'; $bind[] = (string)$p; }
  $stmt->bind_param($types, ...$bind);
  $stmt->execute();
}

function getTables(mysqli $mysqli, string $dbName): array {
  $stmt = $mysqli->prepare("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=? ORDER BY TABLE_NAME");
  stmt_bind_execute($stmt, [$dbName]);
  $res = $stmt->get_result();
  $out = [];
  while ($r = $res->fetch_assoc()) $out[] = $r['TABLE_NAME'];
  return $out;
}

function getColumns(mysqli $mysqli, string $dbName, string $table): array {
  $stmt = $mysqli->prepare("
    SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY, EXTRA
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=? AND TABLE_NAME=?
    ORDER BY ORDINAL_POSITION
  ");
  stmt_bind_execute($stmt, [$dbName, $table]);
  return mysqli_fetch_all_assoc($stmt->get_result());
}

function getPrimaryKey(array $cols): ?string {
  foreach ($cols as $c) {
    if (($c['COLUMN_KEY'] ?? '') === 'PRI') return $c['COLUMN_NAME'];
  }
  return null;
}

function isTextType(string $dt): bool {
  $dt = strtolower($dt);
  return in_array($dt, ['char','varchar','text','tinytext','mediumtext','longtext'], true);
}

function isNumericType(string $dt): bool {
  $dt = strtolower($dt);
  return in_array($dt, ['int','tinyint','smallint','mediumint','bigint','decimal','float','double'], true);
}

function buildQuery(array $add): string {
  $q = $_GET;
  foreach ($add as $k=>$v) {
    if ($v === null) unset($q[$k]);
    else $q[$k] = $v;
  }
  return '?' . http_build_query($q);
}

/* ========= 3) AUTH CHECK (optional) ========= */
if ($REQUIRE_ADMIN && !isAdminUser()) {
  http_response_code(403);
  echo "<h2 style='font-family:system-ui;margin:20px'>403 — Bạn không có quyền truy cập Admin 😤</h2>";
  exit;
}

/* ========= 4) CONNECT DB ========= */
$dbOk = false;
$dbErr = '';
$mysqli = null;

try {
  $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
  if ($mysqli->connect_error) throw new Exception($mysqli->connect_error);
  $mysqli->set_charset($dbCharset);
  $dbOk = true;
} catch (Throwable $e) {
  $dbErr = $e->getMessage();
  $dbOk = false;
}

/* ========= 5) PARAMS ========= */
$table  = normalizeName((string)($_GET['table'] ?? ''));
$action = (string)($_GET['action'] ?? ''); // add|edit|delete
$id     = (string)($_GET['id'] ?? '');
$q      = (string)($_GET['q'] ?? '');

$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = max(5, min(100, (int)($_GET['limit'] ?? 10)));
$offset = ($page - 1) * $limit;

$sort   = normalizeName((string)($_GET['sort'] ?? ''));
$dir    = strtolower((string)($_GET['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

$filterCol = normalizeName((string)($_GET['fcol'] ?? ''));
$filterVal = (string)($_GET['fval'] ?? '');

/* ========= 6) LOAD META ========= */
$tables = [];
$cols   = [];
$pk     = null;

if ($dbOk) {
  $tables = getTables($mysqli, $dbName);

  // pin important tables to top
  $pins = ['topics','lessons','questions','question_types','quiz_results','users','books','subitems','items'];
  $pinned = [];
  $others = [];
  foreach ($tables as $t) {
    if (in_array($t, $pins, true)) $pinned[] = $t;
    else $others[] = $t;
  }
  // keep pin order as pins list
  usort($pinned, fn($a,$b)=> array_search($a,$pins,true) <=> array_search($b,$pins,true));
  sort($others);
  $tables = array_values(array_unique(array_merge($pinned, $others)));

  if ($table === '' && $tables) $table = $tables[0];
  if ($table !== '') {
    $cols = getColumns($mysqli, $dbName, $table);
    $pk = getPrimaryKey($cols);
  }
}

/* ========= 7) VALIDATE SORT ========= */
$colNames = array_map(fn($c)=> $c['COLUMN_NAME'], $cols);
if ($sort === '' || !in_array($sort, $colNames, true)) $sort = $pk ?: ($colNames[0] ?? '');

/* ========= 8) BUILD WHERE (search + filter) ========= */
$params = [];
$whereParts = [];

if (trim($q) !== '') {
  $likes = [];
  foreach ($cols as $c) {
    $dt = (string)($c['DATA_TYPE'] ?? '');
    if (isTextType($dt)) {
      $likes[] = "`{$c['COLUMN_NAME']}` LIKE ?";
      $params[] = '%' . trim($q) . '%';
    }
  }
  if ($likes) $whereParts[] = '(' . implode(' OR ', $likes) . ')';
}

$filterValTrim = trim($filterVal);
if ($filterCol !== '' && $filterValTrim !== '' && in_array($filterCol, $colNames, true)) {
  $dt = '';
  foreach ($cols as $c) if ($c['COLUMN_NAME'] === $filterCol) $dt = (string)$c['DATA_TYPE'];

  if (isTextType($dt)) {
    $whereParts[] = "`$filterCol` LIKE ?";
    $params[] = '%' . $filterValTrim . '%';
  } else {
    $whereParts[] = "`$filterCol` = ?";
    $params[] = $filterValTrim;
  }
}

$whereSql = $whereParts ? ('WHERE ' . implode(' AND ', $whereParts)) : '';

/* ========= 9) FLASH ========= */
$flash = null;
$flashType = 'success';
$editRow = null;

function verifyCsrfOrDie(string $token): void {
  $posted = (string)($_POST['csrf'] ?? '');
  if (!hash_equals($token, $posted)) {
    http_response_code(400);
    echo "<h3 style='font-family:system-ui;margin:18px'>CSRF token không hợp lệ 😤</h3>";
    exit;
  }
}

/* ========= 10) CRUD ========= */
if ($dbOk && $table !== '' && $pk) {

  // DELETE
  if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrDie($csrfToken);
    $delId = (string)($_POST['id'] ?? '');
    try {
      $stmt = $mysqli->prepare("DELETE FROM `$table` WHERE `$pk`=? LIMIT 1");
      stmt_bind_execute($stmt, [$delId]);
      $flash = "Đã xóa bản ghi ID = {$delId}";
      $flashType = 'success';
      $action = '';
      $id = '';
    } catch (Throwable $e) {
      $flash = "Lỗi xóa: " . $e->getMessage();
      $flashType = 'danger';
    }
  }

  // ADD
  if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrDie($csrfToken);
    try {
      $fields = [];
      $placeholders = [];
      $p = [];

      foreach ($cols as $c) {
        $col = $c['COLUMN_NAME'];
        $extra = (string)($c['EXTRA'] ?? '');
        if ($col === $pk && str_contains($extra, 'auto_increment')) continue;

        $val = $_POST[$col] ?? null;
        if ($val === '' && (($c['IS_NULLABLE'] ?? '') === 'YES')) $val = null;

        $fields[] = "`$col`";
        $placeholders[] = "?";
        $p[] = $val;
      }

      $sql = "INSERT INTO `$table` (" . implode(',', $fields) . ") VALUES (" . implode(',', $placeholders) . ")";
      $stmt = $mysqli->prepare($sql);
      stmt_bind_execute($stmt, $p);

      $flash = "Đã thêm bản ghi mới vào `$table`";
      $flashType = 'success';
      $action = '';
    } catch (Throwable $e) {
      $flash = "Lỗi thêm: " . $e->getMessage();
      $flashType = 'danger';
    }
  }

  // EDIT
  if ($action === 'edit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrDie($csrfToken);
    $editId = (string)($_POST['id'] ?? '');
    try {
      $sets = [];
      $p = [];

      foreach ($cols as $c) {
        $col = $c['COLUMN_NAME'];
        if ($col === $pk) continue;

        $val = $_POST[$col] ?? null;
        if ($val === '' && (($c['IS_NULLABLE'] ?? '') === 'YES')) $val = null;

        $sets[] = "`$col`=?";
        $p[] = $val;
      }
      $p[] = $editId;

      $sql = "UPDATE `$table` SET " . implode(',', $sets) . " WHERE `$pk`=? LIMIT 1";
      $stmt = $mysqli->prepare($sql);
      stmt_bind_execute($stmt, $p);

      $flash = "Đã cập nhật ID = {$editId}";
      $flashType = 'success';
      $action = '';
      $id = '';
    } catch (Throwable $e) {
      $flash = "Lỗi sửa: " . $e->getMessage();
      $flashType = 'danger';
    }
  }

  // Load row for edit (GET)
  if ($action === 'edit' && $_SERVER['REQUEST_METHOD'] === 'GET' && $id !== '') {
    try {
      $stmt = $mysqli->prepare("SELECT * FROM `$table` WHERE `$pk`=? LIMIT 1");
      stmt_bind_execute($stmt, [$id]);
      $editRow = $stmt->get_result()->fetch_assoc();
      if (!$editRow) {
        $flash = "Không tìm thấy ID = {$id}";
        $flashType = 'danger';
        $action = '';
        $id = '';
      }
    } catch (Throwable $e) {
      $flash = "Lỗi load dữ liệu: " . $e->getMessage();
      $flashType = 'danger';
      $action = '';
      $id = '';
    }
  }
}

/* ========= 11) LIST DATA + PAGINATION ========= */
$rows = [];
$total = 0;
$pages = 1;

if ($dbOk && $table !== '' && $pk) {
  // total
  $sqlCount = "SELECT COUNT(*) as cnt FROM `$table` $whereSql";
  $stmtC = $mysqli->prepare($sqlCount);
  stmt_bind_execute($stmtC, $params);
  $total = (int)($stmtC->get_result()->fetch_assoc()['cnt'] ?? 0);
  $pages = max(1, (int)ceil($total / $limit));

  // data
  $sqlData = "SELECT * FROM `$table` $whereSql ORDER BY `$sort` $dir LIMIT $limit OFFSET $offset";
  $stmtD = $mysqli->prepare($sqlData);
  stmt_bind_execute($stmtD, $params);
  $rows = mysqli_fetch_all_assoc($stmtD->get_result());
}

function sortLink(string $col, string $currentSort, string $currentDir): string {
  $nextDir = 'asc';
  if ($currentSort === $col && $currentDir === 'asc') $nextDir = 'desc';
  return buildQuery(['sort'=>$col,'dir'=>$nextDir,'page'=>1]);
}
?>
<!doctype html>
<html lang="vi">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Admin — SaaS CRUD</title>

  <!-- Fonts giống index -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@500;600;700;800;900&family=Merriweather:wght@700;900&display=swap" rel="stylesheet">

  <!-- Theme index -->
  <link rel="stylesheet" href="index.css">

  <style>
    :root{
      --primary:#1D3557;
      --bg:#F3F6FB;
      --card:#FFFFFF;
      --ink:#1E2430;
      --muted:#5C6678;
      --line:#DDE4F2;
      --radius:18px;
      --shadow-soft: 0 10px 30px rgba(10,22,40,.08);
      --shadow-strong: 0 18px 55px rgba(10,22,40,.12);
      --ring: rgba(29,53,87,.16);
      --glass: rgba(255,255,255,.78);
    }
    *{ box-sizing:border-box; }
    body{
      margin:0;
      background: var(--bg);
      color: var(--ink);
      font-family:"Inter",system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;
      line-height:1.65;
    }
    a{ color:inherit; text-decoration:none; }
    .shell{
      min-height:100vh;
      display:grid;
      grid-template-columns: 290px 1fr;
    }

    /* Sidebar */
    .side{
      position:sticky; top:0;
      height:100vh;
      padding:16px;
      border-right:1px solid var(--line);
      background: linear-gradient(180deg, rgba(255,255,255,.75), rgba(255,255,255,.55));
      backdrop-filter: blur(10px);
      -webkit-backdrop-filter: blur(10px);
    }
    .brand{
      display:flex; gap:12px; align-items:center;
      padding:12px;
      border-radius:18px;
      border:1px solid var(--ring);
      background: var(--glass);
      box-shadow: var(--shadow-soft);
    }
    .brand__icon{
      width:44px; height:44px;
      border-radius:14px;
      display:grid; place-items:center;
      color:#fff;
      background: linear-gradient(135deg, #1D3557 0%, #2E4057 55%, #162A45 100%);
      box-shadow: 0 16px 30px rgba(29,53,87,.22);
      font-weight:900;
      font-family:"Merriweather", serif;
    }
    .brand__title{
      font-family:"Merriweather", serif;
      font-weight:900;
      line-height:1.1;
    }
    .brand__sub{
      margin-top:2px;
      color:var(--muted);
      font-weight:800;
      font-size:12px;
    }

    .side__card{
      margin-top:12px;
      padding:12px;
      border-radius:18px;
      border:1px solid var(--line);
      background: rgba(255,255,255,.78);
    }
    .side__label{
      display:flex; justify-content:space-between; align-items:center;
      font-weight:900; font-size:12px;
      color: rgba(30,36,48,.86);
      margin-bottom:8px;
    }
    .kbd{
      font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono","Courier New", monospace;
      font-size:12px;
      background: rgba(233,239,251,.75);
      border: 1px solid rgba(29,53,87,.12);
      padding: 2px 8px;
      border-radius: 999px;
    }
    .input{
      width:100%;
      border-radius:999px;
      border:1px solid var(--line);
      background: rgba(255,255,255,.92);
      padding:10px 14px;
      font-weight:650;
      outline:none;
    }
    .input:focus{
      border-color: rgba(29,53,87,.35);
      box-shadow: 0 0 0 4px rgba(29,53,87,.10);
    }

    .table-list{
      margin-top:10px;
      display:grid;
      gap:6px;
      max-height: calc(100vh - 320px);
      overflow:auto;
      padding-right:6px;
    }
    .pill{
      display:flex;
      justify-content:space-between;
      align-items:center;
      gap:10px;
      padding:10px 12px;
      border-radius:14px;
      border:1px solid transparent;
      color: rgba(30,36,48,.86);
      font-weight:850;
      font-size:13px;
      transition:.12s ease;
    }
    .pill:hover{
      background: rgba(233,239,251,.95);
      border-color: rgba(29,53,87,.12);
      transform: translateY(-1px);
    }
    .pill.is-active{
      background: rgba(29,53,87,.10);
      border-color: rgba(29,53,87,.18);
      color: var(--primary);
    }
    .pill__tag{
      font-size:12px;
      color: var(--muted);
      font-weight:800;
    }

    /* Main */
    .main{
      padding:18px 18px 40px;
    }
    .topbar{
      position:sticky; top:0;
      z-index:10;
      padding-bottom:12px;
      background: linear-gradient(180deg, rgba(243,246,251,1), rgba(243,246,251,0));
    }
    .topbar__inner{
      padding:12px;
      border-radius:18px;
      border:1px solid var(--ring);
      background: rgba(255,255,255,.80);
      box-shadow: var(--shadow-soft);
      display:flex;
      justify-content:space-between;
      align-items:center;
      gap:12px;
      flex-wrap:wrap;
    }
    .chips{
      display:flex;
      gap:10px;
      align-items:center;
      flex-wrap:wrap;
      font-size:13px;
      color: rgba(30,36,48,.86);
      font-weight:850;
    }
    .chip{
      display:inline-flex;
      gap:8px;
      align-items:center;
      padding:8px 12px;
      border-radius:999px;
      border:1px solid rgba(29,53,87,.14);
      background: rgba(255,255,255,.92);
      font-weight:900;
    }

    .btn{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      gap:10px;
      border-radius:999px;
      padding:10px 14px;
      font-weight:900;
      border:1px solid transparent;
      cursor:pointer;
      transition:.12s ease;
      white-space:nowrap;
    }
    .btn:hover{ transform: translateY(-1px); }
    .btn-primary{
      color:#fff;
      background: linear-gradient(180deg, #1D3557 0%, #162A45 100%);
      border-color: rgba(29,53,87,.25);
    }
    .btn-outline{
      background: rgba(255,255,255,.92);
      border-color: rgba(29,53,87,.16);
      color: rgba(30,36,48,.88);
    }
    .btn-danger{
      color:#fff;
      background: linear-gradient(180deg, #B23A48 0%, #8f2c37 100%);
      border-color: rgba(178,58,72,.25);
    }
    .btn-xs{ padding:8px 10px; font-size:12px; }

    .flash{
      margin-top:12px;
      padding:12px 14px;
      border-radius:18px;
      border:1px solid var(--line);
      background: rgba(255,255,255,.86);
      box-shadow: var(--shadow-soft);
      font-weight:800;
    }
    .flash.is-danger{ border-color: rgba(178,58,72,.25); background: rgba(178,58,72,.08); color:#B23A48; }
    .flash.is-success{ border-color: rgba(45,106,79,.22); background: rgba(45,106,79,.08); color:#2d6a4f; }

    .panel{
      margin-top:12px;
      border-radius:18px;
      border:1px solid var(--ring);
      background: var(--glass);
      box-shadow: var(--shadow-soft);
      overflow:hidden;
    }
    .panel__head{
      padding:14px 16px;
      border-bottom:1px solid var(--line);
      background: linear-gradient(180deg, rgba(243,246,251,.75), rgba(255,255,255,0));
      display:flex;
      justify-content:space-between;
      align-items:flex-end;
      gap:12px;
      flex-wrap:wrap;
    }
    .panel__title{
      margin:0;
      font-family:"Merriweather", serif;
      font-weight:900;
      color:#132844;
      font-size:18px;
    }
    .panel__sub{
      margin:4px 0 0;
      color:var(--muted);
      font-weight:650;
      font-size:13px;
    }
    .panel__body{ padding:14px 16px 16px; }

    .bar{
      display:flex;
      gap:10px;
      flex-wrap:wrap;
      align-items:end;
    }
    .field{
      display:grid;
      gap:6px;
      min-width: 210px;
    }
    .label{
      font-weight:900;
      font-size:12px;
      color: rgba(30,36,48,.86);
    }
    .select{
      width:100%;
      border-radius:999px;
      border:1px solid var(--line);
      background: rgba(255,255,255,.92);
      padding:10px 14px;
      font-weight:650;
      outline:none;
    }
    .select:focus{
      border-color: rgba(29,53,87,.35);
      box-shadow: 0 0 0 4px rgba(29,53,87,.10);
    }

    /* Table */
    .table-wrap{
      border-radius:18px;
      border:1px solid var(--line);
      background: rgba(255,255,255,.92);
      overflow:hidden;
    }
    table{ width:100%; border-collapse:collapse; font-size:13px; }
    thead th{
      text-align:left;
      padding:12px 12px;
      background: rgba(233,239,251,.75);
      border-bottom:1px solid var(--line);
      color: rgba(30,36,48,.86);
      font-weight:900;
      white-space:nowrap;
    }
    thead th a{
      display:inline-flex;
      gap:6px;
      align-items:center;
      color:inherit;
    }
    .sort-badge{
      font-size:11px;
      padding:2px 8px;
      border-radius:999px;
      border:1px solid rgba(29,53,87,.12);
      background: rgba(255,255,255,.75);
      color: var(--muted);
      font-weight:900;
    }
    tbody td{
      padding:12px 12px;
      border-bottom:1px solid var(--line);
      vertical-align:top;
      color: rgba(30,36,48,.90);
      font-weight:650;
    }
    tbody tr:hover{ background: rgba(233,239,251,.45); }
    .cell-muted{ color:var(--muted); font-weight:650; }
    .actions{ display:flex; gap:8px; flex-wrap:wrap; }

    /* clickable cell to view full */
    .cell{
      cursor:pointer;
      display:inline-block;
      max-width: 520px;
      word-break: break-word;
    }

    /* Pager */
    .pager{
      margin-top:12px;
      display:flex;
      justify-content:space-between;
      align-items:center;
      gap:10px;
      flex-wrap:wrap;
    }
    .pager__meta{ color:var(--muted); font-weight:650; font-size:13px; }
    .pager__links{ display:flex; gap:8px; flex-wrap:wrap; }

    /* Dialog */
    dialog{
      width:min(860px, calc(100% - 24px));
      border:1px solid rgba(29,53,87,.16);
      border-radius:18px;
      padding:0;
      background: rgba(255,255,255,.96);
      box-shadow: var(--shadow-strong);
    }
    dialog::backdrop{
      background: rgba(10,22,40,.35);
      backdrop-filter: blur(3px);
    }
    .dlg__head{
      padding:14px 16px;
      border-bottom:1px solid var(--line);
      background: linear-gradient(180deg, rgba(243,246,251,.75), rgba(255,255,255,0));
      display:flex;
      justify-content:space-between;
      align-items:center;
      gap:10px;
      flex-wrap:wrap;
    }
    .dlg__title{
      margin:0;
      font-family:"Merriweather", serif;
      font-weight:900;
      font-size:16px;
      color:#132844;
    }
    .dlg__body{ padding:14px 16px 16px; }
    .dlg__foot{
      padding:12px 16px 16px;
      border-top:1px solid var(--line);
      display:flex;
      justify-content:flex-end;
      gap:10px;
      flex-wrap:wrap;
    }

    .form-grid{
      display:grid;
      grid-template-columns: repeat(2, 1fr);
      gap: 12px;
    }
    .span-2{ grid-column: span 2; }
    .textarea{
      border-radius:18px;
      border:1px solid var(--line);
      background: rgba(255,255,255,.92);
      padding:10px 14px;
      font-weight:650;
      min-height:110px;
      outline:none;
      width:100%;
    }
    .textarea:focus{
      border-color: rgba(29,53,87,.35);
      box-shadow: 0 0 0 4px rgba(29,53,87,.10);
    }

    @media (max-width: 980px){
      .shell{ grid-template-columns: 1fr; }
      .side{ position:relative; height:auto; }
      .table-list{ max-height: 280px; }
      .form-grid{ grid-template-columns: 1fr; }
      .span-2{ grid-column:auto; }
      .cell{ max-width: 100%; }
    }
    .sort-badge{ display:none !important; }

  </style>
</head>

<body>
  <div class="shell">

    <!-- SIDEBAR -->
    <aside class="side">
      <div class="brand">
        <div class="brand__icon">A</div>
        <div>
          <div class="brand__title">Admin</div>          
        </div>
      </div>

      <div class="side__card">
        <div class="side__label">
          <span>Tables</span>
          <span class="kbd"><?= (int)count($tables) ?></span>
        </div>

        <input class="input" id="tableSearch" placeholder="Tìm bảng… (ví dụ: lessons)">

        <div class="table-list" id="tableList">
          <?php foreach ($tables as $t): ?>
            <a class="pill <?= $t === $table ? 'is-active' : '' ?>" href="?table=<?= h($t) ?>">
              <span><?= h($t) ?></span>
            </a>

          <?php endforeach; ?>
        </div>
      </div>
    </aside>

    <!-- MAIN -->
    <main class="main">
      <div class="topbar">
        <div class="topbar__inner">        

          <div class="chips">
            <a class="btn btn-outline" href="<?= h(buildQuery(['q'=>null,'fcol'=>null,'fval'=>null,'page'=>1])) ?>">Làm mới</a>
            <a class="btn btn-outline" href="index.php">Trang chủ</a>
            <a class="btn btn-primary" href="<?= h(buildQuery(['action'=>'add','id'=>null])) ?>" id="btnAdd">+ Thêm bản ghi</a>
          </div>
        </div>
      </div>

      <?php if ($flash): ?>
        <div class="flash <?= $flashType==='danger' ? 'is-danger' : 'is-success' ?>">
          <?= h($flash) ?>
        </div>
      <?php endif; ?>

      <section class="panel">
        <div class="panel__head">
          <div>
            <h2 class="panel__title">Quản lý dữ liệu</h2>            
          </div>         
        </div>

        <div class="panel__body">
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th style="width:160px;">Hành động</th>
                  <?php foreach ($cols as $c): ?>
                    <?php
                      $cn = $c['COLUMN_NAME'];
                      $isCur = ($sort === $cn);
                      $badge = $isCur ? strtoupper($dir) : 'SORT';
                    ?>
                    <th>
                      <a href="<?= h(sortLink($cn, $sort, $dir)) ?>">
                        <?= h($cn) ?>
                        <span class="sort-badge"><?= h($badge) ?></span>
                      </a>
                    </th>
                  <?php endforeach; ?>
                </tr>
              </thead>

              <tbody>
              <?php if (!$dbOk || $table==='' || !$pk): ?>
                <tr><td colspan="<?= 1 + count($cols) ?>" class="cell-muted">Không có dữ liệu (DB lỗi hoặc bảng không hợp lệ).</td></tr>
              <?php elseif (!$rows): ?>
                <tr><td colspan="<?= 1 + count($cols) ?>" class="cell-muted">Không có bản ghi phù hợp.</td></tr>
              <?php else: ?>
                <?php foreach ($rows as $r): ?>
                  <tr>
                    <td>
                      <div class="actions">
                        <a class="btn btn-outline btn-xs" href="<?= h(buildQuery(['action'=>'edit','id'=>(string)$r[$pk]])) ?>">Sửa</a>
                        <button class="btn btn-danger btn-xs" type="button" onclick="openDelete('<?= h((string)$r[$pk]) ?>')">Xóa</button>
                      </div>
                    </td>

                    <?php foreach ($cols as $c): ?>
                      <?php
                        $name = $c['COLUMN_NAME'];
                        $val = $r[$name] ?? '';
                        $full = (string)$val;
                        $short = $full;
                        if (mb_strlen($short) > 160) $short = mb_substr($short, 0, 160) . '…';
                      ?>
                      <td>
                        <span class="cell"
                              data-col="<?= h($name) ?>"
                              data-full="<?= h($full) ?>"
                              onclick="openCell(this)">
                          <?= h($short) ?>
                        </span>
                      </td>
                    <?php endforeach; ?>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
              </tbody>
            </table>
          </div>

          <?php
            $pages = max(1, (int)$pages);
            $page = min($page, $pages);
            $prev = max(1, $page - 1);
            $next = min($pages, $page + 1);
          ?>
          <div class="pager">
            <div class="pager__meta">
              Trang <b><?= (int)$page ?></b>/<b><?= (int)$pages ?></b> •
              Hiển thị <b><?= (int)min($limit, max(0, $total - $offset)) ?></b>/<b><?= (int)$total ?></b> bản ghi
            </div>

            <div class="pager__links">
              <a class="btn btn-outline btn-xs" href="<?= h(buildQuery(['page'=>1])) ?>">« Đầu</a>
              <a class="btn btn-outline btn-xs" href="<?= h(buildQuery(['page'=>$prev])) ?>">‹ Trước</a>
              <a class="btn btn-outline btn-xs" href="<?= h(buildQuery(['page'=>$next])) ?>">Sau ›</a>
              <a class="btn btn-outline btn-xs" href="<?= h(buildQuery(['page'=>$pages])) ?>">Cuối »</a>
            </div>
          </div>
        </div>
      </section>

      <!-- ADD/EDIT DIALOG -->
      <dialog id="editDialog">
        <form method="post" action="<?= h(buildQuery(['action'=>($action==='edit'?'edit':'add')])) ?>">
          <input type="hidden" name="csrf" value="<?= h($csrfToken) ?>">

          <div class="dlg__head">
            <h3 class="dlg__title"><?= ($action==='edit') ? 'Sửa bản ghi' : 'Thêm bản ghi mới' ?></h3>
            <button class="btn btn-outline btn-xs" type="button" onclick="closeEdit()">Đóng</button>
          </div>

          <div class="dlg__body">
            <?php if ($action==='edit' && $pk): ?>
              <input type="hidden" name="id" value="<?= h((string)($editRow[$pk] ?? $id)) ?>"/>
            <?php endif; ?>

            <div class="form-grid">
              <?php foreach ($cols as $c): ?>
                <?php
                  $col = $c['COLUMN_NAME'];
                  $extra = (string)($c['EXTRA'] ?? '');
                  $dt = strtolower((string)($c['DATA_TYPE'] ?? ''));
                  $isAuto = ($col === $pk && str_contains($extra, 'auto_increment'));
                  $isArea = in_array($dt, ['text','tinytext','mediumtext','longtext'], true);

                  $val = '';
                  if ($action === 'edit' && is_array($editRow)) $val = (string)($editRow[$col] ?? '');
                  else $val = (string)($_POST[$col] ?? '');
                ?>

                <?php if ($isAuto): ?>
                  <div class="field">
                    <div class="label"><?= h($col) ?> (auto)</div>
                    <input class="input" value="auto_increment" disabled>
                    <div class="cell-muted" style="font-size:12px;">PK auto — không sửa</div>
                  </div>
                <?php else: ?>
                  <div class="field <?= $isArea ? 'span-2' : '' ?>">
                    <div class="label"><?= h($col) ?></div>

                    <?php if ($isArea): ?>
                      <textarea class="textarea" name="<?= h($col) ?>" placeholder="<?= h($col) ?>"><?= h($val) ?></textarea>
                    <?php else: ?>
                      <input class="input" name="<?= h($col) ?>" value="<?= h($val) ?>" placeholder="<?= h($col) ?>">
                    <?php endif; ?>

                    <div class="cell-muted" style="font-size:12px;">
                      <?= h(strtoupper($dt)) ?> • NULL: <?= h((string)($c['IS_NULLABLE'] ?? '')) ?>
                    </div>
                  </div>
                <?php endif; ?>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="dlg__foot">
            <button class="btn btn-outline" type="button" onclick="closeEdit()">Hủy</button>
            <button class="btn btn-primary" type="submit">Lưu</button>
          </div>
        </form>
      </dialog>

      <!-- DELETE DIALOG -->
      <dialog id="deleteDialog">
        <form method="post" action="<?= h(buildQuery(['action'=>'delete'])) ?>">
          <input type="hidden" name="csrf" value="<?= h($csrfToken) ?>">
          <input type="hidden" name="id" id="delete_id">

          <div class="dlg__head">
            <h3 class="dlg__title" style="color:#B23A48;">Xóa bản ghi</h3>
            <button class="btn btn-outline btn-xs" type="button" onclick="closeDelete()">Đóng</button>
          </div>

          <div class="dlg__body">
            <p style="margin:0; font-weight:850;">
              Sắp xóa ID: <span class="kbd" id="delete_id_text">?</span>
            </p>
            <p class="cell-muted" style="margin:8px 0 0;">
              Bạn chắc chắn là muốn xóa chứ ?
            </p>
          </div>

          <div class="dlg__foot">
            <button class="btn btn-outline" type="button" onclick="closeDelete()">Hủy</button>
            <button class="btn btn-danger" type="submit">Xóa</button>
          </div>
        </form>
      </dialog>

      <!-- CELL VIEW DIALOG -->
      <dialog id="cellDialog">
        <div class="dlg__head">
          <h3 class="dlg__title">Xem nội dung</h3>
          <button class="btn btn-outline btn-xs" type="button" onclick="closeCell()">Đóng</button>
        </div>
        <div class="dlg__body">
          <div class="cell-muted" style="font-weight:900;margin-bottom:8px;">
            Cột: <span class="kbd" id="cellCol">—</span>
          </div>
          <pre id="cellFull" style="margin:0;white-space:pre-wrap;word-break:break-word;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:13px;background:rgba(233,239,251,.55);border:1px solid rgba(29,53,87,.12);padding:12px;border-radius:18px;"></pre>
        </div>
        <div class="dlg__foot">
          <button class="btn btn-outline" type="button" onclick="copyCell()">Copy</button>
          <button class="btn btn-primary" type="button" onclick="closeCell()">OK</button>
        </div>
      </dialog>

    </main>
  </div>

  <script>
    // Sidebar table search
    const tableSearch = document.getElementById('tableSearch');
    const tableList = document.getElementById('tableList');
    if (tableSearch && tableList) {
      tableSearch.addEventListener('input', () => {
        const kw = tableSearch.value.trim().toLowerCase();
        const items = tableList.querySelectorAll('.pill');
        items.forEach(a => {
          const name = a.textContent.trim().toLowerCase();
          a.style.display = name.includes(kw) ? '' : 'none';
        });
      });
    }

    // Dialogs
    const editDialog = document.getElementById('editDialog');
    const deleteDialog = document.getElementById('deleteDialog');
    const cellDialog = document.getElementById('cellDialog');

    function openEdit(){
      if (editDialog?.showModal) editDialog.showModal();
      else editDialog?.setAttribute('open','open');
    }
    function closeEdit(){
      if (editDialog?.close) editDialog.close();
      else editDialog?.removeAttribute('open');

      // clean URL action/id
      const url = new URL(window.location.href);
      url.searchParams.delete('action');
      url.searchParams.delete('id');
      history.replaceState({}, '', url.toString());
    }

    function openDelete(id){
      document.getElementById('delete_id').value = id;
      document.getElementById('delete_id_text').innerText = id;
      if (deleteDialog?.showModal) deleteDialog.showModal();
      else deleteDialog?.setAttribute('open','open');
    }
    function closeDelete(){
      if (deleteDialog?.close) deleteDialog.close();
      else deleteDialog?.removeAttribute('open');
    }

    // Cell view
    let lastCellText = '';
    function openCell(el){
      const col = el.getAttribute('data-col') || '—';
      const full = el.getAttribute('data-full') || '';
      lastCellText = full;

      document.getElementById('cellCol').innerText = col;
      document.getElementById('cellFull').textContent = full;

      if (cellDialog?.showModal) cellDialog.showModal();
      else cellDialog?.setAttribute('open','open');
    }
    function closeCell(){
      if (cellDialog?.close) cellDialog.close();
      else cellDialog?.removeAttribute('open');
    }
    async function copyCell(){
      try {
        await navigator.clipboard.writeText(lastCellText);
      } catch(e){
        // fallback
        const ta = document.createElement('textarea');
        ta.value = lastCellText;
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
      }
    }

    // Auto open dialog if action add/edit
    (function(){
      const action = <?= json_encode($action) ?>;
      if (action === 'add' || action === 'edit') openEdit();
    })();
  </script>
</body>
</html>
