/* Liquid glass tab bar. Needs the markup in demo.html and liquid-tabbar.css.
   Listen for the 'tabchange' event on the bar to know which tab was picked. */
(function () {
/* ---------- squircle ----------
   Used on every browser now. Chrome's native corner-shape draws a different
   curve to this one, which is why the laptop and the phone disagreed. */
function squirclePath(w, h, r, n = 5) {
  r = Math.min(r, w/2, h/2);
  const steps = 16, pts = [];
  const corners = [[w-r,h-r,1,1], [r,h-r,-1,1], [r,r,-1,-1], [w-r,r,1,-1]];
  for (const [cx, cy, sx, sy] of corners) {
    const forward = sx * sy > 0;                 // neighbouring corners sweep opposite ways
    for (let i = 0; i <= steps; i++) {
      const a = (forward ? i : steps - i) / steps * Math.PI / 2;
      pts.push((cx + sx*r*Math.abs(Math.cos(a))**(2/n)).toFixed(2) + ' ' +
               (cy + sy*r*Math.abs(Math.sin(a))**(2/n)).toFixed(2));
    }
  }
  return `path("M${pts.join('L')}Z")`;
}
const shaped = [...document.querySelectorAll('[data-sq]')];
const draw = el => {
  const b = el.getBoundingClientRect();
  if (b.width > 2 && b.height > 2) el.style.clipPath = squirclePath(b.width, b.height, +el.dataset.sq);
};
const ro = new ResizeObserver(es => es.forEach(e => draw(e.target)));
shaped.forEach(el => { draw(el); ro.observe(el); });
document.fonts?.ready.then(() => shaped.forEach(draw));

document.querySelectorAll('[data-press], [data-tap]').forEach(el => {
  const on = () => el.classList.add('pressed'), off = () => el.classList.remove('pressed');
  el.addEventListener('pointerdown', on);
  ['pointerup','pointercancel','pointerleave'].forEach(e => el.addEventListener(e, off));
});

/* ---------- lens ---------- */
const bar = document.getElementById('bar');
const tabsEl = document.getElementById('tabs');
const lens = document.getElementById('lens');
const tabs = [...document.querySelectorAll('.tab')];
const glass = document.querySelector('.bar__glass');
const sheen = document.querySelector('.bar__sheen');
const edgeSvg = document.getElementById('edge');
const edgePath = document.getElementById('edgePath');

  const stiffness = 160, damping = 21.5;
  const swellW = 1.31, swellH = 1.46, give = 5, zoom = 1.30;
let pos = 0, vel = 0, tgt = 0, index = 0, raf = null;
let grow = 0, growTo = 0;                 // 0 at rest, 1 fully swollen
const tabZoom = tabs.map(() => 0);        // one eased value per tab
let down = false, startX = 0, startPos = 0, moved = 0;

/* Signed distance to a horizontal capsule. Negative inside.
   This is what lets the bar deform against the lens's real silhouette
   instead of against its bounding box. */
function capsuleSD(px, py, cx, cy, w, h) {
  const r = h / 2, half = Math.max(0, w / 2 - r);
  const qx = Math.abs(px - cx) - half, qy = py - cy;
  return (qx < 0 ? Math.abs(qy) : Math.hypot(qx, qy)) - r;
}

/* The bar outline as points with outward normals, so every part of it,
   the round ends included, can be pushed in by the lens. */
function outline(w, h) {
  const r = h / 2, pts = [];
  const N = 26, C = 22;
  for (let i = 0; i <= N; i++) pts.push([r + (w - 2*r) * i/N, 0, 0, -1]);
  for (let i = 1; i < C; i++) {
    const a = -Math.PI/2 + Math.PI * i/C;
    pts.push([w - r + r*Math.cos(a), h/2 + r*Math.sin(a), Math.cos(a), Math.sin(a)]);
  }
  for (let i = 0; i <= N; i++) pts.push([w - r - (w - 2*r) * i/N, h, 0, 1]);
  for (let i = 1; i < C; i++) {
    const a = Math.PI/2 + Math.PI * i/C;
    pts.push([r + r*Math.cos(a), h/2 + r*Math.sin(a), Math.cos(a), Math.sin(a)]);
  }
  return pts;
}

function reshape(cx, cy, lw, lh) {
  // offsetWidth, not getBoundingClientRect. The bar scales while held, and a
  // rect would hand back the scaled size, which the clip path then applies
  // inside the already-scaled element. Layout sizes are scale free.
  const w = glass.offsetWidth, h = glass.offsetHeight;
  if (w < 4) return;

  const depth = give * grow, feather = 9;
  let d = '';
  for (const [x, y, nx, ny] of outline(w, h)) {
    let push = 0;
    if (depth > 0.2) {
      const sd = capsuleSD(x, y, cx, cy, lw, lh);
      const k = Math.min(1, Math.max(0, -sd / feather));
      push = depth * (k * k * (3 - 2 * k));
    }
    d += (d ? 'L' : 'M') + (x - nx*push).toFixed(2) + ',' + (y - ny*push).toFixed(2);
  }
  d += 'Z';

  glass.style.clipPath = sheen.style.clipPath = `path("${d}")`;
  edgeSvg.setAttribute('viewBox', `0 0 ${w} ${h}`);
  edgePath.setAttribute('d', d);
}

function paint() {
  const stripW = tabsEl.offsetWidth, stripH = tabsEl.offsetHeight;
  if (stripW < 4) return;
  const pad = (bar.offsetWidth - stripW) / 2;      // the bar's own padding
  const baseW = stripW / 4, baseH = stripH;

  // settle exactly onto the tab so a resting lens is never a hair too wide
  const g = grow < 0.005 ? 0 : grow;
  const lw = baseW * (1 + g * (swellW - 1));
  const lh = baseH * (1 + g * (swellH - 1));
  const x = (pos / 100) * baseW - (lw - baseW) / 2;

  lens.style.width  = lw + 'px';
  lens.style.height = lh + 'px';
  lens.style.top    = (baseH - lh) / 2 + 'px';
  lens.style.transform = `translateX(${x}px)`;

  // Each tab zooms by how close the lens is to it, not by which one is
  // selected. So it swells as the lens arrives and falls away as it leaves,
  // instead of snapping on at the moment the selection changes.
  const centre = pos / 100;
  tabs.forEach((tab, i) => {
    const near = Math.max(0, 1 - Math.abs(centre - i));
    const want = grow * near;
    tabZoom[i] += (want - tabZoom[i]) * 0.12;      // slower than the swell
    const z = 1 + tabZoom[i] * (zoom - 1);
    tab.style.transform = tabZoom[i] > 0.002 ? `scale(${z.toFixed(4)})` : '';
  });

  // lens centre in the glass layer's own coordinates
  reshape(pad + x + lw / 2, pad + baseH / 2, lw, lh);
}

function tick() {
  const dt = 1/60;
  vel += (-stiffness*(pos - tgt) - damping*vel) * dt;
  pos += vel * dt;

  // While the finger is down it stays fully swollen. Once released, the swell
  // tracks how fast the lens is travelling, so a tapped jump swells on the way
  // across and settles as it lands.
  if (!down) growTo = Math.min(1, Math.abs(vel) / 110);
  grow += (growTo - grow) * 0.2;

  const still = Math.abs(vel) < 0.35 && Math.abs(pos - tgt) < 0.35;
  const done  = Math.abs(grow - growTo) < 0.004 && tabZoom.every((z, i) =>
                  Math.abs(z - grow * Math.max(0, 1 - Math.abs(pos/100 - i))) < 0.004);
  if (still) { pos = tgt; vel = 0; }
  if (still && done) { grow = growTo; paint(); raf = null; return; }
  paint();
  raf = requestAnimationFrame(tick);
}
const spring = () => { if (!raf) raf = requestAnimationFrame(tick); };

function select(i) {
  index = i; tgt = i * 100;
  tabs.forEach(t => t.classList.toggle('on', +t.dataset.i === i));
  spring();
  if (navigator.vibrate) navigator.vibrate(7);   // android only, iOS has none
  bar.dispatchEvent(new CustomEvent('tabchange', { detail: { index: i } }));
}

tabsEl.addEventListener('pointerdown', e => {
  down = true; moved = 0; startX = e.clientX; startPos = pos; vel = 0;
  lens.classList.add('lit');
  bar.classList.add('held');
  growTo = 1; spring();
  tabsEl.setPointerCapture(e.pointerId);
});

tabsEl.addEventListener('pointermove', e => {
  if (!down) return;
  moved = Math.max(moved, Math.abs(e.clientX - startX));
  if (moved < 5) return;
  const step = tabsEl.getBoundingClientRect().width / 4;
  const next = Math.max(0, Math.min(300, startPos + (e.clientX - startX)/step*100));
  vel = (next - pos) * 40; pos = next; tgt = next; paint();
  const near = Math.round(pos/100);
  tabs.forEach(t => t.classList.toggle('on', +t.dataset.i === near));
});

function release(e) {
  if (!down) return;
  down = false;
  let i;
  if (moved < 5) {
    const hit = document.elementFromPoint(e.clientX, e.clientY)?.closest('.tab');
    i = hit ? +hit.dataset.i : index;
  } else i = Math.round(Math.max(0, Math.min(300, pos + vel*0.05)) / 100);
  bar.classList.remove('held');
  select(i);
  setTimeout(() => lens.classList.remove('lit'), 300);
}
tabsEl.addEventListener('pointerup', release);
tabsEl.addEventListener('pointercancel', release);


  paint();
  addEventListener('resize', paint);
})();
