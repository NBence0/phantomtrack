// facefinder/static/js/images.js — Infinite Scroll verzió
const PAGE_SIZE = 30;
let currentPage = 1;
let totalPages  = 1;
let isLoading   = false;
let allLoaded   = false;
let selectedFaces = new Set();
let currentImages = [];  // összes betöltött kép (kumulált)
let activeLightboxIndex = -1;

// Manuális Tag mód
let isTagMode   = false;
let tagDrawing  = false;
let tagStartX   = 0;
let tagStartY   = 0;
let tagImageFile = null;
let tagImageNatW = 0;
let tagImageNatH = 0;

const colors = [
    '#00e5ff','#ff3366','#00e676','#ffaa00','#a78bfa',
    '#f472b6','#34d399','#fbbf24','#60a5fa','#a3e635',
    '#f87171','#38bdf8','#818cf8','#e879f9','#4ade80'
];

function getColorForCluster(cid) {
    if (cid === -1) return 'rgba(255,255,255,0.7)';
    return colors[Math.abs(cid) % colors.length];
}

// ── BELÉPÉS ─────────────────────────────────────────────────────────────────
function doLogin(e) {
    e.preventDefault();
    const btn = document.getElementById('loginBtn');
    const err = document.getElementById('loginErr');
    btn.textContent = 'Ellenőrzés...'; btn.style.opacity = '0.7';
    const f = new FormData(); f.append('password', document.getElementById('pw').value);
    fetch('api/auth.php?action=login', {method:'POST',body:f})
      .then(r=>r.json()).then(res=>{
        if(res.ok||res.success){err.style.color='#00e676';err.textContent='Sikeres!';setTimeout(()=>location.reload(),500);}
        else{err.textContent=res.error||'Hibás jelszó';btn.textContent='Hitelesítés';btn.style.opacity='1';}
      }).catch(()=>{err.textContent='Hálózati hiba.';btn.textContent='Hitelesítés';btn.style.opacity='1';});
}

// ── BETÖLTÉS ─────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    loadMoreImages();
    setupIntersectionObserver();
    setupLightboxEvents();
    setupTagMode();
});

function loadMoreImages() {
    if (isLoading || allLoaded) return;
    isLoading = true;
    showScrollLoader(true);

    fetch(`api/editor_api.php?action=get_images&page=${currentPage}&limit=${PAGE_SIZE}`, {method:'POST'})
        .then(r => r.json())
        .then(res => {
            isLoading = false;
            showScrollLoader(false);
            if (!res.success) { showError(res.error); return; }

            totalPages = res.totalPages || 1;
            const newImages = res.images || [];
            if (newImages.length === 0 || currentPage >= totalPages) {
                allLoaded = true;
                showEndMessage(res.total || currentImages.length);
            }

            document.getElementById('pageStats').innerHTML =
                `Betöltve: <b>${currentImages.length + newImages.length}</b> / ${res.total}`;

            appendImages(newImages);
            currentImages = currentImages.concat(newImages);
            currentPage++;
            
            // Ha a betöltött képek nem töltik ki a képernyőt, töltsünk be még!
            setTimeout(() => {
                if (!allLoaded && document.body.scrollHeight <= window.innerHeight + 100) {
                    loadMoreImages();
                }
            }, 100);
        })
        .catch(() => { isLoading = false; showScrollLoader(false); showError('Hálózati hiba.'); });
}

// ── INFINITE SCROLL OBSERVER ──────────────────────────────────────────────────
function setupIntersectionObserver() {
    const sentinel = document.getElementById('scrollSentinel');
    if (!sentinel) return;
    const obs = new IntersectionObserver((entries) => {
        if (entries[0].isIntersecting && !isLoading && !allLoaded) {
            loadMoreImages();
        }
    }, { rootMargin: '200px' });
    obs.observe(sentinel);
}

function showScrollLoader(show) {
    const el = document.getElementById('scrollLoader');
    if (el) el.style.display = show ? 'flex' : 'none';
}

