<?php

namespace App\Console\Commands;

use App\Actions\MergeEntitySynonyms;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('graph:merge-synonyms')]
#[Description('Слить сущности графа знаний, которые называют одно и то же (FR-4)')]
class MergeGraphSynonyms extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(MergeEntitySynonyms $mergeSynonyms): int
    {
        $merged = $mergeSynonyms->handle(function (string $type, string $first, string $second, bool $same): void {
            $this->line(sprintf('%s %s: «%s» — «%s»', $same ? 'слито  ' : 'разные ', $type, $first, $second));
        });

        $this->info("Слито сущностей: {$merged}");

        return self::SUCCESS;
    }
}
