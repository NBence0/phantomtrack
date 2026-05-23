/* facefinder/static/js/faces.js */
let allClusters = [];
let currentCluster = null;
let lboxIndex = 0;
let lboxAnimId = null;

function loadClusters() {
  fetch('api/gallery.php?action=get_clusters')
    .then(r => r.json())
    .then(res => {
      if (!res.success) { showEmpty('API hiba: ' + res.error); return; }
      allClusters = res.clusters || [];
      renderGrid(allClusters);
    })
    .catch(() => showEmpty('Hálózati hiba.'));
}

function renderGrid(clusters) {
  const grid = document.getElementById('mainGrid');
  document.getElementById('personCount').textContent = clusters.length + ' személy';

  if (clusters.length === 0) {
    showEmpty('Még nincsenek csoportosított arcok. Kattints az Újracsoportosítás gombra!');
    return;
  }

  grid.innerHTML = '';
  clusters.forEach((cl, i) => {
    const originalIdx = allClusters.indexOf(cl);
    const card = document.createElement('div');
    card.className = 'card';
    card.dataset.idx = originalIdx;
    card.dataset.name = (cl.name || '').toLowerCase();

    const rank = document.createElement('div');
    rank.className = 'card-rank';
    rank.textContent = '#' + (i+1);
    card.appendChild(rank);

    const img = document.createElement('img');
    img.className = 'thumb-circle';
    img.src = 'api/gallery.php?action=get_thumb&face_id=' + cl.cover_face_id;
    img.loading = 'lazy';
    card.appendChild(img);

    const name = document.createElement('div');
    name.className = 'card-name';
    name.textContent = cl.name || ('Személy #' + (i+1));
    card.appendChild(name);

    const cnt = document.createElement('div');
    cnt.className = 'card-count';
    cnt.textContent = cl.count + ' fotón találtam arcot';
    card.appendChild(cnt);

    card.onclick = () => openModal(originalIdx);
    grid.appendChild(card);
  });
}

function showEmpty(msg) {
  document.getElementById('mainGrid').innerHTML =
    '<div class="empty"><h2>🤔</h2><p>' + msg + '</p></div>';
  document.getElementById('personCount').textContent = '0 személy';
}

function filterCards() {
  const q = document.getElementById('searchInput').value.toLowerCase();
  const filtered = allClusters.filter(cl =>
    !q || (cl.name && cl.name.toLowerCase().includes(q))
  );
  renderGrid(filtered);
}

function openModal(idx) {
  currentCluster = allClusters[idx];
  const cl = currentCluster;

  document.getElementById('modalName').textContent = cl.name || ('Személy #' + (idx+1));
  document.getElementById('modalCount').textContent = 'Betöltés...';
  document.getElementById('nameInput').value = cl.name || '';
  document.getElementById('notesInput').value = cl.notes || '';
  document.getElementById('nameEditForm').classList.remove('visible');

  const av = document.getElementById('modalAvatar');
  av.src = 'api/gallery.php?action=get_thumb&face_id=' + cl.cover_face_id;

  const grid = document.getElementById('modalPhotos');
  grid.innerHTML = '<div class="loader"><div class="spinner"></div> Fotók betöltése...</div>';
  document.getElementById('modal').style.display = 'block';

  fetch('api/gallery.php?action=get_person_faces&cluster_id=' + cl.cluster_id)
    .then(r => r.json())
    .then(res => {
      if(res.success) {
        renderModalPhotos(res.faces);
      } else {
        grid.innerHTML = '<div class="empty">Hiba a képek betöltésekor: ' + res.error + '</div>';
      }
    }).catch(() => grid.innerHTML = '<div class="empty">Hálózati hiba.</div>');
}