function showEndMessage(total) {
    const el = document.getElementById('scrollEndMsg');
    if (el) { el.textContent = `✅ Minden kép betöltve (${total} db)`; el.style.display = 'block'; }
}

function showError(msg) {
    const grid = document.getElementById('galleryGrid');
    if (!grid.querySelector('.loading-state')) {
        const div = document.createElement('div');
        div.className = 'loading-state';
        div.style.color = 'var(--danger-primary)';
        div.textContent = 'Hiba: ' + msg;
        grid.appendChild(div);
    }
}

function changeGridSize(size) {
    document.getElementById('galleryGrid').style.gridTemplateColumns = `repeat(auto-fill, minmax(${size}px, 1fr))`;
}

// ── GRID MEGJELENÍTÉS ─────────────────────────────────────────────────────────
function appendImages(images) {
    const grid = document.getElementById('galleryGrid');
    // Töröljük az üres állapot jelzőt (ha volt)
    const loadState = grid.querySelector('.loading-state');
    if (loadState) loadState.remove();

    const globalOffset = currentImages.length; // eddig mennyi volt

    const fragment = document.createDocumentFragment();

    images.forEach((img, localIdx) => {
        const index = globalOffset + localIdx;
        const card  = document.createElement('div');
        card.className = 'img-card';
        card.style.animationDelay = `${(localIdx % 10) * 0.04}s`;

        const clusterCounts = {};
        img.faces.forEach(f => { if (f.cluster_id !== -1) clusterCounts[f.cluster_id] = (clusterCounts[f.cluster_id]||0)+1; });
        img.clusterCounts = clusterCounts; // Store for drawGridBboxes
        const dups = Object.keys(clusterCounts).filter(k => clusterCounts[k] > 1);
        const dupHtml = dups.length ? `<div class="dup-warning">⚠️ ID: ${dups.join(', ')}</div>` : '';

        const storedFile = img.stored_file || img.file;
        const isVideo = /\.(mp4|webm|ogg|mov|avi|mkv)$/i.test(storedFile);
        
        let mediaHtml = isVideo 
            ? `<video src="../uploads/${storedFile}" onclick="openLightbox(${index})" style="width:100%; height:100%; object-fit:cover;" muted loop onmouseover="this.play()" onmouseout="this.pause()"></video>`
            : `<img src="../uploads/${storedFile}" alt="${img.file}" onclick="openLightbox(${index})" loading="lazy">`;

        card.innerHTML = `
            <div class="img-container">
                ${mediaHtml}
                <div class="bbox-layer" id="grid_layer_${index}"></div>
            </div>
            <div class="img-info">
                <div class="img-stats">
                    <div class="face-count"><i class="fas fa-eye"></i> ${Array.isArray(img.faces) ? img.faces.length : 0}</div>${dupHtml}
                </div>
                <div class="img-filename" title="${img.file}">${img.file}</div>
            </div>
        `;
        fragment.appendChild(card);

        const mediaEl = card.querySelector('img, video');
        const draw  = () => {
            if (mediaEl.tagName === 'VIDEO' && mediaEl.videoWidth === 0) { setTimeout(draw, 100); return; }
            if (mediaEl.tagName === 'IMG' && mediaEl.naturalWidth === 0) { setTimeout(draw, 100); return; }
            drawGridBboxes(img, index, mediaEl);
        };
        if (mediaEl.tagName === 'VIDEO') {
            if (mediaEl.readyState >= 1) draw(); else mediaEl.addEventListener('loadedmetadata', draw);
        } else {
            if (mediaEl.complete) draw(); else mediaEl.onload = draw;
        }
    });
    grid.appendChild(fragment);
}

