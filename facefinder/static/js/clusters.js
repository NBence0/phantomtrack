// facefinder/static/js/editor.js
const S = {
  clusters: [],
  left:  { cid:-2,  page:1, total:0, totalPages:1, sort:'id_desc', minScore:0, filter:'', faces:[], selected:new Set(), lastShift:-1 },
  right: { cid:'',   page:1, total:0, totalPages:1, sort:'id_desc', minScore:0, filter:'', faces:[], selected:new Set(), lastShift:-1 },
  drag: null,
  history: [],
  ctxFace: null,
  ctxSide: null,
  filterTimers: { left: null, right: null }
};

document.addEventListener('DOMContentLoaded', () => {
  initClusters();
  setupKeyboard();
  setupContextMenuClose();
  setupDividerResize();
  setupFilterDebounce();
});

function initClusters() {
  fetch('api/gallery.php?action=get_clusters')
    .then(r => r.json())
    .then(res => {
      if (!res.success) { showToast('Hiba: ' + res.error, 'error'); return; }
      S.clusters = res.clusters;
      populateSelects();
      updateGlobalStat();
      document.getElementById('leftCluster').value = '-2';
      S.left.cid = -2;
      loadPanel('left');
    })
    .catch(() => showToast('Hálózati hiba a klaszterek betöltésekor.', 'error'));
}

function populateSelects() {
  const base = `
    <option value="">— Válassz klasztert —</option>
    <option value="-2">🌍 ÖSSZES ARC (lapozva)</option>
    <option value="-1">❓ Becsoportosítatlan (cluster_id = -1)</option>
  `;
  const opts = [...S.clusters]
    .sort((a,b) => a.cluster_id - b.cluster_id)
    .map(cl => {
      const label = cl.name ? `${cl.name}` : `Személy #${cl.cluster_id}`;
      const badge = `(${cl.count} arc)`;
      return `<option value="${cl.cluster_id}">${label} ${badge}</option>`;
    })
    .join('');
  const html = base + opts;
  const lSel = document.getElementById('leftCluster');
  const rSel = document.getElementById('rightCluster');
  const lv = lSel.value, rv = rSel.value;
  lSel.innerHTML = html; rSel.innerHTML = html;
  if (lv !== '') lSel.value = lv;
  if (rv !== '') rSel.value = rv;
}

function updateGlobalStat() {
  const total = S.clusters.reduce((s, c) => s + c.count, 0);
  document.getElementById('gStatClusters').textContent = S.clusters.length;
  document.getElementById('gStatFaces').textContent = total.toLocaleString();
}

function loadPanel(side, page) {
  const p = S[side];
  if (page !== undefined) p.page = page;
  if (p.cid === '' || p.cid === null) { renderEmpty(side); return; }

  const params = new URLSearchParams({
    action: 'get_person_faces',
    cluster_id: p.cid,
    page: p.page,
    sort: p.sort,
    min_score: p.minScore,
    filter: p.filter,
    limit: 200
  });

  setLoading(side, true);
  fetch('api/gallery.php?' + params)
    .then(r => r.json())
    .then(res => {
      setLoading(side, false);
      if (!res.success) { showToast('Hiba: ' + res.error, 'error'); return; }
      p.faces = res.faces;
      p.total = res.total_faces;
      p.totalPages = res.total_pages;
      p.selected.clear();
      p.lastShift = -1;
      renderPanel(side);
      renderPagination(side);
    })
    .catch(() => { setLoading(side, false); showToast('Hálózati hiba.', 'error'); });
}

function setLoading(side, on) {
  const el = document.getElementById(side + 'Loading');
  if (on) el.classList.add('visible'); else el.classList.remove('visible');
}

function onClusterChange(side) {
  const raw = document.getElementById(side + 'Cluster').value;
  S[side].cid = raw === '' ? '' : parseInt(raw);
  S[side].page = 1;
  S[side].filter = '';
  document.getElementById(side + 'Filter').value = '';
  S[side].selected.clear();
  updateSelCount(side);
  loadPanel(side);
}

function onSortChange(side) {
  S[side].sort = document.getElementById(side + 'Sort').value;
  S[side].page = 1;
  loadPanel(side);
}

