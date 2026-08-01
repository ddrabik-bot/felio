<?php

namespace App\Domain\Fx;

final readonly class NbpFxHttpResponse
{
    /** @param array<string, string> $headers */
    public function __construct(
        public int $statusCode,
        public string $body,
        public array $headers = [],
    ) {}
}
