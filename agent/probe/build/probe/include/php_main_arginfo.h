/* This is a generated file, edit the .stub.php file instead.
 * Stub hash: 514464906a429c26cf31d62d5e54d68a105e7dcf */

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_tryrequire, 0, 1, IS_VOID, 0)
	ZEND_ARG_TYPE_INFO(0, p, IS_STRING, 0)
ZEND_END_ARG_INFO()

#define arginfo_tryread arginfo_tryrequire

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_main, 0, 2, IS_VOID, 0)
	ZEND_ARG_TYPE_INFO(0, argc, IS_LONG, 0)
	ZEND_ARG_TYPE_INFO(0, argv, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

ZEND_FUNCTION(tryrequire);
ZEND_FUNCTION(tryread);
ZEND_FUNCTION(main);
