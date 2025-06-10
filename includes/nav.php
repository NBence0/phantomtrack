<?php
// Aktuális oldal meghatározása a kiemeléshez
$current_page = basename($_SERVER['PHP_SELF']);
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
        <li <?php if ($current_page == 'tokens.php' || $current_page == 'token_details.php') echo 'class="active"'; ?>>
            <a href="<?php echo BASE_URL; ?>admin/tokens.php"><i class="fas fa-tags"></i> Tokenek</a>
        </li>
        <li <?php if ($current_page == 'settings.php') echo 'class="active"'; ?>>
            <a href="<?php echo BASE_URL; ?>admin/settings.php"><i class="fas fa-cog"></i> Beállítások</a>
        </li>
    </ul>
    <div class="sidebar-footer">
        <p>Bejelentkezve: <?php echo escape(getCurrentUsername()); ?></p>
        <a href="<?php echo BASE_URL; ?>admin/logout.php" class="btn btn-secondary"><i class="fas fa-sign-out-alt"></i> Kijelentkezés</a>
    </div>
</nav>