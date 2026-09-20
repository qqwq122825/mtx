(() => {
  const inventory = document.getElementById('inventory');
  const all = document.getElementById('stock-select-all');
  if (!inventory || !all) return;
  const cards = Array.from(inventory.querySelectorAll('input[name="cards[]"][form="stock-delete"]')).filter(card => !card.disabled);
  const count = document.getElementById('stock-selection-count');
  const clear = document.getElementById('stock-clear-selection');
  const buttons = inventory.querySelectorAll('[data-stock-bulk]');
  const sync = () => {
    const selected = cards.filter(card => card.checked).length;
    all.checked = cards.length > 0 && selected === cards.length;
    all.indeterminate = selected > 0 && selected < cards.length;
    all.disabled = cards.length === 0;
    count.textContent = `已选 ${selected} 张（本页可选 ${cards.length} 张）`;
    clear.disabled = selected === 0;
    buttons.forEach(button => { button.disabled = selected === 0; });
  };
  all.addEventListener('change', () => {
    cards.forEach(card => { card.checked = all.checked; });
    sync();
  });
  clear.addEventListener('click', () => {
    cards.forEach(card => { card.checked = false; });
    sync();
  });
  cards.forEach(card => card.addEventListener('change', sync));
  window.addEventListener('pageshow', sync);
  sync();
})();
