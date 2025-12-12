// ====================================================================
// PhantomTrack Globális Scriptek (script.js) - VÉGLEGES, TISZTÍTOTT VERZIÓ
// ====================================================================

// --- 1. Színséma a grafikonokhoz ---
const ptColors = {
    primary: 'rgba(0, 212, 255, 1)',
    primaryTransparent: 'rgba(0, 212, 255, 0.2)',
    secondary: 'rgba(78, 205, 196, 1)',
    secondaryTransparent: 'rgba(78, 205, 196, 0.2)',
    contrastHighlight: 'rgba(58, 123, 213, 1)',
    contrastHighlightTransparent: 'rgba(58, 123, 213, 0.2)',
    textPrimary: 'rgba(255, 255, 255, 1)',
    textSecondary: 'rgba(160, 167, 211, 1)',
    green: 'rgba(46, 213, 115, 1)',
    greenTransparent: 'rgba(46, 213, 115, 0.2)',
    yellow: 'rgba(255, 202, 40, 1)',
    red: 'rgba(255, 71, 87, 1)',
    purple: 'rgba(153, 102, 255, 1)',
    orange: 'rgba(255, 159, 64, 1)',
    pink: 'rgba(255, 99, 132, 1)',
    lightBlue: 'rgba(54, 162, 235, 1)',
    teal: 'rgba(75, 192, 192, 1)',
    grey: 'rgba(120, 120, 120, 1)'
};

// --- 2. Globális Chart.js beállítások a téma illesztéséhez ---
if (typeof Chart !== 'undefined') {
    Chart.defaults.font.family = "'Poppins', 'Segoe UI', sans-serif";
    Chart.defaults.color = ptColors.textSecondary;
    Chart.defaults.borderColor = 'rgba(255, 255, 255, 0.1)';
    Chart.defaults.plugins.tooltip.backgroundColor = 'rgba(30, 30, 50, 0.8)';
    Chart.defaults.plugins.tooltip.titleColor = ptColors.primary;
    Chart.defaults.plugins.tooltip.bodyColor = ptColors.textPrimary;
    Chart.defaults.plugins.tooltip.borderColor = ptColors.primaryTransparent;
    Chart.defaults.plugins.tooltip.borderWidth = 1;
    Chart.defaults.plugins.tooltip.padding = 10;
    Chart.defaults.plugins.tooltip.cornerRadius = 6;
    Chart.defaults.plugins.tooltip.displayColors = false;
}

// --- 3. Központi AJAX adatlekérő segédfüggvény ---
async function fetchChartData(url) {
    try {
        const response = await fetch(url);
        if (!response.ok) {
            console.error(`HTTP hiba! Status: ${response.status} a ${url} címen.`);
            return { error: `HTTP hiba: ${response.status}` };
        }
        return await response.json();
    } catch (error) {
        console.error("Hiba a grafikon adatainak lekérésekor:", error);
        return { error: 'Hálózati hiba vagy a válasz nem érvényes JSON.' };
    }
}

// --- 4. Dinamikus üzenetmegjelenítő ---
function showDynamicMessage(message, type = 'info', duration = 5000) {
    const mainContent = document.querySelector('.main-content');
    if (!mainContent) return;
    document.querySelectorAll('.dynamic-message').forEach(el => el.remove());
    const messageBox = document.createElement('div');
    messageBox.className = `message ${type}-message dynamic-message`;
    messageBox.textContent = message;
    mainContent.insertAdjacentElement('afterbegin', messageBox);
    if (duration > 0) {
        setTimeout(() => {
            messageBox.style.opacity = '0';
            messageBox.style.transition = 'opacity 0.5s ease';
            setTimeout(() => messageBox.remove(), 500);
        }, duration);
    }
}

// --- 5. Vágólapra másolás ---
function copyToClipboard(inputElement) {
    inputElement.select();
    inputElement.setSelectionRange(0, 99999);
    try {
        document.execCommand('copy');
        showDynamicMessage('URL vágólapra másolva!', 'success');
    } catch (err) {
        showDynamicMessage('Hiba a másolás során.', 'error');
    }
}


// ====================================================================
// === ESEMÉNYKEZELŐK INICIALIZÁLÁSA ===
// ====================================================================

// --- KÖZPONTI KATTINTÁSKEZELŐ (ESEMÉNY-DELEGÁLÁS) ---
document.addEventListener('click', function (e) {
    const target = e.target;

    // --- 1. EGYEDI LEGÖRDÜLŐ MENÜ KEZELÉSE ---
    const selectTrigger = target.closest('.select-trigger');
    const customOption = target.closest('.custom-option');

    // Ha triggerre kattintottunk, nyitjuk/csukjuk a saját menüt
    if (selectTrigger) {
        const wrapper = selectTrigger.closest('.custom-select-wrapper');
        // Előbb becsukunk minden mást
        document.querySelectorAll('.custom-select-wrapper.open').forEach(w => {
            if (w !== wrapper) w.classList.remove('open');
        });
        wrapper.classList.toggle('open');
        return;
    }

    // Ha opcióra kattintottunk, kiválasztjuk
    if (customOption) {
        const wrapper = customOption.closest('.custom-select-wrapper');
        const triggerDiv = wrapper.querySelector('.select-trigger');
        const originalSelect = wrapper.querySelector('select');
        
        wrapper.querySelector('.custom-option.selected')?.classList.remove('selected');
        customOption.classList.add('selected');
        triggerDiv.textContent = customOption.textContent;
        originalSelect.value = customOption.dataset.value;
        originalSelect.dispatchEvent(new Event('change'));
        wrapper.classList.remove('open');
        return;
    }

    // Ha máshova kattintottunk, minden nyitott menüt bezárunk
    document.querySelectorAll('.custom-select-wrapper.open').forEach(wrapper => {
        wrapper.classList.remove('open');
    });

    // --- 2. MOBIL MENÜ BEZÁRÁSA (HA máshova kattintunk) ---
    const sidebar = document.querySelector('.sidebar');
    const toggleButton = document.getElementById('mobileMenuToggle');
    if (sidebar && sidebar.classList.contains('open') && !sidebar.contains(target) && !toggleButton.contains(target)) {
        toggleButton.click(); // Szimulálunk egy kattintást a gombon a bezáráshoz
    }
});


// --- MOBIL MENÜ GOMB ESEMÉNYKEZELŐJE ---
document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.querySelector('.sidebar');
    const toggleButton = document.getElementById('mobileMenuToggle');

    if (sidebar && toggleButton) {
        toggleButton.addEventListener('click', function() {
            sidebar.classList.toggle('open');
            this.classList.toggle('is-active');
            this.setAttribute('aria-expanded', sidebar.classList.contains('open'));
        });
    }
        
    // Ellenőrizzük, hogy a flatpickr betöltődött-e
    if (typeof flatpickr !== "undefined") {
        
        // Magyar lokalizáció beállítása
        flatpickr.localize(flatpickr.l10ns.hu);
        
        document.querySelectorAll('input[type="date"]').forEach(function(dateInput) {
            // Hozzáadjuk a class-t a stílusozáshoz, ha még nincs
            dateInput.classList.add('flatpickr-input');
            
            flatpickr(dateInput, {
                dateFormat: "Y-m-d", // Az adatbázisnak megfelelő formátum
                altInput: true,      // Egy felhasználóbarát formátumot is mutat
                altFormat: "Y. F j.", // Pl. "2024. június 21."
                allowInput: true,    // Engedélyezi a kézi beírást is
            });
        });
    }
});