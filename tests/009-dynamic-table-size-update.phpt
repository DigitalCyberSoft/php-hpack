--TEST--
Dynamic table size update handling (RFC 7541 Section 6.3)
--EXTENSIONS--
hpack
--FILE--
<?php

/*
 * RFC 7541 Section 6.3: Dynamic Table Size Update
 * Wire format: 001xxxxx with 5-bit prefix integer
 *
 * When the HTTP/2 client advertises a non-default SETTINGS_HEADER_TABLE_SIZE
 * (e.g. Chrome uses 65536), the server's HPACK encoder emits a dynamic table
 * size update at the start of its first response header block. The decoder
 * must parse and apply this instruction instead of returning null.
 */

// Helper: encode an integer with 5-bit prefix for table size update (001xxxxx)
function encode_table_size_update(int $size): string {
    if ($size < 31) {
        return chr(0x20 | $size);
    }
    $result = chr(0x3F); // 0x20 | 0x1F
    $size -= 31;
    while ($size >= 128) {
        $result .= chr(($size & 127) | 128);
        $size >>= 7;
    }
    $result .= chr($size);
    return $result;
}

/*
 * Test 1: Non-default context accepts size update up to its configured max
 */
echo "--- Non-default table sizes ---\n";
$sizes = [8192, 16384, 32768, 65536];
foreach ($sizes as $size) {
    // Size update equal to context max
    $d = new HPackContext($size);
    $wire = encode_table_size_update($size) . "\x88"; // + :status: 200
    $r = $d->decode($wire, $size);
    echo "ctx=$size update=$size: " . ($r !== null && $r[0][0] === ':status' ? "ok" : "FAIL") . "\n";

    // Size update below context max
    $d = new HPackContext($size);
    $wire = encode_table_size_update(4096) . "\x88";
    $r = $d->decode($wire, $size);
    echo "ctx=$size update=4096: " . ($r !== null && $r[0][0] === ':status' ? "ok" : "FAIL") . "\n";
}

/*
 * Test 2: Size update to 0 (table eviction) should work with any context
 */
echo "--- Size update to zero ---\n";
$d = new HPackContext(65536);
$r = $d->decode("\x20\x88", 65536); // 0x20 = table size update to 0
echo "Update to 0: " . ($r !== null && $r[0][0] === ':status' ? "ok" : "FAIL") . "\n";

/*
 * Test 3: Size update exceeding context max must be rejected
 */
echo "--- Exceeding max rejected ---\n";
$d = new HPackContext(4096);
$wire = encode_table_size_update(8192) . "\x88";
$r = $d->decode($wire, 8192);
echo "ctx=4096 update=8192: " . ($r === null ? "null" : "FAIL (should be null)") . "\n";

$d = new HPackContext(8192);
$wire = encode_table_size_update(16384) . "\x88";
$r = $d->decode($wire, 16384);
echo "ctx=8192 update=16384: " . ($r === null ? "null" : "FAIL (should be null)") . "\n";

/*
 * Test 4: Round-trip encode/decode with non-default table sizes
 * Simulates the Chrome 65536 scenario end-to-end
 */
echo "--- Round-trip non-default sizes ---\n";
foreach ([8192, 65536] as $size) {
    $enc = new HPackContext($size);
    $dec = new HPackContext($size);
    $headers = [
        [":status", "200"],
        ["content-type", "text/html"],
        ["server", "test"],
        ["x-custom", "value"],
    ];
    $encoded = $enc->encode($headers);
    $decoded = $dec->decode($encoded, $size);

    $match = ($decoded !== null && count($decoded) === count($headers));
    if ($match) {
        for ($i = 0; $i < count($headers); $i++) {
            if ($headers[$i][0] !== $decoded[$i][0] || $headers[$i][1] !== $decoded[$i][1]) {
                $match = false;
                break;
            }
        }
    }
    echo "Round-trip $size: " . ($match ? "ok" : "FAIL") . "\n";
}

/*
 * Test 5: Multiple sequential decodes after size update preserve dynamic table
 */
echo "--- Sequential decodes after size update ---\n";
$enc = new HPackContext(65536);
$dec = new HPackContext(65536);

$r1 = $dec->decode($enc->encode([[":status", "200"], ["x-custom", "first"]]), 65536);
echo "First: " . ($r1 !== null ? "ok (" . count($r1) . " headers)" : "FAIL") . "\n";

$r2 = $dec->decode($enc->encode([[":status", "304"], ["x-custom", "second"]]), 65536);
echo "Second: " . ($r2 !== null ? "ok (" . count($r2) . " headers)" : "FAIL") . "\n";
echo "Values: " . $r2[0][1] . ", " . $r2[1][1] . "\n";

echo "OK\n";
?>
--EXPECT--
--- Non-default table sizes ---
ctx=8192 update=8192: ok
ctx=8192 update=4096: ok
ctx=16384 update=16384: ok
ctx=16384 update=4096: ok
ctx=32768 update=32768: ok
ctx=32768 update=4096: ok
ctx=65536 update=65536: ok
ctx=65536 update=4096: ok
--- Size update to zero ---
Update to 0: ok
--- Exceeding max rejected ---
ctx=4096 update=8192: null
ctx=8192 update=16384: null
--- Round-trip non-default sizes ---
Round-trip 8192: ok
Round-trip 65536: ok
--- Sequential decodes after size update ---
First: ok (2 headers)
Second: ok (2 headers)
Values: 304, second
OK
