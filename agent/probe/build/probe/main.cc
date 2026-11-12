#include <cstring>
#include <phpx.h>
#include <phpx_helper.h>
#include <phpx_big_int.h>
#include <phpx_big_float.h>
#include <phpx_decimal.h>
#include <phpx_python.h>
#include <typephp_helper.h>
#include <typephp_fiber_generator.h>
#include <phpx_std.h>
#include <php_tp_probe_runtime_decl.h>
#include <php_tp_probe_main_3cb769abc8_decl.h>

namespace typephp_project_tp_probe {
extern THREAD_LOCAL php::Var _global_var__SERVER;
}

void php_tryrequire(php::Str p) {
    php::Var tmp_var_0;
    php::Object e;
    php::Var tmp_var_2;

    try {
        php::include(php::Var(p), php::REQUIRE, php::Array{{ get_str(0).str(), php::Var(p) }});
        php::echo(php::toString(php::concat({get_str(1), p, get_str(2)})));
    } catch (zend_object *_ex) {
        tmp_var_0 = php::catchException();
        bool tmp_var_1 = false;
        if (!tmp_var_1 && tmp_var_0 && (php::instanceOf(tmp_var_0, get_persistent_class(PersistentClassId{0}, get_str(3))))) {
            tmp_var_1 = true;
            e = tmp_var_0;
            tmp_var_0 = php::null;
            tmp_var_2 = typephp_call_method_cached(e, get_str(6), get_method_call_cache(MethodCallCacheId{0}));
            php::echo(php::concat({php::toString(php::concat({get_str(4), p, get_str(5)})), php::toString(tmp_var_2), get_str(2)}));
            tmp_var_2.unset();
        }
    }
    if (tmp_var_0) {
        php::throwException(php::Object(tmp_var_0));
    }
}

void php_tryread(php::Str p) {
    php::Bool is = 0;
    php::Var c;
    php::Var tmp_var_0;
    php::Int tmp_var_1 = 0;

    is = php::toBool(php::fn::is_file(p));
    c = (php::toBool(is)) ? (php::Var(php::call(get_persistent_func(PersistentFuncId{0}, get_str(7)), php::VarList{p}))) : (php::Var(false));
    tmp_var_0 = (php::toBool(is)) ? (get_str(10)) : (get_str(11));
    tmp_var_1 = php::toInt((php::toBool(php::same(c, php::false_))) ? (-1LL) : (php::toInt(php::call(get_persistent_func(PersistentFuncId{1}, get_str(13)), php::VarList{c}))));
    php::echo(php::concat({php::toString(php::concat({get_str(8), p, get_str(9)})), tmp_var_0, get_str(12), tmp_var_1, get_str(2)}));
    tmp_var_0.unset();
}

void php_main(php::Int argc, php::Array argv) {
    php::Var tmp_var_0;
    php::Var tmp_var_1;
    php::Var tmp_var_2;
    php::Str binDir;
    php::Var tmp_var_3;
    php::Var tmp_var_4;
    php::Var tmp_var_5;
    php::Var tmp_var_6;
    php::Var tmp_var_7;
    php::Var tmp_var_8;

    php::echo(get_str(14));
    tmp_var_1 = php::exists(argv, {{php::ArrayDimFetch, php::Var(0LL)}}, tmp_var_0) ? php::Var(tmp_var_0) : php::Var(php::empty_string);
    tmp_var_2 = tmp_var_1;
    php::echo(php::concat({get_str(15), php::toString(tmp_var_2), get_str(2)}));
    tmp_var_2.unset();
    tmp_var_4 = php::exists(argv, {{php::ArrayDimFetch, php::Var(0LL)}}, tmp_var_3) ? php::Var(tmp_var_3) : php::Var(get_str(37));
    tmp_var_5 = tmp_var_4;
    tmp_var_6 = php::call(get_persistent_func(PersistentFuncId{3}, get_str(36)), php::VarList{tmp_var_5});
    tmp_var_5.unset();
    binDir = php::toString(php::call(get_persistent_func(PersistentFuncId{2}, get_str(35)), php::VarList{tmp_var_6}));
    tmp_var_6.unset();
    php::echo(php::toString(php::concat({get_str(38), binDir, get_str(2)})));
    php_tryrequire(php::concat(binDir, get_str(39)));
    tmp_var_7 = (php::toBool(php::fn::class_exists(get_str(41), true))) ? (get_str(22)) : (get_str(23));
    php::echo(php::concat({get_str(40), tmp_var_7, get_str(2)}));
    tmp_var_7.unset();
    if (php::toBool(php::fn::class_exists(get_str(41), true))) {
        tmp_var_8 = typephp_call_cached(get_str(44), get_function_call_cache(FunctionCallCacheId{0}));
        php::echo(php::concat({get_str(24), php::toString(tmp_var_8), get_str(2)}));
        tmp_var_8.unset();
    }
    php_tryread(get_str(26));
    php_tryread(php::concat({php::toString(get_str(17)), get_str(27)}));
    php::echo(get_str(28));
}

ZEND_FUNCTION(tryrequire) {
    PHPX_TRY {
        php::checkCallArgCount(1, 1, false);
        php::Var raw_arg_p = php::getCallArg(0);
        if (UNEXPECTED(!(raw_arg_p.isString()))) {
            php::throwArgumentTypeError(raw_arg_p, get_str(29), 1, get_str(0), get_str(30));
        }
        php::Str arg_p = php::toString(raw_arg_p);
        php_tryrequire(php::takeValue(arg_p));
    } PHPX_CATCH(zend_object *, typephp_wrapper_exception) {
        /* EG(exception) is already set; return control to ZendVM for frame cleanup. */
    }
}

ZEND_FUNCTION(tryread) {
    PHPX_TRY {
        php::checkCallArgCount(1, 1, false);
        php::Var raw_arg_p = php::getCallArg(0);
        if (UNEXPECTED(!(raw_arg_p.isString()))) {
            php::throwArgumentTypeError(raw_arg_p, get_str(31), 1, get_str(0), get_str(30));
        }
        php::Str arg_p = php::toString(raw_arg_p);
        php_tryread(php::takeValue(arg_p));
    } PHPX_CATCH(zend_object *, typephp_wrapper_exception) {
        /* EG(exception) is already set; return control to ZendVM for frame cleanup. */
    }
}

ZEND_FUNCTION(main) {
    PHPX_TRY {
        php::checkCallArgCount(2, 2, false);
        php::Var raw_arg_argc = php::getCallArg(0);
        if (UNEXPECTED(!(raw_arg_argc.isInt()))) {
            php::throwArgumentTypeError(raw_arg_argc, get_str(32), 1, get_str(33), get_str(34));
        }
        php::Int arg_argc = php::toInt(raw_arg_argc);
        php::Array arg_argv = php::toArray(php::getCallArg(1));
        const char *value = "D:\\git\\php\\typephp_dms\\agent\\probe\\main.php";
        php::Var &_SERVER = _global_var__SERVER;
        _SERVER.item("PHP_SELF", true) = value;
        _SERVER.item("SCRIPT_NAME", true) = value;
        _SERVER.item("SCRIPT_FILENAME", true) = value;
        _SERVER.item("PATH_TRANSLATED", true) = value;
        _SERVER.item("DOCUMENT_ROOT", true) = php::empty_string;

        php_main(arg_argc,php::takeValue(arg_argv));
    } PHPX_CATCH(zend_object *, typephp_wrapper_exception) {
        /* EG(exception) is already set; return control to ZendVM for frame cleanup. */
    }
}

