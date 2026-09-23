<?php

namespace App\Enums;

/**
 * Docling pipeline a document is extracted with (ADR-0012).
 */
enum DoclingRoute: string
{
    case Standard = 'standard';
    case Vlm = 'vlm';
}
