<?php

declare(strict_types=1);

namespace AgeWallet\Sdk\Interfaces;

interface SessionHandlerInterface
{
    /**
     * Set a value in the session store.
     *
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function set(string $key, $value): void;

    /**
     * Get a value from the session store.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, $default = null);

    /**
     * Check if a key exists in the session store.
     *
     * @param string $key
     * @return bool
     */
    public function has(string $key): bool;

    /**
     * Remove a key from the session store.
     *
     * @param string $key
     * @return void
     */
    public function remove(string $key): void;
}