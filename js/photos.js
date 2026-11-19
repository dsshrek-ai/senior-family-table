// Photos: shrinking before upload, uploading, and a full-screen viewer.
// The API hands out signed links that expire (docs/ARCHITECTURE.md §8); photoSrc() puts the
// API address in front. Requires js/api.js and js/app.js.

const PHOTO_SEND_PX = 2000;          // the server keeps 1600 px; a little extra keeps it sharp
const PHOTO_MAX_MB = 15;

function photoSrc(link) {
  return CONFIG.API_URL + link;
}

// Turns a phone photo (often 5–12 MB) into a ~0.5 MB upright JPEG before sending.
// Falls back to the original file when the browser can't read it (the server still checks it).
async function preparePhoto(file) {
  let bitmap = null;
  try {
    bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
  } catch (e) {
    try { bitmap = await createImageBitmap(file); } catch (e2) { bitmap = null; }
  }
  if (!bitmap) {
    if (/^image\/(jpeg|png|webp)$/.test(file.type) && file.size <= PHOTO_MAX_MB * 1048576) { return file; }
    throw new Error(`"${file.name}" isn't a photo this browser can read. Try a JPEG or PNG.`);
  }
  const scale = Math.min(1, PHOTO_SEND_PX / Math.max(bitmap.width, bitmap.height));
  const canvas = document.createElement('canvas');
  canvas.width = Math.max(1, Math.round(bitmap.width * scale));
  canvas.height = Math.max(1, Math.round(bitmap.height * scale));
  const ctx = canvas.getContext('2d');
  ctx.fillStyle = '#fff';
  ctx.fillRect(0, 0, canvas.width, canvas.height);
  ctx.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
  if (bitmap.close) { bitmap.close(); }
  const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', 0.88));
  if (!blob) { return file; }
  return new File([blob], (file.name || 'photo').replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' });
}

// action: 'uploadRecipePhoto' (params.recipeId) or 'uploadNotePhoto' (params.noteId).
async function uploadPhoto(action, params, file, caption) {
  const ready = await preparePhoto(file);
  const form = new FormData();
  form.append('file', ready, ready.name || 'photo.jpg');
  if (caption) { form.append('caption', caption); }
  const qs = new URLSearchParams({ action, ...params });
  return readResponse(await fetch(`${CONFIG.API_URL}?${qs}`, { method: 'POST', headers: authHeaders(), body: form }));
}

// A hidden file picker; calls onFiles with the chosen files.
function pickPhotos(onFiles, multiple = true) {
  const input = document.createElement('input');
  input.type = 'file';
  input.accept = 'image/jpeg,image/png,image/webp,image/*';
  input.multiple = multiple;
  input.style.display = 'none';
  input.addEventListener('change', () => {
    const files = [...input.files];
    input.remove();
    if (files.length) { onFiles(files); }
  });
  document.body.append(input);
  input.click();
}

// ---- Full-screen viewer ----------------------------------------------------------
// items: [{ full, caption }] (signed links); opens at `start`. Arrow keys, swipe, Esc.

function openPhotoViewer(items, start = 0) {
  let i = start;
  const back = document.activeElement;
  const box = document.createElement('div');
  box.className = 'viewer';
  box.setAttribute('role', 'dialog');
  box.setAttribute('aria-modal', 'true');
  box.setAttribute('aria-label', 'Photo');
  box.innerHTML = `
    <button type="button" class="viewer-close" aria-label="Close">✕</button>
    ${items.length > 1 ? '<button type="button" class="viewer-nav prev" aria-label="Previous photo">‹</button><button type="button" class="viewer-nav next" aria-label="Next photo">›</button>' : ''}
    <figure><img alt=""><figcaption></figcaption></figure>`;
  const img = box.querySelector('img');
  const cap = box.querySelector('figcaption');
  const show = () => {
    const it = items[i];
    img.src = photoSrc(it.full);
    img.alt = it.caption || `Photo ${i + 1} of ${items.length}`;
    cap.textContent = [it.caption, items.length > 1 ? `${i + 1} / ${items.length}` : ''].filter(Boolean).join('  ·  ');
  };
  const go = d => { i = (i + d + items.length) % items.length; show(); };
  const close = () => {
    document.removeEventListener('keydown', onKey);
    box.remove();
    document.body.classList.remove('viewer-open');
    if (back && back.focus) { back.focus(); }
  };
  const onKey = ev => {
    if (ev.key === 'Escape') { close(); }
    else if (ev.key === 'ArrowLeft' && items.length > 1) { go(-1); }
    else if (ev.key === 'ArrowRight' && items.length > 1) { go(1); }
  };
  box.querySelector('.viewer-close').addEventListener('click', close);
  box.addEventListener('click', ev => { if (ev.target === box || ev.target.tagName === 'FIGURE') { close(); } });
  if (items.length > 1) {
    box.querySelector('.prev').addEventListener('click', () => go(-1));
    box.querySelector('.next').addEventListener('click', () => go(1));
    let x0 = null;
    box.addEventListener('touchstart', ev => { x0 = ev.touches[0].clientX; }, { passive: true });
    box.addEventListener('touchend', ev => {
      if (x0 === null) { return; }
      const dx = ev.changedTouches[0].clientX - x0;
      if (Math.abs(dx) > 50) { go(dx < 0 ? 1 : -1); }
      x0 = null;
    });
  }
  document.addEventListener('keydown', onKey);
  document.body.classList.add('viewer-open');
  document.body.append(box);
  show();
  box.querySelector('.viewer-close').focus();
}

// Fetches a photo as a data URL (for the PDF). Null if it can't be had.
async function photoDataUrl(link) {
  try {
    const res = await fetch(photoSrc(link));
    if (!res.ok) { return null; }
    const blob = await res.blob();
    return await new Promise(resolve => {
      const fr = new FileReader();
      fr.onload = () => resolve(fr.result);
      fr.onerror = () => resolve(null);
      fr.readAsDataURL(blob);
    });
  } catch (e) {
    return null;
  }
}
