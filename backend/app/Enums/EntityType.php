<?php

namespace App\Enums;

/**
 * Knowledge graph entity types (FR-4); people are not extracted, they are personal data (SRS 4.4).
 */
enum EntityType: string
{
    case Equipment = 'Equipment';
    case Component = 'Component';
    case Fault = 'Fault';
    case Procedure = 'Procedure';
    case Organization = 'Organization';
}
