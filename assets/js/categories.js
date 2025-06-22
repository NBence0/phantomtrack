// === FÜGGVÉNYEK A MODÁLIS ABLAKOK KEZELÉSÉHEZ ===

function openEditCategoryModal(categoryId) {
    const modal = document.getElementById('editCategoryModal');
    const form = document.getElementById('editCategoryForm');
    form.querySelector('#edit_category_name').value = 'Betöltés...';
    modal.style.display = 'block';
    const formData = new FormData();
    formData.append('action', 'get_category_details');
    formData.append('category_id', categoryId);
    formData.append('csrf_token', form.querySelector('input[name="csrf_token"]').value);
    fetch(AJAX_PHP_URL, { method: 'POST', body: formData })
    .then(r => r.json()).then(d => {
        if (d.success && d.category) {
            form.querySelector('#edit_category_id').value = d.category.id;
            form.querySelector('#edit_category_name').value = d.category.name;
        } else {
            showDynamicMessage(d.message || 'Hiba a betöltéskor.', 'error');
            modal.style.display = 'none';
        }
    });
}

function openDeleteCategoryModal(categoryId, categoryName, tokenCount) {
    const modal = document.getElementById('deleteCategoryModal');
    modal.querySelector('#categoryIdToDelete').value = categoryId;
    modal.querySelector('#categoryToDeleteName').innerText = categoryName;
    modal.querySelector('#categoryTokenCount').innerText = tokenCount;
    modal.querySelector('#confirmCategoryName').innerText = categoryName;
    const wrapper = modal.querySelector('#categoryMoveSelectWrapper');
    const hiddenSelect = wrapper.querySelector('select');
    const optionsContainer = wrapper.querySelector('.custom-options');
    const trigger = wrapper.querySelector('.select-trigger');
    hiddenSelect.innerHTML = '<option value="none">Nincs kategória</option>';
    optionsContainer.innerHTML = '<span class="custom-option selected" data-value="none">Nincs kategória</span>';
    trigger.textContent = 'Nincs kategória';
    allCategoriesForMove.filter(c => c.id != categoryId).forEach(c => {
        hiddenSelect.add(new Option(c.name, c.id));
        const span = document.createElement('span');
        span.className = 'custom-option'; span.dataset.value = c.id; span.textContent = c.name;
        optionsContainer.appendChild(span);
    });
    modal.querySelector('#confirm_delete_input').value = '';
    modal.querySelector('#finalDeleteBtn').disabled = true;
    modal.style.display = 'block';
}
// === ESEMÉNYKEZELŐK BEÁLLÍTÁSA ===
document.addEventListener('DOMContentLoaded', function() {
    // Generikus űrlapküldő függvény AJAX-hoz
    function handleFormSubmit(formId, successCallback) {
        const form = document.getElementById(formId);
        if (!form) return;
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            const submitButton = this.querySelector('button[type="submit"]');
            const originalButtonText = submitButton.innerHTML;
            submitButton.disabled = true;
            submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Feldolgozás...';
            fetch(AJAX_PHP_URL, { method: 'POST', body: formData })
            .then(r => r.json()).then(data => {
                if (data.success) {
                    document.getElementById(formId).closest('.modal').style.display = 'none';
                    showDynamicMessage(data.message, 'success');
                    setTimeout(() => window.location.reload(), 1500);
                } else {
                    showDynamicMessage(data.message || 'Ismeretlen hiba.', 'error');
                }
            }).catch(err => {
                showDynamicMessage('Hálózati hiba történt.', 'error');
            }).finally(() => {
                submitButton.disabled = false;
                submitButton.innerHTML = originalButtonText;
            });
        });
    }

    // Űrlapok bekötése
    handleFormSubmit('addCategoryForm');
    handleFormSubmit('editCategoryForm');
    handleFormSubmit('deleteCategoryForm');

    // Törlés megerősítő logika
    const confirmInput = document.getElementById('confirm_delete_input');
    if(confirmInput) {
        confirmInput.addEventListener('input', function() {
            document.getElementById('finalDeleteBtn').disabled = (this.value !== document.getElementById('confirmCategoryName').innerText);
        });
    }
});