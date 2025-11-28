<?php

declare(strict_types=1);

namespace AgeWallet\Sdk\Interfaces;

interface GateRendererInterface
{
    /**
     * Renders the HTML for the age gate.
     *
     * @param string $authUrl The URL the user must visit to verify their age.
     * @param array $options Optional configuration (title, description, logo, etc.).
     * @return string The complete HTML string.
     */
    public function render(string $authUrl, array $options = []): string;
}