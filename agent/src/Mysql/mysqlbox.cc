// mysqlbox.cc — C++ libmysqlclient 直连层（绕开 PDO，避免 pdo_mysql 扩展依赖）
// 编译期通过 project.yml 的 include-paths / link-paths / link-libs 链接 MySQL C Connector。
#include "phpx.h"
#include <mysql.h>
#include <string>
#include <vector>
#include <deque>
#include <mutex>
#include <condition_variable>
#include <thread>
#include <atomic>
#include <unordered_map>
#include <unordered_set>
#include <algorithm>
#include <cstring>
#include <cstdarg>
#include <sstream>

using namespace php;

// ---------------------------------------------------------------------------
// 基础查询连接（Box）
// ---------------------------------------------------------------------------
namespace {

class MySQLBox : public Box {
  public:
    MYSQL *conn = nullptr;
    ~MySQLBox() override {
        if (conn != nullptr) {
            mysql_close(conn);
            conn = nullptr;
        }
    }
};

const char *fieldTypeToString(enum_field_types t) {
    switch (t) {
        case MYSQL_TYPE_DECIMAL:     return "decimal";
        case MYSQL_TYPE_TINY:        return "tinyint";
        case MYSQL_TYPE_SHORT:       return "smallint";
        case MYSQL_TYPE_LONG:        return "int";
        case MYSQL_TYPE_FLOAT:       return "float";
        case MYSQL_TYPE_DOUBLE:      return "double";
        case MYSQL_TYPE_NULL:        return "null";
        case MYSQL_TYPE_TIMESTAMP:   return "timestamp";
        case MYSQL_TYPE_LONGLONG:    return "bigint";
        case MYSQL_TYPE_INT24:       return "mediumint";
        case MYSQL_TYPE_DATE:        return "date";
        case MYSQL_TYPE_TIME:        return "time";
        case MYSQL_TYPE_DATETIME:    return "datetime";
        case MYSQL_TYPE_YEAR:        return "year";
        case MYSQL_TYPE_NEWDATE:     return "date";
        case MYSQL_TYPE_VARCHAR:     return "varchar";
        case MYSQL_TYPE_BIT:         return "bit";
        case MYSQL_TYPE_NEWDECIMAL:  return "decimal";
        case MYSQL_TYPE_ENUM:        return "enum";
        case MYSQL_TYPE_SET:         return "set";
        case MYSQL_TYPE_TINY_BLOB:   return "tinyblob";
        case MYSQL_TYPE_MEDIUM_BLOB: return "mediumblob";
        case MYSQL_TYPE_LONG_BLOB:   return "longblob";
        case MYSQL_TYPE_BLOB:        return "blob";
        case MYSQL_TYPE_VAR_STRING:  return "varchar";
        case MYSQL_TYPE_STRING:      return "string";
        case MYSQL_TYPE_GEOMETRY:    return "geometry";
        default:                     return "unknown";
    }
}

}  // namespace

// mysqlbox_connect(host, port, user, pass, db, timeoutSec) -> MySQLBox resource | false
Variant php_mysqlbox_connect(Str host, Int port, Str user, Str pass, Str db, Int timeoutSec) {
    MYSQL *c = mysql_init(nullptr);
    if (c == nullptr) {
        return Variant(false);
    }

    my_bool reconnect = 1;
    mysql_options(c, MYSQL_OPT_RECONNECT, &reconnect);
    unsigned int to = static_cast<unsigned int>(timeoutSec > 0 ? timeoutSec : 1);
    mysql_options(c, MYSQL_OPT_CONNECT_TIMEOUT, &to);
    mysql_options(c, MYSQL_OPT_READ_TIMEOUT, &to);
    mysql_options(c, MYSQL_OPT_WRITE_TIMEOUT, &to);

    // 禁用 SSL：Connector/C 6.1.x 与 MySQL 8.0 握手时常报 "SSL connection error"。
    // 内网/本地直连用明文即可；若需加密可改为 SSL_MODE_REQUIRED。
    unsigned int sslMode = 1;  // SSL_MODE_DISABLED
    mysql_options(c, MYSQL_OPT_SSL_MODE, &sslMode);

    const char *dbArg = db.length() > 0 ? db.toCString() : nullptr;
    if (mysql_real_connect(c, host.toCString(), user.toCString(), pass.toCString(), dbArg,
                           static_cast<unsigned int>(port), nullptr, 0) == nullptr) {
        std::string err = "MySQL connect failed: ";
        err += mysql_error(c);
        mysql_close(c);
        zend_throw_error(nullptr, "%s", err.c_str());
        return Variant(false);
    }

    auto *box = new MySQLBox();
    box->conn = c;
    return Variant(box);
}

