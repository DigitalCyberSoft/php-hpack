--TEST--
HPackContext basic encode/decode
--EXTENSIONS--
hpack
--FILE--
<?php

$ctx = new HPackContext();

// Encode standard HTTP/2 headers
$headers = [
    [":method", "GET"],
    [":path", "/"],
    [":scheme", "https"],
    [":authority", "example.com"],
    ["user-agent", "php-test"],
    ["accept", "*/*"],
];

$encoded = $ctx->encode($headers);
echo "Encoded length: " . strlen($encoded) . "\n";
echo "Encoded is binary: " . (strlen($encoded) > 0 ? "yes" : "no") . "\n";

// Decode with a fresh context
$ctx2 = new HPackContext();
$decoded = $ctx2->decode($encoded, 8192);

echo "Decoded count: " . count($decoded) . "\n";

foreach ($decoded as $i => [$name, $value]) {
    echo "$name: $value\n";
}

// Test empty headers
$empty = $ctx->encode([]);
echo "Empty encode: " . strlen($empty) . " bytes\n";

// Test max size enforcement on decode
$ctx3 = new HPackContext();
$result = $ctx3->decode($encoded, 5); // very small max
echo "Small max decode: " . ($result === null ? "null" : "not null") . "\n";

echo "OK\n";
?>
--EXPECT--
Encoded length: 26
Encoded is binary: yes
Decoded count: 6
:method: GET
:path: /
:scheme: https
:authority: example.com
user-agent: php-test
accept: */*
Empty encode: 0 bytes
Small max decode: null
OK
