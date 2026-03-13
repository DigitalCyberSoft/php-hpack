/*
 * php-hpack: PHP extension for HPACK header compression (RFC 7541)
 * Wraps libnghttp2 for high-performance encode/decode.
 */

#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php.h"
#include "php_ini.h"
#include "ext/standard/info.h"
#include "zend_exceptions.h"
#include "ext/spl/spl_exceptions.h"
#include "php_hpack.h"

#include <nghttp2/nghttp2.h>

/* ----------------------------------------------------------------
 * HPackContext class
 * ---------------------------------------------------------------- */

typedef struct {
	nghttp2_hd_deflater *deflater;
	nghttp2_hd_inflater *inflater;
	size_t table_size;
	zend_object std;
} hpack_context_obj;

static zend_class_entry *hpack_context_ce;
static zend_object_handlers hpack_context_handlers;

static inline hpack_context_obj *hpack_context_from_obj(zend_object *obj)
{
	return (hpack_context_obj *)((char *)obj - XtOffsetOf(hpack_context_obj, std));
}

#define Z_HPACK_CONTEXT_P(zv) hpack_context_from_obj(Z_OBJ_P(zv))

static void hpack_context_free(zend_object *object)
{
	hpack_context_obj *ctx = hpack_context_from_obj(object);

	if (ctx->deflater) {
		nghttp2_hd_deflate_del(ctx->deflater);
		ctx->deflater = NULL;
	}
	if (ctx->inflater) {
		nghttp2_hd_inflate_del(ctx->inflater);
		ctx->inflater = NULL;
	}

	zend_object_std_dtor(&ctx->std);
}

static zend_object *hpack_context_create(zend_class_entry *ce)
{
	hpack_context_obj *ctx = zend_object_alloc(sizeof(hpack_context_obj), ce);

	ctx->deflater = NULL;
	ctx->inflater = NULL;
	ctx->table_size = 4096;

	zend_object_std_init(&ctx->std, ce);
	object_properties_init(&ctx->std, ce);
	ctx->std.handlers = &hpack_context_handlers;

	return &ctx->std;
}

/* Sensitive headers that should not be indexed */
static int is_sensitive_header(const char *name, size_t namelen)
{
	if (namelen == 13 && zend_binary_strcasecmp(name, 13, "authorization", 13) == 0) return 1;
	if (namelen == 6 && zend_binary_strcasecmp(name, 6, "cookie", 6) == 0) return 1;
	if (namelen == 19 && zend_binary_strcasecmp(name, 19, "proxy-authorization", 19) == 0) return 1;
	if (namelen == 10 && zend_binary_strcasecmp(name, 10, "set-cookie", 10) == 0) return 1;
	return 0;
}

/* {{{ HPackContext::__construct(int $tableSize = 4096) */
PHP_METHOD(HPackContext, __construct)
{
	zend_long table_size = 4096;
	hpack_context_obj *ctx;
	int rv;

	ZEND_PARSE_PARAMETERS_START(0, 1)
		Z_PARAM_OPTIONAL
		Z_PARAM_LONG(table_size)
	ZEND_PARSE_PARAMETERS_END();

	if (table_size < 0 || table_size > 1048576) {
		zend_throw_exception(zend_ce_value_error, "Table size must be between 0 and 1048576", 0);
		RETURN_THROWS();
	}

	ctx = Z_HPACK_CONTEXT_P(ZEND_THIS);
	ctx->table_size = (size_t)table_size;

	rv = nghttp2_hd_deflate_new(&ctx->deflater, (size_t)table_size);
	if (rv != 0) {
		zend_throw_exception_ex(spl_ce_RuntimeException, 0,
			"Failed to initialize HPACK deflater: %s", nghttp2_strerror(rv));
		RETURN_THROWS();
	}

	rv = nghttp2_hd_inflate_new(&ctx->inflater);
	if (rv != 0) {
		nghttp2_hd_deflate_del(ctx->deflater);
		ctx->deflater = NULL;
		zend_throw_exception_ex(spl_ce_RuntimeException, 0,
			"Failed to initialize HPACK inflater: %s", nghttp2_strerror(rv));
		RETURN_THROWS();
	}
}
/* }}} */

