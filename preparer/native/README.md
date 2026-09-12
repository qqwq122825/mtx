# Fixed server preparation component

This tool prepares the existing `prepared-payload-v1` contract. It operates on a
private copy of a validated Mach-O; it never loads or executes the uploaded app.
The surrounding Python worker validates code-page hashes and compares code and
entitlement bytes before accepting output. It is not an installer or a general
IPA signing service.

## Provenance

Sources are pinned to the same revisions used by this project's existing custom
TrollInstallerX preparation tool:

- TrollStore `88424f683b2a08f34a3f88985f790f97d84ce1df`:
  `prepare.c` derives from `Exploits/fastPathSign/src/coretrust_bug.c`; embedded
  upstream signing templates are in `vendor/templates/`.
  MIT license: `vendor/LICENSE-TrollStore`.
- ChOma `964023ddac2286ef8e843f90df64d44ac6a673df`: vendored C library in
  `vendor/choma/`, MIT license: `vendor/LICENSE-ChOma`.
- Mach-O structure declarations in `compat/mach-o/` and `compat/mach/machine.h`
  derive from Apple's SDK headers, retaining their Apple Public Source License
  notices. The full license is `compat/LICENSE-APSL`.

The platform port replaces CoreFoundation serialization with equivalent XML for
the two digest entries, removes unused Apple-only headers, and supplies narrow
Linux byte-order, type and CommonCrypto digest compatibility headers using
OpenSSL. Upstream trailing whitespace is normalized. The original preparation algorithm remains the one in the pinned
TrollStore revision. Embedded upstream certificate/template material is public
source material, unrelated to this server's private release or account keys.

`main.c`, `build.sh` and small compatibility shims are local glue. No source from
unlicensed third-party Linux forks is redistributed.

## Build

On Linux: Clang with Blocks support, OpenSSL development headers and
libBlocksRuntime. On macOS: Xcode Clang and Homebrew OpenSSL 3 at
`/opt/homebrew/opt/openssl@3`. Run `sh preparer/native/build.sh` from the project
root. The platform-specific executable is ignored by Git and excluded from
code-only deployment archives; compile it on the deployment host.

Keep the executable and source root-owned and read-only to the PHP/worker user.
Use `preparer/worker.py`, not this CLI directly, for the validated production
workflow. Do not run package preparation as root.
