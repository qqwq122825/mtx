// In-memory DOM fixture. No browser/network; preview content must never become raw HTML.
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync('public/assets/telegram-announcements.js', 'utf8');
class Element {
  constructor(tag) {this.tag = tag;this.children = [];this._text = '';}
  set innerHTML(_) {throw new Error('Raw HTML assignment prohibited');}
  set textContent(s) {this._text = s;this.children = [];}
  get textContent() {return this._text + this.children.map(c => c.textContent).join('');}
  append(el) {this.children.push(el);}
  replaceChildren() {this.children = [];this._text = '';}
}
const values = {text:'🔴 **标题**\n*提醒* <img src=x onerror=alert(1)>',contact:'@MySupport',bot_contact:'@MyBot_name',button1_text:'官方卡网',button1_url:'https://store.example.com',button2_text:'频道',button2_url:''};
const elements = Object.fromEntries(Object.entries(values).map(([k,v]) => [k,{value:v}]));
let input;
const form = {elements:{namedItem:key=>elements[key]},addEventListener:(event,handler)=>{input=handler;}};
const bubble = new Element('div'),buttons = new Element('div'),status = new Element('span');
const sends = [{disabled:false},{disabled:false}];
const document = {getElementById:id=>({'announcement-form':form,'announcement-preview':bubble,'announcement-buttons':buttons,'preview-state':status})[id],createElement:tag=>new Element(tag),createTextNode:text=>{const n=new Element('#text');n.textContent=text;return n;},querySelectorAll:()=>sends};
vm.runInNewContext(source,{document});
assert(bubble.children.some(c=>c.tag==='b' && c.textContent==='标题'));
assert(bubble.children.some(c=>c.tag==='i' && c.textContent==='提醒'));
assert(bubble.textContent.includes('<img src=x onerror=alert(1)>') && !bubble.children.some(c=>c.tag==='img'));
assert(bubble.textContent.includes('@MySupport') && bubble.textContent.includes('@MyBot_name'));
assert.equal(buttons.children.length,1);assert.equal(buttons.children[0].textContent,'官方卡网 ↗');
elements.text.value='新版 **消息**';elements.contact.value='';elements.bot_contact.value='';elements.button2_url.value='https://t.me/MyChannel';input();
assert(bubble.textContent==='新版 消息' && buttons.children.length===2);
assert.equal(status.textContent,'编辑中 · 请先保存');assert(sends.every(b=>b.disabled));
vm.runInNewContext(source,{document:{getElementById:()=>null}});
console.log('8 announcement preview checks passed. Text-node rendering; no HTML injection.');
