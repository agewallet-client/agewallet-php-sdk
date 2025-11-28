<?php

declare(strict_types=1);

namespace AgeWallet\Sdk;

use AgeWallet\Sdk\Interfaces\SessionHandlerInterface;
use AgeWallet\Sdk\Interfaces\SecurityGeneratorInterface;
use AgeWallet\Sdk\Interfaces\GateRendererInterface;
use AgeWallet\Sdk\Storage\NativeSessionHandler;
use AgeWallet\Sdk\Security\StandardSecurityGenerator;
use AgeWallet\Sdk\Renderers\StandardGateRenderer;
use AgeWallet\Sdk\Exceptions\AgeWalletException;

class Client
{
    const AUTH_ENDPOINT     = 'https://app.agewallet.io/user/authorize';
    const TOKEN_ENDPOINT    = 'https://app.agewallet.io/user/token';
    const USERINFO_ENDPOINT = 'https://app.agewallet.io/user/userinfo';

    private array $config;
    private SessionHandlerInterface $session;
    private SecurityGeneratorInterface $security;
    private GateRendererInterface $renderer;

    public function __construct(
        array $config,
        ?SessionHandlerInterface $session = null,
        ?SecurityGeneratorInterface $security = null,
        ?GateRendererInterface $renderer = null
    ) {
        $this->validateConfig($config);
        $this->config   = $config;
        $this->session  = $session ?? new NativeSessionHandler();
        $this->security = $security ?? new StandardSecurityGenerator();
        $this->renderer = $renderer ?? new StandardGateRenderer();
    }

    public function getUser(): User
    {
        if ($this->session->get('aw_verified') === true) {
            return new User(true, $this->session->get('aw_claims', []));
        }

        if (!empty($_COOKIE['aw_verified_token'])) {
            $payload = $this->verifySignedCookie($_COOKIE['aw_verified_token']);
            if ($payload) {
                $this->session->set('aw_verified', true);
                return new User(true, $payload);
            }
        }

        return new User(false);
    }

    public function renderer(): GateRendererInterface
    {
        return $this->renderer;
    }

    // ------------------------------------------------------------------------
    // GATING METHODS
    // ------------------------------------------------------------------------

    public function isVerified(): bool
    {
        return $this->getUser()->isVerified();
    }

    public function protect(callable $protectedContent, ?callable $gateContent = null): string
    {
        if ($this->isVerified()) {
            return (string) $protectedContent();
        }

        if ($gateContent) {
            return (string) $gateContent();
        }

        $authData = $this->beginFlow();
        return $this->renderer->render($authData['url']);
    }

    /**
     * The Guard.
     * Renders the gate and stops execution if unverified.
     * Prevents any content below this call from loading.
     */
    public function guard(?string $returnUrl = null): void
    {
        if ($this->isVerified()) {
            return;
        }

        // Generate Auth URL
        $authData = $this->beginFlow($returnUrl);

        // Render the Gate UI with the 'is_guard' flag
        // This tells the renderer to take over the full page styling (black background)
        echo $this->renderer->render($authData['url'], ['is_guard' => true]);

        // Stop execution immediately (Hard Gating)
        exit;
    }

    public function bufferStart(): void
    {
        ob_start();
    }

    public function bufferEnd(): void
    {
        $content = ob_get_clean();

        if ($this->isVerified()) {
            echo $content;
        } else {
            $authData = $this->beginFlow();
            echo $this->renderer->render($authData['url']);
        }
    }

    // ------------------------------------------------------------------------
    // OIDC FLOW LOGIC
    // ------------------------------------------------------------------------

    public function beginFlow(?string $returnUrl = null): array
    {
        $state = $this->security->generateRandomString(16);
        $nonce = $this->security->generateRandomString(16);
        $verifier = $this->security->generatePkceVerifier();
        $challenge = $this->security->generatePkceChallenge($verifier);

        $this->session->set('aw_oidc_state', [
            'state' => $state,
            'nonce' => $nonce,
            'verifier' => $verifier,
            'return_url' => $returnUrl ?? $this->getCurrentUrl()
        ]);

        $params = [
            'response_type' => 'code',
            'client_id' => $this->config['client_id'],
            'redirect_uri' => $this->config['redirect_uri'],
            'scope' => 'openid age',
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'nonce' => $nonce
        ];

        $url = self::AUTH_ENDPOINT . '?' . http_build_query($params);

        return ['url' => $url, 'state' => $state];
    }

