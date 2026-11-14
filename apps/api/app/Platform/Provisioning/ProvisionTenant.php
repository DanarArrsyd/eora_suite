<?php

declare(strict_types=1);

namespace App\Platform\Provisioning;

use App\Platform\Auth\Delivery\ConsoleInvitation;
use App\Platform\Console\ReadsTextInput;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Operator command: create a tenant with its first administrator (doc 25 §2.5, D-B1-13, D-B1-16). Idempotent — a re-run
 * with the same code and admin e-mail resumes or, once created, only resends the invitation.
 */
final class ProvisionTenant extends Command
{
    use ReadsTextInput;

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
        $deliver = $this->textOption('deliver');
        if (! in_array($deliver, ['email', 'console'], true)) {
            $this->error('--deliver must be email or console.');

            return self::INVALID;
        }

        try {
            $spec = new TenantSpec(
                tenantCode: $this->textArgument('code'),
                tenantName: $this->textArgument('name'),
                adminEmail: $this->textArgument('admin-email'),
                companyCode: $this->textOption('company-code'),
                companyName: $this->textOption('company-name'),
                plantCode: $this->textOption('plant-code'),
                plantName: $this->textOption('plant-name'),
                productionDayStartsAt: $this->textOption('day-starts'),
                timezone: $this->textOption('timezone'),
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
