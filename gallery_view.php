<?php
// === Fájl: gallery_view.php ===

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php'; // Session kezelés

$db = getDB();
$token = $_GET['token'] ?? '';
$urlUser = $_GET['user'] ?? '';
$urlSlug = $_GET['slug'] ?? '';
$error = null;
$gallery = null;
$isAuthorized = false;

// 1. Galéria keresése
if ($token) {
    $stmt = $db->prepare("
        SELECT g.*, u.username as owner_name 
        FROM galleries g 
        JOIN users u ON g.user_id = u.id 
        WHERE g.view_token = :token
    ");
    $stmt->execute([':token' => $token]);
    $gallery = $stmt->fetch();
} elseif ($urlUser && $urlSlug) {
    // Új keresés
    $stmt = $db->prepare("
        SELECT g.*, u.username as owner_name 
        FROM galleries g 
        JOIN users u ON g.user_id = u.id 
        WHERE u.username = :uname AND g.slug = :slug
    ");
    $stmt->execute([':uname' => $urlUser, ':slug' => $urlSlug]);
    $gallery = $stmt->fetch();
}

if (!$gallery) {
    // Ne használjunk http_response_code(404) mert a globális ErrorDocument
    // intercept-eli és redirect-el a static/error.php-ra.
    // Helyette: redirect az általunk kontrollált 404 oldalra.
    header('Location: ' . BASE_URL . '?error=gallery_not_found');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['logout_gallery'])) {
    $sessionKey = 'gallery_auth_' . $gallery['id'];
    if (isset($_SESSION[$sessionKey])) {
        unset($_SESSION[$sessionKey]); // Töröljük a jogosultságot
    }
    // Újratöltés, hogy megjelenjen a jelszókérő
    header("Location: " . $_SERVER['REQUEST_URI']);
    exit;
}
// 2. Jogosultság ellenőrzése

// JAVÍTOTT KÓD RÉSZLET (gallery_view.php)
$user = null;
if (function_exists('isLoggedIn') && isLoggedIn()) {
    $currentUserId = getCurrentUserId();
    if ($currentUserId) {
        $uStmt = $db->prepare("SELECT * FROM users WHERE id = :id");
        $uStmt->execute([':id' => $currentUserId]);
        $user = $uStmt->fetch(PDO::FETCH_ASSOC);
    }
}
$sessionKey = 'gallery_auth_' . $gallery['id'];

if ($gallery['visibility'] === 'public') {
    $isAuthorized = true;
} elseif ($gallery['visibility'] === 'private') {
    // Csak a tulajdonos vagy admin láthatja
    if ($user && ($user['id'] == $gallery['user_id'] || isAdmin())) {
        $isAuthorized = true;
    } else {
        $error = "Ez egy privát galéria. Nincs jogosultságod megtekinteni.";
    }
} elseif ($gallery['visibility'] === 'password') {
    // Jelszavas védelem
    if (isset($_SESSION[$sessionKey]) && $_SESSION[$sessionKey] === true) {
        $isAuthorized = true;
    } elseif ($user && ($user['id'] == $gallery['user_id'] || isAdmin())) {
        $isAuthorized = true; // Tulajdonosnak nem kell jelszó
    }
    
    // Jelszó ellenőrzés POST esetén
    if (!$isAuthorized && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['gallery_password'])) {
        if (password_verify($_POST['gallery_password'], $gallery['password_hash'])) {
            $_SESSION[$sessionKey] = true;
            $isAuthorized = true;
            // Refresh hogy eltűnjön a POST adat
            header("Location: " . $_SERVER['REQUEST_URI']);
            exit;
        } else {
            $error = "Hibás jelszó!";
        }
    }
}

// 3. Adatok betöltése (Ha jogosult)
$images = [];
$comments = [];

