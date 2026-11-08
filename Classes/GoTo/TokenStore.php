<?php

declare(strict_types=1);

namespace WapplerSystems\GotoWebinar\GoTo;

use TYPO3\CMS\Core\Registry;

/**
 * Keeps the OAuth tokens in sys_registry. They change at runtime (every
 * refresh may hand out a new refresh token), so they cannot live in the
 * extension configuration.
 */
class TokenStore
{
    private const NAMESPACE = 'tx_gotowebinar';

    public function __construct(private readonly Registry $registry) {}

    public function refreshToken(): string
    {
        return (string)$this->registry->get(self::NAMESPACE, 'refreshToken', '');
    }

    public function accessToken(): string
    {
        $expires = (int)$this->registry->get(self::NAMESPACE, 'accessTokenExpires', 0);
        // 60 s margin, a token must not run out between check and request.
        if ($expires < time() + 60) {
            return '';
        }
        return (string)$this->registry->get(self::NAMESPACE, 'accessToken', '');
    }

    public function organizerKey(): string
    {
        return (string)$this->registry->get(self::NAMESPACE, 'organizerKey', '');
    }

    public function principal(): string
    {
        return (string)$this->registry->get(self::NAMESPACE, 'principal', '');
    }

    /**
     * @param array<string, mixed> $response token endpoint response
     */
    public function storeTokenResponse(array $response): void
    {
        $this->registry->set(self::NAMESPACE, 'accessToken', (string)($response['access_token'] ?? ''));
        $this->registry->set(self::NAMESPACE, 'accessTokenExpires', time() + (int)($response['expires_in'] ?? 3600));
        if (!empty($response['refresh_token'])) {
            $this->registry->set(self::NAMESPACE, 'refreshToken', (string)$response['refresh_token']);
        }
        if (!empty($response['organizer_key'])) {
            $this->registry->set(self::NAMESPACE, 'organizerKey', (string)$response['organizer_key']);
        }
        if (!empty($response['principal'])) {
            $this->registry->set(self::NAMESPACE, 'principal', (string)$response['principal']);
        }
    }

    public function clear(): void
    {
        $this->registry->removeAllByNamespace(self::NAMESPACE);
    }

    /**
     * Last successfully fetched webinar list. Served when the API is down,
     * so the page does not lose its webinars during a GoTo outage.
     *
     * @return list<array<string, mixed>>|null
     */
    public function lastWebinars(): ?array
    {
        $value = $this->registry->get(self::NAMESPACE, 'lastWebinars');
        return is_array($value) ? $value : null;
    }

    /**
     * @param list<array<string, mixed>> $webinars
     */
    public function storeLastWebinars(array $webinars): void
    {
        $this->registry->set(self::NAMESPACE, 'lastWebinars', $webinars);
    }
}
