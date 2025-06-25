/**
 * Ez a szkript a "Tokenek" adminisztrációs oldal dinamikus funkcióit kezeli.
 *
 * Függőségek:
 *  - Egy globális 'PageConfig' objektum, amelynek tartalmaznia kell:
 *    - PageConfig.ajaxUrl (string): Az AJAX kérések végpontja.
 *    - PageConfig.availableCategories (array): A felhasználói kategóriák listája.
 *  - Egy 'showDynamicMessage' globális funkció az üzenetek megjelenítéséhez.
 *  - Egy 'handleOptionClick' globális funkció a custom select menük működéséhez.
 */

// ===================================================================
// I. KONFIGURÁCIÓ ÉS GLOBÁLIS VÁLTOZÓK
// ===================================================================

// A felhasználó összes kategóriája, amit a PHP-ből kapunk a PageConfig objektumon keresztül.
const allUserCategories = PageConfig.allUserCategories; // JAVÍTOTT KULCS


// ===================================================================
// II. TOKEN SZERKESZTÉSÉNEK LOGIKÁJA
// ===================================================================

/**
 * Megnyitja a token szerkesztő modális ablakot, és AJAX-szal betölti az adatokat.
 * A HTML-ből közvetlenül, `onclick` eseménnyel van meghívva.
 * @param {number} tokenId A szerkesztendő token azonosítója.
 */
function openEditTokenModal(tokenId) {
    const modal = document.getElementById('editTokenModal');
    const form = document.getElementById('editTokenForm');
    const trigger = form.querySelector('.select-trigger');
    
    // Először mutassunk egy "töltés" állapotot
    trigger.textContent = 'Betöltés...';
    form.reset(); // Űrlap alaphelyzetbe állítása
    modal.style.display = 'block';
    
    const formData = new FormData();
    formData.append('action', 'get_token_details');
    formData.append('token_id', tokenId);
    formData.append('csrf_token', form.querySelector('input[name="csrf_token"]').value);

    fetch(PageConfig.ajaxUrl, { // PHP helyett a PageConfig objektumot használjuk
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && data.token) {
            const token = data.token;
            // Űrlap feltöltése a kapott adatokkal
            form.querySelector('#edit_token_id').value = token.id;
            form.querySelector('#edit_token_name').value = token.name;
            form.querySelector('#edit_token_description').value = token.description;
            form.querySelector('#edit_is_active').checked = (token.is_active == 1);

            // Az egyedi legördülő menü dinamikus felépítése
            const wrapper = form.querySelector('#editCategorySelectWrapper');
            const hiddenSelect = wrapper.querySelector('select');
            const optionsContainer = wrapper.querySelector('.custom-options');

            hiddenSelect.innerHTML = ''; // Kiürítés
            optionsContainer.innerHTML = ''; // Kiürítés

            // "Nincs kategória" opció
            hiddenSelect.add(new Option('Nincs kategória', ''));
            const noCatSpan = document.createElement('span');
            noCatSpan.className = 'custom-option';
            noCatSpan.dataset.value = '';
            noCatSpan.textContent = 'Nincs kategória';
            optionsContainer.appendChild(noCatSpan);

            // Többi kategória hozzáadása
            let selectedCategoryName = 'Nincs kategória';
            allUserCategories.forEach(cat => {
                hiddenSelect.add(new Option(cat.name, cat.id));
                const optionSpan = document.createElement('span');
                optionSpan.className = 'custom-option';
                optionSpan.dataset.value = cat.id;
                optionSpan.textContent = cat.name;
                optionsContainer.appendChild(optionSpan);

                if (cat.id == token.category_id) {
                    optionSpan.classList.add('selected');
                    hiddenSelect.value = cat.id;
                    selectedCategoryName = cat.name;
                }
            });
            
            if (!token.category_id) {
                noCatSpan.classList.add('selected');
            }

            // Trigger és eseménykezelők beállítása
            trigger.textContent = selectedCategoryName;
        } else {
            alert('Hiba: ' + (data.message || 'A token adatok betöltése sikertelen.'));
            modal.style.display = 'none';
        }
    })
    .catch(error => {
        alert('Hálózati hiba történt.');
        console.error('Error:', error);
        modal.style.display = 'none';
    });
}

/**
 * Eseménykezelő a token szerkesztő űrlap elküldéséhez.
 */
const editTokenForm = document.getElementById('editTokenForm');
if (editTokenForm) {
    editTokenForm.addEventListener('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        const submitButton = this.querySelector('button[type="submit"]');
        const originalButtonText = submitButton.innerHTML;
        
        // Vizuális visszajelzés mentés közben
        submitButton.disabled = true;
        submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Mentés...';
        
        fetch(PageConfig.ajaxUrl, { // PHP helyett a PageConfig objektumot használjuk
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // SIKERES MENTÉS
                document.getElementById('editTokenModal').style.display = 'none';
                showDynamicMessage(data.message, 'success');
                setTimeout(() => window.location.reload(), 1500); // Újratöltés, hogy a felhasználó lássa a változást
            } else {
                // SIKERTELEN MENTÉS
                showDynamicMessage('Hiba: ' + (data.message || 'Ismeretlen szerverhiba.'), 'error');
            }
        })
        .catch(error => {
            // HÁLÓZATI HIBA
            showDynamicMessage('Hálózati hiba. Kérjük, ellenőrizze a kapcsolatot.', 'error');
            console.error('Error:', error);
        })
        .finally(() => {
            // A gomb állapotának visszaállítása (főleg hiba esetén fontos)
            submitButton.disabled = false;
            submitButton.innerHTML = originalButtonText;
        });
    });
}