if ($isAuthorized) {
    // Képek
    $imgStmt = $db->prepare("SELECT * FROM files WHERE gallery_id = :gid ORDER BY upload_timestamp DESC");
    $imgStmt->execute([':gid' => $gallery['id']]);
    $images = $imgStmt->fetchAll();
    
    // Kommentek
    $cmtStmt = $db->prepare("
        SELECT c.*, u.username 
        FROM gallery_comments c 
        JOIN users u ON c.user_id = u.id 
        WHERE c.gallery_id = :gid 
        ORDER BY c.created_at DESC
    ");
    $cmtStmt->execute([':gid' => $gallery['id']]);
    $comments = $cmtStmt->fetchAll();
    
    // Arcok betöltése
    $facesStmt = $db->prepare("SELECT face_id, bbox, cluster_id, video_path FROM ff_faces WHERE gallery_id = :gid");
    $facesStmt->execute([':gid' => $gallery['id']]);
    $allFaces = $facesStmt->fetchAll(PDO::FETCH_ASSOC);
    $facesByFile = [];
    foreach ($allFaces as $f) {
        $stored = basename(str_replace('\\', '/', $f['video_path']));
        $bbox = $f['bbox'] ? array_map('floatval', explode(',', $f['bbox'])) : [];
        $facesByFile[$stored][] = [
            'face_id' => $f['face_id'],
            'bbox' => $bbox,
            'cluster_id' => $f['cluster_id']
        ];
    }
    
    // Logolás (Nagytesó stílusban)
    logActivity('gallery_view', null, null); // Itt a gallery_id-t még bele kell hackelni a log függvénybe ha nagyon precízek akarunk lenni, de most elég az activity.
}

function renderComment($text) {
    $text = htmlspecialchars($text);
    return preg_replace_callback('/\[image:([a-zA-Z0-9_-]+)\]/', function($m) {
        $token = $m[1];
        $thumb = BASE_URL . 'thumbnails/' . $token . '.webp';
        return '<div style="margin-top:10px;"><img src="'.$thumb.'" style="max-height:150px; border-radius:8px; border:1px solid rgba(255,255,255,0.1); cursor:pointer;" onclick="openLightboxByToken(\''.$token.'\')"></div>';
    }, nl2br($text));
}

?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo escape($gallery['name']); ?> | Galéria</title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/gallery_view.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>

<?php if (!$isAuthorized): ?>
    <!-- Jelszó bekérő kapu -->
    <div class="password-gate">
        <div class="gate-card">
            <i class="fas fa-lock" style="font-size: 4em; color: var(--accent); margin-bottom: 20px;"></i>
            <h2>Védett Tartalom</h2>
            <?php if ($error): ?>
                <p style="color: #ff4757; margin-bottom: 15px;"><?php echo $error; ?></p>
            <?php endif; ?>
            
            <?php if ($gallery['visibility'] === 'private'): ?>
                <p>Ez a galéria privát. Kérjük, jelentkezz be a fiókodba!</p>
                <a href="<?php echo BASE_URL; ?>tracker/login.php?redirect=<?php echo urlencode($_SERVER['REQUEST_URI']); ?>" class="btn-submit" style="text-decoration:none; display:inline-block; margin-top:10px;">Bejelentkezés</a>
            <?php else: ?>
                <p>A megtekintéshez jelszó szükséges.</p>
                <form method="POST">
                    <input type="password" name="gallery_password" placeholder="Jelszó..." style="padding:10px; border-radius:5px; border:none; margin-bottom:10px;">
                    <br>
                    <button type="submit" class="btn-submit">Belépés</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

<?php else: ?>
    <!-- Galéria Tartalom -->
    <div class="gallery-container">
        
        <!-- Fejléc -->
        <div class="infobox">
            <h1><?php echo escape($gallery['name']); ?></h1>
            <p><?php echo nl2br(escape($gallery['description'])); ?></p>
            <div class="gallery-meta">
                <i class="fas fa-camera"></i> <?php echo count($images); ?> kép • 
                <i class="fas fa-user"></i> <?php echo escape($gallery['owner_name']); ?> • 
                <i class="fas fa-calendar"></i> <?php echo date('Y.m.d', strtotime($gallery['created_at'])); ?>
            </div>
            
            <!-- Admin Upload Gomb (Csak ha a tulaj nézi) -->
            <?php if ($user && ($user['id'] == $gallery['user_id'] || isAdmin())): ?>
                <div style="margin-top: 20px;">
                    <a href="<?php echo BASE_URL; ?>tracker/galleries.php" class="btn-submit" style="text-decoration:none; font-size:0.9em; margin-right:10px;">
                        <i class="fas fa-cog"></i> Kezelés / Feltöltés
                    </a>
                </div>
            <?php endif; ?>

            <!-- AI Gombok innen eltávolítva a kérésnek megfelelően -->

            <!-- KILÉPÉS GOMB (Jelszavas galériáknál) -->
            <?php if ($gallery['visibility'] === 'password' && isset($_SESSION['gallery_auth_' . $gallery['id']])): ?>
                <form method="POST" style="position:absolute; top:20px; right:20px;">
                    <input type="hidden" name="logout_gallery" value="1">
                    <button type="submit" class="delete-comment-btn" title="Kilépés a galériából" style="font-size:1.5em; opacity:0.8;">
                        <i class="fas fa-sign-out-alt"></i>
                    </button>
                </form>
            <?php endif; ?>

        </div>

        <!-- Arc kiemelő panel (alul fix) -->
        <div id="faceHighlightPanel" style="display:none; position:fixed; bottom:20px; left:50%; transform:translateX(-50%); background:#0f0f1a; border:1px solid #00e5ff; border-radius:12px; padding:14px 20px; z-index:500; color:#fff; font-size:.88rem; max-width:90vw; text-align:center; box-shadow:0 8px 30px rgba(0,229,255,.25); white-space:nowrap;">
            <span id="faceHighlightText"></span>
            <button onclick="clearClusterFilter()" style="margin-left:14px; background:#ff3366; color:#fff; border:none; border-radius:6px; padding:5px 12px; cursor:pointer; font-weight:bold;">&#10005; Szűrő törlése</button>
        </div>

        <!-- Képrács -->
        <?php if (empty($images)): ?>
            <div style="text-align:center; padding:50px; color:#666;">
                <i class="far fa-images" style="font-size:3em; margin-bottom:10px;"></i>
                <p>Még nincsenek feltöltött képek.</p>
            </div>
        <?php else: ?>
            <div class="thumbnails" id="thumbnailGrid">
                <?php foreach ($images as $index => $img): 
                    $thumbUrl = BASE_URL . 'thumbnails/' . $img['view_token'] . '.webp';
                    $fullUrl = BASE_URL . 'data.php?id=' . $img['view_token'] . '&type=raw';
                    
                    $fileName = $img['original_name'] ?? $img['file_path'] ?? '';
                    $isVideo = preg_match('/\.(mp4|webm|ogg|mov|avi|mkv)$/i', $fileName);
                    
                    $stored = $img['stored_filename'] ?? basename($img['file_path'] ?? '');
                    $imgClusters = array_unique(array_column($facesByFile[$stored] ?? [], 'cluster_id'));
                    $clustersAttr = implode(',', $imgClusters);
                ?>
                    <div class="thumb-item" style="position:relative;" data-index="<?php echo $index; ?>" data-clusters="<?php echo htmlspecialchars($clustersAttr); ?>">
                        <img src="<?php echo $thumbUrl; ?>" loading="lazy" alt="Kép" onclick="openLightbox(<?php echo $index; ?>)">
                        <?php if ($isVideo): ?>
                            <div style="position:absolute; top:50%; left:50%; transform:translate(-50%, -50%); color:white; font-size:2em; pointer-events:none; opacity:0.8; text-shadow:0 2px 5px rgba(0,0,0,0.5);"><i class="fas fa-play-circle"></i></div>
                        <?php endif; ?>
                        
                        <?php if ($user): ?>
                        <button class="tag-btn" onclick="insertImageTag('<?php echo $img['view_token']; ?>')" title="Kép beillesztése a kommentbe" style="position:absolute; bottom:5px; right:5px; background:rgba(0,0,0,0.6); color:white; border:none; border-radius:4px; padding:3px 6px; cursor:pointer; font-size:12px; z-index:2;">🏷️ Tag</button>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Komment Szekció -->
        <div class="forum">
            <h3 style="border-bottom: 1px solid var(--glass-border); padding-bottom: 10px; margin-bottom: 20px;">
                <i class="fas fa-comments"></i> Hozzászólások
            </h3>

            <?php if ($user): ?>
                <div class="comment-form">
                    <textarea id="commentMsg" placeholder="Írj egy hozzászólást..."></textarea>
                    <button class="btn-submit" onclick="postComment()">Küldés</button>
                </div>
            <?php else: ?>
                <p style="text-align:center; color:#888; font-style:italic; margin-bottom:20px;">
                    A hozzászóláshoz <a href="<?php echo BASE_URL; ?>tracker/login.php?redirect=<?php echo urlencode($_SERVER['REQUEST_URI']); ?>" style="color:var(--accent);">be kell jelentkezned</a>.
                </p>
            <?php endif; ?>

            <div id="commentList">
                <?php foreach ($comments as $cmt): ?>
                    <div class="post" id="comment-<?php echo $cmt['id']; ?>">
                        <div class="post-header">
                            <div>
                                <span class="post-author"><?php echo escape($cmt['username']); ?></span>
                                <span class="post-date"> • <?php echo formatTimestamp($cmt['created_at']); ?></span>
                            </div>
                            
                            <!-- TÖRLÉS GOMB -->
                            <?php if ($user && ($user['id'] == $gallery['user_id'] || isAdmin())): ?>
                                <button class="delete-comment-btn" onclick="deleteComment(<?php echo $cmt['id']; ?>)" title="Törlés">
                                    <i class="fas fa-trash"></i>
                                </button>
                            <?php endif; ?>
                        </div>
                        <div class="post-message"><?php echo renderComment($cmt['message']); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

    </div>

    <!-- Lightbox Modal -->
    <div id="lightbox" class="lightbox" onclick="if(event.target===this||event.target.id==='lightbox')closeLightbox()">
        <span class="close" onclick="closeLightbox()">&times;</span>
        <span class="nav-arrow nav-left" onclick="event.stopPropagation();changeImage(-1)">&#10094;</span>
        <span class="nav-arrow nav-right" onclick="event.stopPropagation();changeImage(1)">&#10095;</span>
        <div id="lbWrapper" style="position:relative; max-width:90vw; max-height:85vh;">
            <img class="lightbox-content" id="lightbox-img" style="display:none; max-width:90vw; max-height:85vh;">
            <video class="lightbox-content" id="lightbox-vid" controls style="display:none; max-width:90vw; max-height:85vh;"></video>
            <!-- pointer-events:none a kontenéren, de a gyerekek (bbox div-ek) auto-t kapnak -->
            <div id="lbBboxContainer" style="position:absolute; top:0; left:0; width:100%; height:100%; pointer-events:none; overflow:visible;"></div>
        </div>
    </div>

    <script src="<?php echo BASE_URL; ?>assets/js/log.js"></script>
    <script>
        // JS Adatok
        window.galleryId = <?php echo $gallery['id']; ?>;
        const galleryId = window.galleryId;
        const csrfToken = '<?php echo generateCsrfToken(); ?>';
        const ajaxUrl = '<?php echo BASE_URL; ?>tracker/ajax_actions.php';
        
        // Kép lista a lightboxhoz
        const images = [
            <?php foreach ($images as $img): 
                $fileName = $img['original_name'] ?? $img['file_path'] ?? '';
                $isVideo = preg_match('/\.(mp4|webm|ogg|mov|avi|mkv)$/i', $fileName) ? 'true' : 'false';
                $stored = $img['stored_filename'] ?? basename($img['file_path'] ?? '');
                $facesJson = json_encode($facesByFile[$stored] ?? []);
            ?>
                { url: "<?php echo BASE_URL . 'data.php?id=' . $img['view_token'] . '&type=raw'; ?>", isVideo: <?php echo $isVideo; ?>, token: "<?php echo $img['view_token']; ?>", faces: <?php echo $facesJson; ?> },
            <?php endforeach; ?>
        ];
        
        let currentIndex = 0;

        // Lightbox Funkciók
        function openLightboxByToken(token) {
            const index = images.findIndex(i => i.token === token);
            if (index !== -1) openLightbox(index);
        }

        function openLightbox(index) {
            currentIndex = index;
            const lb = document.getElementById('lightbox');
            const img = document.getElementById('lightbox-img');
            const vid = document.getElementById('lightbox-vid');
            const container = document.getElementById('lbBboxContainer');
            container.innerHTML = '';
            
            const item = images[currentIndex];
            
            if (item.isVideo) {
                img.style.display = 'none';
                vid.style.display = 'block';
                vid.src = item.url;
                vid.onloadedmetadata = () => drawPublicBboxes(vid, item.faces);
                vid.play().catch(e => console.log("Autoplay prevented"));
            } else {
                if (vid) { vid.style.display = 'none'; vid.pause(); }
                img.style.display = 'block';
                img.src = item.url;
                img.onload = () => drawPublicBboxes(img, item.faces);
            }
            
            lb.classList.add('is-open');
            document.body.style.overflow = 'hidden';
            
            if (window.LogSystem) {
                window.LogSystem.logImageView(item.url.split('=').pop(), index);
            }
        }

        // Arc kiemelő szűrő logika
        let activeFilterCluster = null;
        const originalThumbnailsHtml = document.getElementById('thumbnailGrid') ? document.getElementById('thumbnailGrid').innerHTML : '';

        function filterByCluster(clusterId) {
            activeFilterCluster = clusterId;

            // Egyező thumb-item-ek megjelenítése, többi elrejtése
            const items = document.querySelectorAll('#thumbnailGrid .thumb-item');
            let matchCount = 0;
            items.forEach(item => {
                const clusters = (item.dataset.clusters || '').split(',').map(Number);
                const match = clusters.includes(clusterId);
                // Ha egyezik: marad + kék kiemelés. Ha nem: elrejtjük.
                item.style.display = match ? '' : 'none';
                if (match) {
                    item.style.outline = '3px solid #00e5ff';
                    item.style.boxShadow = '0 0 18px rgba(0,229,255,.4)';
                    item.style.borderRadius = '6px';
                    matchCount++;
                } else {
                    item.style.outline = '';
                    item.style.boxShadow = '';
                }
            });

            const label = clusterId === -1 ? 'Ismeretlen arc' : 'Személy #' + clusterId;
            document.getElementById('faceHighlightText').textContent =
                '👁️ ' + label + ' — ' + matchCount + ' kép megjelenítve';
            document.getElementById('faceHighlightPanel').style.display = 'block';

            // Lightbox bezárása + görgetés a galériához
            closeLightbox();
            document.getElementById('thumbnailGrid').scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        function clearClusterFilter() {
            activeFilterCluster = null;
            document.getElementById('faceHighlightPanel').style.display = 'none';
            document.querySelectorAll('#thumbnailGrid .thumb-item').forEach(item => {
                item.style.display = '';
                item.style.outline = '';
                item.style.boxShadow = '';
            });
        }

        function drawPublicBboxes(mediaEl, faces) {
            const container = document.getElementById('lbBboxContainer');
            container.innerHTML = '';
            if (!faces || faces.length === 0) return;

            const natW = mediaEl.naturalWidth || mediaEl.videoWidth || 0;
            const natH = mediaEl.naturalHeight || mediaEl.videoHeight || 0;
            if (!natW || !natH) {
                setTimeout(() => drawPublicBboxes(mediaEl, faces), 100);
                return;
            }

            // Animáció beszúrása, ha még nincs
            if (!document.getElementById('facePulseStyle')) {
                const style = document.createElement('style');
                style.id = 'facePulseStyle';
                style.innerHTML = `
                    @keyframes facePulse {
                        0% { box-shadow: 0 0 8px currentColor; }
                        50% { box-shadow: 0 0 25px currentColor; }
                        100% { box-shadow: 0 0 8px currentColor; }
                    }
                    .face-box-anim {
                        animation: facePulse 2s infinite ease-in-out;
                    }
                `;
                document.head.appendChild(style);
            }

            const clusterColors = ['#00e5ff','#ff3366','#00e676','#ffaa00','#a78bfa','#f472b6','#34d399','#fbbf24','#60a5fa','#a3e635','#f87171','#38bdf8','#818cf8','#e879f9','#4ade80'];
            const getColor = cid => cid === -1 ? 'rgba(255,255,255,0.7)' : clusterColors[Math.abs(cid) % clusterColors.length];

            faces.forEach(f => {
                if (!f.bbox || f.bbox.length !== 4) return;
                const [x1, y1, x2, y2] = f.bbox;
                const color = getColor(f.cluster_id);
                const isActive = activeFilterCluster === f.cluster_id;

                const box = document.createElement('div');
                box.className = 'face-box-anim';
                // background:rgba(0,0,0,0.01) is crucial so the inside of the box is clickable, not just the border!
                box.style.cssText = [
                    'position:absolute',
                    `border: ${isActive ? '4px' : '3px'} solid ${color}`,
                    `color: ${color}`,
                    `background:rgba(255,255,255,0.01)`,
                    `left:${(x1/natW)*100}%`,
                    `top:${(y1/natH)*100}%`,
                    `width:${((x2-x1)/natW)*100}%`,
                    `height:${((y2-y1)/natH)*100}%`,
                    'cursor:pointer',
                    'pointer-events:auto',
                    'transition:border-width 0.2s, background 0.2s',
                    isActive ? `outline:3px solid ${color}` : '',
                ].filter(Boolean).join(';');

                // Hover kiemelés
                box.addEventListener('mouseenter', () => {
                    box.style.background = 'rgba(255,255,255,0.1)';
                    box.style.borderWidth = '4px';
                });
                box.addEventListener('mouseleave', () => {
                    box.style.background = 'rgba(255,255,255,0.01)';
                    box.style.borderWidth = isActive ? '4px' : '3px';
                });

                // Kattintásra szűrés
                box.addEventListener('click', (e) => {
                    e.stopPropagation();
                    filterByCluster(f.cluster_id);
                });

                const label = document.createElement('div');
                // pointer-events:auto is added here
                label.style.cssText = `position:absolute;top:-26px;left:-3px;background:${color};color:#000;padding:3px 8px;font-size:12px;font-weight:bold;border-radius:4px 4px 0 0;white-space:nowrap;cursor:pointer;pointer-events:auto;`;
                label.textContent = (f.cluster_id === -1 ? '? Ismeretlen' : '★ Személy #' + f.cluster_id);
                label.title = 'Kattints a szűréshez!';
                label.addEventListener('click', (e) => { e.stopPropagation(); filterByCluster(f.cluster_id); });

                box.appendChild(label);
                container.appendChild(box);
            });
        }

        function closeLightbox() {
            document.getElementById('lightbox').classList.remove('is-open');
            document.body.style.overflow = 'auto';
            const vid = document.getElementById('lightbox-vid');
            if (vid) vid.pause();
            document.getElementById('lbBboxContainer').innerHTML = '';
        }

        function changeImage(dir) {
            currentIndex += dir;
            if (currentIndex >= images.length) currentIndex = 0;
            if (currentIndex < 0) currentIndex = images.length - 1;
            openLightbox(currentIndex); // Re-use the setup logic
        }

        // Fórum tag funkció
        function insertImageTag(token) {
            const textarea = document.getElementById('commentMsg');
            if (!textarea) return;
            const tag = '[image:' + token + ']';
            const start = textarea.selectionStart;
            const end = textarea.selectionEnd;
            const text = textarea.value;
            
            textarea.value = text.substring(0, start) + tag + text.substring(end);
            textarea.selectionStart = textarea.selectionEnd = start + tag.length;
            textarea.focus();
            
            document.querySelector('.forum').scrollIntoView({ behavior: 'smooth' });
        }

        // Billentyűzet vezérlés
        document.addEventListener('keydown', function(e) {
            if (document.getElementById('lightbox').style.display === 'block') {
                if (e.key === 'ArrowLeft') changeImage(-1);
                if (e.key === 'ArrowRight') changeImage(1);
                if (e.key === 'Escape') closeLightbox();
            }
        });

    function deleteComment(commentId) {
        if (!confirm('Biztosan törlöd ezt a hozzászólást?')) return;

        const formData = new FormData();
        formData.append('action', 'delete_gallery_comment');
        formData.append('comment_id', commentId);
        formData.append('gallery_id', galleryId); // Biztonsági ellenőrzéshez
        formData.append('csrf_token', csrfToken);

        fetch(ajaxUrl, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                // Eltávolítás az oldalról animációval
                const el = document.getElementById('comment-' + commentId);
                if (el) {
                    el.style.transition = 'all 0.3s ease';
                    el.style.opacity = '0';
                    el.style.transform = 'translateX(20px)';
                    setTimeout(() => el.remove(), 300);
                }
            } else {
                alert('Hiba: ' + data.message);
            }
        })
        .catch(err => alert('Hálózati hiba.'));
    }
        function postComment() {
            const msgInput = document.getElementById('commentMsg');
            const msg = msgInput.value.trim();
            if (!msg) return;

            // Gomb letiltása (dupla küldés ellen)
            const btn = document.querySelector('.btn-submit');
            const originalText = btn.innerText;
            btn.innerText = 'Küldés...';
            btn.disabled = true;

            const formData = new FormData();
            formData.append('action', 'submit_gallery_comment');
            formData.append('gallery_id', galleryId);
            formData.append('message', msg);
            formData.append('csrf_token', csrfToken);

            fetch(ajaxUrl, { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    // SIKER: Komment beszúrása a lista tetejére
                    const commentList = document.getElementById('commentList');
                    const newComment = document.createElement('div');
                    newComment.className = 'post';
                    newComment.innerHTML = `
                        <div class="post-header">
                            <span class="post-author">${data.comment.username}</span>
                            <span class="post-date">${data.comment.timestamp}</span>
                        </div>
                        <div class="post-message">${data.comment.message}</div>
                    `;
                    // Animációval megjelenítjük
                    newComment.style.opacity = '0';
                    commentList.prepend(newComment);
                    
                    // Fade in
                    setTimeout(() => { newComment.style.transition = 'opacity 0.5s'; newComment.style.opacity = '1'; }, 50);
                    
                    // Mező ürítése
                    msgInput.value = '';
                } else {
                    alert('Hiba: ' + data.message);
                }
            })
            .catch(err => {
                console.error(err);
                alert('Hálózati hiba történt.');
            })
            .finally(() => {
                // Gomb visszaállítása
                btn.innerText = originalText;
                btn.disabled = false;
            });
        }

        // A kistesó logolást felváltotta a LogSystem V6.0 (log.js)

    </script>
<?php endif; ?>
</body>
</html>