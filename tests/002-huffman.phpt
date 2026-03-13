--TEST--
HPACK Huffman encode/decode standalone functions
--EXTENSIONS--
hpack
--FILE--
<?php

// Test basic Huffman round-trip
$input = "www.example.com";
$encoded = hpack_huffman_encode($input);
$decoded = hpack_huffman_decode($encoded);
echo "Round-trip: " . ($decoded === $input ? "PASS" : "FAIL: got '$decoded'") . "\n";

// Huffman should compress ASCII text
echo "Original: " . strlen($input) . ", Encoded: " . strlen($encoded) . "\n";
echo "Compressed: " . (strlen($encoded) < strlen($input) ? "yes" : "no") . "\n";

// Test empty string
echo "Empty encode: '" . hpack_huffman_encode("") . "'\n";
echo "Empty decode: '" . hpack_huffman_decode("") . "'\n";

// Test various strings
$tests = [
    "GET",
    "/index.html",
    "text/html",
    "application/json",
    "custom-header-value-12345",
];

$all_pass = true;
foreach ($tests as $test) {
    $enc = hpack_huffman_encode($test);
    $dec = hpack_huffman_decode($enc);
    if ($dec !== $test) {
        echo "FAIL: '$test' -> '$dec'\n";
        $all_pass = false;
    }
}
echo "All round-trips: " . ($all_pass ? "PASS" : "FAIL") . "\n";

echo "OK\n";
?>
--EXPECT--
Round-trip: PASS
Original: 15, Encoded: 12
Compressed: yes
Empty encode: ''
Empty decode: ''
All round-trips: PASS
OK
