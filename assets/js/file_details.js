/**
 * ===================================================================
 *  PhantomTrack - Fájl Részletek Oldal (file_details.js)
 * ===================================================================
 */

document.addEventListener('DOMContentLoaded', function() {

    // --- 1. Napi Aktivitás (Megtekintés vs Letöltés) ---
    const dailyActivityCtx = document.getElementById('fileDailyActivityChart')?.getContext('2d');
    if (dailyActivityCtx) {
        fetchChartData(`${ajaxBaseUrl}?action=daily_activity_file&file_id=${currentFileId}`)
            .then(apiData => {
                if (apiData && apiData.labels) {
                    new Chart(dailyActivityCtx, {
                        type: 'line',
                        data: {
                            labels: apiData.labels,
                            datasets: [
                                {
                                    label: 'Megtekintések',
                                    data: apiData.views,
                                    borderColor: ptColors.primary,
                                    backgroundColor: ptColors.primaryTransparent,
                                    tension: 0.3,
                                    yAxisID: 'y',
                                    fill: true
                                },
                                {
                                    label: 'Letöltések',
                                    data: apiData.downloads,
                                    borderColor: ptColors.secondary,
                                    backgroundColor: ptColors.secondaryTransparent,
                                    tension: 0.3,
                                    yAxisID: 'y',
                                    fill: true
                                }
                            ]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            scales: {
                                x: {
                                    type: 'time',
                                    time: { unit: 'day', tooltipFormat: 'yyyy.MM.dd' }
                                },
                                y: {
                                    beginAtZero: true,
                                    ticks: { precision: 0 } // Egész számok a tengelyen
                                }
                            },
                            plugins: {
                                legend: { position: 'top' },
                                tooltip: { mode: 'index', intersect: false }
                            }
                        }
                    });
                } else {
                     dailyActivityCtx.canvas.parentElement.innerHTML = `<p class="chart-placeholder">Nincs megjeleníthető napi adat.</p>`;
                }
            });
    }

    // --- 2. Óránkénti Aktivitás (Összesített) ---
    const hourlyActivityCtx = document.getElementById('fileHourlyActivityChart')?.getContext('2d');
    if (hourlyActivityCtx) {
        fetchChartData(`${ajaxBaseUrl}?action=hourly_activity_file&file_id=${currentFileId}`)
            .then(apiData => {
                if (apiData && apiData.labels) {
                    new Chart(hourlyActivityCtx, {
                        type: 'bar',
                        data: {
                            labels: apiData.labels,
                            datasets: [{
                                label: 'Összes aktivitás (megtekintés + letöltés)',
                                data: apiData.data,
                                backgroundColor: ptColors.green,
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    ticks: { precision: 0 }
                                }
                            },
                            plugins: {
                                legend: { display: false }
                            }
                        }
                    });
                } else {
                     hourlyActivityCtx.canvas.parentElement.innerHTML = `<p class="chart-placeholder">Nincs megjeleníthető óránkénti adat.</p>`;
                }
            });
    }

});