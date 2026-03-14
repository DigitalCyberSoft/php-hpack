--TEST--
HPackContext::encode() handles IS_REFERENCE zvals from by-reference foreach
--EXTENSIONS--
hpack
--FILE--
<?php

/*
 * When a PHP array is iterated with foreach(&$val), each element becomes
 * an IS_REFERENCE zval. The C extension must call ZVAL_DEREF() before
 * checking types or accessing values, otherwise it hangs or misreads data.
 */

$ctx = new HPackContext(4096);
$headers = [
    [":method", "GET"],
    [":authority", "example.com"],
    [":scheme", "https"],
    [":path", "/test"],
    ["user-agent", "php-test"],
];

// Create IS_REFERENCE zvals via by-reference foreach
foreach ($headers as &$header) {
    $header[0] = (string) $header[0];
    $header[1] = (string) $header[1];
}
unset($header);

$encoded = $ctx->encode($headers);
echo "Encoded: " . (strlen($encoded) > 0 ? "ok" : "empty") . "\n";

$dec = new HPackContext(4096);
$decoded = $dec->decode($encoded, 8192);
echo "Decoded: " . count($decoded) . " headers\n";

// Verify round-trip
$match = true;
for ($i = 0; $i < count($headers); $i++) {
    if ($headers[$i][0] !== $decoded[$i][0] || $headers[$i][1] !== $decoded[$i][1]) {
        $match = false;
        echo "Mismatch at $i: expected [{$headers[$i][0]}: {$headers[$i][1]}], got [{$decoded[$i][0]}: {$decoded[$i][1]}]\n";
    }
}
echo "Round-trip: " . ($match ? "ok" : "FAIL") . "\n";

// Also test with references on inner name/value elements
$headers2 = [":status", "200"];
$ref = &$headers2[0];
$ref2 = &$headers2[1];

$ctx2 = new HPackContext();
$encoded2 = $ctx2->encode([$headers2]);
$dec2 = new HPackContext();
$decoded2 = $dec2->decode($encoded2, 8192);
echo "Inner refs: " . ($decoded2[0][0] === ':status' && $decoded2[0][1] === '200' ? "ok" : "FAIL") . "\n";

echo "OK\n";
?>
--EXPECT--
Encoded: ok
Decoded: 5 headers
Round-trip: ok
Inner refs: ok
OK
