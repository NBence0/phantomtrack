/***

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

// Globális változók a chart instance-ek és közös beállítások tárolására
const CHART_INSTANCES = {};
const DOUGHNUT_CHART_OPTIONS = {
    responsive: true, maintainAspectRatio: false, cutout: '65%',
    plugins: { legend: { position: 'bottom', labels: { padding: 15, boxWidth: 12 } } }
};
const DOUGHNUT_COLORS = [
    ptColors.primary, ptColors.secondary, ptColors.green, ptColors.purple,
    ptColors.orange, ptColors.yellow, ptColors.red, ptColors.lightBlue, ptColors.teal, ptColors.grey
];

/**
 * Segédfüggvény egy chart példány megsemmisítésére, ha létezik.
 * @param {string} chartId A canvas elem ID-ja.
 *
function destroyChartIfExists(chartId) {
    if (CHART_INSTANCES[chartId]) {
        CHART_INSTANCES[chartId].destroy();
        delete CHART_INSTANCES[chartId];
    }
}

/**
 * Fő függvény a dashboard grafikonjainak és kártyáinak renderelésére.
 * @param {object} apiData - A szerverről kapott, összesített adatobjektum.
 *
function renderDashboard(apiData) {
    // --- GRAFIKONOK RENDERELÉSE ---

    const renderChart = (ctxId, type, data, options) => {
        const ctx = document.getElementById(ctxId)?.getContext('2d');
        if (ctx && data && data.labels && data.labels.length > 0) {
            destroyChartIfExists(ctxId);
            CHART_INSTANCES[ctxId] = new Chart(ctx, { type, data, options });
        } else if (ctx) {
            ctx.canvas.parentElement.innerHTML = `<p class="chart-placeholder">Nincs megjeleníthető adat.</p>`;
        }
    };
    
    // Napi Megnyitások
    renderChart('dailyOpensChartOverall', 'line',
        { labels: apiData.daily_opens_overall?.labels, datasets: [{ label: 'Megnyitások', data: apiData.daily_opens_overall?.data, borderColor: ptColors.primary, backgroundColor: ptColors.primaryTransparent, tension: 0.3, fill: true }] },
        { responsive: true, maintainAspectRatio: false, scales: { x: { type: 'time', time: { unit: 'day', tooltipFormat: 'yyyy.MM.dd' } }, y: { beginAtZero: true } }, plugins: { legend: { display: false } } }
    );
    // Top Tokenek
    renderChart('topTokensChart', 'bar',
        { labels: apiData.top_tokens?.labels, datasets: [{ label: 'Megnyitások', data: apiData.top_tokens?.data, backgroundColor: DOUGHNUT_COLORS }] },
        { indexAxis: 'y', responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } }
    );
    // Országok
    renderChart('countryDistributionChart', 'bar',
        { labels: apiData.country_distribution?.labels, datasets: [{ label: 'Megnyitások', data: apiData.country_distribution?.data, backgroundColor: ptColors.secondary }] },
        { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } }
    );
    // Böngészők, OS, Eszközök
    renderChart('browserDistributionChart', 'doughnut', { labels: apiData.browser_distribution?.labels, datasets: [{ data: apiData.browser_distribution?.data, backgroundColor: DOUGHNUT_COLORS }] }, DOUGHNUT_CHART_OPTIONS);
    renderChart('osDistributionChart', 'doughnut', { labels: apiData.os_distribution?.labels, datasets: [{ data: apiData.os_distribution?.data, backgroundColor: DOUGHNUT_COLORS.slice().reverse() }] }, DOUGHNUT_CHART_OPTIONS);
    renderChart('deviceTypeDistributionChart', 'doughnut', { labels: apiData.device_type_distribution?.labels, datasets: [{ data: apiData.device_type_distribution?.data, backgroundColor: [ptColors.green, ptColors.contrastHighlight, ...DOUGHNUT_COLORS.slice(2)] }] }, DOUGHNUT_CHART_OPTIONS);
    
    // --- TOVÁBBI GRAFIKONOK --- (a meglévő HTML elemek alapján)
    // A legtöbb ezek közül nincs is a jelenlegi dashboard.php-ben, de a logika itt van hozzájuk.
    renderChart('topReferrersChart', 'bar', { labels: apiData.top_referrers?.labels, datasets: [{ data: apiData.top_referrers?.data, backgroundColor: ptColors.greenTransparent, borderColor: ptColors.green, borderWidth:1 }] }, {indexAxis:'y', responsive:true, maintainAspectRatio:false, plugins: { legend: { display: false } }});
    renderChart('weeklyOpensChart', 'bar', { labels: apiData.weekly_opens_overall?.labels, datasets: [{ data: apiData.weekly_opens_overall?.data, backgroundColor: ptColors.purple }] }, {scales: {x:{type:'time', time:{unit:'week'}}}, plugins:{legend:{display:false}}});
    renderChart('hourlyActivityOverallChart', 'bar', { labels: apiData.hourly_activity_overall?.labels, datasets: [{ data: apiData.hourly_activity_overall?.data, backgroundColor: ptColors.orange }] }, {plugins:{legend:{display:false}}});
    renderChart('ispDistributionChart', 'doughnut', { labels: apiData.isp_distribution?.labels, datasets: [{ data: apiData.isp_distribution?.data, backgroundColor:DOUGHNUT_COLORS.slice(1) }] }, DOUGHNUT_CHART_OPTIONS);
    renderChart('tokenStatusRatioChart', 'pie', { labels: apiData.token_status_ratio?.labels, datasets: [{ data: apiData.token_status_ratio?.data, backgroundColor:[ptColors.green, ptColors.red] }] }, {plugins:{legend:{position:'bottom'}}});
    renderChart('newTokensMonthlyChart', 'line', { labels: apiData.new_tokens_monthly?.labels, datasets: [{ data: apiData.new_tokens_monthly?.data, borderColor: ptColors.contrastHighlight, fill:true }] }, {scales:{x:{type:'time',time:{unit:'month'}}}});
    renderChart('topCitiesChart', 'bar', { labels: apiData.top_cities?.labels, datasets: [{ data: apiData.top_cities?.data, backgroundColor:ptColors.pink }] }, {indexAxis:'y', plugins:{legend:{display:false}}});
    renderChart('botActivityRatioChart', 'pie', { labels: apiData.bot_activity_ratio?.labels, datasets: [{ data: apiData.bot_activity_ratio?.data, backgroundColor:[ptColors.secondary, ptColors.grey] }] }, {plugins:{legend:{position:'bottom'}}});

    // --- KÁRTYÁK ÉS LISTÁK FRISSÍTÉSE ---
    
    // Átlagos megnyitások
    if (apiData.average_opens_per_token) {
        document.getElementById('avgOpensPerToken').textContent = apiData.average_opens_per_token.average;
        document.getElementById('avgOpensTotalInfo').textContent = `(${apiData.average_opens_per_token.total_opens} / ${apiData.average_opens_per_token.active_tokens} token)`;
    }

    // Geo telítettség
    if (apiData.geo_data_completeness) {
        document.getElementById('geoCompletenessCountry').textContent = `Ország: ${apiData.geo_data_completeness.country_percentage}%`;
        document.getElementById('geoCompletenessCity').textContent = `Város: ${apiData.geo_data_completeness.city_percentage}%`;
    }
    
    // Legkevésbé aktív tokenek
    const leastActiveEl = document.getElementById('leastActiveTokensList');
    if (leastActiveEl && apiData.least_active_tokens) {
        if (apiData.least_active_tokens.length === 0) {
            leastActiveEl.innerHTML = '<p>Minden token aktív.</p>';
        } else {
            let listHtml = '<ul class="simple-list-condensed">';
            apiData.least_active_tokens.forEach(t => {
                listHtml += `<li><strong>${t.name}</strong><br><small class="text-muted">Utoljára: ${t.last_open || 'Soha'}</small></li>`;
            });
            leastActiveEl.innerHTML = listHtml + '</ul>';
        }
    }

    // Trend
    if (apiData.opens_trend_comparison) {
        const trend = apiData.opens_trend_comparison;
        const trendEl = document.getElementById('opensChangePercentage');
        let changeText = `${trend.percentage_change}%`;
        let changeClass = trend.percentage_change > 0 ? 'text-success' : (trend.percentage_change < 0 ? 'text-danger' : '');
        if (trend.percentage_change > 0) changeText = `+${changeText}`;
        trendEl.innerHTML = `<span class="${changeClass}">${changeText}</span>`;
        document.getElementById('opensChangePeriodInfo').innerHTML = `(${trend.current_opens} vs ${trend.previous_opens})<br>Elmúlt 7 vs. előző 7 nap`;
    }
}

/**
 * Fő belépési pont. A `window.onload` biztosítja, hogy minden (kép, stíluslap) betöltődjön, mielőtt az adatkérés elindul.
 *
window.onload = function() {
    const mainDashboard = document.querySelector('.content-header h1 i.fa-tachometer-alt');
    if (!mainDashboard) return; // Ne fusson le más oldalakon, csak a dashboardon

    console.log("PhantomTrack Dashboard: Adatok lekérése indul...");
    
    // Loading állapot jelzése
    document.querySelectorAll('.chart-container').forEach(container => {
        const canvasId = container.querySelector('canvas')?.id;
        if(canvasId) {
             container.innerHTML = `<p class="chart-placeholder">Adatok betöltése...</p><canvas id="${canvasId}" style="display:none;"></canvas>`;
        }
    });

    // Egyetlen, központi adatlekérés
    fetchChartData(`${ajaxBaseUrl}?action=get_dashboard_data&days=30`)
        .then(data => {
            // Placeholder-ek eltávolítása és canvas-ok visszaállítása
            document.querySelectorAll('.chart-container').forEach(container => {
                const canvas = container.querySelector('canvas');
                container.innerHTML = '';
                if(canvas) {
                    canvas.style.display = '';
                    container.appendChild(canvas);
                }
            });

            if (data && !data.error) {
                renderDashboard(data);
            } else {
                console.error("Hiba a dashboard adatok feldolgozásakor:", data?.error);
                document.querySelector('.main-content').insertAdjacentHTML('afterbegin', '<div class="message error-message">Hiba történt a dashboard adatok betöltésekor.</div>');
            }
        })
        .catch(err => {
            console.error("Végzetes hálózati hiba:", err);
            document.querySelector('.main-content').insertAdjacentHTML('afterbegin', '<div class="message error-message">Hálózati hiba a dashboard adatok betöltésekor.</div>');
        });
};
*/
document.addEventListener('DOMContentLoaded', async function(){
    
    // Fánkdiagramok közös beállításai
    const doughnutChartOptions = {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '65%', // Fánk "lyuk" mérete
        plugins: {
            legend: {
                position: 'bottom',
                labels: { padding: 15, boxWidth: 12 }
            }
        }
    };
    const doughnutColors = [
        ptColors.primary, ptColors.secondary, ptColors.green, ptColors.purple, 
        ptColors.orange, ptColors.yellow, ptColors.red, ptColors.lightBlue, ptColors.teal, ptColors.grey
    ];


    // Segédfüggvény HTML escape-eléshez (biztonsági okokból)
    function escapeHtml(unsafe) {
        if (unsafe === null || typeof unsafe === 'undefined') return '';
        return unsafe
             .replace(/&/g, "&")
             .replace(/</g, "<")
             .replace(/>/g, ">")
             .replace(/"/g, '"')
             .replace(/'/g, "'");
    }
    // Segédfüggvény dátum formázáshoz (ha a PHP formatTimestamp nem elérhető itt könnyen)
    function formatDateForDisplay(dateString) {
        if (!dateString) return 'N/A';
        try {
            const date = new Date(dateString);
            return date.toLocaleDateString('hu-HU', { year: 'numeric', month: 'short', day: 'numeric', hour:'2-digit', minute:'2-digit' });
        } catch (e) {
            return dateString; // Ha nem sikerül formázni, visszaadjuk az eredetit
        }
    }


/////////////////////////////////////////////////////////////////////////////////////////////////////////////


    // 1. Napi Megnyitások (Összesített)
    const dailyOpensCtx = document.getElementById('dailyOpensChartOverall')?.getContext('2d');
    if (dailyOpensCtx) {
        fetchChartData(`${ajaxBaseUrl}?action=daily_opens_overall&days=30`).then(apiData => {
            if (apiData && apiData.labels && apiData.data) {
                new Chart(dailyOpensCtx, {
                    type: 'line',
                    data: {
                        labels: apiData.labels,
                        datasets: [{
                            label: 'Megnyitások száma',
                            data: apiData.data,
                            borderColor: ptColors.primary,
                            backgroundColor: ptColors.primaryTransparent,
                            tension: 0.3,
                            fill: true,
                            pointBackgroundColor: ptColors.primary,
                            pointBorderColor: ptColors.textPrimary,
                            pointHoverBackgroundColor: ptColors.textPrimary,
                            pointHoverBorderColor: ptColors.primary
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            x: {
                                type: 'time',
                                time: {
                                    unit: 'day',
                                    tooltipFormat: 'yyyy.MM.dd',
                                    displayFormats: {
                                        day: 'MM.dd'
                                    }
                                },
                                title: { display: false, text: 'Dátum' }
                            },
                            y: {
                                beginAtZero: true,
                                title: { display: false, text: 'Megnyitások' }
                            }
                        },
                        plugins: {
                            legend: { display: false },
                            tooltip: { mode: 'index', intersect: false }
                        }
                    }
                });
            }
        });
    }

    // 2. Legaktívabb Tokenek
    const topTokensCtx = document.getElementById('topTokensChart')?.getContext('2d');
    if (topTokensCtx) {
        fetchChartData(`${ajaxBaseUrl}?action=top_tokens&limit=7`).then(apiData => {
            if (apiData && apiData.labels && apiData.data) {
                new Chart(topTokensCtx, {
                    type: 'bar',
                    data: {
                        labels: apiData.labels,
                        datasets: [{
                            label: 'Megnyitások',
                            data: apiData.data,
                            backgroundColor: [
                                ptColors.primary, 
                                ptColors.secondary,
                                ptColors.green,
                                ptColors.purple,
                                ptColors.orange,
                                ptColors.lightBlue,
                                ptColors.teal
                            ],
                            borderColor: ptColors.glassBorderStrong,
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        indexAxis: 'y', // Vízszintes oszlopokhoz lehet 'y'
                        scales: {
                            x: { beginAtZero: true },
                            y: { ticks: { autoSkip: false } }
                        },
                        plugins: { legend: { display: false } }
                    }
                });
            }
        });
    }
    
    // 3. Országok Szerinti Megnyitások
    const countryDistCtx = document.getElementById('countryDistributionChart')?.getContext('2d');
    if (countryDistCtx) {
        fetchChartData(`${ajaxBaseUrl}?action=country_distribution&limit=10`).then(apiData => {
            if (apiData && apiData.labels && apiData.data) {
                new Chart(countryDistCtx, {
                    type: 'bar',
                    data: {
                        labels: apiData.labels,
                        datasets: [{
                            label: 'Megnyitások',
                            data: apiData.data,
                            backgroundColor: ptColors.secondary,
                            borderColor: ptColors.glassBorderStrong,
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            x: { ticks: { autoSkip: false, maxRotation: 45, minRotation: 45 } }, // Döntött címkék, ha sok van
                            y: { beginAtZero: true }
                        },
                        plugins: { legend: { display: false } }
                    }
                });
            }
        });
    }

    // 4. Böngésző Eloszlás
    const browserDistCtx = document.getElementById('browserDistributionChart')?.getContext('2d');
    if (browserDistCtx) {
        fetchChartData(`${ajaxBaseUrl}?action=browser_distribution`).then(apiData => {
            if (apiData && apiData.labels && apiData.data) {
                new Chart(browserDistCtx, {
                    type: 'doughnut',
                    data: {
                        labels: apiData.labels,
                        datasets: [{
                            label: 'Böngészők',
                            data: apiData.data,
                            backgroundColor: doughnutColors,
                            borderColor: ptColors.glassBg, // Háttérszínnel megegyező a szebb fánkhoz
                            borderWidth: 2
                        }]
                    },
                    options: doughnutChartOptions
                });
            }
        });
    }

    // 5. OS Eloszlás
    const osDistCtx = document.getElementById('osDistributionChart')?.getContext('2d');
    if (osDistCtx) {
        fetchChartData(`${ajaxBaseUrl}?action=os_distribution`).then(apiData => {
            if (apiData && apiData.labels && apiData.data) {
                new Chart(osDistCtx, {
                    type: 'doughnut',
                    data: {
                        labels: apiData.labels,
                        datasets: [{
                            label: 'Operációs rendszerek',
                            data: apiData.data,
                            backgroundColor: doughnutColors.slice().reverse(), // Más színkombináció
                            borderColor: ptColors.glassBg,
                            borderWidth: 2
                        }]
                    },
                    options: doughnutChartOptions
                });
            }
        });
    }

    // 6. Eszköztípus Eloszlás
    const deviceTypeDistCtx = document.getElementById('deviceTypeDistributionChart')?.getContext('2d');
    if (deviceTypeDistCtx) {
        fetchChartData(`${ajaxBaseUrl}?action=device_type_distribution`).then(apiData => {
            if (apiData && apiData.labels && apiData.data) {
                new Chart(deviceTypeDistCtx, {
                    type: 'doughnut',
                    data: {
                        labels: apiData.labels,
                        datasets: [{
                            label: 'Eszköztípusok',
                            data: apiData.data,
                            backgroundColor: [ptColors.green, ptColors.contrastHighlight, ptColors.yellow, ...doughnutColors.slice(3)],
                            borderColor: ptColors.glassBg,
                            borderWidth: 2
                        }]
                    },
                    options: doughnutChartOptions
                });
            }
        });
    }
    
    // 7. Top Referrer Domainek (Vízszintes oszlop)
    const topReferrersCtx = document.getElementById('topReferrersChart')?.getContext('2d');
    if (topReferrersCtx) {
        fetchChartData(`${ajaxBaseUrl}?action=top_referrers&limit=10`).then(apiData => {
            if (apiData && apiData.labels && apiData.data) {
                new Chart(topReferrersCtx, {
                    type: 'bar',
                    data: {
                        labels: apiData.labels,
                        datasets: [{
                            label: 'Megnyitások száma',
                            data: apiData.data,
                            backgroundColor: ptColors.greenTransparent,
                            borderColor: ptColors.green,
                            borderWidth: 1
                        }]
                    },
                    options: {
                        indexAxis: 'y', // Ettől lesz vízszintes
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            x: { beginAtZero: true },
                            y: { ticks: { autoSkip: false } }
                        },
                        plugins: { legend: { display: false } }
                    }
                });
            }
        });
    }




    // 8. Heti Összesített Megnyitások
    const weeklyOpensCtx = document.getElementById('weeklyOpensChart')?.getContext('2d');
    if (weeklyOpensCtx) {
        fetchChartData(`${ajaxBaseUrl}?action=weekly_opens_overall&weeks=12`).then(apiData => {
            if (apiData && apiData.labels && apiData.data) {
                new Chart(weeklyOpensCtx, {
                    type: 'bar',
                    data: {
                        labels: apiData.labels, // Ezek dátum stringek (hétfők)
                        datasets: [{
                            label: 'Heti megnyitások',
                            data: apiData.data,
                            backgroundColor: ptColors.purpleTransparent,
                            borderColor: ptColors.purple,
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            x: {
                                type: 'time',
                                time: {
                                    unit: 'week',
                                    tooltipFormat: 'yyyy MMM dd', // pl. 2023 Júl 10
                                    displayFormats: {
                                        week: 'MMM dd' // pl. Júl 10
                                    }
                                },
                                title: { display: false, text: 'Hét' }
                            },
                            y: { beginAtZero: true, title: { display: false, text: 'Megnyitások' }}
                        },
                        plugins: { legend: { display: false } }
                    }
                });
            }
        });
    }

    // 9. Napszakok Szerinti Aktivitás (Összesített)
    const hourlyActivityOverallCtx = document.getElementById('hourlyActivityOverallChart')?.getContext('2d');
    if (hourlyActivityOverallCtx) {
        fetchChartData(`${ajaxBaseUrl}?action=hourly_activity_overall&days_back=30`).then(apiData => {
            if (apiData && apiData.labels && apiData.data) {
                new Chart(hourlyActivityOverallCtx, {
                    type: 'bar',
                    data: {
                        labels: apiData.labels, // 00:00, 01:00, ...
                        datasets: [{
                            label: 'Megnyitások (elmúlt 30 nap alapján)',
                            data: apiData.data,
                            backgroundColor: ptColors.orange,
                            borderColor: ptColors.orangeTransparent,
                            borderWidth:1
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            x: { title: {display: false, text: "Óra"} },
                            y: { beginAtZero: true, title: {display: false, text: "Megnyitások"} }
                        },
                        plugins: { legend: { display: false } }
                    }
                });
            }
        });
    }
    
    // 10. ISP Eloszlás
    const ispDistCtx = document.getElementById('ispDistributionChart')?.getContext('2d');
    if (ispDistCtx) {
        fetchChartData(`${ajaxBaseUrl}?action=isp_distribution`).then(apiData => {
            if (apiData && apiData.labels && apiData.data) {
                new Chart(ispDistCtx, {
                    type: 'doughnut',
                    data: {
                        labels: apiData.labels,
                        datasets: [{
                            label: 'ISP Eloszlás',
                            data: apiData.data,
                            backgroundColor: [ptColors.teal, ptColors.pink, ptColors.lightBlue, ...doughnutColors.slice(3).reverse()],
                            borderColor: ptColors.glassBg,
                            borderWidth: 2
                        }]
                    },
                    options: doughnutChartOptions // Használjuk a korábban definiált közös opciókat
                });
            }
        });
    }

    // 11. Token Státusz Arány
    const tokenStatusCtx = document.getElementById('tokenStatusRatioChart')?.getContext('2d');
    if (tokenStatusCtx) {
        fetchChartData(`${ajaxBaseUrl}?action=token_status_ratio`).then(apiData => {
            if (apiData && apiData.labels && apiData.data) {
                new Chart(tokenStatusCtx, {
                    type: 'pie', // Pie chart is jó ide
                    data: {
                        labels: apiData.labels,
                        datasets: [{
                            label: 'Token Státusz',
                            data: apiData.data,
                            backgroundColor: [ptColors.green, ptColors.red], // Aktív: zöld, Inaktív: piros
                            borderColor: ptColors.glassBg,
                            borderWidth: 2
                        }]
                    },
                    options: { // Egyedi opciók, ha kellenek, vagy doughnutChartOptions
                        responsive: true,
                        maintainAspectRatio: false,
                         plugins: {
                            legend: {
                                position: 'bottom',
                                labels: { padding: 15, boxWidth: 12 }
                            }
                        }
                    }
                });
            }
        });
    }
    
    // 12. Új Tokenek Havi Bontásban
    const newTokensMonthlyCtx = document.getElementById('newTokensMonthlyChart')?.getContext('2d');
    if (newTokensMonthlyCtx) {
        fetchChartData(`${ajaxBaseUrl}?action=new_tokens_monthly&months=12`).then(apiData => {
            if (apiData && apiData.labels && apiData.data) {
                new Chart(newTokensMonthlyCtx, {
                    type: 'line',
                    data: {
                        labels: apiData.labels, // YYYY-MM formátumú stringek
                        datasets: [{
                            label: 'Új tokenek száma',
                            data: apiData.data,
                            borderColor: ptColors.contrastHighlight,
                            backgroundColor: ptColors.contrastHighlightTransparent,
                            fill: true,
                            tension: 0.1
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                             x: {
                                type: 'time',
                                time: {
                                    unit: 'month',
                                    parser: 'yyyy-MM', // Fontos a parser a helyes megjelenítéshez
                                    tooltipFormat: 'yyyy MMMM', 
                                    displayFormats: {
                                        month: 'yyyy MMM' 
                                    }
                                },
                                title: { display: false, text: 'Hónap' }
                            },
                            y: { 
                                beginAtZero: true, 
                                title: { display: false, text: 'Új Tokenek' },
                                ticks: { precision: 0 } // Egész számok a Y tengelyen
                            }
                        },
                        plugins: { legend: { display: false } }
                    }
                });
            }
        });
    }

    
    // 13. Top Városok
    const topCitiesCtx = document.getElementById('topCitiesChart')?.getContext('2d');
    if (topCitiesCtx) {
        fetchChartData(`${ajaxBaseUrl}?action=top_cities&limit=10`).then(apiData => {
            if (apiData && apiData.labels && apiData.data) {
                new Chart(topCitiesCtx, {
                    type: 'bar',
                    data: {
                        labels: apiData.labels,
                        datasets: [{
                            label: 'Megnyitások',
                            data: apiData.data,
                            backgroundColor: ptColors.pinkTransparent,
                            borderColor: ptColors.pink,
                            borderWidth: 1
                        }]
                    },
                    options: {
                        indexAxis: 'y',
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: { x: { beginAtZero: true } },
                        plugins: { legend: { display: false } }
                    }
                });
            }
        });
    }

    // 14. Bot Aktivitás Aránya
    const botActivityCtx = document.getElementById('botActivityRatioChart')?.getContext('2d');
    if (botActivityCtx) {
        fetchChartData(`${ajaxBaseUrl}?action=bot_activity_ratio`).then(apiData => {
            if (apiData && apiData.labels && apiData.data) {
                new Chart(botActivityCtx, {
                    type: 'pie',
                    data: {
                        labels: apiData.labels,
                        datasets: [{
                            data: apiData.data,
                            backgroundColor: [ptColors.secondary, ptColors.grey],
                            borderColor: ptColors.glassBg,
                            borderWidth: 2
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { position: 'bottom', labels: {padding: 15, boxWidth: 12} },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        let label = context.label || '';
                                        if (label) { label += ': '; }
                                        if (context.parsed !== null) {
                                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                            const percentage = total > 0 ? (context.raw / total * 100).toFixed(1) + '%' : '0%';
                                            label += context.raw + ' (' + percentage + ')';
                                        }
                                        return label;
                                    }
                                }
                            }
                        }
                    }
                });
            }
        });
    }
    
    // 15. Átlagos Megnyitások Tokenenként (Kártya frissítése)
    const avgOpensEl = document.getElementById('avgOpensPerToken');
    const avgOpensInfoEl = document.getElementById('avgOpensTotalInfo');
    if (avgOpensEl && avgOpensInfoEl) {
        fetchChartData(`${ajaxBaseUrl}?action=average_opens_per_token`).then(apiData => {
            if (apiData && typeof apiData.average !== 'undefined') {
                avgOpensEl.textContent = apiData.average;
                avgOpensInfoEl.textContent = `(${apiData.total_opens} megnyitás / ${apiData.active_tokens} aktív token)`;
            } else {
                avgOpensEl.textContent = 'N/A';
                avgOpensInfoEl.textContent = 'Adat hiba';
            }
        });
    }

    // 16. Tokenek Aktivitás Szerinti Eloszlása
    const tokenActivityDistCtx = document.getElementById('tokenActivityDistributionChart')?.getContext('2d');
    if (tokenActivityDistCtx) {
        fetchChartData(`${ajaxBaseUrl}?action=token_activity_distribution`).then(apiData => {
            if (apiData && apiData.labels && apiData.data) {
                new Chart(tokenActivityDistCtx, {
                    type: 'bar',
                    data: {
                        labels: apiData.labels,
                        datasets: [{
                            label: 'Tokenek száma ebben a kategóriában',
                            data: apiData.data,
                            backgroundColor: ptColors.lightBlueTransparent,
                            borderColor: ptColors.lightBlue,
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            x: { title: { display: true, text: 'Megnyitások Száma Tokenenként' } },
                            y: { beginAtZero: true, title: { display: true, text: 'Tokenek Száma' }, ticks: { precision: 0 } }
                        },
                        plugins: { legend: { display: false } }
                    }
                });
            }
        });
    }

    // 17. Geolokáció Telítettség (Kártya frissítése) - JAVÍTVA
    const geoCountryEl = document.getElementById('geoCompletenessCountry');
    const geoCityEl = document.getElementById('geoCompletenessCity');
    const geoTotalLogsEl = document.getElementById('geoCompletenessTotalLogs'); // ÚJ

    if (geoCountryEl && geoCityEl && geoTotalLogsEl) { // Ellenőrizzük geoTotalLogsEl-t is
        fetchChartData(`${ajaxBaseUrl}?action=geo_data_completeness`).then(apiData => {
            if (apiData && typeof apiData.country_percentage !== 'undefined') {
                geoCountryEl.textContent = `Ország: ${apiData.country_percentage}%`;
                geoCityEl.textContent = `Város: ${apiData.city_percentage}%`;
                geoTotalLogsEl.textContent = `(${apiData.total_logs_for_geo} logbejegyzés alapján)`; // ÚJ
            } else {
                geoCountryEl.textContent = 'Ország: N/A';
                geoCityEl.textContent = 'Város: N/A';
                geoTotalLogsEl.textContent = '(Adat hiba)'; // ÚJ
            }
        });
    }


    // 18. Legkevésbé Aktív Tokenek (Lista)
    const leastActiveTokensEl = document.getElementById('leastActiveTokensList');
    if (leastActiveTokensEl) {
        fetchChartData(`${ajaxBaseUrl}?action=least_active_tokens&limit=5`).then(apiData => {
            if (apiData && Array.isArray(apiData)) {
                if (apiData.length === 0) {
                    leastActiveTokensEl.innerHTML = '<p>Minden aktív tokennek van friss aktivitása, vagy nincsenek aktív tokenek.</p>';
                } else {
                    let listHtml = '<ul class="simple-list">'; // simple-list-condensed-et is használhatsz
                    apiData.forEach(token => {
                        listHtml += `<li>
                            <strong>${escapeHtml(token.token_name)}</strong>
                            <small class="text-muted">(${escapeHtml(token.token_value.substring(0,10))}...)</small><br>
                            Utolsó megnyitás: ${token.last_open_time ? formatDateForDisplay(token.last_open_time) : 'Soha'} <br>
                            Összes megnyitás: ${token.total_opens}
                        </li>`;
                    });
                    listHtml += '</ul>';
                    leastActiveTokensEl.innerHTML = listHtml;
                }
            } else {
                leastActiveTokensEl.innerHTML = '<p>Hiba a legkevésbé aktív tokenek betöltésekor.</p>';
            }
        });
    }

    // 19. "Hibás" / Nem Elemzett Adatok Aránya (Kártyák) - JAVÍTVA
    // Közös függvény a Data Quality kártyák frissítéséhez
    function updateDataQualityCard(cardId, percentage, absolute, totalLogs) {
        const card = document.getElementById(cardId);
        if (card) {
            const statValueSpan = card.querySelector('.stat-value span');
            const smallAbsSpan = card.querySelector('small span:first-child');
            const smallTotalSpan = card.querySelector('small span.total-logs-dq');

            if (statValueSpan) statValueSpan.textContent = percentage;
            if (smallAbsSpan) smallAbsSpan.textContent = absolute;
            if (smallTotalSpan) smallTotalSpan.textContent = totalLogs;
        }
    }

    // A fetch hívás a data_quality_stats-hoz
    // Ennek a `if` blokknak az elemek lekérése után kell lennie, de a `fetchChartData` hívás elég egyszer.
    // Összevonjuk a feltételeket, hogy csak akkor fusson, ha legalább egy kártya létezik.
    const dqBrowserCard = document.getElementById('dataQualityCardUnknownBrowser');
    const dqOsCard = document.getElementById('dataQualityCardUnknownOs');
    const dqDeviceCard = document.getElementById('dataQualityCardUnknownDevice');
    const dqCountryCard = document.getElementById('dataQualityCardUnknownCountry');

    if (dqBrowserCard || dqOsCard || dqDeviceCard || dqCountryCard) {
        fetchChartData(`${ajaxBaseUrl}?action=data_quality_stats`).then(apiData => {
            if (apiData && typeof apiData.total_logs !== 'undefined') {
                updateDataQualityCard('dataQualityCardUnknownBrowser', apiData.unknown_browser_perc, apiData.unknown_browser_abs, apiData.total_logs);
                updateDataQualityCard('dataQualityCardUnknownOs', apiData.unknown_os_perc, apiData.unknown_os_abs, apiData.total_logs);
                updateDataQualityCard('dataQualityCardUnknownDevice', apiData.unknown_device_type_perc, apiData.unknown_device_type_abs, apiData.total_logs);
                updateDataQualityCard('dataQualityCardUnknownCountry', apiData.unknown_country_perc, apiData.unknown_country_abs, apiData.total_logs);
            } else {
                // Hiba esetén alapértelmezett szöveg minden kártyára
                updateDataQualityCard('dataQualityCardUnknownBrowser', 'N/A', 'N/A', 'N/A');
                updateDataQualityCard('dataQualityCardUnknownOs', 'N/A', 'N/A', 'N/A');
                updateDataQualityCard('dataQualityCardUnknownDevice', 'N/A', 'N/A', 'N/A');
                updateDataQualityCard('dataQualityCardUnknownCountry', 'N/A', 'N/A', 'N/A');
            }
        });
    }
    // 20. Napi Átlagos Megnyitások (Kártya)
    const dailyAvgValEl = document.getElementById('dailyAvgOpensVal');
    const dailyAvgPeriodEl = document.getElementById('dailyAvgOpensPeriod');
    if (dailyAvgValEl && dailyAvgPeriodEl) {
        fetchChartData(`${ajaxBaseUrl}?action=daily_average_opens&days=30`).then(apiData => {
            if (apiData && typeof apiData.daily_average !== 'undefined') {
                dailyAvgValEl.textContent = apiData.daily_average;
                dailyAvgPeriodEl.textContent = `az elmúlt ${apiData.period_days} nap alapján (${apiData.total_in_period} össz.)`;
            } else {
                dailyAvgValEl.textContent = 'N/A';
                dailyAvgPeriodEl.textContent = 'Adat hiba';
            }
        });
    }

    // 21. Leggyakoribb Óra a Megnyitásokhoz (Kártya)
    const busiestHourValEl = document.getElementById('busiestHourVal');
    const busiestHourCountEl = document.getElementById('busiestHourCount');
    if (busiestHourValEl && busiestHourCountEl) {
        fetchChartData(`${ajaxBaseUrl}?action=most_active_hour_overall&days_back=30`).then(apiData => {
            if (apiData && apiData.hour) {
                busiestHourValEl.textContent = apiData.hour;
                busiestHourCountEl.textContent = `${apiData.count} megnyitással (elmúlt 30 nap)`;
            } else {
                busiestHourValEl.textContent = 'N/A';
                busiestHourCountEl.textContent = 'Adat hiba';
            }
        });
    }
    
    // 22. Megnyitások Kategóriánként
    const categoryOpensCtx = document.getElementById('categoryOpensDistributionChart')?.getContext('2d');
    if (categoryOpensCtx) {
        fetchChartData(`${ajaxBaseUrl}?action=category_opens_distribution`).then(apiData => {
            if (apiData && apiData.labels && apiData.data) {
                new Chart(categoryOpensCtx, {
                    type: 'doughnut',
                    data: {
                        labels: apiData.labels,
                        datasets: [{
                            label: 'Megnyitások kategóriánként',
                            data: apiData.data,
                            backgroundColor: [ // Új színkombináció
                                ptColors.purple, ptColors.orange, ptColors.teal, ptColors.pink,
                                ptColors.greenTransparent, ptColors.secondaryTransparent, ptColors.primaryTransparent,
                                ptColors.grey
                            ],
                            borderColor: ptColors.glassBg,
                            borderWidth: 2
                        }]
                    },
                    options: doughnutChartOptions // Használjuk a korábban definiált közös opciókat
                });
            }
        });
    }

    // 23. Megnyitások Változása Előző Időszakhoz Képest (Kártya frissítése)
    // A HTML elemek már léteznek: opensChangePercentage, opensChangePeriodInfo
    const opensChangeValEl = document.getElementById('opensChangePercentage');
    const opensChangePeriodInfoEl = document.getElementById('opensChangePeriodInfo');
    if (opensChangeValEl && opensChangePeriodInfoEl) {
        // Paraméterek: aktuális periódus hossza 7 nap, összehasonlítás az ezt megelőző 7 nappal
        fetchChartData(`${ajaxBaseUrl}?action=opens_trend_comparison¤t_days=7&offset_days=7&length_days=7`).then(apiData => {
            if (apiData && typeof apiData.percentage_change !== 'undefined') {
                let changeText = `${apiData.percentage_change}%`;
                let changeClass = '';
                if (apiData.percentage_change > 0) {
                    changeText = `+${changeText}`;
                    changeClass = 'text-success'; // CSS osztály zöld színhez
                } else if (apiData.percentage_change < 0) {
                    changeClass = 'text-danger'; // CSS osztály piros színhez
                }
                opensChangeValEl.innerHTML = `<span class="${changeClass}">${changeText}</span>`;
                opensChangePeriodInfoEl.innerHTML = `(${apiData.current_opens} vs ${apiData.previous_opens})<br>${apiData.current_period_label} vs. ${apiData.previous_period_label.replace('Előző','az azt megelőző')}`;
            } else {
                opensChangeValEl.textContent = 'N/A';
                opensChangePeriodInfoEl.textContent = 'Adat hiba';
            }
        });
    }
});