function renderModalPhotos(faces) {
  const grid = document.getElementById('modalPhotos');
  grid.innerHTML = '';
  
  const fileCounts = {};
  faces.forEach(f => {
    fileCounts[f.file_path] = (fileCounts[f.file_path] || 0) + 1;
  });

  const uniqueFiles = [...new Map(faces.map(f => [f.file_path, f])).values()];
  
  document.getElementById('modalCount').textContent = uniqueFiles.length + ' egyedi fotón (' + faces.length + ' arc találat)';

  uniqueFiles.forEach((face, fi) => {
    const div = document.createElement('div');
    div.className = 'photo-thumb';
    const img = document.createElement('img');
    img.src = face.thumb || ('../thumbs/' + face.file_path);
    img.loading = 'lazy';
    div.appendChild(img);

    if (fileCounts[face.file_path] > 1) {
        const dupBadge = document.createElement('div');
        dupBadge.className = 'dup-badge';
        dupBadge.textContent = '⚠️ DUPLIKÁLT (' + fileCounts[face.file_path] + 'x)';
        div.appendChild(dupBadge);
    }

    div.onclick = () => openLightbox(uniqueFiles, fi);
    grid.appendChild(div);
  });
}

function closeModal() { document.getElementById('modal').style.display = 'none'; }

function toggleNameEdit() {
  document.getElementById('nameEditForm').classList.toggle('visible');
}

function saveName() {
  const cl = currentCluster;
  const name = document.getElementById('nameInput').value.trim();
  const notes = document.getElementById('notesInput').value.trim();
  if (!name) return;
  fetch('api/gallery.php?action=rename_person', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify({cluster_id: cl.cluster_id, name, notes})
  }).then(r => r.json()).then(res => {
    if (res.success) {
      cl.name = name; cl.notes = notes;
      document.getElementById('modalName').textContent = name;
      document.getElementById('nameEditForm').classList.remove('visible');
      renderGrid(allClusters);
    }
  });
}

let lboxFiles = [];

function openLightbox(files, startIdx) {
  lboxFiles = files; lboxIndex = startIdx;
  document.getElementById('lightbox').style.display = 'block';
  drawLightboxFrame();
}

function drawLightboxFrame() {
  if (lboxAnimId) cancelAnimationFrame(lboxAnimId);
  
  const face = lboxFiles[lboxIndex];
  
  if (lboxFiles.length > 1) {
    const nextIdx = (lboxIndex + 1) % lboxFiles.length;
    const prevIdx = (lboxIndex - 1 + lboxFiles.length) % lboxFiles.length;
    if (nextIdx !== lboxIndex) new Image().src = '../images/' + encodeURIComponent(lboxFiles[nextIdx].file_path);
    if (prevIdx !== lboxIndex && prevIdx !== nextIdx) new Image().src = '../images/' + encodeURIComponent(lboxFiles[prevIdx].file_path);
  }

  const imgEl = document.getElementById('lightbox-img');
  const cv = document.getElementById('lightbox-canvas');
  const ctx = cv.getContext('2d');
  
  ctx.clearRect(0, 0, cv.width, cv.height);
  imgEl.src = '';
  
  const img = new Image();
  img.src = '../images/' + encodeURIComponent(face.file_path);
  img.onload = () => {
    imgEl.src = img.src;
    cv.width = img.width; 
    cv.height = img.height;
    
    const bbox = face.bbox && face.bbox.length >= 4 ? face.bbox : null;
    let alpha = 0, growing = true;
    
    function drawFrame() {
      ctx.clearRect(0, 0, cv.width, cv.height);
      if (bbox) {
        const [x1,y1,x2,y2] = bbox;
        const pad = Math.max((x2-x1),(y2-y1)) * 0.15;
        ctx.strokeStyle = `rgba(0,229,255,${0.6 + alpha * 0.4})`;
        ctx.lineWidth = Math.max(6, (x2-x1)*0.025);
        ctx.shadowColor = '#00e5ff';
        ctx.shadowBlur = 20 * alpha;
        ctx.beginPath();
        ctx.rect(x1-pad, y1-pad, (x2-x1)+pad*2, (y2-y1)+pad*2);
        ctx.stroke();
        ctx.shadowBlur = 0;
        alpha += growing ? 0.05 : -0.05;
        if (alpha >= 1) growing = false;
        if (alpha <= 0) growing = true;
      }
      if (document.getElementById('lightbox').style.display === 'block' && bbox) {
        lboxAnimId = requestAnimationFrame(drawFrame);
      }
    }
    
    if (bbox) {
      drawFrame();
    } else {
      ctx.clearRect(0, 0, cv.width, cv.height);
    }
  };
}