/* {{{ HPackContext::encode(array $headers): string */
PHP_METHOD(HPackContext, encode)
{
	zval *headers_zv;
	hpack_context_obj *ctx;
	nghttp2_nv *nva = NULL;
	size_t nvlen, i;
	ssize_t rv;
	uint8_t *buf = NULL;
	size_t buflen;

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_ARRAY(headers_zv)
	ZEND_PARSE_PARAMETERS_END();

	ctx = Z_HPACK_CONTEXT_P(ZEND_THIS);

	if (!ctx->deflater) {
		zend_throw_exception(spl_ce_RuntimeException, "HPACK context not initialized", 0);
		RETURN_THROWS();
	}

	nvlen = zend_hash_num_elements(Z_ARRVAL_P(headers_zv));
	if (nvlen == 0) {
		RETURN_EMPTY_STRING();
	}

	nva = ecalloc(nvlen, sizeof(nghttp2_nv));

	i = 0;
	zval *entry;
	ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(headers_zv), entry) {
		zval *name_zv, *value_zv;

		if (Z_TYPE_P(entry) != IS_ARRAY || zend_hash_num_elements(Z_ARRVAL_P(entry)) < 2) {
			efree(nva);
			zend_throw_exception(zend_ce_value_error,
				"Each header must be an array of [name, value]", 0);
			RETURN_THROWS();
		}

		name_zv = zend_hash_index_find(Z_ARRVAL_P(entry), 0);
		value_zv = zend_hash_index_find(Z_ARRVAL_P(entry), 1);

		if (!name_zv || !value_zv) {
			efree(nva);
			zend_throw_exception(zend_ce_value_error,
				"Each header must be an array of [name, value]", 0);
			RETURN_THROWS();
		}

		if (Z_TYPE_P(name_zv) != IS_STRING || Z_TYPE_P(value_zv) != IS_STRING) {
			efree(nva);
			zend_throw_exception(zend_ce_value_error,
				"Header name and value must be strings", 0);
			RETURN_THROWS();
		}

		nva[i].name = (uint8_t *)Z_STRVAL_P(name_zv);
		nva[i].namelen = Z_STRLEN_P(name_zv);
		nva[i].value = (uint8_t *)Z_STRVAL_P(value_zv);
		nva[i].valuelen = Z_STRLEN_P(value_zv);

		/* Set flags for sensitive headers */
		if (is_sensitive_header((const char *)nva[i].name, nva[i].namelen)) {
			nva[i].flags = NGHTTP2_NV_FLAG_NO_INDEX |
			               NGHTTP2_NV_FLAG_NO_COPY_NAME |
			               NGHTTP2_NV_FLAG_NO_COPY_VALUE;
		} else {
			nva[i].flags = NGHTTP2_NV_FLAG_NO_COPY_NAME |
			               NGHTTP2_NV_FLAG_NO_COPY_VALUE;
		}

		i++;
	} ZEND_HASH_FOREACH_END();

	buflen = nghttp2_hd_deflate_bound(ctx->deflater, nva, nvlen);
	buf = emalloc(buflen);

	rv = nghttp2_hd_deflate_hd(ctx->deflater, buf, buflen, nva, nvlen);

	efree(nva);

	if (rv < 0) {
		efree(buf);
		zend_throw_exception_ex(spl_ce_RuntimeException, 0,
			"HPACK encoding failed: %s", nghttp2_strerror((int)rv));
		RETURN_THROWS();
	}

	RETVAL_STRINGL((char *)buf, (size_t)rv);
	efree(buf);
}
/* }}} */

