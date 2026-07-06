<?php

namespace App\Http\Controllers;

use App\Models\JobUpdatePhoto;
use App\Services\LeadCustomerResolver;
use App\Services\UploadStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

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
            'storage:link' => 'storage:link',
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

    public function storageLink(string $secret): JsonResponse
    {
        $this->authorizeDeploy($secret);
        Artisan::call('storage:link');

        return $this->ok('storage:link');
    }

    public function storageDiagnostic(string $secret): JsonResponse
    {
        $this->authorizeDeploy($secret);

        $uploads = app(UploadStorage::class);
        $disk = $uploads->diskName();
        $s3Configured = (bool) config('filesystems.disks.s3.bucket')
            && (bool) config('filesystems.disks.s3.key');

        $spacesTest = null;
        if ($s3Configured) {
            try {
                $testPath = 'test/connection-test-'.time().'.txt';
                Storage::disk('s3')->put($testPath, 'ServiceOP Spaces test '.now());
                try {
                    Storage::disk('s3')->setVisibility($testPath, 'public');
                } catch (\Throwable) {
                    //
                }
                $uploads = app(UploadStorage::class);
                $spacesTest = [
                    'ok' => true,
                    'path' => $testPath,
                    'url' => $uploads->publicUrl($testPath),
                    'exists' => Storage::disk('s3')->exists($testPath),
                ];
            } catch (\Throwable $e) {
                $spacesTest = ['ok' => false, 'error' => $e->getMessage()];
            }
        }

        $photos = JobUpdatePhoto::latest()->take(10)->get(['id', 'file_name', 'file_url'])->map(function ($photo) use ($disk) {
            $relative = '';
            if (preg_match('#digitaloceanspaces\.com/(.+)$#', $photo->file_url ?? '', $m)) {
                $relative = $m[1];
            } elseif (preg_match('#/api/files/(.+)$#', $photo->file_url ?? '', $m)) {
                $relative = $m[1];
            } elseif (str_starts_with($photo->file_url ?? '', '/storage/')) {
                $relative = substr($photo->file_url, strlen('/storage/'));
            }
            $relative = ltrim($relative, '/');
            $exists = false;
            if ($relative && str_contains($photo->file_url ?? '', 'digitaloceanspaces.com')) {
                try {
                    $exists = Storage::disk('s3')->exists($relative);
                } catch (\Throwable) {
                    $exists = false;
                }
            } elseif ($relative && $disk !== 's3') {
                $exists = Storage::disk('public')->exists($relative);
            }

            return [
                'id' => $photo->id,
                'file_name' => $photo->file_name,
                'file_url' => $photo->file_url,
                'storage_path' => $relative,
                'exists_on_disk' => $exists,
            ];
        });

        return response()->json([
            'ok' => true,
            'filesystem_default' => config('filesystems.default'),
            'uploads_disk_env' => env('UPLOADS_DISK'),
            'active_upload_disk' => $disk,
            'public_root' => config('filesystems.disks.public.root'),
            's3_configured' => $s3Configured,
            's3_bucket' => config('filesystems.disks.s3.bucket'),
            's3_endpoint_set' => (bool) config('filesystems.disks.s3.endpoint'),
            's3_url' => config('filesystems.disks.s3.url'),
            'spaces_connection_test' => $spacesTest,
            'latest_photos' => $photos,
            'recommendation' => $disk === 's3' && ($spacesTest['ok'] ?? false)
                ? 'Spaces active — new uploads are permanent.'
                : 'Configure DigitalOcean Spaces (FILESYSTEM_DISK=s3 + AWS_* env vars). Local files are wiped on redeploy.',
        ]);
    }

    public function fixPhotoUrls(string $secret): JsonResponse
    {
        $this->authorizeDeploy($secret);

        $updated = 0;

        foreach (\App\Models\JobUpdatePhoto::all() as $photo) {
            $new = UploadStorage::normalizeStoredUrl($photo->file_url);
            if ($new !== $photo->file_url) {
                $photo->update(['file_url' => $new]);
                $updated++;
            }
        }

        foreach (\App\Models\RevisionRequestPhoto::all() as $photo) {
            $new = UploadStorage::normalizeStoredUrl($photo->file_url);
            if ($new !== $photo->file_url) {
                $photo->update(['file_url' => $new]);
                $updated++;
            }
        }

        foreach (\App\Models\ContractorDocument::all() as $doc) {
            $new = UploadStorage::normalizeStoredUrl($doc->file_url);
            if ($new !== $doc->file_url) {
                $doc->update(['file_url' => $new]);
                $updated++;
            }
        }

        foreach (\App\Models\LeadPhoto::all() as $photo) {
            $new = UploadStorage::normalizeStoredUrl($photo->file_url);
            if ($new !== $photo->file_url) {
                $photo->update(['file_url' => $new]);
                $updated++;
            }
        }

        foreach (\App\Models\Contractor::all() as $contractor) {
            $changes = [];
            foreach (['wcb_file_url', 'insurance_file_url'] as $col) {
                $new = UploadStorage::normalizeStoredUrl($contractor->{$col});
                if ($new !== $contractor->{$col}) {
                    $changes[$col] = $new;
                }
            }
            if ($changes) {
                $contractor->update($changes);
                $updated += count($changes);
            }
        }

        return response()->json([
            'ok' => true,
            'message' => 'Photo URLs normalized to /api/files route.',
            'updated' => $updated,
        ]);
    }

    public function debugFilePath(string $secret, string $path): JsonResponse
    {
        $this->authorizeDeploy($secret);

        $uploads = app(UploadStorage::class);
        $content = null;
        $error = null;
        try {
            $content = Storage::disk('s3')->get($path);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        return response()->json([
            'received_path' => $path,
            'upload_disk' => $uploads->diskName(),
            'content_bytes' => $content !== null ? strlen($content) : null,
            'error' => $error,
        ]);
    }

    public function testSpacesUpload(string $secret): JsonResponse
    {
        $this->authorizeDeploy($secret);

        try {
            $uploads = app(UploadStorage::class);
            $path = 'test/upload-'.time().'.png';
            $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
            $putOk = false;
            $putError = null;
            try {
                $client = Storage::disk('s3')->getClient();
                $client->putObject([
                    'Bucket' => config('filesystems.disks.s3.bucket'),
                    'Key' => $path,
                    'Body' => $png,
                    'ACL' => 'public-read',
                ]);
                $putOk = true;
            } catch (\Throwable $e) {
                $putError = $e->getMessage();
                try {
                    $putOk = (bool) Storage::disk('s3')->put($path, $png);
                } catch (\Throwable $e2) {
                    $putError = $putError.' | fallback: '.$e2->getMessage();
                }
            }
            if ($putError || ! $putOk) {
                return response()->json([
                    'ok' => false,
                    'error' => $putError ?: 'S3 put returned false',
                    'put_ok' => $putOk,
                    'path' => $path,
                    'bucket' => config('filesystems.disks.s3.bucket'),
                    'region' => config('filesystems.disks.s3.region'),
                    'endpoint' => config('filesystems.disks.s3.endpoint'),
                    'use_path_style' => config('filesystems.disks.s3.use_path_style_endpoint'),
                ], 500);
            }
            $url = $uploads->publicUrl($path);
            $getError = null;
            $bytes = null;
            try {
                $content = Storage::disk('s3')->get($path);
                $bytes = $content !== null ? strlen($content) : null;
            } catch (\Throwable $e) {
                $getError = $e->getMessage();
            }

            $internalStatus = null;
            $internalBytes = null;
            try {
                $internal = app()->handle(
                    \Illuminate\Http\Request::create('/api/files/'.implode('%2F', array_map('rawurlencode', explode('/', $path))), 'GET')
                );
                $internalStatus = $internal->getStatusCode();
                $internalBytes = strlen($internal->getContent());
            } catch (\Throwable $e) {
                $internalStatus = 'error: '.$e->getMessage();
            }

            $httpStatus = null;
            try {
                $httpStatus = \Illuminate\Support\Facades\Http::timeout(10)->get($url)->status();
            } catch (\Throwable $e) {
                $httpStatus = 'fetch_failed: '.$e->getMessage();
            }

            return response()->json([
                'ok' => true,
                'path' => $path,
                'url' => $url,
                'bytes' => $bytes,
                'internal_status' => $internalStatus,
                'internal_bytes' => $internalBytes,
                'http_status' => $httpStatus,
                'get_error' => $getError,
                'storage' => 's3',
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function cleanBrokenFileUrls(string $secret): JsonResponse
    {
        $this->authorizeDeploy($secret);

        $counts = [
            'job_update_photos' => \App\Models\JobUpdatePhoto::where(function ($q) {
                $q->where('file_url', 'like', '%api.serviceop.ca%')
                    ->orWhere('file_url', 'like', '%/storage/%')
                    ->orWhere('file_url', 'like', '%digitaloceanspaces.com/')
                    ->orWhere('file_url', 'like', '%digitaloceanspaces.com');
            })->count(),
        ];

        $cleaned = 0;
        $cleaned += \App\Models\JobUpdatePhoto::where(function ($q) {
            $q->where('file_url', 'like', '%api.serviceop.ca%')
                ->orWhere('file_url', 'like', '%/storage/%')
                ->orWhere('file_url', 'like', '%digitaloceanspaces.com/')
                ->orWhere('file_url', 'like', '%digitaloceanspaces.com');
        })->update(['file_url' => null]);

        $cleaned += \App\Models\RevisionRequestPhoto::where(function ($q) {
            $q->where('file_url', 'like', '%api.serviceop.ca%')
                ->orWhere('file_url', 'like', '%/storage/%')
                ->orWhere('file_url', 'like', '%digitaloceanspaces.com/')
                ->orWhere('file_url', 'like', '%digitaloceanspaces.com');
        })->update(['file_url' => null]);

        $cleaned += \App\Models\ContractorDocument::where(function ($q) {
            $q->where('file_url', 'like', '%api.serviceop.ca%')
                ->orWhere('file_url', 'like', '%/storage/%')
                ->orWhere('file_url', 'like', '%digitaloceanspaces.com/')
                ->orWhere('file_url', 'like', '%digitaloceanspaces.com');
        })->update(['file_url' => null]);

        \App\Models\Contractor::query()->each(function ($contractor) use (&$cleaned) {
            $changes = [];
            foreach (['wcb_file_url', 'insurance_file_url'] as $col) {
                $url = $contractor->{$col};
                if ($url && (
                    str_contains($url, 'api.serviceop.ca')
                    || str_contains($url, '/storage/')
                    || str_ends_with(rtrim($url, '/'), 'digitaloceanspaces.com')
                )) {
                    $changes[$col] = null;
                }
            }
            if ($changes) {
                $contractor->update($changes);
                $cleaned += count($changes);
            }
        });

        return response()->json([
            'ok' => true,
            'message' => 'Broken local file URLs cleared.',
            'broken_before' => $counts,
            'cleaned' => $cleaned,
        ]);
    }

    public function cleanTestData(string $secret): JsonResponse
    {
        $this->authorizeDeploy($secret);

        DB::transaction(function () {
            \App\Models\RevisionRequestPhoto::query()->delete();
            \App\Models\RevisionRequest::query()->delete();
            \App\Models\JobUpdatePhoto::query()->delete();
            \App\Models\JobUpdate::query()->delete();
            \App\Models\Payment::query()->delete();
            \App\Models\Payout::query()->delete();
            \App\Models\Invoice::query()->delete();
            \App\Models\Message::query()->delete();
            \App\Models\Quote::query()->delete();
            \App\Models\Job::query()->delete();
            \App\Models\SiteVisit::query()->delete();
            \App\Models\Lead::query()->delete();
            \App\Models\Customer::query()->delete();
            \App\Models\SmsLog::query()->delete();
            \App\Models\EmailLog::query()->delete();
            \App\Models\AuditLog::query()->delete();

            \App\Models\Contractor::query()->update([
                'wcb_status' => 'not_uploaded',
                'liability_insurance_status' => 'not_uploaded',
                'wcb_file_url' => null,
                'insurance_file_url' => null,
            ]);
        });

        return response()->json([
            'ok' => true,
            'message' => 'Test data cleaned successfully',
            'kept' => 'users, companies, settings, contractor profiles',
            'deleted' => 'leads, jobs, quotes, invoices, payments, payouts, messages, updates, site visits, logs',
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
