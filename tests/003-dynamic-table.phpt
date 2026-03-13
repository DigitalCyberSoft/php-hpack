--TEST--
HPackContext dynamic table state across encode/decode calls
--EXTENSIONS--
hpack
--FILE--
<?php

// Encoder uses dynamic table - subsequent encodes should be smaller
$enc = new HPackContext(4096);
$dec = new HPackContext(4096);

$headers = [
    [":method", "GET"],
    [":path", "/api/v1/users"],
    [":scheme", "https"],
    [":authority", "api.example.com"],
    ["authorization", "Bearer token123"],
];

$first = $enc->encode($headers);
$decoded1 = $dec->decode($first, 8192);
echo "First decode count: " . count($decoded1) . "\n";

// Same headers again - nghttp2 uses dynamic table, may be smaller
$second = $enc->encode($headers);
$decoded2 = $dec->decode($second, 8192);
echo "Second decode count: " . count($decoded2) . "\n";
echo "Second encoding smaller: " . (strlen($second) <= strlen($first) ? "yes" : "no") . "\n";

// Verify content matches
$match = true;
for ($i = 0; $i < count($headers); $i++) {
    if ($decoded2[$i][0] !== $headers[$i][0] || $decoded2[$i][1] !== $headers[$i][1]) {
        $match = false;
        echo "Mismatch at $i: expected [{$headers[$i][0]}: {$headers[$i][1]}], got [{$decoded2[$i][0]}: {$decoded2[$i][1]}]\n";
    }
}
echo "Content match: " . ($match ? "PASS" : "FAIL") . "\n";

echo "OK\n";
?>
--EXPECT--
First decode count: 5
Second decode count: 5
Second encoding smaller: yes
Content match: PASS
OK
