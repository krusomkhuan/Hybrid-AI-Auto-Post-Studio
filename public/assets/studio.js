'use strict';
document.querySelectorAll('[data-copy]').forEach(button => {
  button.addEventListener('click', async () => {
    const field = document.getElementById(button.dataset.copy);
    if (!field) return;
    try {
      await navigator.clipboard.writeText(field.value);
      const notice = document.getElementById('copy-feedback');
      notice.hidden = false;
      setTimeout(() => { notice.hidden = true; }, 2400);
    } catch (_) {
      field.focus(); field.select();
      button.textContent = 'เลือกข้อความแล้ว กรุณาคัดลอก';
    }
  });
});
const select = document.querySelector('[data-media-select]');
if (select) {
  const video = document.getElementById('editor-video');
  const placeholder = document.getElementById('editor-placeholder');
  const update = () => {
    const src = select.selectedOptions[0]?.dataset.preview;
    video.hidden = !src;
    placeholder.hidden = Boolean(src);
    if (src) video.src = src;
    else { video.removeAttribute('src'); video.load(); }
  };
  select.addEventListener('change', update); update();
}
document.querySelector('[data-upload-form]')?.addEventListener('submit', event => {
  if (!event.target.reportValidity()) return;
  event.target.querySelector('button[type="submit"]').disabled = true;
  event.target.querySelector('.upload-status').textContent = 'กำลังอัปโหลดและตรวจไฟล์ กรุณารอ…';
});
