<?php

namespace App\Http\Controllers;

use App\Services\LeadCustomerResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;

class DeployController extends Controller
{
    public function seedSettings(string $secret): JsonResponse
    {
        $this->authorizeDeploy($secret);
        Artisan::call('db:seed', ['--class' => 'SettingsSeeder', '--force' => true]);

        return $this->ok('db:seed --class=SettingsSeeder --force');
    }

    public function release(string $secret): JsonResponse
    {
        $this->authorizeDeploy($secret);

        $steps = [];
        foreach ([
            'config:clear' => 'config:clear',
            'cache:clear' => 'cache:clear',
            'config:cache' => 'config:cache',
            'migrate --force' => 'migrate',
        ] as $label => $command) {
            $args = $command === 'migrate' ? ['--force' => true] : [];
            Artisan::call($command, $args);
            $steps[$label] = trim(Artisan::output());
        }

        return response()->json([
            'ok' => true,
            'message' => 'Production release completed.',
            'steps' => $steps,
        ]);
    }

    public function migrate(string $secret): JsonResponse
    {
        $this->authorizeDeploy($secret);
        Artisan::call('migrate', ['--force' => true]);

        return $this->ok('migrate --force');
    }

    public function seed(string $secret): JsonResponse
    {
        $this->authorizeDeploy($secret);
        Artisan::call('db:seed', ['--force' => true]);

        return $this->ok('db:seed --force');
    }

    public function setup(string $secret): JsonResponse
    {
        $this->authorizeDeploy($secret);

        Artisan::call('migrate', ['--force' => true]);
        $migrateOut = Artisan::output();

        Artisan::call('db:seed', ['--force' => true]);
        $seedOut = Artisan::output();

        Artisan::call('storage:link');
        $linkOut = Artisan::output();

        Artisan::call('hsop:repair-data');
        $repairOut = Artisan::output();

        return response()->json([
            'ok' => true,
            'message' => 'Migrate, seed, storage:link, and data repair completed.',
            'output' => trim($migrateOut."\n\n".$seedOut."\n\n".$linkOut."\n\n".$repairOut),
        ]);
    }

    public function repair(string $secret): JsonResponse
    {
        $this->authorizeDeploy($secret);

        $resolver = app(LeadCustomerResolver::class);
        $customers = $resolver->repairJobCustomers();
        $contractors = $resolver->repairContractorIds();

        return response()->json([
            'ok' => true,
            'repaired_customers' => $customers,
            'fixed_contractors' => $contractors,
        ]);
    }

    private function authorizeDeploy(string $secret): void
    {
        $expected = env('DEPLOY_SECRET');
        if (! $expected || ! hash_equals($expected, $secret)) {
            abort(403, 'Invalid deploy secret');
        }
    }

    private function ok(string $command): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'command' => $command,
            'output' => trim(Artisan::output()),
        ]);
    }
}
