<?php
declare(strict_types=1);

final class MediaLinkService
{
    private PDO $sqlite;
    private MSSQL_Connection $mssql;

    public function __construct(PDO $sqlite, MSSQL_Connection $mssql)
    {
        $this->sqlite = $sqlite;
        $this->mssql = $mssql;
    }

    /**
     * Synchronisiert Bild- und Dokument-Verknüpfungen zwischen AFS und der lokalen SQLite-Datenbank.
     *
     * @return array<string,mixed>
     */
    public function sync(): array
    {
        $this->ensureSchema();

        $articleImages   = $this->fetchArticleImages();
        $categoryImages  = $this->fetchCategoryImages();
        $documentEntries = $this->fetchDocuments();

        $imageRows = $this->loadImageRows();
        $documentRows = $this->loadDocumentRows();

        $now = (new DateTimeImmutable())->format('c');

        $removedImages = 0;
        $removedDocuments = 0;
        $deactivatedArticleLinks = 0;
        $deactivatedCategoryLinks = 0;
        $deactivatedDocumentLinks = 0;

        $this->sqlite->beginTransaction();
        try {
            $this->sqlite->exec("UPDATE bilder SET image_id = COALESCE(NULLIF(image_id, ''), file_name)");
            $this->sqlite->exec("UPDATE bilder SET media_type = 0, art_id = NULL, cat_id = NULL");
            $this->sqlite->exec("UPDATE dokumente SET doc_id = COALESCE(NULLIF(doc_id, ''), file_name)");
            $this->sqlite->exec("DELETE FROM dokumente WHERE mime LIKE 'image/%'");
            $this->sqlite->exec("DELETE FROM bilder WHERE mime IS NOT NULL AND mime NOT LIKE 'image/%'");

            $resetArticleLinks = $this->sqlite->prepare('UPDATE artikel_bilder SET status = 0, change = 1, updated_at = :updated');
            $resetArticleLinks->execute([':updated' => $now]);
            $deactivatedArticleLinks = (int)$this->sqlite->query('SELECT changes()')->fetchColumn();

            $resetCategoryLinks = $this->sqlite->prepare('UPDATE category_bilder SET status = 0, change = 1, updated_at = :updated');
            $resetCategoryLinks->execute([':updated' => $now]);
            $deactivatedCategoryLinks = (int)$this->sqlite->query('SELECT changes()')->fetchColumn();

            $resetDocumentLinks = $this->sqlite->prepare('UPDATE artikel_dokumente SET status = 0, change = 1, updated_at = :updated');
            $resetDocumentLinks->execute([':updated' => $now]);
            $deactivatedDocumentLinks = (int)$this->sqlite->query('SELECT changes()')->fetchColumn();

            $missingImages = 0;
            $articleLinkCount = 0;
            $updateArticleImage = $this->sqlite->prepare(
                'UPDATE bilder SET media_type = 1, art_id = :article WHERE rowid = :rowid'
            );
            $insertArticleLink = $this->sqlite->prepare(
                'INSERT OR REPLACE INTO artikel_bilder (id, image_id, position, status, change, updated_at)
                 VALUES (:article, :image, :position, 1, 1, :updated)'
            );

            foreach ($articleImages as $entry) {
                $key = $entry['file_key'];
                $imageCandidates = $imageRows[$key] ?? [];
                if ($imageCandidates === []) {
                    $missingImages++;
                    continue;
                }
                foreach ($imageCandidates as $img) {
                    $updateArticleImage->execute([
                        ':article' => $entry['article'],
                        ':rowid'   => $img['rowid'],
                    ]);
                    $insertArticleLink->execute([
                        ':article'  => $entry['article'],
                        ':image'    => $img['file_name'],
                        ':position' => $entry['position'],
                        ':updated'  => $now,
                    ]);
                    $articleLinkCount++;
                }
            }

            $missingCategoryImages = 0;
            $categoryLinkCount = 0;
            $updateCategoryImage = $this->sqlite->prepare(
                'UPDATE bilder SET media_type = CASE WHEN media_type = 1 THEN 1 ELSE 2 END, cat_id = :cat WHERE rowid = :rowid'
            );
            $insertCategoryLink = $this->sqlite->prepare(
                'INSERT OR REPLACE INTO category_bilder (cat_id, image_id, image_type, status, change, updated_at)
                 VALUES (:cat, :image, :type, 1, 1, :updated)'
            );

            foreach ($categoryImages as $entry) {
                $key = $entry['file_key'];
                $imageCandidates = $imageRows[$key] ?? [];
                if ($imageCandidates === []) {
                    $missingCategoryImages++;
                    continue;
                }
                foreach ($imageCandidates as $img) {
                    $updateCategoryImage->execute([
                        ':cat'   => $entry['category'],
                        ':rowid' => $img['rowid'],
                    ]);
                    $insertCategoryLink->execute([
                        ':cat'    => $entry['category'],
                        ':image'  => $img['file_name'],
                        ':type'   => $entry['type'],
                        ':updated'=> $now,
                    ]);
                    $categoryLinkCount++;
                }
            }

            $missingDocuments = 0;
            $documentLinkCount = 0;
            $insertDocumentLink = $this->sqlite->prepare(
                'INSERT OR REPLACE INTO artikel_dokumente (id, doc_id, status, change, updated_at)
                 VALUES (:article, :doc, 1, 1, :updated)'
            );

            foreach ($documentEntries as $entry) {
                $key = $entry['file_key'];
                $docCandidates = $documentRows[$key] ?? [];
                if ($docCandidates === []) {
                    $missingDocuments++;
                    continue;
                }
                foreach ($docCandidates as $doc) {
                    $insertDocumentLink->execute([
                        ':article' => $entry['article'],
                        ':doc'     => $doc['file_name'],
                        ':updated' => $now,
                    ]);
                    $documentLinkCount++;
                }
            }

            $this->sqlite->exec("DELETE FROM bilder WHERE media_type = 0");
            $removedImages = (int)$this->sqlite->query("SELECT changes()")->fetchColumn();
            $this->sqlite->exec("DELETE FROM dokumente WHERE doc_id NOT IN (SELECT DISTINCT doc_id FROM artikel_dokumente)");
            $removedDocuments = (int)$this->sqlite->query("SELECT changes()")->fetchColumn();

            $this->sqlite->commit();
        } catch (Throwable $e) {
            $this->sqlite->rollBack();
            throw $e;
        }

        return [
            'bilder' => [
                'article_links'   => $articleLinkCount,
                'category_links'  => $categoryLinkCount,
                'missing_images'  => $missingImages,
                'missing_cat_images' => $missingCategoryImages,
                'removed_unreferenced' => $removedImages,
                'deactivated_links' => $deactivatedArticleLinks,
                'deactivated_category_links' => $deactivatedCategoryLinks,
            ],
            'dokumente' => [
                'article_links'     => $documentLinkCount,
                'missing_documents' => $missingDocuments,
                'removed_unreferenced' => $removedDocuments,
                'deactivated_links' => $deactivatedDocumentLinks,
            ],
        ];
    }

