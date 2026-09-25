<?php

namespace App\Actions;

use App\Contracts\DocumentStorage;
use App\Models\Document;

class VerifyDocumentIntegrity
{
    public function __construct(private readonly DocumentStorage $storage) {}

    /**
     * Recompute the digest of the stored original; the volume is not replicated, so this is the only guard against corruption (ADR-0018).
     */
    public function handle(Document $document): bool
    {
        $file = $this->storage->get($document->digest);

        try {
            $context = hash_init('sha256');
            hash_update_stream($context, $file);

            return hash_equals($document->digest, hash_final($context));
        } finally {
            is_resource($file) && fclose($file);
        }
    }
}
