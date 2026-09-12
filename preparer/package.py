#!/usr/bin/env python3
"""Archive and arm64 validation shared with the existing local build preparation.
Uploaded programs are read as bytes, never executed.
"""
import argparse
import fcntl
import hashlib
import io
import json
import os
from pathlib import Path, PurePosixPath
import plistlib
import re
import shutil
import stat
import struct
import subprocess
import sys
import tarfile
import tempfile
import zipfile

ROOT = Path(__file__).resolve().parent
OVERRIDE_KEYS = (
    "NSAppTransportSecurity", "CADisableMinimumFrameDurationOnPhone",
    "UIRequiresFullScreen", "UISupportedInterfaceOrientations",
    "UISupportedInterfaceOrientations~ipad", "UIStatusBarHidden",
    "UIViewControllerBasedStatusBarAppearance", "UIAppFonts",
)


def sha(data):
    return hashlib.sha256(data).hexdigest()


def checked_members(z):
    seen = set()
    total = 0
    for item in z.infolist():
        path = PurePosixPath(item.filename)
        mode = item.external_attr >> 16
        if (not item.filename or path.is_absolute() or ".." in path.parts
                or "\\" in item.filename or "\x00" in item.filename
                or stat.S_ISLNK(mode) or item.filename in seen):
            raise ValueError("Invalid archive member: " + repr(item.filename))
        if item.flag_bits & 1:
            raise ValueError("Encrypted ZIP member")
        total += item.file_size
        if total > 256 * 1024 * 1024 or item.file_size > 128 * 1024 * 1024:
            raise ValueError("Archive size limit exceeded")
        seen.add(item.filename)
    return seen


def macho_fingerprint(data):
    """Read only; compare code and dylib names before/after signature changes."""
    if len(data) < 32:
        raise ValueError("Truncated Mach-O")
    magic, cpu, subtype, filetype, ncmds, sizeofcmds, flags, reserved = struct.unpack_from("<8I", data)
    if magic != 0xFEEDFACF or cpu != 0x0100000C or filetype != 2:
        raise ValueError("Expected a thin arm64 iOS executable")
    if 32 + sizeofcmds > len(data) or ncmds > 4096:
        raise ValueError("Invalid load commands")
    pos, code_hash, minimum, signature = 32, None, None, None
    dylibs = []
    for _ in range(ncmds):
        cmd, size = struct.unpack_from("<II", data, pos)
        if size < 8 or pos + size > 32 + sizeofcmds:
            raise ValueError("Invalid load command size")
        if cmd == 0x19:  # LC_SEGMENT_64
            if size < 72:
                raise ValueError("Truncated segment")
            count = struct.unpack_from("<I", data, pos + 64)[0]
            if 72 + count * 80 > size:
                raise ValueError("Truncated sections")
            for i in range(count):
                sec = pos + 72 + i * 80
                sectname = data[sec:sec+16].split(b"\0")[0]
                segname = data[sec+16:sec+32].split(b"\0")[0]
                if (segname, sectname) == (b"__TEXT", b"__text"):
                    length = struct.unpack_from("<Q", data, sec + 40)[0]
                    offset = struct.unpack_from("<I", data, sec + 48)[0]
                    if offset + length > len(data):
                        raise ValueError("Invalid code section")
                    code_hash = sha(data[offset:offset+length])
        elif cmd in (0xC, 0x80000018, 0x8000001F):
            off = struct.unpack_from("<I", data, pos+8)[0]
            if off >= size:
                raise ValueError("Invalid dylib name")
            dylibs.append(data[pos+off:pos+size].split(b"\0")[0].decode())
        elif cmd == 0x32:
            platform, version = struct.unpack_from("<II", data, pos+8)
            if platform != 2:
                raise ValueError("Expected iOS device platform")
            minimum = version
        elif cmd == 0x25:
            minimum = struct.unpack_from("<I", data, pos+8)[0]
        elif cmd in (0x21, 0x2C):
            if struct.unpack_from("<I", data, pos+16)[0]:
                raise ValueError("Encrypted executable")
        elif cmd == 0x1D:
            signature = struct.unpack_from("<II", data, pos+8)
        pos += size
    if not code_hash or minimum is None or minimum > (16 << 16 | 6 << 8 | 1):
        raise ValueError("Missing code section or incompatible minimum iOS")
    return (code_hash, tuple(dylibs), minimum), signature


