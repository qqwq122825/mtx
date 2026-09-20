import {createApp,h,ref} from 'vue';
import {ElTable,ElTableColumn,ElButton,ElPagination,ElConfigProvider,ElMessageBox} from 'element-plus';
import zhCn from 'element-plus/es/locale/lang/zh-cn';

const codeCell=code=>h('code',{style:{whiteSpace:'normal',overflowWrap:'anywhere',userSelect:'text'}},code);
const stockHost=document.getElementById('trial-stock-table');
if(stockHost){
  const fallback=document.getElementById('trial-stock-fallback');
  const form=document.getElementById('stock-delete');
  const rows=Array.from(document.querySelectorAll('#trial-stock-source tbody tr')).map(tr=>({
    id:tr.querySelector('input[name="cards[]"]')?.value,
    code:tr.querySelector('input[readonly]').value,
    status:tr.cells[2].textContent.trim(),peer:tr.cells[3].textContent.trim(),
  })).map((row,index)=>({...row,key:row.id||`allocated-${index}`}));
  const selected=ref([]),table=ref(null),busy=ref(false);
  async function remove(cards){
    if(busy.value||!cards.length)return;
    const hashes=cards.map(card=>card.id).filter(Boolean);
    if(!hashes.length)return;
    busy.value=true;
    try{
      await ElMessageBox.confirm(`确认删除这 ${hashes.length} 张未分配卡密？活动及领取记录保留。`,'删除库存',{type:'warning',confirmButtonText:'确认删除',cancelButtonText:'取消',closeOnClickModal:false});
      form.querySelectorAll('[data-selected-card]').forEach(input=>input.remove());
      hashes.forEach(hash=>{
        const input=document.createElement('input');input.type='hidden';input.name='cards[]';input.value=hash;input.dataset.selectedCard='';form.append(input);
      });
      form.submit();
    }catch{busy.value=false;}
  }
  try{
    createApp({setup:()=>()=>h(ElConfigProvider,{locale:zhCn},()=>[
      h('div',{class:'bot-actions'},[
        h('span',{role:'status',class:'muted'},`已选 ${selected.value.length} 张（全选仅限本页未分配库存）`),
        h(ElButton,{disabled:busy.value||!selected.value.length,onClick:()=>table.value?.clearSelection()},()=> '取消选择'),
        h(ElButton,{type:'danger',disabled:busy.value||!selected.value.length,onClick:()=>remove(selected.value)},()=> '删除勾选卡密'),
      ]),
      h(ElTable,{ref:table,data:rows,rowKey:'key',stripe:true,emptyText:'暂无符合条件的卡密',onSelectionChange:value=>selected.value=value},()=>[
        h(ElTableColumn,{type:'selection',width:55,selectable:row=>!!row.id&&!busy.value}),
        h(ElTableColumn,{label:'原始卡密',minWidth:280},{default:({row})=>codeCell(row.code)}),
        h(ElTableColumn,{prop:'status',label:'状态',minWidth:170}),
        h(ElTableColumn,{prop:'peer',label:'领取用户',minWidth:140}),
        h(ElTableColumn,{label:'操作',width:110,fixed:'right'},{default:({row})=>row.id?h(ElButton,{type:'danger',link:true,disabled:busy.value,onClick:()=>remove([row])},()=> '删除此卡'):h('span',{class:'muted'},'已分配 · 保留')}),
      ]),
      h('div',{class:'admin-pagination'},h(ElPagination,{currentPage:Number(stockHost.dataset.page),pageSize:50,total:Number(stockHost.dataset.total),layout:'total, prev, pager, next',background:true,'onUpdate:currentPage':page=>{window.location.assign(`${stockHost.dataset.url}&page=${page}#inventory`);}})),
    ])}).mount(stockHost);
    // The mounted component owns selection; hidden native fallback fields must not POST.
    fallback.querySelectorAll('input[name="cards[]"]').forEach(input=>input.disabled=true);
    fallback.hidden=true;
  }catch{stockHost.replaceChildren();}
}
const claimsHost=document.getElementById('trial-claims-table');
if(claimsHost){
  const rows=Array.from(document.querySelectorAll('#trial-claims-source tbody tr')).map((tr,id)=>({id,at:tr.cells[0].textContent.trim(),peer:tr.cells[1].textContent.trim(),code:tr.querySelector('input[readonly]').value,status:tr.cells[3].textContent.trim()}));
  const page=ref(1);
  try{
    createApp({setup:()=>()=>h(ElConfigProvider,{locale:zhCn},()=>[
      h(ElTable,{data:rows.slice((page.value-1)*10,page.value*10),rowKey:'id',stripe:true,emptyText:'还没有领取记录'},()=>[
        h(ElTableColumn,{prop:'at',label:'领取时间（北京时间）',minWidth:180}),
        h(ElTableColumn,{prop:'peer',label:'用户 ID',minWidth:140}),
        h(ElTableColumn,{label:'原始卡密',minWidth:280},{default:({row})=>codeCell(row.code)}),
        h(ElTableColumn,{prop:'status',label:'发送状态',minWidth:170}),
      ]),
      h('div',{class:'admin-pagination'},h(ElPagination,{currentPage:page.value,pageSize:10,total:rows.length,layout:'total, prev, pager, next',background:true,'onUpdate:currentPage':value=>page.value=value})),
    ])}).mount(claimsHost);
    document.getElementById('trial-claims-fallback').hidden=true;
  }catch{claimsHost.replaceChildren();}
}
