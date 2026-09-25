<?php

namespace App\Actions;

use App\Enums\AccessLevel;
use App\Models\RestrictedChunkRead;
use App\Models\User;

class RecordRestrictedChunkReads
{
    /**
     * Log the restricted chunks an answer was built from (SRS 6.4); public ones are not logged, the question must already be masked.
     *
     * @param  list<array<string, mixed>>  $sources  metadata of the context chunks, as GroundedContextNode keeps it
     */
    public function handle(?User $user, array $sources, string $maskedQuestion): void
    {
        foreach ($sources as $source) {
            $accessLevel = AccessLevel::tryFrom($source['access_level'] ?? '');

            if ($accessLevel === null || $accessLevel === AccessLevel::Public) {
                continue;
            }

            RestrictedChunkRead::create([
                'user_id' => $user?->id,
                'document_id' => $source['document_id'] ?? null,
                'chunk_id' => $source['chunk_id'] ?? null,
                'access_level' => $accessLevel,
                'page_numbers' => $source['page_numbers'] ?? null,
                'question' => $maskedQuestion,
            ]);
        }
    }
}