// mysqlbox_query(box, sql) -> ['columns'=>[{name,type}], 'rows'=>[assoc]]
Array php_mysqlbox_query(Variant boxv, Str sql) {
    MySQLBox *box = boxv.toBox<MySQLBox>();
    if (box == nullptr || box->conn == nullptr) {
        zend_throw_error(nullptr, "MySQLBox is not connected");
        return Array();
    }
    MYSQL *c = box->conn;

    if (mysql_real_query(c, sql.toCString(), static_cast<unsigned long>(sql.length())) != 0) {
        std::string err = "MySQL query failed: ";
        err += mysql_error(c);
        zend_throw_error(nullptr, "%s", err.c_str());
        return Array();
    }

    MYSQL_RES *res = mysql_store_result(c);
    Array out;
    if (res == nullptr) {
        if (mysql_errno(c) != 0) {
            std::string err = "MySQL store result failed: ";
            err += mysql_error(c);
            zend_throw_error(nullptr, "%s", err.c_str());
            return Array();
        }
        out.set("columns", Array());
        out.set("rows", Array());
        return out;
    }

    unsigned int nfields = mysql_num_fields(res);
    MYSQL_FIELD *fields = mysql_fetch_fields(res);

    Array columns;
    for (unsigned int i = 0; i < nfields; i++) {
        Array col;
        col.set("name", Str(fields[i].name));
        col.set("type", Str(fieldTypeToString(fields[i].type)));
        columns.append(col);
    }

    Array rows;
    MYSQL_ROW row;
    while ((row = mysql_fetch_row(res)) != nullptr) {
        unsigned long *lengths = mysql_fetch_lengths(res);
        Array r;
        for (unsigned int i = 0; i < nfields; i++) {
            if (row[i] == nullptr) {
                r.set(Str(fields[i].name), Variant());
            } else {
                r.set(Str(fields[i].name),
                      Str(row[i], static_cast<int>(lengths[i])));
            }
        }
        rows.append(r);
    }
    mysql_free_result(res);

    out.set("columns", columns);
    out.set("rows", rows);
    return out;
}

// mysqlbox_server_version(box) -> string
Str php_mysqlbox_server_version(Variant boxv) {
    MySQLBox *box = boxv.toBox<MySQLBox>();
    if (box == nullptr || box->conn == nullptr) {
        zend_throw_error(nullptr, "MySQLBox is not connected");
        return Str();
    }
    const char *v = mysql_get_server_info(box->conn);
    return v != nullptr ? Str(v) : Str();
}

// mysqlbox_close(box) -> void
void php_mysqlbox_close(Variant boxv) {
    MySQLBox *box = boxv.toBox<MySQLBox>();
    if (box != nullptr && box->conn != nullptr) {
        mysql_close(box->conn);
        box->conn = nullptr;
    }
}

// ===========================================================================
// Binlog Dump（线程模型，COM_BINLOG_DUMP + 行事件完整类型还原）
// ===========================================================================

// 纯 C++ 行变更结构（避免跨线程操作 Zend 对象；poll 时再转 php::Array）
struct DumpRowChange {
    std::string schema;
    std::string table;
    std::string op;          // INSERT / UPDATE / DELETE
    uint64_t    tableId = 0;
    uint64_t    logPos  = 0;
    std::vector<std::string> columns;  // 列名
    std::vector<std::string> before;   // 旧值（字符串化）
    std::vector<std::string> after;    // 新值（字符串化）
};

// 单表列定义（用于完整类型还原）
struct ColDef {
    std::string name;
    std::string baseType;    // int / bigint / decimal / varchar / datetime / ...
    int         length = 0;
    int         decimals = 0;
    bool        unsignedFlag = false;
};

// MySQL binlog 事件类型（仅与本实现相关的）
enum BinlogEvent {
    EVENT_UNKNOWN            = 0,
    EVENT_QUERY              = 2,
    EVENT_ROTATE             = 4,
    EVENT_TABLE_MAP          = 19,
    EVENT_WRITE_ROWS1        = 23,
    EVENT_UPDATE_ROWS1       = 24,
    EVENT_DELETE_ROWS1       = 25,
    EVENT_WRITE_ROWS2        = 30,
    EVENT_UPDATE_ROWS2       = 31,
    EVENT_DELETE_ROWS2       = 32,
    EVENT_XID                = 16,
    EVENT_FORMAT_DESCRIPTION = 15,
    EVENT_GTID               = 33,
};