// ===================================================================
// III. ÚJ KATEGÓRIA HOZZÁADÁSÁNAK LOGIKÁJA
// ===================================================================

/**
 * Kezeli az új kategória létrehozását a "Token hozzáadása" modális ablakban.
 */
function handleAddNewCategory() {
    const newCategoryNameInput = document.getElementById('new_category_name_modal');
    const newCategoryName = newCategoryNameInput.value.trim();
    const responseEl = document.getElementById('category_ajax_response');
    const form = document.getElementById('addTokenForm');
    const csrfToken = form.querySelector('input[name="csrf_token"]').value;

    if (newCategoryName === '') {
        responseEl.textContent = 'Kérlek, add meg az új kategória nevét!';
        responseEl.style.color = 'var(--color-warning)';
        return;
    }

    responseEl.textContent = 'Feldolgozás...';
    responseEl.style.color = 'var(--text-secondary)';

    const formData = new FormData();
    formData.append('action', 'create_category_ajax');
    formData.append('category_name', newCategoryName);
    formData.append('csrf_token', csrfToken);

    fetch(PageConfig.ajaxUrl, { // PHP helyett a PageConfig objektumot használjuk
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && data.category) {
            responseEl.textContent = `Siker! '${data.category.name}' hozzáadva.`;
            responseEl.style.color = 'var(--color-success)';

            const wrapper = document.getElementById('token_category_id_modal').closest('.custom-select-wrapper');
            const hiddenSelect = wrapper.querySelector('select');
            const optionsContainer = wrapper.querySelector('.custom-options');

            // 1. Új opció hozzáadása a rejtett select-hez
            const newHiddenOption = new Option(data.category.name, data.category.id);
            hiddenSelect.appendChild(newHiddenOption);

            // 2. Új opció hozzáadása a látható custom listához
            const newCustomOption = document.createElement('span');
            newCustomOption.classList.add('custom-option');
            newCustomOption.dataset.value = data.category.id;
            newCustomOption.textContent = data.category.name;
            optionsContainer.appendChild(newCustomOption);
            
            // 3. Az új elemhez is hozzáadjuk a kattintás eseménykezelőt
            newCustomOption.addEventListener('click', () => handleOptionClick(newCustomOption, wrapper));

            // 4. Az új opció automatikus kiválasztása egy kattintás szimulálásával
            newCustomOption.click();

            newCategoryNameInput.value = '';
        } else {
            responseEl.textContent = 'Hiba: ' + (data.message || 'Ismeretlen hiba.');
            responseEl.style.color = 'var(--color-error)';
        }
    })
    .catch(error => {
        responseEl.textContent = 'Hálózati hiba történt.';
        responseEl.style.color = 'var(--color-error)';
        console.error('Error:', error);
    });
}

/**
 * Eseménykezelő regisztrálása az "Új kategória" gombhoz.
 * A DOM teljes betöltődése után fut le.
 */
document.addEventListener('DOMContentLoaded', function() {
    const addNewCategoryBtn = document.getElementById('add_new_category_btn');
    if (addNewCategoryBtn) {
        addNewCategoryBtn.addEventListener('click', handleAddNewCategory);
    }
});



// ===================================================================
// IV. TOKEN másolása gomb
// ===================================================================

function openGetCodeModal(tokenValue) {
    // PageConfig használata, ahogy korábban megbeszéltük
    const pixelUrl = `${PageConfig.baseUrl}pixel.php?token=${tokenValue}`;
    
    // A kódelemek feltöltése
    document.getElementById('snippet-html').textContent = `<img src="${pixelUrl}" alt="pixel" width="1" height="1" border="0">`;
    document.getElementById('snippet-markdown').textContent = `![pixel](${pixelUrl})`;
    document.getElementById('snippet-bbcode').textContent = `[img]${pixelUrl}[/img]`;
    
    // Prism API hívása a frissen beillesztett kód highlightolására
    Prism.highlightAllUnder(document.getElementById('getCodeModal'));
    
    // Modális ablak megjelenítése
    document.getElementById('getCodeModal').style.display = 'block';
}

// A copySnippet függvényt meghagyhatjuk más célokra, de innen már nem hívjuk.

function copySnippet(elementId) {
    const codeElement = document.getElementById(elementId);
    const textToCopy = codeElement.textContent;

    // Ellenőrizzük, hogy a biztonságos Clipboard API elérhető-e
    if (navigator.clipboard && window.isSecureContext) {
        // Ha igen, ezt a modern, aszinkron módszert használjuk
        navigator.clipboard.writeText(textToCopy).then(() => {
            showDynamicMessage('Kód vágólapra másolva!', 'success');
        }).catch(err => {
            console.error('Hiba a vágólapra másolás során (Clipboard API): ', err);
            showDynamicMessage('Hiba a másolás során.', 'error');
        });
    } else {
        // Ha nem (pl. HTTP-n vagy régebbi böngészőben), a régi, szinkron módszert használjuk
        const textArea = document.createElement('textarea');
        textArea.value = textToCopy;
        
        // Elrejtjük a textarea-t a képernyőről
        textArea.style.position = 'fixed';
        textArea.style.top = '0';
        textArea.style.left = '0';
        textArea.style.opacity = '0';

        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();

        try {
            const successful = document.execCommand('copy');
            if (successful) {
                showDynamicMessage('Kód vágólapra másolva!', 'success');
            } else {
                showDynamicMessage('A másolás nem sikerült.', 'error');
            }
        } catch (err) {
            console.error('Hiba a vágólapra másolás során (execCommand): ', err);
            showDynamicMessage('Hiba a másolás során.', 'error');
        }

        document.body.removeChild(textArea);
    }
}