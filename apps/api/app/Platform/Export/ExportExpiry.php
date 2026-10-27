<?php

declare(strict_types=1);

namespace App\Platform\Export;

use App\Platform\Audit\AuditRecorder;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Tenancy\TenantContext;
use App\Platform\Tenancy\TenantTransaction;
use Illuminate\Support\Facades\Storage;

/**
 * D-092: an exported file lives 24 hours. For one tenant, as the system actor: every finished export past its expiry
 * loses its file and becomes EXPIRED (audited). Links already refuse an expired job, so a late run never makes a file
 * reachable for longer.
 */
final class ExportExpiry
{
    public function __construct(private readonly TenantTransaction $transaction, private readonly AuditRecorder $audit) {}

    /** @return int files deleted */
    public function expire(string $tenant): int
    {
        return $this->transaction->run(TenantContext::system($tenant), function (EoraPostgresConnection $db): int {
            $jobs = $db->select("select tenant_id, id from export_jobs where status = 'DONE' and expires_at <= now() for update skip locked");
            foreach ($jobs as $job) {
                Storage::disk(Exports::DISK)->deleteDirectory(Exports::folder((string) $job->tenant_id, (string) $job->id));
                $db->update("update export_jobs set status = 'EXPIRED', file_key = null where id = ?", [$job->id]);
                $this->audit->record($db, 'export_job.expired', 'export_job', (string) $job->id);
            }

            return count($jobs);
        }, 'pgsql_provisioner');
    }
}
