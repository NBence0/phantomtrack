<?php
// === Fájl: includes/sidebar.php ===

$current_page = basename($_SERVER['PHP_SELF']);
$current_uri = $_SERVER['REQUEST_URI'];

// Kategóriák lekérése a dinamikus menühöz
$navCategories = [];
if (function_exists('getCurrentUserId') && getCurrentUserId()) {
    $dbNav = getDB();
    $categoriesNavStmt = $dbNav->prepare("SELECT id, name FROM token_categories WHERE user_id = :user_id ORDER BY name ASC");
    $categoriesNavStmt->execute([':user_id' => getCurrentUserId()]);
    $navCategories = $categoriesNavStmt->fetchAll();
}

$category_id_from_url = isset($_GET['category_id']) ? (int)$_GET['category_id'] : null;
?>
<nav class="sidebar glass-effect">
    <div class="sidebar-header">
        <a href="<?php echo BASE_URL; ?>tracker/dashboard.php" class="logo-link">
           Phantom<span class="accent-text">Track</span>
        </a>
    </div>
    
    <ul class="nav-menu">
        <!-- FŐMENÜ -->
        <li <?php if ($current_page == 'dashboard.php') echo 'class="active"'; ?>>
            <a href="<?php echo BASE_URL; ?>tracker/dashboard.php"><i class="fas fa-tachometer-alt"></i><span>Irányítópult</span></a>
        </li>
        
        <li <?php if ($current_page == 'files.php') echo 'class="active"'; ?>>
            <a href="<?php echo BASE_URL; ?>tracker/files.php"><i class="fas fa-folder-open"></i><span>Fájlkezelő</span></a>
        </li>

        <li <?php if (strpos($current_uri, 'galleries.php') !== false) echo 'class="active"'; ?>>
            <a href="<?php echo BASE_URL; ?>tracker/galleries.php"><i class="fas fa-images"></i><span>Galériák</span></a>
        </li>

        <!-- TRACKEREK (Tokenek + Bekérők) -->
        <li <?php if ($current_page == 'tokens.php') echo 'class="active"'; ?>>
            <a href="<?php echo BASE_URL; ?>tracker/tokens.php">
                <i class="fas fa-eye"></i><span>Tokenek</span>
            </a>
        </li>

        <!-- ÚJ: Fájlbekérők -->
        <li <?php if ($current_page == 'file_requests.php') echo 'class="active"'; ?>>
            <a href="<?php echo BASE_URL; ?>tracker/file_requests.php">
                <i class="fas fa-inbox"></i><span>Fájlbekérők</span>
            </a>
        </li>
        
        <!-- KATEGÓRIÁK -->
        <?php $is_cat_active = ($current_page == 'categories.php' || $category_id_from_url !== null); ?>
        <li class="<?php if($is_cat_active) echo 'active '; if(!empty($navCategories)) echo 'has-submenu'; ?>">
            <a href="<?php echo BASE_URL; ?>tracker/categories.php"><i class="fas fa-tags"></i><span>Kategóriák</span></a>
            <?php if (!empty($navCategories)): ?>
                <ul class="submenu">
                    <li class="submenu-header" style="padding:5px 15px; font-size:0.8em; color:#666; text-transform:uppercase;">Szűrés (Tokenek):</li>
                    <?php foreach ($navCategories as $cat): ?>
                        <li <?php if ($category_id_from_url === $cat['id']) echo 'class="active"'; ?>>
                            <a href="<?php echo BASE_URL; ?>tracker/tokens.php?category_id=<?php echo $cat['id']; ?>">
                                <?php echo escape($cat['name']); ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </li>
        
        <!-- RENDSZER -->
        <li class="menu-divider"><hr style="border-color:rgba(255,255,255,0.1); margin:10px 0;"></li>
        
        <li <?php if ($current_page == 'settings.php') echo 'class="active"'; ?>>
            <a href="<?php echo BASE_URL; ?>tracker/settings.php"><i class="fas fa-cog"></i><span>Beállítások</span></a>
        </li>
        
        <?php if (isAdmin()): ?>
            <li <?php if ($current_page == 'users.php' || $current_page == 'user_manager.php') echo 'class="active"'; ?>>
                <a href="<?php echo BASE_URL; ?>tracker/users.php"><i class="fas fa-users-cog"></i><span>Felhasználók</span></a>
            </li>
        <?php endif; ?>
    </ul>
    
    <div class="sidebar-footer">
        <p>Bejelentkezve: <strong><?php echo escape(getCurrentUsername()); ?></strong></p>
        <a href="<?php echo BASE_URL; ?>tracker/logout.php" class="btn btn-secondary"><i class="fas fa-sign-out-alt"></i><span>Kijelentkezés</span></a>
    </div>
</nav>