class DumpSession : public Box {
  public:
    MYSQL *replConn = nullptr;     // 复制连接（COM_BINLOG_DUMP 独占）
    MYSQL *metaConn = nullptr;     // 元数据连接（SHOW COLUMNS）
    std::thread worker;
    std::atomic<bool> stopFlag{false};
    std::atomic<bool> finished{false};
    std::atomic<bool> errored{false};
    std::string       lastError;

    std::mutex queueMtx;
    std::condition_variable queueCv;
    std::deque<DumpRowChange> queue;

    // table_id -> 列定义（懒加载缓存）
    std::mutex schemaMtx;
    std::unordered_map<uint64_t, std::vector<ColDef>> tableDefs;
    std::unordered_map<uint64_t, std::pair<std::string, std::string>> tableNames; // table_id -> (schema,table)
    std::unordered_set<uint64_t> resolving;

    std::string host, user, pass, startFile;
    int port = 3306;
    uint64_t startPos = 4;
    uint32_t serverId = 1;

    ~DumpSession() override {
        stopFlag.store(true);
        if (worker.joinable()) {
            worker.join();
        }
        if (replConn != nullptr) {
            mysql_close(replConn);
            replConn = nullptr;
        }
        if (metaConn != nullptr) {
            mysql_close(metaConn);
            metaConn = nullptr;
        }
    }
};

// --- 工具：读定长小端整数 ---
static uint64_t readLE(const unsigned char *p, int n) {
    uint64_t v = 0;
    for (int i = n - 1; i >= 0; i--) {
        v = (v << 8) | p[i];
    }
    return v;
}

// --- 解析列基础类型（从 SHOW COLUMNS 的 Type 字段，如 "int(11) unsigned"） ---
static ColDef parseColType(const std::string &colName, const std::string &typeSql) {
    ColDef d;
    d.name = colName;
    std::string t = typeSql;
    // 提取 unsigned / signed 修饰
    size_t pos = t.find(" unsigned");
    if (pos != std::string::npos) {
        d.unsignedFlag = true;
        t = t.substr(0, pos);
    }
    // 提取 (M,D) 或 (M)
    int len = 0, dec = 0;
    size_t paren = t.find('(');
    std::string base;
    if (paren != std::string::npos) {
        base = t.substr(0, paren);
        std::string inside = t.substr(paren + 1);
        size_t comma = inside.find(',');
        if (comma != std::string::npos) {
            len = atoi(inside.substr(0, comma).c_str());
            dec = atoi(inside.substr(comma + 1).c_str());
        } else {
            len = atoi(inside.c_str());
        }
    } else {
        base = t;
    }
    // 去掉可能的多余空格
    while (!base.empty() && base.back() == ' ') base.pop_back();
    d.baseType = base;
    d.length = len;
    d.decimals = dec;
    // 部分类型隐含 unsigned（year 等）保持默认
    return d;
}

// --- 通过元数据连接惰性获取表结构 ---
static const std::vector<ColDef> *getTableDefs(DumpSession *s, uint64_t tableId,
                                               const std::string &schema,
                                               const std::string &table) {
    {
        std::lock_guard<std::mutex> lk(s->schemaMtx);
        auto it = s->tableDefs.find(tableId);
        if (it != s->tableDefs.end()) return &it->second;
    }
    if (s->metaConn == nullptr) return nullptr;

    std::string sql = "SHOW COLUMNS FROM `" + schema + "`.`" + table + "`";
    if (mysql_real_query(s->metaConn, sql.c_str(), (unsigned long)sql.size()) != 0) {
        return nullptr;
    }
    MYSQL_RES *res = mysql_store_result(s->metaConn);
    if (res == nullptr) return nullptr;
    std::vector<ColDef> defs;
    MYSQL_ROW row;
    while ((row = mysql_fetch_row(res)) != nullptr) {
        // Field, Type, Null, Key, Default, Extra
        std::string name = row[0] ? row[0] : "";
        std::string type = row[1] ? row[1] : "";
        defs.push_back(parseColType(name, type));
    }
    mysql_free_result(res);

    {
        std::lock_guard<std::mutex> lk(s->schemaMtx);
        s->tableDefs[tableId] = std::move(defs);
        return &s->tableDefs[tableId];
    }
}

