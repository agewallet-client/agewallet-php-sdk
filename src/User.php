<?php

declare(strict_types=1);

namespace AgeWallet\Sdk;

class User
{
    private bool $isVerified;
    private ?array $claims;

    public function __construct(bool $isVerified, array $claims = [])
    {
        $this->isVerified = $isVerified;
        $this->claims = $claims;
    }

    public function isVerified(): bool
    {
        return $this->isVerified;
    }

    public function getClaims(): array
    {
        return $this->claims;
    }

    public function getSubject(): ?string
    {
        $sub = $this->claims['sub'] ?? null;

        if ($sub === null) {
            return null;
        }

        // Cast to string to satisfy return type hint (in case JSON decoded 'sub' as int)
        return (string) $sub;
    }
}