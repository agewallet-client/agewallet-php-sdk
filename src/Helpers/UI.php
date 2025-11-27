<?php

declare(strict_types=1);

namespace AgeWallet\Sdk\Helpers;

class UI
{
    /**
     * Renders a standalone "Verify Age" button.
     */
    public function renderVerifyButton(string $url, string $text = 'Verify Age'): string
    {
        return sprintf(
            '<a href="%s" style="%s">%s</a>',
            htmlspecialchars($url),
            'display:inline-block; background-color:#6a1b9a; color:#fff; padding:12px 24px; text-decoration:none; border-radius:6px; font-weight:bold; font-family:sans-serif;',
            htmlspecialchars($text)
        );
    }

    /**
     * Renders the standard "Gate Card" centered on screen or inline.
     */
    public function renderGate(string $authUrl, string $title = 'Age Verification Required', string $message = 'You must be 18+ to view this content.'): string
    {
        $btnHtml = $this->renderVerifyButton($authUrl, 'I Agree / Verify');

        $html = <<<HTML
        <div style="max-width:500px; margin: 40px auto; padding:30px; border:1px solid #ddd; border-radius:12px; text-align:center; background:#fff; font-family:-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; box-shadow:0 4px 12px rgba(0,0,0,0.1);">
            <h2 style="margin-top:0; color:#333;">{$title}</h2>
            <p style="color:#555; line-height:1.5; margin-bottom:25px;">{$message}</p>
            {$btnHtml}
            <p style="margin-top:20px; font-size:12px; color:#999;">Powered by AgeWallet™</p>
        </div>
HTML;
        return $html;
    }
}