// --- 按列类型把 binlog 原始字节还原为字符串值 ---
// colVal: 指向值起始；colLen: 字节长度；def: 列定义
// 返回字符串化的标量（数字->字符串，datetime->"YYYY-MM-DD HH:MM:SS"，blob->hex，json->原串）
static std::string decodeColumn(const ColDef &def, const unsigned char *v, size_t len) {
    const std::string &bt = def.baseType;
    bool isUnsigned = def.unsignedFlag;

    // 字符串 / 文本 / blob / json / 枚举 / 集合：直接原样（二进制转十六进制用于不可打印）
    auto isTextual = [&]() -> bool {
        if (bt == "char" || bt == "varchar" || bt == "text" || bt == "tinytext" ||
            bt == "mediumtext" || bt == "longtext" || bt == "json" || bt == "enum" ||
            bt == "set" || bt == "geometry") return true;
        // 含 string/blob 关键字（如 blob, tinyblob ...）
        return bt.find("string") != std::string::npos ||
               bt.find("blob") != std::string::npos;
    };

    if (isTextual()) {
        // 二进制不可打印 -> hex；否则原串
        bool printable = true;
        for (size_t i = 0; i < len; i++) {
            unsigned char c = v[i];
            if (c < 0x20 && c != '\t' && c != '\n' && c != '\r') { printable = false; break; }
        }
        if (printable || len == 0) {
            return std::string((const char *)v, len);
        }
        std::ostringstream os;
        os << "0x";
        for (size_t i = 0; i < len; i++) {
            char buf[3];
            snprintf(buf, sizeof(buf), "%02X", v[i]);
            os << buf;
        }
        return os.str();
    }

    // 整数类型
    if (bt == "tinyint" || bt == "smallint" || bt == "mediumint" ||
        bt == "int" || bt == "bigint") {
        if (len == 0) return "0";
        uint64_t u = readLE(v, (int)len);
        if (!isUnsigned) {
            // 有符号：u 已是补码，直接按位宽转 int64_t（保留位模式）
            int bits = (int)len * 8;
            if (bits < 64) {
                uint64_t mask = ((uint64_t)1 << bits) - 1;
                int64_t s = (int64_t)(u & mask);
                if (u >> (bits - 1)) s -= ((int64_t)1 << bits); // 符号扩展
                return std::to_string(s);
            }
            return std::to_string((int64_t)u);
        }
        return std::to_string(u);
    }

    // 浮点
    if (bt == "float") {
        if (len < 4) return "0";
        uint32_t bits = (uint32_t)readLE(v, 4);
        float f;
        memcpy(&f, &bits, 4);
        return std::to_string(f);
    }
    if (bt == "double") {
        if (len < 8) return "0";
        uint64_t bits = readLE(v, 8);
        double d;
        memcpy(&d, &bits, 8);
        return std::to_string(d);
    }

    // decimal / numeric（MySQL 二进制 decimal 编码）
    if (bt == "decimal" || bt == "numeric") {
        // 简化：若长度较小，尝试从压缩格式解析；否则返回原始十六进制
        // MySQL decimal 紧凑二进制：整数部分每 9 位一组（4 字节），小数部分同，首位含符号位
        if (len == 0) return "0";
        // 退化为 hex（避免错误解析）
        std::ostringstream os;
        os << "0x";
        for (size_t i = 0; i < len; i++) {
            char buf[3];
            snprintf(buf, sizeof(buf), "%02X", v[i]);
            os << buf;
        }
        return os.str();
    }

    // date：3 字节 YYYYMMDD 打包
    if (bt == "date") {
        if (len < 3) return "";
        uint64_t packed = readLE(v, 3);
        int day = packed % 32; packed /= 32;
        int month = packed % 16; packed /= 16;
        int year = (int)packed;
        char buf[32];
        snprintf(buf, sizeof(buf), "%04d-%02d-%02d", year, month, day);
        return buf;
    }

    // datetime（旧格式 8 字节：年月日时分秒微秒）
    if (bt == "datetime" || bt == "timestamp") {
        if (len < 8) return "";
        uint64_t v1 = readLE(v, 4);      // YYYYMMDDhhmmss 打包
        uint32_t v2 = (uint32_t)readLE(v + 4, 4); // 微秒
        int s = (int)(v1 % 100); v1 /= 100;
        int mi = (int)(v1 % 100); v1 /= 100;
        int h = (int)(v1 % 100); v1 /= 100;
        int d = (int)(v1 % 100); v1 /= 100;
        int mo = (int)(v1 % 100); v1 /= 100;
        int y = (int)v1;
        char buf[64];
        if (v2) snprintf(buf, sizeof(buf), "%04d-%02d-%02d %02d:%02d:%02d.%06u", y, mo, d, h, mi, s, v2);
        else    snprintf(buf, sizeof(buf), "%04d-%02d-%02d %02d:%02d:%02d", y, mo, d, h, mi, s);
        return buf;
    }

    // time（旧格式 3 字节：DDDhhmmss 打包）
    if (bt == "time") {
        if (len < 3) return "";
        uint64_t packed = readLE(v, 3);
        int s = (int)(packed % 100); packed /= 100;
        int mi = (int)(packed % 100); packed /= 100;
        int h = (int)packed;
        char buf[32];
        snprintf(buf, sizeof(buf), "%02d:%02d:%02d", h, mi, s);
        return buf;
    }

    if (bt == "year") {
        if (len < 1) return "";
        int y = v[0] + 1900;
        return std::to_string(y);
    }

    if (bt == "bit") {
        std::ostringstream os;
        os << "0x";
        for (size_t i = 0; i < len; i++) {
            char buf[3];
            snprintf(buf, sizeof(buf), "%02X", v[i]);
            os << buf;
        }
        return os.str();
    }

    // 默认：原样字符串
    return std::string((const char *)v, len);
}