/* {{{ HPackContext::decode(string $input, int $maxSize): ?array */
PHP_METHOD(HPackContext, decode)
{
	char *input;
	size_t input_len;
	zend_long max_size;
	hpack_context_obj *ctx;
	nghttp2_nv nv;
	int inflate_flags;
	ssize_t rv;
	size_t total_size = 0;

	ZEND_PARSE_PARAMETERS_START(2, 2)
		Z_PARAM_STRING(input, input_len)
		Z_PARAM_LONG(max_size)
	ZEND_PARSE_PARAMETERS_END();

	ctx = Z_HPACK_CONTEXT_P(ZEND_THIS);

	if (!ctx->inflater) {
		zend_throw_exception(spl_ce_RuntimeException, "HPACK context not initialized", 0);
		RETURN_THROWS();
	}

	if (max_size < 0) {
		zend_throw_exception(zend_ce_value_error, "Max size must be non-negative", 0);
		RETURN_THROWS();
	}

	array_init(return_value);

	const uint8_t *buf = (const uint8_t *)input;
	size_t buflen = input_len;

	for (;;) {
		rv = nghttp2_hd_inflate_hd2(ctx->inflater, &nv, &inflate_flags,
			buf, buflen, 1);

		if (rv < 0) {
			nghttp2_hd_inflate_end_headers(ctx->inflater);
			zval_ptr_dtor(return_value);
			RETURN_NULL();
		}

		buf += rv;
		buflen -= (size_t)rv;

		if (inflate_flags & NGHTTP2_HD_INFLATE_EMIT) {
			/* RFC 7541 Section 4.1: entry size = name length + value length + 32 */
			total_size += nv.namelen + nv.valuelen + 32;

			if (total_size > (size_t)max_size) {
				nghttp2_hd_inflate_end_headers(ctx->inflater);
				zval_ptr_dtor(return_value);
				RETURN_NULL();
			}

			zval pair;
			array_init_size(&pair, 2);
			add_next_index_stringl(&pair, (char *)nv.name, nv.namelen);
			add_next_index_stringl(&pair, (char *)nv.value, nv.valuelen);
			add_next_index_zval(return_value, &pair);
		}

		if (inflate_flags & NGHTTP2_HD_INFLATE_FINAL) {
			nghttp2_hd_inflate_end_headers(ctx->inflater);
			return;
		}

		if (rv == 0 && buflen == 0) {
			/* All input consumed without FINAL flag - incomplete header block */
			nghttp2_hd_inflate_end_headers(ctx->inflater);
			zval_ptr_dtor(return_value);
			RETURN_NULL();
		}
	}

	/* Loop exited without FINAL or error - should not happen, treat as failure */
	nghttp2_hd_inflate_end_headers(ctx->inflater);
	zval_ptr_dtor(return_value);
	RETURN_NULL();
}
/* }}} */

/* ----------------------------------------------------------------
 * Standalone Huffman functions
 * ---------------------------------------------------------------- */

