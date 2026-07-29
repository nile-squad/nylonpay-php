<?php

declare(strict_types=1);

namespace NileSquad\NylonPay;

/**
 * Wire helpers — omit absent optionals and recurse nested structures.
 */
final class Wire
{
    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function toWire(array $input): array
    {
        $result = [];

        foreach ($input as $key => $value) {
            if ($value === null) {
                continue;
            }

            if (is_array($value)) {
                if (array_is_list($value)) {
                    $result[$key] = array_map(
                        static fn (mixed $item): mixed => is_array($item) ? self::toWire($item) : $item,
                        $value,
                    );
                } else {
                    $result[$key] = self::toWire($value);
                }
                continue;
            }

            $result[$key] = $value;
        }

        return $result;
    }
}
