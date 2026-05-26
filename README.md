# AgeWallet PHP SDK

The official, zero-dependency PHP SDK for integrating AgeWallet Age Verification into any PHP application.

Designed for versatility, it supports everything from modern frameworks (Laravel, Symfony) to legacy custom PHP sites.

## Features

- **Zero Dependencies:** No Guzzle, no complex vendor libraries. Runs on native PHP extensions.
- **Secure by Default:** Handles PKCE (S256), State, and Nonce generation automatically.
- **Flexible Gating:** Provides 4 different ways to gate content (Conditionals, Closures, Full-Page Guards, and Output Buffering).
- **Customizable UI:** Includes a Standard Gate Renderer with light customization options (Logo/Text) and supports full UI replacement via Interfaces.
- **Framework Agnostic:** Configurable Session and Security interfaces allow easy integration with Redis, Databases, or custom frameworks.

## Requirements

- PHP 7.4 or higher
- `ext-json`
- `ext-curl`

## Installation

### Option 1: Composer (Recommended)

Run the following command in your project root:

    composer require agewallet/php-sdk

### Option 2: Manual Installation (No Composer)

1. Download the latest release `.zip` and extract it to your project (e.g., into a folder named `agewallet-sdk`).
2. Include the `autoload.php` file at the top of your script:

    `require_once __DIR__ . '/agewallet-sdk/autoload.php';`

## Configuration

Initialize the Client once in your application's bootstrap file (e.g., `init.php` or `config.php`).

    use AgeWallet\Sdk\Client;

    $config = [
        'client_id'     => 'YOUR_AGEWALLET_CLIENT_ID',
        'client_secret' => 'YOUR_AGEWALLET_CLIENT_SECRET',
        'redirect_uri'  => 'https://yoursite.com/auth/callback', // Must match AgeWallet Dashboard
        'hmac_secret'   => 'YOUR_RANDOM_LONG_STRING', // Used to sign the verification cookie
    ];

    // Initialize the SDK
    $ageWallet = new Client($config);

### Important: The Callback Handler

You must create a file at your `redirect_uri` (e.g., `auth/callback.php`) to handle the return from AgeWallet:

    // auth/callback.php
    require 'init.php'; // Your bootstrap file containing the $ageWallet instance

    try {
        // Exchanges the code, verifies the token, and sets the session/cookie
        $user = $ageWallet->authenticate();

        // Redirect back to where the user came from
        header('Location: ' . $ageWallet->getReturnUrl());
        exit;
    } catch (Exception $e) {
        die("Verification Failed: " . $e->getMessage());
    }

## Usage: The 4 Ways to Gate Content

Choose the method that best fits your coding style and file structure.

### Option A: Conditional Logic (Granular Control)

Best for: Customizing the UI based on verification status (e.g., hiding a specific button).

    if ($ageWallet->isVerified()) {
        echo '<button class="btn-buy">Add to Cart (18+)</button>';
    } else {
        // Manually render the gate inline
        $authData = $ageWallet->beginFlow();
        echo $ageWallet->renderer()->render($authData['url']);
    }

### Option B: The Content Wrapper (Modern/Clean)

Best for: Protecting specific blocks of code/content without leaking variables. Automatically renders the AgeWallet Gate UI if unverified.

    // The content inside the function only runs if the user is verified.
    echo $ageWallet->protect(function() {
        return '<video src="secure-movie.mp4" controls></video>';
    });

### Option C: The Guard (Full Page Protection)

Best for: Protecting entire files (e.g., `download.php` or `adult-gallery.php`). **Note:** If the user is unverified, this method stops script execution immediately and renders the full-page gate.

    <?php
    require 'init.php';

    // Stop right here if not verified
    $ageWallet->guard();

    // --- SAFE ZONE ---
    // Anything below this line is only accessible to 18+ users.
    readfile('/secure/files/report.pdf');

### Option D: Output Buffering (Legacy/Template Friendly)

Best for: Wrapping large chunks of HTML in older PHP files where closures are annoying to write.

    <?php $ageWallet->bufferStart(); ?>

    <div class="hero">
        <h1>Welcome to the VIP Lounge</h1>
        <img src="adult-banner.jpg">
        </div>

    <?php $ageWallet->bufferEnd(); ?>