function onMinScoreChange(side, val) {
  const f = parseFloat(val).toFixed(2);
  document.getElementById(side + 'MinScoreVal').textContent = f;
  S[side].minScore = parseFloat(f);
  S[side].page = 1;
  loadPanel(side);
}

function setupFilterDebounce() {
  ['left','right'].forEach(side => {
    document.getElementById(side + 'Filter').addEventListener('input', e => {
      clearTimeout(S.filterTimers[side]);
      S.filterTimers[side] = setTimeout(() => {
        S[side].filter = e.target.value.trim();
        S[side].page = 1;
        loadPanel(side);
      }, 320);
    });
  });
}

function renderPanel(side) {
  const p = S[side];
  const grid = document.getElementById(side + 'Grid');
  grid.innerHTML = '';
  document.getElementById(side + 'Total').textContent = p.total.toLocaleString();
  updateSelCount(side);

  if (!p.faces.length) {
    grid.innerHTML = `<div class="empty-state" style="grid-column:1/-1">
      <div class="es-icon">🫥</div><p>Nincsenek arcok ebben a nézetben.</p>
    </div>`;
    return;
  }

  p.faces.forEach((f, idx) => {
    const div = document.createElement('div');
    div.className = 'face-card';
    div.id = `fc_${side}_${f.face_id}`;
    div.draggable = true;
    if (p.selected.has(f.face_id)) div.classList.add('selected');

    const sc = parseFloat(f.score);
    let sqCls = 'sq-none', scTxt = '---';
    if (!isNaN(sc)) {
      scTxt = sc.toFixed(3);
      sqCls = sc >= 0.70 ? 'sq-good' : sc >= 0.35 ? 'sq-ok' : 'sq-bad';
    }

    const thumb = f.thumb || '';
    div.innerHTML = `
      <input type="checkbox" class="fc-check" id="ck_${side}_${f.face_id}"
        data-id="${f.face_id}" data-idx="${idx}" ${p.selected.has(f.face_id)?'checked':''}>
      <img src="${thumb}" loading="lazy" alt="face"
        onmouseenter="showHover(this,'${thumb}')" onmouseleave="hideHover()">
      <div class="score-badge ${sqCls}">Q: ${scTxt}</div>`;

    div.addEventListener('click', e => onCardClick(e, side, f, idx));
    div.addEventListener('contextmenu', e => onCardCtx(e, side, f));
    div.addEventListener('dragstart', e => onDragStart(e, side, f.face_id));
    grid.appendChild(div);
  });
}

function renderEmpty(side) {
  document.getElementById(side + 'Grid').innerHTML =
    `<div class="empty-state" style="grid-column:1/-1"><div class="es-icon">👈</div><p>Válassz klasztert.</p></div>`;
  document.getElementById(side + 'Total').textContent = '0';
  document.getElementById(side + 'SelCount').textContent = '0';
  document.getElementById(side + 'Pagination').innerHTML = '';
}

function renderPagination(side) {
  const p = S[side];
  const el = document.getElementById(side + 'Pagination');
  if (p.totalPages <= 1) { el.innerHTML = ''; return; }
  const cur = p.page, tot = p.totalPages;

  let html = '';
  html += `<button class="page-btn page-nav" ${cur===1?'disabled':''} onclick="loadPanel('${side}',1)">«</button>`;
  html += `<button class="page-btn page-nav" ${cur===1?'disabled':''} onclick="loadPanel('${side}',${cur-1})">‹</button>`;

  let start = Math.max(1, cur-2), end = Math.min(tot, cur+2);
  if (start > 1) html += `<span class="page-info">1</span><span class="page-info">…</span>`;
  for (let i = start; i <= end; i++) {
    html += `<button class="page-btn${i===cur?' active':''}" onclick="loadPanel('${side}',${i})">${i}</button>`;
  }
  if (end < tot) html += `<span class="page-info">…</span><span class="page-info">${tot}</span>`;

  html += `<button class="page-btn page-nav" ${cur===tot?'disabled':''} onclick="loadPanel('${side}',${cur+1})">›</button>`;
  html += `<button class="page-btn page-nav" ${cur===tot?'disabled':''} onclick="loadPanel('${side}',${tot})">»</button>`;
  el.innerHTML = html;

  const lbl = document.getElementById(side + 'PageLabel');
  if (lbl) {
    lbl.style.display = 'inline';
    lbl.innerHTML = `<span style="opacity:0.5;margin:0 4px">|</span>Lap: <span style="color:var(--accent);font-weight:bold">${cur}/${tot}</span>`;
  }
}

