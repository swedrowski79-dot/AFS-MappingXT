<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$allowedTablesMap = [
    'main' => [
        'Artikel',
        'Artikel_Bilder',
        'Artikel_Dokumente',
        'Attrib_Artikel',
        'Attribute',
        'Bilder',
        'Dokumente',
        'category',
    ],
    'delta' => [
        'Artikel',
        'Artikel_Bilder',
        'Artikel_Dokumente',
        'Attrib_Artikel',
        'Attribute',
        'Bilder',
        'Dokumente',
        'category',
    ],
    'status' => [
        'sync_status',
        'sync_log',
    ],
];

$allowedLimits = ['100', '250', '500', 'all'];
$allowedDbs = [
    'main' => 'Hauptdatenbank',
    'delta' => 'Delta-Datenbank',
    'status' => 'Status-Datenbank',
];

$table = isset($_GET['table']) ? (string)$_GET['table'] : '';
$limitParam = isset($_GET['limit']) ? strtolower((string)$_GET['limit']) : '100';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$dbKey = isset($_GET['db']) ? strtolower((string)$_GET['db']) : 'main';

if (!array_key_exists($dbKey, $allowedTablesMap)) {
    $dbKey = 'main';
}

$allowedTables = $allowedTablesMap[$dbKey];

if (!in_array($table, $allowedTables, true)) {
    http_response_code(400);
    echo renderError('Ungültige Tabelle', $table, $limitParam, $page, $allowedTables, $allowedLimits, $dbKey, $allowedDbs);
    exit;
}

if (!in_array($limitParam, $allowedLimits, true)) {
    $limitParam = '100';
}

$dbKey = array_key_exists($dbKey, $allowedDbs) ? $dbKey : 'main';

$limit = $limitParam === 'all' ? null : (int)$limitParam;
if ($limit === null) {
    $page = 1;
}

