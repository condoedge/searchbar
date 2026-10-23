<?php

/**
 * Payloads the server hands to the browser and later unserializes (rules in chip links) are signed: an unsigned one
 * would let a client unserialize any class (PHP object injection).
 */
function searchbarSign(string $payload): string
{
    return $payload . '.' . hash_hmac('sha256', $payload, searchbarSigningKey());
}

/** The signed payload, or null when it is missing or was not produced by this app. */
function searchbarVerify($signed): ?string
{
    if (!is_string($signed) || ($dot = strrpos($signed, '.')) === false) {
        return null;
    }

    $payload = substr($signed, 0, $dot);

    return hash_equals(hash_hmac('sha256', $payload, searchbarSigningKey()), substr($signed, $dot + 1)) ? $payload : null;
}

function searchbarSigningKey(): string
{
    // Without APP_KEY the key would be a public constant: signatures forgeable, payloads unserialized.
    if (empty(config('app.key'))) {
        throw new \RuntimeException('The searchbar signs its payloads with APP_KEY, which is not set.');
    }

    return hash('sha256', 'kompo-searchbar|' . config('app.key'), true);
}
