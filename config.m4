dnl config.m4 for extension hpack

PHP_ARG_ENABLE([hpack],
  [whether to enable hpack support],
  [AS_HELP_STRING([--enable-hpack],
    [Enable hpack support])],
  [no])

if test "$PHP_HPACK" != "no"; then
  dnl Check for nghttp2
  AC_CHECK_HEADER([nghttp2/nghttp2.h], [],
    [AC_MSG_ERROR([nghttp2 headers not found. Install libnghttp2-devel.])])

  PHP_CHECK_LIBRARY(nghttp2, nghttp2_hd_deflate_new,
    [PHP_ADD_LIBRARY(nghttp2, 1, HPACK_SHARED_LIBADD)],
    [AC_MSG_ERROR([libnghttp2 not found or missing nghttp2_hd_deflate_new])])

  PHP_SUBST(HPACK_SHARED_LIBADD)
  PHP_NEW_EXTENSION(hpack, hpack.c, $ext_shared)
fi
