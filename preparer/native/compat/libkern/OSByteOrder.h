#pragma once
#include <endian.h>
#define OSSwapBigToHostInt16 be16toh
#define OSSwapLittleToHostInt16 le16toh
#define OSSwapHostToBigInt16 htobe16
#define OSSwapHostToLittleInt16 htole16
#define OSSwapBigToHostInt32 be32toh
#define OSSwapLittleToHostInt32 le32toh
#define OSSwapHostToBigInt32 htobe32
#define OSSwapHostToLittleInt32 htole32
#define OSSwapBigToHostInt64 be64toh
#define OSSwapLittleToHostInt64 le64toh
#define OSSwapHostToBigInt64 htobe64
#define OSSwapHostToLittleInt64 htole64
