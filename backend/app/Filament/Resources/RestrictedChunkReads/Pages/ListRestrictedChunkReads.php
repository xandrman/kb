<?php

namespace App\Filament\Resources\RestrictedChunkReads\Pages;

use App\Filament\Resources\RestrictedChunkReads\RestrictedChunkReadResource;
use Filament\Resources\Pages\ListRecords;

class ListRestrictedChunkReads extends ListRecords
{
    protected static string $resource = RestrictedChunkReadResource::class;
}