function drawGridBboxes(imgData, index, mediaEl) {
    const container = document.getElementById(`grid_layer_${index}`);
    if (!container) return;
    // Clear previous boxes
    container.innerHTML = '';
    const natW = mediaEl.naturalWidth || mediaEl.videoWidth || 1; // Fallback to avoid division by zero
    const natH = mediaEl.naturalHeight || mediaEl.videoHeight || 1;
    if (natW <= 1 || natH <= 1) {
        // If still not loaded, try again in 100ms
        setTimeout(() => drawGridBboxes(imgData, index, mediaEl), 100);
        return;
    }

    const fragment = document.createDocumentFragment();

    imgData.faces.forEach(f => {
        if (!f.bbox || f.bbox.length !== 4) return;
        const box = document.createElement('div');
        box.className = 'bbox'; box.id = 'grid_bbox_' + f.face_id;
        const [x1,y1,x2,y2] = f.bbox;
        box.style.left   = `${(x1/natW)*100}%`;
        box.style.top    = `${(y1/natH)*100}%`;
        box.style.width  = `${((x2-x1)/natW)*100}%`;
        box.style.height = `${((y2-y1)/natH)*100}%`;
        const color = getColorForCluster(f.cluster_id);
        box.style.borderColor = color;
        
        // Use pre-calculated counts
        const dupsInImg = imgData.clusterCounts && imgData.clusterCounts[f.cluster_id] ? imgData.clusterCounts[f.cluster_id] : 1;
        const label = document.createElement('div');
        label.className = 'bbox-label'; label.style.backgroundColor = color;
        
        if (f.cluster_id !== -1 && dupsInImg > 1) {
            // Eredeti FaceFinder duplikátum stílus visszaállítása
            box.style.borderStyle = 'dashed';
            box.style.boxShadow = `0 0 12px ${color}, inset 0 0 12px ${color}`;
            label.textContent = '?' === f.cluster_id ? '?' : '#' + f.cluster_id;
        } else {
            label.textContent = f.cluster_id === -1 ? '?' : '#' + f.cluster_id;
        }
        box.appendChild(label);
        
        if (selectedFaces.has(f.face_id)) box.classList.add('selected');
        box.onclick = (e) => { e.stopPropagation(); toggleSelect(f.face_id); };

        // Track original dimensions
        box.dataset.origW = box.style.width;
        box.dataset.origH = box.style.height;

        // Átméretezés figyelése
        box.addEventListener('mouseup', (e) => handleBboxResize(e, box, f.face_id, natW, natH));

        fragment.appendChild(box);
    });
    container.appendChild(fragment);
}

function handleBboxResize(e, box, faceId, natW, natH) {
    if (e.target !== box) return; // Csak ha magát a dobozt méretezi (jobb alsó sarok)
    
    // Check if it actually resized
    if (box.style.width === box.dataset.origW && box.style.height === box.dataset.origH) return;
    
    const bw = parseFloat(box.style.width);
    const bh = parseFloat(box.style.height);
    const bx = parseFloat(box.style.left);
    const by = parseFloat(box.style.top);
    
    // Convert back to native pixels
    const x1 = (bx / 100) * natW;
    const y1 = (by / 100) * natH;
    const x2 = x1 + ((bw / 100) * natW);
    const y2 = y1 + ((bh / 100) * natH);

    fetch('api/editor_api.php?action=update_bbox', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ face_id: faceId, bbox: [x1, y1, x2, y2] })
    }).then(() => {
        // Update original dimensions after successful save
        box.dataset.origW = box.style.width;
        box.dataset.origH = box.style.height;
    }).catch(console.error);
}

// ── KIJELÖLÉS & TÖRLÉS ───────────────────────────────────────────────────────
function toggleSelect(faceId) {
    const isSel = selectedFaces.has(faceId);
    if (isSel) selectedFaces.delete(faceId); else selectedFaces.add(faceId);
    ['grid_bbox_','lb_bbox_'].forEach(p => {
        const el = document.getElementById(p+faceId);
        if (el) el.classList.toggle('selected', !isSel);
    });
    updateActionBar();
}

function deselectAll() {
    selectedFaces.clear();
    document.querySelectorAll('.bbox.selected').forEach(el => el.classList.remove('selected'));
    updateActionBar();
}

