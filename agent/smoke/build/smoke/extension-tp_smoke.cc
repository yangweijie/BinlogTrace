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
#include <php_main_arginfo.h>
#include <new>
#include <typephp_runtime.h>
extern "C" void typephp_opcode_table_install(void) {}
extern "C" void typephp_opcode_table_uninstall(void) {}
extern "C" {
#include "php_cli_process_title.h"
#include "php_cli_process_title_arginfo.h"
}
namespace typephp_project_tp_smoke {

// global vars 
THREAD_LOCAL php::Var _global_var__SERVER;
// class register functions 
// request-local caches 
struct php_request_cache_storage final {
    php_request_cache_storage() = default;
    php_request_cache_storage(const php_request_cache_storage &) = delete;
    php_request_cache_storage(php_request_cache_storage &&) = delete;
    php_request_cache_storage &operator=(const php_request_cache_storage &) = delete;
    php_request_cache_storage &operator=(php_request_cache_storage &&) = delete;
    zend_class_entry *class_map[1]{};
    zend_function *func_map[1]{};
    php::PropertyCacheSlot property_cache_map[1]{};
    php::MethodCallCacheSlot method_call_cache_map[2]{};
    php::FunctionCallCacheSlot function_call_cache_map[1]{};
    uint8_t function_resolution_cache_map[1]{};
};
static THREAD_LOCAL php_request_cache_storage *php_request_cache = nullptr;
// class entry 
static php::PersistentCacheSlot<zend_class_entry *> php_persistent_class_map[3]{};
// func 
static php::PersistentCacheSlot<zend_function *> php_persistent_func_map[14]{};
// property 
static php::PersistentCacheSlot<uint32_t> php_persistent_property_map[1]{};
// functions 
zend_class_entry *get_class(RequestClassId class_id, const php::Str &class_name) {
    const auto index = static_cast<uint32_t>(class_id);
    auto &slot = php_request_cache->class_map[index];
    if (UNEXPECTED(slot == nullptr)) {
        slot = php::getClassEntrySafe(class_name);
    }
    return slot;
}

zend_function *get_func(RequestFuncId func_id, const php::Str &func_name) {
    const auto index = static_cast<uint32_t>(func_id);
    auto &slot = php_request_cache->func_map[index];
    if (UNEXPECTED(slot == nullptr)) {
        slot = php::getFunction(func_name);
    }
    return slot;
}

zend_function *get_method(RequestFuncId func_id, const php::Str &method_name, RequestClassId class_id, const php::Str &class_name) {
    const auto index = static_cast<uint32_t>(func_id);
    auto &slot = php_request_cache->func_map[index];
    if (UNEXPECTED(slot == nullptr)) {
        auto ce = get_class(class_id, class_name);
        slot = php::getMethod(ce, method_name);
    }
    return slot;
}

zend_class_entry *get_persistent_class(PersistentClassId class_id, const php::Str &class_name) {
    const auto index = static_cast<uint32_t>(class_id);
    return php::getPersistentCache(php_persistent_class_map[index], [&]() {
        return php::getClassEntrySafe(class_name);
    });
}

zend_function *get_persistent_func(PersistentFuncId func_id, const php::Str &func_name) {
    const auto index = static_cast<uint32_t>(func_id);
    return php::getPersistentCache(php_persistent_func_map[index], [&]() {
        return php::getFunction(func_name);
    });
}

zend_function *get_persistent_method(PersistentFuncId func_id, const php::Str &method_name, PersistentClassId class_id, const php::Str &class_name) {
    const auto index = static_cast<uint32_t>(func_id);
    return php::getPersistentCache(php_persistent_func_map[index], [&]() {
        auto ce = get_persistent_class(class_id, class_name);
        return php::getMethod(ce, method_name);
    });
}

uint32_t get_persistent_prop(PersistentPropertyId prop_id, const php::Str &prop_name, const php::Str &class_name) {
    const auto index = static_cast<uint32_t>(prop_id);
    auto value = php::getPersistentCache(php_persistent_property_map[index], [&]() {
        return php::getPropertyOffset(class_name, prop_name) + 1024;
    });
    return value - 1024;
}

php::PropertyCacheSlot &get_property_cache(PropertyCacheId cache_id) noexcept {
    return php_request_cache->property_cache_map[static_cast<uint32_t>(cache_id)];
}

php::MethodCallCacheSlot &get_method_call_cache(MethodCallCacheId cache_id) noexcept {
    return php_request_cache->method_call_cache_map[static_cast<uint32_t>(cache_id)];
}

php::FunctionCallCacheSlot &get_function_call_cache(FunctionCallCacheId cache_id) noexcept {
    return php_request_cache->function_call_cache_map[static_cast<uint32_t>(cache_id)];
}

uint8_t &get_function_resolution_cache(FunctionResolutionCacheId cache_id) noexcept {
    return php_request_cache->function_resolution_cache_map[static_cast<uint32_t>(cache_id)];
}

// literal strings 
static php::Str _literal_strings[] = {
php::Str{ZEND_STRL("extension_loaded"), true}, // [0]
php::Str{ZEND_STRL("ext "), true}, // [1]
php::Str{ZEND_STRL(" = "), true}, // [2]
php::Str{ZEND_STRL("true"), true}, // [3]
php::Str{ZEND_STRL("false"), true}, // [4]
php::Str{ZEND_STRL("\n"), true}, // [5]
php::Str{ZEND_STRL("fn  "), true}, // [6]
php::Str{ZEND_STRL("=== typephp runtime smoke ===\n"), true}, // [7]
php::Str{ZEND_STRL("PHP_VERSION = "), true}, // [8]
php::Str{ZEND_STRL("8.5.11"), true}, // [9]
php::Str{ZEND_STRL("PHP_SAPI    = "), true}, // [10]
php::Str{ZEND_STRL("embed"), true}, // [11]
php::Str{ZEND_STRL("PHP_BINARY  = "), true}, // [12]
php::Str{ZEND_STRL("D:\\git\\php\\tpc_v0.9.5_windows_x64\\tpc.exe"), true}, // [13]
php::Str{ZEND_STRL("__DIR__     = "), true}, // [14]
php::Str{ZEND_STRL("D:\\git\\php\\typephp_dms\\agent\\smoke"), true}, // [15]
php::Str{ZEND_STRL("getcwd()    = "), true}, // [16]
php::Str{ZEND_STRL("getcwd"), true}, // [17]
php::Str{ZEND_STRL("argv0       = "), true}, // [18]
php::Str{ZEND_STRL("pdo"), true}, // [19]
php::Str{ZEND_STRL("pdo_mysql"), true}, // [20]
php::Str{ZEND_STRL("sockets"), true}, // [21]
php::Str{ZEND_STRL("stream_socket_server"), true}, // [22]
php::Str{ZEND_STRL("stream_select"), true}, // [23]
php::Str{ZEND_STRL("proc_open"), true}, // [24]
php::Str{ZEND_STRL("proc_get_status"), true}, // [25]
php::Str{ZEND_STRL("getenv"), true}, // [26]
php::Str{ZEND_STRL("bin2hex"), true}, // [27]
php::Str{ZEND_STRL("random_bytes"), true}, // [28]
php::Str{ZEND_STRL("stream_set_blocking"), true}, // [29]
php::Str{ZEND_STRL("mysql:host=127.0.0.1;port=1;charset=utf8mb4"), true}, // [30]
php::Str{ZEND_STRL("x"), true}, // [31]
php::Str{ZEND_STRL("pdo mysql construct = ok\n"), true}, // [32]
php::Str{ZEND_STRL("PDOException"), true}, // [33]
php::Str{ZEND_STRL("getMessage"), true}, // [34]
php::Str{ZEND_STRL("pdo mysql construct threw (driver present?): "), true}, // [35]
php::Str{ZEND_STRL("Throwable"), true}, // [36]
php::Str{ZEND_STRL("pdo other throwable: "), true}, // [37]
php::Str{ZEND_STRL("get_class"), true}, // [38]
php::Str{ZEND_STRL(": "), true}, // [39]
php::Str{ZEND_STRL("pdo mysql construct = SKIPPED (driver missing)\n"), true}, // [40]
php::Str{ZEND_STRL("error_reporting"), true}, // [41]
php::Str{ZEND_STRL("tcp://127.0.0.1:19871"), true}, // [42]
php::Str{ZEND_STRL("stream_socket_server = ok (19871)\n"), true}, // [43]
php::Str{ZEND_STRL("stream_select = ok (n="), true}, // [44]
php::Str{ZEND_STRL(")\n"), true}, // [45]
php::Str{ZEND_STRL("fclose"), true}, // [46]
php::Str{ZEND_STRL("stream_socket_server = FAIL ("), true}, // [47]
php::Str{ZEND_STRL(") "), true}, // [48]
php::Str{ZEND_STRL("cmd /c echo smoke-proc-ok"), true}, // [49]
php::Str{ZEND_STRL("pipe"), true}, // [50]
php::Str{ZEND_STRL("w"), true}, // [51]
php::Str{ZEND_STRL("stream_get_contents"), true}, // [52]
php::Str{ZEND_STRL("proc_close"), true}, // [53]
php::Str{ZEND_STRL("proc_open = ok (out="), true}, // [54]
php::Str{ZEND_STRL("trim"), true}, // [55]
php::Str{ZEND_STRL("proc_open = FAIL\n"), true}, // [56]
php::Str{ZEND_STRL("\\"), true}, // [57]
php::Str{ZEND_STRL(".env"), true}, // [58]
php::Str{ZEND_STRL("env candidate path = "), true}, // [59]
php::Str{ZEND_STRL(" exists="), true}, // [60]
php::Str{ZEND_STRL("token = "), true}, // [61]
php::Str{ZEND_STRL("json = "), true}, // [62]
php::Str{ZEND_STRL("ok"), true}, // [63]
php::Str{ZEND_STRL("n"), true}, // [64]
php::Str{ZEND_STRL("FAIL"), true}, // [65]
php::Str{ZEND_STRL("=== smoke done ===\n"), true}, // [66]
php::Str{ZEND_STRL("PDO"), true}, // [67]
php::Str{ZEND_STRL("checkExt"), true}, // [68]
php::Str{ZEND_STRL("name"), true}, // [69]
php::Str{ZEND_STRL("string"), true}, // [70]
php::Str{ZEND_STRL("checkFn"), true}, // [71]
php::Str{ZEND_STRL("main"), true}, // [72]
php::Str{ZEND_STRL("argc"), true}, // [73]
php::Str{ZEND_STRL("int"), true}, // [74]
php::Str{ZEND_STRL("extension_dir = "), true}, // [75]
php::Str{ZEND_STRL("ini_get"), true}, // [76]
php::Str{ZEND_STRL("extension_dir"), true}, // [77]
php::Str{ZEND_STRL("(empty)"), true}, // [78]
php::Str{ZEND_STRL("php_pdo_mysql.dll"), true}, // [79]
php::Str{ZEND_STRL("D:/git/php/tpc_v0.9.5_windows_x64/ext/php_pdo_mysql.dll"), true}, // [80]
php::Str{ZEND_STRL("dl"), true}, // [81]
php::Str{ZEND_STRL("dl() not available\n"), true}, // [82]
php::Str{ZEND_STRL("dl("), true}, // [83]
php::Str{ZEND_STRL(") = "), true}, // [84]
php::Str{ZEND_STRL("pdo_mysql loaded via dl\n"), true}, // [85]
};

ZEND_ATTRIBUTE_CONST php::Str &get_str(uint32_t index) noexcept {
    return _literal_strings[index];
}

}  // namespace typephp_project_tp_smoke

