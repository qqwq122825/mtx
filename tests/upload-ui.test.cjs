// DOM-independent regression harness for the upload controller.
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
let checks = 0;
function fixture(size = 12) {
  const handlers = {};
  const button = {disabled:false};
  const progress = {value:0};
  const status = {textContent:''};
  const feedback = {hidden:true,querySelector:s=>s==='progress'?progress:status};
  const form = {
    // Browsers expose a named <input name="action"> through form.action.
    action: {value:'upload'},
    getAttribute: name => name === 'action' ? '/renamed_console/action.php' : null,
    addEventListener: (name, callback) => handlers[name]=callback,
    querySelector: s => s==='button[type=submit]'?button:s==='.upload-feedback'?feedback:{files:[{size}]},
  };
  let xhr; let redirect;
  class FakeXHR {
    constructor() { xhr=this; this.upload={}; }
    open(method,url) { this.method=method; this.url=url; }
    setRequestHeader() {}
    send(body) { this.body=body; }
  }
  const context = {
    document:{querySelectorAll:sel=>sel==='.upload-form'?[form]:[],getElementById:()=>null},
    window:{location:{assign:url=>redirect=url}},navigator:{},
    XMLHttpRequest:FakeXHR,FormData:class {constructor(f){this.form=f;}},setTimeout,
  };
  vm.runInNewContext(fs.readFileSync('frontend/legacy-admin.js','utf8'),context);
  handlers.submit({preventDefault(){}});
  return {xhr,button,progress,status,feedback,getRedirect:()=>redirect};
}
let f=fixture();
assert.equal(f.xhr.url,'/renamed_console/action.php');assert.equal(f.xhr.method,'POST');checks++;
assert.equal(f.button.disabled,true);assert.equal(f.feedback.hidden,false);checks++;
f.xhr.upload.onprogress({lengthComputable:true,loaded:12,total:12});assert.equal(f.progress.value,100);assert.match(f.status.textContent,/服务器/);checks++;
f.xhr.status=200;f.xhr.responseText=JSON.stringify({redirect:'/renamed_console/?app=test'});f.xhr.onload();assert.equal(f.getRedirect(),'/renamed_console/?app=test');checks++;
f=fixture();f.xhr.status=422;f.xhr.responseText=JSON.stringify({error:{message:'Bundle ID mismatch'}});f.xhr.onload();assert.equal(f.button.disabled,false);assert.equal(f.status.textContent,'Bundle ID mismatch');checks++;
f=fixture(268435457);assert.equal(f.xhr,undefined);assert.equal(f.button.disabled,false);assert.match(f.status.textContent,/256 MiB/);checks++;
f=fixture();f.xhr.status=200;f.xhr.responseText=JSON.stringify({redirect:'https://other.example.com'});f.xhr.onload();assert.equal(f.getRedirect(),undefined);checks++;
console.log(`${checks} upload-controller regression checks passed.`);
