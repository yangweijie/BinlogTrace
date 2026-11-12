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
#include <php_main_arginfo.h>
#include <new>
#include <typephp_runtime.h>
extern "C" void typephp_opcode_table_install(void) {}
extern "C" void typephp_opcode_table_uninstall(void) {}
extern "C" {
#include "php_cli_process_title.h"
#include "php_cli_process_title_arginfo.h"
}
namespace typephp_project_tp_probe {

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
    php::MethodCallCacheSlot method_call_cache_map[1]{};
    php::FunctionCallCacheSlot function_call_cache_map[1]{};
    uint8_t function_resolution_cache_map[1]{};
};
static THREAD_LOCAL php_request_cache_storage *php_request_cache = nullptr;
// class entry 
static php::PersistentCacheSlot<zend_class_entry *> php_persistent_class_map[1]{};
// func 
static php::PersistentCacheSlot<zend_function *> php_persistent_func_map[4]{};
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
php::Str{ZEND_STRL("p"), true}, // [0]
php::Str{ZEND_STRL("REQUIRE OK: "), true}, // [1]
php::Str{ZEND_STRL("\n"), true}, // [2]
php::Str{ZEND_STRL("Throwable"), true}, // [3]
php::Str{ZEND_STRL("REQUIRE FAIL: "), true}, // [4]
php::Str{ZEND_STRL(" => "), true}, // [5]
php::Str{ZEND_STRL("getMessage"), true}, // [6]
php::Str{ZEND_STRL("file_get_contents"), true}, // [7]
php::Str{ZEND_STRL("READ "), true}, // [8]
php::Str{ZEND_STRL(": is_file="), true}, // [9]
php::Str{ZEND_STRL("Y"), true}, // [10]
php::Str{ZEND_STRL("N"), true}, // [11]
php::Str{ZEND_STRL(" len="), true}, // [12]
php::Str{ZEND_STRL("strlen"), true}, // [13]
php::Str{ZEND_STRL("=== embed probe ===\n"), true}, // [14]
php::Str{ZEND_STRL("argv0="), true}, // [15]
php::Str{ZEND_STRL("payload/hello.php"), true}, // [16]
php::Str{ZEND_STRL("D:\\git\\php\\typephp_dms\\agent\\probe"), true}, // [17]
php::Str{ZEND_STRL("/payload/hello.php"), true}, // [18]
php::Str{ZEND_STRL("probe/payload/hello.php"), true}, // [19]
php::Str{ZEND_STRL("class ProbePayload\\Greeting = "), true}, // [20]
php::Str{ZEND_STRL("ProbePayload\\Greeting"), true}, // [21]
php::Str{ZEND_STRL("LOADED"), true}, // [22]
php::Str{ZEND_STRL("MISSING"), true}, // [23]
php::Str{ZEND_STRL("call: "), true}, // [24]
php::Str{ZEND_STRL("ProbePayload\\Greeting::hi"), true}, // [25]
php::Str{ZEND_STRL("payload/data.txt"), true}, // [26]
php::Str{ZEND_STRL("/payload/data.txt"), true}, // [27]
php::Str{ZEND_STRL("=== done ===\n"), true}, // [28]
php::Str{ZEND_STRL("tryRequire"), true}, // [29]
php::Str{ZEND_STRL("string"), true}, // [30]
php::Str{ZEND_STRL("tryRead"), true}, // [31]
php::Str{ZEND_STRL("main"), true}, // [32]
php::Str{ZEND_STRL("argc"), true}, // [33]
php::Str{ZEND_STRL("int"), true}, // [34]
php::Str{ZEND_STRL("dirname"), true}, // [35]
php::Str{ZEND_STRL("realpath"), true}, // [36]
php::Str{ZEND_STRL("."), true}, // [37]
php::Str{ZEND_STRL("binDir="), true}, // [38]
php::Str{ZEND_STRL("/ondisk/plain.php"), true}, // [39]
php::Str{ZEND_STRL("class ProbeDisk\\Plain = "), true}, // [40]
php::Str{ZEND_STRL("ProbeDisk\\Plain"), true}, // [41]
php::Str{ZEND_STRL("global var from disk script = "), true}, // [42]
php::Str{ZEND_STRL("probeDiskGlobal"), true}, // [43]
php::Str{ZEND_STRL("ProbeDisk\\Plain::hi"), true}, // [44]
};

ZEND_ATTRIBUTE_CONST php::Str &get_str(uint32_t index) noexcept {
    return _literal_strings[index];
}

}  // namespace typephp_project_tp_probe

// native/import stub callbacks 
// default argument values 
namespace typephp_project_tp_probe {

// constants 
// class 
// clang-format off
static const zend_function_entry ext_functions[] = {
    PHP_FE(cli_set_process_title,        arginfo_cli_set_process_title)
    PHP_FE(cli_get_process_title,        arginfo_cli_get_process_title)
    ZEND_RAW_FENTRY("tryRequire", ZEND_FN(tryrequire), arginfo_tryrequire, ZEND_ACC_STRICT_TYPES, NULL, NULL)
    ZEND_RAW_FENTRY("tryRead", ZEND_FN(tryread), arginfo_tryread, ZEND_ACC_STRICT_TYPES, NULL, NULL)
    ZEND_RAW_FENTRY("main", ZEND_FN(main), arginfo_main, ZEND_ACC_STRICT_TYPES, NULL, NULL)
    ZEND_FE_END
};
// clang-format on

PHP_MINIT_FUNCTION(typephp_tp_probe) {
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

PHP_MSHUTDOWN_FUNCTION(typephp_tp_probe) {
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

PHP_RINIT_FUNCTION(typephp_tp_probe) {
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
php::eval(std::string(21, '\n') + "global $argc, $argv; main($argc, $argv);", "D:\\git\\php\\typephp_dms\\agent\\probe\\main.php");
return SUCCESS;
}

PHP_RSHUTDOWN_FUNCTION(typephp_tp_probe) {
    php::request_shutdown();
    delete php_request_cache;
    php_request_cache = nullptr;
    module_clean();    return SUCCESS;
}
zend_module_entry typephp_tp_probe_module_entry = {
    STANDARD_MODULE_HEADER,
    "typephp_tp_probe",
    ext_functions,
    PHP_MINIT(typephp_tp_probe),
    PHP_MSHUTDOWN(typephp_tp_probe),
    PHP_RINIT(typephp_tp_probe),
    PHP_RSHUTDOWN(typephp_tp_probe),
    nullptr,
    nullptr,
    STANDARD_MODULE_PROPERTIES,
};

}  // namespace typephp_project_tp_probe

TYPEPHP_EMBED_GET_MODULE_FUNCTION(tp_probe) {
    return &typephp_project_tp_probe::typephp_tp_probe_module_entry;
}

TYPEPHP_EMBED_PRE_SHUTDOWN_FUNCTION(tp_probe) {
}
