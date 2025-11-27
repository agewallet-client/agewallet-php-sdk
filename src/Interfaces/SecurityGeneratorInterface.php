<?php

declare(strict_types=1);

namespace AgeWallet\Sdk\Interfaces;

interface SecurityGeneratorInterface
{
    /**
     * Generate a cryptographically secure random string (hex).
     *
     * @param int $length Byte length (output will be double this in hex chars).
     * @return string
     */
    public function generateRandomString(int $length = 32): string;

    /**
     * Generate a PKCE Code Verifier.
     *
     * @param int $length
     * @return string
     */
    public function generatePkceVerifier(int $length = 64): string;

    /**
     * Generate a PKCE S256 Challenge from a Verifier.
     *
     * @param string $verifier
     * @return string
     */
    public function generatePkceChallenge(string $verifier): string;
}