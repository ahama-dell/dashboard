// 포털 화면의 드래그 앤 드롭 및 리사이징 로직

function applyLayout() {
  const cg = document.getElementById('contentGrid');
  if (!cg || !state.layout) return;
  const boardW = cg.clientWidth;
  const boardH = cg.clientHeight;
  // clientHeight가 0이면 아직 레이아웃이 준비 안된 것 — 잠시 후 재시도
  if (boardW === 0 || boardH === 0) {
    requestAnimationFrame(applyLayout);
    return;
  }
  Object.keys(state.layout).forEach(id => {
    const el = document.getElementById(id);
    if (!el) return;
    const L = state.layout[id];
    el.style.left  = Math.round(L.x * boardW) + 'px';
    el.style.top   = Math.round(L.y * boardH) + 'px';
    el.style.width = Math.round(L.w * boardW) + 'px';
    el.style.height= Math.round(L.h * boardH) + 'px';
  });
}

let _dragJustEnded = false; // 드래그 직후 클릭 억제용 플래그

function attachInteractions() {
  document.querySelectorAll('.panel-header').forEach(h => {
    h.addEventListener('mousedown', e => {
      if (e.target.closest('button') || e.target.closest('input')) return;
      startDrag(e, h.dataset.panel);
    });
  });
  document.querySelectorAll('.rs-handle').forEach(h => {
    h.addEventListener('mousedown', e => startResize(e, h.dataset.panel, h.dataset.dir, h));
  });
  
  // <a> 태그인 블록(tempCardWrap 등)의 드래그 직후 클릭 이동 억제
  document.querySelectorAll('a.block-panel').forEach(el => {
    el.addEventListener('click', e => {
      if (_dragJustEnded) {
        e.preventDefault();
        _dragJustEnded = false;
      }
    });
  });
  
  // 리사이징 가이드 요소를 document에 추가
  if (!document.getElementById('snapGuideV')) {
    const vGuide = document.createElement('div');
    vGuide.id = 'snapGuideV'; vGuide.className = 'snap-guide';
    document.body.appendChild(vGuide);
  }
  if (!document.getElementById('snapGuideH')) {
    const hGuide = document.createElement('div');
    hGuide.id = 'snapGuideH'; hGuide.className = 'snap-guide';
    document.body.appendChild(hGuide);
  }
  
  // 브라우저 리사이즈 시 높이/너비 재적용
  window.addEventListener('resize', applyLayout);
}

const SNAP_THRESHOLD = 15;

function getSnapTargets(excludeId) {
  const cg = document.getElementById('contentGrid');
  const boardW = cg.clientWidth;
  const boardH = cg.clientHeight;
  const xs = [], ys = [];
  
  Object.keys(state.layout).forEach(id => {
    if (id === excludeId) return;
    const L = state.layout[id];
    if (!L) return;
    const pxX = L.x * boardW, pxY = L.y * boardH;
    const pxW = L.w * boardW, pxH = L.h * boardH;
    xs.push(pxX, pxX + pxW, pxX + pxW / 2);
    ys.push(pxY, pxY + pxH, pxY + pxH / 2);
  });
  xs.push(0, boardW, boardW/2);
  ys.push(0, boardH, boardH/2);
  return { xs, ys };
}

function nearestSnap(val, candidates) {
  let best = null, bestD = SNAP_THRESHOLD;
  for (const c of candidates) {
    const d = Math.abs(val - c);
    if (d < bestD) { bestD = d; best = c; }
  }
  return best;
}

function showVGuide(cg, boardX) {
  const g = document.getElementById('snapGuideV');
  const rect = cg.getBoundingClientRect();
  g.style.left = (rect.left + boardX) + 'px';
  g.style.top = rect.top + 'px';
  g.style.width = '1px';
  g.style.height = rect.height + 'px';
  g.classList.add('show');
}
function showHGuide(cg, boardY) {
  const g = document.getElementById('snapGuideH');
  const rect = cg.getBoundingClientRect();
  g.style.top = (rect.top + boardY) + 'px';
  g.style.left = rect.left + 'px';
  g.style.width = rect.width + 'px';
  g.style.height = '1px';
  g.classList.add('show');
}
function hideGuides() {
  document.querySelectorAll('.snap-guide').forEach(g => g.classList.remove('show'));
}

