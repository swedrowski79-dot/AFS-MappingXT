# Agent: RAM-Bulk-Refactor (PHP + SQLite)
## Model
gpt-5-codex

## Goals
- Alle Daten **zuerst im RAM** normalisieren, dann **stapelweise** via TEMP-Staging in SQLite mergen.
- **Idempotenz**: Zweiter Lauf ergibt keine Nettodiffs.
- **Zuweisungen säubern**: In Pivot-Tabellen dürfen **nur aktuelle** Paare stehen.

## Scope
- Betroffene Pfade: `src/Sync/**`, `indexcli.php`
- Tabellen: `artikel`, `bilder`, `attrib_artikel`, `artikel_bilder`

## Tactics
- Eine Transaktion: `BEGIN IMMEDIATE;`
- PRAGMAs: `journal_mode=WAL`, `synchronous=NORMAL`, `temp_store=MEMORY`, `busy_timeout=5000`
- Batchgröße: `floor(999 / spalten_anzahl)`
- Upsert: `INSERT ... SELECT ... ON CONFLICT(...) DO UPDATE`
- Cleanup:
  - `DELETE FROM artikel_bilder WHERE artikel_id IN (SELECT DISTINCT artikel_id FROM _stg_artikel_bilder)
    AND (artikel_id, bild_id) NOT IN (SELECT artikel_id, bild_id FROM _stg_artikel_bilder);`
  - Analog für `attrib_artikel`

## Do
- Deterministische IDs (z. B. `sha1('artikel|'.$nummer)`), keine SELECT-Lookups.
- Logging: Laufzeit & `memory_get_peak_usage(true)`.
- Feature-Flag: `MODE_RAM_BULK`.

## Don't
- Keine Mega-Query, keine einzelnen Commits pro Datensatz.
- Keine ungescopten Deletes außerhalb der betroffenen Parents.
