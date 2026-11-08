<?php

declare(strict_types=1);

namespace WapplerSystems\FormGotowebinar\GoTo;

/**
 * A GoTo API call that did not succeed. $status is the HTTP status (0 when
 * the API was not reached at all), $body the raw response for the log.
 */
class ApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly string $body = '',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * @return array<string, mixed>
     */
    public function decodedBody(): array
    {
        try {
            $data = json_decode($this->body, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            return [];
        }
        return is_array($data) ? $data : [];
    }

    public function errorCode(): string
    {
        $body = $this->decodedBody();
        return (string)($body['errorCode'] ?? $body['error'] ?? $body['int_err_code'] ?? '');
    }

    public function errorMessage(): string
    {
        $body = $this->decodedBody();
        return (string)($body['description'] ?? $body['msg'] ?? $body['error_description'] ?? $body['message'] ?? '');
    }

    /**
     * GoTo answers 409 when the address is already registered for that
     * webinar. For the visitor that is a success, not an error.
     */
    public function isDuplicateRegistrant(): bool
    {
        return $this->status === 409;
    }

    public function isAuthenticationError(): bool
    {
        return $this->status === 401;
    }
}