    public function authenticate(): User
    {
        $storedData = $this->session->get('aw_oidc_state');
        if (!$storedData) {
            throw new AgeWalletException('No verification session found. Please try again.');
        }

        $inputState = $_GET['state'] ?? '';
        if (!hash_equals($storedData['state'], $inputState)) {
            throw new AgeWalletException('Invalid state parameter.');
        }

        if (isset($_GET['error'])) {
            if ($_GET['error'] === 'access_denied' &&
                ($_GET['error_description'] ?? '') === 'Region does not require verification') {
                $this->setVerifiedState([]);
                return $this->getUser();
            }
            throw new AgeWalletException('Verification failed: ' . ($_GET['error_description'] ?? $_GET['error']));
        }

        $code = $_GET['code'] ?? null;
        if (!$code) {
            throw new AgeWalletException('Missing authorization code.');
        }

        $tokens = $this->exchangeCode($code, $storedData['verifier']);
        $userInfo = $this->fetchUserInfo($tokens['access_token']);

        if (($userInfo['age_verified'] ?? false) !== true) {
            throw new AgeWalletException('Age verification requirements were not met.');
        }

        $this->setVerifiedState($userInfo);
        $this->session->remove('aw_oidc_state');

        return $this->getUser();
    }

    public function getReturnUrl(): string
    {
        $data = $this->session->get('aw_oidc_state');
        return $data['return_url'] ?? '/';
    }

    // ------------------------------------------------------------------------
    // INTERNAL HELPERS
    // ------------------------------------------------------------------------

    private function exchangeCode(string $code, string $verifier): array
    {
        $ch = curl_init(self::TOKEN_ENDPOINT);
        $postData = [
            'grant_type' => 'authorization_code',
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'redirect_uri' => $this->config['redirect_uri'],
            'code' => $code,
            'code_verifier' => $verifier
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($postData)
        ]);

        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status !== 200) {
            throw new AgeWalletException('Token exchange failed. HTTP ' . $status);
        }

        return json_decode($response, true);
    }

    private function fetchUserInfo(string $accessToken): array
    {
        $ch = curl_init(self::USERINFO_ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken]
        ]);

        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status !== 200) {
            throw new AgeWalletException('User info fetch failed. HTTP ' . $status);
        }

        return json_decode($response, true);
    }

    private function setVerifiedState(array $claims): void
    {
        $this->session->set('aw_verified', true);
        $this->session->set('aw_claims', $claims);

        if (!empty($this->config['hmac_secret'])) {
            $payload = json_encode(['exp' => time() + 86400, 'sub' => $claims['sub'] ?? 'anon']);
            $sig = hash_hmac('sha256', base64_encode($payload), $this->config['hmac_secret']);
            $cookieVal = base64_encode($payload) . '.' . $sig;
            setcookie('aw_verified_token', $cookieVal, time() + 86400, '/', '', true, true);
        }
    }

    private function verifySignedCookie(string $value): ?array
    {
        if (empty($this->config['hmac_secret'])) return null;

        $parts = explode('.', $value);
        if (count($parts) !== 2) return null;

        [$b64Payload, $sig] = $parts;
        $expectedSig = hash_hmac('sha256', $b64Payload, $this->config['hmac_secret']);

        if (!hash_equals($expectedSig, $sig)) return null;

        $payload = json_decode(base64_decode($b64Payload), true);
        if (($payload['exp'] ?? 0) < time()) return null;

        return $payload;
    }

   private function getCurrentUrl(): string
    {
        if (php_sapi_name() === 'cli') {
            return 'http://localhost/cli-context';
        }
        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        return "{$protocol}://{$host}{$uri}";
    }

    private function validateConfig(array $config): void
    {
        $required = ['client_id', 'client_secret', 'redirect_uri'];
        foreach ($required as $key) {
            if (empty($config[$key])) {
                throw new AgeWalletException("Missing configuration key: {$key}");
            }
        }
    }
}