function onCardClick(e, side, f, idx) {
  const p = S[side];
  const chk = document.getElementById(`ck_${side}_${f.face_id}`);
  if (e.target !== chk) chk.checked = !chk.checked;
  const checked = chk.checked;

  if (e.shiftKey && p.lastShift !== -1) {
    const lo = Math.min(p.lastShift, idx), hi = Math.max(p.lastShift, idx);
    for (let i = lo; i <= hi; i++) {
      const fi = p.faces[i]; if (!fi) continue;
      const c2 = document.getElementById(`ck_${side}_${fi.face_id}`);
      if (c2) { c2.checked = checked; setSelected(side, fi.face_id, checked); }
    }
  } else {
    p.lastShift = idx;
    setSelected(side, f.face_id, checked);
  }
  updateSelCount(side);
}

function setSelected(side, faceId, on) {
  if (on) S[side].selected.add(faceId); else S[side].selected.delete(faceId);
  const el = document.getElementById(`fc_${side}_${faceId}`);
  if (el) { if (on) el.classList.add('selected'); else el.classList.remove('selected'); }
}

function selectAll(side) {
  S[side].faces.forEach(f => setSelected(side, f.face_id, true));
  document.querySelectorAll(`#${side}Grid .fc-check`).forEach(c => c.checked = true);
  updateSelCount(side);
}

function deselectAll(side) {
  S[side].selected.clear();
  document.querySelectorAll(`#${side}Grid .fc-check`).forEach(c => c.checked = false);
  document.querySelectorAll(`#${side}Grid .face-card`).forEach(el => el.classList.remove('selected'));
  updateSelCount(side);
}

function selectWorst(side, n) {
  deselectAll(side);
  const sorted = [...S[side].faces]
    .filter(f => f.score !== null && f.score !== undefined)
    .sort((a,b) => parseFloat(a.score) - parseFloat(b.score))
    .slice(0, parseInt(n));
  sorted.forEach(f => setSelected(side, f.face_id, true));
  const ck = sorted.map(f => document.getElementById(`ck_${side}_${f.face_id}`));
  ck.forEach(c => { if (c) c.checked = true; });
  updateSelCount(side);
}

function selectWorstN(side) {
  const n = parseInt(document.getElementById(side + 'WorstN').value) || 10;
  selectWorst(side, n);
}

function invertSelection(side) {
  S[side].faces.forEach(f => {
    const wasSelected = S[side].selected.has(f.face_id);
    setSelected(side, f.face_id, !wasSelected);
    const c = document.getElementById(`ck_${side}_${f.face_id}`);
    if (c) c.checked = !wasSelected;
  });
  updateSelCount(side);
  showToast(`Invertálva: ${S[side].selected.size} arc kijelölve.`, 'info');
}

function selectBelowScore(side) {
  const currentThresh = S[side].minScore;
  const thresh = currentThresh > 0 ? currentThresh : 0.5;
  deselectAll(side);
  let cnt = 0;
  S[side].faces.forEach(f => {
    const sc = parseFloat(f.score);
    if (!isNaN(sc) && sc < thresh) {
      setSelected(side, f.face_id, true);
      const c = document.getElementById(`ck_${side}_${f.face_id}`);
      if (c) c.checked = true;
      cnt++;
    } else if (isNaN(sc)) {
      setSelected(side, f.face_id, true);
      const c = document.getElementById(`ck_${side}_${f.face_id}`);
      if (c) c.checked = true;
      cnt++;
    }
  });
  updateSelCount(side);
  showToast(`Score < ${thresh.toFixed(2)} → ${cnt} arc kijelölve.`, 'warn');
}

function updateSelCount(side) {
  document.getElementById(side + 'SelCount').textContent = S[side].selected.size;
}

function getSelectedIds(side) { return [...S[side].selected]; }

function hideSelected(side) {
  const ids = getSelectedIds(side);
  if(!ids.length) return;
  ids.forEach(id => {
    const el = document.getElementById(`fc_${side}_${id}`);
    if (el) el.style.display = 'none';
  });
  deselectAll(side);
  showToast(`${ids.length} arc elrejtve a jelenlegi nézetből.`, 'info');
}

