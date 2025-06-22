<?php
// Helye: public/contact.php

require_once __DIR__ . '/../includes/header_public.php'; 
$pageTitle = "Kapcsolat";
?>

<div class="content-header">
    <h1><?php echo escape($pageTitle); ?></h1>
</div>

<div class="form-container glass-effect" style="max-width: 800px; margin: 20px auto; padding: var(--card-padding);">
    
    <div class="message info-message">
        A kapcsolatfelvételi űrlap jelenleg fejlesztés alatt áll.
    </div>
    
    <!-- Az űrlap le van tiltva, csak a dizájnt mutatja -->
    <fieldset disabled>
        <form method="POST" action="">
            <div class="form-group">
                <label for="name">Név:</label>
                <input type="text" id="name" name="name" required placeholder="Az Ön neve...">
            </div>
            <div class="form-group">
                <label for="email">Email cím:</label>
                <input type="email" id="email" name="email" required placeholder="az_on_email@cime.hu">
            </div>
            <div class="form-group">
                <label for="subject">Tárgy:</label>
                <input type="text" id="subject" name="subject" required>
            </div>
            <div class="form-group">
                <label for="message">Üzenet:</label>
                <textarea id="message" name="message" rows="6" required></textarea>
            </div>
            <button type="submit" class="btn btn-primary">Üzenet Küldése</button>
        </form>
    </fieldset>

</div>

<?php require_once __DIR__ . '/../includes/footer_public.php'; ?>