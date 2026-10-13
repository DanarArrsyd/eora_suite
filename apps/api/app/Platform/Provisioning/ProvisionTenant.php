<?php

declare(strict_types=1);

namespace App\Platform\Provisioning;

use App\Platform\Auth\Delivery\ConsoleInvitation;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Operator command: create a tenant with its first administrator (doc 25 §2.5, D-B1-13, D-B1-16). Idempotent — a re-run
 * with the same code and admin e-mail resumes or, once created, only resends the invitation.
 */
final class ProvisionTenant extends Command
{
    protected $signature = 'eora:tenant:provision
        {code : Tenant code (a-z, 0-9, -)}
        {name : Tenant display name}
        {admin-email : E-mail of the first administrator}
        {--company-code= : Company code (A-Z, 0-9, _ -)}
        {--company-name= : Company legal name}
        {--plant-code= : First plant code (A-Z, 0-9, _ -)}
        {--plant-name= : First plant name}
        {--day-starts= : Production day start, HH:MM in the tenant time zone (ADR-0012)}
        {--timezone=Asia/Jakarta : IANA time zone (D-044 default)}
        {--deliver=email : email (delivery worker) or console (link printed here, never logged)}';

    protected $description = 'Provision a tenant, its first company/plant/calendar and the first administrator (TENANT_ADMIN)';

    public function handle(TenantProvisioner $provisioner, ConsoleInvitation $console): int
    {
        $deliver = (string) $this->option('deliver');
        if (! in_array($deliver, ['email', 'console'], true)) {
            $this->error('--deliver must be email or console.');

            return self::INVALID;
        }

        try {
            $spec = new TenantSpec(
                tenantCode: (string) $this->argument('code'),
                tenantName: (string) $this->argument('name'),
                adminEmail: (string) $this->argument('admin-email'),
                companyCode: (string) $this->option('company-code'),
                companyName: (string) $this->option('company-name'),
                plantCode: (string) $this->option('plant-code'),
                plantName: (string) $this->option('plant-name'),
                productionDayStartsAt: (string) $this->option('day-starts'),
                timezone: (string) $this->option('timezone'),
            );
            $result = $provisioner->provision($spec, console: $deliver === 'console');
        } catch (InvalidArgumentException|ProvisioningException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info($result->created ? "Tenant created: {$result->tenantId}" : "Tenant already provisioned: {$result->tenantId} (invitation re-queued)");

        if ($deliver === 'email') {
            $this->line('The delivery worker sends the invitation to the administrator.');

            return self::SUCCESS;
        }

        $link = $console->deliver($result->adminMembershipId);
        if ($link === null) {
            $this->warn('No invitation could be issued (already accepted, or the resend limit of 5 per 24 h was reached).');

            return self::FAILURE;
        }
        $this->line('One-time invitation link for the administrator (valid 72 h, single use; not stored anywhere):');
        $this->line($link);

        return self::SUCCESS;
    }
}