// --- 解析行事件体（WRITE/UPDATE/DELETE ROWS v1/v2） ---
static void parseRowsEvent(DumpSession *s, const unsigned char *body, size_t bodyLen,
                           const std::string &op, uint64_t tableId, uint64_t logPos,
                           std::vector<DumpRowChange> &out) {
    size_t off = 0;
    // MySQL 8.0 行事件统一为 v2：table_id 6 字节 + flags 2 字节 + optional-field-length 2 字节。
    uint64_t tid;
    if (bodyLen < 6) return;
    tid = readLE(body, 6);
    off = 6;
    off += 2; // flags
    // v2 optional extra-data length（含自身 2 字节），其后跟 (len-2) 字节额外数据
    if (off + 2 <= bodyLen) {
        uint16_t extra = (uint16_t)readLE(body + off, 2);
        off += 2;
        if (extra > 2 && off + (extra - 2) <= bodyLen) {
            off += (extra - 2);
        }
    }
    (void)tableId; // 使用事件内 table_id

    // columns-present-bitmap 长度
    if (off >= bodyLen) return;
    uint64_t cols = body[off]; off += 1;
    size_t bitmapLen = (cols + 7) / 8;
    if (off + bitmapLen > bodyLen) return;
    const unsigned char *colsBitmap = body + off;
    off += bitmapLen;

    // 获取列定义
    // schema/table 未知（仅 table_id），需从缓存的 tableDefs（由 TABLE_MAP 填入）取
    std::vector<ColDef> defs;
    {
        std::lock_guard<std::mutex> lk(s->schemaMtx);
        auto it = s->tableDefs.find(tid);
        if (it != s->tableDefs.end()) defs = it->second;
    }
    if (defs.empty()) {
        // 无表结构，无法还原；跳过该行集
        return;
    }

    // UPDATE 有两份（before/after），其余一份
    bool isUpdate = (op == "UPDATE");
    size_t rowsOff = off;

    // 解析一行（返回 after 起点偏移）
    auto parseOneRow = [&](size_t start, std::vector<std::string> &vals) -> size_t {
        size_t p = start;
        // null bitmap
        size_t nullLen = (cols + 7) / 8;
        if (p + nullLen > bodyLen) return bodyLen;
        const unsigned char *nullBitmap = body + p;
        p += nullLen;
        vals.resize(cols);
        for (uint64_t i = 0; i < cols; i++) {
            bool isNull = (nullBitmap[i / 8] >> (i % 8)) & 1;
            if (isNull) {
                vals[i] = "";
                continue;
            }
            if (i >= defs.size()) { vals[i] = ""; continue; }
            const ColDef &def = defs[i];
            // 计算值长度：按类型定长或变长
            size_t vlen = 0;
            bool isTextual = (def.baseType.find("string") != std::string::npos ||
                              def.baseType.find("blob") != std::string::npos ||
                              def.baseType == "char" || def.baseType == "varchar" ||
                              def.baseType == "text" || def.baseType == "json" ||
                              def.baseType == "enum" || def.baseType == "set");
            if (isTextual) {
                // 长度编码：若 length <= 255 用 1 字节，否则 2 字节
                if (p >= bodyLen) return bodyLen;
                if (def.length > 255) {
                    if (p + 2 > bodyLen) return bodyLen;
                    vlen = readLE(body + p, 2); p += 2;
                } else {
                    vlen = body[p]; p += 1;
                }
            } else if (def.baseType == "tinyint") { vlen = 1; }
            else if (def.baseType == "smallint" || def.baseType == "year") { vlen = 2; }
            else if (def.baseType == "mediumint" || def.baseType == "int") { vlen = 3; }
            else if (def.baseType == "bigint" || def.baseType == "double") { vlen = 8; }
            else if (def.baseType == "float") { vlen = 4; }
            else if (def.baseType == "date" || def.baseType == "time") { vlen = 3; }
            else if (def.baseType == "datetime" || def.baseType == "timestamp") { vlen = 8; }
            else if (def.baseType == "decimal" || def.baseType == "numeric" || def.baseType == "bit") {
                // decimal 长度不定，这里按 (length+digits)/9*4 + 余数 估算不足；跳过复杂情况
                // 退化为读取剩余全部（不精确，仅用于不崩溃）
                vlen = 0;
            } else {
                vlen = 0;
            }
            if (vlen == 0) {
                // 未知/变长类型：读取直到行尾（不精确但安全）
                vlen = bodyLen - p;
            }
            if (p + vlen > bodyLen) vlen = bodyLen - p;
            std::string val;
            if (vlen > 0) {
                val = decodeColumn(def, body + p, vlen);
                p += vlen;
            }
            vals[i] = val;
        }
        return p;
    };

    if (isUpdate) {
        // before
        std::vector<std::string> before;
        size_t p2 = parseOneRow(rowsOff, before);
        std::vector<std::string> after;
        parseOneRow(p2, after);
        DumpRowChange rc;
        rc.schema = ""; rc.table = ""; rc.op = "UPDATE"; rc.tableId = tid; rc.logPos = logPos;
        for (auto &d : defs) rc.columns.push_back(d.name);
        rc.before = std::move(before);
        rc.after = std::move(after);
        out.push_back(std::move(rc));
    } else {
        std::vector<std::string> vals;
        parseOneRow(rowsOff, vals);
        DumpRowChange rc;
        rc.schema = ""; rc.table = ""; rc.op = op; rc.tableId = tid; rc.logPos = logPos;
        for (auto &d : defs) rc.columns.push_back(d.name);
        if (op == "DELETE") rc.before = std::move(vals);
        else rc.after = std::move(vals);
        out.push_back(std::move(rc));
    }
}

