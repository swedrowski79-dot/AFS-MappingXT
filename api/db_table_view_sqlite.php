<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    api_error('Methode nicht erlaubt', 405);
}

$table = isset($_GET['table']) ? (string)$_GET['table'] : '';
$path = isset($_GET['path']) ? (string)$_GET['path'] : '';
$limitParam = isset($_GET['limit']) ? strtolower((string)$_GET['limit']) : '100';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;

if ($table === '' || $path === '') {
    http_response_code(400);
    echo 'Fehlende Parameter';
    exit;
}

$allowedLimits = ['100','250','500','all'];
if (!in_array($limitParam, $allowedLimits, true)) {
    $limitParam = '100';
}
$limit = $limitParam === 'all' ? null : (int)$limitParam;

try {
    if (!is_file($path)) {
        throw new RuntimeException('SQLite-Datei nicht gefunden: ' . $path);
    }
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $quote = fn(string $ident) => '"' . str_replace('"', '""', $ident) . '"';
    $tableQuoted = $quote($table);

    $countStmt = $pdo->query("SELECT COUNT(*) AS total FROM {$tableQuoted}");
    $countRow = $countStmt ? $countStmt->fetch(PDO::FETCH_ASSOC) : ['total' => 0];
    $totalRows = (int)($countRow['total'] ?? 0);

    if ($limit === null || $totalRows === 0) {
        $totalPages = 1;
        $page = 1;
        $offset = 0;
    } else {
        $totalPages = (int)max(1, ceil($totalRows / $limit));
        if ($page > $totalPages) $page = $totalPages;
        $offset = ($page - 1) * $limit;
    }

    if ($limit === null) {
        $stmt = $pdo->prepare("SELECT * FROM {$tableQuoted}");
    } else {
        $stmt = $pdo->prepare("SELECT * FROM {$tableQuoted} LIMIT :limit OFFSET :offset");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    }
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Build columns
    $columns = [];
    foreach ($rows as $row) {
        foreach (array_keys($row) as $col) {
            if (!in_array($col, $columns, true)) $columns[] = $col;
        }
    }

    // Fetch tables for selector
    $tables = [];
    $tStmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name");
    if ($tStmt) {
        while ($r = $tStmt->fetch(PDO::FETCH_ASSOC)) {
            $n = (string)($r['name'] ?? '');
            if ($n !== '') $tables[] = $n;
        }
    }

    $limitOptions = function(string $selected) use ($allowedLimits): string {
        $labels = ['100'=>'100','250'=>'250','500'=>'500','all'=>'Alle'];
        $h=''; foreach ($allowedLimits as $l) { $sel=$l===$selected?' selected':''; $h.=sprintf('<option value="%s"%s>%s</option>',htmlspecialchars($l,ENT_QUOTES),$sel,htmlspecialchars($labels[$l]??$l,ENT_QUOTES)); }
        return $h;
    };
    $tableOptions = function(string $selected, array $list): string {
        $h=''; foreach ($list as $t) { $sel=$t===$selected?' selected':''; $h.=sprintf('<option value="%s"%s>%s</option>',htmlspecialchars($t,ENT_QUOTES),$sel,htmlspecialchars($t,ENT_QUOTES)); }
        return $h;
    };

    $escapedTable = htmlspecialchars($table, ENT_QUOTES, 'UTF-8');
    $escapedPath = htmlspecialchars($path, ENT_QUOTES, 'UTF-8');

    ob_start();
    ?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <title>SQLite Debug · <?= $escapedTable ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body{color-scheme:dark;background:#0f172a;color:#f8fafc;margin:0;padding:24px;font-family:Inter,system-ui,-apple-system,"Segoe UI",sans-serif}
    header{display:flex;justify-content:space-between;align-items:flex-end;margin-bottom:16px}
    .toolbar{display:flex;gap:12px;align-items:center;flex-wrap:wrap;background:rgba(30,41,59,.6);padding:12px;border-radius:12px;border:1px solid rgba(148,163,184,.25);margin-bottom:16px}
    select,input,button{background:rgba(148,163,184,.15);border:1px solid rgba(148,163,184,.3);color:inherit;border-radius:10px;padding:8px 12px;font:inherit}
    button{cursor:pointer;background:rgba(59,130,246,.2);border-color:rgba(59,130,246,.4)}
    table{width:100%;border-collapse:collapse;border-radius:12px;overflow:hidden}
    thead{background:rgba(30,41,59,.9)}
    th,td{padding:10px 12px;border-bottom:1px solid rgba(148,163,184,.15);text-align:left;font-size:.92rem;vertical-align:top}
    tbody tr:nth-child(odd){background:rgba(15,23,42,.65)}
    tbody tr:nth-child(even){background:rgba(15,23,42,.45)}
    code{font-family:"Fira Code","JetBrains Mono",ui-monospace,monospace;font-size:.85rem}
    .pagination{display:flex;justify-content:space-between;align-items:center;margin-top:12px;gap:12px;color:rgba(226,232,240,.8);font-size:.9rem}
    .pagination a{color:#bfdbfe;text-decoration:none;padding:6px 10px;border-radius:8px;border:1px solid rgba(148,163,184,.25);background:rgba(59,130,246,.15)}
  </style>
</head>
<body>
  <header>
    <div>
      <h1>SQLite · <?= $escapedPath ?> · <?= $escapedTable ?></h1>
      <div style="color:rgba(226,232,240,.8)"><?= number_format($totalRows,0,',','.') ?> Zeilen</div>
    </div>
    <div><?= date('d.m.Y H:i:s') ?></div>
  </header>

  <form class="toolbar" method="get" action="">
    <input type="hidden" name="path" value="<?= $escapedPath ?>">
    <label>Tabelle:<select name="table"><?= $tableOptions($table, $tables) ?></select></label>
    <label>Limit:<select name="limit"><?= $limitOptions($limitParam) ?></select></label>
    <?php if ($limit !== null): ?>
      <label>Seite:<input type="number" name="page" value="<?= $page ?>" min="1" max="<?= max(1,$totalPages) ?>"></label>
    <?php endif; ?>
    <button type="submit">Neu laden</button>
  </form>

  <?php if ($rows === []) : ?>
    <div>Keine Datensätze gefunden.</div>
  <?php else : ?>
    <table>
      <thead><tr><?php foreach ($columns as $c): ?><th><?= htmlspecialchars($c,ENT_QUOTES) ?></th><?php endforeach; ?></tr></thead>
      <tbody>
        <?php foreach ($rows as $row): ?><tr><?php foreach ($columns as $c): ?><td><?php $v=$row[$c]??null; if ($v===null) {echo '<code style="opacity:.6">NULL</code>';} elseif ($v==='') {echo '<code style="opacity:.6">""</code>';} elseif (is_scalar($v)) {echo '<code>'.htmlspecialchars(is_bool($v)?($v?'1':'0'):(string)$v,ENT_QUOTES).'</code>';} else {echo '<code>'.htmlspecialchars(json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),ENT_QUOTES).'</code>';} ?></td><?php endforeach; ?></tr><?php endforeach; ?>
      </tbody>
    </table>
    <?php if ($limit !== null && $totalPages > 1): ?>
      <div class="pagination">
        <div>Seite <?= $page ?> von <?= $totalPages ?></div>
        <div style="display:flex;gap:8px;">
          <?php if ($page>1): ?><a href="?<?= http_build_query(['path'=>$path,'table'=>$table,'limit'=>$limitParam,'page'=>1]) ?>">« Erste</a><a href="?<?= http_build_query(['path'=>$path,'table'=>$table,'limit'=>$limitParam,'page'=>$page-1]) ?>">‹ Zurück</a><?php endif; ?>
          <?php if ($page<$totalPages): ?><a href="?<?= http_build_query(['path'=>$path,'table'=>$table,'limit'=>$limitParam,'page'=>$page+1]) ?>">Weiter ›</a><a href="?<?= http_build_query(['path'=>$path,'table'=>$table,'limit'=>$limitParam,'page'=>$totalPages]) ?>">Letzte »</a><?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</body>
</html>
<?php
    echo (string)ob_get_clean();
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Fehler: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
}