    private function ensureSchema(): void
    {
        $this->ensureColumnExists('bilder', 'media_type', 'INTEGER DEFAULT 0');
        $this->ensureColumnExists('bilder', 'art_id', 'TEXT');
        $this->ensureColumnExists('bilder', 'cat_id', 'TEXT');
        $this->ensureColumnExists('bilder', 'image_id', 'TEXT');
        $this->ensureColumnExists('dokumente', 'doc_id', 'TEXT');

        $this->sqlite->exec(
            'CREATE TABLE IF NOT EXISTS category_bilder (
                cat_id TEXT NOT NULL,
                image_id TEXT NOT NULL,
                image_type INTEGER NOT NULL DEFAULT 1,
                status INTEGER NOT NULL DEFAULT 1,
                change INTEGER NOT NULL DEFAULT 1,
                updated_at TEXT,
                PRIMARY KEY (cat_id, image_id, image_type)
            )'
        );
    }

    private function ensureColumnExists(string $table, string $column, string $definition): void
    {
        $stmt = $this->sqlite->prepare(
            "SELECT 1 FROM pragma_table_info(:table) WHERE lower(name) = lower(:column)"
        );
        $stmt->execute([':table' => $table, ':column' => $column]);
        $exists = (bool)$stmt->fetchColumn();
        if (!$exists) {
            $this->sqlite->exec(sprintf(
                'ALTER TABLE %s ADD COLUMN %s %s',
                $table,
                $column,
                $definition
            ));
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function fetchArticleImages(): array
    {
        $rows = $this->mssql->fetchAll(
            'SELECT Artikelnummer, Bild1, Bild2, Bild3, Bild4, Bild5, Bild6, Bild7, Bild8, Bild9, Bild10 FROM Artikel'
        );
        $result = [];
        foreach ($rows as $row) {
            $article = trim((string)($row['Artikelnummer'] ?? ''));
            if ($article === '') {
                continue;
            }
            for ($i = 1; $i <= 10; $i++) {
                $field = 'Bild' . $i;
                [$file, $key] = $this->normalizeFileName($row[$field] ?? '');
                if ($file === '') {
                    continue;
                }
                $result[] = [
                    'article'   => $article,
                    'file'      => $file,
                    'file_key'  => $key,
                    'position'  => $i,
                ];
            }
        }
        return $result;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function fetchCategoryImages(): array
    {
        $rows = $this->mssql->fetchAll(
            'SELECT Warengruppe, Bild, Bild_gross FROM Warengruppe'
        );
        $result = [];
        foreach ($rows as $row) {
            $category = trim((string)($row['Warengruppe'] ?? ''));
            if ($category === '') {
                continue;
            }
            [$small, $smallKey] = $this->normalizeFileName($row['Bild'] ?? '');
            if ($small !== '') {
                $result[] = [
                    'category' => $category,
                    'file'     => $small,
                    'file_key' => $smallKey,
                    'type'     => 1,
                ];
            }
            [$large, $largeKey] = $this->normalizeFileName($row['Bild_gross'] ?? '');
            if ($large !== '') {
                $result[] = [
                    'category' => $category,
                    'file'     => $large,
                    'file_key' => $largeKey,
                    'type'     => 2,
                ];
            }
        }
        return $result;
    }

    /**
     * @return array<int,array<string,string>>
     */
    private function fetchDocuments(): array
    {
        $rows = $this->mssql->fetchAll(
            'SELECT Artikel, Dateiname FROM Dokument WHERE Artikel IS NOT NULL AND LTRIM(RTRIM(ISNULL(Dateiname, \'\' ))) <> \'\''
        );
        $result = [];
        foreach ($rows as $row) {
            $article = trim((string)($row['Artikel'] ?? ''));
            if ($article === '') {
                continue;
            }
            [$file, $key] = $this->normalizeFileName($row['Dateiname'] ?? '');
            if ($file === '') {
                continue;
            }
            $result[] = [
                'article'  => $article,
                'file'     => $file,
                'file_key' => $key,
            ];
        }
        return $result;
    }

    /**
     * @return array<string,array<int,array<string,string>>>
     */
    private function loadImageRows(): array
    {
        $rows = [];
        $stmt = $this->sqlite->query('SELECT rowid, file_name FROM bilder');
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $file = $row['file_name'] ?? '';
            [$normalized, $key] = $this->normalizeFileName($file);
            if ($normalized === '') {
                continue;
            }
            if (!isset($rows[$key])) {
                $rows[$key] = [];
            }
            $rows[$key][] = [
                'rowid'     => (int)$row['rowid'],
                'file_name' => $normalized,
            ];
        }
        return $rows;
    }

    /**
     * @return array<string,array<int,array<string,string>>>
     */
    private function loadDocumentRows(): array
    {
        $rows = [];
        $stmt = $this->sqlite->query('SELECT rowid, file_name FROM dokumente');
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $file = $row['file_name'] ?? '';
            [$normalized, $key] = $this->normalizeFileName($file);
            if ($normalized === '') {
                continue;
            }
            if (!isset($rows[$key])) {
                $rows[$key] = [];
            }
            $rows[$key][] = [
                'rowid'     => (int)$row['rowid'],
                'file_name' => $normalized,
            ];
        }
        return $rows;
    }

    /**
     * @param mixed $value
     * @return array{0:string,1:string}
     */
    private function normalizeFileName($value): array
    {
        $string = trim((string)$value);
        if ($string === '') {
            return ['', ''];
        }
        $string = str_replace('\\', '/', $string);
        $string = basename($string);
        return [$string, strtolower($string)];
    }
}
