<?php

declare(strict_types=1);

namespace AgeWallet\Sdk\Tests\Mocks;

use AgeWallet\Sdk\Interfaces\SessionHandlerInterface;

class InMemorySessionHandler implements SessionHandlerInterface
{
    private array $store = [];

    public function set(string $key, $value): void
    {
        $this->store[$key] = $value;
    }

    public function get(string $key, $default = null)
    {
        return $this->store[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return isset($this->store[$key]);
    }

    public function remove(string $key): void
    {
        unset($this->store[$key]);
    }

    // Helper for testing
    public function dump(): array
    {
        return $this->store;
    }
}