--TEST--
RFC 7541 Appendix C request examples (C.3.x without Huffman, C.4.x with Huffman)
--EXTENSIONS--
hpack
--FILE--
<?php

/**
 * Official test vectors from RFC 7541 Appendix C.
 * These verify wire-level decode compatibility with the spec.
 *
 * C.3.1-C.3.3: First Request / Second Request / Third Request (no Huffman)
 * C.4.1-C.4.3: Same requests with Huffman encoding
 *
 * Each group shares a single decoder context (dynamic table state carries over).
 */

function hex2bin_strip(string $hex): string {
    return hex2bin(str_replace(' ', '', $hex));
}

function check_headers(array $decoded, array $expected, string $label): void {
    if (count($decoded) !== count($expected)) {
        echo "$label: FAIL (count " . count($decoded) . " != " . count($expected) . ")\n";
        return;
    }
    for ($i = 0; $i < count($expected); $i++) {
        if ($decoded[$i][0] !== $expected[$i][0] || $decoded[$i][1] !== $expected[$i][1]) {
            echo "$label: FAIL at $i: [{$decoded[$i][0]}: {$decoded[$i][1]}] != [{$expected[$i][0]}: {$expected[$i][1]}]\n";
            return;
        }
    }
    echo "$label: PASS\n";
}

/*
 * C.3: Request Examples without Huffman Coding
 * All three use the same decoder context (continuation).
 */
echo "--- C.3: Requests without Huffman ---\n";
$ctx = new HPackContext();

// C.3.1 First Request
$input = hex2bin_strip('8286 8441 0f77 7777 2e65 7861 6d70 6c65 2e63 6f6d');
$decoded = $ctx->decode($input, 4096);
check_headers($decoded, [
    [':method', 'GET'],
    [':scheme', 'http'],
    [':path', '/'],
    [':authority', 'www.example.com'],
], 'C.3.1');

// C.3.2 Second Request (continuation - reuses dynamic table from C.3.1)
$input = hex2bin_strip('8286 84be 5808 6e6f 2d63 6163 6865');
$decoded = $ctx->decode($input, 4096);
check_headers($decoded, [
    [':method', 'GET'],
    [':scheme', 'http'],
    [':path', '/'],
    [':authority', 'www.example.com'],
    ['cache-control', 'no-cache'],
], 'C.3.2');

// C.3.3 Third Request (continuation)
$input = hex2bin_strip('8287 85bf 400a 6375 7374 6f6d 2d6b 6579 0c63 7573 746f 6d2d 7661 6c75 65');
$decoded = $ctx->decode($input, 4096);
check_headers($decoded, [
    [':method', 'GET'],
    [':scheme', 'https'],
    [':path', '/index.html'],
    [':authority', 'www.example.com'],
    ['custom-key', 'custom-value'],
], 'C.3.3');

/*
 * C.4: Request Examples with Huffman Coding
 * Same headers as C.3, but Huffman-encoded. Fresh decoder context.
 */
echo "--- C.4: Requests with Huffman ---\n";
$ctx = new HPackContext();

// C.4.1 First Request
$input = hex2bin_strip('8286 8441 8cf1 e3c2 e5f2 3a6b a0ab 90f4 ff');
$decoded = $ctx->decode($input, 4096);
check_headers($decoded, [
    [':method', 'GET'],
    [':scheme', 'http'],
    [':path', '/'],
    [':authority', 'www.example.com'],
], 'C.4.1');

// C.4.2 Second Request (continuation)
$input = hex2bin_strip('8286 84be 5886 a8eb 1064 9cbf');
$decoded = $ctx->decode($input, 4096);
check_headers($decoded, [
    [':method', 'GET'],
    [':scheme', 'http'],
    [':path', '/'],
    [':authority', 'www.example.com'],
    ['cache-control', 'no-cache'],
], 'C.4.2');

// C.4.3 Third Request (continuation)
$input = hex2bin_strip('8287 85bf 4088 25a8 49e9 5ba9 7d7f 8925 a849 e95b b8e8 b4bf');
$decoded = $ctx->decode($input, 4096);
check_headers($decoded, [
    [':method', 'GET'],
    [':scheme', 'https'],
    [':path', '/index.html'],
    [':authority', 'www.example.com'],
    ['custom-key', 'custom-value'],
], 'C.4.3');

echo "OK\n";
?>
--EXPECT--
--- C.3: Requests without Huffman ---
C.3.1: PASS
C.3.2: PASS
C.3.3: PASS
--- C.4: Requests with Huffman ---
C.4.1: PASS
C.4.2: PASS
C.4.3: PASS
OK
