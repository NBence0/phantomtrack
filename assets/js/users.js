/**
 * Ez a szkript a "Felhasználók" adminisztrációs oldal dinamikus funkcióit kezeli.
 *
 * Függőségek:
 *  - Egy globális 'UserPageConfig' objektum, amelynek tartalmaznia kell:
 *    - UserPageConfig.ajaxUrl (string): Az AJAX kérések végpontja.
 *    - UserPageConfig.allUsersForMove (array): A felhasználók listája (id, username).
 *  - Egy 'showDynamicMessage' globális funkció az üzenetek megjelenítéséhez.
 *  - Egy 'handleOptionClick' globális funkció a custom select menük működéséhez (ha van ilyen).
 */

// ===================================================================
// I. GLOBÁLISAN HÍVOTT FÜGGVÉNYEK (HTML onclick-hez)
// ===================================================================

/**
 * Megnyitja a felhasználó szerkesztő modális ablakot és betölti az adatait.
 * A HTML-ből `onclick` attribútummal hívódik.
 * @param {number} userId A szerkesztendő felhasználó azonosítója.
 */
function openEditUserModal(userId) {
    const modal = document.getElementById('editUserModal');
    if (!modal) return;
    
    const form = document.getElementById('editUserForm');
    form.reset();
    form.querySelector('#edit_username').value = 'Betöltés...';
    modal.style.display = 'block';

    const formData = new FormData();
    formData.append('action', 'get_user_details');
    formData.append('user_id', userId);
    formData.append('csrf_token', form.querySelector('input[name="csrf_token"]').value);

    fetch(UserPageConfig.ajaxUrl, { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => {
            if (data.success && data.user) {
                const user = data.user;
                form.querySelector('#edit_user_id').value = user.id;
                form.querySelector('#edit_username').value = user.username;
                form.querySelector('#edit_email').value = user.email;
                form.querySelector('#edit_is_admin').checked = (user.is_admin == 1);
            } else {
                showDynamicMessage(data.message || 'Hiba a felhasználói adatok betöltésekor.', 'error');
                closeModal('editUserModal');
            }
        })
        .catch(error => {
            console.error('Fetch error:', error);
            showDynamicMessage('Hálózati hiba történt a felhasználói adatok betöltésekor.', 'error');
            closeModal('editUserModal');
        });
}

/**
 * Megnyitja a felhasználó törlő modális ablakot és előkészíti azt.
 * A HTML-ből `onclick` attribútummal hívódik.
 * @param {number} userId A törlendő felhasználó ID-ja.
 * @param {string} userName A törlendő felhasználó neve.
 * @param {number} tokenCount A felhasználóhoz tartozó tokenek száma.
 */
function openDeleteUserModal(userId, userName, tokenCount) {
    const modal = document.getElementById('deleteUserModal');
    if (!modal) return;

    // --- Modális ablak feltöltése adatokkal ---
    modal.querySelector('#userToDeleteId').value = userId;
    modal.querySelector('#userToDeleteName').innerText = userName;
    modal.querySelector('#userTokenCount').innerText = tokenCount;

    // --- Legördülő menü előkészítése ---
    const wrapper = modal.querySelector('#userMoveSelectWrapper');
    const hiddenSelect = wrapper.querySelector('select');
    const optionsContainer = wrapper.querySelector('.custom-options');
    const trigger = wrapper.querySelector('.select-trigger');

    // Előző opciók törlése és alaphelyzetbe állítás
    hiddenSelect.innerHTML = '<option value="">Válassz egy felhasználót...</option>';
    optionsContainer.innerHTML = '<span class="custom-option" data-value="">Válassz egy felhasználót...</span>';
    trigger.textContent = 'Válassz egy felhasználót...';
    
    // Elérhető felhasználók szűrése (saját magát ne listázzuk)
    const availableUsers = UserPageConfig.allUsersForMove.filter(user => user.id != userId);
    
    availableUsers.forEach(user => {
        hiddenSelect.add(new Option(user.username, user.id));
        const newOptionSpan = document.createElement('span');
        newOptionSpan.className = 'custom-option';
        newOptionSpan.dataset.value = user.id;
        newOptionSpan.textContent = user.username;
        optionsContainer.appendChild(newOptionSpan);
        // Itt kellene a handleOptionClick eseménykezelőt is hozzáadni, ha a custom select igényli
    });

    // --- Rádiógombok állapotának beállítása ---
    const moveRadio = modal.querySelector('#option_move_tokens');
    const deleteRadio = modal.querySelector('#option_delete_tokens');

    if (availableUsers.length === 0) {
        // Ha nincs más felhasználó, csak törölni lehet a tokeneket
        moveRadio.disabled = true;
        deleteRadio.checked = true;
        wrapper.style.opacity = '0.5';
        wrapper.style.pointerEvents = 'none'; // A legördülő menü letiltása
    } else {
        // Ha van más felhasználó, az áthelyezés az alapértelmezett
        moveRadio.disabled = false;
        moveRadio.checked = true; // Alapértelmezetten az áthelyezés legyen kiválasztva
        deleteRadio.checked = false;
        wrapper.style.opacity = '1';
        wrapper.style.pointerEvents = 'auto'; // A legördülő menü engedélyezése
    }
    
    modal.style.display = 'block';
}

/**
 * Bezár egy megadott azonosítójú modális ablakot.
 * A HTML-ből `onclick` attribútummal hívódik.
 * @param {string} modalId A bezárandó modális ablak ID-ja.
 */
function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.style.display = 'none';
    }
}