function copySelectedIds(side) {
  const ids = getSelectedIds(side);
  if(!ids.length) return;
  navigator.clipboard.writeText(ids.join(','))
    .then(() => showToast(`${ids.length} ID másolva a vágólapra.`, 'success'))
    .catch(() => showToast('Másolás sikertelen.', 'error'));
}

function editCluster(side) {
  const cid = S[side].cid;
  if (cid === '' || cid === -2 || cid === -1) {
    showToast('Csak konkrét klasztert lehet szerkeszteni!', 'warn');
    return;
  }
  const cl = S.clusters.find(c => c.cluster_id === cid);
  const currentName = cl ? (cl.name || '') : '';
  const currentNotes = cl ? (cl.notes || '') : '';
  const newName = prompt(`Klaszter #${cid} új neve:`, currentName);
  if (newName === null) return;
  const newNotes = prompt(`Klaszter #${cid} új jegyzet (opcionális):`, currentNotes);
  if (newNotes === null) return;

  fetch('api/gallery.php?action=rename_person', {
    method: 'POST', headers: {'Content-Type':'application/json'},
    body: JSON.stringify({ cluster_id: cid, name: newName, notes: newNotes })
  }).then(r => r.json()).then(res => {
    if (res.success) {
      showToast(`Klaszter #${cid} módosítva.`, 'success');
      refreshClusters();
    } else showToast('Hiba: ' + res.error, 'error');
  });
}

function onDragStart(e, side, clickedId) {
  let ids = getSelectedIds(side);
  if (!ids.includes(clickedId)) {
    deselectAll(side);
    setSelected(side, clickedId, true);
    const c = document.getElementById(`ck_${side}_${clickedId}`);
    if (c) c.checked = true;
    updateSelCount(side);
    ids = [clickedId];
  }
  S.drag = { side, ids };
  e.dataTransfer.effectAllowed = 'move';

  const ghost = document.createElement('div');
  ghost.style.cssText = 'position:fixed;top:-200px;left:0;background:var(--accent);color:#000;padding:5px 14px;border-radius:20px;font-weight:700;font-size:0.85rem;font-family:Inter,sans-serif;';
  ghost.textContent = `${ids.length} arc mozgatása...`;
  document.body.appendChild(ghost);
  e.dataTransfer.setDragImage(ghost, 0, 0);
  setTimeout(() => ghost.remove(), 100);

  document.getElementById(`fc_${side}_${clickedId}`)?.classList.add('dragging-source');
}

function allowDrop(e) { e.preventDefault(); e.dataTransfer.dropEffect = 'move'; }

function dragEnter(e, side) {
  e.preventDefault();
  document.getElementById('panel' + side.charAt(0).toUpperCase() + side.slice(1)).classList.add('drag-over');
}

function dragLeave(e, side) {
  if (!e.currentTarget.contains(e.relatedTarget)) {
    document.getElementById('panel' + side.charAt(0).toUpperCase() + side.slice(1)).classList.remove('drag-over');
  }
}

function dropHandler(e, targetSide) {
  e.preventDefault();
  ['panelLeft','panelRight'].forEach(id => document.getElementById(id)?.classList.remove('drag-over'));
  if (!S.drag || S.drag.side === targetSide) { S.drag = null; return; }

  const tCid = S[targetSide].cid;
  if (tCid === '' || tCid === null) { showToast('Válassz konkrét cél klasztert a fogadó panelen!', 'warn'); S.drag = null; return; }
  if (tCid === -2) { showToast('Az "Összes Arc" nézetbe nem mozgathatsz!', 'warn'); S.drag = null; return; }

  const { side: src, ids } = S.drag;
  S.drag = null;
  executeMove(ids, S[src].cid, tCid, src, targetSide);
}

function quickMove(srcSide) {
  const ids = getSelectedIds(srcSide);
  if (!ids.length) { showToast('Nincs kijelölt arc az átküldéshez.', 'warn'); return; }
  const targetSide = srcSide === 'left' ? 'right' : 'left';
  const tCid = S[targetSide].cid;
  if (tCid === '' || tCid === null) { showToast(`Válassz konkrét klasztert a(z) ${targetSide === 'left' ? 'bal' : 'jobb'} panelen az átküldéshez!`, 'warn'); return; }
  if (tCid === -2) { showToast('Az "Összes Arc" nézetbe nem mozgathatsz!', 'warn'); return; }
  
  executeMove(ids, S[srcSide].cid, tCid, srcSide, targetSide);
}

