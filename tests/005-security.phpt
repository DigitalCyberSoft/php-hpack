--TEST--
HPackContext security hardening
--EXTENSIONS--
hpack
--FILE--
<?php

/*
 * Test 1: Truncated HPACK input must return null, not partial headers
 * (Incomplete header blocks without NGHTTP2_HD_INFLATE_FINAL)
 */
echo "--- Truncated input ---\n";
$enc = new HPackContext();
$headers = [
    [":method", "GET"],
    [":path", "/"],
    [":scheme", "https"],
    [":authority", "example.com"],
    ["user-agent", "test"],
];
$encoded = $enc->encode($headers);

// Truncate by removing last 3 bytes
$ctx1 = new HPackContext();
$result = $ctx1->decode(substr($encoded, 0, -3), 8192);
echo "Truncated -3: " . ($result === null ? "null" : "partial") . "\n";

// Truncate to half
$ctx2 = new HPackContext();
$result = $ctx2->decode(substr($encoded, 0, intdiv(strlen($encoded), 2)), 8192);
echo "Truncated half: " . ($result === null ? "null" : "partial") . "\n";

// Incomplete literal header (name length declared but no name data)
$ctx3 = new HPackContext();
$result = $ctx3->decode("\x40\x05", 8192);
echo "Incomplete literal: " . ($result === null ? "null" : "partial") . "\n";

// Empty input should return empty array (valid empty header block)
$ctx4 = new HPackContext();
$result = $ctx4->decode("", 8192);
echo "Empty decode: " . ($result === null ? "null" : "array(" . count($result) . ")") . "\n";

/*
 * Test 2: Type safety - non-string header values must throw ValueError
 * (Prevents convert_to_string re-entrance / use-after-free via __toString)
 */
echo "--- Type safety ---\n";

// Integers should be coerced to strings (e.g. [":status", 200])
$ctx = new HPackContext();
$encoded = $ctx->encode([[":status", 200]]);
$dec = new HPackContext();
$decoded = $dec->decode($encoded, 8192);
echo "int value coerced: " . ($decoded[0][1] === "200" ? "ok" : "fail") . "\n";

$ctx = new HPackContext();
$encoded = $ctx->encode([[123, "value"]]);
$dec = new HPackContext();
$decoded = $dec->decode($encoded, 8192);
echo "int name coerced: " . ($decoded[0][0] === "123" ? "ok" : "fail") . "\n";

// Non-integer non-string types must still throw ValueError
$types = [
    'bool value'  => [["name", true]],
    'null value'  => [["name", null]],
    'float name'  => [[1.5, "value"]],
    'array value' => [["name", []]],
];
foreach ($types as $label => $headers) {
    try {
        $ctx = new HPackContext();
        $ctx->encode($headers);
        echo "$label: no throw (BUG)\n";
    } catch (ValueError $e) {
        echo "$label: ValueError\n";
    }
}

// Verify valid string headers still work
$ctx = new HPackContext();
$encoded = $ctx->encode([["x-test", "hello"]]);
echo "Valid strings: " . (strlen($encoded) > 0 ? "ok" : "fail") . "\n";

/*
 * Test 3: max_size includes 32-byte per-entry overhead (RFC 7541 Section 4.1)
 * Entry size = name_len + value_len + 32
 * :method(7) + GET(3) + 32 = 42 bytes per RFC
 */
echo "--- max_size overhead ---\n";
$enc = new HPackContext();
$encoded = $enc->encode([[":method", "GET"]]);

// max_size=41: entry costs 42, should fail
$ctx = new HPackContext();
echo "max_size=41: " . ($ctx->decode($encoded, 41) === null ? "null" : "ok") . "\n";

// max_size=42: entry costs 42, should succeed
$ctx = new HPackContext();
echo "max_size=42: " . ($ctx->decode($encoded, 42) === null ? "null" : "ok") . "\n";

/*
 * Test 4: Case-insensitive sensitive header detection
 * Mixed-case "Authorization" must be marked never-index (same as lowercase)
 */
echo "--- Case-insensitive sensitive ---\n";

// Mixed-case sensitive header: should NOT be indexed (never-index flag)
// Second encode same size = not in dynamic table
$enc1 = new HPackContext();
$first = $enc1->encode([["Authorization", "Bearer token123"]]);
$second = $enc1->encode([["Authorization", "Bearer token123"]]);
echo "Authorization not indexed: " . (strlen($second) == strlen($first) ? "yes" : "no") . "\n";

// Non-sensitive header: SHOULD be indexed, second encode smaller
$enc2 = new HPackContext();
$first2 = $enc2->encode([["x-custom", "Bearer token123"]]);
$second2 = $enc2->encode([["x-custom", "Bearer token123"]]);
echo "x-custom indexed: " . (strlen($second2) < strlen($first2) ? "yes" : "no") . "\n";

// Test other sensitive headers with mixed case
$enc3 = new HPackContext();
$f1 = $enc3->encode([["COOKIE", "session=abc"]]);
$f2 = $enc3->encode([["COOKIE", "session=abc"]]);
echo "COOKIE not indexed: " . (strlen($f2) == strlen($f1) ? "yes" : "no") . "\n";

$enc4 = new HPackContext();
$f1 = $enc4->encode([["Proxy-Authorization", "Basic xyz"]]);
$f2 = $enc4->encode([["Proxy-Authorization", "Basic xyz"]]);
echo "Proxy-Authorization not indexed: " . (strlen($f2) == strlen($f1) ? "yes" : "no") . "\n";

$enc5 = new HPackContext();
$f1 = $enc5->encode([["SET-COOKIE", "id=abc"]]);
$f2 = $enc5->encode([["SET-COOKIE", "id=abc"]]);
echo "SET-COOKIE not indexed: " . (strlen($f2) == strlen($f1) ? "yes" : "no") . "\n";

/*
 * Test 5: Context reuse after valid decode works (dynamic table preserved)
 */
echo "--- Context reuse ---\n";
$enc = new HPackContext();
$ctx = new HPackContext();

$r1 = $ctx->decode($enc->encode([[":method", "GET"], [":path", "/"]]), 8192);
echo "First: array(" . count($r1) . ")\n";

$r2 = $ctx->decode($enc->encode([[":method", "POST"], [":path", "/api"]]), 8192);
echo "Second: array(" . count($r2) . ")\n";
echo "Second values: " . $r2[0][1] . ", " . $r2[1][1] . "\n";

echo "OK\n";
?>
--EXPECT--
--- Truncated input ---
Truncated -3: null
Truncated half: null
Incomplete literal: null
Empty decode: array(0)
--- Type safety ---
int value coerced: ok
int name coerced: ok
bool value: ValueError
null value: ValueError
float name: ValueError
array value: ValueError
Valid strings: ok
--- max_size overhead ---
max_size=41: null
max_size=42: ok
--- Case-insensitive sensitive ---
Authorization not indexed: yes
x-custom indexed: yes
COOKIE not indexed: yes
Proxy-Authorization not indexed: yes
SET-COOKIE not indexed: yes
--- Context reuse ---
First: array(2)
Second: array(2)
Second values: POST, /api
OK
