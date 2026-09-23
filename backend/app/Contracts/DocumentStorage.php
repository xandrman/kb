<?php

namespace App\Contracts;

/**
 * Content-addressed blob store for source documents (ADR-0018), keyed by sha256 digest.
 */
interface DocumentStorage
{
    /**
     * Publish the file under its digest; an already published digest is left untouched.
     */
    public function put(string $sourcePath): string;

    /**
     * @return resource
     */
    public function get(string $digest);

    public function exists(string $digest): bool;

    public function delete(string $digest): void;

    /**
     * Store the DoclingDocument extracted from the original, replacing a previous extraction (ADR-0026).
     */
    public function putExtracted(string $digest, string $json): void;

    public function getExtracted(string $digest): string;
}
