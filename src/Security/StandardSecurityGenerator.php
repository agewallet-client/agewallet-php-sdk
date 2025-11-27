<?php

declare(strict_types=1);

namespace AgeWallet\Sdk\Security;

use AgeWallet\Sdk\Interfaces\SecurityGeneratorInterface;
use AgeWallet\Sdk\Exceptions\AgeWalletException;

class StandardSecurityGenerator implements SecurityGeneratorInterface
{
    public function generateRandomString(int $length = 32): string
    {
        try {
            return bin2hex(random_bytes($length));
        } catch (\Exception $e) {
            throw new AgeWalletException("Failed to generate random bytes: " . $e->getMessage());
        }
    }

    public function generatePkceVerifier(int $length = 64): string
    {
        try {
            $bytes = random_bytes($length);
            return $this->base64UrlEncode($bytes);
        } catch (\Exception $e) {
            throw new AgeWalletException("Failed to generate PKCE verifier: " . $e->getMessage());
        }
    }

    public function generatePkceChallenge(string $verifier): string
    {
        $hash = hash('sha256', $verifier, true);
        return $this->base64UrlEncode($hash);
    }

    private function base64UrlEncode(string $input): string
    {
        return rtrim(strtr(base64_encode($input), '+/', '-_'), '=');
    }
}