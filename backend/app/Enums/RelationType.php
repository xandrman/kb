<?php

namespace App\Enums;

/**
 * Knowledge graph relation types (FR-4): a fixed list keeps multi-hop paths predictable.
 */
enum RelationType: string
{
    case HasComponent = 'HAS_COMPONENT';
    case HasFault = 'HAS_FAULT';
    case FixedBy = 'FIXED_BY';
    case AppliesTo = 'APPLIES_TO';
    case ManufacturedBy = 'MANUFACTURED_BY';
    case ServicedBy = 'SERVICED_BY';

    /**
     * Whether the relation may go from an entity of the source type to one of the target type.
     */
    public function connects(EntityType $source, EntityType $target): bool
    {
        $hardware = [EntityType::Equipment, EntityType::Component];

        return match ($this) {
            self::HasComponent => in_array($source, $hardware, true) && $target === EntityType::Component,
            self::HasFault => in_array($source, $hardware, true) && $target === EntityType::Fault,
            self::FixedBy => $source === EntityType::Fault && $target === EntityType::Procedure,
            self::AppliesTo => $source === EntityType::Procedure && in_array($target, $hardware, true),
            self::ManufacturedBy => in_array($source, $hardware, true) && $target === EntityType::Organization,
            self::ServicedBy => $source === EntityType::Equipment && $target === EntityType::Organization,
        };
    }
}