function startDrag(e, id) {
  e.preventDefault();
  const panel = document.getElementById(id);
  const cg = document.getElementById('contentGrid');
  const boardW = cg.clientWidth;
  const boardH = cg.clientHeight;
  
  const L0 = {
    x: state.layout[id].x * boardW,
    y: state.layout[id].y * boardH,
    w: state.layout[id].w * boardW,
    h: state.layout[id].h * boardH
  };
  
  const startX = e.clientX, startY = e.clientY;
  let hasMoved = false; // 실제 드래그 이동 여부
  panel.classList.add('dragging');
  
  function onMove(ev) {
    const dx = ev.clientX - startX, dy = ev.clientY - startY;
    // 5px 이상 움직이면 드래그로 판단
    if (!hasMoved && (Math.abs(dx) > 5 || Math.abs(dy) > 5)) hasMoved = true;
    
    let nx = L0.x + dx, ny = L0.y + dy;
    nx = Math.max(0, Math.min(boardW - L0.w, nx));
    ny = Math.max(0, Math.min(boardH - L0.h, ny));

    const T = getSnapTargets(id);
    const cx = nx + L0.w / 2, cy = ny + L0.h / 2;
    
    const sxL = nearestSnap(nx, T.xs);
    const sxR = nearestSnap(nx + L0.w, T.xs);
    const sxC = nearestSnap(cx, T.xs);
    let xGuide = null;
    if (sxL !== null && (sxR === null || Math.abs(sxL - nx) <= Math.abs(sxR - (nx + L0.w))) && (sxC === null || Math.abs(sxL - nx) <= Math.abs(sxC - cx))) { nx = sxL; xGuide = sxL; }
    else if (sxR !== null && (sxC === null || Math.abs(sxR - (nx + L0.w)) <= Math.abs(sxC - cx))) { nx = sxR - L0.w; xGuide = sxR; }
    else if (sxC !== null) { nx = sxC - L0.w/2; xGuide = sxC; }

    const syT = nearestSnap(ny, T.ys);
    const syB = nearestSnap(ny + L0.h, T.ys);
    const syC = nearestSnap(cy, T.ys);
    let yGuide = null;
    if (syT !== null && (syB === null || Math.abs(syT - ny) <= Math.abs(syB - (ny + L0.h))) && (syC === null || Math.abs(syT - ny) <= Math.abs(syC - cy))) { ny = syT; yGuide = syT; }
    else if (syB !== null && (syC === null || Math.abs(syB - (ny + L0.h)) <= Math.abs(syC - cy))) { ny = syB - L0.h; yGuide = syB; }
    else if (syC !== null) { ny = syC - L0.h/2; yGuide = syC; }

    if (xGuide !== null) showVGuide(cg, xGuide); else hideGuides.call(null) || (document.getElementById('snapGuideV')?.classList.remove('show'));
    if (yGuide !== null) showHGuide(cg, yGuide); else document.getElementById('snapGuideH')?.classList.remove('show');

    panel.style.left = nx + 'px';
    panel.style.top = ny + 'px';
  }
  
  function onUp(ev) {
    document.removeEventListener('mousemove', onMove);
    document.removeEventListener('mouseup', onUp);
    panel.classList.remove('dragging');
    hideGuides();
    
    if (hasMoved) {
      // 실제로 움직였을 때만 레이아웃 저장 + 클릭 억제
      _dragJustEnded = true;
      // setTimeout으로 click 이벤트가 으이지나면 플래그 돌려놓기
      setTimeout(() => { _dragJustEnded = false; }, 200);
      
      state.layout[id] = {
        x: (parseFloat(panel.style.left) || 0) / boardW,
        y: (parseFloat(panel.style.top) || 0) / boardH,
        w: L0.w / boardW,
        h: L0.h / boardH,
      };
      applyLayout();
      document.querySelector('.admin-save-btn').style.background = '#ffd700'; // 강조
    }
  }
  
  document.addEventListener('mousemove', onMove);
  document.addEventListener('mouseup', onUp);
}

function startResize(e, id, dir, handle) {
  e.preventDefault(); e.stopPropagation();
  const panel = document.getElementById(id);
  const cg = document.getElementById('contentGrid');
  const boardW = cg.clientWidth;
  const boardH = cg.clientHeight;
  
  const L0 = {
    x: state.layout[id].x * boardW,
    y: state.layout[id].y * boardH,
    w: state.layout[id].w * boardW,
    h: state.layout[id].h * boardH
  };
  
  const startX = e.clientX, startY = e.clientY;
  let hasMoved = false; // 실제 리사이징 여부
  panel.classList.add('resizing');
  handle.classList.add('active');

  function onMove(ev) {
    const dx = ev.clientX - startX, dy = ev.clientY - startY;
    // 3px 이상 움직이면 리사이징으로 판단
    if (!hasMoved && (Math.abs(dx) > 3 || Math.abs(dy) > 3)) hasMoved = true;

    let nx = L0.x, ny = L0.y, nw = L0.w, nh = L0.h;
    if (dir.includes('e')) nw = L0.w + dx;
    if (dir.includes('s')) nh = L0.h + dy;
    
    if (nw < 200) nw = 200;
    if (nh < 150) nh = 150;
    nx = Math.max(0, Math.min(boardW - nw, nx));
    ny = Math.max(0, Math.min(boardH - nh, ny));

    const T = getSnapTargets(id);
    let xGuide = null, yGuide = null;
    if (dir.includes('e')) {
      const s = nearestSnap(nx + nw, T.xs);
      if (s !== null && s > nx + 200) { nw = s - nx; xGuide = s; }
    }
    if (dir.includes('s')) {
      const s = nearestSnap(ny + nh, T.ys);
      if (s !== null && s > ny + 150) { nh = s - ny; yGuide = s; }
    }
    
    if (xGuide !== null) showVGuide(cg, xGuide); else document.getElementById('snapGuideV')?.classList.remove('show');
    if (yGuide !== null) showHGuide(cg, yGuide); else document.getElementById('snapGuideH')?.classList.remove('show');

    panel.style.left = nx + 'px'; panel.style.top = ny + 'px';
    panel.style.width = nw + 'px'; panel.style.height = nh + 'px';
  }
  
  function onUp() {
    document.removeEventListener('mousemove', onMove);
    document.removeEventListener('mouseup', onUp);
    panel.classList.remove('resizing');
    handle.classList.remove('active');
    hideGuides();
    
    if (hasMoved) {
      // 실제로 크기를 조절했을 때만 레이아웃 저장 + 클릭 억제
      _dragJustEnded = true;
      setTimeout(() => { _dragJustEnded = false; }, 200);
      
      state.layout[id] = {
        x: (parseFloat(panel.style.left) || 0) / boardW,
        y: (parseFloat(panel.style.top) || 0) / boardH,
        w: (parseFloat(panel.style.width) || 200) / boardW,
        h: (parseFloat(panel.style.height) || 150) / boardH,
      };
      applyLayout();
      document.querySelector('.admin-save-btn').style.background = '#ffd700'; // 강조
    }
  }
  document.addEventListener('mousemove', onMove);
  document.addEventListener('mouseup', onUp);
}
