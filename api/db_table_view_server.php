<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    api_error('Methode nicht erlaubt', 405);
}

function dbg_findConnectionById(string $id): ?array {
    $cfg = DatabaseConfig::load();
    foreach ($cfg['connections'] as $c) { if ((string)($c['id'] ?? '') === $id) return $c; }
    return null;
}

function dbg_validate_table(string $table): string {
    // Allow schema.table, underscores, digits; quote appropriately per engine later
    if (!preg_match('/^[A-Za-z0-9_\.]+$/', $table)) {
        throw new RuntimeException('Ungültiger Tabellenname');
    }
    return $table;
}

$connId = isset($_GET['conn_id']) ? (string)$_GET['conn_id'] : '';
$table  = isset($_GET['table']) ? (string)$_GET['table'] : '';
$limitParam = isset($_GET['limit']) ? strtolower((string)$_GET['limit']) : '100';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;

if ($connId === '' || $table === '') { http_response_code(400); echo 'Fehlende Parameter'; exit; }
$allowedLimits = ['100','250','500','all'];
if (!in_array($limitParam, $allowedLimits, true)) { $limitParam = '100'; }
$limit = $limitParam === 'all' ? null : (int)$limitParam;

try {
    // lightweight debug log
    $dbgLogFile = __DIR__ . '/../logs/debug.log';
    $dbgLog = function(string $msg) use ($dbgLogFile) { @file_put_contents($dbgLogFile, '['.date('Y-m-d H:i:s')."] db_table_view_server: $msg\n", FILE_APPEND); };
    $dbgLog("conn_id={$connId} table={$table} limit={$limitParam} page={$page}");
    $conn = dbg_findConnectionById($connId);
    if (!$conn) throw new RuntimeException('Verbindung nicht gefunden');
    $type = (string)($conn['type'] ?? '');
    $settings = is_array($conn['settings'] ?? null) ? $conn['settings'] : [];
    $table = dbg_validate_table($table);

    $rows = [];
    $columns = [];
    $totalRows = 0;
    $totalPages = 1;
    $currentDb = '';

    if ($type === 'mysql') {
        if (!function_exists('mysqli_connect')) throw new RuntimeException('mysqli nicht verfügbar');
        $host = (string)($settings['host'] ?? '');
        $port = (int)($settings['port'] ?? 3306);
        $dbn  = (string)($settings['database'] ?? '');
        $user = (string)($settings['username'] ?? '');
        $pass = (string)($settings['password'] ?? '');
        if ($host === '' || $dbn === '' || $user === '') throw new RuntimeException('Unvollständige MySQL-Konfiguration');
        $mysqli = @mysqli_connect($host, $user, $pass, $dbn, $port);
        if (!$mysqli) throw new RuntimeException('Connect-Fehler: ' . mysqli_connect_error());
        $qt = array_map(fn($p) => '`' . str_replace('`','``',$p) . '`', explode('.', $table));
        $tq = implode('.', $qt);
        $res = $mysqli->query("SELECT COUNT(*) FROM {$tq}");
        if ($res) { $totalRows = (int)($res->fetch_row()[0] ?? 0); $res->close(); }
        if ($limit !== null && $totalRows > 0) {
            $totalPages = (int)max(1, ceil($totalRows / $limit));
            if ($page > $totalPages) $page = $totalPages;
            $offset = ($page - 1) * $limit;
            $res = $mysqli->query("SELECT * FROM {$tq} LIMIT {$limit} OFFSET {$offset}");
            if (!$res) { $res = $mysqli->query("SELECT * FROM {$tq} LIMIT {$limit}"); }
        } else {
            $res = $mysqli->query("SELECT * FROM {$tq}");
        }
        // Current DB name
        $resDb = $mysqli->query("SELECT DATABASE()");
        if ($resDb) { $r = $resDb->fetch_row(); $currentDb = (string)($r[0] ?? ''); $resDb->close(); }
        if ($res) {
            while ($row = $res->fetch_assoc()) { $rows[] = $row; }
            $res->close();
        }
        $mysqli->close();
    } elseif ($type === 'mssql') {
        if (!function_exists('sqlsrv_connect')) throw new RuntimeException('sqlsrv nicht verfügbar');
        $host = (string)($settings['host'] ?? '');
        $port = (int)($settings['port'] ?? 1433);
        $dbn  = (string)($settings['database'] ?? '');
        $user = (string)($settings['username'] ?? '');
        $pass = (string)($settings['password'] ?? '');
        $encrypt = (bool)($settings['encrypt'] ?? true);
        $trust = (bool)($settings['trust_server_certificate'] ?? false);
        if ($host === '' || $dbn === '' || $user === '') throw new RuntimeException('Unvollständige MSSQL-Konfiguration');
        $server = $host . ',' . $port;
        $info = ['Database'=>$dbn,'UID'=>$user,'PWD'=>$pass,'Encrypt'=>$encrypt,'TrustServerCertificate'=>$trust,'LoginTimeout'=>10,'CharacterSet'=>'UTF-8','ReturnDatesAsStrings'=>true];
        $h = @sqlsrv_connect($server, $info);
        if ($h === false) { $err=sqlsrv_errors(); throw new RuntimeException('Connect-Fehler' . ($err && isset($err[0]['message'])?': '.$err[0]['message']:'')); }
        // Enforce configured database
        $dbQuoted = '[' . str_replace(']', ']]', $dbn) . ']';
        @sqlsrv_query($h, "USE {$dbQuoted}", [], ['QueryTimeout'=>30]);
        $parts = array_map(fn($p) => '[' . str_replace(']', ']]', $p) . ']', explode('.', $table));
        $tq = implode('.', $parts);
        // Current DB name
        $dbNameStmt = sqlsrv_query($h, "SELECT DB_NAME() AS db", [], ['QueryTimeout'=>30,'Scrollable'=>SQLSRV_CURSOR_CLIENT_BUFFERED]);
        if ($dbNameStmt && ($rdb = sqlsrv_fetch_array($dbNameStmt, SQLSRV_FETCH_ASSOC))) { $currentDb = (string)($rdb['db'] ?? ''); }
        if ($dbNameStmt) sqlsrv_free_stmt($dbNameStmt);
        $cnt = sqlsrv_query($h, "SELECT COUNT(*) AS c FROM {$tq}", [], ['QueryTimeout'=>30,'Scrollable'=>SQLSRV_CURSOR_CLIENT_BUFFERED]);
        if ($cnt && ($r = sqlsrv_fetch_array($cnt, SQLSRV_FETCH_ASSOC))) { $totalRows = (int)($r['c'] ?? 0); }
        if ($cnt) sqlsrv_free_stmt($cnt);
        // Determine stable order column (first column by ordinal)
        $schema = 'dbo'; $tblName = $table;
        if (strpos($table, '.') !== false) { [$schema,$tblName] = explode('.', $table, 2); $schema = trim($schema, '[]'); $tblName = trim($tblName, '[]'); }
        $colStmt = sqlsrv_query($h, "SELECT TOP 1 COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION", [$schema, $tblName], ['QueryTimeout'=>30,'Scrollable'=>SQLSRV_CURSOR_CLIENT_BUFFERED]);
        $orderCol = null;
        if ($colStmt && ($colRow = sqlsrv_fetch_array($colStmt, SQLSRV_FETCH_NUMERIC))) { $orderCol = (string)($colRow[0] ?? ''); }
        if ($colStmt) sqlsrv_free_stmt($colStmt);
        $orderExpr = $orderCol ? ('[' . str_replace(']', ']]', $orderCol) . ']') : '(SELECT NULL)';

        if ($limit !== null && $totalRows > 0) {
            $totalPages = (int)max(1, ceil($totalRows / $limit)); if ($page > $totalPages) $page = $totalPages;
            $offset = ($page - 1) * $limit;
            $stmt = sqlsrv_query($h, "SELECT * FROM {$tq} ORDER BY {$orderExpr} OFFSET {$offset} ROWS FETCH NEXT {$limit} ROWS ONLY", [], ['QueryTimeout'=>30,'Scrollable'=>SQLSRV_CURSOR_CLIENT_BUFFERED]);
            if ($stmt === false) {
                // Fallback for servers without OFFSET support
                $from = "SELECT ROW_NUMBER() OVER (ORDER BY {$orderExpr}) AS rn, * FROM {$tq}";
                $stmt = sqlsrv_query($h, "SELECT * FROM ({$from}) AS t WHERE rn BETWEEN ? AND ?", [($offset + 1), ($offset + $limit)], ['QueryTimeout'=>30,'Scrollable'=>SQLSRV_CURSOR_CLIENT_BUFFERED]);
            }
        } else {
            $stmt = sqlsrv_query($h, "SELECT * FROM {$tq} ORDER BY {$orderExpr}", [], ['QueryTimeout'=>120,'Scrollable'=>SQLSRV_CURSOR_FORWARD]);
        }
        if ($stmt) {
            $fetched = 0;
            while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) { $rows[] = $r; $fetched++; }
            sqlsrv_free_stmt($stmt);
            if ($limit === null) {
                if ($fetched === 0 && $totalRows > 0) {
                    $dbgLog('MSSQL all-mode empty, using batch fetch');
                    $batch = 1000; $rows = [];
                    for ($off = 0; $off < $totalRows; $off += $batch) {
                        $s2 = sqlsrv_query($h, "SELECT * FROM {$tq} ORDER BY {$orderExpr} OFFSET {$off} ROWS FETCH NEXT {$batch} ROWS ONLY", [], ['QueryTimeout'=>120,'Scrollable'=>SQLSRV_CURSOR_FORWARD]);
                        if ($s2) { while ($rr = sqlsrv_fetch_array($s2, SQLSRV_FETCH_ASSOC)) { $rows[] = $rr; } sqlsrv_free_stmt($s2); } else { $err = sqlsrv_errors(); $dbgLog('MSSQL batch failed at offset '.$off.': '.json_encode($err)); break; }
                    }
                }
            } else if ($fetched < min($limit, max(0,$totalRows))) {
                $dbgLog('MSSQL fetched '.$fetched.' of expected '.min($limit, max(0,$totalRows)).' — applying TOP fallback');
                // Try TOP fallback
                $stmt2 = sqlsrv_query($h, "SET ROWCOUNT {$limit}; SELECT * FROM {$tq} ORDER BY {$orderExpr}; SET ROWCOUNT 0;", [], ['QueryTimeout'=>30,'Scrollable'=>SQLSRV_CURSOR_CLIENT_BUFFERED]);
                if ($stmt2) {
                    $rows = [];
                    while ($rr = sqlsrv_fetch_array($stmt2, SQLSRV_FETCH_ASSOC)) { $rows[] = $rr; }
                    sqlsrv_free_stmt($stmt2);
                } else { $err = sqlsrv_errors(); $dbgLog('MSSQL TOP fallback failed: '.json_encode($err)); }
            } else {
                $dbgLog('MSSQL fetched rows='.$fetched.' totalRows='.$totalRows.' limit='.(string)$limit.' page='.(string)$page);
            }
        } else { $err = sqlsrv_errors(); $dbgLog('MSSQL SELECT failed: '.json_encode($err)); }
        sqlsrv_close($h);
    } elseif ($type === 'sqlite') {
        $path = (string)($settings['path'] ?? ''); if ($path === '') throw new RuntimeException('Pfad fehlt');
        $full = (new ReflectionClass('DatabaseConfig'))->getMethod('normalizePath')->invoke(null, $path);
        $pdo = new PDO('sqlite:' . $full); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $currentDb = basename((string)$full);
        $tq = '"' . str_replace('"','""',$table) . '"';
        $totalRows = (int)$pdo->query("SELECT COUNT(*) FROM {$tq}")->fetchColumn();
        if ($limit !== null && $totalRows > 0) {
            $totalPages = (int)max(1, ceil($totalRows / $limit)); if ($page > $totalPages) $page = $totalPages; $offset = ($page - 1) * $limit;
            $stmt = $pdo->prepare("SELECT * FROM {$tq} LIMIT :limit OFFSET :offset"); $stmt->bindValue(':limit',$limit,PDO::PARAM_INT); $stmt->bindValue(':offset',$offset,PDO::PARAM_INT); $stmt->execute();
        } else { $stmt = $pdo->query("SELECT * FROM {$tq}"); }
        $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    } else {
        throw new RuntimeException('Nicht unterstützter Typ: ' . $type);
    }

    // Build columns
    foreach ($rows as $row) { foreach (array_keys($row) as $c) { if (!in_array($c,$columns,true)) $columns[]=$c; } }

    // Render simple HTML
    $escapedTable = htmlspecialchars($table, ENT_QUOTES, 'UTF-8');
    $connLabel = htmlspecialchars((string)($conn['title'] ?? $conn['id'] ?? $type), ENT_QUOTES, 'UTF-8');
    $pageInputDisabled = $limit === null ? ' disabled' : '';
    ob_start();
    ?>
