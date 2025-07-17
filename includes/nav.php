<?php
// Aktuális oldal és paraméterek meghatározása a kiemeléshez
$current_page = basename($_SERVER['PHP_SELF']);

// Kategória ID kiolvasása az URL-ből, ha van, a kiemeléshez
$category_id_from_url = null;
if (in_array($current_page, ['tokens.php', 'files.php']) && isset($_GET['category_id'])) {
    $category_id_from_url = (int)$_GET['category_id'];
}

// Csak akkor kérdezzük le a kategóriákat, ha be van lépve a felhasználó
$navCategories = [];
if (function_exists('getCurrentUserId') && getCurrentUserId()) {
    $dbNav = getDB();
    $categoriesNavStmt = $dbNav->prepare("SELECT id, name FROM token_categories WHERE user_id = :user_id ORDER BY name ASC");
    $categoriesNavStmt->execute([':user_id' => getCurrentUserId()]);
    $navCategories = $categoriesNavStmt->fetchAll();
}
?>
<nav class="sidebar glass-effect">
    <div class="sidebar-header">
        <a href="<?php echo BASE_URL; ?>tracker/dashboard.php" class="logo-link">
           Phantom<span class="accent-text">Track</span>
        </a>
    </div>
    <ul class="nav-menu">
        <li <?php if ($current_page == 'dashboard.php') echo 'class="active"'; ?>>
            <a href="<?php echo BASE_URL; ?>tracker/dashboard.php" title="Irányítópult"><i class="fas fa-tachometer-alt"></i><span>Irányítópult</span></a>
        </li>
        
        <li <?php if ($current_page == 'files.php') echo 'class="active"'; ?>>
            <a href="<?php echo BASE_URL; ?>tracker/files.php" title="Fájlkezelő"><i class="fas fa-folder-open"></i><span>Fájlkezelő</span></a>
        </li>

        <!-- TRACKEREK SZEKCIÓ -->
        <?php 
            $tracker_pages = ['tokens.php', 'file_requests.php', 'permanent_requests.php', 'token_details.php'];
            $is_tracker_section_active = in_array($current_page, $tracker_pages);
        ?>
        <li class="<?php if($is_tracker_section_active) echo 'active '; ?>has-submenu">
            <a href="#" title="Trackerek és Fájlbekérők"><i class="fas fa-satellite-dish"></i><span>Trackerek</span></a>
            <ul class="submenu">
                <li <?php if ($current_page == 'tokens.php') echo 'class="active"'; ?>>
                    <a href="<?php echo BASE_URL; ?>tracker/tokens.php" title="Követő Pixelek"><i class="fas fa-crosshairs"></i><span>Tokenek</span></a>
                </li>
                <li <?php if ($current_page == 'file_requests.php') echo 'class="active"'; ?>>
                    <a href="<?php echo BASE_URL; ?>tracker/file_requests.php" title="Egyszeri Fájlbekérő Linkek"><i class="fas fa-file-import"></i><span>Egyszeri Bekérők</span></a>
                </li>
                 <li <?php if ($current_page == 'permanent_requests.php') echo 'class="active"'; ?>>
                    <a href="<?php echo BASE_URL; ?>tracker/permanent_requests.php" title="Állandó Feltöltő Link"><i class="fas fa-link"></i><span>Állandó Link</span></a>
                </li>
            </ul>
        </li>
        
        <!-- KATEGÓRIÁK (DINAMIKUS ALMENÜVEL) -->
        <?php $is_category_section_active = ($current_page == 'categories.php' || (in_array($current_page, ['tokens.php', 'files.php']) && $category_id_from_url !== null)); ?>
        <li class="<?php if($is_category_section_active) echo 'active '; if (!empty($navCategories)) echo 'has-submenu'; ?>">
            <a href="<?php echo BASE_URL; ?>tracker/categories.php" title="Kategóriák kezelése"><i class="fas fa-tags"></i><span>Kategóriák</span></a>
            <?php if (!empty($navCategories)): ?>
                <ul class="submenu">
                    <!-- Link, ami az összes elemet mutatja az adott típusból (pl. "Összes Pixel Követő") -->
                    <li <?php if ((in_array($current_page, ['tokens.php', 'files.php'])) && $category_id_from_url === null) echo 'class="active"'; ?>>
                        <a href="<?php echo BASE_URL . 'tracker/' . ($current_page == 'files.php' ? 'files.php' : 'tokens.php'); ?>" title="Minden elem kategória nélkül">Összes (aktuális nézet)</a>
                    </li>
                    <li class="submenu-divider"><hr></li>
                    <?php foreach ($navCategories as $cat): ?>
                        <li <?php if ($category_id_from_url === $cat['id']) echo 'class="active"'; ?>>
                             <!-- A link attól függően változik, hogy a files.php-n vagy a tokens.php-n vagyunk -->
                            <a href="<?php echo BASE_URL . 'tracker/' . ($current_page == 'files.php' ? 'files.php' : 'tokens.php'); ?>?category_id=<?php echo $cat['id']; ?>"><?php echo escape($cat['name']); ?></a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </li>
        
        <li <?php if ($current_page == 'settings.php') echo 'class="active"'; ?>>
            <a href="<?php echo BASE_URL; ?>tracker/settings.php" title="Beállítások"><i class="fas fa-cog"></i><span>Beállítások</span></a>
        </li>
        <?php if (isAdmin()): ?>
            <li <?php if ($current_page == 'users.php') echo 'class="active"'; ?>>
                <a href="<?php echo BASE_URL; ?>tracker/users.php" title="Felhasználók"><i class="fas fa-users-cog"></i><span>Felhasználók</span></a>
            </li>
        <?php endif; ?>
    </ul>
    <div class="sidebar-footer">
        <p>Bejelentkezve: <?php echo escape(getCurrentUsername()); ?></p>
        <a href="<?php echo BASE_URL; ?>tracker/logout.php" class="btn btn-secondary"><i class="fas fa-sign-out-alt"></i><span>Kijelentkezés</span></a>
    </div>
</nav>