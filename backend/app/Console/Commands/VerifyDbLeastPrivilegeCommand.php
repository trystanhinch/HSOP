<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PDO;
use Throwable;

/**
 * Verify MySQL least-privilege grants for runtime and/or migrate identities.
 * Trystan-required two-user model: serviceop_app (no DDL) + serviceop_migrate (DDL, no DROP).
 */
class VerifyDbLeastPrivilegeCommand extends Command
{
    protected $signature = 'db:verify-least-privilege
                            {--identity=current : current|runtime|migrate — which grant profile to assert}
                            {--username= : Optional MySQL user to inspect (defaults to current connection)}
                            {--password= : Password for --username (required when username differs from config)}
                            {--host= : Optional host override}
                            {--database= : Optional database override}
                            {--port= : Optional port override}';

    protected $description = 'Inspect MySQL SHOW GRANTS and FAIL if the selected identity (runtime/migrate/current) is out of bounds';

    /** Privileges that must NEVER appear on either least-privilege identity. */
    private const FORBIDDEN_BOTH = [
        'DROP',
        'GRANT OPTION',
        'CREATE USER',
        'SUPER',
        'FILE',
        'PROCESS',
        'SHUTDOWN',
        'RELOAD',
        'CREATE TABLESPACE',
        'CREATE ROLE',
        'DROP ROLE',
        'EVENT',
        'PROXY',
    ];

    /** DDL privileges that runtime must NOT have, but migrate must have. */
    private const RUNTIME_FORBIDDEN_DDL = [
        'CREATE',
        'ALTER',
        'INDEX',
        'REFERENCES',
        'TRIGGER',
    ];

    /** DDL privileges migrate is expected to hold. */
    private const MIGRATE_REQUIRED_DDL = [
        'CREATE',
        'ALTER',
        'INDEX',
        'REFERENCES',
        'TRIGGER',
    ];