<!doctype html><html lang="de"><head><meta charset="utf-8"><title>DB Debug · <?= $connLabel ?> · <?= $escapedTable ?></title><meta name="viewport" content="width=device-width, initial-scale=1"><style>
  :root { color-scheme: dark; }
  body{background:#0f172a;color:#f8fafc;margin:0;padding:24px;font-family:Inter,system-ui,-apple-system,"Segoe UI",sans-serif}
  header{display:flex;justify-content:space-between;align-items:flex-end;margin-bottom:16px}
  .toolbar{display:flex;gap:12px;align-items:center;flex-wrap:wrap;background:rgba(30,41,59,.6);padding:12px;border-radius:12px;border:1px solid rgba(148,163,184,.25);margin-bottom:16px}
  select,input,button{background:rgba(148,163,184,.15);border:1px solid rgba(148,163,184,.3);color:inherit;border-radius:10px;padding:8px 12px;font:inherit}
  button{cursor:pointer;background:rgba(59,130,246,.2);border-color:rgba(59,130,246,.4)}
  table{width:100%;border-collapse:collapse;border-radius:12px;overflow:hidden}
  thead{background:rgba(30,41,59,.9)}
  th,td{padding:10px 12px;border-bottom:1px solid rgba(148,163,184,.15);text-align:left;font-size:.92rem;vertical-align:top}
  tbody tr:nth-child(odd){background:rgba(15,23,42,.65)}
  tbody tr:nth-child(even){background:rgba(15,23,42,.45)}
  code{font-family:"Fira Code","JetBrains Mono",ui-monospace,monospace;font-size:.85rem;white-space:pre-wrap}
  td code{max-height:12rem;display:block;overflow:auto;overflow-wrap:anywhere}
  .pagination{display:flex;justify-content:space-between;align-items:center;margin-top:12px;gap:12px;color:rgba(226,232,240,.8);font-size:.9rem}
  .pagination a{color:#bfdbfe;text-decoration:none;padding:6px 10px;border-radius:8px;border:1px solid rgba(148,163,184,.25);background:rgba(59,130,246,.15)}
</style></head><body>
<header><div><h1>DB · <?= $connLabel ?> · <?= $escapedTable ?></h1><div style="color:rgba(226,232,240,.8)">Datenbank: <?= htmlspecialchars($currentDb ?: ($type==='sqlite' ? $currentDb : ($settings['database'] ?? '')), ENT_QUOTES) ?> · <?= number_format($totalRows,0,',','.') ?> Zeilen<?= $limit===null?' · Alle':' · ' . $limit . ' pro Seite (Seite ' . $page . ' / ' . max(1,(int)ceil(max(1,$totalRows)/max(1,$limit))) ?>)</div></div><div><?= date('d.m.Y H:i:s') ?></div></header>
<form class="toolbar" method="get" action=""><input type="hidden" name="conn_id" value="<?= htmlspecialchars($connId,ENT_QUOTES) ?>"><label>Tabelle:<input type="text" name="table" value="<?= $escapedTable ?>" style="min-width:16rem"></label><label>Limit:<select name="limit"><?php foreach ($allowedLimits as $l){$sel=$l===$limitParam?' selected':'';$lab=$l==='all'?'Alle':$l;echo '<option value="',htmlspecialchars($l,ENT_QUOTES),'"',$sel,'>',htmlspecialchars($lab,ENT_QUOTES),'</option>'; } ?></select></label><?php if ($limit!==null): ?><label>Seite:<input type="number" name="page" value="<?= $page ?>" min="1" max="<?= max(1,(int)ceil(max(1,$totalRows)/max(1,$limit))) ?>"<?= $pageInputDisabled ?>></label><?php endif; ?><button type="submit">Neu laden</button></form>
<?php if ($rows === []) : ?><div>Keine Datensätze in dieser Seite. Gesamt: <?= number_format($totalRows,0,',','.') ?>. Passe ggf. Limit/Seite an.</div><?php else : ?><table><thead><tr><?php foreach ($columns as $c): ?><th><?= htmlspecialchars($c,ENT_QUOTES) ?></th><?php endforeach; ?></tr></thead><tbody><?php foreach ($rows as $r): ?><tr><?php foreach ($columns as $c): $v=$r[$c]??''; if ($v instanceof DateTimeInterface) { $v = $v->format('Y-m-d H:i:s'); } elseif (is_object($v) || is_array($v)) { $v = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); } $sv = (string)$v; ?><td><?php if ($sv===''){echo '<code style="opacity:.6">""</code>'; } else { echo '<code>'.htmlspecialchars($sv,ENT_QUOTES).'</code>'; } ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody></table><?php endif; ?>
</body></html>
<?php
    echo (string)ob_get_clean();
} catch (Throwable $e) { http_response_code(500); echo 'Fehler: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'); }
