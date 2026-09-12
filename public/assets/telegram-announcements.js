'use strict';
(() => {
  const form = document.getElementById('announcement-form');
  if (!form) return;
  const bubble = document.getElementById('announcement-preview');
  const buttons = document.getElementById('announcement-buttons');
  const value = key => form.elements.namedItem(key).value.trim();
  const render = () => {
    bubble.replaceChildren();
    const parts = value('text').split(/(\*\*[^*\n]+\*\*|\*[^*\n]+\*)/u);
    for (const part of parts) {
      const bold = part.startsWith('**') && part.endsWith('**') && part.length > 4;
      const italic = !bold && part.startsWith('*') && part.endsWith('*') && part.length > 2;
      if (bold || italic) {
        const el = document.createElement(bold ? 'b' : 'i');
        el.textContent = part.slice(bold ? 2 : 1, bold ? -2 : -1);
        bubble.append(el);
      } else bubble.append(document.createTextNode(part));
    }
    for (const [key, label] of [['contact', '💬 唯一客服：'], ['bot_contact', '🤖 双向联系：']]) {
      const username = value(key).replace(/^@+/, '');
      if (!username) continue;
      bubble.append(document.createTextNode('\n' + label));
      const el = document.createElement('span');el.className = 'telegram-mention';el.textContent = '@' + username;bubble.append(el);
    }
    buttons.replaceChildren();
    for (let i = 1; i <= 2; i++) {
      if (!value(`button${i}_url`)) continue;
      const el = document.createElement('span');el.textContent = (value(`button${i}_text`) || '按钮名称') + ' ↗';buttons.append(el);
    }
  };
  form.addEventListener('input', () => {
    render();document.getElementById('preview-state').textContent = '编辑中 · 请先保存';
    document.querySelectorAll('[data-saved-send]').forEach(button => { button.disabled = true; });
  });
  render();
})();
