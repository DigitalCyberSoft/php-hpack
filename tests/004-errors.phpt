--TEST--
HPackContext error handling
--EXTENSIONS--
hpack
--FILE--
<?php

// Invalid table size
try {
    $ctx = new HPackContext(-1);
    echo "Should have thrown\n";
} catch (ValueError $e) {
    echo "Caught: " . $e->getMessage() . "\n";
}

try {
    $ctx = new HPackContext(2000000);
    echo "Should have thrown\n";
} catch (ValueError $e) {
    echo "Caught: " . $e->getMessage() . "\n";
}

// Invalid header format
$ctx = new HPackContext();
try {
    $ctx->encode(["not-an-array"]);
    echo "Should have thrown\n";
} catch (ValueError $e) {
    echo "Caught: " . $e->getMessage() . "\n";
}

// Invalid binary input to decode
$result = $ctx->decode("\xff\xff\xff\xff", 8192);
echo "Invalid decode: " . ($result === null ? "null" : "not null") . "\n";

echo "OK\n";
?>
--EXPECT--
Caught: Table size must be between 0 and 1048576
Caught: Table size must be between 0 and 1048576
Caught: Each header must be an array of [name, value]
Invalid decode: null
OK
