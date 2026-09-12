/* Fixed operation: prepare one validated, disposable thin arm64 file. */
#include <stdio.h>
#include <sys/stat.h>
#include <unistd.h>
#include "Host.h"
int apply_coretrust_bypass(const char *path);
int host_get_cpu_information(cpu_type_t *type, cpu_subtype_t *subtype) { *type=CPU_TYPE_ARM64; *subtype=CPU_SUBTYPE_ARM64_ALL; return 0; }
MachO *fat_find_preferred_slice(FAT *fat) { return fat_find_slice(fat,CPU_TYPE_ARM64,CPU_SUBTYPE_ARM64_ALL); }
int main(int argc, char **argv) {
    struct stat st;
    if (argc!=2 || lstat(argv[1],&st) || !S_ISREG(st.st_mode) || st.st_size<32 || st.st_size>134217728) return 2;
    return apply_coretrust_bypass(argv[1])==0 ? 0 : 1;
}
