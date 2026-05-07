<?php

if (!defined('ABSPATH')) {
    exit;
}

return [
    'migrations' => [
        '0.0.29' => function (): bool {
            $publicKey = get_option('universally_public_api_key', '');
            $privateKey = get_option('universally_private_api_key', '');

            // Skip if old keys don't exist or new key already set
            if (empty($publicKey) || empty($privateKey) || get_option('universally_api_key', '')) {
                return true;
            }

            // Strip pk_ and sk_ prefixes
            $public = substr($publicKey, 3);
            $private = substr($privateKey, 3);

            // Save combined key and clean up old options
            update_option('universally_api_key', $public . $private);
            delete_option('universally_public_api_key');
            delete_option('universally_private_api_key');

            return true;
        },
    ],
];