// native/import stub callbacks 
// default argument values 
namespace typephp_project_tp_smoke {

// constants 
// class 
// clang-format off
static const zend_function_entry ext_functions[] = {
    PHP_FE(cli_set_process_title,        arginfo_cli_set_process_title)
    PHP_FE(cli_get_process_title,        arginfo_cli_get_process_title)
    ZEND_RAW_FENTRY("checkExt", ZEND_FN(checkext), arginfo_checkext, ZEND_ACC_STRICT_TYPES, NULL, NULL)
    ZEND_RAW_FENTRY("checkFn", ZEND_FN(checkfn), arginfo_checkfn, ZEND_ACC_STRICT_TYPES, NULL, NULL)
    ZEND_RAW_FENTRY("main", ZEND_FN(main), arginfo_main, ZEND_ACC_STRICT_TYPES, NULL, NULL)
    ZEND_FE_END
};
// clang-format on

PHP_MINIT_FUNCTION(typephp_tp_smoke) {
// class/interface class entries
if (typephp_install_reflection_attribute_handlers() != SUCCESS) {
    return FAILURE;
}
typephp_register_fiber_generator_class();
if (type == MODULE_TEMPORARY) {
    EG(current_module)->type = MODULE_PERSISTENT;
    zend_interned_strings_switch_storage(false);
}

// register symbols
if (type == MODULE_TEMPORARY) {
    zend_interned_strings_switch_storage(true);
    EG(current_module)->type = MODULE_TEMPORARY;
}
return SUCCESS;
}

PHP_MSHUTDOWN_FUNCTION(typephp_tp_smoke) {
for (auto &slot : php_persistent_class_map) {
    php::resetPersistentCache(slot);
}
for (auto &slot : php_persistent_func_map) {
    php::resetPersistentCache(slot);
}
for (auto &slot : php_persistent_property_map) {
    php::resetPersistentCache(slot);
}
typephp_unregister_fiber_generator_class();
typephp_uninstall_reflection_attribute_handlers();
return SUCCESS;
}

THREAD_LOCAL zval globals_array;
static void module_init() {
// register constants
// global vars 
php::initGlobal("_SERVER", _global_var__SERVER);
// static property 
// class array constants
}

static void module_clean() {
_global_var__SERVER.unset();
php::unsetGlobal("_SERVER");
// class array constants
}

PHP_RINIT_FUNCTION(typephp_tp_smoke) {
if (UNEXPECTED(php_request_cache != nullptr)) {
    php_error_docref(nullptr, E_WARNING, "TypePHP request cache is already initialized");
    return FAILURE;
}
php_request_cache = new (std::nothrow) php_request_cache_storage{};
if (UNEXPECTED(php_request_cache == nullptr)) {
    php_error_docref(nullptr, E_WARNING, "Unable to allocate TypePHP request cache");
    return FAILURE;
}
php::request_init();
module_init();
php::eval(std::string(24, '\n') + "global $argc, $argv; main($argc, $argv);", "D:\\git\\php\\typephp_dms\\agent\\smoke\\main.php");
return SUCCESS;
}

PHP_RSHUTDOWN_FUNCTION(typephp_tp_smoke) {
    php::request_shutdown();
    delete php_request_cache;
    php_request_cache = nullptr;
    module_clean();    return SUCCESS;
}
zend_module_entry typephp_tp_smoke_module_entry = {
    STANDARD_MODULE_HEADER,
    "typephp_tp_smoke",
    ext_functions,
    PHP_MINIT(typephp_tp_smoke),
    PHP_MSHUTDOWN(typephp_tp_smoke),
    PHP_RINIT(typephp_tp_smoke),
    PHP_RSHUTDOWN(typephp_tp_smoke),
    nullptr,
    nullptr,
    STANDARD_MODULE_PROPERTIES,
};

}  // namespace typephp_project_tp_smoke

TYPEPHP_EMBED_GET_MODULE_FUNCTION(tp_smoke) {
    return &typephp_project_tp_smoke::typephp_tp_smoke_module_entry;
}

TYPEPHP_EMBED_PRE_SHUTDOWN_FUNCTION(tp_smoke) {
}
