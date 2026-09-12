'use strict';
const toast = (message) => {
  const element = document.getElementById('toast');
  if (!element) return;
  element.textContent = message; element.hidden = false;
  setTimeout(() => { element.hidden = true; }, 2400);
};
document.querySelectorAll('[data-copy]').forEach(button => button.addEventListener('click', async () => {
  try { await navigator.clipboard.writeText(button.dataset.copy); toast('共用更新接口已复制'); }
  catch { toast('请选中地址后手动复制'); }
}));
document.querySelectorAll('[data-dialog]').forEach(button => button.addEventListener('click', () => document.getElementById(button.dataset.dialog).showModal()));
document.querySelectorAll('[data-close-dialog]').forEach(button => button.addEventListener('click', () => button.closest('dialog').close()));
document.querySelectorAll('[data-confirm]').forEach(form => form.addEventListener('submit', event => {
  if (!window.confirm(form.dataset.confirm)) event.preventDefault();
}));
document.querySelectorAll('input[type=file]').forEach(input => input.addEventListener('change', () => {
  const label = input.closest('.drop-zone')?.querySelector('.file-label');
  if (label) label.textContent = input.files[0]?.name || '还未选择文件';
}));
document.querySelectorAll('.upload-form').forEach(form => form.addEventListener('submit', event => {
  event.preventDefault();
  const button = form.querySelector('button[type=submit]');
  const feedback = form.querySelector('.upload-feedback');
  const progress = feedback.querySelector('progress');
  const status = feedback.querySelector('[role=status]');
  const file = form.querySelector('input[type=file]').files[0];
  feedback.hidden = false;
  if (!file || file.size > 268435456) { status.textContent = '请选择不超过 256 MiB 的文件。'; return; }
  button.disabled = true; progress.value = 0; status.textContent = '正在上传…';
  const xhr = new XMLHttpRequest();
  // A named input "action" shadows HTMLFormElement.action in browsers.
  xhr.open('POST', form.getAttribute('action')); xhr.timeout = 600000;
  xhr.setRequestHeader('X-MTX-Upload', '1');
  xhr.upload.onprogress = event => {
    if (event.lengthComputable) {
      progress.value = event.loaded / event.total * 100;
      status.textContent = progress.value >= 100 ? '上传完成，服务器正在检查结构与摘要…' : `正在上传 ${Math.round(progress.value)}%`;
    }
  };
  xhr.onload = () => {
    let response;
    try { response = JSON.parse(xhr.responseText); } catch { response = null; }
    if (xhr.status >= 200 && xhr.status < 300 && typeof response?.redirect === 'string' && response.redirect.startsWith(form.getAttribute('action').replace(/action\.php$/, '?'))) window.location.assign(response.redirect);
    else { button.disabled = false; status.textContent = response?.error?.message || `上传未完成（HTTP ${xhr.status}），请检查服务器上传额度或重新登录。`; }
  };
  xhr.onerror = xhr.ontimeout = () => { button.disabled = false; status.textContent = '连接中断或请求超时。请先刷新检查版本记录，避免重复上传。'; };
  xhr.send(new FormData(form));
}));
