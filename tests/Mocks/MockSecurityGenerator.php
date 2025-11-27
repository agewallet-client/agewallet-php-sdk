<?php

declare(strict_types=1);

namespace AgeWallet\Sdk\Tests\Mocks;

use AgeWallet\Sdk\Interfaces\SecurityGeneratorInterface;

class MockSecurityGenerator implements SecurityGeneratorInterface
{
    public function generateRandomString(int $length = 32): string
    {
        return "test-random-string-fixed";
    }

    public function generatePkceVerifier(int $length = 64): string
    {
        return "test-pkce-verifier-fixed-value";
    }

    public function generatePkceChallenge(string $verifier): string
    {
        // We simulate a hash so we can verify the logic works
        return "mock-challenge-for-" . $verifier;
    }
}