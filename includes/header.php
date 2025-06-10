<?php
require_once __DIR__ . '/../config.php'; // Biztosítja, hogy a BASE_URL elérhető legyen
require_once __DIR__ . '/auth.php';      // requireLogin itt hívható
require_once __DIR__ . '/functions.php'; // escape() függvény
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($pageTitle) ? escape($pageTitle) . ' - ' : ''; ?>PhantomTrack</title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/style.css">
    <!-- FontAwesome CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

</head>
<body>
    <div class="page-container">
        <?php if (isLoggedIn()): // Navigáció csak bejelentkezett felhasználóknak ?>
            <?php include __DIR__ . '/nav.php'; ?>
        <?php endif; ?>
        <main class="main-content">
            <?php if (isset($_SESSION['flash_message'])): ?>
                <div class="message <?php echo escape($_SESSION['flash_message_type'] ?? 'info'); ?>-message">
                    <?php echo escape($_SESSION['flash_message']); ?>
                </div>
                <?php unset($_SESSION['flash_message'], $_SESSION['flash_message_type']); ?>
            <?php endif; ?>