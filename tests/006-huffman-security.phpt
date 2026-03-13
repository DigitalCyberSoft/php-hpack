--TEST--
Huffman encode/decode security hardening (RFC 7541 Section 5.2)
--EXTENSIONS--
hpack
--FILE--
<?php

/*
 * Test 1: Invalid padding must return false
 * RFC 7541 5.2: "A padding not corresponding to the most significant bits
 * of the code for the EOS symbol MUST be treated as a decoding error."
 */
echo "--- Invalid padding ---\n";

// 0x06 = 00000110: first 5 bits decode '0' (code 0x00), remaining 3 bits = 110 (not all 1s)
echo "Bad pad 110: " . (hpack_huffman_decode("\x06") === false ? "false" : "decoded") . "\n";

// 0x04 = 00000100: first 5 bits decode '0', remaining 3 bits = 100
echo "Bad pad 100: " . (hpack_huffman_decode("\x04") === false ? "false" : "decoded") . "\n";

// 0x00 = 00000000: first 5 bits decode '0', remaining 3 bits = 000
echo "Bad pad 000: " . (hpack_huffman_decode("\x00") === false ? "false" : "decoded") . "\n";

// 0x07 = 00000111: first 5 bits decode '0', remaining 3 bits = 111 (valid!)
$r = hpack_huffman_decode("\x07");
echo "Good pad 111: " . ($r === false ? "false" : "'" . $r . "'") . "\n";

/*
 * Test 2: Padding > 7 bits must return false
 * RFC 7541 5.2: "A padding strictly longer than 7 bits MUST be treated
 * as a decoding error."
 */
echo "--- Padding > 7 bits ---\n";

// 0xFE = 11111110: no valid 5-8 bit code matches, leaving 8 bits unmatched
// acc_bits=8 > 7 triggers the long padding error
echo "Long padding: " . (hpack_huffman_decode("\xfe") === false ? "false" : "decoded") . "\n";

/*
 * Test 3: EOS symbol / accumulator overflow protection
 * RFC 7541 5.2: "A Huffman-encoded string literal containing the EOS symbol
 * MUST be treated as a decoding error."
 * Also tests uint64 accumulator prevents UB on 30+ accumulated bits.
 */
echo "--- EOS / accumulator overflow ---\n";

// 4 bytes of 0xFF: no valid symbol matches any prefix of all-1s up to 28 bits,
// accumulates to 32 bits, triggers EOS detection at 30+ bits
echo "4x FF: " . (hpack_huffman_decode("\xff\xff\xff\xff") === false ? "false" : "decoded") . "\n";

// 5 bytes of 0xFF: previously caused uint32_t accumulator overflow (UB)
echo "5x FF: " . (hpack_huffman_decode("\xff\xff\xff\xff\xff") === false ? "false" : "decoded") . "\n";

// 8 bytes of 0xFF: stress test for accumulator overflow
echo "8x FF: " . (hpack_huffman_decode(str_repeat("\xff", 8)) === false ? "false" : "decoded") . "\n";

/*
 * Test 4: Valid Huffman round-trips still work after hardening
 */
echo "--- Valid round-trips ---\n";
$tests = [
    "",
    "a",
    "hello",
    "www.example.com",
    "application/json; charset=utf-8",
    str_repeat("x", 256),
];
$all_ok = true;
foreach ($tests as $input) {
    $enc = hpack_huffman_encode($input);
    $dec = hpack_huffman_decode($enc);
    if ($dec !== $input) {
        echo "FAIL: len=" . strlen($input) . "\n";
        $all_ok = false;
    }
}
echo "All round-trips: " . ($all_ok ? "PASS" : "FAIL") . "\n";

/*
 * Test 5: Binary data round-trip (all byte values)
 */
echo "--- Binary round-trip ---\n";
$binary = "";
for ($i = 0; $i < 256; $i++) {
    $binary .= chr($i);
}
$enc = hpack_huffman_encode($binary);
$dec = hpack_huffman_decode($enc);
echo "All 256 bytes: " . ($dec === $binary ? "PASS" : "FAIL") . "\n";

echo "OK\n";
?>
--EXPECT--
--- Invalid padding ---
Bad pad 110: false
Bad pad 100: false
Bad pad 000: false
Good pad 111: '0'
--- Padding > 7 bits ---
Long padding: false
--- EOS / accumulator overflow ---
4x FF: false
5x FF: false
8x FF: false
--- Valid round-trips ---
All round-trips: PASS
--- Binary round-trip ---
All 256 bytes: PASS
OK