def check_prepared_signature(data):
    _, sig = macho_fingerprint(data)
    if not sig:
        raise ValueError("Missing signature")
    offset, length = sig
    if offset + length > len(data) or length < 12:
        raise ValueError("Invalid signature bounds")
    magic, total, count = struct.unpack_from(">III", data, offset)
    if magic != 0xFADE0CC0 or total > length or 12 + count * 8 > total:
        raise ValueError("Invalid signature superblob")
    slots = set()
    for i in range(count):
        slot, rel = struct.unpack_from(">II", data, offset+12+i*8)
        if rel + 8 > total:
            raise ValueError("Invalid signature slot")
        blob_magic, blob_length = struct.unpack_from(">II", data, offset+rel)
        if blob_length < 8 or rel + blob_length > total:
            raise ValueError("Invalid signature blob")
        slots.add(slot)
    if not {0, 0x1000, 0x10000}.issubset(slots):
        raise ValueError("Prepared signature is missing expected CoreTrust slots")


def inspect_package(path, config):
    return inspect_package_bytes(path.read_bytes(), config)


def ios_version_number(value):
    if not isinstance(value, str) or not re.fullmatch(r"[0-9]+(?:\.[0-9]+){0,2}", value):
        raise ValueError("Invalid MinimumOSVersion")
    parts = [int(part) for part in value.split('.')]
    parts += [0] * (3 - len(parts))
    if any(part > 255 for part in parts):
        raise ValueError("Invalid MinimumOSVersion")
    return parts[0] << 16 | parts[1] << 8 | parts[2]


def inspect_package_bytes(raw, config):
    if sha(raw) != config["sourceSHA256"]:
        raise ValueError("Source SHA-256 differs from CustomPayload.json")
    with zipfile.ZipFile(io.BytesIO(raw)) as z:
        names = checked_members(z)
        roots = [n for n in names if re.fullmatch(r"Payload/[^/]+\.app/Info\.plist", n)]
        if len(roots) != 1:
            raise ValueError("Expected exactly one main app")
        info = plistlib.loads(z.read(roots[0]))
        if info.get("CFBundleIdentifier") != config["bundleIdentifier"]:
            raise ValueError("包内主程序 Bundle ID 应为 " + config["bundleIdentifier"]
                             + "，实际为 " + str(info.get("CFBundleIdentifier")))
        exe = info.get("CFBundleExecutable", "")
        if not exe or PurePosixPath(exe).name != exe or exe in (".", ".."):
            raise ValueError("Invalid main executable name")
        base = roots[0][:-len("Info.plist")]
        main = z.read(base + exe)
        minimum = macho_fingerprint(main)[0][2]
        required = ios_version_number(config.get("minimumIOS", "14.0"))
        if minimum > required or ios_version_number(info.get("MinimumOSVersion", "")) > required:
            raise ValueError("内置主程序最低系统要求高于安装器声明范围；请提供支持 iOS "
                             + config.get("minimumIOS", "14.0") + " 的包")
        omitted = sorted(n for n in names if n.startswith(base + "PlugIns/") and n.endswith("Info.plist"))
        if omitted and not config.get("omitExtensions"):
            raise ValueError("Extension adaptation is pending")
        if any(n.startswith(base + "Frameworks/") for n in names):
            raise ValueError("Framework packaging needs explicit adaptation")
        # Keep the system host's icons. Other newly added resources need a
        # matching installer/restore change rather than being silently dropped.
        for n in sorted(names):
            if not n.startswith(base) or n.endswith("/"):
                continue
            relative = n[len(base):]
            if (relative in {exe, "Info.plist", "PkgInfo", "embedded.mobileprovision"}
                    or relative in config["resourceNames"]
                    or relative.startswith(("PlugIns/", "_CodeSignature/"))
                    or re.fullmatch(r"AppIcon[^/]*\.png", relative)):
                continue
            raise ValueError("新增资源需要接入安装和还原流程：" + relative)
        resources = {}
        for name in config["resourceNames"]:
            if name != "MTXMenuIcons.ttf":
                raise ValueError("Resource is outside the first-build allowlist")
            resources[name] = z.read(base + name)
    return info, main, resources, omitted