/* HPACK Huffman table (RFC 7541 Appendix B) */
static const struct {
	uint32_t code;
	uint8_t  bits;
} huffman_table[257] = {
	{0x1ff8,     13}, {0x7fffd8,   23}, {0xfffffe2,  28}, {0xfffffe3,  28},
	{0xfffffe4,  28}, {0xfffffe5,  28}, {0xfffffe6,  28}, {0xfffffe7,  28},
	{0xfffffe8,  28}, {0xffffea,   24}, {0x3ffffffc, 30}, {0xfffffe9,  28},
	{0xfffffea,  28}, {0x3ffffffd, 30}, {0xfffffeb,  28}, {0xfffffec,  28},
	{0xfffffed,  28}, {0xfffffee,  28}, {0xfffffef,  28}, {0xffffff0,  28},
	{0xffffff1,  28}, {0xffffff2,  28}, {0x3ffffffe, 30}, {0xffffff3,  28},
	{0xffffff4,  28}, {0xffffff5,  28}, {0xffffff6,  28}, {0xffffff7,  28},
	{0xffffff8,  28}, {0xffffff9,  28}, {0xffffffa,  28}, {0xffffffb,  28},
	{0x14,        6}, {0x3f8,      10}, {0x3f9,      10}, {0xffa,      12},
	{0x1ff9,     13}, {0x15,        6}, {0xf8,        8}, {0x7fa,      11},
	{0x3fa,      10}, {0x3fb,      10}, {0xf9,        8}, {0x7fb,      11},
	{0xfa,        8}, {0x16,        6}, {0x17,        6}, {0x18,        6},
	{0x0,         5}, {0x1,         5}, {0x2,         5}, {0x19,        6},
	{0x1a,        6}, {0x1b,        6}, {0x1c,        6}, {0x1d,        6},
	{0x1e,        6}, {0x1f,        6}, {0x5c,        7}, {0xfb,        8},
	{0x7ffc,     15}, {0x20,        6}, {0xffb,      12}, {0x3fc,      10},
	{0x1ffa,     13}, {0x21,        6}, {0x5d,        7}, {0x5e,        7},
	{0x5f,        7}, {0x60,        7}, {0x61,        7}, {0x62,        7},
	{0x63,        7}, {0x64,        7}, {0x65,        7}, {0x66,        7},
	{0x67,        7}, {0x68,        7}, {0x69,        7}, {0x6a,        7},
	{0x6b,        7}, {0x6c,        7}, {0x6d,        7}, {0x6e,        7},
	{0x6f,        7}, {0x70,        7}, {0x71,        7}, {0x72,        7},
	{0xfc,        8}, {0x73,        7}, {0xfd,        8}, {0x1ffb,     13},
	{0x7fff0,    19}, {0x1ffc,     13}, {0x3ffc,     14}, {0x22,        6},
	{0x7ffd,     15}, {0x3,         5}, {0x23,        6}, {0x4,         5},
	{0x24,        6}, {0x5,         5}, {0x25,        6}, {0x26,        6},
	{0x27,        6}, {0x6,         5}, {0x74,        7}, {0x75,        7},
	{0x28,        6}, {0x29,        6}, {0x2a,        6}, {0x7,         5},
	{0x2b,        6}, {0x76,        7}, {0x2c,        6}, {0x8,         5},
	{0x9,         5}, {0x2d,        6}, {0x77,        7}, {0x78,        7},
	{0x79,        7}, {0x7a,        7}, {0x7b,        7}, {0x7ffe,     15},
	{0x7fc,      11}, {0x3ffd,     14}, {0x1ffd,     13}, {0xffffffc,  28},
	{0xfffe6,    20}, {0x3fffd2,   22}, {0xfffe7,    20}, {0xfffe8,    20},
	{0x3fffd3,   22}, {0x3fffd4,   22}, {0x3fffd5,   22}, {0x7fffd9,   23},
	{0x3fffd6,   22}, {0x7fffda,   23}, {0x7fffdb,   23}, {0x7fffdc,   23},
	{0x7fffdd,   23}, {0x7fffde,   23}, {0xffffeb,   24}, {0x7fffdf,   23},
	{0xffffec,   24}, {0xffffed,   24}, {0x3fffd7,   22}, {0x7fffe0,   23},
	{0xffffee,   24}, {0x7fffe1,   23}, {0x7fffe2,   23}, {0x7fffe3,   23},
	{0x7fffe4,   23}, {0x1fffdc,   21}, {0x3fffd8,   22}, {0x7fffe5,   23},
	{0x3fffd9,   22}, {0x7fffe6,   23}, {0x7fffe7,   23}, {0xffffef,   24},
	{0x3fffda,   22}, {0x1fffdd,   21}, {0xfffe9,    20}, {0x3fffdb,   22},
	{0x3fffdc,   22}, {0x7fffe8,   23}, {0x7fffe9,   23}, {0x1fffde,   21},
	{0x7fffea,   23}, {0x3fffdd,   22}, {0x3fffde,   22}, {0xfffff0,   24},
	{0x1fffdf,   21}, {0x3fffdf,   22}, {0x7fffeb,   23}, {0x7fffec,   23},
	{0x1fffe0,   21}, {0x1fffe1,   21}, {0x3fffe0,   22}, {0x1fffe2,   21},
	{0x7fffed,   23}, {0x3fffe1,   22}, {0x7fffee,   23}, {0x7fffef,   23},
	{0xfffea,    20}, {0x3fffe2,   22}, {0x3fffe3,   22}, {0x3fffe4,   22},
	{0x7ffff0,   23}, {0x3fffe5,   22}, {0x3fffe6,   22}, {0x7ffff1,   23},
	{0x3ffffe0,  26}, {0x3ffffe1,  26}, {0xfffeb,    20}, {0x7fff1,    19},
	{0x3fffe7,   22}, {0x7ffff2,   23}, {0x3fffe8,   22}, {0x1ffffec,  25},
	{0x3ffffe2,  26}, {0x3ffffe3,  26}, {0x3ffffe4,  26}, {0x7ffffde,  27},
	{0x7ffffdf,  27}, {0x3ffffe5,  26}, {0xfffff1,   24}, {0x1ffffed,  25},
	{0x7fff2,    19}, {0x1fffe3,   21}, {0x3ffffe6,  26}, {0x7ffffe0,  27},
	{0x7ffffe1,  27}, {0x3ffffe7,  26}, {0x7ffffe2,  27}, {0xfffff2,   24},
	{0x1fffe4,   21}, {0x1fffe5,   21}, {0x3ffffe8,  26}, {0x3ffffe9,  26},
	{0xffffffd,  28}, {0x7ffffe3,  27}, {0x7ffffe4,  27}, {0x7ffffe5,  27},
	{0xfffec,    20}, {0xfffff3,   24}, {0xfffed,    20}, {0x1fffe6,   21},
	{0x3fffe9,   22}, {0x1fffe7,   21}, {0x1fffe8,   21}, {0x7ffff3,   23},
	{0x3fffea,   22}, {0x3fffeb,   22}, {0x1ffffee,  25}, {0x1ffffef,  25},
	{0xfffff4,   24}, {0xfffff5,   24}, {0x3ffffea,  26}, {0x7ffff4,   23},
	{0x3ffffeb,  26}, {0x7ffffe6,  27}, {0x3ffffec,  26}, {0x3ffffed,  26},
	{0x7ffffe7,  27}, {0x7ffffe8,  27}, {0x7ffffe9,  27}, {0x7ffffea,  27},
	{0x7ffffeb,  27}, {0xffffffe,  28}, {0x7ffffec,  27}, {0x7ffffed,  27},
	{0x7ffffee,  27}, {0x7ffffef,  27}, {0x7fffff0,  27}, {0x3ffffee,  26},
	{0x3fffffff, 30}  /* EOS */
};

