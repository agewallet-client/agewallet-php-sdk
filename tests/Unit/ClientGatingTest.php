<?php

declare(strict_types=1);

namespace AgeWallet\Sdk\Tests\Unit;

use AgeWallet\Sdk\Client;
use AgeWallet\Sdk\Tests\Mocks\InMemorySessionHandler;
use AgeWallet\Sdk\Tests\Mocks\MockSecurityGenerator;
use PHPUnit\Framework\TestCase;

class ClientGatingTest extends TestCase
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
    }

    public function testProtectExecutesContentWhenVerified(): void
    {
        $this->session->set('aw_verified', true);
        $client = new Client($this->config, $this->session, $this->security);

        $result = $client->protect(function() { return "Secret Content"; });
        $this->assertEquals("Secret Content", $result);
    }

    public function testProtectShowsGateWhenUnverified(): void
    {
        $client = new Client($this->config, $this->session, $this->security);
        $result = $client->protect(function() { return "Should Not Run"; });

        $this->assertStringContainsString('aw-gate-wrapper', $result);
        // FIX: Expect URL encoded redirect_uri
        $this->assertStringContainsString('http%3A%2F%2Flocalhost%2Fcallback', $result);
    }

    public function testGuardStopsExecutionWhenUnverified(): void
    {
        $client = $this->getMockBuilder(Client::class)
                       ->setConstructorArgs([$this->config, $this->session, $this->security])
                       ->onlyMethods(['terminate'])
                       ->getMock();

        $client->expects($this->once())->method('terminate');
        $this->expectOutputRegex('/aw-gate-wrapper/');
        $client->guard();
    }

    public function testGuardAllowsExecutionWhenVerified(): void
    {
        $this->session->set('aw_verified', true);
        $client = $this->getMockBuilder(Client::class)
                       ->setConstructorArgs([$this->config, $this->session, $this->security])
                       ->onlyMethods(['terminate'])
                       ->getMock();

        $client->expects($this->never())->method('terminate');
        ob_start();
        $client->guard();
        $output = ob_get_clean();
        $this->assertEmpty($output);
    }

    public function testBufferingOutputsContentWhenVerified(): void
    {
        $this->session->set('aw_verified', true);
        $client = new Client($this->config, $this->session, $this->security);

        $client->bufferStart();
        echo "Buffered Content";
        ob_start();
        $client->bufferEnd();
        $output = ob_get_clean();

        $this->assertEquals("Buffered Content", $output);
    }

    public function testBufferingOutputsGateWhenUnverified(): void
    {
        $client = new Client($this->config, $this->session, $this->security);

        // FIX: Wrap the whole sequence to capture what bufferEnd outputs to the top level
        ob_start();

        $client->bufferStart();
        echo "Should Be Hidden"; // This goes into SDK buffer
        $client->bufferEnd();    // This cleans SDK buffer (discarding content) and echoes Gate

        $output = ob_get_clean(); // This captures the Gate

        $this->assertStringNotContainsString("Should Be Hidden", $output);
        $this->assertStringContainsString('aw-gate-wrapper', $output);
    }
}