## Customizing the Gate UI

### Level 1: Light Customization (Configuration)

You can customize the text and logo of the Standard Renderer by passing an options array to the `render()` method. Note: This works best with manual rendering (Option A) or by extending the renderer.

    echo $ageWallet->renderer()->render($authUrl, [
        'title'       => 'Restricted Area',
        'message'     => 'Please verify your age to continue.',
        'button_text' => 'Verify Now',
        'logo_src'    => 'https://mysite.com/logo.png', // URL or Base64 Data URI
        'logo_width'  => 150
    ]);

### Level 2: Full Customization (Custom Renderer)

For complete control over HTML and CSS (e.g., to use Bootstrap, Tailwind, or multi-language support), create your own Renderer class.

1. Create a class that implements `AgeWallet\Sdk\Interfaces\GateRendererInterface`.
2. Inject it into the Client constructor (4th argument).

    class MyCustomGate implements \AgeWallet\Sdk\Interfaces\GateRendererInterface {
        public function render(string $authUrl, array $options = []): string {
             return `<div class="my-gate"><a href="'.$authUrl.'">Verify Me</a></div>`;
        }
    }

    // Inject into Client
    $ageWallet = new Client($config, null, null, new MyCustomGate());

## Advanced Usage

### Attaching Metadata to a Verification

You can attach an opaque per-verification string (up to 4096 bytes) that round-trips through the OIDC flow and is stored alongside the verification record on AgeWallet's side. Useful for tying a verification to an order ID, cart hash, internal user ID, or any audit token your application needs.

There are three ways to supply the value, from least to most specific:

    // 1. Instance default via config — used by every beginFlow() call on this client.
    $ageWallet = new Client([
        'client_id'     => '...',
        'client_secret' => '...',
        'redirect_uri'  => '...',
        'metadata'      => 'tenant-abc',
    ]);

    // 2. Mutate the default at runtime — useful when the value isn't known at construct time.
    $ageWallet->setMetadata('order-' . $order->id);

    // 3. Per-call override — does NOT change the instance default.
    $authData = $ageWallet->beginFlow($returnUrl, ['metadata' => 'one-off-value']);

After `authenticate()` completes, read the metadata back from the persisted verification:

    $md = $ageWallet->getMetadata(); // string|null

Validation: metadata must be a string ≤ `Client::METADATA_MAX_BYTES` (4096). Invalid values throw `AgeWalletException` immediately.

### Custom Session Storage

If your application uses Redis, Memcached, or a Database for sessions (instead of PHP's default `$_SESSION`), you can override the session handler.

1. Create a class that implements `AgeWallet\Sdk\Interfaces\SessionHandlerInterface`.
2. Pass it to the constructor (2nd argument).

    class MyRedisSession implements \AgeWallet\Sdk\Interfaces\SessionHandlerInterface {
        // Implement set(), get(), has(), remove() using your Redis logic
    }

    $ageWallet = new Client($config, new MyRedisSession());

### Headless / API Mode (LocalStorage)

By default, the SDK uses Cookies because they are secure and work immediately on page load. If you are building a **Single Page App (SPA)** and want to store tokens in **LocalStorage**, the browser must send the token to your PHP API in a header.

To support this, create a custom Session Handler that reads from HTTP Headers:

    class HeaderSessionHandler implements \AgeWallet\Sdk\Interfaces\SessionHandlerInterface {
        public function get(string $key, $default = null) {
            // If checking verification status, look for the Bearer token
            if ($key === 'aw_verified') {
                $headers = getallheaders();
                $token = $headers['Authorization'] ?? '';
                // Validate $token logic here...
                return $isValid;
            }
            return $default;
        }
        // ...
    }

## Testing

This SDK includes a comprehensive PHPUnit test suite. To run the tests:

1. Install development dependencies:

   composer install

2. Run the test runner:
    ./vendor/bin/phpunit

## Demos & Examples

This repository includes a complete demo suite in the `examples/` directory, covering all integration methods (Conditional, Protect, Guard, Buffering).

To run the demos locally:

1. Open `examples/init.php` and add your AgeWallet API credentials.
2. Start the built-in PHP server:

   php -S localhost:8000 -t examples/

3. Visit http://localhost:8000 in your browser.