/* {{{ hpack_huffman_encode(string $input): string */
PHP_FUNCTION(hpack_huffman_encode)
{
	char *input;
	size_t input_len;
	uint8_t *output;
	size_t output_size;
	size_t bit_count = 0;
	size_t i, byte_pos;

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_STRING(input, input_len)
	ZEND_PARSE_PARAMETERS_END();

	if (input_len == 0) {
		RETURN_EMPTY_STRING();
	}

	/* Guard against integer overflow in buffer size calculation */
	if (input_len > SIZE_MAX / 4) {
		zend_throw_exception(zend_ce_value_error, "Input too large for Huffman encoding", 0);
		RETURN_THROWS();
	}

	/* Calculate output size: worst case is ~4x input (max code is 30 bits = 3.75 bytes) */
	output_size = input_len * 4 + 1;
	output = ecalloc(1, output_size);

	for (i = 0; i < input_len; i++) {
		uint8_t ch = (uint8_t)input[i];
		uint32_t code = huffman_table[ch].code;
		uint8_t bits = huffman_table[ch].bits;
		int remaining = bits;

		while (remaining > 0) {
			byte_pos = bit_count >> 3;
			int bit_offset = bit_count & 7;
			int available = 8 - bit_offset;

			if (remaining >= available) {
				output[byte_pos] |= (uint8_t)((code >> (remaining - available)) & ((1 << available) - 1));
				bit_count += available;
				remaining -= available;
			} else {
				output[byte_pos] |= (uint8_t)((code & ((1 << remaining) - 1)) << (available - remaining));
				bit_count += remaining;
				remaining = 0;
			}
		}
	}

	/* Pad with 1s (EOS prefix) */
	if (bit_count & 7) {
		byte_pos = bit_count >> 3;
		int pad_bits = 8 - (bit_count & 7);
		output[byte_pos] |= (uint8_t)((1 << pad_bits) - 1);
		bit_count += pad_bits;
	}

	size_t final_len = bit_count >> 3;
	RETVAL_STRINGL((char *)output, final_len);
	efree(output);
}
/* }}} */

