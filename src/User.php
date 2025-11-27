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
        return $this->claims['sub'] ?? null;
    }
}