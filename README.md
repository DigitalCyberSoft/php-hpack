# php-hpack

A PHP extension for HPACK header compression as defined in [RFC 7541](https://www.rfc-editor.org/rfc/rfc7541). HPACK is the header compression format used by HTTP/2.

This extension wraps [libnghttp2](https://nghttp2.org/) to provide high-performance HPACK encoding and decoding with dynamic table support and automatic sensitive header protection.

## Features

- **Stateful HPACK context** with dynamic table management for efficient header compression across multiple requests
- **Huffman encoding/decoding** standalone functions (RFC 7541 Appendix B)
- **Sensitive header protection** - automatically applies NO_INDEX for `authorization`, `cookie`, `proxy-authorization`, and `set-cookie` headers
- **Configurable dynamic table size** (0 to 1,048,576 bytes, default 4096)

## Requirements

- PHP 8.0+
- libnghttp2 development headers (`libnghttp2-devel` on Fedora/RHEL, `libnghttp2-dev` on Debian/Ubuntu)

## Installation

### From COPR (Fedora/RHEL)

```bash
sudo dnf copr enable reversejames/php-hpack
sudo dnf install php-hpack
```

### From Source

```bash
phpize
./configure --enable-hpack
make
make test
sudo make install
```

Add to your PHP configuration:

```ini
extension=hpack.so
```

## API

### HPackContext Class

```php
$ctx = new HPackContext(int $tableSize = 4096);
```

#### `encode(array $headers): string`

Encodes an array of `[name, value]` header pairs into HPACK binary format.

```php
$ctx = new HPackContext();
$encoded = $ctx->encode([
    [':method', 'GET'],
    [':path', '/'],
    [':scheme', 'https'],
    [':authority', 'example.com'],
    ['user-agent', 'php-hpack/1.0'],
    ['accept', '*/*'],
]);
```

#### `decode(string $input, int $maxSize): ?array`

Decodes HPACK binary data back into header pairs. Returns `null` if decoding fails or the total decoded size exceeds `$maxSize`.

```php
$headers = $ctx->decode($encoded, 8192);
// [
//     [':method', 'GET'],
//     [':path', '/'],
//     ...
// ]
```

### Huffman Functions

```php
// Encode a string with Huffman coding
$compressed = hpack_huffman_encode('www.example.com');
// 15 bytes → 12 bytes

// Decode Huffman-encoded data
$original = hpack_huffman_decode($compressed);
// 'www.example.com'
```

## Dynamic Table

The `HPackContext` maintains state between calls. Encoding the same headers repeatedly becomes more efficient as entries are added to the dynamic table:

```php
$ctx = new HPackContext();

$first = $ctx->encode([['x-custom', 'value']]);
$second = $ctx->encode([['x-custom', 'value']]);

// strlen($second) < strlen($first) due to dynamic table
```

## Error Handling

- `ValueError` is thrown for invalid parameters (bad table size, malformed headers)
- `RuntimeException` is thrown for library initialization failures
- `decode()` returns `null` for invalid/incomplete input rather than throwing

## Tests

```bash
make test
```

The test suite covers basic encode/decode, Huffman round-trips, dynamic table behavior, and error handling.

## License

MIT