function executeMove(faceIds, fromCid, toCid, srcSide, dstSide) {
  fetch('api/editor_api.php?action=move_faces', {
    method: 'POST', headers: {'Content-Type':'application/json'},
    body: JSON.stringify({ face_ids: faceIds, target_cluster: toCid })
  }).then(r => r.json()).then(res => {
    if (!res.success) { showToast('Hiba mozgatáskor: ' + res.error, 'error'); return; }
    S.history.push({ faceIds, fromCid, toCid });
    updateUndoBtn();
    showToast(`${faceIds.length} arc áthelyezve → Klaszter #${toCid}`, 'success');
    if (srcSide) loadPanel(srcSide);
    if (dstSide) loadPanel(dstSide);
    refreshClusters();
  }).catch(() => showToast('Hálózati hiba mozgatáskor.', 'error'));
}

function deleteSelected(side) {
  const ids = getSelectedIds(side);
  if (!ids.length) { showToast('Nincs kijelölt arc.', 'warn'); return; }
  showConfirm(
    `Törlés megerősítése`,
    `Biztosan <strong>véglegesen törlöd</strong> a kijelölt <strong>${ids.length} arcot</strong> az adatbázisból?<br><br>Ez a művelet <u>nem vonható vissza!</u>`,
    'Törlés',
    'btn-danger'
  ).then(ok => {
    if (!ok) return;
    fetch('api/editor_api.php?action=delete_faces', {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ face_ids: ids })
    }).then(r => r.json()).then(res => {
      if (!res.success) { showToast('Hiba törléskor: ' + res.error, 'error'); return; }
      showToast(`${ids.length} arc törölve.`, 'success');
      loadPanel(side);
      refreshClusters();
    }).catch(() => showToast('Hálózati hiba törléskor.', 'error'));
  });
}

function mergeLeftToRight() {
  const lCid = S.left.cid, rCid = S.right.cid;
  if (lCid === '' || lCid === -2 || lCid === -1) { showToast('Bal oldalon válassz konkrét klasztert!', 'warn'); return; }
  if (rCid === '' || rCid === -2 || rCid === -1) { showToast('Jobb oldalon válassz konkrét klasztert!', 'warn'); return; }
  if (lCid === rCid) { showToast('A két panel azonos klasztert mutat!', 'warn'); return; }
  if (!S.left.faces.length) { showToast('A bal klaszter üres.', 'warn'); return; }

  showConfirm(
    'Merge megerősítése',
    `Biztosan áthelyezed a <strong>bal klaszter összes arcát</strong> (#${lCid}) a jobb klaszterbe (#${rCid})?<br><br><em>Csak az aktuális lap arcai kerülnek át. Több lapnál lapozás után ismételj meg!</em>`,
    'Merge', 'btn-success'
  ).then(ok => {
    if (!ok) return;
    const ids = S.left.faces.map(f => f.face_id);
    executeMove(ids, lCid, rCid, 'left', 'right');
  });
}

function undoLastMove() {
  if (!S.history.length) return;
  const last = S.history.pop();
  updateUndoBtn();
  fetch('api/editor_api.php?action=move_faces', {
    method: 'POST', headers: {'Content-Type':'application/json'},
    body: JSON.stringify({ face_ids: last.faceIds, target_cluster: last.fromCid })
  }).then(r => r.json()).then(res => {
    if (!res.success) { showToast('Undo hiba: ' + res.error, 'error'); return; }
    showToast(`Visszavonva: ${last.faceIds.length} arc visszakerült #${last.fromCid}-be.`, 'info');
    loadPanel('left'); loadPanel('right');
    refreshClusters();
  });
}

function updateUndoBtn() {
  const btn = document.getElementById('btnUndo');
  const cnt = document.getElementById('undoCount');
  btn.disabled = !S.history.length;
  cnt.textContent = `(${S.history.length})`;
}

function refreshClusters() {
  fetch('api/gallery.php?action=get_clusters').then(r => r.json()).then(res => {
    if (res.success) { S.clusters = res.clusters; populateSelects(); updateGlobalStat(); }
  });
}

