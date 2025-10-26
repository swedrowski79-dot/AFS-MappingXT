<?php

class AFS_Evo_AttributeSync extends AFS_Evo_Base
{
    public function import(): array
    {
        $raw = is_array($this->afs->Attribute) ? $this->afs->Attribute : [];
        $namesRaw = [];
        foreach ($raw as $item) {
            if (is_array($item) && isset($item['Attribname'])) {
                $namesRaw[] = (string)$item['Attribname'];
            } else {
                $namesRaw[] = (string)$item;
            }
        }

        $names = $this->normalizeStrings($namesRaw);
        if ($names === []) {
            $this->afs->Attribute = [];
            return [];
        }

        $this->db->beginTransaction();
        try {
            $ins = $this->db->prepare('INSERT OR IGNORE INTO Attribute (Attribname, "update") VALUES (:name, 1)');
            foreach ($names as $name) {
                $ins->execute([':name' => $name]);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        $map = $this->fetchIdMap('Attribute', 'ID', 'Attribname', $names);
        $enriched = [];
        foreach ($names as $name) {
            if (!isset($map[$name]) || isset($enriched[$name])) {
                continue;
            }
            $enriched[$name] = [
                'Attribname' => $name,
                'ID' => $map[$name],
            ];
        }
        $this->afs->Attribute = array_values($enriched);

        return $map;
    }

    public function attributeIdMapFromAfs(): array
    {
        $map = [];
        $items = is_array($this->afs->Attribute) ? $this->afs->Attribute : [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $name = isset($item['Attribname']) ? trim((string)$item['Attribname']) : '';
            $id   = isset($item['ID']) ? (int)$item['ID'] : 0;
            if ($name === '' || $id <= 0) {
                continue;
            }
            $map[$name] = $id;
        }
        return $map;
    }
}
