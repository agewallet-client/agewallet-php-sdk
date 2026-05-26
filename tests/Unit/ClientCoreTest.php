<?php

declare(strict_types=1);

namespace AgeWallet\Sdk\Tests\Unit;

use AgeWallet\Sdk\Client;
use AgeWallet\Sdk\Exceptions\AgeWalletException;
use AgeWallet\Sdk\Tests\Mocks\InMemorySessionHandler;
use AgeWallet\Sdk\Tests\Mocks\MockSecurityGenerator;
use PHPUnit\Framework\TestCase;

class ClientCoreTest extends TestCase
{
    private InMemorySessionHandler $session;
    private MockSecurityGenerator $security;
    private array $config;

    protected function setUp(): void
    {
        $this->session = new InMemorySessionHandler();
        $this->security = new MockSecurityGenerator();
        $this->config = [
            'client_id' => 'test-id',
            'client_secret' => 'test-secret',
            'redirect_uri' => 'http://localhost/callback',
            'hmac_secret' => 'test-hmac'
        ];

        // Clear superglobals before each test
        $_GET = [];
        $_COOKIE = [];
    }

    public function testBeginFlowStoresStateAndReturnsUrl(): void
    {
        $client = new Client($this->config, $this->session, $this->security);

        $result = $client->beginFlow();

        // 1. Assert URL construction (Client ID, Scope, Method)
        $this->assertStringContainsString('https://app.agewallet.io/user/authorize', $result['url']);
        $this->assertStringContainsString('client_id=test-id', $result['url']);
        $this->assertStringContainsString('scope=openid+age', $result['url']);

        // 2. Assert Security Params (from MockGenerator)
        $this->assertStringContainsString('state=test-random-string-fixed', $result['url']);
        $this->assertStringContainsString('code_challenge_method=S256', $result['url']);

        // 3. Assert Session Storage
        $stored = $this->session->get('aw_oidc_state');
        $this->assertIsArray($stored);
        $this->assertEquals('test-random-string-fixed', $stored['state']);
        $this->assertEquals('test-pkce-verifier-fixed-value', $stored['verifier']);
    }

    public function testAuthenticateThrowsExceptionIfNoSessionState(): void
    {
        $client = new Client($this->config, $this->session, $this->security);

        // We simulate a callback without having started the flow
        $_GET['state'] = 'some-state';
        $_GET['code'] = 'some-code';

        $this->expectException(AgeWalletException::class);
        $this->expectExceptionMessage('No verification session found');

        $client->authenticate();
    }

    public function testAuthenticateThrowsExceptionIfStateMismatch(): void
    {
        // 1. Setup Valid Session
        $this->session->set('aw_oidc_state', ['state' => 'valid-state']);

        // 2. Setup Malicious Input (CSRF Attempt)
        $_GET['state'] = 'evil-state';

        $client = new Client($this->config, $this->session, $this->security);

        $this->expectException(AgeWalletException::class);
        $this->expectExceptionMessage('Invalid state parameter');

        $client->authenticate();
    }

    public function testAuthenticateHandlesRegionalExemption(): void
    {
        // 1. Setup Session
        $this->session->set('aw_oidc_state', [
            'state' => 'valid-state',
            'verifier' => 'v',
            'nonce' => 'n'
        ]);

        // 2. Setup Exemption Input (Standard OIDC Error for Exemption)
        $_GET['state'] = 'valid-state';
        $_GET['error'] = 'access_denied';
        $_GET['error_description'] = 'Region does not require verification';

        // PARTIAL MOCK: Prevent setcookie header errors
        $client = $this->getMockBuilder(Client::class)
                       ->setConstructorArgs([$this->config, $this->session, $this->security])
                       ->onlyMethods(['setCookie'])
                       ->getMock();

        // Expect setCookie to be called once
        $client->expects($this->once())
               ->method('setCookie')
               ->willReturn(true);

        $user = $client->authenticate();

        // 4. Assert Verified State
        $this->assertTrue($user->isVerified());
        $this->assertTrue($this->session->get('aw_verified'));
    }

