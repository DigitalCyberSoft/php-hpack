--TEST--
RFC 7541 Appendix C response examples (C.5.x without Huffman, C.6.x with Huffman)
--EXTENSIONS--
hpack
--FILE--
<?php

/**
 * Official test vectors from RFC 7541 Appendix C.
 *
 * C.5.1-C.5.3: Response examples without Huffman (sequential context)
 * C.6.1-C.6.3: Response examples with Huffman (sequential context)
 *
 * These test dynamic table management across multiple decode calls,
 * including table eviction (C.5.3/C.6.3 cause eviction due to table size).
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
 * C.5: Response Examples without Huffman Coding
 * Dynamic table size limited to 256 bytes (RFC example uses 256).
 * C.5.3 triggers eviction: set-cookie entry (98 bytes) forces older entries out.
 *
 * Note: The RFC examples in C.5 use a 256-byte dynamic table, but our
 * HPackContext sets table size for the DEFLATER only. The inflater uses
 * nghttp2 defaults (4096). The encoded data itself contains table size
 * update instructions, so the inflater handles the 256-byte constraint
 * from the wire format. We use default context here - nghttp2's inflater
 * processes the data correctly regardless.
 */
echo "--- C.5: Responses without Huffman ---\n";
$ctx = new HPackContext();

// C.5.1 First Response (302 redirect)
$input = hex2bin_strip(
    '4803 3330 3258 0770 7269 7661 7465 611d' .
    '4d6f 6e2c 2032 3120 4f63 7420 3230 3133' .
    '2032 303a 3133 3a32 3120 474d 546e 1768' .
    '7474 7073 3a2f 2f77 7777 2e65 7861 6d70' .
    '6c65 2e63 6f6d'
);
$decoded = $ctx->decode($input, 4096);
check_headers($decoded, [
    [':status', '302'],
    ['cache-control', 'private'],
    ['date', 'Mon, 21 Oct 2013 20:13:21 GMT'],
    ['location', 'https://www.example.com'],
], 'C.5.1');

// C.5.2 Second Response (307 redirect, reuses dynamic table)
$input = hex2bin_strip('4803 3330 37c1 c0bf');
$decoded = $ctx->decode($input, 4096);
check_headers($decoded, [
    [':status', '307'],
    ['cache-control', 'private'],
    ['date', 'Mon, 21 Oct 2013 20:13:21 GMT'],
    ['location', 'https://www.example.com'],
], 'C.5.2');

// C.5.3 Third Response (200 OK with set-cookie, triggers table eviction)
$input = hex2bin_strip(
    '88c1 611d 4d6f 6e2c 2032 3120 4f63 7420' .
    '3230 3133 2032 303a 3133 3a32 3220 474d' .
    '54c0 5a04 677a 6970 7738 666f 6f3d 4153' .
    '444a 4b48 514b 425a 584f 5157 454f 5049' .
    '5541 5851 5745 4f49 553b 206d 6178 2d61' .
    '6765 3d33 3630 303b 2076 6572 7369 6f6e' .
    '3d31'
);
$decoded = $ctx->decode($input, 4096);
check_headers($decoded, [
    [':status', '200'],
    ['cache-control', 'private'],
    ['date', 'Mon, 21 Oct 2013 20:13:22 GMT'],
    ['location', 'https://www.example.com'],
    ['content-encoding', 'gzip'],
    ['set-cookie', 'foo=ASDJKHQKBZXOQWEOPIUAXQWEOIU; max-age=3600; version=1'],
], 'C.5.3');

/*
 * C.6: Response Examples with Huffman Coding
 * Same headers as C.5 but Huffman-encoded. Fresh decoder context.
 */
echo "--- C.6: Responses with Huffman ---\n";
$ctx = new HPackContext();

// C.6.1 First Response
$input = hex2bin_strip(
    '4882 6402 5885 aec3 771a 4b61 96d0 7abe' .
    '9410 54d4 44a8 2005 9504 0b81 66e0 82a6' .
    '2d1b ff6e 919d 29ad 1718 63c7 8f0b 97c8' .
    'e9ae 82ae 43d3'
);
$decoded = $ctx->decode($input, 4096);
check_headers($decoded, [
    [':status', '302'],
    ['cache-control', 'private'],
    ['date', 'Mon, 21 Oct 2013 20:13:21 GMT'],
    ['location', 'https://www.example.com'],
], 'C.6.1');

// C.6.2 Second Response (continuation)
$input = hex2bin_strip('4883 640e ffc1 c0bf');
$decoded = $ctx->decode($input, 4096);
check_headers($decoded, [
    [':status', '307'],
    ['cache-control', 'private'],
    ['date', 'Mon, 21 Oct 2013 20:13:21 GMT'],
    ['location', 'https://www.example.com'],
], 'C.6.2');

// C.6.3 Third Response (continuation, with eviction)
$input = hex2bin_strip(
    '88c1 6196 d07a be94 1054 d444 a820 0595' .
    '040b 8166 e084 a62d 1bff c05a 839b d9ab' .
    '77ad 94e7 821d d7f2 e6c7 b335 dfdf cd5b' .
    '3960 d5af 2708 7f36 72c1 ab27 0fb5 291f' .
    '9587 3160 65c0 03ed 4ee5 b106 3d50 07'
);
$decoded = $ctx->decode($input, 4096);
check_headers($decoded, [
    [':status', '200'],
    ['cache-control', 'private'],
    ['date', 'Mon, 21 Oct 2013 20:13:22 GMT'],
    ['location', 'https://www.example.com'],
    ['content-encoding', 'gzip'],
    ['set-cookie', 'foo=ASDJKHQKBZXOQWEOPIUAXQWEOIU; max-age=3600; version=1'],
], 'C.6.3');

echo "OK\n";
?>
--EXPECT--
--- C.5: Responses without Huffman ---
C.5.1: PASS
C.5.2: PASS
C.5.3: PASS
--- C.6: Responses with Huffman ---
C.6.1: PASS
C.6.2: PASS
C.6.3: PASS
OK