try {
    global $config;
    $pdo = match ($dbKey) {
        'delta' => createEvoDeltaPdo($config),
        'status' => createStatusPdo($config),
        default => createEvoPdo($config),
    };
    $tableQuoted = '"' . str_replace('"', '""', $table) . '"';

    $countStmt = $pdo->query("SELECT COUNT(*) AS total FROM {$tableQuoted}");
    $countRow = $countStmt ? $countStmt->fetch(PDO::FETCH_ASSOC) : ['total' => 0];
    $totalRows = (int)($countRow['total'] ?? 0);

    if ($limit === null || $totalRows === 0) {
        $totalPages = 1;
        $page = 1;
        $offset = 0;
    } else {
        $totalPages = (int)max(1, ceil($totalRows / $limit));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
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
} catch (\Throwable $e) {
    http_response_code(500);
    echo renderError($e->getMessage(), $table, $limitParam, $page, $allowedTables, $allowedLimits, $dbKey, $allowedDbs);
    exit;
}

echo renderTable(
    $table,
    $rows,
    $totalRows,
    $limit,
    $limitParam,
    $page,
    $totalPages,
    $allowedTables,
    $allowedLimits,
    $dbKey,
    $allowedDbs
);

function renderTable(
    string $table,
    array $rows,
    int $total,
    ?int $limit,
    string $limitParam,
    int $page,
    int $totalPages,
    array $allowedTables,
    array $allowedLimits,
    string $dbKey,
    array $allowedDbs
): string {
    $columns = [];
    foreach ($rows as $row) {
        foreach (array_keys($row) as $col) {
            if (!in_array($col, $columns, true)) {
                $columns[] = $col;
            }
        }
    }

    $tableOptions = buildTableOptions($table, $allowedTables);
    $limitOptions = buildLimitOptions($limitParam, $allowedLimits);
    $dbOptions = buildDbOptions($dbKey, $allowedDbs);
    $escapedTable = htmlspecialchars($table, ENT_QUOTES, 'UTF-8');
    $pageInputDisabled = $limit === null ? ' disabled' : '';
    $dbLabel = htmlspecialchars($allowedDbs[$dbKey] ?? 'Hauptdatenbank', ENT_QUOTES, 'UTF-8');

    ob_start();
    ?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <title>EVO Debug · <?= $escapedTable ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    :root {
      color-scheme: dark;
      font-family: "Inter", system-ui, -apple-system, "Segoe UI", sans-serif;
      background: #0f172a;
      color: #f8fafc;
    }
    body {
      margin: 0;
      padding: 24px 28px 48px;
      background: linear-gradient(180deg, rgba(15, 23, 42, 0.9), rgba(15, 23, 42, 0.75));
    }
    header {
      display: flex;
      flex-wrap: wrap;
      justify-content: space-between;
      gap: 12px;
      align-items: flex-end;
      margin-bottom: 24px;
    }
    header h1 {
      margin: 0;
      font-size: 1.6rem;
      letter-spacing: -0.01em;
    }
    .meta {
      color: rgba(226, 232, 240, 0.8);
      font-size: 0.95rem;
    }
    .toolbar {
      display: flex;
      gap: 12px;
      align-items: center;
      flex-wrap: wrap;
      background: rgba(30, 41, 59, 0.6);
      padding: 16px;
      border-radius: 16px;
      border: 1px solid rgba(148, 163, 184, 0.25);
      margin-bottom: 24px;
    }
    select, input, button {
      background: rgba(148, 163, 184, 0.15);
      border: 1px solid rgba(148, 163, 184, 0.3);
      color: inherit;
      border-radius: 10px;
      padding: 8px 12px;
      font: inherit;
    }
    button {
      cursor: pointer;
      background: rgba(59, 130, 246, 0.2);
      border-color: rgba(59, 130, 246, 0.4);
    }
    button:hover {
      background: rgba(59, 130, 246, 0.28);
    }
    table {
      width: 100%;
      border-collapse: collapse;
      border-radius: 16px;
      overflow: hidden;
      box-shadow: 0 18px 48px rgba(15, 23, 42, 0.45);
    }
    thead {
      background: rgba(30, 41, 59, 0.9);
    }
    th, td {
      padding: 12px 14px;
      border-bottom: 1px solid rgba(148, 163, 184, 0.15);
      text-align: left;
      font-size: 0.92rem;
      vertical-align: top;
    }
    tbody tr:nth-child(odd) {
      background: rgba(15, 23, 42, 0.65);
    }
    tbody tr:nth-child(even) {
      background: rgba(15, 23, 42, 0.45);
    }
    code {
      font-family: "Fira Code", "JetBrains Mono", ui-monospace, SFMono-Regular, Consolas, "Liberation Mono", Menlo, monospace;
      font-size: 0.85rem;
    }
    .empty {
      padding: 42px;
      text-align: center;
      color: rgba(148, 163, 184, 0.75);
      background: rgba(30, 41, 59, 0.6);
      border-radius: 16px;
      border: 1px dashed rgba(148, 163, 184, 0.35);
    }
    .pagination {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-top: 18px;
      gap: 12px;
      flex-wrap: wrap;
      color: rgba(226, 232, 240, 0.8);
      font-size: 0.9rem;
    }
    .pagination a {
      color: #bfdbfe;
      text-decoration: none;
      padding: 6px 10px;
      border-radius: 8px;
      border: 1px solid rgba(148, 163, 184, 0.25);
      background: rgba(59, 130, 246, 0.15);
    }
    .pagination a:hover {
      background: rgba(59, 130, 246, 0.25);
    }
    .pagination .spacer {
      flex-grow: 1;
    }
  </style>
</head>
<body>
  <header>
    <div>
      <h1>EVO Debug · <?= $dbLabel ?> · <?= $escapedTable ?></h1>
      <div class="meta">
        Quelle: <?= $dbLabel ?><br>
        <?= number_format($total, 0, ',', '.') ?> Zeilen insgesamt
        <?php if ($limit === null): ?>
          &middot; Anzeige: alle Einträge
        <?php else: ?>
          &middot; <?= $limit ?> pro Seite (Seite <?= $page ?> / <?= $totalPages ?>)
        <?php endif; ?>
      </div>
    </div>
    <div class="meta"><?= date('d.m.Y H:i:s') ?></div>
  </header>

  <form class="toolbar" method="get" action="">
    <label>
      Datenbank:
      <select name="db">
        <?= $dbOptions ?>
      </select>
    </label>
    <label>
      Tabelle:
      <select name="table">
        <?= $tableOptions ?>
      </select>
    </label>
    <label>
      Limit:
      <select name="limit">
        <?= $limitOptions ?>
      </select>
    </label>
    <label>
      Seite:
      <input type="number" name="page" value="<?= $page ?>" min="1" max="<?= max(1, $totalPages) ?>"<?= $pageInputDisabled ?>>
    </label>
    <button type="submit">Neu laden</button>
  </form>

  <?php if ($rows === []) : ?>
    <div class="empty">Keine Datensätze gefunden.</div>
  <?php else : ?>
    <table>
      <thead>
        <tr>
          <?php foreach ($columns as $column) : ?>
            <th><?= htmlspecialchars($column, ENT_QUOTES, 'UTF-8') ?></th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $row) : ?>
          <tr>
            <?php foreach ($columns as $column) : ?>
              <td>
                <?php
                $value = $row[$column] ?? null;
                if ($value === null) {
                    echo '<code style="opacity:0.6">NULL</code>';
                } elseif ($value === '') {
                    echo '<code style="opacity:0.6">""</code>';
                } elseif (is_scalar($value)) {
                    $string = is_bool($value) ? ($value ? '1' : '0') : (string)$value;
                    echo '<code>' . htmlspecialchars($string, ENT_QUOTES, 'UTF-8') . '</code>';
                } else {
                    echo '<code>' . htmlspecialchars(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') . '</code>';
                }
                ?>
              </td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <?php if ($limit !== null && $totalPages > 1) : ?>
      <?= renderPagination($table, $limitParam, $page, $totalPages, $dbKey) ?>
    <?php endif; ?>
  <?php endif; ?>
</body>
</html>
    <?php
    return (string)ob_get_clean();
}

function renderPagination(string $table, string $limitParam, int $page, int $totalPages, string $dbKey): string
{
    $makeLink = function (int $targetPage, string $label) use ($table, $limitParam, $dbKey): string {
        $url = buildPageUrl($table, $limitParam, $targetPage, $dbKey);
        return sprintf(
            '<a href="%s">%s</a>',
            htmlspecialchars($url, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
        );
    };

    $parts = [];
    if ($page > 1) {
        $parts[] = $makeLink(1, '« Erste');
        $parts[] = $makeLink($page - 1, '‹ Zurück');
    }
    if ($page < $totalPages) {
        $parts[] = $makeLink($page + 1, 'Weiter ›');
        $parts[] = $makeLink($totalPages, 'Letzte »');
    }

    $info = sprintf('Seite %d von %d', $page, $totalPages);

    return sprintf(
        '<nav class="pagination"><div>%s</div><div class="spacer"></div><div style="display:flex;gap:8px;">%s</div></nav>',
        htmlspecialchars($info, ENT_QUOTES, 'UTF-8'),
        $parts === [] ? '' : implode('', $parts)
    );
}

function renderError(
    string $message,
    string $table,
    string $limitParam,
    int $page,
    array $allowedTables,
    array $allowedLimits,
    string $dbKey,
    array $allowedDbs
): string {
    $tableOptions = buildTableOptions($table, $allowedTables);
    $limitOptions = buildLimitOptions($limitParam, $allowedLimits);
    $dbOptions = buildDbOptions($dbKey, $allowedDbs);
    $escapedMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    $page = max(1, $page);

    ob_start();
    ?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <title>EVO Debug · Fehler</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body {
      color-scheme: dark;
      font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
      background: #0f172a;
      color: #f8fafc;
      margin: 0;
      padding: 36px;
      display: grid;
      place-items: center;
    }
    .card {
      background: rgba(30, 41, 59, 0.75);
      border: 1px solid rgba(148, 163, 184, 0.25);
      padding: 28px;
      border-radius: 18px;
      width: min(480px, 100%);
      text-align: center;
      box-shadow: 0 18px 45px rgba(15, 23, 42, 0.45);
    }
    h1 {
      margin: 0 0 12px;
    }
    form {
      margin-top: 20px;
      display: flex;
      gap: 12px;
      justify-content: center;
      flex-wrap: wrap;
    }
    select, input, button {
      background: rgba(148, 163, 184, 0.15);
      border: 1px solid rgba(148, 163, 184, 0.3);
      color: inherit;
      border-radius: 10px;
      padding: 8px 12px;
      font: inherit;
    }
    button {
      cursor: pointer;
      background: rgba(59, 130, 246, 0.2);
      border-color: rgba(59, 130, 246, 0.4);
    }
  </style>
</head>
<body>
  <div class="card">
    <h1>Fehler</h1>
    <p><?= $escapedMessage ?></p>
    <form method="get" action="">
      <select name="table">
        <?= $tableOptions ?>
      </select>
      <select name="limit">
        <?= $limitOptions ?>
      </select>
      <select name="db">
        <?= $dbOptions ?>
      </select>
      <input type="number" name="page" value="<?= $page ?>" min="1">
      <button type="submit">Neu versuchen</button>
    </form>
  </div>
</body>
</html>
    <?php
    return (string)ob_get_clean();
}

function buildTableOptions(string $selected, array $allowedTables): string
{
    $html = '';
    foreach ($allowedTables as $table) {
        $isSelected = $table === $selected ? ' selected' : '';
        $html .= sprintf(
            '<option value="%s"%s>%s</option>',
            htmlspecialchars($table, ENT_QUOTES, 'UTF-8'),
            $isSelected,
            htmlspecialchars($table, ENT_QUOTES, 'UTF-8')
        );
    }
    return $html;
}

function buildLimitOptions(string $selected, array $allowedLimits): string
{
    $labels = [
        '100' => '100',
        '250' => '250',
        '500' => '500',
        'all' => 'Alle',
    ];

    $html = '';
    foreach ($allowedLimits as $limit) {
        $isSelected = $limit === $selected ? ' selected' : '';
        $label = $labels[$limit] ?? $limit;
        $html .= sprintf(
            '<option value="%s"%s>%s</option>',
            htmlspecialchars($limit, ENT_QUOTES, 'UTF-8'),
            $isSelected,
            htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
        );
    }
    return $html;
}

function buildDbOptions(string $selected, array $allowedDbs): string
{
    $html = '';
    foreach ($allowedDbs as $key => $label) {
        $isSelected = $key === $selected ? ' selected' : '';
        $html .= sprintf(
            '<option value="%s"%s>%s</option>',
            htmlspecialchars($key, ENT_QUOTES, 'UTF-8'),
            $isSelected,
            htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
        );
    }
    return $html;
}

function buildPageUrl(string $table, string $limitParam, int $page, string $dbKey): string
{
    $query = http_build_query([
        'table' => $table,
        'limit' => $limitParam,
        'page' => $page,
        'db' => $dbKey,
    ]);
    return '?' . $query;
}
