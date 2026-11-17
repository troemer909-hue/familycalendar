<?php

declare(strict_types=1);

namespace Familienkalender\Core;

interface AlexaRequestVerifier
{
    /** @param array<string,string> $headers */
    public function verify(string $rawBody, array $headers, ?int $now = null): bool;
}