    public function testAuthenticateSuccessFlow(): void
    {
        // 1. Setup Session
        $this->session->set('aw_oidc_state', [
            'state' => 'valid-state',
            'verifier' => 'v',
            'nonce' => 'n'
        ]);

        // 2. Setup Input
        $_GET['state'] = 'valid-state';
        $_GET['code'] = 'valid-code';

        // 3. Mock Client (HTTP Seam + Cookie Seam)
        $client = $this->getMockBuilder(Client::class)
                       ->setConstructorArgs([$this->config, $this->session, $this->security])
                       ->onlyMethods(['sendRequest', 'setCookie'])
                       ->getMock();

        // 4. Expect HTTP Requests
        $client->expects($this->exactly(2))
               ->method('sendRequest')
               ->withConsecutive(
                   // 1. Token Exchange
                   [$this->stringContains(Client::TOKEN_ENDPOINT), $this->anything()],
                   // 2. User Info
                   [$this->stringContains(Client::USERINFO_ENDPOINT), $this->anything()]
               )
               ->willReturnOnConsecutiveCalls(
                   // Response 1
                   ['status' => 200, 'body' => json_encode(['access_token' => 'mock-token', 'token_type' => 'Bearer'])],
                   // Response 2
                   ['status' => 200, 'body' => json_encode(['sub' => 'mock-user-id', 'age_verified' => true])]
               );

        // 5. Expect Cookie Set
        $client->expects($this->once())->method('setCookie')->willReturn(true);

        // 6. Execute
        $user = $client->authenticate();

        // 7. Assert
        $this->assertTrue($user->isVerified());
        $this->assertEquals('mock-user-id', $user->getSubject());
        $this->assertArrayNotHasKey('aw_oidc_state', $this->session->dump(), 'State should be cleared after success');
    }

    public function testGetUserReturnsUnverifiedByDefault(): void
    {
        $client = new Client($this->config, $this->session, $this->security);
        $user = $client->getUser();

        $this->assertFalse($user->isVerified());
    }

    public function testGetUserReturnsVerifiedIfSessionExists(): void
    {
        // Simulate already logged in user
        $this->session->set('aw_verified', true);
        $this->session->set('aw_claims', ['sub' => 'user-123']);

        $client = new Client($this->config, $this->session, $this->security);
        $user = $client->getUser();

        $this->assertTrue($user->isVerified());
        $this->assertEquals('user-123', $user->getSubject());
    }

    // ---- Metadata feature ----

    public function testBeginFlowAppendsMetadataFromConfig(): void
    {
        $config = $this->config + ['metadata' => 'order-9001'];
        $client = new Client($config, $this->session, $this->security);

        $result = $client->beginFlow();

        $this->assertStringContainsString('metadata=order-9001', $result['url']);
    }

    public function testBeginFlowOmitsMetadataWhenUnset(): void
    {
        $client = new Client($this->config, $this->session, $this->security);

        $result = $client->beginFlow();

        $this->assertStringNotContainsString('metadata=', $result['url']);
    }

    public function testBeginFlowPerCallOverrideWins(): void
    {
        $config = $this->config + ['metadata' => 'instance-default'];
        $client = new Client($config, $this->session, $this->security);

        $result = $client->beginFlow(null, ['metadata' => 'per-call-value']);

        $this->assertStringContainsString('metadata=per-call-value', $result['url']);
        $this->assertStringNotContainsString('instance-default', $result['url']);
    }

    public function testSetMetadataMutatesDefault(): void
    {
        $client = new Client($this->config, $this->session, $this->security);
        $client->setMetadata('new-value');

        $result = $client->beginFlow();

        $this->assertStringContainsString('metadata=new-value', $result['url']);
    }

    public function testSetMetadataNullClearsValue(): void
    {
        $config = $this->config + ['metadata' => 'will-be-cleared'];
        $client = new Client($config, $this->session, $this->security);
        $client->setMetadata(null);

        $result = $client->beginFlow();

        $this->assertStringNotContainsString('metadata=', $result['url']);
    }

    public function testConstructorRejectsOversizedMetadata(): void
    {
        $oversized = str_repeat('a', Client::METADATA_MAX_BYTES + 1);
        $this->expectException(AgeWalletException::class);
        $this->expectExceptionMessage('exceeds');

        new Client($this->config + ['metadata' => $oversized], $this->session, $this->security);
    }

    public function testSetMetadataRejectsOversized(): void
    {
        $client = new Client($this->config, $this->session, $this->security);
        $oversized = str_repeat('a', Client::METADATA_MAX_BYTES + 1);

        $this->expectException(AgeWalletException::class);
        $client->setMetadata($oversized);
    }

    public function testBeginFlowRejectsOversizedOverride(): void
    {
        $client = new Client($this->config, $this->session, $this->security);
        $oversized = str_repeat('a', Client::METADATA_MAX_BYTES + 1);

        $this->expectException(AgeWalletException::class);
        $client->beginFlow(null, ['metadata' => $oversized]);
    }

    public function testGetMetadataReturnsNullWhenNoVerification(): void
    {
        $client = new Client($this->config, $this->session, $this->security);

        $this->assertNull($client->getMetadata());
    }

    public function testGetMetadataReturnsStringFromClaims(): void
    {
        $this->session->set('aw_verified', true);
        $this->session->set('aw_claims', ['sub' => 'u', 'metadata' => 'persisted-md']);

        $client = new Client($this->config, $this->session, $this->security);

        $this->assertSame('persisted-md', $client->getMetadata());
    }
}