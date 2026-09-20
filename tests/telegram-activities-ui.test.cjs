const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync('frontend/activities.js','utf8').replace(/^import .*;\n/gm,'');
const renders={},hidden=[],inputs=[];let submitted=0,confirmations=0,cancel=false,navigated;
const stockHost={dataset:{page:'1',total:'51',url:'/admin/telegram.php?view=activities&stock_status=all'},replaceChildren(){}},claimsHost={replaceChildren(){}};
const fallback={hidden:false,querySelectorAll:()=>hidden};const claimsFallback={hidden:false};
const form={querySelectorAll:()=>inputs,append:input=>inputs.push(input),submit:()=>submitted++};
const row=(id,code)=>({cells:[{textContent:'09-21 08:00'},{textContent:'#123'},{textContent:id?'未分配':'已发送'},{textContent:id?'—':'#123'}],querySelector:selector=>selector.includes('readonly')?{value:code}:id?{value:id}:null});
const els={'trial-stock-table':stockHost,'trial-stock-fallback':fallback,'stock-delete':form,'trial-claims-table':claimsHost,'trial-claims-fallback':claimsFallback};
const context={document:{getElementById:id=>els[id],querySelectorAll:selector=>selector.includes('stock')?[row('hash1','CARD_ONE'),row(null,'CARD_USED')]:[row(null,'CLAIM_CODE')],createElement:()=>({dataset:{},remove(){inputs.splice(inputs.indexOf(this),1);}})},window:{location:{assign:url=>navigated=url}},ref:value=>({value}),createApp:app=>({mount:host=>{renders[host===stockHost?'stock':'claims']=app.setup();renders[host===stockHost?'stock':'claims']();}}),h:(type,props,children)=>({type,props:props||{},children:typeof children==='function'?children():children}),zhCn:{},ElMessageBox:{confirm:async()=>{confirmations++;if(cancel)throw Error('cancel');}}};
for(const key of ['ElTable','ElTableColumn','ElButton','ElPagination','ElConfigProvider'])context[key]=key;
vm.runInNewContext(source,context);
function find(tree,type){if(!tree)return[];if(Array.isArray(tree))return tree.flatMap(t=>find(t,type));return [...(tree.type===type?[tree]:[]),...find(tree.children?.type||Array.isArray(tree.children)?tree.children:[],type)];}
(async()=>{
 assert(fallback.hidden&&claimsFallback.hidden);
 let tree=renders.stock(),table=find(tree,'ElTable')[0],selection=find(tree,'ElTableColumn')[0];
 assert.equal(selection.props.type,'selection');assert(selection.props.selectable(table.props.data[0]));assert(!selection.props.selectable(table.props.data[1]));
 assert(find(tree,'ElButton')[1].props.disabled);
 table.props.onSelectionChange([table.props.data[0]]);
 tree=renders.stock();assert(!find(tree,'ElButton')[1].props.disabled);
 cancel=true;await find(tree,'ElButton')[1].props.onClick();assert.equal(submitted,0);
 cancel=false;await find(renders.stock(),'ElButton')[1].props.onClick();assert.equal(submitted,1);assert.equal(inputs[0].name,'cards[]');assert.equal(inputs[0].value,'hash1');assert.equal(confirmations,2);
 assert(find(renders.stock(),'ElButton')[1].props.disabled);
 find(tree,'ElPagination')[0].props['onUpdate:currentPage'](2);assert(navigated.endsWith('&page=2#inventory'));
 const claimTable=find(renders.claims(),'ElTable')[0];assert.equal(claimTable.props.data[0].code,'CLAIM_CODE');
 const codeColumn=find(renders.claims(),'ElTableColumn')[2];assert.equal(codeColumn.children.default({row:{code:'<original-card>'}}).children,'<original-card>');
 console.log('Element Plus table tests passed: selection, allocated protection, confirmation/cancel, POST fields, pagination, original card rendering.');
})().catch(error=>{console.error(error);process.exitCode=1;});
