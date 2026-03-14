#ifndef PHP_HPACK_H
#define PHP_HPACK_H

extern zend_module_entry hpack_module_entry;
#define phpext_hpack_ptr &hpack_module_entry

#define PHP_HPACK_VERSION "1.1.1"

#ifdef PHP_WIN32
# define PHP_HPACK_API __declspec(dllexport)
#elif defined(__GNUC__) && __GNUC__ >= 4
# define PHP_HPACK_API __attribute__ ((visibility("default")))
#else
# define PHP_HPACK_API
#endif

PHP_MINIT_FUNCTION(hpack);
PHP_MSHUTDOWN_FUNCTION(hpack);
PHP_MINFO_FUNCTION(hpack);

#endif /* PHP_HPACK_H */
