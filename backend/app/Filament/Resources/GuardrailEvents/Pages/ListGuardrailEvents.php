<?php

namespace App\Filament\Resources\GuardrailEvents\Pages;

use App\Filament\Resources\GuardrailEvents\GuardrailEventResource;
use Filament\Resources\Pages\ListRecords;

class ListGuardrailEvents extends ListRecords
{
    protected static string $resource = GuardrailEventResource::class;
}
