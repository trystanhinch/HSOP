<?php

namespace App\Services\Ledger;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Milestone 6A Phase 6 — MySQL BEFORE UPDATE/DELETE triggers for append-only ledgers.
 *
 * Used when the app and migrations share one DB user (confirmed: no migration-only
 * privilege separation in config/database.php). Privilege-revocation is not viable
 * without a separate migration user; triggers enforce SEC-09 style tamper resistance
 * even when Eloquent booted() guards are bypassed via DB::table() / raw SQL.
 */
class LedgerAppendOnlyTriggers
{
    /** @var list<string> */
    public const TABLES = [
        'review_gateway_access_logs',
        'learning_gateway_access_logs',
        'ai_evaluation_runs',
        'ai_evaluation_findings',
    ];

    public static function isMysql(): bool
    {
        return DB::connection()->getDriverName() === 'mysql';
    }

    public static function updateTriggerName(string $table): string
    {
        return $table.'_bu_append_only';
    }

    public static function deleteTriggerName(string $table): string
    {
        return $table.'_bd_append_only';
    }

    /**
     * Drop then create all ledger append-only triggers (idempotent apply).
     */
    public static function applyAll(): void
    {
        self::assertMysql();
        foreach (self::TABLES as $table) {
            self::dropForTable($table);
            self::createForTable($table);
        }
    }

    public static function dropAll(): void
    {
        self::assertMysql();
        foreach (self::TABLES as $table) {
            self::dropForTable($table);
        }
    }

    public static function dropForTable(string $table): void
    {
        self::assertKnownTable($table);
        DB::unprepared('DROP TRIGGER IF EXISTS `'.self::updateTriggerName($table).'`');
        DB::unprepared('DROP TRIGGER IF EXISTS `'.self::deleteTriggerName($table).'`');
    }

    public static function createForTable(string $table): void
    {
        self::assertKnownTable($table);
        $bu = self::updateTriggerName($table);
        $bd = self::deleteTriggerName($table);
        $updateMsg = str_replace("'", "''", "{$table} is append-only: updates are not permitted");
        $deleteMsg = str_replace("'", "''", "{$table} is append-only: deletes are not permitted");

        DB::unprepared(<<<SQL
CREATE TRIGGER `{$bu}`
BEFORE UPDATE ON `{$table}`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$updateMsg}';
END
SQL);

        DB::unprepared(<<<SQL
CREATE TRIGGER `{$bd}`
BEFORE DELETE ON `{$table}`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$deleteMsg}';
END
SQL);
    }

    /**
     * @return array{ok: bool, tables: array<string, array{update: bool, delete: bool, update_name: string, delete_name: string}>}
     */
    public static function verify(): array
    {
        self::assertMysql();
        $existing = self::existingTriggerNames();
        $tables = [];
        $ok = true;
        foreach (self::TABLES as $table) {
            $bu = self::updateTriggerName($table);
            $bd = self::deleteTriggerName($table);
            $hasUpdate = in_array($bu, $existing, true);
            $hasDelete = in_array($bd, $existing, true);
            if (! $hasUpdate || ! $hasDelete) {
                $ok = false;
            }
            $tables[$table] = [
                'update' => $hasUpdate,
                'delete' => $hasDelete,
                'update_name' => $bu,
                'delete_name' => $bd,
            ];
        }

        return ['ok' => $ok, 'tables' => $tables];
    }

    /**
     * @return list<string>
     */
    private static function existingTriggerNames(): array
    {
        $db = DB::getDatabaseName();
        $rows = DB::select(
            'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ?',
            [$db]
        );

        return array_values(array_map(fn ($r) => (string) $r->TRIGGER_NAME, $rows));
    }

    private static function assertMysql(): void
    {
        if (! self::isMysql()) {
            throw new RuntimeException('Ledger append-only triggers require a MySQL connection.');
        }
    }

    private static function assertKnownTable(string $table): void
    {
        if (! in_array($table, self::TABLES, true)) {
            throw new RuntimeException("Unknown ledger table: {$table}");
        }
    }
}