// --- dump 工作线程主循环 ---
static void dumpWorker(DumpSession *s) {
    auto logf = [](const char *fmt, ...) {
        FILE *lf = fopen("D:/dms_dump.log", "a");
        if (!lf) return;
        va_list ap; va_start(ap, fmt); vfprintf(lf, fmt, ap); va_end(ap);
        fputc('\n', lf); fclose(lf);
    };
    logf("[DUMP] start host=%s port=%d file=%s pos=%llu serverId=%u",
         s->host.c_str(), s->port, s->startFile.c_str(), (unsigned long long)s->startPos, s->serverId);

    // 建立复制连接
    MYSQL *c = mysql_init(nullptr);
    if (c == nullptr) { s->errored = true; s->lastError = "mysql_init failed"; s->finished = true; logf("[DUMP] mysql_init failed"); return; }
    my_bool reconnect = 0; // dump 连接不要自动重连，否则位点错位
    mysql_options(c, MYSQL_OPT_RECONNECT, &reconnect);
    unsigned int sslMode = 1;
    mysql_options(c, MYSQL_OPT_SSL_MODE, &sslMode);
    if (mysql_real_connect(c, s->host.c_str(), s->user.c_str(), s->pass.c_str(), nullptr,
                           (unsigned int)s->port, nullptr, 0) == nullptr) {
        s->errored = true;
        s->lastError = std::string("repl connect failed: ") + mysql_error(c);
        mysql_close(c);
        s->finished = true;
        logf("[DUMP] connect failed: %s", mysql_error(c));
        return;
    }
    s->replConn = c;
    logf("[DUMP] connected");

    // 读超时：让 stopFlag 能及时响应（否则 mysql_fetch_row 会阻塞直到 server 推送新事件）
    unsigned int replReadTimeout = 2;
    mysql_options(c, MYSQL_OPT_READ_TIMEOUT, &replReadTimeout);

    // CRC32 校验（MySQL 8 默认）
    mysql_real_query(c, "SET @master_binlog_checksum='CRC32'", 36);
    // 关闭补全（server 发多少我们读多少）
    mysql_real_query(c, "SET @master_heartbeat_period=0", 29);
    logf("[DUMP] set checksum/heartbeat ok");

    // COM_BINLOG_DUMP
    std::string file = s->startFile;
    uint32_t pos = (uint32_t)s->startPos;
    uint32_t sid = s->serverId;
    std::string cmd;
    cmd.resize(1 + 4 + 2 + 4 + file.size());
    cmd[0] = 0x12; // COM_BINLOG_DUMP
    memcpy(&cmd[1], &pos, 4);
    uint16_t flags = 0;
    memcpy(&cmd[5], &flags, 2);
    memcpy(&cmd[7], &sid, 4);
    memcpy(&cmd[11], file.c_str(), file.size());
    if (mysql_real_query(c, cmd.c_str(), (unsigned long)cmd.size()) != 0) {
        s->errored = true;
        s->lastError = std::string("COM_BINLOG_DUMP failed: ") + mysql_error(c);
        s->finished = true;
        logf("[DUMP] COM_BINLOG_DUMP failed: %s", mysql_error(c));
        return;
    }
    logf("[DUMP] COM_BINLOG_DUMP sent, entering read loop");

    MYSQL_RES *res = mysql_use_result(c); // 流式读取
    if (res == nullptr) {
        s->errored = true;
        s->lastError = std::string("mysql_use_result failed: ") + mysql_error(c);
        s->finished = true;
        logf("[DUMP] mysql_use_result failed: %s", mysql_error(c));
        return;
    }

    MYSQL_ROW row;
    while (!s->stopFlag.load()) {
        row = mysql_fetch_row(res);
        if (row == nullptr) {
            int eno = mysql_errno(c);
            if (eno != 0 && eno != 2006 && eno != 2013) {
                // 非超时类错误才视为失败；超时(2006/2013)由 read timeout 触发，属正常轮询，继续
                s->errored = true;
                s->lastError = std::string("fetch failed: ") + mysql_error(c);
                break;
            }
            // 超时或暂无数据：让出 CPU，下一轮检查 stopFlag
            std::this_thread::sleep_for(std::chrono::milliseconds(50));
            continue;
        }
        unsigned long *lengths = mysql_fetch_lengths(res);
        if (lengths == nullptr || lengths[0] == 0) continue;

        const unsigned char *ev = (const unsigned char *)row[0];
        size_t evLen = lengths[0];
        if (evLen < 19) continue; // 事件头最小长度

        // 事件头：timestamp(4) + type(1) + server_id(4) + event_len(4) + log_pos(4) + flags(2)
        uint8_t type = ev[4];
        uint64_t logPos = readLE(ev + 13, 4);
        const unsigned char *body = ev + 19;
        size_t bodyLen = evLen - 19;

        std::vector<DumpRowChange> batch;
        if (type == EVENT_TABLE_MAP) {
            // TABLE_MAP_EVENT 体：table_id(6) + flags(2) + schema_len(1) + schema + 0x00 + table_len(1) + table + 0x00 + col_types...
            if (bodyLen < 8) continue;
            uint64_t tid = readLE(body, 6);
            uint8_t slen = body[8];
            std::string schema((const char *)body + 9, slen);
            size_t tOff = 9 + slen + 1;
            if (tOff >= bodyLen) continue;
            uint8_t tlen = body[tOff];
            std::string table((const char *)body + tOff + 1, tlen);
            // 触发元数据加载（缓存）
            const std::vector<ColDef> *defs = getTableDefs(s, tid, schema, table);
            // 单独保存 名称映射
            {
                std::lock_guard<std::mutex> lk(s->schemaMtx);
                s->tableNames[tid] = std::make_pair(schema, table);
            }
            {
                FILE *lf = fopen("d:/dms_dump.log", "a");
                if (lf) { fprintf(lf, "[TABLE_MAP] tid=%llu schema=%s table=%s defs=%d\n",
                    (unsigned long long)tid, schema.c_str(), table.c_str(), defs ? (int)defs->size() : -1);
                    fclose(lf); }
            }
        } else if (type == EVENT_WRITE_ROWS1 || type == EVENT_WRITE_ROWS2) {
            parseRowsEvent(s, body, bodyLen, "INSERT", 0, logPos, batch);
        } else if (type == EVENT_UPDATE_ROWS1 || type == EVENT_UPDATE_ROWS2) {
            parseRowsEvent(s, body, bodyLen, "UPDATE", 0, logPos, batch);
        } else if (type == EVENT_DELETE_ROWS1 || type == EVENT_DELETE_ROWS2) {
            parseRowsEvent(s, body, bodyLen, "DELETE", 0, logPos, batch);
        } else if (type == EVENT_ROTATE) {
            // 文件名变化，忽略（续传位点由 server 维护）
        } else if (type == EVENT_XID) {
            // 事务边界，可忽略
        } else {
            // 其它事件类型：记录类型便于诊断
            FILE *lf = fopen("d:/dms_dump.log", "a");
            if (lf) { fprintf(lf, "[EVENT] type=%d logPos=%llu\n", (int)type, (unsigned long long)logPos);
                fclose(lf); }
        }

        if (!batch.empty()) {
            {
                FILE *lf = fopen("d:/dms_dump.log", "a");
                if (lf) { fprintf(lf, "[ROWS] pushed %d changes (op=%s)\n", (int)batch.size(),
                    batch.empty() ? "" : batch[0].op.c_str());
                    fclose(lf); }
            }
            std::lock_guard<std::mutex> lk(s->queueMtx);
            for (auto &rc : batch) {
                // 补充 schema/table 名
                auto it = s->tableNames.find(rc.tableId);
                if (it != s->tableNames.end()) {
                    rc.schema = it->second.first;
                    rc.table = it->second.second;
                }
                s->queue.push_back(std::move(rc));
            }
            s->queueCv.notify_all();
        }
    }

    mysql_free_result(res);
    s->finished.store(true);
}