function updateActionBar() {
    const bar = document.getElementById('actionBar');
    if (bar) {
        document.getElementById('selCount').textContent = selectedFaces.size;
        bar.style.display = selectedFaces.size > 0 ? 'flex' : 'none';
    }
    
    const lbSelInfo = document.getElementById('lbSelInfo');
    const btnLbDelete = document.getElementById('btnLbDelete');
    const btnLbDeselect = document.getElementById('btnLbDeselect');
    if (lbSelInfo) {
        document.getElementById('lbSelCount').textContent = selectedFaces.size;
        const display = selectedFaces.size > 0 ? 'inline-block' : 'none';
        lbSelInfo.style.display = display;
        btnLbDelete.style.display = display;
        btnLbDeselect.style.display = display;
    }
}

function deleteSelected() {
    if (!selectedFaces.size) return;
    if (!confirm(`VIGYÁZAT! Biztosan véglegesen törlöd a kijelölt ${selectedFaces.size} arcot?`)) return;
    fetch('api/editor_api.php?action=delete_faces', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({face_ids: Array.from(selectedFaces)})
    }).then(r=>r.json()).then(res=>{
        if (res.success) {
            selectedFaces.forEach(faceId => {
                document.getElementById('grid_bbox_'+faceId)?.remove();
                document.getElementById('lb_bbox_'+faceId)?.remove();
            });
            selectedFaces.clear(); updateActionBar();
        } else alert('Hiba: ' + res.error);
    }).catch(()=>alert('Hálózati hiba.'));
}

// ── LIGHTBOX ─────────────────────────────────────────────────────────────────
const lbOverlay   = document.getElementById('lightbox');
const lbWrapper   = document.getElementById('lbWrapper');
const lbImg       = document.getElementById('lbImg');
const lbVid       = document.getElementById('lbVid');
const lbContainer = document.getElementById('lbBboxContainer');
let lbZoom=1,lbPanX=0,lbPanY=0,isDragging=false,startDragX,startDragY;

function updateTransform() {
    lbWrapper.style.transform = `translate(${lbPanX}px,${lbPanY}px) scale(${lbZoom})`;
}

function openLightbox(index) {
    if (index < 0 || index >= currentImages.length) return;
    activeLightboxIndex = index;
    const imgData = currentImages[index];
    lbZoom=1; lbPanX=0; lbPanY=0; updateTransform();
    lbContainer.innerHTML = '';

    const storedFile = imgData.stored_file || imgData.file;
    const isVideo = /\.(mp4|webm|ogg|mov|avi|mkv)$/i.test(storedFile);
    tagImageFile = storedFile;

    if (isVideo) {
        lbImg.style.display = 'none';
        lbVid.style.display = 'block';
        lbVid.src = `../uploads/${storedFile}`;
        lbVid.onloadedmetadata = () => { tagImageNatW = lbVid.videoWidth; tagImageNatH = lbVid.videoHeight; };
        lbVid.play().catch(e=>console.log(e));
    } else {
        if (lbVid) { lbVid.style.display = 'none'; lbVid.pause(); }
        lbImg.style.display = 'block';
        lbImg.src = `../uploads/${storedFile}`;
        lbImg.onload = () => { tagImageNatW = lbImg.naturalWidth; tagImageNatH = lbImg.naturalHeight; };
    }

    const gridLayer = document.getElementById(`grid_layer_${index}`);
    if (gridLayer) {
        lbContainer.innerHTML = gridLayer.innerHTML;
        lbContainer.querySelectorAll('.bbox').forEach(b => {
            const faceId = parseInt(b.id.replace('grid_bbox_',''));
            b.id = 'lb_bbox_'+faceId;
            b.onclick = (e) => { e.stopPropagation(); toggleSelect(faceId); };
            
            // Re-attach resize listener
            const natW = imgData.natW || lbImg.naturalWidth;
            const natH = imgData.natH || lbImg.naturalHeight;
            b.addEventListener('mouseup', (e) => handleBboxResize(e, b, faceId, lbImg.naturalWidth, lbImg.naturalHeight));
        });
    }
    updateTagBtn();
    lbOverlay.classList.add('is-open');
    document.body.style.overflow = 'hidden';
}
function closeLightbox() {
    lbOverlay.classList.remove('is-open');
    document.body.style.overflow = 'auto';
    activeLightboxIndex = -1;
    isTagMode = false; tagDrawing = false;
    lbWrapper.style.cursor = '';
    document.getElementById('tagDrawBox')?.remove();
    updateTagBtn();
    if (lbVid) lbVid.pause();
}
function prevImage() { if (activeLightboxIndex > 0) openLightbox(activeLightboxIndex-1); }
function nextImage() { if (activeLightboxIndex >= 0 && activeLightboxIndex < currentImages.length-1) openLightbox(activeLightboxIndex+1); }

