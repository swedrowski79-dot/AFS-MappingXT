<?php

class EVO_CategorySync extends EVO_Base
{
    public function import(): array
    {
        $rows = is_array($this->afs->Warengruppe) ? $this->afs->Warengruppe : [];
        $inserted = 0;
        $updated = 0;
        $parentSet = 0;
        $total = 0;

        if (empty($rows)) {
            return ['inserted'=>0,'updated'=>0,'parent_set'=>0,'total'=>0];
        }

        $this->db->beginTransaction();
        try {
            $insertStmt = $this->db->prepare(
                'INSERT INTO category (afsid, afsparent, xtid, name, online, picture, picture_id, picture_big, picture_big_id, description, meta_title, meta_description, "update")
                 VALUES (:afsid, :afsparent, :xtid, :name, :online, :picture, :picture_id, :picture_big, :picture_big_id, :description, :meta_title, :meta_description, 1)'
            );
            $updateStmt = $this->db->prepare(
                'UPDATE category
                 SET afsparent = :afsparent,
                     name = :name,
                     online = :online,
                     picture = :picture,
                     picture_id = :picture_id,
                     picture_big = :picture_big,
                     picture_big_id = :picture_big_id,
                     description = :description,
                     meta_title = :meta_title,
                     meta_description = :meta_description,
                     "update" = 1
                 WHERE afsid = :afsid'
            );

            $bildMap = $this->loadBildIdMap();
            // Batch load all existing categories to avoid N+1 queries
            $existingCategories = $this->loadExistingCategories();

            foreach ($rows as $r) {
                if (!is_array($r)) {
                    continue;
                }
                $afsid = isset($r['AFS_ID']) ? (int)$r['AFS_ID'] : null;
                if ($afsid === null) {
                    continue;
                }
                $afsparent = isset($r['Parent']) && $r['Parent'] !== '' ? (int)$r['Parent'] : null;
                $name = isset($r['Bezeichnung']) ? trim((string)$r['Bezeichnung']) : '';
                $online = !empty($r['Online']) ? 1 : 0;
                $picture = isset($r['Bild']) ? (string)$r['Bild'] : null;
                $pictureBig = isset($r['Bild_gross']) ? (string)$r['Bild_gross'] : null;
                $pictureId = $this->resolveBildId($bildMap, $picture);
                $pictureBigId = $this->resolveBildId($bildMap, $pictureBig);
                $description = isset($r['Beschreibung']) ? (string)$r['Beschreibung'] : null;
                $metaTitle = $this->nullIfEmpty($r['Meta_Title'] ?? null);
                $metaDescription = $this->nullIfEmpty($r['Meta_Description'] ?? null);

                $current = $existingCategories[$afsid] ?? false;

                $params = [
                    ':afsid' => $afsid,
                    ':afsparent' => $afsparent,
                    ':xtid' => null,
                    ':name' => $name,
                    ':online' => $online,
                    ':picture' => $picture,
                    ':picture_id' => $pictureId,
                    ':picture_big' => $pictureBig,
                    ':picture_big_id' => $pictureBigId,
                    ':description' => $description,
                    ':meta_title' => $metaTitle,
                    ':meta_description' => $metaDescription,
                ];

                if ($current === false) {
                    $insertStmt->execute($params);
                    $inserted++;
                } else {
                    if ($this->categoryNeedsUpdate($current, $params)) {
                        $updateStmt->execute($params);
                        $updated++;
                    }
                }
                $total++;
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        $map = $this->loadCategoryIdMap();

        $this->db->beginTransaction();
        try {
            $updParent = $this->db->prepare(
                'UPDATE category SET Parent = :parentId, "update" = 1
                 WHERE afsid = :afsid AND (
                    (Parent IS NULL AND :parentId IS NOT NULL)
                    OR (Parent IS NOT NULL AND Parent <> :parentId)
                 )'
            );
            foreach ($rows as $r) {
                if (!is_array($r)) {
                    continue;
                }
                $afsid = isset($r['AFS_ID']) ? (int)$r['AFS_ID'] : null;
                $afsparent = isset($r['Parent']) && $r['Parent'] !== '' ? (int)$r['Parent'] : null;
                if ($afsid === null || $afsparent === null) {
                    continue;
                }
                if (!isset($map[$afsparent]) || !isset($map[$afsid])) {
                    continue;
                }
                $parentId = (int)$map[$afsparent];
                $updParent->execute([':parentId' => $parentId, ':afsid' => $afsid]);
                if ($updParent->rowCount() > 0) {
                    $parentSet++;
                }
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return ['inserted'=>$inserted,'updated'=>$updated,'parent_set'=>$parentSet,'total'=>$total];
    }

    public function loadCategoryIdMap(): array
    {
        $out = [];
        $sql = 'SELECT id, afsid FROM category';
        foreach ($this->db->query($sql, PDO::FETCH_ASSOC) as $row) {
            $out[(int)$row['afsid']] = (int)$row['id'];
        }
        return $out;
    }

    /**
     * Batch load all existing categories to avoid N+1 queries
     * @return array<int,array<string,mixed>>
     */
    private function loadExistingCategories(): array
    {
        $out = [];
        $sql = 'SELECT afsid, afsparent, name, online, picture, picture_id, picture_big, picture_big_id, description, meta_title, meta_description FROM category';
        foreach ($this->db->query($sql, PDO::FETCH_ASSOC) as $row) {
            $afsid = (int)$row['afsid'];
            $out[$afsid] = $row;
        }
        return $out;
    }

    private function loadBildIdMap(): array
    {
        $out = [];
        $sql = 'SELECT ID, Bildname FROM Bilder';
        foreach ($this->db->query($sql, PDO::FETCH_ASSOC) as $row) {
            $name = (string)$row['Bildname'];
            if ($name === '') {
                continue;
            }
            $base = basename($name);
            $id   = (int)$row['ID'];
            $out[$name] = $id;
            if ($base !== $name) {
                $out[$base] = $id;
            }
            $out[strtolower($base)] = $id;
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $current
     * @param array<string,mixed> $params
     */
    private function categoryNeedsUpdate(array $current, array $params): bool
    {
        $map = [
            'afsparent' => ':afsparent',
            'name' => ':name',
            'online' => ':online',
            'picture' => ':picture',
            'picture_id' => ':picture_id',
            'picture_big' => ':picture_big',
            'picture_big_id' => ':picture_big_id',
            'description' => ':description',
            'meta_title' => ':meta_title',
            'meta_description' => ':meta_description',
        ];

        foreach ($map as $column => $paramKey) {
            $new = $params[$paramKey] ?? null;
            $existing = $current[$column] ?? null;

            switch ($column) {
                case 'online':
                    if ((int)$existing !== (int)$new) {
                        return true;
                    }
                    break;
                case 'afsparent':
                case 'picture_id':
                case 'picture_big_id':
                    if ($existing === null && $new === null) {
                        break;
                    }
                    if ((int)$existing !== (int)$new) {
                        return true;
                    }
                    break;
                default:
                    $existingStr = $existing === null ? null : (string)$existing;
                    $newStr = $new === null ? null : (string)$new;
                    if ($existingStr !== $newStr) {
                        return true;
                    }
                    break;
            }
        }

        return false;
    }

    private function resolveBildId(array $map, ?string $name): ?int
    {
        if ($name === null) {
            return null;
        }
        $trimmed = trim($name);
        if ($trimmed === '') {
            return null;
        }
        $base = basename($trimmed);
        if ($base === '') {
            return null;
        }

        $candidates = [
            $trimmed,
            $base,
            strtolower($base),
        ];

        foreach ($candidates as $candidate) {
            if (isset($map[$candidate])) {
                return (int)$map[$candidate];
            }
        }

        return null;
    }
}
