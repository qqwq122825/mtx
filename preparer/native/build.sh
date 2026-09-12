#!/bin/sh
set -eu
cd "$(dirname "$0")"
mkdir -p build
SOURCES="vendor/choma/Base64.c vendor/choma/BufferedStream.c vendor/choma/CSBlob.c vendor/choma/CodeDirectory.c vendor/choma/FAT.c vendor/choma/FileStream.c vendor/choma/MachO.c vendor/choma/MachOLoadCommand.c vendor/choma/MemoryStream.c vendor/choma/Util.c"
if [ "$(uname -s)" = Darwin ]; then
    clang -O2 -fblocks -Wno-deprecated-declarations -Ivendor/choma -I/opt/homebrew/opt/openssl@3/include $SOURCES prepare.c main.c -L/opt/homebrew/opt/openssl@3/lib -lcrypto -lm -o build/mtx-prepare
else
    clang -O2 -fblocks -D_GNU_SOURCE -include stdint.h -include stddef.h -include string.h -Icompat -Ivendor/choma -Wno-deprecated-declarations $SOURCES prepare.c main.c -lcrypto -lBlocksRuntime -lm -o build/mtx-prepare
fi