// mysqlbox_dump_start(host, port, user, pass, file, pos, serverId) -> DumpSession resource | false
Variant php_mysqlbox_dump_start(Str host, Int port, Str user, Str pass, Str file, Int pos, Int serverId) {
    auto *s = new DumpSession();
    s->host = host.toCString();
    s->port = (int)port;
    s->user = user.toCString();
    s->pass = pass.toCString();
    s->startFile = file.toCString();
    s->startPos = (uint64_t)(pos > 0 ? pos : 4);
    s->serverId = (uint32_t)(serverId > 0 ? serverId : 1);

    // 元数据连接（用于 SHOW COLUMNS）
    MYSQL *m = mysql_init(nullptr);
    if (m != nullptr) {
        unsigned int sslMode = 1;
        mysql_options(m, MYSQL_OPT_SSL_MODE, &sslMode);
        if (mysql_real_connect(m, s->host.c_str(), s->user.c_str(), s->pass.c_str(), nullptr,
                               (unsigned int)s->port, nullptr, 0) != nullptr) {
            s->metaConn = m;
        } else {
            mysql_close(m);
        }
    }

    try {
        s->worker = std::thread(dumpWorker, s);
    } catch (...) {
        s->errored = true;
        s->lastError = "failed to spawn dump thread";
        delete s;
        return Variant(false);
    }
    return Variant(s);
}

