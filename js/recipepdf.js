// "Send as PDF": a printable recipe for someone without an account. Built entirely in the
// browser with the vendored jsPDF (js/vendor/), then handed to the phone's share sheet
// (Messages, Mail…) or downloaded on a computer. Never includes private notes.
// Requires js/api.js and js/app.js.

let jsPdfLoading = null;
function loadJsPdf() {
  if (window.jspdf) { return Promise.resolve(window.jspdf); }
  if (!jsPdfLoading) {
    jsPdfLoading = new Promise((resolve, reject) => {
      const s = document.createElement('script');
      s.src = 'js/vendor/jspdf.umd.min.js';
      s.onload = () => resolve(window.jspdf);
      s.onerror = () => { jsPdfLoading = null; reject(new Error('Couldn\'t load the PDF maker. Check your connection and try again.')); };
      document.head.append(s);
    });
  }
  return jsPdfLoading;
}

// The PDF's built-in fonts cover Western characters (including ½ ¼ ¾) but not ⅓ ⅔ ⅛ …
const PDF_FRACTIONS = { '⅓': '1/3', '⅔': '2/3', '⅛': '1/8', '⅜': '3/8', '⅝': '5/8', '⅞': '7/8', '⅕': '1/5', '⅙': '1/6' };
const WIN_ANSI_EXTRAS = '€‚ƒ„…†‡ˆ‰Š‹ŒŽ‘’“”•–—˜™š›œžŸ';
function pdfText(text) {
  return String(text ?? '')
    .replace(/(\d)([⅓⅔⅛⅜⅝⅞⅕⅙])/g, (m, d, f) => `${d} ${PDF_FRACTIONS[f]}`)
    .replace(/[⅓⅔⅛⅜⅝⅞⅕⅙]/g, f => PDF_FRACTIONS[f])
    .replace(/[^\n\x20-\xFF]/g, ch => (WIN_ANSI_EXTRAS.includes(ch) ? ch : ''));
}

function slugify(title) {
  return String(title).normalize('NFKD').replace(/[^\w\s-]/g, '').trim().replace(/\s+/g, '-').slice(0, 60) || 'recipe';
}

/**
 * r: the recipe as getRecipe returns it; f: scale factor; amountLabel: e.g. "12 servings" when
 * scaled (shown under the title), or '' at the original amount; cover: { data (JPEG data URL),
 * width, height } or null.
 */
