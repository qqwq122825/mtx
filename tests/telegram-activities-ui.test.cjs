const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync('public/assets/telegram-activities.js', 'utf8');
function element() {
  return {checked:false, disabled:false, listeners:{}, addEventListener(event, fn) {this.listeners[event]=fn;}};
}
function fixture(size) {
  const all=element(), clear=element(), count=element();
  const cards=Array.from({length:size}, element), buttons=[element(), element()];
  const inventory={querySelectorAll:selector=>selector==='[data-stock-bulk]'?buttons:cards};
  const elements={'inventory':inventory,'stock-select-all':all,'stock-clear-selection':clear,'stock-selection-count':count};
  vm.runInNewContext(source,{document:{getElementById:id=>elements[id]},window:{addEventListener(){}}});
  return {all,clear,count,cards,buttons};
}
const f=fixture(3);
assert(f.buttons.every(b=>b.disabled));
f.cards[0].checked=true;f.cards[0].listeners.change();
assert(f.all.indeterminate && !f.all.checked && f.count.textContent.includes('已选 1 张'));
f.cards[1].checked=true;f.cards[1].listeners.change();
assert(f.cards[0].checked && f.count.textContent.includes('已选 2 张'));
f.all.checked=true;f.all.listeners.change();
assert(f.cards.every(c=>c.checked) && !f.all.indeterminate && f.buttons.every(b=>!b.disabled));
f.cards[1].checked=false;f.cards[1].listeners.change();
assert(f.all.indeterminate && !f.all.checked);
f.clear.listeners.click();
assert(f.cards.every(c=>!c.checked) && f.buttons.every(b=>b.disabled));
f.all.checked=true;f.all.listeners.change();f.all.checked=false;f.all.listeners.change();
assert(f.cards.every(c=>!c.checked));
const empty=fixture(0);assert(empty.all.disabled && empty.buttons.every(b=>b.disabled));
vm.runInNewContext(source,{document:{getElementById:()=>null}});
console.log('9 inventory selection UI checks passed.');
