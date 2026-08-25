// phpx_shim.cc — 兼容垫片：为当前 tpc_v1113 的 phpx 运行时补齐较新 aot-compiler 生成代码
// 所引用的符号（typephp_prepare_property_redeclaration）。实现为 no-op。
#include "typephp_runtime.h"

extern "C" void typephp_prepare_property_redeclaration(zend_class_entry *class_entry, const char *property_name) {
    (void) class_entry;
    (void) property_name;
}
