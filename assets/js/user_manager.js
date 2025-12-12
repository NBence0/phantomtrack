// === assets/js/user_manager.js ===

let currentTab = 'tokens'; // Alapértelmezett fül

// --- Tab váltás ---
function switchTab(tabName) {
    currentTab = tabName;
    
    // Gombok frissítése
    document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
    event.target.classList.add('active');
    
    // Tartalom frissítése
    document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
    document.getElementById('tab-' + tabName).classList.add('active');
    
    updateSelectionCount();
}

// --- Kijelölés kezelése ---
function toggleSelectAll(checkbox, type) {
    const items = document.querySelectorAll(`.select-${type}`);
    items.forEach(item => {
        item.checked = checkbox.checked;
    });
    updateSelectionCount();
}

// Minden checkbox változást figyelünk a számlálóhoz
document.addEventListener('change', function(e) {
    if (e.target.classList.contains('select-item')) {
        updateSelectionCount();
    }
});

function updateSelectionCount() {
    // Csak az aktuális fülön lévő kijelöléseket számoljuk
    const count = document.querySelectorAll(`#tab-${currentTab} .select-item:checked`).length;
    document.getElementById('selectionCount').textContent = count + ' elem kijelölve';
}

// --- FŐ MŰVELET VÉGREHAJTÁSA ---
function executeAction(actionType) {
    // 1. Validáció: Van-e kijelölve valami?
    const selectedCheckboxes = document.querySelectorAll(`#tab-${currentTab} .select-item:checked`);
    if (selectedCheckboxes.length === 0) {
        alert('Nincs elem kijelölve!');
        return;
    }

    const ids = Array.from(selectedCheckboxes).map(cb => cb.value);
    
    // 2. Célpont ellenőrzése (Move és Copy esetén kötelező)
    const targetUserId = document.getElementById('targetUserId').value;
    if ((actionType === 'move' || actionType === 'copy') && !targetUserId) {
        alert('Kérlek válassz egy célfelhasználót!');
        return;
    }

    // 3. Megerősítés
    const actionName = (actionType === 'delete') ? 'TÖRLÉS' : (actionType === 'copy' ? 'Másolás' : 'Áthelyezés');
    if (!confirm(`Biztosan végrehajtod a műveletet (${actionName}) a kijelölt ${ids.length} elemen?`)) {
        return;
    }

    // 4. Adatok összeállítása
    const formData = new FormData();
    formData.append('action', 'bulk_operation');
    formData.append('operation', actionType);      // move, copy, delete
    formData.append('entity_type', currentTab);    // tokens, galleries, files
    formData.append('target_user_id', targetUserId);
    formData.append('source_user_id', SOURCE_USER_ID);
    formData.append('ids', JSON.stringify(ids));
    formData.append('csrf_token', CSRF_TOKEN);

    // 5. Küldés
    const btn = event.target;
    const originalText = btn.innerHTML;
    btn.innerHTML = 'Feldolgozás...';
    btn.disabled = true;

    fetch(AJAX_MANAGER_URL, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Siker esetén újratöltés
            alert(data.message);
            location.reload();
        } else {
            alert('Hiba: ' + data.message);
        }
    })
    .catch(error => {
        console.error(error);
        alert('Hálózati hiba történt.');
    })
    .finally(() => {
        btn.innerHTML = originalText;
        btn.disabled = false;
    });
}