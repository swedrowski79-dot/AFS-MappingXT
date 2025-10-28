<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    api_error('Methode nicht erlaubt', 405);
}

function findConnectionById(string $id): ?array {
    $cfg = DatabaseConfig::load();
    foreach ($cfg['connections'] as $c) {
        if ((string)($c['id'] ?? '') === $id) return $c;
    }
    return null;
}

$connId = isset($_GET['conn_id']) ? (string)$_GET['conn_id'] : '';
$table  = isset($_GET['table']) ? (string)$_GET['table'] : '';
$limitParam = isset($_GET['limit']) ? strtolower((string)$_GET['limit']) : '100';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;

if ($connId === '' || $table === '') {
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
    $conn = findConnectionById($connId);
    if (!$conn) throw new RuntimeException('Verbindung nicht gefunden');
    if (($conn['type'] ?? '') !== 'file') throw new RuntimeException('Nur Dateipfad-Verbindungen werden unterstützt');
    $settings = is_array($conn['settings'] ?? null) ? $conn['settings'] : [];
    $basePath = (string)($settings['path'] ?? '');
    if ($basePath === '') throw new RuntimeException('Pfad fehlt');

    // Normalize root and target table directory
    $root = (new ReflectionClass('DatabaseConfig'))->getMethod('normalizePath')->invoke(null, $basePath);
    $tableDir = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $table;
    if (!is_dir($tableDir)) throw new RuntimeException('Tabellenordner nicht gefunden: ' . $table);

    // Records are subdirectories (IDs)
    $allIds = array_values(array_filter(scandir($tableDir) ?: [], function($n) use ($tableDir) {
        return $n !== '.' && $n !== '..' && is_dir($tableDir . DIRECTORY_SEPARATOR . $n);
    }));
    sort($allIds, SORT_NATURAL | SORT_FLAG_CASE);

    $totalRows = count($allIds);
    if ($limit === null || $totalRows === 0) {
        $totalPages = 1;
        $page = 1;
        $offset = 0;
    } else {
        $totalPages = (int)max(1, ceil($totalRows / $limit));
        if ($page > $totalPages) $page = $totalPages;
        $offset = ($page - 1) * $limit;
    }

    $pageIds = $limit === null ? $allIds : array_slice($allIds, $offset, $limit);

    // Build rows by reading text files in each record folder
    $rows = [];
    $columns = ['ID'];
    foreach ($pageIds as $idDir) {
        $recordPath = $tableDir . DIRECTORY_SEPARATOR . $idDir;
        $fields = ['ID' => $idDir];
        $files = array_values(array_filter(scandir($recordPath) ?: [], function($n) use ($recordPath) {
            return $n !== '.' && $n !== '..' && is_file($recordPath . DIRECTORY_SEPARATOR . $n);
        }));
        foreach ($files as $fname) {
            $field = pathinfo($fname, PATHINFO_FILENAME);
            $content = @file_get_contents($recordPath . DIRECTORY_SEPARATOR . $fname);
            if ($content === false) $content = '';
            $content = rtrim($content, "\r\n");
            $fields[$field] = $content;
            if (!in_array($field, $columns, true)) $columns[] = $field;
        }
        $rows[] = $fields;
    }

    // Render HTML
    $escapedTable = htmlspecialchars($table, ENT_QUOTES, 'UTF-8');
    $connLabel = htmlspecialchars((string)($conn['title'] ?? $conn['id'] ?? 'Dateipfad'), ENT_QUOTES, 'UTF-8');
    $pageInputDisabled = $limit === null ? ' disabled' : '';

    ob_start();
    ?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <title>File Debug · <?= $connLabel ?> · <?= $escapedTable ?></title>
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
    code{font-family:"Fira Code","JetBrains Mono",ui-monospace,monospace;font-size:.85rem;white-space:pre-wrap}
    .pagination{display:flex;justify-content:space-between;align-items:center;margin-top:12px;gap:12px;color:rgba(226,232,240,.8);font-size:.9rem}
    .pagination a{color:#bfdbfe;text-decoration:none;padding:6px 10px;border-radius:8px;border:1px solid rgba(148,163,184,.25);background:rgba(59,130,246,.15)}
  </style>
</head>
<body>
  <header>
    <div>
      <h1>File · <?= $connLabel ?> · <?= $escapedTable ?></h1>
      <div style="color:rgba(226,232,240,.8)"><?= number_format($totalRows,0,',','.') ?> Einträge</div>
    </div>
    <div><?= date('d.m.Y H:i:s') ?></div>
  </header>

  <form class="toolbar" method="get" action="">
    <input type="hidden" name="conn_id" value="<?= htmlspecialchars($connId, ENT_QUOTES) ?>">
    <input type="hidden" name="table" value="<?= $escapedTable ?>">
    <label>Limit:<select name="limit">
      <?php foreach ($allowedLimits as $l): $sel=$l===$limitParam?' selected':''; $lab=$l==='all'?'Alle':$l; echo '<option value="',htmlspecialchars($l,ENT_QUOTES),'"',$sel,'>',htmlspecialchars($lab,ENT_QUOTES),'</option>'; endforeach; ?>
    </select></label>
    <?php if ($limit !== null): ?>
      <label>Seite:<input type="number" name="page" value="<?= $page ?>" min="1" max="<?= max(1, (int)ceil(max(1,$totalRows)/max(1,$limit))) ?>"<?= $pageInputDisabled ?>></label>
    <?php endif; ?>
    <button type="submit">Neu laden</button>
  </form>

  <?php if ($rows === []) : ?>
    <div>Keine Datensätze gefunden.</div>
  <?php else : ?>
    <table>
      <thead><tr><?php foreach ($columns as $c): ?><th><?= htmlspecialchars($c,ENT_QUOTES) ?></th><?php endforeach; ?></tr></thead>
      <tbody>
        <?php foreach ($rows as $r): ?><tr><?php foreach ($columns as $c): $v=$r[$c]??''; ?><td><?php if ($v===''){echo '<code style="opacity:.6">""</code>'; } else { echo '<code>'.htmlspecialchars((string)$v,ENT_QUOTES).'</code>'; } ?></td><?php endforeach; ?></tr><?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</body>
</html>
<?php
    echo (string)ob_get_clean();
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Fehler: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
}
