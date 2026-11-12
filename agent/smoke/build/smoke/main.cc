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
#include <php_tp_smoke_runtime_decl.h>
#include <php_tp_smoke_main_ffebd67f58_decl.h>

namespace typephp_project_tp_smoke {
extern THREAD_LOCAL php::Var _global_var__SERVER;
}

void php_checkext(php::Str name) {
    php::Bool ok = 0;
    php::Var tmp_var_0;

    ok = php::toBool(php::call(get_persistent_func(PersistentFuncId{0}, get_str(0)), php::VarList{name}));
    tmp_var_0 = (php::toBool(ok)) ? (get_str(3)) : (get_str(4));
    php::echo(php::concat({php::toString(php::concat({get_str(1), name, get_str(2)})), tmp_var_0, get_str(5)}));
    tmp_var_0.unset();
}

void php_checkfn(php::Str name) {
    php::Var tmp_var_0;

    tmp_var_0 = (php::toBool(php::fn::function_exists(name))) ? (get_str(3)) : (get_str(4));
    php::echo(php::concat({php::toString(php::concat({get_str(6), name, get_str(2)})), tmp_var_0, get_str(5)}));
    tmp_var_0.unset();
}

void php_main(php::Int argc, php::Array argv) {
    php::Var tmp_var_0;
    php::Var tmp_var_1;
    php::Var tmp_var_2;
    php::Var tmp_var_3;
    php::Var tmp_var_4;
    php::Var tmp_var_5;
    php::Var tmp_var_6;
    php::Var tmp_var_7;
    php::Array cands;
    php::Var dll;
    php::Str fn;
    php::Var ok;
    php::Var tmp_var_10;
    php::Object pdo;
    php::Array tmp_var_12;
    php::Var tmp_var_13;
    php::Var tmp_var_14;
    php::Var tmp_var_15;
    php::Var tmp_var_16;
    php::Var tmp_var_17;
    php::Object e;
    php::Var msg;
    php::Var tmp_var_19;
    php::Var tmp_var_20;
    php::Int _php__var__errno = php::toInt(0LL);
    php::Str errstr = php::empty_string;
    php::Var srv;
    php::Array r;
    php::Array w;
    php::Array x;
    php::Var n;
    php::Array pipes;
    php::Var proc;
    php::Var tmp_var_29;
    php::Var tmp_var_30;
    php::Var tmp_var_31;
    php::Var out;
    php::Var tmp_var_34;
    php::Var tmp_var_35;
    php::Var tmp_var_36;
    php::Var tmp_var_37;
    php::Str binDir;
    php::Str envPath;
    php::Var tmp_var_38;
    php::Var tmp_var_39;
    php::Var tmp_var_40;
    php::Var tmp_var_41;
    php::Var tmp_var_42;
    php::Var tmp_var_43;
    php::Var tmp_var_44;
    php::Var tmp_var_45;
    php::Var tmp_var_46;
    php::Var tmp_var_47;
    zend_class_entry *tmp_var_11 = get_persistent_class(PersistentClassId{2}, get_str(67));

    php::echo(get_str(7));
    php::echo(php::concat({get_str(8), get_str(9), get_str(5)}));
    php::echo(php::concat({get_str(10), get_str(11), get_str(5)}));
    php::echo(php::concat({get_str(12), get_str(13), get_str(5)}));
    php::echo(php::concat({get_str(14), php::toString(get_str(15)), get_str(5)}));
    tmp_var_0 = php::call(get_persistent_func(PersistentFuncId{1}, get_str(17)));
    php::echo(php::concat({get_str(16), php::toString(tmp_var_0), get_str(5)}));
    tmp_var_0.unset();
    tmp_var_2 = php::exists(argv, {{php::ArrayDimFetch, php::Var(0LL)}}, tmp_var_1) ? php::Var(tmp_var_1) : php::Var(php::empty_string);
    tmp_var_3 = tmp_var_2;
    php::echo(php::concat({get_str(18), php::toString(tmp_var_3), get_str(5)}));
    tmp_var_3.unset();
    php_checkext(get_str(19));
    php_checkext(get_str(20));
    php_checkext(get_str(21));
    php_checkfn(get_str(22));
    php_checkfn(get_str(23));
    php_checkfn(get_str(24));
    php_checkfn(get_str(25));
    php_checkfn(get_str(26));
    php_checkfn(get_str(27));
    php_checkfn(get_str(28));
    php_checkfn(get_str(29));
    if (php::toBool(!(php::toBool(php::call(get_persistent_func(PersistentFuncId{0}, get_str(0)), php::VarList{get_str(20)}))))) {
        tmp_var_4=php::call(get_persistent_func(PersistentFuncId{13}, get_str(76)), php::VarList{get_str(77)});
        tmp_var_6 = php::notEmpty(tmp_var_4, {}, tmp_var_5) ? php::Var(tmp_var_5) : php::Var(get_str(78));
        tmp_var_7 = tmp_var_6;
        php::echo(php::concat({get_str(75), php::toString(tmp_var_7), get_str(5)}));
        tmp_var_7.unset();
        cands = php::Array{
            php::Var(get_str(79)), 
            php::Var(get_str(80))
        };
        {
            php::ForeachIterator tmp_var_8{cands, false, nullptr};
            while (tmp_var_8.nextValue(dll)) {
                fn = get_str(81);
                if (php::toBool(!(php::toBool(php::fn::function_exists(fn))))) {
                    php::echo(get_str(82));
                    break;
                }
                auto tmp_var_9 = EG(error_reporting);
                php::call(get_persistent_func(PersistentFuncId{3}, get_str(41)), {E_FATAL_ERRORS});
                ok = typephp_call_cached(fn, get_function_call_cache(FunctionCallCacheId{0}), php::VarList{dll});
                php::call(get_persistent_func(PersistentFuncId{3}, get_str(41)), {tmp_var_9});
                tmp_var_10 = (php::toBool(ok)) ? (get_str(3)) : (get_str(4));
                php::echo(php::concat({php::toString(php::concat({get_str(83), dll, get_str(84)})), tmp_var_10, get_str(5)}));
                tmp_var_10.unset();
                if (php::toBool(php::call(get_persistent_func(PersistentFuncId{0}, get_str(0)), php::VarList{get_str(20)}))) {
                    php::echo(get_str(85));
                    break;
                }
            }
        }
    }
    if (php::toBool(php::call(get_persistent_func(PersistentFuncId{0}, get_str(0)), php::VarList{get_str(20)}))) {
        try {
            tmp_var_12.clean();
            tmp_var_13 = 3LL;
            tmp_var_14 = 2LL;
            tmp_var_12.setValue(tmp_var_13, tmp_var_14);
            tmp_var_15 = 2LL;
            tmp_var_12.setValue(tmp_var_15, 1LL);
            tmp_var_16 = tmp_var_12;
            tmp_var_13.unset();
            tmp_var_14.unset();
            tmp_var_15.unset();
            pdo = php::newObject(tmp_var_11, php::VarList{get_str(30), get_str(31), get_str(31), tmp_var_16});
            tmp_var_16.unset();
            php::echo(get_str(32));
        } catch (zend_object *_ex) {
            tmp_var_17 = php::catchException();
            bool tmp_var_18 = false;
            if (!tmp_var_18 && tmp_var_17 && (php::instanceOf(tmp_var_17, get_persistent_class(PersistentClassId{0}, get_str(33))))) {
                tmp_var_18 = true;
                e = tmp_var_17;
                tmp_var_17 = php::null;
                msg = typephp_call_method_cached(e, get_str(34), get_method_call_cache(MethodCallCacheId{0}));
                php::echo(php::concat({get_str(35), php::toString(msg), get_str(5)}));
            }
            if (!tmp_var_18 && tmp_var_17 && (php::instanceOf(tmp_var_17, get_persistent_class(PersistentClassId{1}, get_str(36))))) {
                tmp_var_18 = true;
                e = tmp_var_17;
                tmp_var_17 = php::null;
                tmp_var_19 = php::toString(php::call(get_persistent_func(PersistentFuncId{2}, get_str(38)), php::VarList{e}));
                tmp_var_20 = typephp_call_method_cached(e, get_str(34), get_method_call_cache(MethodCallCacheId{1}));
                php::echo(php::concat({get_str(37), tmp_var_19, get_str(39), php::toString(tmp_var_20), get_str(5)}));
                tmp_var_19.unset();
                tmp_var_20.unset();
            }
        }
        if (tmp_var_17) {
            php::throwException(php::Object(tmp_var_17));
        }
    } else {
        php::echo(get_str(40));
    }
    auto tmp_var_21 = EG(error_reporting);
    php::call(get_persistent_func(PersistentFuncId{3}, get_str(41)), {E_FATAL_ERRORS});
    php::RefWrap<php::Int> tmp_var_22(_php__var__errno);
    php::RefWrap<php::Str> tmp_var_23(errstr);
    srv = php::call(get_persistent_func(PersistentFuncId{4}, get_str(22)), php::VarList{get_str(42), &tmp_var_22.ref(), &tmp_var_23.ref(), ((4LL) | (8LL))});
    tmp_var_22.commit();
    tmp_var_23.commit();
    php::call(get_persistent_func(PersistentFuncId{3}, get_str(41)), {tmp_var_21});
    if (php::toBool(php::fn::is_resource(srv))) {
        php::toBool(php::call(get_persistent_func(PersistentFuncId{5}, get_str(29)), php::VarList{srv, false}));
        php::echo(get_str(43));
        r = php::Array{
            php::Var(srv)
        };
        w = php::Array{};
        x = php::Array{};
        auto tmp_var_24 = EG(error_reporting);
        php::call(get_persistent_func(PersistentFuncId{3}, get_str(41)), {E_FATAL_ERRORS});
        php::RefWrap<php::Array> tmp_var_25(r);
        php::RefWrap<php::Array> tmp_var_26(w);
        php::RefWrap<php::Array> tmp_var_27(x);
        n = php::call(get_persistent_func(PersistentFuncId{6}, get_str(23)), php::VarList{&tmp_var_25.ref(), &tmp_var_26.ref(), &tmp_var_27.ref(), 0LL, 100000LL});
        tmp_var_25.commit();
        tmp_var_26.commit();
        tmp_var_27.commit();
        php::call(get_persistent_func(PersistentFuncId{3}, get_str(41)), {tmp_var_24});
        php::echo(php::toString(php::concat({get_str(44), n, get_str(45)})));
        php::toBool(php::call(get_persistent_func(PersistentFuncId{7}, get_str(46)), php::VarList{srv}));
    } else {
        php::echo(php::toString(php::concat({get_str(47), _php__var__errno, get_str(48), errstr, get_str(5)})));
    }
    pipes = php::Array{};
    auto tmp_var_28 = EG(error_reporting);
    php::call(get_persistent_func(PersistentFuncId{3}, get_str(41)), {E_FATAL_ERRORS});
    tmp_var_29 = php::Array{
            php::Var(get_str(50)), 
            php::Var(get_str(51))
        };
    tmp_var_30 = php::Array{
            php::Var(get_str(50)), 
            php::Var(get_str(51))
        };
    tmp_var_31 = php::Array{
        { 1LL, php::Var(tmp_var_29) }, 
        { 2LL, php::Var(tmp_var_30) }
    };
    tmp_var_29.unset();
    tmp_var_30.unset();
    php::RefWrap<php::Array> tmp_var_32(pipes);
    proc = php::call(get_persistent_func(PersistentFuncId{8}, get_str(24)), php::VarList{get_str(49), tmp_var_31, &tmp_var_32.ref()});
    tmp_var_31.unset();
    tmp_var_32.commit();
    php::call(get_persistent_func(PersistentFuncId{3}, get_str(41)), {tmp_var_28});
    if (php::toBool(php::fn::is_resource(proc))) {
        auto tmp_var_33 = EG(error_reporting);
        php::call(get_persistent_func(PersistentFuncId{3}, get_str(41)), {E_FATAL_ERRORS});
        tmp_var_34 = pipes.get(1LL);
        out = php::toString(php::call(get_persistent_func(PersistentFuncId{9}, get_str(52)), php::VarList{php::deindirect(tmp_var_34)}));
        tmp_var_34.unset();
        php::call(get_persistent_func(PersistentFuncId{3}, get_str(41)), {tmp_var_33});
        tmp_var_35 = pipes.get(1LL);
        php::toBool(php::call(get_persistent_func(PersistentFuncId{7}, get_str(46)), php::VarList{php::deindirect(tmp_var_35)}));
        tmp_var_35.unset();
        tmp_var_36 = pipes.get(2LL);
        php::toBool(php::call(get_persistent_func(PersistentFuncId{7}, get_str(46)), php::VarList{php::deindirect(tmp_var_36)}));
        tmp_var_36.unset();
        php::toInt(php::call(get_persistent_func(PersistentFuncId{10}, get_str(53)), php::VarList{proc}));
        tmp_var_37 = php::toString(php::call(get_persistent_func(PersistentFuncId{11}, get_str(55)), php::VarList{out}));
        php::echo(php::concat({get_str(54), tmp_var_37, get_str(45)}));
        tmp_var_37.unset();
    } else {
        php::echo(get_str(56));
    }
    binDir = php::fn::dirname(get_str(13));
    envPath = php::concat({binDir, get_str(57), get_str(58)});
    tmp_var_38 = (php::toBool(php::fn::is_file(envPath))) ? (get_str(3)) : (get_str(4));
    php::echo(php::concat({php::toString(php::concat({get_str(59), envPath, get_str(60)})), tmp_var_38, get_str(5)}));
    tmp_var_38.unset();
    tmp_var_39 = php::fn::random_bytes(4LL);
    tmp_var_40 = php::toString(php::call(get_persistent_func(PersistentFuncId{12}, get_str(27)), php::VarList{tmp_var_39}));
    tmp_var_39.unset();
    php::echo(php::concat({get_str(61), tmp_var_40, get_str(5)}));
    tmp_var_40.unset();
    tmp_var_41 = php::Array{
        { get_str(63).str(), php::Var(true) }, 
        { get_str(64).str(), php::Var(1LL) }
    };
    tmp_var_42 = php::Array{
        { get_str(63).str(), php::Var(true) }, 
        { get_str(64).str(), php::Var(1LL) }
    };
    tmp_var_44 = php::Array{
        { get_str(63).str(), php::Var(true) }, 
        { get_str(64).str(), php::Var(1LL) }
    };
    tmp_var_43=php::fn::json_encode(tmp_var_44, 256LL);
    tmp_var_46 = php::notEmpty(tmp_var_43, {}, tmp_var_45) ? php::Var(tmp_var_45) : php::Var(get_str(65));
    tmp_var_47 = tmp_var_46;
    tmp_var_41.unset();
    tmp_var_42.unset();
    tmp_var_44.unset();
    php::echo(php::concat({get_str(62), php::toString(tmp_var_47), get_str(5)}));
    tmp_var_47.unset();
    php::echo(get_str(66));
}

