<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * db:verify-least-privilege — two-user identity profiles.
 */
class DbLeastPrivilegeVerifyTest extends TestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();
        $app['config']->set('database.default', 'mysql');
        $app['config']->set('database.connections.mysql.database', 'hsop_job_command');
        $app['config']->set('payment.provider', 'mock');
        $app['config']->set('ai.provider', 'mock');

        return $app;
    }

    public function test_verify_reports_fail_against_current_root_grants(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL only');
        }

        $exit = Artisan::call('db:verify-least-privilege', ['--identity' => 'current']);
        $output = Artisan::output();

        $this->assertSame(1, $exit, $output);
        $this->assertStringContainsString('FAIL', $output);
        $this->assertStringContainsString('DROP', $output);
        $this->assertStringContainsString('GRANT OPTION', $output);
        $this->assertStringContainsString('LEAST-PRIVILEGE CHECK: FAIL', $output);
    }

    public function test_runtime_identity_fails_on_root_including_ddl(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL only');
        }

        $exit = Artisan::call('db:verify-least-privilege', ['--identity' => 'runtime']);
        $output = Artisan::output();

        $this->assertSame(1, $exit, $output);
        $this->assertStringContainsString('identity [runtime]', $output);
        $this->assertStringContainsString('FAIL', $output);
    }

    public function test_migrate_identity_fails_on_root(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL only');
        }

        $exit = Artisan::call('db:verify-least-privilege', ['--identity' => 'migrate']);
        $output = Artisan::output();

        $this->assertSame(1, $exit, $output);
        $this->assertStringContainsString('identity [migrate]', $output);
        $this->assertStringContainsString('FAIL', $output);
    }

    public function test_invalid_identity_rejected(): void
    {
        $exit = Artisan::call('db:verify-least-privilege', ['--identity' => 'nope']);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Invalid --identity', Artisan::output());
    }

    public function test_scratch_two_user_identities_when_admin_can_create_users(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL only');
        }

        $suffix = substr(uniqid(), -6);
        $appUser = 'sop_app_'.$suffix;
        $migUser = 'sop_mig_'.$suffix;
        $db = config('database.connections.mysql.database');
        $pass = 'ScratchPass_'.$suffix.'!';

        try {
            DB::statement("CREATE USER '{$appUser}'@'localhost' IDENTIFIED BY '{$pass}'");
        } catch (\Throwable $e) {
            $this->markTestSkipped('Cannot CREATE USER in this sandbox: '.$e->getMessage());
        }

        try {
            DB::statement("REVOKE ALL PRIVILEGES, GRANT OPTION FROM '{$appUser}'@'localhost'");
            DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE, CREATE TEMPORARY TABLES, LOCK TABLES, EXECUTE, SHOW VIEW ON `{$db}`.* TO '{$appUser}'@'localhost'");

            DB::statement("CREATE USER '{$migUser}'@'localhost' IDENTIFIED BY '{$pass}'");
            DB::statement("REVOKE ALL PRIVILEGES, GRANT OPTION FROM '{$migUser}'@'localhost'");
            DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES, TRIGGER, CREATE TEMPORARY TABLES, LOCK TABLES, EXECUTE, SHOW VIEW ON `{$db}`.* TO '{$migUser}'@'localhost'");
            DB::statement('FLUSH PRIVILEGES');

            $host = config('database.connections.mysql.host', '127.0.0.1');
            // SHOW GRANTS works over TCP; user was created for localhost — use localhost host for PDO
            $verifyHost = '127.0.0.1';

            // Runtime verify PASS
            $exitApp = Artisan::call('db:verify-least-privilege', [
                '--identity' => 'runtime',
                '--username' => $appUser,
                '--password' => $pass,
                '--host' => $verifyHost,
                '--database' => $db,
            ]);
            $outApp = Artisan::output();
            // localhost vs 127.0.0.1 auth may fail — retry via socket user host
            if ($exitApp !== 0 && str_contains($outApp, 'Could not read grants')) {
                // Recreate grants for 127.0.0.1
                DB::statement("CREATE USER IF NOT EXISTS '{$appUser}'@'127.0.0.1' IDENTIFIED BY '{$pass}'");
                DB::statement("REVOKE ALL PRIVILEGES, GRANT OPTION FROM '{$appUser}'@'127.0.0.1'");
                DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE, CREATE TEMPORARY TABLES, LOCK TABLES, EXECUTE, SHOW VIEW ON `{$db}`.* TO '{$appUser}'@'127.0.0.1'");
                DB::statement("CREATE USER IF NOT EXISTS '{$migUser}'@'127.0.0.1' IDENTIFIED BY '{$pass}'");
                DB::statement("REVOKE ALL PRIVILEGES, GRANT OPTION FROM '{$migUser}'@'127.0.0.1'");
                DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES, TRIGGER, CREATE TEMPORARY TABLES, LOCK TABLES, EXECUTE, SHOW VIEW ON `{$db}`.* TO '{$migUser}'@'127.0.0.1'");
                DB::statement('FLUSH PRIVILEGES');
                $exitApp = Artisan::call('db:verify-least-privilege', [
                    '--identity' => 'runtime',
                    '--username' => $appUser,
                    '--password' => $pass,
                    '--host' => $verifyHost,
                    '--database' => $db,
                ]);
                $outApp = Artisan::output();
            }

            $this->assertSame(0, $exitApp, $outApp);

            $exitMig = Artisan::call('db:verify-least-privilege', [
                '--identity' => 'migrate',
                '--username' => $migUser,
                '--password' => $pass,
                '--host' => $verifyHost,
                '--database' => $db,
            ]);
            $outMig = Artisan::output();
            $this->assertSame(0, $exitMig, $outMig);

            // Probes via PDO as each user
            $pdoApp = new \PDO(
                "mysql:host={$verifyHost};dbname={$db}",
                $appUser,
                $pass,
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );
            $denied = false;
            try {
                $pdoApp->exec('CREATE TABLE `_priv_probe_app` (id INT)');
            } catch (\Throwable) {
                $denied = true;
            }
            $this->assertTrue($denied, 'Runtime user must not CREATE TABLE');

            $pdoMig = new \PDO(
                "mysql:host={$verifyHost};dbname={$db}",
                $migUser,
                $pass,
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );
            $pdoMig->exec('CREATE TABLE `_priv_probe_mig` (id INT PRIMARY KEY)');
            $pdoMig->exec('CREATE TRIGGER `_priv_probe_trg` BEFORE DELETE ON `_priv_probe_mig` FOR EACH ROW SIGNAL SQLSTATE "45000" SET MESSAGE_TEXT = "blocked"');

            $truncDenied = false;
            try {
                $pdoMig->exec('TRUNCATE TABLE `_priv_probe_mig`');
            } catch (\Throwable) {
                $truncDenied = true;
            }
            $this->assertTrue($truncDenied, 'Migrate user must not TRUNCATE');

            $dropDenied = false;
            try {
                $pdoMig->exec('DROP TABLE `_priv_probe_mig`');
            } catch (\Throwable) {
                $dropDenied = true;
            }
            $this->assertTrue($dropDenied, 'Migrate user must not DROP TABLE');

            // Cleanup probe table as root
            DB::statement('DROP TRIGGER IF EXISTS `_priv_probe_trg`');
            DB::statement('DROP TABLE IF EXISTS `_priv_probe_mig`');
            DB::statement('DROP TABLE IF EXISTS `_priv_probe_app`');
        } finally {
            foreach (['localhost', '127.0.0.1'] as $h) {
                try {
                    DB::statement("DROP USER IF EXISTS '{$appUser}'@'{$h}'");
                } catch (\Throwable) {
                }
                try {
                    DB::statement("DROP USER IF EXISTS '{$migUser}'@'{$h}'");
                } catch (\Throwable) {
                }
            }
            try {
                DB::statement('FLUSH PRIVILEGES');
            } catch (\Throwable) {
            }
        }
    }
}
