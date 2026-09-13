// Test the confirmation handshake before any native POST/XHR handler runs.
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync('frontend/admin.js','utf8').replace(/^import .*;\n/gm,'');
function fixture(){
  let listener,resolve,reject,prompts=0,submitted=0,intercepted=0,bubbled=0,lastSubmitter;
  const form={dataset:{confirm:'fixture confirmation'},addEventListener(type,fn,capture){assert.equal(capture,true);listener=fn;},
    requestSubmit(submitter){submitted++;lastSubmitter=submitter;return dispatch(submitter);}};
  const dispatch=submitter=>listener({submitter,preventDefault(){intercepted++;},stopImmediatePropagation(){bubbled++;}});
  vm.runInNewContext(source,{
    document:{querySelector:()=>null,querySelectorAll:s=>s==='form[data-confirm]'?[form]:[],getElementById:()=>null,addEventListener(){}},
    window:{matchMedia:()=>({matches:false,addEventListener(){}})},WeakSet,
    ElMessageBox:{confirm:()=>{prompts++;return new Promise((ok,no)=>{resolve=ok;reject=no;});}},
  });
  return {dispatch,approve:()=>resolve(),cancel:()=>reject('cancel'),state:()=>({prompts,submitted,intercepted,bubbled,lastSubmitter})};
}
(async()=>{
  let f=fixture(),button={isConnected:true};let pending=f.dispatch(button);
  assert.equal(f.state().submitted,0);assert.equal(f.state().bubbled,1);
  await f.dispatch(button);assert.equal(f.state().prompts,1);
  f.cancel();await pending;assert.equal(f.state().submitted,0);
  pending=f.dispatch(button);f.approve();await pending;
  assert.equal(f.state().submitted,1);assert.equal(f.state().lastSubmitter,button);
  assert.equal(f.state().prompts,2);assert.equal(f.state().intercepted,3);
  pending=f.dispatch(button);f.cancel();await pending;assert.equal(f.state().submitted,1);
  f=fixture();pending=f.dispatch({isConnected:false});f.approve();await pending;
  assert.equal(f.state().submitted,1);assert.equal(f.state().lastSubmitter,undefined);
  console.log('7 admin confirmation checks passed; no POST or external API calls.');
})().catch(e=>{console.error(e);process.exitCode=1;});
