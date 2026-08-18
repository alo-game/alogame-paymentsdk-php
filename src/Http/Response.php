<?php

declare(strict_types=1);

namespace Alogame\PaymentSdk\Http;

/**
 * Framework-agnostic on purpose — WebpayHandler never touches a PSR-7
 * object or a specific framework's Response class, since a partner might
 * be on Laravel, Symfony, or plain PHP. The caller's own route handler does
 * the one-line translation, e.g. Laravel:
 *   return response()->json($response->body, $response->status);
 */
final class Response
{
    /**
     * @param array<string, mixed> $body
     */
    public function __construct(
        public readonly int $status,
        public readonly array $body,
    ) {
    }
}
