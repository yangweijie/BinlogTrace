#include <typephp_opcode_table.h>
static const uint8_t *const typephp_embedded_archive_start = typephp_embedded_archive_data();
static const typephp_opcode_entry typephp_opcodes[] = {
    {"D:\\git\\php\\typephp_dms\\agent\\probe\\payload\\hello.php", typephp_embedded_archive_start + 217, 2208},
    {nullptr, nullptr, 0},
};
extern "C" const typephp_opcode_entry *typephp_project_opcode_table(size_t *count) {
    *count = 1; return typephp_opcodes;
}
static const typephp_embedded_file_entry typephp_embedded_files[] = {
    {"D:\\git\\php\\typephp_dms\\agent\\probe\\payload\\data.txt", typephp_embedded_archive_start + 0, 17},
    {"D:\\git\\php\\typephp_dms\\agent\\probe\\payload\\hello.php", typephp_embedded_archive_start + 17, 200},
    {nullptr, nullptr, 0},
};
extern "C" const typephp_embedded_file_entry *typephp_project_embedded_file_table(size_t *count) {
    *count = 2; return typephp_embedded_files;
}
extern "C" const char *typephp_project_php_version(void) { return "8.5.11"; }
extern "C" const char *typephp_project_entry_file(void) { return nullptr; }
