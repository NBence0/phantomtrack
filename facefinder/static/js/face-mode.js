// face-mode.js — Galériás arc-keretező és kiemelő mód

(function() {
    'use strict';

    const DEFAULT_FACE_MODE = true;
    let faceModeActive = DEFAULT_FACE_MODE;
    let currentLightboxFilename = null;
    let currentFaces = [];
    let faceAnimFrame = null;

    // Kiválasztott klaszter — lapozáskor megmarad
    window._selectedCluster = null; // { id: int, name: string|null }

    // Konzisztens klaszterszín (HSL-alapú)
    function clusterColor(cid, alpha) {
        alpha = alpha || 1;
        if (cid < 0) return 'rgba(180,180,180,' + alpha + ')';
        const hue = (cid * 137.5) % 360;
        return 'hsla(' + hue + ',90%,60%,' + alpha + ')';
    }

    window.setFaceModeVisuals = function() {
        const btn = document.getElementById('faceModeBtn');
        if (!btn) return;
        if (faceModeActive) {
            btn.style.background = 'rgba(0,229,255,.3)';
            btn.style.borderColor = '#00e5ff';
            btn.textContent = '👁️ Arcok Mutatása: BE';
            if (currentLightboxFilename) drawFaces();
        } else {
            btn.style.background = 'rgba(0,229,255,.12)';
            btn.style.borderColor = 'rgba(0,229,255,.3)';
            btn.textContent = '👁️ Arcok Mutatása';
            redrawBaseImage();
            stopFaceAnim();
        }
    };

    // Bekapcsolható "Arcok Mutatása" mód
    window.toggleFaceMode = function() {
        faceModeActive = !faceModeActive;
        window.setFaceModeVisuals();
    };

    function getCanvas() { return document.getElementById('lightbox-canvas'); }

    function drawImageToCanvas(src, callback) {
        stopFaceAnim();
        const cv = getCanvas();
        if (!cv) return;
        const ctx = cv.getContext('2d');
        const img = new Image();
        img.onload = function() {
            const MAX_DISPLAY = 2560;
            let dw = img.naturalWidth, dh = img.naturalHeight;
            if (dw > MAX_DISPLAY) {
                const scale = MAX_DISPLAY / dw;
                dw = MAX_DISPLAY; dh = Math.round(dh * scale);
            }
            cv.width = dw; cv.height = dh;
            ctx.drawImage(img, 0, 0, dw, dh);
            cv._scaleX = dw / img.naturalWidth;
            cv._scaleY = dh / img.naturalHeight;
            cv._baseImg = img; cv._dw = dw; cv._dh = dh;
            if (callback) callback();
        };
        img.onerror = function() { console.error('Kép nem tölthető be:', src); };
        img.src = src;
    }

    function redrawBaseImage() {
        const cv = getCanvas();
        if (!cv || !cv._baseImg) return;
        cv.getContext('2d').drawImage(cv._baseImg, 0, 0, cv._dw || cv.width, cv._dh || cv.height);
    }

    function fetchAndDrawFaces(filename) {
        fetch('facefinder/api.php?action=faces_in_image&filename=' + encodeURIComponent(filename))
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (res.success && res.faces && res.faces.length) {
                    currentFaces = res.faces;
                    
                    // --- 4. Pontossági adatok konzolra írása ---
                    console.group('🔍 Arc adatok: ' + filename);
                    currentFaces.forEach(function(f, i) {
                        const genderStr = f.gender === 'M' ? 'Férfi' : (f.gender === 'F' ? 'Nő' : f.gender);
                        console.log('Arc #' + (i+1) + ': ' + (f.name || 'Ismeretlen') + 
                                    ' [ID:' + f.face_id + ', Cluster:' + f.cluster_id + ']\n' +
                                    '   - Kor: ~' + (f.age || '?') + ' év\n' +
                                    '   - Nem: ' + (genderStr || '?') + '\n' +
                                    '   - Pontosság: ' + (f.score ? (f.score*100).toFixed(1)+'%' : '?'));
                    });
                    console.groupEnd();

                    animateFaces();
                } else {
                    redrawBaseImage();
                }
            }).catch(function(e) { console.warn('faces_in_image hiba:', e); });
    }

    function drawFaces() {
        if (currentLightboxFilename) fetchAndDrawFaces(currentLightboxFilename);
    }

    var _animPhase = 0;
    function animateFaces() {
        stopFaceAnim();
        const selectedId = window._selectedCluster ? window._selectedCluster.id : null;

        function frame() {
            redrawBaseImage();
            _animPhase = (_animPhase + 0.04) % (Math.PI * 2);
            var pulse = 0.6 + Math.sin(_animPhase) * 0.4;
            var cv = getCanvas();
            if (!cv) return;
            var ctx = cv.getContext('2d');

            currentFaces.forEach(function(f) {
                if (!f.bbox || f.bbox.length < 4) return;
                var sx = cv._scaleX || 1, sy = cv._scaleY || 1;
                var x1 = f.bbox[0]*sx, y1 = f.bbox[1]*sy;
                var x2 = f.bbox[2]*sx, y2 = f.bbox[3]*sy;

                var isSelected = (selectedId !== null && f.cluster_id === selectedId);
                var isOther = (selectedId !== null && f.cluster_id !== selectedId);

                if (isOther) {
                    // Más arcok: halvány szürke
                    ctx.strokeStyle = 'rgba(120,120,120,0.35)';
                    ctx.lineWidth = 2;
                    ctx.shadowBlur = 0;
                    ctx.strokeRect(x1, y1, x2-x1, y2-y1);
                    return;
                }

                // Kiválasztott (vagy mind, ha nincs szelekció): teljes szín + pulzálás
                var color = isSelected ? '#00e5ff' : clusterColor(f.cluster_id, pulse);
                ctx.lineWidth = isSelected
                    ? Math.max(5, cv.width * 0.004)
                    : Math.max(3, cv.width * 0.003);
                ctx.strokeStyle = color;
                ctx.shadowColor = color;
                ctx.shadowBlur = isSelected ? 25 * pulse : 12 * pulse;
                ctx.strokeRect(x1, y1, x2-x1, y2-y1);
                ctx.shadowBlur = 0;

                // Névfelirat
                var label = f.name || ('#' + f.cluster_id);
                if (isSelected) label = '★ ' + label;
                ctx.font = 'bold ' + Math.max(14, cv.width*0.012) + 'px Inter,sans-serif';
                ctx.fillStyle = color;
                ctx.fillText(label, x1 + 4, Math.max(y1 - 6, 16));
            });

            faceAnimFrame = requestAnimationFrame(frame);
        }
        faceAnimFrame = requestAnimationFrame(frame);
    }

    function stopFaceAnim() {
        if (faceAnimFrame) { cancelAnimationFrame(faceAnimFrame); faceAnimFrame = null; }
    }

    // Galéria szűrés klaszter alapján (csak az egyező képek látszanak)
    window.highlightClusterInGallery = function(clusterId, name) {
        // Kiválasztott klaszter eltárolása — lightboxban megmarad
        window._selectedCluster = { id: clusterId, name: name || null };

        fetch('facefinder/api.php?action=get_person_faces&limit=5000&cluster_id=' + clusterId)
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (!res.success || !res.faces) return;

                var seen = {};
                var matchFiles = [];
                res.faces.forEach(function(f) {
                    var fn = f.file_path.split('/').pop();
                    if (!seen[fn]) { seen[fn] = 1; matchFiles.push(fn); }
                });

                document.querySelectorAll('.pagination, .pagination-bottom, .gallery-controls').forEach(function(el) {
                    el.style.display = 'none';
                });

                window._filteredLboxImages = matchFiles;

                var container = document.querySelector('.thumbnails');
                if (!container) return;
                var allImgs = window.allImages || [];
                var html = '';
                matchFiles.forEach(function(fn) {
                    var globalIndex = allImgs.indexOf(fn);
                    if (globalIndex === -1) globalIndex = 0;
                    html += '<div class="thumb-item" style="outline:3px solid #00e5ff;box-shadow:0 0 18px rgba(0,229,255,.4);border-radius:6px;">';
                    html += '<img src="thumbs/' + fn + '" alt="' + fn + '" onclick="openLightbox(\'' + fn + '\',' + globalIndex + ')">';
                    html += '<div class="image-info"><span class="filename" style="font-size:12px;color:#00e5ff;">' + fn + '</span></div>';
                    html += '</div>';
                });
                container.innerHTML = html;

                var panel = document.getElementById('faceHighlightPanel');
                var label = name || ('Személy #' + clusterId);
                document.getElementById('faceHighlightText').textContent =
                    '👁️ ' + label + ' — ' + matchFiles.length + ' kép megjelenítve';
                if (panel) panel.style.display = 'block';

                var gallery = document.querySelector('.gallery');
                if (gallery) gallery.scrollIntoView({behavior:'smooth', block:'start'});
            });
    };

    window.clearFaceHighlight = function() {
        window._selectedCluster = null;
        window._filteredLboxImages = null;
        var panel = document.getElementById('faceHighlightPanel');
        if (panel) panel.style.display = 'none';
        window.location.reload();
    };

    // DOMContentLoaded után hookoljuk be a lightbox-ot és canvas eventeket
    document.addEventListener('DOMContentLoaded', function() {
        window.setFaceModeVisuals();
        var cv = getCanvas();
        if (cv) {
            cv.addEventListener('click', function(e) {
                if (!currentFaces.length) return;
                var rect = cv.getBoundingClientRect();
                var sx = cv.width / rect.width, sy = cv.height / rect.height;
                var mx = (e.clientX - rect.left) * sx;
                var my = (e.clientY - rect.top) * sy;
                var clicked = null;
                for (var i = 0; i < currentFaces.length; i++) {
                    var f = currentFaces[i];
                    if (!f.bbox || f.bbox.length < 4) continue;
                    var imgSX = cv._scaleX || 1, imgSY = cv._scaleY || 1;
                    var x1 = f.bbox[0]*imgSX, y1 = f.bbox[1]*imgSY;
                    var x2 = f.bbox[2]*imgSX, y2 = f.bbox[3]*imgSY;
                    if (mx >= x1 && mx <= x2 && my >= y1 && my <= y2) { clicked = f; break; }
                }
                if (clicked) {
                    window.closeLightbox && window.closeLightbox();
                    window.highlightClusterInGallery(clicked.cluster_id, clicked.name);
                }
            });

            cv.addEventListener('mousemove', function(e) {
                if (!currentFaces.length) return;
                var rect = cv.getBoundingClientRect();
                var sx = cv.width / rect.width, sy = cv.height / rect.height;
                var mx = (e.clientX - rect.left) * sx;
                var my = (e.clientY - rect.top) * sy;
                var tip = document.getElementById('faceTooltip');
                var hovered = null;
                for (var i = 0; i < currentFaces.length; i++) {
                    var f = currentFaces[i];
                    if (!f.bbox || f.bbox.length < 4) continue;
                    var imgSX = cv._scaleX || 1, imgSY = cv._scaleY || 1;
                    var x1 = f.bbox[0]*imgSX, y1 = f.bbox[1]*imgSY;
                    var x2 = f.bbox[2]*imgSX, y2 = f.bbox[3]*imgSY;
                    if (mx >= x1 && mx <= x2 && my >= y1 && my <= y2) { hovered = f; break; }
                }
                if (hovered) {
                    cv.style.cursor = 'pointer';
                    if (tip) {
                        tip.style.display = 'block';
                        tip.style.left = (e.clientX - rect.left + 12) + 'px';
                        tip.style.top = (e.clientY - rect.top - 30) + 'px';
                        tip.textContent = (hovered.name || 'Személy #' + hovered.cluster_id) + ' — kattints a galéria szűréséhez';
                    }
                } else {
                    cv.style.cursor = 'default';
                    if (tip) tip.style.display = 'none';
                }
            });
        }

        // Lightbox override — faces fetch-kor _selectedCluster-t is figyelembe veszi
        window.openLightbox = function(filename, index) {
            currentLightboxFilename = filename;
            currentFaces = [];
            drawImageToCanvas('images/' + filename, function() {
                // Ha van aktív klaszter-szelekció VAGY face mód be van kapcsolva → arcok betöltése
                if (faceModeActive || window._selectedCluster) {
                    fetchAndDrawFaces(filename);
                }
            });
            window._lboxImages = window._filteredLboxImages || window.allImages;
            window._lboxIndex = window._lboxImages ? window._lboxImages.indexOf(filename) : 0;
            if (window._lboxIndex === -1) window._lboxIndex = 0;
            
            var counter = document.getElementById('lightbox-counter');
            if (counter) counter.textContent = (window._lboxIndex + 1) + ' / ' + (window._lboxImages ? window._lboxImages.length : '?');
            var lb = document.getElementById('lightbox');
            if (lb) lb.style.display = 'block';
            document.body.style.overflow = 'hidden';
        };

        window.closeLightbox = function() {
            stopFaceAnim();
            var lb = document.getElementById('lightbox');
            if (lb) lb.style.display = 'none';
            var tip = document.getElementById('faceTooltip');
            if (tip) tip.style.display = 'none';
            document.body.style.overflow = 'auto';
        };

        window.prevImage = function() {
            if (!window._lboxImages || window._lboxIndex <= 0) return;
            window._lboxIndex--;
            var fn = window._lboxImages[window._lboxIndex];
            currentLightboxFilename = fn; currentFaces = [];
            drawImageToCanvas('images/' + fn, function() {
                if (faceModeActive || window._selectedCluster) fetchAndDrawFaces(fn);
            });
            var counter = document.getElementById('lightbox-counter');
            if (counter) counter.textContent = (window._lboxIndex + 1) + ' / ' + window._lboxImages.length;
        };

        window.nextImage = function() {
            if (!window._lboxImages || window._lboxIndex >= window._lboxImages.length - 1) return;
            window._lboxIndex++;
            var fn = window._lboxImages[window._lboxIndex];
            currentLightboxFilename = fn; currentFaces = [];
            drawImageToCanvas('images/' + fn, function() {
                if (faceModeActive || window._selectedCluster) fetchAndDrawFaces(fn);
            });
            var counter = document.getElementById('lightbox-counter');
            if (counter) counter.textContent = (window._lboxIndex + 1) + ' / ' + window._lboxImages.length;
        };

        document.addEventListener('keydown', function(e) {
            var lb = document.getElementById('lightbox');
            if (lb && lb.style.display !== 'none') {
                if (e.key === 'Escape') window.closeLightbox();
                if (e.key === 'ArrowLeft') window.prevImage();
                if (e.key === 'ArrowRight') window.nextImage();
            }
        });
    });

})();
