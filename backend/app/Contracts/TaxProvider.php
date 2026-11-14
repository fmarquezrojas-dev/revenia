<?php

namespace App\Contracts;

interface TaxProvider
{
    /** Results: status accepted/rejected/failed, code, message, and kind recoverable/unknown/permanent for failures. */
    public function submit(string $submissionId, array $payload): array;

    public function lookup(string $submissionId): ?array;
}