ZEND_FUNCTION(checkext) {
    PHPX_TRY {
        php::checkCallArgCount(1, 1, false);
        php::Var raw_arg_name = php::getCallArg(0);
        if (UNEXPECTED(!(raw_arg_name.isString()))) {
            php::throwArgumentTypeError(raw_arg_name, get_str(68), 1, get_str(69), get_str(70));
        }
        php::Str arg_name = php::toString(raw_arg_name);
        php_checkext(php::takeValue(arg_name));
    } PHPX_CATCH(zend_object *, typephp_wrapper_exception) {
        /* EG(exception) is already set; return control to ZendVM for frame cleanup. */
    }
}

ZEND_FUNCTION(checkfn) {
    PHPX_TRY {
        php::checkCallArgCount(1, 1, false);
        php::Var raw_arg_name = php::getCallArg(0);
        if (UNEXPECTED(!(raw_arg_name.isString()))) {
            php::throwArgumentTypeError(raw_arg_name, get_str(71), 1, get_str(69), get_str(70));
        }
        php::Str arg_name = php::toString(raw_arg_name);
        php_checkfn(php::takeValue(arg_name));
    } PHPX_CATCH(zend_object *, typephp_wrapper_exception) {
        /* EG(exception) is already set; return control to ZendVM for frame cleanup. */
    }
}

ZEND_FUNCTION(main) {
    PHPX_TRY {
        php::checkCallArgCount(2, 2, false);
        php::Var raw_arg_argc = php::getCallArg(0);
        if (UNEXPECTED(!(raw_arg_argc.isInt()))) {
            php::throwArgumentTypeError(raw_arg_argc, get_str(72), 1, get_str(73), get_str(74));
        }
        php::Int arg_argc = php::toInt(raw_arg_argc);
        php::Array arg_argv = php::toArray(php::getCallArg(1));
        const char *value = "D:\\git\\php\\typephp_dms\\agent\\smoke\\main.php";
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

