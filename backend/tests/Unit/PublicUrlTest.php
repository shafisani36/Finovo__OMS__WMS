<?php

namespace Tests\Unit;

use App\Rules\PublicUrl;
use Tests\TestCase;

/**
 * Addresses Finovo may call (stores, couriers, product photos): never into the server's own network.
 */
class PublicUrlTest extends TestCase
{
    public function test_private_and_reserved_addresses_are_refused(): void
    {
        config(['app.block_private_hosts' => true]);

        foreach (['http://localhost/admin', 'http://api.localhost', 'http://127.0.0.1:8001', 'https://10.0.0.5/x', 'http://169.254.169.254/latest/meta-data', 'http://[::1]/', 'http://192.168.1.10', 'ftp://example.com/a.png', 'not a url'] as $url) {
            $this->assertFalse(PublicUrl::allows($url), $url);
        }

        $this->assertTrue(PublicUrl::allows('https://8.8.8.8/'));
        $this->assertTrue(PublicUrl::allows('https://cdn.shopify.com/s/files/photo.jpg'));
    }

    public function test_the_check_can_be_turned_off_for_local_development(): void
    {
        config(['app.block_private_hosts' => false]);

        $this->assertTrue(PublicUrl::allows('http://localhost/wordpress'));
        $this->assertFalse(PublicUrl::allows('ftp://localhost/file'));
    }

    public function test_a_database_host_is_checked_as_a_host(): void
    {
        config(['app.block_private_hosts' => true]);
        $failed = null;

        (new PublicUrl(hostOnly: true))->validate('db_host', '127.0.0.1', function (string $message) use (&$failed) {
            $failed = $message;
        });

        $this->assertNotNull($failed);
    }
}
