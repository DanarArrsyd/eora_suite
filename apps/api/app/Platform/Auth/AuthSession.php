<?php

declare(strict_types=1);

namespace App\Platform\Auth;

use App\Platform\Tenancy\TenantContext;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Date;

/**
 * The server-side session state of a signed-in user (ADR-0006, DD-07): user, credential version, login time and the
 * active tenant/plant. Nothing here ever comes from client input except through the auth endpoints that validate it.
 */
final class AuthSession
{
    private const string KEY = 'eora.auth';

    /** D-B1-05: absolute session lifetime for office devices (idle timeout = SESSION_LIFETIME). */
    public const int ABSOLUTE_LIFETIME = 12 * 3600;

    public function __construct(private readonly Session $session) {}

    public function start(string $userId, int $credentialVersion): void
    {
        $this->session->regenerate(true);
        $this->session->put(self::KEY, [
            'user_id' => $userId,
            'credential_version' => $credentialVersion,
            'logged_in_at' => Date::now()->getTimestamp(),
            'tenant_id' => null,
            'plant_id' => null,
        ]);
    }

    public function userId(): ?string
    {
        return $this->state()['user_id'] ?? null;
    }

    public function tenantId(): ?string
    {
        return $this->state()['tenant_id'] ?? null;
    }

    public function plantId(): ?string
    {
        return $this->state()['plant_id'] ?? null;
    }

    public function credentialVersion(): int
    {
        return (int) ($this->state()['credential_version'] ?? 0);
    }

    public function loggedInAt(): int
    {
        return (int) ($this->state()['logged_in_at'] ?? 0);
    }

    /** Switching tenant regenerates the session id and clears the plant (ADR-0006). */
    /** A tenant switch starts without a plant, or with the only plant in the user's scope (D-071). */
    public function switchTenant(string $tenantId, ?string $plantId = null): void
    {
        $this->session->regenerate(true);
        $this->session->put(self::KEY, ['tenant_id' => $tenantId, 'plant_id' => $plantId] + $this->state());
    }

    public function switchPlant(string $plantId): void
    {
        $this->session->regenerate(true);
        $this->session->put(self::KEY, ['plant_id' => $plantId] + $this->state());
    }

    /** The active plant left the user's scope (assignment revoked or expired): the workspace falls back to no plant. */
    public function clearPlant(): void
    {
        $this->session->put(self::KEY, ['plant_id' => null] + $this->state());
    }

    public function context(): ?TenantContext
    {
        $user = $this->userId();

        return $user === null ? null : new TenantContext($this->tenantId(), $user);
    }

    /**
     * The signed-in user, or an exception: 401 when there is no session, 401 SESSION_EXPIRED (session ended) when the
     * absolute lifetime has passed. Every authenticated entry point goes through here.
     */
    public function requireUser(): string
    {
        $user = $this->userId() ?? throw new AuthenticationException;

        if ($this->loggedInAt() + self::ABSOLUTE_LIFETIME < Date::now()->getTimestamp()) {
            $this->end();

            throw new SessionExpiredException('Session lifetime exceeded.');
        }

        return $user;
    }

    public function end(): void
    {
        $this->session->invalidate();
        $this->session->regenerateToken();
    }

    /** @return array{user_id?: string, credential_version?: int, logged_in_at?: int, tenant_id?: ?string, plant_id?: ?string} */
    private function state(): array
    {
        $state = $this->session->get(self::KEY);

        return is_array($state) ? $state : [];
    }
}