// ── MANUÁLIS TAG MÓD ─────────────────────────────────────────────────────────
function setupTagMode() {
    lbWrapper.addEventListener('mousedown', startTagDraw);
    lbWrapper.addEventListener('mousemove', doTagDraw);
    lbWrapper.addEventListener('mouseup',   endTagDraw);
}

function toggleTagMode() {
    isTagMode = !isTagMode;
    lbWrapper.style.cursor = isTagMode ? 'crosshair' : '';
    document.getElementById('tagDrawBox')?.remove();
    updateTagBtn();
}
function updateTagBtn() {
    const btn = document.getElementById('btnTagMode');
    if (!btn) return;
    btn.textContent = isTagMode ? '✅ Tag Mód: BE' : '🏷️ Tag Mód';
    btn.style.background = isTagMode ? '#00e676' : '';
    btn.style.color = isTagMode ? '#000' : '';
}

function startTagDraw(e) {
    if (!isTagMode || e.target.closest('.bbox')) return;
    tagDrawing = true;
    const rect = lbWrapper.getBoundingClientRect();
    tagStartX = (e.clientX - rect.left) / lbZoom;
    tagStartY = (e.clientY - rect.top)  / lbZoom;

    let box = document.getElementById('tagDrawBox');
    if (!box) { box = document.createElement('div'); box.id = 'tagDrawBox'; box.style.cssText='position:absolute;border:2px dashed #00e5ff;background:rgba(0,229,255,0.08);pointer-events:none;z-index:100;'; lbWrapper.appendChild(box); }
    box.style.left   = tagStartX + 'px';
    box.style.top    = tagStartY + 'px';
    box.style.width  = '0'; box.style.height = '0';
    e.preventDefault();
}
function doTagDraw(e) {
    if (!tagDrawing) return;
    const rect = lbWrapper.getBoundingClientRect();
    const curX = (e.clientX - rect.left) / lbZoom;
    const curY = (e.clientY - rect.top)  / lbZoom;
    const box  = document.getElementById('tagDrawBox');
    if (!box) return;
    box.style.left   = Math.min(tagStartX, curX) + 'px';
    box.style.top    = Math.min(tagStartY, curY) + 'px';
    box.style.width  = Math.abs(curX - tagStartX) + 'px';
    box.style.height = Math.abs(curY - tagStartY) + 'px';
}
function endTagDraw(e) {
    if (!tagDrawing) return;
    tagDrawing = false;
    const box = document.getElementById('tagDrawBox');
    if (!box) return;
    const w = parseInt(box.style.width), h = parseInt(box.style.height);
    if (w < 10 || h < 10) { box.remove(); return; } // Túl kis rajz

    // Koordináták visszaszámítása a kép natív méreteire
    // Ha a kép látható, lbImg méreteit használjuk; ha videó, lbVid méreteit
    const activeMedia = (lbImg && lbImg.style.display !== 'none') ? lbImg : (lbVid || lbImg);
    const dispW = activeMedia.clientWidth  || 1;
    const dispH = activeMedia.clientHeight || 1;
    const scaleX = tagImageNatW / dispW;
    const scaleY = tagImageNatH / dispH;
    const x1 = parseFloat(box.style.left) * scaleX;
    const y1 = parseFloat(box.style.top)  * scaleY;
    const x2 = (parseFloat(box.style.left) + w) * scaleX;
    const y2 = (parseFloat(box.style.top)  + h) * scaleY;
    box.remove();

    const clusterInput = prompt('Melyik klaszterhez tartozik? (Cluster ID, vagy hagyd üresen = ismeretlen)', '-1');
    if (clusterInput === null) return; // Cancel
    const clusterId = parseInt(clusterInput) || -1;

    fetch('api/editor_api.php?action=add_manual_face', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ video_path: tagImageFile, bbox:[x1,y1,x2,y2], cluster_id: clusterId })
    }).then(r=>r.json()).then(res=>{
        if (res.success) {
            const color = getColorForCluster(clusterId);
            const newBox = document.createElement('div');
            newBox.className = 'bbox'; newBox.id = 'lb_bbox_'+res.face_id;
            newBox.style.left   = `${(x1/tagImageNatW)*100}%`;
            newBox.style.top    = `${(y1/tagImageNatH)*100}%`;
            newBox.style.width  = `${((x2-x1)/tagImageNatW)*100}%`;
            newBox.style.height = `${((y2-y1)/tagImageNatH)*100}%`;
            newBox.style.borderColor = color;
            newBox.style.borderStyle = 'dashed';
            const lbl = document.createElement('div'); lbl.className='bbox-label'; lbl.style.background=color;
            lbl.textContent = clusterId === -1 ? '?' : '#'+clusterId;
            newBox.appendChild(lbl);

            newBox.addEventListener('mouseup', (e) => handleBboxResize(e, newBox, res.face_id, tagImageNatW, tagImageNatH));

            lbContainer.appendChild(newBox);
            alert(`✅ Kézi arc mentve! (face_id: ${res.face_id})`);
        } else alert('Hiba: ' + res.error);
    }).catch(()=>alert('Hálózati hiba.'));
}