// ===================================================================
// II. ESEMÉNYKEZELŐK REGISZTRÁLÁSA
// ===================================================================

document.addEventListener('DOMContentLoaded', function() {
    
    /**
     * Általános űrlapkezelő funkció, ami regisztrál egy submit eseményfigyelőt.
     * @param {string} formId Az űrlap ID-ja.
     * @param {boolean} isAjax Meghatározza, hogy AJAX-szal vagy hagyományosan küldjük-e az űrlapot.
     */
    function handleFormSubmit(formId, isAjax = true) {
        const form = document.getElementById(formId);
        if (!form) return;

        form.addEventListener('submit', function(event) {
            // Hagyományos (nem AJAX) beküldés esete
            if (!isAjax) {
                // Itt lehetne a jövőben validáció, pl. törlés megerősítése
                console.log(`Form '${formId}' submitted without AJAX.`);
                return;
            }

            // AJAX beküldés esete
            event.preventDefault();
            const formData = new FormData(this);
            const submitButton = this.querySelector('button[type="submit"]');
            const originalButtonText = submitButton.innerHTML;

            // Vizuális visszajelzés
            submitButton.disabled = true;
            submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Feldolgozás...';

            fetch(UserPageConfig.ajaxUrl, { method: 'POST', body: formData })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const modal = form.closest('.modal');
                        if (modal) {
                            modal.style.display = 'none';
                        }
                        showDynamicMessage(data.message, 'success');
                        setTimeout(() => window.location.reload(), 1500);
                    } else {
                        showDynamicMessage(data.message || 'Ismeretlen hiba történt.', 'error');
                    }
                })
                .catch(error => {
                    console.error('Fetch error:', error);
                    showDynamicMessage('Hálózati hiba történt.', 'error');
                })
                .finally(() => {
                    // Gomb visszaállítása
                    submitButton.disabled = false;
                    submitButton.innerHTML = originalButtonText;
                });
        });
    }

    // --- Eseménykezelők regisztrálása az űrlapokra ---
    handleFormSubmit('addUserForm'); // Ez AJAX-szal küldi
    handleFormSubmit('editUserForm'); // Ez is AJAX-szal küldi (feltételezve, hogy létezik)
    handleFormSubmit('deleteUserForm', false); // Ez hagyományos POST kérést indít
});