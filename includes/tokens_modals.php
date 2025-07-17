<div id="addTokenModal" class="modal">
    <div class="modal-content glass-effect">
        <span class="close-btn" onclick="document.getElementById('addTokenModal').style.display='none'">×</span>
        <h2>Új Követő Token Létrehozása</h2>
        <form method="POST" action="<?php echo BASE_URL; ?>tracker/tokens.php" id="addTokenForm">
            <?php echo csrfInput(); ?>
            <input type="hidden" name="action" value="create_token">
            
            <div class="form-group">
                <label for="token_name">Token Neve:</label>
                <input type="text" id="token_name" name="token_name" required>
            </div>
            
            <div class="form-group">
                <label for="token_description">Leírás (opcionális):</label>
                <textarea id="token_description" name="token_description" rows="3"></textarea>
            </div>
            
            <div class="form-group">
                <label>Kategória:</label>
                <div class="custom-select-wrapper">
                    <!-- Rejtett, funkcionális select -->
                    <select id="token_category_id_modal" name="token_category_id">
                        <option value="" selected>Nincs kategória</option>
                        <?php if (isset($availableCategories)): foreach ($availableCategories as $cat): ?>
                            <option value="<?php echo $cat['id']; ?>"><?php echo escape($cat['name']); ?></option>
                        <?php endforeach; endif; ?>
                    </select>

                    <!-- Látható trigger -->
                    <div class="select-trigger">Nincs kategória</div>

                    <!-- Látható, stílusozott opciók -->
                    <div class="custom-options">
                        <span class="custom-option selected" data-value="">Nincs kategória</span>
                        <?php if (isset($availableCategories)): foreach ($availableCategories as $cat): ?>
                            <span class="custom-option" data-value="<?php echo $cat['id']; ?>"><?php echo escape($cat['name']); ?></span>
                        <?php endforeach; endif; ?>
                    </div>
                </div>
                
                <!-- Új kategória létrehozó -->
                <div style="margin-top:10px; display:flex; gap:10px;">
                    <input type="text" id="new_category_name_modal" placeholder="Vagy adj meg egy újat..." style="flex-grow:1;">
                    <button type="button" class="btn btn-secondary btn-small" id="add_new_category_btn">Hozzáadás</button>
                </div>
                <small id="category_ajax_response" style="display:block; margin-top:5px; min-height: 1.2em;"></small>
            </div>
            
            <button type="submit" class="btn btn-primary">Létrehozás</button>
        </form>
    </div>
</div>

<!-- Token Szerkesztése Modális Ablak -->
<div id="editTokenModal" class="modal">
    <div class="modal-content glass-effect">
        <span class="close-btn" onclick="document.getElementById('editTokenModal').style.display='none'">×</span>
        <h2>Token Szerkesztése</h2>
        
        <!-- A formot a JS fogja feltölteni és kezelni -->
        <form id="editTokenForm" method="POST">
            <?php echo csrfInput(); ?>
            <input type="hidden" name="action" value="update_token">
            <input type="hidden" id="edit_token_id" name="token_id">

            <div class="form-group">
                <label for="edit_token_name">Token Neve:</label>
                <input type="text" id="edit_token_name" name="token_name" required>
            </div>
            
            <div class="form-group">
                <label for="edit_token_description">Leírás:</label>
                <textarea id="edit_token_description" name="token_description" rows="4"></textarea>
            </div>
            
            <div class="form-group">
                <label>Kategória:</label>
                <!-- Ezt a select-et a JS fogja feltölteni -->
                <div class="custom-select-wrapper" id="editCategorySelectWrapper">
                    <select id="edit_token_category_id" name="token_category_id">
                        <!-- JS tölti fel -->
                    </select>
                    <div class="select-trigger">Betöltés...</div>
                    <div class="custom-options"></div>
                </div>
            </div>
            
            <div class="form-group">
                <input type="checkbox" id="edit_is_active" name="is_active" value="1">
                <label for="edit_is_active" style="display: inline-block; font-weight:normal;">Aktív</label>
            </div>
            
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Mentés</button>
        </form>
    </div>
</div>

<!-- Get Code Modal -->
<div id="getCodeModal" class="modal">
    <div class="modal-content glass-effect">
        <span class="close-btn" onclick="document.getElementById('getCodeModal').style.display='none'">×</span>
        <h2>Pixel Kód Snippetek</h2>
        <p>Másold ki és illeszd be a megfelelő kódot a tartalmadba.</p>
        
        <div class="code-snippets-container">
            <!-- HTML Snippet -->
            <h4>HTML (Weboldalakhoz, E-mailekhez)</h4>
            <!-- A pre tagre is kell class a toolbarhoz -->
            <pre class="language-html"><code id="snippet-html" class="language-html"></code></pre>

            <!-- Markdown Snippet -->
            <h4>Markdown (pl. GitHub README.md)</h4>
            <pre class="language-markdown"><code id="snippet-markdown" class="language-markdown"></code></pre>
            
            <!-- BBCode Snippet -->
            <h4>BBCode (Fórumokhoz)</h4>
            <pre class="language-bbcode"><code id="snippet-bbcode" class="language-bbcode"></code></pre>
        </div>
    </div>
</div>