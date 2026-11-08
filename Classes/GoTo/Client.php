<?php

declare(strict_types=1);

namespace WapplerSystems\FormGotowebinar\GoTo;

use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;

/**
 * GoTo Webinar API v2 client for the few calls the registration needs.
 *
 * Authentication is OAuth 2 with the authorization code flow: an organizer
 * grants access once (gotowebinar:connect), afterwards the stored refresh
 * token is exchanged for access tokens without any login.
 *
 * Client id and secret come from the extension configuration or, if empty,
 * from GOTO_CLIENT_ID / GOTO_CLIENT_SECRET.
 */
class Client
{
    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly TokenStore $tokenStore,
        private readonly LockFactory $lockFactory,
    ) {}

    public function hasCredentials(): bool
    {
        return $this->clientId() !== '' && $this->clientSecret() !== '';
    }

    public function isConnected(): bool
    {
        return $this->hasCredentials() && $this->tokenStore->refreshToken() !== '' && $this->organizerKey() !== '';
    }

    public function authorizeUrl(string $state = ''): string
    {
        $query = [
            'client_id' => $this->clientId(),
            'response_type' => 'code',
            'redirect_uri' => $this->redirectUri(),
        ];
        if ($state !== '') {
            $query['state'] = $state;
        }
        return $this->authBaseUrl() . '/oauth/authorize?' . http_build_query($query);
    }

    /**
     * Exchanges the code from the redirect after the organizer granted access.
     *
     * @return array<string, mixed> token response
     */
    public function connect(string $code): array
    {
        $response = $this->tokenRequest([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri(),
        ]);
        $this->tokenStore->storeTokenResponse($response);
        return $response;
    }

    /**
     * Upcoming webinars of the organizer, sorted by start time.
     *
     * @return list<array{webinarKey: string, subject: string, description: string, startTime: string, endTime: string, timeZone: string, registrationUrl: string}>
     */
    public function upcomingWebinars(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $webinars = [];
        $page = 0;
        do {
            $data = $this->get('/organizers/' . rawurlencode($this->organizerKey()) . '/webinars', [
                'fromTime' => $from->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
                'toTime' => $to->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
                'page' => $page,
                'size' => 200,
            ]);
            foreach ((array)($data['_embedded']['webinars'] ?? []) as $webinar) {
                $normalized = self::normalizeWebinar((array)$webinar);
                if ($normalized !== null) {
                    $webinars[] = $normalized;
                }
            }
            $totalPages = (int)($data['page']['totalPages'] ?? 1);
            $page++;
        } while ($page < $totalPages && $page < 20);

        usort($webinars, static fn(array $a, array $b): int => strcmp($a['startTime'], $b['startTime']));
        return $webinars;
    }

    /**
     * Fields and custom questions the registration form of a webinar asks for.
     *
     * @return array{fields: list<array<string, mixed>>, questions: list<array<string, mixed>>}
     */
    public function registrationFields(string $webinarKey): array
    {
        $data = $this->get('/organizers/' . rawurlencode($this->organizerKey()) . '/webinars/' . rawurlencode($webinarKey) . '/registrants/fields');
        return [
            'fields' => array_values((array)($data['fields'] ?? [])),
            'questions' => array_values((array)($data['questions'] ?? [])),
        ];
    }

    /**
     * Registers one person for one webinar.
     *
     * @param array<string, mixed> $registrant firstName, lastName, email and
     *        optional standard fields (organization, jobTitle, ...) plus
     *        "responses" for custom questions
     * @return array{registrantKey: string, joinUrl: string, duplicate: bool}
     */
    public function createRegistrant(string $webinarKey, array $registrant, bool $resendConfirmation = false): array
    {
        $path = '/organizers/' . rawurlencode($this->organizerKey()) . '/webinars/' . rawurlencode($webinarKey) . '/registrants';
        try {
            $data = $this->send('POST', $path, ['resendConfirmation' => $resendConfirmation ? 'true' : 'false'], $registrant, [
                // v1.1 accepts the extended standard fields (organization, phone, ...)
                'Accept' => 'application/vnd.citrix.g2wapi-v1.1+json',
            ]);
            $duplicate = false;
        } catch (ApiException $e) {
            if (!$e->isDuplicateRegistrant()) {
                throw $e;
            }
            $data = $e->decodedBody();
            $duplicate = true;
        }
        return [
            'registrantKey' => (string)($data['registrantKey'] ?? ''),
            'joinUrl' => (string)($data['joinUrl'] ?? ''),
            'duplicate' => $duplicate,
        ];
    }

    /**
     * @param array<string, mixed> $webinar raw API record
     * @return array{webinarKey: string, subject: string, description: string, startTime: string, endTime: string, timeZone: string, registrationUrl: string}|null
     */
    public static function normalizeWebinar(array $webinar): ?array
    {
        $key = (string)($webinar['webinarKey'] ?? '');
        if ($key === '') {
            return null;
        }
        // A series webinar has several sessions; the next one counts.
        $times = array_values((array)($webinar['times'] ?? []));
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $next = null;
        foreach ($times as $time) {
            if ((string)($time['endTime'] ?? '') >= $now) {
                $next = $time;
                break;
            }
        }
        $next ??= $times[0] ?? [];
        return [
            'webinarKey' => $key,
            'subject' => trim((string)($webinar['subject'] ?? '')),
            'description' => trim((string)($webinar['description'] ?? '')),
            'startTime' => (string)($next['startTime'] ?? ''),
            'endTime' => (string)($next['endTime'] ?? ''),
            'timeZone' => (string)($webinar['timeZone'] ?? 'Europe/Berlin'),
            'registrationUrl' => (string)($webinar['registrationUrl'] ?? ''),
        ];
    }

    /**
     * @param array<string, scalar> $query
     * @return array<string, mixed>
     */
    private function get(string $path, array $query = []): array
    {
        return $this->send('GET', $path, $query);
    }

    /**
     * @param array<string, scalar> $query
     * @param array<string, mixed>|null $payload
     * @param array<string, string> $headers
     * @return array<string, mixed>
     * @throws ApiException
     */
    private function send(string $method, string $path, array $query = [], ?array $payload = null, array $headers = [], bool $retry = true): array
    {
        if (!$this->isConnected()) {
            throw new ApiException('GoTo is not connected. Run "typo3 gotowebinar:connect" first.', 0, '', 1762560001);
        }
        $url = $this->apiBaseUrl() . $path . ($query !== [] ? '?' . http_build_query($query) : '');
        $options = [
            'headers' => array_merge([
                'Authorization' => 'Bearer ' . $this->accessToken(),
                'Accept' => 'application/json',
            ], $headers),
            'timeout' => 10,
            'http_errors' => false,
        ];
        if ($payload !== null) {
            $options['headers']['Content-Type'] = 'application/json';
            $options['body'] = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }

        $response = $this->request($url, $method, $options);
        $status = $response->getStatusCode();
        $body = (string)$response->getBody();

        // The access token may have been revoked before it expired.
        if ($status === 401 && $retry) {
            $this->refreshAccessToken();
            return $this->send($method, $path, $query, $payload, $headers, false);
        }
        if ($status < 200 || $status >= 300) {
            throw new ApiException('GoTo API answered ' . $status . ' for ' . $method . ' ' . $path, $status, $body, 1762560002);
        }
        return self::decode($body);
    }

    private function accessToken(): string
    {
        $token = $this->tokenStore->accessToken();
        return $token !== '' ? $token : $this->refreshAccessToken();
    }

    /**
     * Refresh tokens may be rotated, so two requests must not refresh at
     * the same time: the second one would use an already replaced token.
     */
    private function refreshAccessToken(): string
    {
        $lock = $this->lockFactory->createLocker('formgotowebinar_token', LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE);
        $lock->acquire();
        try {
            // Another process may have refreshed while we waited for the lock.
            $token = $this->tokenStore->accessToken();
            if ($token !== '') {
                return $token;
            }
            $refreshToken = $this->tokenStore->refreshToken();
            if ($refreshToken === '') {
                throw new ApiException('No GoTo refresh token stored. Run "typo3 gotowebinar:connect".', 0, '', 1762560003);
            }
            $response = $this->tokenRequest([
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
            ]);
            $this->tokenStore->storeTokenResponse($response);
            return (string)($response['access_token'] ?? '');
        } finally {
            $lock->release();
        }
    }

    /**
     * @param array<string, string> $form
     * @return array<string, mixed>
     */
    private function tokenRequest(array $form): array
    {
        if (!$this->hasCredentials()) {
            throw new ApiException('No GoTo client id/secret configured.', 0, '', 1762560004);
        }
        $response = $this->request($this->authBaseUrl() . '/oauth/token', 'POST', [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($this->clientId() . ':' . $this->clientSecret()),
                'Accept' => 'application/json',
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => http_build_query($form),
            'timeout' => 10,
            'http_errors' => false,
        ]);
        $status = $response->getStatusCode();
        $body = (string)$response->getBody();
        if ($status !== 200) {
            throw new ApiException('GoTo token endpoint answered ' . $status, $status, $body, 1762560005);
        }
        $data = self::decode($body);
        if (empty($data['access_token'])) {
            throw new ApiException('GoTo token response without access token', $status, $body, 1762560006);
        }
        return $data;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function request(string $url, string $method, array $options): ResponseInterface
    {
        try {
            return $this->requestFactory->request($url, $method, $options);
        } catch (GuzzleException $e) {
            throw new ApiException('GoTo API not reachable: ' . $e->getMessage(), 0, '', 1762560007, $e);
        }
    }

    /**
     * Webinar and registrant keys are 64-bit numbers. Decoded as numbers
     * they would lose digits on the way, so they are kept as strings.
     *
     * @return array<string, mixed>
     */
    private static function decode(string $body): array
    {
        if (trim($body) === '') {
            return [];
        }
        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException $e) {
            throw new ApiException('GoTo API returned invalid JSON', 0, $body, 1762560008, $e);
        }
        return is_array($data) ? $data : [];
    }

    private function organizerKey(): string
    {
        $key = trim((string)$this->setting('organizerKey'));
        return $key !== '' ? $key : $this->tokenStore->organizerKey();
    }

    private function clientId(): string
    {
        $value = trim((string)$this->setting('clientId'));
        return $value !== '' ? $value : trim((string)getenv('GOTO_CLIENT_ID'));
    }

    private function clientSecret(): string
    {
        $value = trim((string)$this->setting('clientSecret'));
        return $value !== '' ? $value : trim((string)getenv('GOTO_CLIENT_SECRET'));
    }

    private function redirectUri(): string
    {
        return trim((string)$this->setting('redirectUri'));
    }

    private function apiBaseUrl(): string
    {
        $url = trim((string)$this->setting('apiBaseUrl'));
        return rtrim($url !== '' ? $url : 'https://api.getgo.com/G2W/rest/v2', '/');
    }

    private function authBaseUrl(): string
    {
        $url = trim((string)$this->setting('authBaseUrl'));
        return rtrim($url !== '' ? $url : 'https://authentication.logmeininc.com', '/');
    }

    private function setting(string $name): mixed
    {
        try {
            return $this->extensionConfiguration->get('form_gotowebinar', $name);
        } catch (\Throwable) {
            return null;
        }
    }
}