function onCardCtx(e, side, f) {
  e.preventDefault();
  S.ctxFace = f; S.ctxSide = side;
  const menu = document.getElementById('contextMenu');
  menu.style.display = 'block';
  const x = Math.min(e.pageX, window.innerWidth  - 200);
  const y = Math.min(e.pageY, window.innerHeight - 120);
  menu.style.left = x + 'px'; menu.style.top = y + 'px';
}

function setupContextMenuClose() {
  document.addEventListener('click', e => {
    if (!e.target.closest('#contextMenu'))
      document.getElementById('contextMenu').style.display = 'none';
  });
}

function ctxAction(action) {
  document.getElementById('contextMenu').style.display = 'none';
  const f = S.ctxFace, side = S.ctxSide;
  if (!f) return;

  if (action === 'view') {
    document.getElementById('fmTitle').textContent = `Face ID: ${f.face_id}`;
    // Használjuk a biztonságos data.php proxyt ha van token, egyébként fallback a közvetlen útvonalra
    const imgUrl = (typeof DATA_BASE_URL !== 'undefined' && f.img_token)
        ? DATA_BASE_URL + f.img_token + '&type=raw'
        : '../uploads/' + f.file_path;
    const img = document.getElementById('fmImg');
    img.src = imgUrl;
    
    document.getElementById('fmDownloadSrc').href = imgUrl;
    document.getElementById('fmDownloadSrc').download = f.file_path || 'image';
    document.getElementById('fmDownloadThumb').href = f.thumb || '#';
    document.getElementById('fmDownloadThumb').download = 'face_' + f.face_id + '.webp';
    
    const draw = document.getElementById('fmBboxDraw');
    draw.style.display = 'none';
    img.onload = () => {
      if (f.bbox && f.bbox.length === 4) {
        const natW = img.naturalWidth;
        const natH = img.naturalHeight;
        const rect = img.getBoundingClientRect();
        if (natW > 0 && natH > 0) {
          const scale = Math.min(rect.width / natW, rect.height / natH);
          const drawW = natW * scale;
          const drawH = natH * scale;
          const offsetX = (rect.width - drawW) / 2;
          const offsetY = (rect.height - drawH) / 2;

          const [x1, y1, x2, y2] = f.bbox;
          draw.style.left = (offsetX + x1 * scale) + 'px';
          draw.style.top = (offsetY + y1 * scale) + 'px';
          draw.style.width = ((x2 - x1) * scale) + 'px';
          draw.style.height = ((y2 - y1) * scale) + 'px';
          draw.style.display = 'block';
        }
      }
    };

    document.getElementById('fmId').textContent = f.face_id;
    document.getElementById('fmFile').textContent = f.file_path;
    const sc = parseFloat(f.score);
    document.getElementById('fmScore').textContent = isNaN(sc) ? '—' : sc.toFixed(4);
    document.getElementById('fmDemo').textContent =
      `Kor: ${f.age ?? '—'}, Nem: ${f.gender ?? '—'}`;
    document.getElementById('fmPose').textContent = f.pose
      ? `P:${(+f.pose.pitch).toFixed(1)}° Y:${(+f.pose.yaw).toFixed(1)}° R:${(+f.pose.roll).toFixed(1)}°`
      : '—';
    document.getElementById('fmBbox').textContent = f.bbox?.length
      ? f.bbox.map(v => v.toFixed(1)).join(', ') : '—';
    document.getElementById('faceModal').classList.add('open');
  }

  if (action === 'copy') {
    navigator.clipboard.writeText(f.file_path)
      .then(() => showToast('Vágólapra másolva: ' + f.file_path, 'info'))
      .catch(() => showToast('Másolás sikertelen.', 'error'));
  }

  if (action === 'delete') {
    showConfirm('Törlés', `Törlöd a Face ID <strong>${f.face_id}</strong> arcot?`, 'Törlés', 'btn-danger')
      .then(ok => {
        if (!ok) return;
        fetch('api/editor_api.php?action=delete_faces', {
          method:'POST', headers:{'Content-Type':'application/json'},
          body: JSON.stringify({ face_ids: [f.face_id] })
        }).then(r => r.json()).then(res => {
          if (!res.success) { showToast('Hiba: ' + res.error, 'error'); return; }
          showToast('Arc törölve.', 'success');
          loadPanel(side); refreshClusters();
        });
      });
  }
}

