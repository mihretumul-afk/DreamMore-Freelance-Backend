<?php

namespace App\Services\Payment;

/**
 * ProviderResult — value object returned by every gateway adapter method.
 *
 * Keeps the rest of the codebase decoupled from any specific gateway's
 * response shape. Controllers and PaymentService only read these fields.
 */
readonly class ProviderResult
{
    public function __construct(
        public bool    $success,
        public string  $message,
        public ?string $reference    = null,
        public ?string $redirectUrl  = null,
        public array   $raw          = [],
        public ?string $failureCode  = null,
    ) {}

    public static function ok(string $message = 'Success', ?string $reference = null, array $raw = []): self
    {
        return new self(success: true, message: $message, reference: $reference, raw: $raw);
    }

    public static function fail(string $message, ?string $code = null, array $raw = []): self
    {
        return new self(success: false, message: $message, failureCode: $code, raw: $raw);
    }
}
