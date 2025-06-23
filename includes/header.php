<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($pageTitle) ? escape($pageTitle) . ' - ' : ''; ?>PhantomTrack</title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/style.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/themes/dark.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/themes/prism-okaidia.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/plugins/toolbar/prism-toolbar.min.css">
</head>
<body class="admin-layout"> <!-- Osztály az admin nézethez -->
    <div class="page-wrapper"> <!-- Ez a fő flex konténer -->
        <?php if (isLoggedIn()): ?>
            <?php include __DIR__ . '/nav.php'; ?>
            <button id="mobileMenuToggle" class="sidebar-toggle" aria-label="Toggle navigation" aria-expanded="false">
                <i class="fas fa-bars"></i><i class="fas fa-times"></i>
            </button>
        <?php endif; ?>
        <main class="main-content">
            <?php if (isset($_SESSION['flash_message'])): ?>
                <div class="message <?php echo escape($_SESSION['flash_message_type'] ?? 'info'); ?>-message">
                    <?php echo escape($_SESSION['flash_message']); ?>
                </div>
                <?php unset($_SESSION['flash_message'], $_SESSION['flash_message_type']); ?>
            <?php endif; ?>