/* {{{ hpack_huffman_decode(string $input): string|false */
PHP_FUNCTION(hpack_huffman_decode)
{
	char *input;
	size_t input_len;
	uint8_t *output;
	size_t output_size;
	size_t output_pos = 0;
	uint64_t accumulator = 0;
	uint8_t acc_bits = 0;

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_STRING(input, input_len)
	ZEND_PARSE_PARAMETERS_END();

	if (input_len == 0) {
		RETURN_EMPTY_STRING();
	}

	/* Output can be at most ~1.6x input, but 2x is safe */
	output_size = input_len * 2 + 1;
	output = emalloc(output_size);

	for (size_t i = 0; i < input_len; i++) {
		accumulator = (accumulator << 8) | (uint8_t)input[i];
		acc_bits += 8;

		while (acc_bits >= 5) {
			int found = 0;

			for (int sym = 0; sym < 256; sym++) {
				if (huffman_table[sym].bits <= acc_bits) {
					uint64_t mask = (1ULL << huffman_table[sym].bits) - 1;
					uint64_t candidate = (accumulator >> (acc_bits - huffman_table[sym].bits)) & mask;

					if (candidate == (uint64_t)huffman_table[sym].code) {
						if (output_pos >= output_size) {
							output_size *= 2;
							output = erealloc(output, output_size);
						}
						output[output_pos++] = (uint8_t)sym;
						acc_bits -= huffman_table[sym].bits;
						accumulator &= (1ULL << acc_bits) - 1;
						found = 1;
						break;
					}
				}
			}

			if (!found) {
				/*
				 * No data symbol matched. If we have >= 30 bits (the maximum
				 * code length in HPACK Huffman), the only remaining possibility
				 * is the EOS symbol (0x3FFFFFFF, 30 bits).
				 * RFC 7541 Section 5.2: "A Huffman-encoded string literal
				 * containing the EOS symbol MUST be treated as a decoding error."
				 */
				if (acc_bits >= 30) {
					efree(output);
					RETURN_FALSE;
				}
				break;
			}
		}
	}

	/* RFC 7541 Section 5.2: padding validation */
	if (acc_bits > 0 && acc_bits <= 7) {
		/* Padding must correspond to the most significant bits of EOS (all 1s) */
		uint64_t pad_mask = (1ULL << acc_bits) - 1;
		if ((accumulator & pad_mask) != pad_mask) {
			efree(output);
			RETURN_FALSE;
		}
	} else if (acc_bits > 7) {
		/* Padding strictly longer than 7 bits MUST be a decoding error */
		efree(output);
		RETURN_FALSE;
	}

	RETVAL_STRINGL((char *)output, output_pos);
	efree(output);
}
/* }}} */

