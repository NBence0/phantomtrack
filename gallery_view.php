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
    
    // Logolás (Nagytesó stílusban)
    logActivity('gallery_view', null, null); // Itt a gallery_id-t még bele kell hackelni a log függvénybe ha nagyon precízek akarunk lenni, de most elég az activity.
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
                    <a href="<?php echo BASE_URL; ?>tracker/galleries.php" class="btn-submit" style="text-decoration:none; font-size:0.9em;">
                        <i class="fas fa-cog"></i> Kezelés / Feltöltés
                    </a>
                </div>
            <?php endif; ?>

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

        <!-- Képrács -->
        <?php if (empty($images)): ?>
            <div style="text-align:center; padding:50px; color:#666;">
                <i class="far fa-images" style="font-size:3em; margin-bottom:10px;"></i>
                <p>Még nincsenek feltöltött képek.</p>
            </div>
        <?php else: ?>
            <div class="thumbnails">
                <?php foreach ($images as $index => $img): 
                    $thumbUrl = BASE_URL . 'thumbnails/' . $img['view_token'] . '.webp';
                    $fullUrl = BASE_URL . 'data.php?id=' . $img['view_token'] . '&type=raw'; // Biztonságos proxy
                ?>
                    <div class="thumb-item" onclick="openLightbox(<?php echo $index; ?>)">
                        <img src="<?php echo $thumbUrl; ?>" loading="lazy" alt="Kép">
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
                        <div class="post-message"><?php echo nl2br(escape($cmt['message'])); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

    </div>

    <!-- Lightbox Modal -->
    <div id="lightbox" class="lightbox">
        <span class="close" onclick="closeLightbox()">&times;</span>
        <span class="nav-arrow nav-left" onclick="changeImage(-1)">&#10094;</span>
        <span class="nav-arrow nav-right" onclick="changeImage(1)">&#10095;</span>
        <img class="lightbox-content" id="lightbox-img">
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
            <?php foreach ($images as $img): ?>
                "<?php echo BASE_URL . 'data.php?id=' . $img['view_token'] . '&type=raw'; ?>",
            <?php endforeach; ?>
        ];
        
        let currentIndex = 0;

        // Lightbox Funkciók
        function openLightbox(index) {
            currentIndex = index;
            const lb = document.getElementById('lightbox');
            const img = document.getElementById('lightbox-img');
            img.src = images[currentIndex];
            lb.style.display = 'block';
            document.body.style.overflow = 'hidden';
            
            // Logolás (God Mode)
            if (window.LogSystem) {
                window.LogSystem.logImageView(images[currentIndex].split('=').pop(), index);
            }
        }

        function closeLightbox() {
            document.getElementById('lightbox').style.display = 'none';
            document.body.style.overflow = 'auto';
        }

        function changeImage(dir) {
            currentIndex += dir;
            if (currentIndex >= images.length) currentIndex = 0;
            if (currentIndex < 0) currentIndex = images.length - 1;
            document.getElementById('lightbox-img').src = images[currentIndex];
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