    public function handle(): int
    {
        $identity = strtolower((string) $this->option('identity') ?: 'current');
        if (! in_array($identity, ['current', 'runtime', 'migrate'], true)) {
            $this->error('Invalid --identity. Use current, runtime, or migrate.');

            return self::FAILURE;
        }

        if (DB::connection()->getDriverName() !== 'mysql' && ! $this->option('username')) {
            $this->error('db:verify-least-privilege requires MySQL (current: '.DB::connection()->getDriverName().').');

            return self::FAILURE;
        }

        try {
            [$grantStrings, $label] = $this->fetchGrants();
        } catch (Throwable $e) {
            $this->error('Could not read grants: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Identity profile: '.$identity);
        $this->info('Inspecting: '.$label);
        $this->newLine();
        $this->line('Raw SHOW GRANTS:');
        foreach ($grantStrings as $g) {
            $this->line('  '.$g);
        }
        $this->newLine();

        $joined = strtoupper(implode("\n", $grantStrings));
        $hasAllPrivileges = str_contains($joined, 'ALL PRIVILEGES');
        $results = [];
        $failed = false;

        if ($hasAllPrivileges) {
            $failed = true;
            $results[] = ['ALL PRIVILEGES', 'FAIL', 'User has ALL PRIVILEGES (typically root / admin).'];
        } else {
            $results[] = ['ALL PRIVILEGES', 'PASS', 'Not granted.'];
        }

        foreach (self::FORBIDDEN_BOTH as $priv) {
            $present = $hasAllPrivileges || $this->grantMentionsPrivilege($joined, $priv);
            if ($present) {
                $failed = true;
                $results[] = [$priv, 'FAIL', 'Must never be granted on serviceop_app or serviceop_migrate.'];
            } else {
                $note = $priv === 'DROP'
                    ? 'Absent — TRUNCATE also requires DROP, so TRUNCATE is blocked.'
                    : 'Absent.';
                $results[] = [$priv, 'PASS', $note];
            }
        }

        $results[] = [
            'TRUNCATE (via DROP)',
            $hasAllPrivileges || $this->grantMentionsPrivilege($joined, 'DROP') ? 'FAIL' : 'PASS',
            'MySQL has no separate TRUNCATE privilege; denying DROP denies TRUNCATE.',
        ];
        if ($hasAllPrivileges || $this->grantMentionsPrivilege($joined, 'DROP')) {
            $failed = true;
        }

        if ($identity === 'runtime') {
            foreach (self::RUNTIME_FORBIDDEN_DDL as $priv) {
                // CREATE USER already covered; check CREATE table privilege carefully
                $present = $hasAllPrivileges || $this->grantMentionsPrivilege($joined, $priv);
                if ($present) {
                    $failed = true;
                    $results[] = [$priv.' (runtime)', 'FAIL', 'Runtime identity must not have DDL.'];
                } else {
                    $results[] = [$priv.' (runtime)', 'PASS', 'Absent on runtime identity.'];
                }
            }
        }

        if ($identity === 'migrate') {
            foreach (self::MIGRATE_REQUIRED_DDL as $priv) {
                $present = $hasAllPrivileges || $this->grantMentionsPrivilege($joined, $priv);
                if (! $present) {
                    $failed = true;
                    $results[] = [$priv.' (migrate)', 'FAIL', 'Migrate identity is missing required DDL privilege.'];
                } else {
                    $results[] = [$priv.' (migrate)', 'PASS', 'Present as required for php artisan migrate / triggers.'];
                }
            }
        }

        $this->table(['Privilege / check', 'Result', 'Notes'], $results);

        if ($failed) {
            $this->error('LEAST-PRIVILEGE CHECK: FAIL — identity ['.$identity.'] is out of bounds.');
            $this->comment('See docs/deployment/database_least_privilege_migration.md (two-user model).');

            return self::FAILURE;
        }

        $this->info('LEAST-PRIVILEGE CHECK: PASS — identity ['.$identity.'] is within bounds.');

        return self::SUCCESS;
    }

    /**
     * @return array{0: list<string>, 1: string}
     */
    private function fetchGrants(): array
    {
        $username = $this->option('username');
        if (! $username) {
            $rows = DB::select('SHOW GRANTS FOR CURRENT_USER()');
            $grantStrings = [];
            foreach ($rows as $row) {
                $arr = (array) $row;
                $grantStrings[] = (string) reset($arr);
            }
            $label = config('database.connections.'.config('database.default').'.username')
                .'@'.config('database.connections.'.config('database.default').'.host')
                .' / DB='.config('database.connections.'.config('database.default').'.database')
                .' (CURRENT_USER)';

            return [$grantStrings, $label];
        }

        $password = (string) ($this->option('password') ?? '');
        if ($password === '' && ! $this->option('password')) {
            // Allow empty only if explicitly passed; otherwise fail loud
            $this->warn('Connecting with --username but empty --password.');
        }

        $default = config('database.connections.'.config('database.default'));
        $host = (string) ($this->option('host') ?: ($default['host'] ?? '127.0.0.1'));
        $port = (int) ($this->option('port') ?: ($default['port'] ?? 3306));
        $database = (string) ($this->option('database') ?: ($default['database'] ?? ''));

        $dsn = "mysql:host={$host};port={$port}";
        if ($database !== '') {
            $dsn .= ";dbname={$database}";
        }

        $pdo = new PDO($dsn, (string) $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $stmt = $pdo->query('SHOW GRANTS FOR CURRENT_USER()');
        $grantStrings = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $grantStrings[] = (string) reset($row);
        }

        $label = "{$username}@{$host} / DB={$database} (explicit --username)";

        return [$grantStrings, $label];
    }

    private function grantMentionsPrivilege(string $joinedUpperGrants, string $privilege): bool
    {
        $priv = strtoupper($privilege);
        if ($priv === 'GRANT OPTION') {
            return str_contains($joinedUpperGrants, 'WITH GRANT OPTION')
                || str_contains($joinedUpperGrants, 'GRANT OPTION');
        }

        // CREATE must not match CREATE TEMPORARY TABLES / CREATE USER when checking runtime DDL.
        if ($priv === 'CREATE') {
            // Match CREATE that is not CREATE TEMPORARY / CREATE USER / CREATE ROLE / CREATE TABLESPACE
            if (preg_match('/\bCREATE\s+TEMPORARY\b/', $joinedUpperGrants)) {
                // still may also have bare CREATE — strip temporary phrases for the check
                $scrubbed = preg_replace('/\bCREATE\s+TEMPORARY\s+TABLES?\b/', '', $joinedUpperGrants) ?? $joinedUpperGrants;
            } else {
                $scrubbed = $joinedUpperGrants;
            }
            $scrubbed = preg_replace('/\bCREATE\s+(USER|ROLE|TABLESPACE)\b/', '', $scrubbed) ?? $scrubbed;

            return (bool) preg_match('/\bCREATE\b/', $scrubbed);
        }

        return (bool) preg_match('/\b'.preg_quote($priv, '/').'\b/', $joinedUpperGrants);
    }
}
