<?php

declare(strict_types=1);

namespace App\Platform\Demo;

use App\Platform\Access\Authorizer;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Import\ImportController;
use App\Platform\Import\ImportTemplates;
use App\Platform\Security\SecurityHasher;
use App\Platform\Tenancy\TenantContext;
use App\Platform\Tenancy\TenantTransaction;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;

/**
 * Master data for a seeded demo tenant (doc 29 §2, M2 exit): the sample files (ImportSamples) go through the real import
 * pipeline — uploaded and submitted by the planner (`ppc@`) where the planner may create that master data, by the
 * master-data administrator (`csa@`) otherwise (D-081), and committed by the administrator; the import worker validates
 * and commits each file. Units, items, customers and the rest become Active; routings and
 * BOMs stay Draft, so the two-person approval is left to UAT; maps need submitted versions and are left to UAT too.
 */
final class DemoMasterData
{
    /** Item of the sample set whose presence means the set was loaded already. */
    public const string MARKER = 'BRK-A100';

    private const array SKIPPED = ['bom_routing_maps'];

    public function __construct(
        private readonly TenantTransaction $transaction,
        private readonly SecurityHasher $hasher,
        private readonly ImportController $imports,
        private readonly ImportTemplates $templates,
        private readonly Authorizer $authorizer,
    ) {}

    /**
     * @param  ?string  $from  first effective date (YYYY-MM-DD); null = today at plant P1
     * @param  Closure(string, int): void  $progress  template, committed rows
     * @return list<array{template: string, rows: int}>
     */
    public function load(DemoSpec $spec, ?string $from, Closure $progress, int $waitSeconds = 300): array
    {
        $tenant = DB::connection('pgsql_provisioner')->selectOne(
            'select tenant_id, status from prov_api.provision_lookup(?, ?)',
            [$spec->code, $this->hasher->hash($spec->code."\n".$spec->email('tenant-admin'))],
        );
        if ($tenant === null || $tenant->status === 'STARTED') {
            throw new RuntimeException("No complete demo tenant {$spec->code}. Run eora:demo:seed --code={$spec->code} first.");
        }
        $tenant = (string) $tenant->tenant_id;
        $planner = $this->member($tenant, $spec->email('ppc'));
        $admin = $this->member($tenant, $spec->email('csa'));
        $state = $this->system($tenant, fn (EoraPostgresConnection $db) => $db->selectOne(
            "select exists (select 1 from items where code = ?) as loaded,
                    (select (now() at time zone timezone)::date::text from plants where code = 'P1') as today",
            [self::MARKER],
        ));
        if ($state->loaded) {
            throw new RuntimeException('The demo master data is loaded already (item '.self::MARKER.' exists). Loading never changes existing data.');
        }

        $dir = sys_get_temp_dir().'/eora-demo-master-'.bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);
        try {
            $loaded = [];
            foreach (ImportSamples::write($this->templates, $dir, $from ?? (string) $state->today) as $file) {
                // the last file holds deliberate errors (UAT), the maps wait for submitted versions (UAT)
                if (str_starts_with(basename($file['path']), '99-') || in_array($file['template'], self::SKIPPED, true)) {
                    continue;
                }
                $rows = $this->import($tenant, $planner, $admin, $file['template'], $file['path'], $waitSeconds);
                $progress($file['template'], $rows);
                $loaded[] = ['template' => $file['template'], 'rows' => $rows];
            }

            return $loaded;
        } finally {
            array_map('unlink', glob("{$dir}/*.xlsx") ?: []);
            rmdir($dir);
        }
    }

    /** Upload and submit as the planner (or the administrator), commit as the administrator; returns the committed rows. */
    private function import(string $tenant, string $planner, string $admin, string $template, string $path, int $waitSeconds): int
    {
        $uploader = $this->may($tenant, $planner, $this->templates->get($template)->targetPermission()) ? $planner : $admin;
        $upload = Request::create('/api/v1/exchange/imports', 'POST', ['template' => $template], [], [
            'file' => new UploadedFile($path, basename($path), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
        ]);
        $id = (string) $this->as($tenant, $uploader, fn () => $this->imports->store($upload)->getData(true)['data']['id']);

        $job = $this->await($tenant, $id, ['VALIDATED'], $waitSeconds);
        if ((int) $job->error_rows > 0) {
            $first = $this->system($tenant, fn (EoraPostgresConnection $db) => $db->selectOne(
                "select row_no, errors::text as errors from import_rows where job_id = ? and status = 'ERROR' order by row_no limit 1",
                [$id],
            ));
            throw new RuntimeException(sprintf('%s: %d rows have errors (row %d: %s). The sample files and the tenant data do not match.',
                basename($path), $job->error_rows, $first->row_no, $first->errors));
        }
        $this->as($tenant, $uploader, fn () => $this->imports->submit($id));
        $this->as($tenant, $admin, fn () => $this->imports->commit($id));

        return (int) $this->await($tenant, $id, ['COMMITTED'], $waitSeconds)->committed_rows;
    }

    /**
     * Waits for the worker to move the job to one of $statuses.
     *
     * @param  list<string>  $statuses
     */
    private function await(string $tenant, string $id, array $statuses, int $waitSeconds): stdClass
    {
        $until = microtime(true) + $waitSeconds;
        while (true) {
            $job = $this->system($tenant, fn (EoraPostgresConnection $db) => $db->selectOne(
                'select template, status, failure_detail, error_rows, committed_rows from import_jobs where id = ?',
                [$id],
            ));
            if (in_array($job->status, $statuses, true)) {
                return $job;
            }
            if (in_array($job->status, ['FAILED', 'REJECTED'], true)) {
                throw new RuntimeException("Import {$job->template} {$job->status}: ".($job->failure_detail ?? 'no detail'));
            }
            if (microtime(true) > $until) {
                throw new RuntimeException("Import {$job->template} is still {$job->status} after {$waitSeconds} seconds. Is the import worker running?");
            }
            usleep(500_000);
        }
    }

    private function may(string $tenant, string $user, string $permission): bool
    {
        return $this->as($tenant, $user, fn (EoraPostgresConnection $db) => $this->authorizer->scopes($db, $permission) !== []);
    }

    private function member(string $tenant, string $email): string
    {
        $user = $this->system($tenant, fn (EoraPostgresConnection $db) => $db->selectOne(
            "select u.id from users u join memberships m on m.user_id = u.id where u.email_display = ? and m.status = 'ACTIVE'",
            [$email],
        ));

        return $user !== null ? (string) $user->id : throw new RuntimeException("No active demo account {$email}.");
    }

    /** @param  Closure(EoraPostgresConnection): mixed  $work  runs as $user, as the API does for a signed-in request */
    private function as(string $tenant, string $user, Closure $work): mixed
    {
        return $this->transaction->run(TenantContext::forTenant($tenant, $user), $work);
    }

    /** @param  Closure(EoraPostgresConnection): mixed  $work */
    private function system(string $tenant, Closure $work): mixed
    {
        return $this->transaction->run(TenantContext::system($tenant), $work, 'pgsql_provisioner');
    }
}
