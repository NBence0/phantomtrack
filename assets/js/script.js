// assets/js/script.js (elejére)

// PhantomTrack Színséma Grafikonokhoz
const ptColors = {
    primary: 'rgba(0, 212, 255, 1)',      // --accent-primary
    primaryTransparent: 'rgba(0, 212, 255, 0.2)',
    secondary: 'rgba(78, 205, 196, 1)',   // --accent-secondary
    secondaryTransparent: 'rgba(78, 205, 196, 0.2)',
    contrastHighlight: 'rgba(58, 123, 213, 1)', // --contrast-highlight
    contrastHighlightTransparent: 'rgba(58, 123, 213, 0.2)',
    textPrimary: 'rgba(255, 255, 255, 1)', // --text-primary
    textSecondary: 'rgba(160, 167, 211, 1)', // --text-secondary

    // Új, harmonizáló színek
    green: 'rgba(46, 213, 115, 1)',        // --color-success
    greenTransparent: 'rgba(46, 213, 115, 0.2)',
    yellow: 'rgba(255, 202, 40, 1)',      // --color-warning
    yellowTransparent: 'rgba(255, 202, 40, 0.2)',
    red: 'rgba(255, 71, 87, 1)',          // --color-error
    redTransparent: 'rgba(255, 71, 87, 0.2)',
    
    // További paletta fánkdiagramokhoz
    purple: 'rgba(153, 102, 255, 1)',
    purpleTransparent: 'rgba(153, 102, 255, 0.2)',
    orange: 'rgba(255, 159, 64, 1)',
    orangeTransparent: 'rgba(255, 159, 64, 0.2)',
    pink: 'rgba(255, 99, 132, 1)',
    pinkTransparent: 'rgba(255, 99, 132, 0.2)',
    lightBlue: 'rgba(54, 162, 235, 1)',
    lightBlueTransparent: 'rgba(54, 162, 235, 0.2)',
    teal: 'rgba(75, 192, 192, 1)',
    tealTransparent: 'rgba(75, 192, 192, 0.2)',
    grey: 'rgba(120, 120, 120, 1)',
    greyTransparent: 'rgba(120, 120, 120, 0.2)'
};

// Globális Chart.js beállítások a téma illesztéséhez
if (typeof Chart !== 'undefined') {
    Chart.defaults.font.family = "'Poppins', 'Segoe UI', sans-serif";
    Chart.defaults.color = ptColors.textSecondary; // Tengelyek, címkék színe
    Chart.defaults.borderColor = 'rgba(255, 255, 255, 0.1)'; // Rácsvonalak színe

    // Tooltip stílusok (opcionális, de szép)
    Chart.defaults.plugins.tooltip.backgroundColor = 'rgba(30, 30, 50, 0.8)';
    Chart.defaults.plugins.tooltip.titleColor = ptColors.primary;
    Chart.defaults.plugins.tooltip.bodyColor = ptColors.textPrimary;
    Chart.defaults.plugins.tooltip.borderColor = ptColors.primaryTransparent;
    Chart.defaults.plugins.tooltip.borderWidth = 1;
    Chart.defaults.plugins.tooltip.padding = 10;
    Chart.defaults.plugins.tooltip.cornerRadius = 6;
    Chart.defaults.plugins.tooltip.displayColors = false; // Elrejti a színmintát a tooltipben, ha nem kell
}

// Segédfüggvény az adatok AJAX lekéréséhez
async function fetchChartData(url) {
    try {
        const response = await fetch(url);
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status} for URL: ${url}`);
        }
        return await response.json();
    } catch (error) {
        console.error("Hiba a grafikon adatainak lekérésekor:", error);
        // Opcionálisan jeleníts meg egy hibaüzenetet a grafikon helyén
        return null;
    }
}