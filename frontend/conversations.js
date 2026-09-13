import {createApp,h,ref,onMounted} from 'vue';
import {ElTable,ElTableColumn,ElInput,ElSelect,ElOption,ElButton,ElTag,ElDrawer,ElPagination,ElConfigProvider} from 'element-plus';
import zhCn from 'element-plus/es/locale/lang/zh-cn';
import 'element-plus/dist/index.css';
import './conversations.css';
const root=document.getElementById('conversation-app');
const stateLabels={waiting:'待回复',replied:'已回复',exception:'投递需核对'};
const sendLabels={pending:'处理中 / 待确认',delivered:'已发送',retry:'等待重试',failed:'发送失败',uncertain:'结果待确认'};
const stamp=at=>new Intl.DateTimeFormat('zh-CN',{timeZone:'Asia/Shanghai',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:false}).format(new Date(at*1000));
async function api(action,params={}) {
  const response=await fetch(root.dataset.endpoint,{method:'POST',credentials:'same-origin',redirect:'error',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({csrf:root.dataset.csrf,action,...params})});
  if(!response.ok || !response.headers.get('content-type')?.includes('application/json'))throw new Error('暂未获取到记录，请刷新页面检查登录状态或稍后重试。');
  return response.json();
}
createApp({setup(){
  const rows=ref([]),q=ref(''),filter=ref('all'),loading=ref(false),error=ref(''),page=ref(1),total=ref(0),counts=ref({all:0,waiting:0,replied:0,exception:0}),days=ref(30),enabled=ref(true);
  const drawer=ref(false),selected=ref(null),messages=ref([]),detailPage=ref(1),detailTotal=ref(0),detailLoading=ref(false),detailError=ref('');
  let listRequest=0,detailRequest=0;
  async function load(reset=false){if(reset)page.value=1;const id=++listRequest;loading.value=true;error.value='';try{const d=await api('conversation_list',{q:q.value,status:filter.value,page:page.value});if(id!==listRequest)return;rows.value=d.rows;total.value=d.total;page.value=d.page;counts.value=d.counts;days.value=d.days;enabled.value=d.enabled;}catch(e){if(id===listRequest)error.value=e.message;}finally{if(id===listRequest)loading.value=false;}}
  async function detail(row,p=1){selected.value=row;drawer.value=true;messages.value=[];detailPage.value=p;detailError.value='';detailLoading.value=true;const id=++detailRequest;try{const d=await api('conversation_detail',{peer:row.peer,page:p});if(id!==detailRequest)return;messages.value=d.messages;detailTotal.value=d.total;detailPage.value=d.page;}catch(e){if(id===detailRequest)detailError.value=e.message;}finally{if(id===detailRequest)detailLoading.value=false;}}
  onMounted(()=>load());
  const column=(label,props,render)=>h(ElTableColumn,{label,...props},render?{default:({row})=>render(row)}:undefined);
  const statusTag=row=>h(ElTag,{type:row.status==='replied'?'success':row.status==='exception'?'danger':'warning',effect:'light'},()=>stateLabels[row.status]);
  return()=>h(ElConfigProvider,{locale:zhCn},()=>[
    h('div',{class:'conversation-metrics'},['all','waiting','replied','exception'].map(key=>h('button',{class:['conversation-metric',filter.value===key?'selected':''],onClick:()=>{filter.value=key;load(true);}},[h('span',key==='all'?'全部会话':stateLabels[key]),h('strong',String(counts.value[key]))]))),
    h('section',{class:'panel conversation-table-panel'},[
      h('div',{class:'conversation-toolbar'},[
        h(ElInput,{modelValue:q.value,'onUpdate:modelValue':v=>q.value=v,placeholder:'搜索昵称、@用户名、ID、最近咨询或回复',maxlength:100,clearable:true,'aria-label':'搜索客服会话',onKeydown:e=>{if(e.key==='Enter')load(true);}}),
        h(ElSelect,{modelValue:filter.value,'onUpdate:modelValue':v=>{filter.value=v;load(true);},'aria-label':'回复状态'},()=>['all','waiting','replied','exception'].map(key=>h(ElOption,{label:key==='all'?'全部状态':stateLabels[key],value:key}))),
        h(ElButton,{type:'primary',onClick:()=>load(true),loading:loading.value},()=> '查询'),
        h(ElButton,{onClick:()=>load(),disabled:loading.value},()=> '刷新')]),
      h('p',{class:'conversation-hint'},`${enabled.value?'正在记录新咨询':'新消息记录已关闭'} · 最近 ${days.value} 天 / 最多 5000 条消息 · 时间均为北京时间`),
      error.value?h('div',{class:'notice error',role:'alert'},error.value):null,
      h(ElTable,{data:rows.value,stripe:true,border:false,rowKey:'peer',emptyText:loading.value?'正在加载…':'暂无匹配的会话。收到新的用户咨询后会出现在这里。',onRowDblclick:row=>detail(row)},()=>[
        column('用户',{minWidth:205},row=>h('div',{class:'conversation-person'},[h('strong',row.name),row.username?h('a',{href:`https://t.me/${row.username}`,target:'_blank',rel:'noopener noreferrer'},'@'+row.username):h('span',{class:'muted'},'未设置用户名'),h('small','#'+row.peer)])),
        ...[['用户最近咨询','last_in'],['客服最近回复','last_out']].map(([label,key])=>column(label,{minWidth:260},row=>{
          const m=row[key];
          return h('div',{class:'conversation-preview'},m?[
            h('span',m.text||'[无文字内容]'),
            h('small',{class:'conversation-direction'},stamp(m.at)),
            m.status!=='delivered'?h('small',{class:'conversation-error'},sendLabels[m.status]||m.status):null
          ]:[h('span',{class:'muted'},key==='last_out'?'暂无回复':'暂无保留的咨询')]);
        })),
        column('状态',{width:130},statusTag),column('最近时间',{width:165},row=>stamp(row.last_at)),column('消息数',{prop:'count',width:85}),
        column('操作',{width:110,fixed:'right'},row=>h(ElButton,{link:true,type:'primary',onClick:()=>detail(row)},()=> '查看会话'))]),
      h('div',{class:'conversation-pagination'},h(ElPagination,{currentPage:page.value,'onUpdate:currentPage':v=>{page.value=v;load();},pageSize:20,total:total.value,layout:'total, prev, pager, next',background:true}))]),
    h(ElDrawer,{modelValue:drawer.value,'onUpdate:modelValue':v=>{drawer.value=v;if(!v)detailRequest++;},title:selected.value?`${selected.value.name} ${selected.value.username?'@'+selected.value.username:''} · #${selected.value.peer}`:'会话详情',size:'min(680px, 100vw)',destroyOnClose:true},()=>[
      h('p',{class:'conversation-hint'},'这里只查看记录。回复请继续在 Telegram 中对对应用户消息使用“回复”。'),
      detailError.value?h('div',{class:'notice error',role:'alert'},detailError.value):null,
      detailLoading.value?h('p','正在加载会话…'):null,
      !detailLoading.value&&!messages.value.length&&!detailError.value?h('p','此会话暂无保留中的记录。'):null,
      ...messages.value.map(m=>h('article',{class:['conversation-message',m.direction==='out'?'outgoing':'incoming'],key:m.id},[
        h('div',{class:'conversation-message-meta'},[h('strong',m.direction==='out'?'客服回复':(m.username?'@'+m.username:m.name||'用户')),h('time',stamp(m.at))]),
        m.type!=='text'?h('div',{class:'conversation-media'},`[${m.type}] ${m.file_name||''}`):null,
        h('p',{class:'conversation-message-text'},m.text|| (m.type==='text'?'[无文字内容]':'附件请在 Telegram 查看')),
        h('small',{class:m.status==='delivered'?'muted':'conversation-error'},(m.direction==='in'?'转发给客服：':'回复用户：')+(sendLabels[m.status]||m.status))])),
      h(ElPagination,{currentPage:detailPage.value,'onUpdate:currentPage':p=>detail(selected.value,p),pageSize:50,total:detailTotal.value,layout:'total, prev, next',small:true}),
      h('p',{class:'conversation-hint'},'第 1 页为最近消息；已发送不代表对方已读。')])]);
}}).mount(root);
