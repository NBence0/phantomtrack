<?php
// Aktuális oldal és paraméterek meghatározása a kiemeléshez
$current_page = basename($_SERVER['PHP_SELF']);
$category_id_from_url = null;
if ($current_page === 'tokens.php' && isset($_GET['category_id'])) {
    $category_id_from_url = (int)$_GET['category_id'];
}

// Csak akkor kérdezzük le, ha be van lépve a felhasználó, hogy ne okozzon hibát a login oldalon
if (function_exists('getCurrentUserId') && getCurrentUserId()) {
    $dbNav = getDB();
    $categoriesNavStmt = $dbNav->prepare("SELECT id, name FROM token_categories WHERE user_id = :user_id ORDER BY name ASC");
    $categoriesNavStmt->execute([':user_id' => getCurrentUserId()]);
    $navCategories = $categoriesNavStmt->fetchAll();
} else {
    $navCategories = [];
}
?>
<nav class="sidebar glass-effect">
    <div class="sidebar-header">
        <a href="<?php echo BASE_URL; ?>admin/dashboard.php" class="logo-link">
           Phantom<span class="accent-text">Track</span>
        </a>
    </div>
    <ul class="nav-menu">
        <li <?php if ($current_page == 'dashboard.php') echo 'class="active"'; ?>>
            <a href="<?php echo BASE_URL; ?>admin/dashboard.php"><i class="fas fa-tachometer-alt"></i> Irányítópult</a>
        </li>

        <!-- TOKENEK MENÜPONT ÁTALAKÍTVA ALMENÜSRE -->
        <?php 
            $is_token_section_active = ($current_page == 'tokens.php' || $current_page == 'token_details.php');
            $has_categories = !empty($navCategories);
        ?>
        <li class="<?php if($is_token_section_active) echo 'active '; if($has_categories) echo 'has-submenu'; ?>">
            <a href="<?php echo BASE_URL; ?>admin/tokens.php"><i class="fas fa-tags"></i> Tokenek</a>
            <?php if ($has_categories): ?>
                <ul class="submenu">
                    <li <?php if ($current_page == 'tokens.php' && !isset($_GET['category_id'])) echo 'class="active"'; ?>>
                        <a href="<?php echo BASE_URL; ?>admin/tokens.php">Összes Token</a>
                    </li>
                    <?php foreach ($navCategories as $cat): ?>
                        <li <?php if ($category_id_from_url === $cat['id']) echo 'class="active"'; ?>>
                            <a href="<?php echo BASE_URL; ?>admin/tokens.php?category_id=<?php echo $cat['id']; ?>"><?php echo escape($cat['name']); ?></a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </li>
        
        <li <?php if ($current_page == 'categories.php') echo 'class="active"'; ?>>
            <a href="<?php echo BASE_URL; ?>admin/categories.php"><i class="fas fa-folder-open"></i> Kategóriák</a>
        </li>
        <li <?php if ($current_page == 'settings.php') echo 'class="active"'; ?>>
            <a href="<?php echo BASE_URL; ?>admin/settings.php"><i class="fas fa-cog"></i> Beállítások</a>
        </li>
        <?php if (isAdmin()): ?>
            <li <?php if ($current_page == 'users.php') echo 'class="active"'; ?>>
                <a href="<?php echo BASE_URL; ?>admin/users.php"><i class="fas fa-users-cog"></i> Felhasználók</a>
            </li>
        <?php endif; ?>
    </ul>
    <div class="sidebar-footer">
        <p>Bejelentkezve: <?php echo escape(getCurrentUsername()); ?></p>
        <a href="<?php echo BASE_URL; ?>admin/logout.php" class="btn btn-secondary"><i class="fas fa-sign-out-alt"></i> Kijelentkezés</a>
    </div>
</nav>