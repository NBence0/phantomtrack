<!-- Exportálás Folyamatban Modális Ablak -->
<div id="exportInProgressModal" class="modal" style="display: none;">
    <div class="modal-content glass-effect" style="max-width: 400px; text-align: center;">
        <h2 style="color: var(--accent-primary);">Exportálás folyamatban...</h2>
        <p style="color: var(--text-secondary); margin-top: 15px;">Kérjük, várjon, amíg a rendszer előkészíti a fájlt a letöltésre. Ez nagyobb adatmennyiség esetén több másodpercet is igénybe vehet.</p>
        <div class="spinner-container" style="margin-top: 25px; font-size: 3em; color: var(--accent-secondary);">
            <i class="fas fa-spinner fa-spin"></i>
        </div>
    </div>
</div>


</main> <!-- .main-content -->
    </div> <!-- .page-wrapper -->

    <footer class="site-footer glass-effect">
        <div class="footer-links">
            <a href="<?php echo BASE_URL; ?>public/terms.php">Általános Szerződési Feltételek</a>
            <span class="footer-separator">|</span>
            <a href="<?php echo BASE_URL; ?>public/privacy.php">Adatkezelési Nyilatkozat</a>
            <span class="footer-separator">|</span>
            <a href="<?php echo BASE_URL; ?>public/contact.php">Kapcsolat</a>
        </div>
        <div class="footer-copy">
            Under Development © <?php echo date('Y'); ?> All rights reserved: NBence
        </div>
    </footer>

    <!-- SCRIPTEK -->
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://npmcdn.com/flatpickr/dist/l10n/hu.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns/dist/chartjs-adapter-date-fns.bundle.min.js"></script>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-core.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/plugins/autoloader/prism-autoloader.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/plugins/toolbar/prism-toolbar.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/plugins/copy-to-clipboard/prism-copy-to-clipboard.min.js"></script>

    <script src="<?php echo BASE_URL; ?>assets/js/script.js"></script>
    <script src="<?php echo BASE_URL; ?>assets/js/smart_table.js"></script>
</body>
</html>