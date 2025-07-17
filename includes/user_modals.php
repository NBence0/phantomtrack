<!-- ÚJ FELHASZNÁLÓ LÉTREHOZÁSA MODÁLIS ABLAK -->
<div id="addUserModal" class="modal">
    <div class="modal-content glass-effect">
        <span class="close-btn" onclick="closeModal('addUserModal')">×</span>
        <h2>Új Felhasználó Létrehozása</h2>
        <form id="addUserForm" method="POST">
            <?php echo csrfInput(); ?>
            <input type="hidden" name="action" value="create_user_ajax">
            <div class="form-group">
                <label for="add_username">Felhasználónév:</label>
                <input type="text" id="add_username" name="username" required>
            </div>
            <div class="form-group">
                <label for="add_email">E-mail cím:</label>
                <input type="email" id="add_email" name="email" required>
            </div>
            <div class="form-group">
                <label for="add_password">Jelszó:</label>
                <input type="password" id="add_password" name="password" required>
            </div>
            <div class="form-group">
                <input type="checkbox" id="add_is_admin" name="is_admin" value="1" style="vertical-align: middle;">
                <label for="add_is_admin" style="display: inline-block; font-weight:normal;">Adminisztrátori jogosultság</label>
            </div>
            <button type="submit" class="btn btn-primary">Létrehozás</button>
        </form>
    </div>
</div>

<!-- FELHASZNÁLÓ SZERKESZTÉSE MODÁLIS ABLAK -->
<div id="editUserModal" class="modal">
    <div class="modal-content glass-effect">
        <span class="close-btn" onclick="closeModal('editUserModal')">×</span>
        <h2>Felhasználó Szerkesztése</h2>
        <form id="editUserForm" method="POST">
            <?php echo csrfInput(); ?>
            <input type="hidden" name="action" value="update_user">
            <input type="hidden" id="edit_user_id" name="user_id">
            <div class="form-group">
                <label for="edit_username">Felhasználónév:</label>
                <input type="text" id="edit_username" name="username" required>
            </div>
            <div class="form-group">
                <label for="edit_email">E-mail cím:</label>
                <input type="email" id="edit_email" name="email" required>
            </div>
            <hr><br>
            <div class="form-group">
                <label for="edit_password">Új jelszó (hagyd üresen, ha nem változik):</label>
                <input type="password" id="edit_password" name="password" autocomplete="new-password">
            </div>
            <div class="form-group">
                <input type="checkbox" id="edit_is_admin" name="is_admin" value="1">
                <label for="edit_is_admin" style="display:inline-block; font-weight:normal;">Adminisztrátori jogosultság</label>
            </div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Mentés</button>
        </form>
    </div>
</div>

<!-- Felhasználó Törlése Modális Ablak -->
<div id="deleteUserModal" class="modal">
    <div class="modal-content glass-effect">
        <span class="close-btn" onclick="closeModal('deleteUserModal')">×</span>
        <h2>Felhasználó Törlése</h2>
        <p>A(z) <strong id="userToDeleteName"></strong> nevű felhasználó törlésre kerül.</p>
        <p>Mi történjen a felhasználóhoz tartozó <strong id="userTokenCount"></strong> darab tokennel?</p>
        <form id="deleteUserForm" method="POST" action="<?php echo BASE_URL . 'tracker/users.php'; ?>">
            <?php echo csrfInput(); ?>
            <input type="hidden" name="action" value="delete_user">
            <input type="hidden" id="userToDeleteId" name="user_id_to_delete">
            <div class="form-group">
                <input type="radio" id="option_delete_tokens" name="token_action" value="delete" checked>
                <label for="option_delete_tokens">Minden token és a hozzájuk tartozó napló végleges törlése.</label>
            </div>
            <div class="form-group">
                <input type="radio" id="option_move_tokens" name="token_action" value="move">
                <label for="option_move_tokens">Tokenek átruházása egy másik felhasználóra:</label>
                <div class="custom-select-wrapper" id="userMoveSelectWrapper" style="margin-top: 10px;">
                    <select name="new_user_id" id="newUserSelect"></select>
                    <div class="select-trigger">Válassz...</div>
                    <div class="custom-options"></div>
                </div>
            </div>
            <p style="color: var(--color-warning); margin-top:20px; font-weight: bold;"><i class="fas fa-exclamation-triangle"></i> A művelet nem vonható vissza!</p>
            <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top:20px;">
                <button type="button" class="btn btn-secondary" onclick="closeModal('deleteUserModal')">Mégse</button>
                <button type="submit" class="btn btn-danger">Felhasználó Törlése</button>
            </div>
        </form>
    </div>
</div>