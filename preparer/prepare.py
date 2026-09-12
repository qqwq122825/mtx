#!/usr/bin/env python3
"""Prepare a validated auxiliary TIPA in a private temporary directory."""
import hashlib, json, os, plistlib, resource, struct, subprocess, tarfile
from pathlib import Path
import package

class PreparationError(Exception): pass

def entitlements(data):
    _, signature = package.macho_fingerprint(data)
    if not signature: raise PreparationError('主程序缺少签名和权限信息。')
    offset, size = signature
    if offset+size>len(data) or size<12: raise PreparationError('主程序签名结构异常。')
    magic,total,count=struct.unpack_from('>III',data,offset)
    if magic!=0xfade0cc0 or total>size or count>64 or 12+8*count>total: raise PreparationError('主程序签名结构异常。')
    blobs={}
    for i in range(count):
        slot,rel=struct.unpack_from('>II',data,offset+12+8*i)
        if rel<12+8*count or rel+8>total or slot in blobs: raise PreparationError('主程序签名结构异常。')
        kind,length=struct.unpack_from('>II',data,offset+rel)
        if length<8 or rel+length>total: raise PreparationError('主程序签名结构异常。')
        blobs[slot]=data[offset+rel:offset+rel+length]
    xml=blobs.get(5,b'')
    if not xml or len(xml)>1048576 or struct.unpack_from('>I',xml)[0]!=0xfade7171: raise PreparationError('辅助包缺少所需权限。')
    ents=plistlib.loads(xml[8:])
    if not isinstance(ents,dict) or ents.get('com.apple.private.security.no-sandbox') is not True: raise PreparationError('辅助包缺少所需权限。')
    cd=blobs.get(0x1000,blobs.get(0,b''))
    if len(cd)<44 or cd[37]!=2 or cd[36]!=32 or cd[39]>16: raise PreparationError('辅助包需要有效的 SHA-256 代码签名。')
    hash_offset,ident,special,slots,limit=struct.unpack_from('>5I',cd,16)
    page=1<<cd[39]
    if limit!=offset or slots!=(offset+page-1)//page or hash_offset+slots*32>len(cd) or special>64 or hash_offset<special*32: raise PreparationError('主程序代码签名范围异常。')
    for i in range(slots):
        if hashlib.sha256(data[i*page:min((i+1)*page,offset)]).digest()!=cd[hash_offset+i*32:hash_offset+(i+1)*32]: raise PreparationError('主程序签名摘要不一致，请重新导出辅助包。')
    return {slot:blobs[slot] for slot in (5,7) if slot in blobs}

def limits():
    if os.uname().sysname=='Linux':
        resource.setrlimit(resource.RLIMIT_AS,(512*1024**2,512*1024**2))
        resource.setrlimit(resource.RLIMIT_CPU,(90,90))
        resource.setrlimit(resource.RLIMIT_CORE,(0,0))
        resource.setrlimit(resource.RLIMIT_FSIZE,(160*1024**2,160*1024**2))

def prepare(job, work):
    source=Path(job['source_path'])
    if source.is_symlink() or not source.is_file(): raise PreparationError('源包文件不存在。')
    with source.open('rb') as f: raw=f.read(256*1024**2+1)
    if len(raw)!=job['source_bytes'] or hashlib.sha256(raw).hexdigest()!=job['source_sha256']: raise PreparationError('源包摘要异常，请重新上传。')
    config={'sourceSHA256':job['source_sha256'],'bundleIdentifier':job['bundle_id'], 'minimumIOS':job['min_ios'], 'omitExtensions':True,'resourceNames':['MTXMenuIcons.ttf']}
    try:
        info, main, resources, omitted=package.inspect_package_bytes(raw,config)
        original=package.macho_fingerprint(main)[0]; ents=entitlements(main)
    except PreparationError: raise
    except Exception: raise PreparationError('辅助包结构与安装器不匹配，请检查主程序、字体及系统版本。')
    binary=work/'Main';binary.write_bytes(main);binary.chmod(0o600)
    tool=Path(__file__).resolve().parent/'native/build/mtx-prepare'
    if not tool.is_file(): raise PreparationError('自动处理工具尚未配置，请联系维护者。')
    try:
        with (work/'native.log').open('wb') as log:
            result=subprocess.run([str(tool),str(binary)],stdin=subprocess.DEVNULL,stdout=log,stderr=subprocess.STDOUT,timeout=100,preexec_fn=limits)
        if result.returncode: raise PreparationError('辅助包自动处理失败，请检查包内签名。')
        signed=binary.read_bytes(); package.check_prepared_signature(signed)
        if package.macho_fingerprint(signed)[0]!=original or entitlements(signed)!=ents: raise PreparationError('自动处理前后的程序或权限不一致，已停止发布。')
    except PreparationError: raise
    except Exception: raise PreparationError('辅助包自动处理或校验未完成，请重试。')
    files={'Main':signed,**resources}
    manifest={'SchemaVersion':1,'BundleIdentifier':job['bundle_id'],'DisplayName':info.get('CFBundleDisplayName',job['bundle_id']),'Version':job['display_version'],'SourceSHA256':job['source_sha256'],'Files':{n:hashlib.sha256(v).hexdigest() for n,v in files.items()},'InfoOverrides':{k:info[k] for k in package.OVERRIDE_KEYS if k in info},'OmittedExtensions':omitted,'SignaturePreparation':'mtx-server-v1'}
    files['Manifest.plist']=plistlib.dumps(manifest,sort_keys=True)
    import io
    output=work/'payload.tar'
    with tarfile.open(output,'w',format=tarfile.USTAR_FORMAT) as tar:
        for name,data in sorted(files.items()):
            m=tarfile.TarInfo(name);m.size=len(data);m.mode=0o755 if name=='Main' else 0o644;m.mtime=0
            tar.addfile(m,io.BytesIO(data))
    output.chmod(0o600)
    return output
