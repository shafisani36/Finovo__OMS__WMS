<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * An http(s) address on the public internet. Addresses that lead into the server's own network (localhost,
 * private and reserved ranges, cloud metadata) are refused, so Finovo cannot be used to reach internal services.
 * Turned off with BLOCK_PRIVATE_HOSTS=false (e.g. to connect a store running on a developer's machine).
 */
class PublicUrl implements ValidationRule
{
    /**
     * @param  bool  $hostOnly  the value is a bare host name or address (e.g. a database host)
     */
    public function __construct(private bool $hostOnly = false) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && ! self::allows($this->hostOnly ? "http://{$value}" : $value)) {
            $fail('Enter a public internet address; addresses inside a private network are not allowed.');
        }
    }

    public static function allows(string $url): bool
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower(trim($parts['host'] ?? '', '[]'));

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return false;
        }

        if (! config('app.block_private_hosts')) {
            return true;
        }

        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.internal')) {
            return false;
        }

        // A name that does not resolve cannot be reached either; only the addresses it resolves to are checked.
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);

        foreach ($addresses as $address) {
            if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }

        return true;
    }
}