/* ----------------------------------------------------------------
 * Arginfo
 * ---------------------------------------------------------------- */

ZEND_BEGIN_ARG_INFO_EX(arginfo_hpack_context_construct, 0, 0, 0)
	ZEND_ARG_TYPE_INFO(0, tableSize, IS_LONG, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_hpack_context_encode, 0, 1, IS_STRING, 0)
	ZEND_ARG_TYPE_INFO(0, headers, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_hpack_context_decode, 0, 2, IS_ARRAY, 1)
	ZEND_ARG_TYPE_INFO(0, input, IS_STRING, 0)
	ZEND_ARG_TYPE_INFO(0, maxSize, IS_LONG, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_hpack_huffman_encode, 0, 1, IS_STRING, 0)
	ZEND_ARG_TYPE_INFO(0, input, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_hpack_huffman_decode, 0, 1, IS_STRING, 0)
	ZEND_ARG_TYPE_INFO(0, input, IS_STRING, 0)
ZEND_END_ARG_INFO()

/* ----------------------------------------------------------------
 * Method/function tables
 * ---------------------------------------------------------------- */

static const zend_function_entry hpack_context_methods[] = {
	PHP_ME(HPackContext, __construct, arginfo_hpack_context_construct, ZEND_ACC_PUBLIC)
	PHP_ME(HPackContext, encode, arginfo_hpack_context_encode, ZEND_ACC_PUBLIC)
	PHP_ME(HPackContext, decode, arginfo_hpack_context_decode, ZEND_ACC_PUBLIC)
	PHP_FE_END
};

static const zend_function_entry hpack_functions[] = {
	PHP_FE(hpack_huffman_encode, arginfo_hpack_huffman_encode)
	PHP_FE(hpack_huffman_decode, arginfo_hpack_huffman_decode)
	PHP_FE_END
};

/* ----------------------------------------------------------------
 * Module lifecycle
 * ---------------------------------------------------------------- */

PHP_MINIT_FUNCTION(hpack)
{
	zend_class_entry ce;

	INIT_CLASS_ENTRY(ce, "HPackContext", hpack_context_methods);
	hpack_context_ce = zend_register_internal_class(&ce);
	hpack_context_ce->create_object = hpack_context_create;
	hpack_context_ce->ce_flags |= ZEND_ACC_FINAL | ZEND_ACC_NO_DYNAMIC_PROPERTIES;

	memcpy(&hpack_context_handlers, &std_object_handlers, sizeof(zend_object_handlers));
	hpack_context_handlers.offset = XtOffsetOf(hpack_context_obj, std);
	hpack_context_handlers.free_obj = hpack_context_free;
	hpack_context_handlers.clone_obj = NULL;

	return SUCCESS;
}

PHP_MSHUTDOWN_FUNCTION(hpack)
{
	return SUCCESS;
}

PHP_MINFO_FUNCTION(hpack)
{
	php_info_print_table_start();
	php_info_print_table_header(2, "hpack support", "enabled");
	php_info_print_table_row(2, "Version", PHP_HPACK_VERSION);
	php_info_print_table_row(2, "Backend", "libnghttp2");
	php_info_print_table_row(2, "nghttp2 version", NGHTTP2_VERSION);
	php_info_print_table_end();
}

zend_module_entry hpack_module_entry = {
	STANDARD_MODULE_HEADER,
	"hpack",
	hpack_functions,
	PHP_MINIT(hpack),
	PHP_MSHUTDOWN(hpack),
	NULL, /* RINIT */
	NULL, /* RSHUTDOWN */
	PHP_MINFO(hpack),
	PHP_HPACK_VERSION,
	STANDARD_MODULE_PROPERTIES
};

#ifdef COMPILE_DL_HPACK
ZEND_GET_MODULE(hpack)
#endif
