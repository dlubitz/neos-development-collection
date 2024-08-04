<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Projection\ContentGraph\Filter\SearchTerm;

use Neos\ContentRepository\Core\Feature\NodeModification\Dto\SerializedPropertyValue;
use Neos\ContentRepository\Core\Feature\NodeModification\Dto\SerializedPropertyValues;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;

/**
 * Performs search term check against the nodes properties
 *
 * @internal
 */
class SearchTermMatcher
{
    public static function matchesNode(Node $node, SearchTerm $searchTerm): bool
    {
        return static::matchesSerializedPropertyValues($node->properties->serialized(), $searchTerm);
    }

    public static function matchesSerializedPropertyValues(SerializedPropertyValues $serializedPropertyValues, SearchTerm $searchTerm): bool
    {
        foreach ($serializedPropertyValues as $serializedPropertyValue) {
            if (self::matchesValue($serializedPropertyValue->value, $searchTerm)) {
                return true;
            }
        }
        return false;
    }

    private static function matchesValue(mixed $value, SearchTerm $searchTerm): bool
    {
        if (is_array($value) || $value instanceof \ArrayObject) {
            foreach ($value as $subValue) {
                if (self::matchesValue($subValue, $searchTerm)) {
                    return true;
                }
            }
            return false;
        }

        return match (true) {
            is_string($value) => mb_stripos($value, $searchTerm->term) !== false,
            // the following behaviour might seem odd, but is implemented after how the database filtering should behave
            is_int($value),
            is_float($value) => str_contains((string)$value, $searchTerm->term),
            $value === true => $searchTerm->term === 'true',
            $value === false => $searchTerm->term === 'false',
            default => throw new \InvalidArgumentException(sprintf('Handling for type %s is not implemented.', get_debug_type($value))),
        };
    }
}
