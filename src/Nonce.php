<?php

declare(strict_types=1);

namespace NileSquad\NylonPay;

/**
 * Cryptographically secure nonce generation for signed requests.
 */
final class Nonce
{
    /**
     * @param int $bytes byte length of the nonce, at least 1
     *
     * @throws \InvalidArgumentException when $bytes is below 1
     */
    public static function generate(int $bytes = 16): string
    {
        // random_bytes() throws its own \Error below 1. Rejecting it here
        // states the requirement in the signature's own terms instead.
        if ($bytes < 1) {
            throw new \InvalidArgumentException(
                \sprintf('Nonce byte length must be at least 1, got %d.', $bytes)
            );
        }

        return bin2hex(random_bytes($bytes));
    }
}
