#pragma once

#include <phpx.h>
#include <typephp_helper.h>


namespace typephp_project_tp_probe {
enum class RequestClassId : uint32_t {};
enum class PersistentClassId : uint32_t {};
enum class RequestFuncId : uint32_t {};
enum class PersistentFuncId : uint32_t {};
enum class PersistentPropertyId : uint32_t {};
enum class PropertyCacheId : uint32_t {};
enum class MethodCallCacheId : uint32_t {};
enum class FunctionCallCacheId : uint32_t {};
enum class FunctionResolutionCacheId : uint32_t {};
zend_class_entry *get_class(RequestClassId class_id, const php::Str &class_name);
zend_function *get_func(RequestFuncId func_id, const php::Str &func_name);
zend_function *get_method(RequestFuncId func_id, const php::Str &method_name, RequestClassId class_id, const php::Str &class_name);
zend_class_entry *get_persistent_class(PersistentClassId class_id, const php::Str &class_name);
zend_function *get_persistent_func(PersistentFuncId func_id, const php::Str &func_name);
zend_function *get_persistent_method(PersistentFuncId func_id, const php::Str &method_name, PersistentClassId class_id, const php::Str &class_name);
uint32_t get_persistent_prop(PersistentPropertyId prop_id, const php::Str &prop_name, const php::Str &class_name);
php::PropertyCacheSlot &get_property_cache(PropertyCacheId cache_id) noexcept;
php::MethodCallCacheSlot &get_method_call_cache(MethodCallCacheId cache_id) noexcept;
php::FunctionCallCacheSlot &get_function_call_cache(FunctionCallCacheId cache_id) noexcept;
uint8_t &get_function_resolution_cache(FunctionResolutionCacheId cache_id) noexcept;
ZEND_ATTRIBUTE_CONST php::Str &get_str(uint32_t index) noexcept;
}  // namespace typephp_project_tp_probe
using namespace typephp_project_tp_probe;