// ── LIGHTBOX EVENTS ──────────────────────────────────────────────────────────
function setupLightboxEvents() {
    document.getElementById('lbPrev').onclick = (e) => { e.stopPropagation(); prevImage(); };
    document.getElementById('lbNext').onclick = (e) => { e.stopPropagation(); nextImage(); };

    lbOverlay.addEventListener('wheel', (e) => {
        if (!e.target.closest('#lbWrapper')) return;
        e.preventDefault();
        const delta = Math.sign(e.deltaY) * -0.15;
        let z = Math.max(1, Math.min(10, lbZoom+delta));
        if (z===1){lbPanX=0;lbPanY=0;} lbZoom=z; updateTransform();
    }, {passive:false});

    lbWrapper.addEventListener('mousedown', (e) => {
        if (isTagMode || e.target.closest('.bbox')) return;
        if (lbZoom<=1) return;
        isDragging=true; startDragX=e.clientX-lbPanX; startDragY=e.clientY-lbPanY;
        lbWrapper.style.cursor='grabbing';
    });
    window.addEventListener('mousemove', (e) => {
        if(!isDragging)return; e.preventDefault();
        lbPanX=e.clientX-startDragX; lbPanY=e.clientY-startDragY; updateTransform();
    });
    window.addEventListener('mouseup', ()=>{ if(isDragging){isDragging=false; lbWrapper.style.cursor=isTagMode?'crosshair':'grab';} });

    document.addEventListener('keydown', (e) => {
        if (e.key==='Escape') {
            if (isTagMode) { isTagMode=false; lbWrapper.style.cursor=''; updateTagBtn(); return; }
            if (lbOverlay.classList.contains('is-open')) closeLightbox();
            else if (selectedFaces.size>0) deselectAll();
        }
        if ((e.key==='Delete'||e.key==='Enter') && selectedFaces.size>0) { e.preventDefault(); deleteSelected(); }
        if (lbOverlay.classList.contains('is-open')) {
            if (e.key==='ArrowLeft'){e.preventDefault();prevImage();}
            if (e.key==='ArrowRight'){e.preventDefault();nextImage();}
        }
    });
}
