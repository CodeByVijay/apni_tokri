<?php
require_once __DIR__ . '/includes/bootstrap.php';
$showBottomNav = true;
?>
<!doctype html><html><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="/Apni_Tokri/assets/css/apni-tokri.css?v=1794645889">
</head>
<body class="at-body min-h-screen flex flex-col" style="background:#eee">
<main class="flex-1"></main>
<?php require __DIR__ . '/includes/layout/footer-public.php'; ?>
<pre id="probe"></pre>
<script>
window.addEventListener('load', () => {
  setTimeout(() => {
    const out = [];
    const w = (el) => el ? Math.round(el.getBoundingClientRect().width) : 'null';
    const sw = (el) => el ? Math.round(el.scrollWidth) : 'null';
    const cw = (el, p) => el ? getComputedStyle(el)[p] : 'null';
    const sel = (s) => document.querySelector(s);
    out.push('innerWidth=' + window.innerWidth);
    out.push('docScrollW=' + document.documentElement.scrollWidth);
    out.push('bodyScrollW=' + document.body.scrollWidth);
    const grid = sel('.at-footer__grid');
    out.push('gridW=' + w(grid) + ' gridScrollW=' + sw(grid));
    out.push('gridCols=' + cw(grid, 'gridTemplateColumns'));
    ['.at-footer__brand','.at-footer__about','.at-footer__policies','.at-footer__support'].forEach(s => {
      const el = sel(s);
      out.push(s + ' w=' + w(el) + ' scrollW=' + sw(el));
      const ul = el && el.querySelector('ul');
      if (ul) out.push('  ul w=' + w(ul) + ' scrollW=' + sw(ul) + ' cols=' + cw(ul,'gridTemplateColumns'));
      const li = ul && ul.querySelector('li');
      if (li) out.push('  li w=' + w(li) + ' scrollW=' + sw(li));
      const a = li && li.querySelector('a');
      if (a) out.push('  a w=' + w(a) + ' display=' + cw(a,'display') + ' ws=' + cw(a,'whiteSpace'));
    });
    // find elements wider than viewport
    let widest = [];
    document.querySelectorAll('.at-footer *').forEach(el => {
      const r = el.getBoundingClientRect();
      if (r.width > window.innerWidth - 20) widest.push(el.tagName+'.'+(el.className||'').toString().slice(0,40)+'='+Math.round(r.width));
    });
    out.push('OVERFLOWERS: ' + widest.slice(0,15).join(' | '));
    document.getElementById('probe').textContent = out.join('\n');
  }, 300);
});
</script>
</body></html>
