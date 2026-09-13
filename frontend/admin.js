import {createApp,h,ref} from 'vue';
import {ElTable,ElTableColumn,ElInput,ElSelect,ElOption,ElButton,ElPagination,ElConfigProvider,ElMessageBox} from 'element-plus';
import zhCn from 'element-plus/es/locale/lang/zh-cn';
import 'element-plus/dist/index.css';
import './admin.css';
import './legacy-admin.js';

const sidebar=document.querySelector('.admin-sidebar'),mobile=window.matchMedia('(max-width:760px)');
const layout=document.querySelector('.admin-layout'),toggle=document.querySelector('.admin-menu-toggle'),scrim=document.querySelector('.admin-nav-scrim');
function nav(open){layout?.classList.toggle('nav-open',open);toggle?.setAttribute('aria-expanded',String(open));if(scrim)scrim.hidden=!open;if(sidebar)sidebar.inert=mobile.matches&&!open;}
nav(false);mobile.addEventListener('change',()=>nav(false));
toggle?.addEventListener('click',()=>nav(!layout.classList.contains('nav-open')));
scrim?.addEventListener('click',()=>nav(false));
document.addEventListener('keydown',event=>{if(event.key==='Escape')nav(false);});
const approved=new WeakSet(),asking=new WeakSet();
document.querySelectorAll('form[data-confirm]').forEach(form=>form.addEventListener('submit',async event=>{
  if(approved.has(form)){approved.delete(form);return;}
  event.preventDefault();event.stopImmediatePropagation();if(asking.has(form))return;
  asking.add(form);const submitter=event.submitter;
  try{
    await ElMessageBox.confirm(form.dataset.confirm,'请确认操作',{confirmButtonText:'确认',cancelButtonText:'取消',type:'warning',closeOnClickModal:false});
    asking.delete(form);approved.add(form);
    if(submitter?.isConnected)form.requestSubmit(submitter);else form.requestSubmit();
  }catch{asking.delete(form);}
},true));
// A native select remains the form's source of truth, retaining names/values and
// native change events for existing preview scripts. File/password fields are untouched.
document.querySelectorAll('select:not([multiple])').forEach(select=>{
  if(select.closest('#conversation-app')||select.disabled)return;
  const host=document.createElement('div');host.className='admin-select-host';select.after(host);
  const options=Array.from(select.options).map(o=>({label:o.label,value:o.value,disabled:o.disabled}));
  const value=ref(select.value),label=select.labels?.[0]?.textContent||select.getAttribute('aria-label')||'选择';
  const id=select.id;if(id)select.removeAttribute('id');
  const oldDisplay=select.style.display;
  try{
    createApp({setup:()=>()=>h(ElConfigProvider,{locale:zhCn},()=>h(ElSelect,{id:id||undefined,modelValue:value.value,'onUpdate:modelValue':v=>{
      value.value=v;select.value=v;select.dispatchEvent(new Event('input',{bubbles:true}));select.dispatchEvent(new Event('change',{bubbles:true}));
    },filterable:options.length>5,'aria-label':label},()=>options.map(o=>h(ElOption,o))))}).mount(host);
    select.style.display='none';select.setAttribute('aria-hidden','true');select.tabIndex=-1;
    select.form?.addEventListener('reset',()=>queueMicrotask(()=>{value.value=select.value;}));
  }catch{host.remove();select.style.display=oldDisplay;if(id)select.id=id;}
});
function table(host,columns,rows,{search=true}={}){
  const q=ref(''),page=ref(1);
  createApp({setup(){return()=>h(ElConfigProvider,{locale:zhCn},()=>{
    const filtered=rows.filter(r=>!q.value||columns.some(c=>String(r[c.key]??'').toLowerCase().includes(q.value.toLowerCase())));
    const pages=Math.max(1,Math.ceil(filtered.length/10));if(page.value>pages)page.value=pages;
    return [
      search?h('div',{class:'admin-table-search'},[
        h('span',{class:'muted'},'筛选当前页面已加载的记录'),
        h(ElInput,{modelValue:q.value,'onUpdate:modelValue':v=>{q.value=v;page.value=1;},placeholder:'搜索记录',clearable:true,'aria-label':'搜索当前记录'})
      ]):null,
      h(ElTable,{data:filtered.slice((page.value-1)*10,page.value*10),stripe:true,emptyText:'暂无记录',rowKey:'id'},()=>columns.map(c=>h(ElTableColumn,{label:c.label,minWidth:c.width||140,fixed:c.action?'right':undefined,width:c.action?110:undefined}, {default:({row})=>c.action?h(ElButton,{link:true,type:'primary',onClick:row.open},()=> '详情与操作'):row.links?.[c.key]?h('a',{href:row.links[c.key],class:'admin-table-link'},String(row[c.key]??'')):String(row[c.key]??'')}))),
      h('div',{class:'admin-pagination'},h(ElPagination,{currentPage:page.value,'onUpdate:currentPage':v=>page.value=v,pageSize:10,total:filtered.length,layout:'total, prev, pager, next',background:true}))
    ];
  });}}).mount(host);
}
document.querySelectorAll('table.bot-table').forEach(original=>{
  if(original.querySelector('button,input,form')||(original.querySelector('a')&&!original.hasAttribute('data-installer-table')))return;
  const heads=Array.from(original.querySelectorAll('thead th')).map((n,i)=>({key:'c'+i,label:n.textContent.trim()}));
  if(!heads.length)return;
  const rows=Array.from(original.querySelectorAll('tbody tr')).map((tr,i)=>{
    const row={id:i,links:{}};
    Array.from(tr.cells).forEach((td,j)=>{
      row['c'+j]=td.textContent.trim();const href=td.querySelector('a')?.getAttribute('href');
      if(href&&/^\/installer\?game_id=[1-9][0-9]*$/.test(href))row.links['c'+j]=href;
    });return row;
  });
  const host=document.createElement('div');host.className='admin-table';original.after(host);
  try{table(host,heads,rows);original.hidden=true;}catch{host.remove();}
});
const releaseHost=document.getElementById('release-table');
if(releaseHost){
  const cards=Array.from(document.querySelectorAll('.release-card'));
  const rows=cards.map((card,i)=>({
    id:i,version:card.querySelector('h3')?.childNodes[0]?.textContent.trim()||'版本',
    status:card.querySelector('.badge')?.textContent.trim(),
    build:card.querySelector('.version-identity p')?.textContent.trim(),
    size:card.querySelector('.release-size')?.textContent.trim(),
    open:()=>{
      cards.forEach(c=>c.hidden=c!==card);
      card.hidden=false;card.querySelector('details').open=true;card.scrollIntoView({behavior:'smooth',block:'start'});
    }
  }));
  if(rows.length)try{
    table(releaseHost,[{key:'version',label:'版本',width:120},{key:'status',label:'状态',width:150},{key:'build',label:'构建与上传时间',width:290},{key:'size',label:'包大小',width:120},{label:'操作',action:true}],rows);
    cards.forEach(c=>c.hidden=true);
  }catch{releaseHost.replaceChildren();}
}