async function buildRecipePdf(r, f, amountLabel, cover = null) {
  const { jsPDF } = await loadJsPdf();
  const doc = new jsPDF({ unit: 'pt', format: 'letter' });
  const W = doc.internal.pageSize.getWidth();
  const H = doc.internal.pageSize.getHeight();
  const M = 54;
  const width = W - 2 * M;
  let y = M;
  const green = [61, 90, 62];

  const ensure = h => { if (y + h > H - M) { doc.addPage(); y = M; } };
  const para = (text, { size = 11, font = 'helvetica', style = 'normal', indent = 0, gap = 4, color = [30, 30, 30] } = {}) => {
    doc.setFont(font, style);
    doc.setFontSize(size);
    doc.setTextColor(...color);
    const lines = doc.splitTextToSize(pdfText(text), width - indent);
    const lh = size * 1.3;
    for (const line of lines) {
      ensure(lh);
      doc.text(line, M + indent, y + size);
      y += lh;
    }
    y += gap;
  };
  const heading = text => {
    ensure(40);
    y += 8;
    para(text, { size: 14, font: 'times', style: 'bold', color: green, gap: 2 });
    doc.setDrawColor(...green);
    doc.setLineWidth(0.8);
    doc.line(M, y, W - M, y);
    y += 8;
  };

  if (cover) {
    const h = Math.min(230, width * cover.height / cover.width);
    const w = h * cover.width / cover.height;
    try {
      doc.addImage(cover.data, 'JPEG', M + (width - w) / 2, y, w, h);
      y += h + 14;
    } catch (e) { /* a photo that won't embed is left out */ }
  }
  para(r.title, { size: 22, font: 'times', style: 'bold', gap: 2 });
  const facts = [
    amountLabel ? `Makes ${amountLabel} (scaled)` : (r.servings ? `Serves ${formatQuantity(r.servings)}` : ''),
    !amountLabel && r.yieldQuantity ? `Makes ${formatQuantity(r.yieldQuantity)} ${r.yieldUnit || ''}`.trim() : '',
    r.prepMinutes !== null ? `Prep ${formatMinutes(r.prepMinutes)}` : '',
    r.cookMinutes !== null ? `Cook ${formatMinutes(r.cookMinutes)}` : '',
    r.totalMinutes !== null ? `Total ${formatMinutes(r.totalMinutes)}` : '',
  ].filter(Boolean).join('   ·   ');
  if (facts) { para(facts, { size: 10, color: [100, 100, 100], gap: 6 }); }
  if (r.description) { para(r.description, { size: 11, style: 'italic', gap: 6 }); }
  if (amountLabel) {
    para('Amounts are scaled. Cooking times and pan sizes may need adjusting.', { size: 9, color: [120, 90, 0], gap: 4 });
  }

  if (r.ingredients.length) {
    heading('Ingredients');
    let section = null;
    for (const i of r.ingredients) {
      if ((i.section || null) !== section) {
        section = i.section || null;
        if (section) { para(section, { size: 11, style: 'bold', gap: 2 }); }
      }
      const line = scaledIngredientText(i, f) + (i.optional && !/optional/i.test(i.text) ? ' (optional)' : '');
      ensure(16);
      doc.setFillColor(...green);
      doc.circle(M + 4, y + 7, 1.8, 'F');
      para(line, { indent: 14, gap: 2 });
    }
  }

  const stepsBlock = (steps, title) => {
    if (!steps.length) { return; }
    if (title) { para(title, { size: 12, style: 'bold', gap: 2 }); }
    steps.forEach((s, n) => {
      const num = `${n + 1}.`;
      ensure(16);
      doc.setFont('helvetica', 'bold');
      doc.setFontSize(11);
      doc.setTextColor(...green);
      doc.text(num, M, y + 11);
      para(s.text, { indent: 22, gap: 5 });
    });
  };
  if (r.steps.length) {
    heading('Steps');
    stepsBlock(r.steps.filter(s => s.methodId === null), '');
    for (const m of r.methods) { stepsBlock(r.steps.filter(s => s.methodId === m.id), m.name); }
  }
  if (r.generalNotes) {
    heading('Notes');
    para(r.generalNotes);
  }

  // Footer on every page.
  const pages = doc.getNumberOfPages();
  for (let p = 1; p <= pages; p++) {
    doc.setPage(p);
    doc.setFont('helvetica', 'normal');
    doc.setFontSize(8);
    doc.setTextColor(140, 140, 140);
    doc.text(pdfText(`From The Senior Family Table${r.source ? ` · Source: ${r.source}` : ''}`), M, H - 28);
    if (pages > 1) { doc.text(`${p} / ${pages}`, W - M, H - 28, { align: 'right' }); }
  }
  return doc.output('blob');
}

// Builds the PDF and offers it to the phone's share sheet, or downloads it.
async function sendRecipePdf(r, f, amountLabel, cover = null) {
  const blob = await buildRecipePdf(r, f, amountLabel, cover);
  const name = `${slugify(r.title)}.pdf`;
  const file = new File([blob], name, { type: 'application/pdf' });
  // Phones and tablets: the share sheet (Messages, Mail…). Computers: a plain download,
  // which is what people expect there.
  const touch = window.matchMedia && window.matchMedia('(pointer: coarse)').matches;
  if (touch && navigator.canShare && navigator.canShare({ files: [file] })) {
    try {
      await navigator.share({ files: [file], title: r.title });
      return 'shared';
    } catch (e) {
      if (e && e.name === 'AbortError') { return 'cancelled'; }
      // Sharing not allowed here — fall through to a download.
    }
  }
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = name;
  document.body.append(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 10000);
  return 'downloaded';
}
