<?php

// 1. Load the SDK
require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/Mocks/InMemorySessionHandler.php';
require_once __DIR__ . '/Mocks/MockSecurityGenerator.php';

use AgeWallet\Sdk\Client;
use AgeWallet\Sdk\Tests\Mocks\InMemorySessionHandler;
use AgeWallet\Sdk\Tests\Mocks\MockSecurityGenerator;

// 2. Setup Mocks & Config
$sessionMock = new InMemorySessionHandler();
$securityMock = new MockSecurityGenerator();

$config = [
    'client_id'     => 'test-client-id',
    'client_secret' => 'test-client-secret',
    'redirect_uri'  => 'http://localhost/callback',
    'hmac_secret'   => 'test-secret-key'
];

echo "--------------------------------------------------\n";
echo "Running AgeWallet SDK Integration Test\n";
echo "--------------------------------------------------\n\n";

try {
    // 3. Initialize Client
    echo "[1] Initializing Client... ";
    $client = new Client($config, $sessionMock, $securityMock);
    echo "OK\n";

    // 4. Test: User should initially be unverified
    echo "[2] Checking initial state... ";
    if ($client->isVerified()) {
        die("FAIL: User should not be verified yet.\n");
    }
    echo "OK (Unverified)\n";

    // 5. Test: Begin Flow (Should generate URL and store state)
    echo "[3] Starting OIDC Flow... ";
    $authData = $client->beginFlow();

    if (empty($authData['url'])) {
        die("FAIL: Auth URL not generated.\n");
    }
    if ($authData['state'] !== 'test-random-string-fixed') {
        die("FAIL: State does not match mock generator.\n");
    }
    echo "OK\n";
    echo "    -> Generated URL: " . substr($authData['url'], 0, 50) . "...\n";

    // 6. Test: Verify Session Storage
    echo "[4] Verifying Session Storage... ";
    $storedState = $sessionMock->get('aw_oidc_state');

    if (!$storedState) {
        die("FAIL: Session is empty.\n");
    }
    if ($storedState['verifier'] !== 'test-pkce-verifier-fixed-value') {
        die("FAIL: PKCE Verifier was not stored correctly.\n");
    }
    echo "OK\n";

    // 7. Test: Simulate Successful Verification
    echo "[5] Simulating Verified Session... ";
    // Manually inject "Verified" state into our mock session
    $sessionMock->set('aw_verified', true);
    $sessionMock->set('aw_claims', ['sub' => 'user-123', 'age_verified' => true]);

    if (!$client->isVerified()) {
        die("FAIL: Client did not read verified state from session.\n");
    }
    echo "OK\n";

    echo "\n--------------------------------------------------\n";
    echo "ALL TESTS PASSED SUCCESSFULLY ✅\n";
    echo "--------------------------------------------------\n";

} catch (Exception $e) {
    echo "\nCRITICAL ERROR: " . $e->getMessage() . "\n";
    exit(1);
}