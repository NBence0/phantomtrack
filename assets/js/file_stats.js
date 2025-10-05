/**
 * ===================================================================
 *  PhantomTrack - Fájl Összefoglaló Statisztikák (file_stats.js)
 * ===================================================================
 */

document.addEventListener('DOMContentLoaded', function() {
    
    const doughnutColors = [
        ptColors.primary, ptColors.secondary, ptColors.green, ptColors.purple, 
        ptColors.orange, ptColors.yellow, ptColors.red, ptColors.lightBlue, 
        ptColors.teal, ptColors.pink, ptColors.grey
    ];

    // --- 1. Napi Aktivitási Trendek ---
    const activityTrendsCtx = document.getElementById('fileActivityTrendsChart')?.getContext('2d');
    if (activityTrendsCtx) {
        fetchChartData(`${ajaxBaseUrl}?action=file_activity_trends&days=30`)
            .then(apiData => {
                if (apiData && apiData.labels) {
                    new Chart(activityTrendsCtx, {
                        type: 'line',
                        data: {
                            labels: apiData.labels,
                            datasets: [
                                { label: 'Feltöltések', data: apiData.uploads, borderColor: ptColors.green, tension: 0.3, fill: false },
                                { label: 'Megtekintések', data: apiData.views, borderColor: ptColors.primary, tension: 0.3, fill: false },
                                { label: 'Letöltések', data: apiData.downloads, borderColor: ptColors.secondary, tension: 0.3, fill: false }
                            ]
                        },
                        options: {
                            responsive: true, maintainAspectRatio: false,
                            scales: {
                                x: { type: 'time', time: { unit: 'day', tooltipFormat: 'yyyy.MM.dd' } },
                                y: { beginAtZero: true, ticks: { precision: 0 } }
                            },
                            plugins: { legend: { position: 'top' }, tooltip: { mode: 'index', intersect: false } }
                        }
                    });
                }
            });
    }

    // --- 2. Fájltípusok Eloszlása ---
    const fileTypeCtx = document.getElementById('fileTypeDistributionChart')?.getContext('2d');
    if (fileTypeCtx) {
        fetchChartData(`${ajaxBaseUrl}?action=file_type_distribution`)
            .then(apiData => {
                if (apiData && apiData.labels && apiData.data.length > 0) {
                    new Chart(fileTypeCtx, {
                        type: 'doughnut',
                        data: {
                            labels: apiData.labels,
                            datasets: [{
                                label: 'Fájlok száma',
                                data: apiData.data,
                                backgroundColor: doughnutColors,
                            }]
                        },
                        options: {
                            responsive: true, maintainAspectRatio: false,
                            plugins: { legend: { position: 'right', labels: { boxWidth: 12, padding: 10 } } }
                        }
                    });
                } else {
                    fileTypeCtx.canvas.parentElement.innerHTML = `<p class="chart-placeholder">Nincs adat a fájltípusok eloszlásáról.</p>`;
                }
            });
    }

    // --- 3. Összesített Aktivitás Óránként (ÚJ) ---
    const hourlyActivityCtx = document.getElementById('fileHourlyActivityChart')?.getContext('2d');
    if (hourlyActivityCtx) {
        fetchChartData(`${ajaxBaseUrl}?action=hourly_activity_overall_files`)
            .then(apiData => {
                if (apiData && apiData.labels) {
                    new Chart(hourlyActivityCtx, {
                        type: 'bar',
                        data: {
                            labels: apiData.labels,
                            datasets: [{
                                label: 'Összes aktivitás (feltöltés, megtekintés, letöltés)',
                                data: apiData.data,
                                backgroundColor: ptColors.orange,
                            }]
                        },
                        options: {
                            responsive: true, maintainAspectRatio: false,
                            scales: {
                                y: { beginAtZero: true, ticks: { precision: 0 } }
                            },
                            plugins: { legend: { display: false } }
                        }
                    });
                }
            });
    }
});