function closeLightbox() { 
  document.getElementById('lightbox').style.display = 'none'; 
  if (lboxAnimId) cancelAnimationFrame(lboxAnimId);
}

function lboxNav(dir) {
  lboxIndex = (lboxIndex + dir + lboxFiles.length) % lboxFiles.length;
  drawLightboxFrame();
}

function runClustering() {
  if (!confirm('Újracsoportosítod az arcokat? Ez felülírja a meglévő szortírozást.')) return;
  const status = document.getElementById('clusterStatus');
  status.textContent = '⏳ Csoportosítás folyamatban...';
  fetch('api/gallery.php?action=cluster', {method:'POST'})
    .then(r => r.json())
    .then(res => {
      status.textContent = res.success
        ? '✅ ' + res.num_clusters + ' személy azonosítva'
        : '❌ ' + res.error;
      if (res.success) loadClusters();
    }).catch(() => { status.textContent = '❌ Hálózati hiba'; });
}

function toggleSelfie() {
  if (localStorage.getItem('gdpr_selfie') !== '1') {
    document.getElementById('gdprModal').style.display = 'flex';
  } else {
    _openSelfieZone();
  }
}

function _openSelfieZone() {
  const z = document.getElementById('selfieZone');
  z.style.display = z.style.display === 'block' ? 'none' : 'block';
}

window.closeFacesGdprModal = function(accepted) {
  document.getElementById('gdprModal').style.display = 'none';
  if (accepted) {
    localStorage.setItem('gdpr_selfie', '1');
    _openSelfieZone();
  }
};

function handleSelfieFile(file) {
  if (!file) return;
  const preview = document.getElementById('selfiePreview');
  preview.src = URL.createObjectURL(file);
  preview.style.display = 'block';
  document.getElementById('selfieResult').textContent = '🔍 Keresés...';

  const fd = new FormData();
  fd.append('selfie', file);
  fetch('api/gallery.php?action=search', {method:'POST', body:fd})
    .then(r => r.json())
    .then(res => {
      if (!res.success || !res.matches.length) {
        document.getElementById('selfieResult').textContent = 'Nem találtam egyező személyt.';
        return;
      }
      document.getElementById('selfieResult').textContent =
        res.matches.length + ' találat — kiemelve a galériában';
      highlightMatchingCards(res.matches);
    })
    .catch(() => {
      document.getElementById('selfieResult').textContent = '❌ Hiba a keresés során.';
    });
}

function highlightMatchingCards(matches) {
  let firstMatch = null;
  const matchClusterIds = new Set(matches.map(m => m.cluster_id));
  
  document.querySelectorAll('.card').forEach(card => {
    card.classList.remove('highlight');
    const idx = parseInt(card.dataset.idx);
    const cl = allClusters[idx];
    if (!cl) return;
    
    if (matchClusterIds.has(cl.cluster_id)) {
      card.classList.add('highlight');
      if (!firstMatch) firstMatch = card;
    }
  });
  if (firstMatch) firstMatch.scrollIntoView({behavior:'smooth', block:'center'});
}

const sz = document.getElementById('selfieZone');
if(sz) {
    sz.addEventListener('dragover', e => { e.preventDefault(); sz.classList.add('active'); });
    sz.addEventListener('dragleave', () => sz.classList.remove('active'));
    sz.addEventListener('drop', e => { e.preventDefault(); sz.classList.remove('active'); handleSelfieFile(e.dataTransfer.files[0]); });
}

document.addEventListener('keydown', e => {
  if (e.key === 'Escape') { closeModal(); closeLightbox(); }
  if (e.key === 'ArrowLeft') lboxNav(-1);
  if (e.key === 'ArrowRight') lboxNav(1);
});

loadClusters();