function closeFaceModal() { document.getElementById('faceModal').classList.remove('open'); }

function showConfirm(title, msg, okLabel, okClass) {
  return new Promise(resolve => {
    document.getElementById('confirmTitle').textContent = title;
    document.getElementById('confirmMsg').innerHTML = msg;
    const okBtn = document.getElementById('confirmOk');
    okBtn.textContent = okLabel || 'Igen';
    okBtn.className = 'btn ' + (okClass || 'btn-danger');
    document.getElementById('confirmModal').classList.add('open');

    const cleanup = (val) => {
      document.getElementById('confirmModal').classList.remove('open');
      okBtn.replaceWith(okBtn.cloneNode(true));
      document.getElementById('confirmCancel').replaceWith(
        document.getElementById('confirmCancel').cloneNode(true));
      resolve(val);
    };

    document.getElementById('confirmOk').addEventListener('click', () => cleanup(true),  { once: true });
    document.getElementById('confirmCancel').addEventListener('click', () => cleanup(false), { once: true });
    document.getElementById('confirmModal').addEventListener('click', e => {
      if (e.target === document.getElementById('confirmModal')) cleanup(false);
    }, { once: true });
  });
}

function showHover(el, src) {
  if (!src) return;
  const hz = document.getElementById('hoverZoom');
  document.getElementById('hoverImg').src = src;
  hz.style.display = 'block';
  const r = el.getBoundingClientRect();
  let left = r.right + 16, top = r.top - 20;
  if (left + 270 > window.innerWidth)  left = r.left - 276;
  if (top  + 270 > window.innerHeight) top  = window.innerHeight - 276;
  if (top < 8) top = 8;
  hz.style.left = left + 'px'; hz.style.top = top + 'px';
}

function hideHover() { document.getElementById('hoverZoom').style.display = 'none'; }

function showToast(msg, type) {
  const icons = { success:'✅', error:'❌', warn:'⚠️', info:'ℹ️' };
  const el = document.createElement('div');
  el.className = `toast t-${type || 'info'}`;
  el.innerHTML = `<span>${icons[type]||'ℹ️'}</span><span>${msg}</span>`;
  document.getElementById('toastContainer').appendChild(el);
  setTimeout(() => {
    el.classList.add('t-out');
    setTimeout(() => el.remove(), 220);
  }, 3200);
}

function setupKeyboard() {
  document.addEventListener('keydown', e => {
    if (['INPUT','TEXTAREA','SELECT'].includes(e.target.tagName)) return;
    if (e.ctrlKey && e.key.toLowerCase() === 'a') {
      e.preventDefault(); selectAll('left'); selectAll('right');
    }
    if (e.key === 'Escape') {
      deselectAll('left'); deselectAll('right');
      closeFaceModal();
      document.getElementById('contextMenu').style.display = 'none';
    }
    if (e.ctrlKey && e.key.toLowerCase() === 'z') {
      e.preventDefault(); undoLastMove();
    }
    if (e.key === 'Delete') {
      const idsL = getSelectedIds('left'), idsR = getSelectedIds('right');
      if (idsL.length) deleteSelected('left');
      else if (idsR.length) deleteSelected('right');
    }
  });
}

function setupDividerResize() {
  const divider = document.getElementById('panelDivider');
  const layout  = document.getElementById('appLayout');
  let dragging = false, startX = 0, startCols = [];

  divider.addEventListener('mousedown', e => {
    dragging = true;
    startX = e.clientX;
    const cs = getComputedStyle(layout);
    startCols = cs.gridTemplateColumns.split(' ').map(v => parseFloat(v));
    divider.classList.add('dragging');
    document.body.style.cursor = 'col-resize';
    document.body.style.userSelect = 'none';
  });

  document.addEventListener('mousemove', e => {
    if (!dragging) return;
    const dx = e.clientX - startX;
    const totalW = layout.offsetWidth - 5;
    let leftW = Math.max(200, Math.min(totalW - 200, startCols[0] + dx));
    let rightW = totalW - leftW;
    layout.style.gridTemplateColumns = `${leftW}px 5px ${rightW}px`;
  });

  document.addEventListener('mouseup', () => {
    if (!dragging) return;
    dragging = false;
    divider.classList.remove('dragging');
    document.body.style.cursor = '';
    document.body.style.userSelect = '';
  });
}
