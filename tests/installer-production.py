#!/usr/bin/env python3
"""Read-only HTTPS smoke check using an installer's actual public binding.

Compiles its shared Apple Security verifier, not its installation entry points.
No login, private server configuration, package download, or publishing occurs.
"""
import argparse
import subprocess
import tempfile
from pathlib import Path

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--installer', required=True)
args = parser.parse_args()
installer = Path(args.installer).resolve()
sources = installer / 'TrollInstallerX/Installer'
binding = sources / 'MTXRemote.generated.h'
if not binding.is_file():
    parser.error('Export MTXRemote.generated.h from the matching deployment first.')

with tempfile.TemporaryDirectory(prefix='mtx-production-check-') as temp:
    temp = Path(temp)
    source = temp / 'Check.m'
    source.write_text(r'''
#import <Foundation/Foundation.h>
#import <Security/Security.h>
#import "MTXRemote.generated.h"
static BOOL Fail(NSError **error, NSString *message) {
    if (error) *error = [NSError errorWithDomain:@"MTXCheck" code:1
        userInfo:@{NSLocalizedDescriptionKey:message}];
    return NO;
}
// Exactly the same implementation and public key as the app and helper.
#import "MTXRemoteVerification.inc"
@interface Probe : NSObject <NSURLSessionDataDelegate>
@property NSMutableData *body;
@property NSError *failure;
@property BOOL accepted;
@property dispatch_semaphore_t done;
@end
@implementation Probe
- (void)fail:(NSString *)message {
    NSError *error; Fail(&error, message); self.failure = error;
}
- (void)URLSession:(NSURLSession *)session task:(NSURLSessionTask *)task
    willPerformHTTPRedirection:(NSHTTPURLResponse *)response
    newRequest:(NSURLRequest *)request
    completionHandler:(void (^)(NSURLRequest *))completionHandler {
    [self fail:@"Production endpoint redirected"]; completionHandler(nil);
}
- (void)URLSession:(NSURLSession *)session dataTask:(NSURLSessionDataTask *)task
    didReceiveResponse:(NSURLResponse *)response
    completionHandler:(void (^)(NSURLSessionResponseDisposition))completionHandler {
    _accepted = [response isKindOfClass:NSHTTPURLResponse.class] &&
        [(NSHTTPURLResponse *)response statusCode] == 200 &&
        response.expectedContentLength <= 131072;
    if (!_accepted) [self fail:@"Expected bounded HTTP 200 metadata"];
    completionHandler(_accepted ? NSURLSessionResponseAllow : NSURLSessionResponseCancel);
}
- (void)URLSession:(NSURLSession *)session dataTask:(NSURLSessionDataTask *)task
    didReceiveData:(NSData *)data {
    if (_body.length + data.length > 131072) {
        [self fail:@"Metadata limit exceeded"]; [task cancel]; return;
    }
    [_body appendData:data];
}
- (void)URLSession:(NSURLSession *)session task:(NSURLSessionTask *)task
    didCompleteWithError:(NSError *)error {
    if (!_failure) _failure = error;
    dispatch_semaphore_signal(_done);
}
@end
int main(void) { @autoreleasepool {
    NSURLComponents *url = [NSURLComponents componentsWithString:MTXRemoteUpdateURL()];
    if (![url.scheme isEqual:@"https"] || !url.host || url.user || url.password ||
        url.query || url.fragment || ![url.path hasSuffix:@"/api/update.php"]) return 2;
    unsigned char bytes[32];
    if (SecRandomCopyBytes(kSecRandomDefault, sizeof(bytes), bytes)) return 2;
    NSString *nonce = [[NSData dataWithBytes:bytes length:sizeof(bytes)] base64EncodedStringWithOptions:0];
    nonce = [[[nonce stringByReplacingOccurrencesOfString:@"+" withString:@"-"]
        stringByReplacingOccurrencesOfString:@"/" withString:@"_"]
        stringByReplacingOccurrencesOfString:@"=" withString:@""];
    url.queryItems = @[
        [NSURLQueryItem queryItemWithName:@"game_id" value:@(MTXRemoteGameID()).stringValue],
        [NSURLQueryItem queryItemWithName:@"nonce" value:nonce],
        [NSURLQueryItem queryItemWithName:@"installer_version" value:@"0.8.3"],
        [NSURLQueryItem queryItemWithName:@"os_version" value:@"16.1.2"],
        [NSURLQueryItem queryItemWithName:@"profile" value:MTXRemoteProfile()]];
    Probe *probe = [Probe new]; probe.body = [NSMutableData data];
    probe.done = dispatch_semaphore_create(0);
    NSURLSessionConfiguration *config = NSURLSessionConfiguration.ephemeralSessionConfiguration;
    config.requestCachePolicy = NSURLRequestReloadIgnoringLocalCacheData;
    config.HTTPShouldSetCookies = NO; config.HTTPCookieStorage = nil; config.URLCache = nil;
    config.timeoutIntervalForRequest = 20; config.timeoutIntervalForResource = 30;
    NSURLSession *session = [NSURLSession sessionWithConfiguration:config delegate:probe delegateQueue:nil];
    [[session dataTaskWithURL:url.URL] resume];
    if (dispatch_semaphore_wait(probe.done, dispatch_time(DISPATCH_TIME_NOW, 35*NSEC_PER_SEC))) {
        [session invalidateAndCancel]; fputs("Production request timed out\n", stderr); return 1;
    }
    [session finishTasksAndInvalidate];
    NSError *error = probe.failure;
    NSDictionary *payload = !error && probe.accepted ? MTXVerifyRemoteEnvelope(probe.body, nonce, &error) : nil;
    if (!payload) {
        fprintf(stderr, "Production verification failed: %s\n", (error.localizedDescription ?: @"Invalid response").UTF8String);
        return 1;
    }
    if (MTXVerifyRemoteEnvelope(probe.body, @"incorrect-nonce", NULL)) return 1;
    NSDictionary *report = @{@"https":@YES, @"redirected":@NO, @"signature":@"valid",
        @"nonce_replay_rejected":@YES, @"game_id":payload[@"game_id"],
        @"status":payload[@"status"], @"game_name":MTXRemoteGameName(),
        @"host":url.host, @"packages_downloaded":@0};
    NSData *json = [NSJSONSerialization dataWithJSONObject:report options:NSJSONWritingSortedKeys error:NULL];
    puts([[NSString alloc] initWithData:json encoding:NSUTF8StringEncoding].UTF8String);
    return 0;
} }
''')
    executable = temp / 'check'
    subprocess.run(['xcrun', '--toolchain', 'com.apple.dt.toolchain.XcodeDefault',
                    'clang', '-fobjc-arc', '-Wno-deprecated-declarations',
                    '-I', str(sources), '-framework', 'Foundation', '-framework', 'Security',
                    str(source), '-o', str(executable)], check=True)
    subprocess.run([str(executable)], check=True, timeout=40)
