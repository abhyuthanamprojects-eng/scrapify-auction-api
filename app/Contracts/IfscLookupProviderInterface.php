<?php

namespace App\Contracts;

interface IfscLookupProviderInterface
{
    /** @return array<string, mixed> */
    public function lookupIfsc(string $ifsc): array;
}