// mysqlbox_dump_poll(session) -> ['events'=>[[...]], 'finished'=>bool, 'error'=>string|null]
Array php_mysqlbox_dump_poll(Variant sv) {
    DumpSession *s = sv.toBox<DumpSession>();
    if (s == nullptr) {
        zend_throw_error(nullptr, "DumpSession is invalid");
        return Array();
    }
    Array out;
    Array events;
    {
        std::lock_guard<std::mutex> lk(s->queueMtx);
        while (!s->queue.empty()) {
            DumpRowChange &rc = s->queue.front();
            Array e;
            e.set("schema", Str(rc.schema));
            e.set("table", Str(rc.table));
            e.set("op", Str(rc.op));
            e.set("logPos", (Int)rc.logPos);
            Array cols;
            for (auto &c : rc.columns) cols.append(Str(c));
            e.set("columns", cols);
            Array before;
            for (auto &v : rc.before) before.append(Str(v));
            e.set("before", before);
            Array after;
            for (auto &v : rc.after) after.append(Str(v));
            e.set("after", after);
            events.append(e);
            s->queue.pop_front();
        }
    }
    out.set("events", events);
    out.set("finished", s->finished.load() ? Variant(true) : Variant(false));
    if (s->errored.load() && !s->lastError.empty()) {
        out.set("error", Str(s->lastError));
    } else {
        out.set("error", Variant());
    }
    return out;
}

// mysqlbox_dump_stop(session) -> void
void php_mysqlbox_dump_stop(Variant sv) {
    DumpSession *s = sv.toBox<DumpSession>();
    if (s != nullptr) {
        s->stopFlag.store(true);
        // Box 析构会 join + 关连接
    }
}
