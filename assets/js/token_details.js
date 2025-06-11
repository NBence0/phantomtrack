
// ptColors, Chart.defaults a script.js-ben vannak feltételezve
const CHART_INSTANCES_TD = {}; 
    
// Segédfüggvény HTML escape-eléshez (ha még nincs globálisan)
function escapeHtml(unsafe) {
    if (unsafe === null || typeof unsafe === 'undefined') return '';
    // Egyszerűbb replace, de production-ben egy robusztusabb megoldás jobb lehet
    return unsafe.toString()
        .replace(/&/g, "&")
        .replace(/</g, "<")
        .replace(/>/g, ">")
        .replace(/"/g, '"')
        .replace(/'/g, "'");
}

// Segédfüggvény dátum formázáshoz olvashatóbb magyar formátumra
function formatDateForDisplay(dateString) {
    if (!dateString) return 'N/A'; // Ha nincs dátum, 'N/A'-t adunk vissza
    try {
        // Az ISO 8601 formátumú stringeket (pl. "YYYY-MM-DD HH:MM:SS") a Date objektum általában jól kezeli.
        // Ha a MySQL `timestamp` más formátumú, lehet, hogy itt finomítani kell.
        const date = new Date(dateString);
        // Ellenőrizzük, hogy érvényes dátumot kaptunk-e
        if (isNaN(date.getTime())) {
            // Ha nem érvényes, próbáljuk meg a space-t 'T'-re cserélni, hátha segít
            const modifiedDateString = dateString.replace(' ', 'T');
            const modifiedDate = new Date(modifiedDateString);
            if (isNaN(modifiedDate.getTime())) {
                console.warn("Érvénytelen dátum string a formatDateForDisplay számára:", dateString);
                return dateString; // Visszaadjuk az eredetit, ha nem tudjuk feldolgozni
            }
            // Ha a módosított érvényes, azt használjuk
            const options = {
                year: 'numeric', month: 'short', day: 'numeric',
                hour: '2-digit', minute: '2-digit', second: '2-digit',
                // timeZoneName: 'short' // Opcionális: időzóna rövidítés
            };
            return modifiedDate.toLocaleDateString('hu-HU', options);
        }
        const options = {
            year: 'numeric',    // pl. 2023
            month: 'short',     // pl. júl. (a 'long' adná a "július"-t)
            day: 'numeric',     // pl. 17
            hour: '2-digit',    // pl. 09
            minute: '2-digit',  // pl. 05
            second: '2-digit',  // pl. 00 (opcionális, ha nem kell másodperc)
            // timeZoneName: 'short' // Opcionális: CEST, stb. Ha a dateString UTC, akkor az UTC-t mutatja.
                                // Ha a szerver ideje helyi, akkor azt.
        };
        return date.toLocaleDateString('hu-HU', options);
    } catch (e) {
        console.error("Hiba a dátum formázása közben:", e, "Eredeti dátum:", dateString);
        return dateString; // Hiba esetén visszaadjuk az eredeti stringet
    }
}

function destroyChartIfExists(chartId) {
    if (CHART_INSTANCES_TD[chartId]) {
        CHART_INSTANCES_TD[chartId].destroy();
        delete CHART_INSTANCES_TD[chartId];
    }
}

// Segédfüggvény az adatok AJAX lekéréséhez (ha nincs a script.js-ben)
async function fetchChartData(url) {
    // console.log("Fetching data from:", url);
    try {
        const response = await fetch(url);
        if (!response.ok) {
            const errorText = await response.text();
            console.error(`HTTP error! status: ${response.status} for URL: ${url}. Response: ${errorText}`);
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        const jsonData = await response.json();
        // console.log("Received JSON data for " + url, jsonData);
        return jsonData;
    } catch (error) {
        console.error("Hiba a grafikon adatainak lekérésekor (" + url + "):", error);
        return null;
    }
}

function escapeHtml(unsafe) {
    if (unsafe === null || typeof unsafe === 'undefined') return '';
    return unsafe.toString().replace(/&/g, "&").replace(/</g, "<").replace(/>/g, ">").replace(/"/g, '"').replace(/'/g, "'");
}

function copyToClipboard(inputElement) {
    inputElement.select();
    inputElement.setSelectionRange(0, 99999);
    try {
        document.execCommand('copy');
        alert('Pixel URL vágólapra másolva!');
    } catch (err) {
        alert('Hiba a másolás során.');
    }
}
        const dateRangeForm = document.getElementById('dateRangeFormTokenDetails'); // Ez a form most GET-tel újratölti az oldalt
        const startDateInput = document.getElementById('td_start_date'); // A PHP által beállított értékeket olvassuk ki
        const endDateInput = document.getElementById('td_end_date');   // A PHP által beállított értékeket olvassuk ki


document.addEventListener('DOMContentLoaded', function() {

    
    function loadTokenSpecificCharts(startDate, endDate) {



        const doughnutPieOptionsTd = {
            responsive: true, maintainAspectRatio: false,
            plugins: {
                legend: { position: 'right', labels: { padding: 10, boxWidth: 10, font: {size: 10} } },
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
        };

        const doughnutColorsTd = [ ptColors.secondary, ptColors.green, ptColors.purple, ptColors.orange, ptColors.pink, ptColors.lightBlue, ptColors.teal, ptColors.yellow, ptColors.contrastHighlight, ptColors.grey ];
        const dateParams = (startDate && endDate) ? `&start_date=${startDate}&end_date=${endDate}` : '';
        const selectedPeriodText = (startDate && endDate) ? `${startDate} / ${endDate}` : 'Alapértelmezett időszak';
        


        // Stat kártyák
        fetchChartData(`${ajaxBaseUrl}?action=unique_vs_total_opens_token&token_id=${currentTokenId}${dateParams}`).then(apiData => {
            if (apiData && typeof apiData.total_opens !== 'undefined') {
                if(document.getElementById('tdTotalOpensVal')) document.getElementById('tdTotalOpensVal').textContent = apiData.total_opens;
                if(document.getElementById('tdUniqueIpsVal')) document.getElementById('tdUniqueIpsVal').textContent = apiData.unique_opens;
                if(document.getElementById('tdTotalOpensPeriod')) document.getElementById('tdTotalOpensPeriod').textContent = selectedPeriodText;
                if(document.getElementById('tdUniqueIpsPeriod')) document.getElementById('tdUniqueIpsPeriod').textContent = selectedPeriodText;
                const tdUniqueRatioValEl = document.getElementById('tdUniqueRatioVal');
                const tdUniqueRatioInfoEl = document.getElementById('tdUniqueRatioInfo');
                if (tdUniqueRatioValEl) {
                    const ratio = apiData.total_opens > 0 ? (apiData.unique_opens / apiData.total_opens * 100).toFixed(1) : 0;
                    tdUniqueRatioValEl.textContent = `${ratio}%`;
                }
                if(tdUniqueRatioInfoEl) tdUniqueRatioInfoEl.textContent = `${apiData.unique_opens} egyedi / ${apiData.total_opens} összes`;
            }
        });

        // Napi Megnyitások (Tokenre)
        destroyChartIfExists('tdDailyOpensChart');
        const tdDailyOpensCtx = document.getElementById('tdDailyOpensChart')?.getContext('2d');
        if (tdDailyOpensCtx) {
            fetchChartData(`${ajaxBaseUrl}?action=daily_opens_token&token_id=${currentTokenId}${dateParams}`).then(apiData => {
                if (apiData && apiData.labels && apiData.data) {
                    CHART_INSTANCES_TD['tdDailyOpensChart'] = new Chart(tdDailyOpensCtx, {
                        type: 'line',
                        data: { labels: apiData.labels, datasets: [{ label: 'Megnyitások', data: apiData.data, borderColor: ptColors.primary, backgroundColor: ptColors.primaryTransparent, tension: 0.3, fill:true, pointRadius: 2, pointHoverRadius: 5 }] },
                        options: { responsive: true, maintainAspectRatio: false, scales: { x: { type: 'time', time: { unit: 'day', tooltipFormat: 'yyyy.MM.dd', displayFormats: {day: 'MM.dd'} }}, y: { beginAtZero: true, ticks: { precision: 0 }}}, plugins:{legend:{display:false}, tooltip: {mode: 'index', intersect: false}} }
                    });
                }
            });
        }

        // Óránkénti Aktivitás (Tokenre)
        destroyChartIfExists('tdHourlyActivityChart');
        const tdHourlyCtx = document.getElementById('tdHourlyActivityChart')?.getContext('2d');
        if (tdHourlyCtx) {
            fetchChartData(`${ajaxBaseUrl}?action=hourly_activity_token&token_id=${currentTokenId}${dateParams}`).then(apiData => {
                if (apiData && apiData.labels && apiData.data) {
                    CHART_INSTANCES_TD['tdHourlyActivityChart'] = new Chart(tdHourlyCtx, {
                        type: 'bar',
                        data: { labels: apiData.labels, datasets: [{ label: 'Megnyitások', data: apiData.data, backgroundColor: ptColors.green }] },
                        options: { responsive: true, maintainAspectRatio: false, scales: {y: { beginAtZero: true, ticks: { precision: 0 } }}, plugins:{legend:{display:false}} }
                    });
                }
            });
        }
        
        // Böngésző Eloszlás (Tokenre)
        destroyChartIfExists('tdBrowserDistributionChart');
        const tdBrowsersCtx = document.getElementById('tdBrowserDistributionChart')?.getContext('2d');
        if (tdBrowsersCtx) {
            fetchChartData(`${ajaxBaseUrl}?action=browser_distribution_token&token_id=${currentTokenId}${dateParams}`).then(apiData => {
                if (apiData && apiData.labels && apiData.data && apiData.data.length > 0) {
                    CHART_INSTANCES_TD['tdBrowserDistributionChart'] = new Chart(tdBrowsersCtx, {
                        type: 'doughnut',
                        data: { labels: apiData.labels, datasets: [{ data: apiData.data, backgroundColor: doughnutColorsTd, borderColor: ptColors.glassBg, borderWidth: 1.5 }] },
                        options: doughnutPieOptionsTd
                    });
                } else if (tdBrowsersCtx.canvas) { tdBrowsersCtx.canvas.parentElement.innerHTML = '<p class="text-muted text-center" style="padding-top:20px;">Nincs adat.</p>';}
            });
        }

        // OS Eloszlás (Tokenre)
        destroyChartIfExists('tdOsDistributionChart');
        const tdOsCtx = document.getElementById('tdOsDistributionChart')?.getContext('2d');
        if (tdOsCtx) {
            fetchChartData(`${ajaxBaseUrl}?action=os_distribution_token&token_id=${currentTokenId}${dateParams}`).then(apiData => {
                if (apiData && apiData.labels && apiData.data && apiData.data.length > 0) {
                    CHART_INSTANCES_TD['tdOsDistributionChart'] = new Chart(tdOsCtx, {
                        type: 'doughnut',
                        data: { labels: apiData.labels, datasets: [{ data: apiData.data, backgroundColor: doughnutColorsTd.slice().reverse(), borderColor: ptColors.glassBg, borderWidth: 1.5 }] },
                        options: doughnutPieOptionsTd
                    });
                } else if (tdOsCtx.canvas) { tdOsCtx.canvas.parentElement.innerHTML = '<p class="text-muted text-center" style="padding-top:20px;">Nincs adat.</p>';}
            });
        }
        
        // Eszköztípus Eloszlás (Tokenre)
        destroyChartIfExists('tdDeviceTypeDistributionChart');
        const tdDeviceCtx = document.getElementById('tdDeviceTypeDistributionChart')?.getContext('2d');
        if (tdDeviceCtx) {
            fetchChartData(`${ajaxBaseUrl}?action=device_type_distribution_token&token_id=${currentTokenId}${dateParams}`).then(apiData => {
                if (apiData && apiData.labels && apiData.data && apiData.data.length > 0) {
                    CHART_INSTANCES_TD['tdDeviceTypeDistributionChart'] = new Chart(tdDeviceCtx, {
                        type: 'doughnut',
                        data: { labels: apiData.labels, datasets: [{ data: apiData.data, backgroundColor: [ptColors.teal, ptColors.orange, ptColors.red, ...doughnutColorsTd.slice(3)], borderColor: ptColors.glassBg, borderWidth: 1.5 }] },
                        options: doughnutPieOptionsTd
                    });
                } else if (tdDeviceCtx.canvas) { tdDeviceCtx.canvas.parentElement.innerHTML = '<p class="text-muted text-center" style="padding-top:20px;">Nincs adat.</p>';}
            });
        }

        // Top Országok (Tokenre)
        destroyChartIfExists('tdCountryDistributionChart');
        const tdCountryCtx = document.getElementById('tdCountryDistributionChart')?.getContext('2d');
        if (tdCountryCtx) {
            fetchChartData(`${ajaxBaseUrl}?action=country_distribution_token&token_id=${currentTokenId}${dateParams}&limit=7`).then(apiData => {
                if (apiData && apiData.labels && apiData.data && apiData.data.length > 0) {
                    CHART_INSTANCES_TD['tdCountryDistributionChart'] = new Chart(tdCountryCtx, {
                        type: 'bar',
                        data: { labels: apiData.labels, datasets: [{ label: 'Megnyitások', data: apiData.data, backgroundColor: ptColors.secondary }] },
                        options: { responsive: true, maintainAspectRatio: false, scales: {y: { beginAtZero: true, ticks: { precision: 0 } }}, plugins:{legend:{display:false}} }
                    });
                } else if (tdCountryCtx.canvas) { tdCountryCtx.canvas.parentElement.innerHTML = '<p class="text-muted text-center" style="padding-top:20px;">Nincs adat.</p>';}
            });
        }
        
        // Top Városok (Tokenre)
        destroyChartIfExists('tdCityDistributionChart');
        const tdCityCtx = document.getElementById('tdCityDistributionChart')?.getContext('2d');
        if (tdCityCtx) {
            fetchChartData(`${ajaxBaseUrl}?action=city_distribution_token&token_id=${currentTokenId}${dateParams}&limit=7`).then(apiData => {
                if (apiData && apiData.labels && apiData.data && apiData.data.length > 0) {
                    CHART_INSTANCES_TD['tdCityDistributionChart'] = new Chart(tdCityCtx, {
                        type: 'bar',
                        data: { labels: apiData.labels, datasets: [{ label: 'Megnyitások', data: apiData.data, backgroundColor: ptColors.purple }] },
                        options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, scales: {x: { beginAtZero: true, ticks: { precision: 0 } }}, plugins:{legend:{display:false}} }
                    });
                } else if (tdCityCtx.canvas) { tdCityCtx.canvas.parentElement.innerHTML = '<p class="text-muted text-center" style="padding-top:20px;">Nincs adat.</p>';}
            });
        }
        
        // Top ISP-k (Tokenre)
        destroyChartIfExists('tdIspDistributionChart');
        const tdIspCtx = document.getElementById('tdIspDistributionChart')?.getContext('2d');
        if (tdIspCtx) {
            fetchChartData(`${ajaxBaseUrl}?action=isp_distribution_token&token_id=${currentTokenId}${dateParams}&limit=7`).then(apiData => {
                if (apiData && apiData.labels && apiData.data && apiData.data.length > 0) {
                    CHART_INSTANCES_TD['tdIspDistributionChart'] = new Chart(tdIspCtx, {
                        type: 'bar',
                        data: { labels: apiData.labels, datasets: [{ label: 'Megnyitások', data: apiData.data, backgroundColor: ptColors.contrastHighlight }] },
                        options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, scales: {x: { beginAtZero: true, ticks: { precision: 0 } }}, plugins:{legend:{display:false}} }
                    });
                } else if (tdIspCtx.canvas) { tdIspCtx.canvas.parentElement.innerHTML = '<p class="text-muted text-center" style="padding-top:20px;">Nincs adat.</p>';}
            });
        }
        
        // Top Referrerek (Tokenre)
        destroyChartIfExists('tdTopReferrersChart');
        const tdReferrersCtx = document.getElementById('tdTopReferrersChart')?.getContext('2d');
        if (tdReferrersCtx) {
            fetchChartData(`${ajaxBaseUrl}?action=top_referrers_token&token_id=${currentTokenId}${dateParams}&limit=7`).then(apiData => {
                if (apiData && apiData.labels && apiData.data && apiData.data.length > 0) {
                    CHART_INSTANCES_TD['tdTopReferrersChart'] = new Chart(tdReferrersCtx, {
                        type: 'bar',
                        data: { labels: apiData.labels, datasets: [{ label: 'Megnyitások', data: apiData.data, backgroundColor: ptColors.greenTransparent, borderColor: ptColors.green, borderWidth: 1 }] },
                        options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, scales: {x: { beginAtZero: true, ticks: { precision: 0 } }}, plugins:{legend:{display:false}} }
                    });
                } else if (tdReferrersCtx.canvas) { tdReferrersCtx.canvas.parentElement.innerHTML = '<p class="text-muted text-center" style="padding-top:20px;">Nincs adat.</p>';}
            });
        }
        // Megnyitások Hét Napjai Szerint (Tokenre)
        destroyChartIfExists('tdDayOfWeekChart');
        const tdDayOfWeekCtx = document.getElementById('tdDayOfWeekChart')?.getContext('2d');
        if (tdDayOfWeekCtx) {
            fetchChartData(`${ajaxBaseUrl}?action=opens_by_day_of_week_token&token_id=${currentTokenId}${dateParams}`).then(apiData => {
                if (apiData && apiData.labels && apiData.data && apiData.data.some(d => d > 0)) { // Csak ha van adat
                    CHART_INSTANCES_TD['tdDayOfWeekChart'] = new Chart(tdDayOfWeekCtx, {
                        type: 'bar',
                        data: { 
                            labels: apiData.labels, 
                            datasets: [{ label: 'Megnyitások', data: apiData.data, backgroundColor: ptColors.yellowTransparent, borderColor: ptColors.yellow, borderWidth:1 }] 
                        },
                        options: { responsive: true, maintainAspectRatio: false, scales: {y: { beginAtZero: true, ticks: { precision: 0 } }}, plugins:{legend:{display:false}} }
                    });
                } else if (tdDayOfWeekCtx.canvas) { tdDayOfWeekCtx.canvas.parentElement.innerHTML = '<p class="text-muted text-center" style="padding-top:20px;">Nincs elég adat ehhez a grafikonhoz a kiválasztott időszakban.</p>';}
            });
        }
    
        // A dátumszűrő form most már normál GET kérést küld, ami újratölti az oldalt.
        // A DOMContentLoaded eseményre a loadTokenSpecificCharts lefut a frissített
        // startDateInput.value és endDateInput.value értékekkel.
        // Nincs szükség külön submit listenerre a grafikonok frissítéséhez itt,
        // ha az oldal újratöltése elfogadható a naplóval való szinkronizáció miatt.
        // Óránkénti aktivitás az elmúlt 24 órában (Tokenre) - EZ FÜGGETLEN A FŐ DÁTUMSZŰRŐTŐL
        destroyChartIfExists('tdHourlyLast24hChart');
        const tdHourly24hCtx = document.getElementById('tdHourlyLast24hChart')?.getContext('2d');
        if (tdHourly24hCtx) {
            // Figyelem: Nincs dateParams, mert ez mindig az utolsó 24 órát nézi
            fetchChartData(`${ajaxBaseUrl}?action=hourly_activity_last_24h_token&token_id=${currentTokenId}`).then(apiData => {
                if (apiData && apiData.labels && apiData.data && apiData.data.some(d => d > 0)) {
                    CHART_INSTANCES_TD['tdHourlyLast24hChart'] = new Chart(tdHourly24hCtx, {
                        type: 'line', // Vonal jobb lehet itt
                        data: { 
                            labels: apiData.labels, 
                            datasets: [{ 
                                label: 'Megnyitások (elmúlt 24h)', 
                                data: apiData.data, 
                                borderColor: ptColors.pink,
                                backgroundColor: ptColors.pinkTransparent,
                                tension: 0.3,
                                fill: true,
                                pointRadius: 2 
                            }] 
                        },
                        options: { responsive: true, maintainAspectRatio: false, scales: {y: { beginAtZero: true, ticks: { precision: 0 } }}, plugins:{legend:{display:false}} }
                    });
                } else if (tdHourly24hCtx.canvas) { tdHourly24hCtx.canvas.parentElement.innerHTML = '<p class="text-muted text-center" style="padding-top:20px;">Nincs aktivitás az elmúlt 24 órában.</p>';}
            });
        }
        // Leggyakoribb IP Címek (Tokenre) - Lista
        const topIpsContainerEl = document.getElementById('tdTopIpsContainer');
        if (topIpsContainerEl) {
            fetchChartData(`${ajaxBaseUrl}?action=top_ips_token&token_id=${currentTokenId}${dateParams}&limit=10`).then(apiData => {
                if (apiData && Array.isArray(apiData)) {
                    if (apiData.length === 0) {
                        topIpsContainerEl.innerHTML = '<p class="text-center text-muted" style="padding:20px;">Nincs IP cím adat a kiválasztott időszakban.</p>';
                    } else {
                        let tableHtml = '<table class="simple-table"><thead><tr><th>IP Cím</th><th>Megnyitások</th><th>Utoljára Látva</th></tr></thead><tbody>';
                        apiData.forEach(ipInfo => {
                            tableHtml += `<tr>
                                <td>${escapeHtml(ipInfo.ip_address)}</td>
                                <td class="text-center">${ipInfo.open_count}</td>
                                <td>${formatDateForDisplay(ipInfo.last_seen)}</td>
                            </tr>`;
                        });
                        tableHtml += '</tbody></table>';
                        topIpsContainerEl.innerHTML = tableHtml;
                    }
                } else {
                    topIpsContainerEl.innerHTML = '<p class="text-center text-danger" style="padding:20px;">Hiba az IP címek betöltésekor.</p>';
                }
            });
        }
                // Heti Megnyitások (Tokenre)
        destroyChartIfExists('tdWeeklyOpensChart');
        const tdWeeklyOpensCtx = document.getElementById('tdWeeklyOpensChart')?.getContext('2d');
        if (tdWeeklyOpensCtx) {
            fetchChartData(`${ajaxBaseUrl}?action=weekly_opens_token&token_id=${currentTokenId}${dateParams}`).then(apiData => {
                if (apiData && apiData.labels && apiData.data && apiData.data.some(d => d > 0)) {
                    CHART_INSTANCES_TD['tdWeeklyOpensChart'] = new Chart(tdWeeklyOpensCtx, {
                        type: 'bar',
                        data: { labels: apiData.labels, datasets: [{ label: 'Heti Megnyitások', data: apiData.data, backgroundColor: ptColors.purpleTransparent, borderColor: ptColors.purple, borderWidth: 1 }] },
                        options: { responsive: true, maintainAspectRatio: false, scales: { x: { type: 'time', time: { unit: 'week', tooltipFormat: 'yyyy MMM dd', displayFormats: { week: 'MMM dd' } }}, y: { beginAtZero: true, ticks:{precision:0} }}, plugins:{legend:{display:false}} }
                    });
                } else if (tdWeeklyOpensCtx.canvas) { tdWeeklyOpensCtx.canvas.parentElement.innerHTML = '<p class="text-muted text-center" style="padding-top:20px;">Nincs adat.</p>';}
            });
        }
        // Havi Megnyitások (Tokenre)
        destroyChartIfExists('tdMonthlyOpensChart');
        const tdMonthlyOpensCtx = document.getElementById('tdMonthlyOpensChart')?.getContext('2d');
        if (tdMonthlyOpensCtx) {
            fetchChartData(`${ajaxBaseUrl}?action=monthly_opens_token&token_id=${currentTokenId}${dateParams}`).then(apiData => {
                if (apiData && apiData.labels && apiData.data && apiData.data.some(d => d > 0)) {
                    CHART_INSTANCES_TD['tdMonthlyOpensChart'] = new Chart(tdMonthlyOpensCtx, {
                        type: 'bar',
                        data: { labels: apiData.labels, datasets: [{ label: 'Havi Megnyitások', data: apiData.data, backgroundColor: ptColors.orangeTransparent, borderColor: ptColors.orange, borderWidth: 1 }] },
                        options: { responsive: true, maintainAspectRatio: false, scales: { x: { type: 'time', time: { unit: 'month', parser: 'yyyy-MM', tooltipFormat: 'yyyy MMMM', displayFormats: { month: 'yyyy MMM' } }}, y: { beginAtZero: true, ticks:{precision:0} }}, plugins:{legend:{display:false}} }
                    });
                } else if (tdMonthlyOpensCtx.canvas) { tdMonthlyOpensCtx.canvas.parentElement.innerHTML = '<p class="text-muted text-center" style="padding-top:20px;">Nincs adat.</p>';}
            });
        }
    
        // Megnyitások Hónap Napjai Szerint (Tokenre)
        destroyChartIfExists('tdMonthDayOpensChart');
        const tdMonthDayCtx = document.getElementById('tdMonthDayOpensChart')?.getContext('2d');
        if (tdMonthDayCtx) {
            fetchChartData(`${ajaxBaseUrl}?action=opens_by_month_day_token&token_id=${currentTokenId}${dateParams}`).then(apiData => {
                if (apiData && apiData.labels && apiData.data && apiData.data.some(d => d > 0)) {
                    CHART_INSTANCES_TD['tdMonthDayOpensChart'] = new Chart(tdMonthDayCtx, {
                        type: 'bar',
                        data: { 
                            labels: apiData.labels, // 1-31
                            datasets: [{ label: 'Megnyitások a hónap napján', data: apiData.data, backgroundColor: ptColors.contrastHighlight }] 
                        },
                        options: { responsive: true, maintainAspectRatio: false, scales: {x: {title:{display:true, text:'Hónap Napja'}}, y: { beginAtZero: true, ticks:{precision:0} }}, plugins:{legend:{display:false}} }
                    });
                } else if (tdMonthDayCtx.canvas) { tdMonthDayCtx.canvas.parentElement.innerHTML = '<p class="text-muted text-center" style="padding-top:20px;">Nincs adat.</p>';}
            });
        }
        // Böngésző Motorok
        destroyChartIfExists('tdBrowserEngineChart');
        const tdEngineCtx = document.getElementById('tdBrowserEngineChart')?.getContext('2d');
        if (tdEngineCtx) {
            fetchChartData(`${ajaxBaseUrl}?action=browser_engine_distribution_token&token_id=${currentTokenId}${dateParams}`).then(apiData => {
                if (apiData && apiData.labels && apiData.data && apiData.data.length > 0) {
                    CHART_INSTANCES_TD['tdBrowserEngineChart'] = new Chart(tdEngineCtx, {
                        type: 'pie',
                        data: { labels: apiData.labels, datasets: [{ data: apiData.data, backgroundColor: doughnutColorsTd.slice(0, apiData.labels.length), borderColor: ptColors.glassBg }] },
                        options: doughnutPieOptionsTd
                    });
                } else if (tdEngineCtx.canvas) { tdEngineCtx.canvas.parentElement.innerHTML = '<p class="text-muted text-center" style="padding-top:20px;">Nincs adat.</p>';}
            });
        }
        // Mobil Eszköz Márkák
        destroyChartIfExists('tdMobileBrandChart');
        const tdMobileBrandCtx = document.getElementById('tdMobileBrandChart')?.getContext('2d');
        if (tdMobileBrandCtx) {
            fetchChartData(`${ajaxBaseUrl}?action=mobile_brand_distribution_token&token_id=${currentTokenId}${dateParams}`).then(apiData => {
                if (apiData && apiData.labels && apiData.data && apiData.data.length > 0) {
                    CHART_INSTANCES_TD['tdMobileBrandChart'] = new Chart(tdMobileBrandCtx, {
                        type: 'pie',
                        data: { labels: apiData.labels, datasets: [{ data: apiData.data, backgroundColor: doughnutColorsTd.slice().reverse().slice(0, apiData.labels.length), borderColor: ptColors.glassBg }] },
                        options: doughnutPieOptionsTd
                    });
                } else if (tdMobileBrandCtx.canvas) { tdMobileBrandCtx.canvas.parentElement.innerHTML = '<p class="text-muted text-center" style="padding-top:20px;">Nincs mobil márka adat.</p>';}
            });
        }
        
        // Referrer Típusok (Kereső, Közösségi)
        destroyChartIfExists('tdSearchEngineReferrerChart');
        const tdSearchRefCtx = document.getElementById('tdSearchEngineReferrerChart')?.getContext('2d');
        destroyChartIfExists('tdSocialMediaReferrerChart');
        const tdSocialRefCtx = document.getElementById('tdSocialMediaReferrerChart')?.getContext('2d');

        if (tdSearchRefCtx || tdSocialRefCtx) {
            fetchChartData(`${ajaxBaseUrl}?action=referrer_type_distribution_token&token_id=${currentTokenId}${dateParams}`).then(apiData => {
                if (apiData && apiData.labels && apiData.data) {
                    const searchData = { labels: [], data: [] };
                    const socialData = { labels: [], data: [] };
                    let hasSearch = false;
                    let hasSocial = false;
                    apiData.labels.forEach((label, index) => {
                        if (label === 'Keresőmotor') {
                            searchData.labels.push(label);
                            searchData.data.push(apiData.data[index]);
                            hasSearch = true;
                        } else if (label === 'Közösségi Média') {
                            socialData.labels.push(label);
                            socialData.data.push(apiData.data[index]);
                            hasSocial = true;
                        }
                        // A "Direkt/Ismeretlen" és "Egyéb Hivatkozó" most nem kerül külön grafikonra,
                        // de a `apiData` tartalmazza őket, ha kellenének.
                    });
                    
                    // Keresőmotorok Aránya (ha van adat)
                    if (tdSearchRefCtx && hasSearch) {
                        CHART_INSTANCES_TD['tdSearchEngineReferrerChart'] = new Chart(tdSearchRefCtx, {
                            type: 'doughnut',
                            data: { 
                                labels: searchData.labels, // Csak a "Keresőmotor" címke
                                datasets: [{ 
                                    data: searchData.data, // Csak a keresőmotorok száma
                                    backgroundColor: [ptColors.green], 
                                    borderColor: ptColors.glassBg 
                                }] 
                            },
                            options: { ...doughnutPieOptionsTd, plugins: {...doughnutPieOptionsTd.plugins, legend: {display:false}, title: {display:true, text: `Kereső: ${searchData.data[0]}`}} }
                        });
                    } else if (tdSearchRefCtx && tdSearchRefCtx.canvas) {
                        tdSearchRefCtx.canvas.parentElement.innerHTML = '<p class="text-muted text-center" style="padding-top:20px;">Nincs keresőmotor forgalom.</p>';
                    }
                    // Közösségi Média Aránya (ha van adat)
                    if (tdSocialRefCtx && hasSocial) {
                        CHART_INSTANCES_TD['tdSocialMediaReferrerChart'] = new Chart(tdSocialRefCtx, {
                            type: 'doughnut',
                            data: { 
                                labels: socialData.labels, // Csak a "Közösségi Média" címke
                                datasets: [{ 
                                    data: socialData.data, 
                                    backgroundColor: [ptColors.lightBlue], 
                                    borderColor: ptColors.glassBg 
                                }] 
                            },
                            options: { ...doughnutPieOptionsTd, plugins: {...doughnutPieOptionsTd.plugins, legend: {display:false}, title: {display:true, text: `Közösségi: ${socialData.data[0]}`}} }
                        });
                    } else if (tdSocialRefCtx && tdSocialRefCtx.canvas) {
                        tdSocialRefCtx.canvas.parentElement.innerHTML = '<p class="text-muted text-center" style="padding-top:20px;">Nincs közösségi média forgalom.</p>';
                    }
                } else {
                    if (tdSearchRefCtx && tdSearchRefCtx.canvas) tdSearchRefCtx.canvas.parentElement.innerHTML = '<p class="text-muted text-center" style="padding-top:20px;">Nincs adat.</p>';
                    if (tdSocialRefCtx && tdSocialRefCtx.canvas) tdSocialRefCtx.canvas.parentElement.innerHTML = '<p class="text-muted text-center" style="padding-top:20px;">Nincs adat.</p>';
                }
            });
        }
        // Kiegészítő Információk frissítése
        // Token létrehozás ideje a PHP $token['created_at'] változóból
        const nowForAge = new Date();
        const ageDiffMillis = nowForAge - tokenCreatedAt;
        const ageDays = Math.floor(ageDiffMillis / (1000 * 60 * 60 * 24));
        const ageHours = Math.floor((ageDiffMillis % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
        if(document.getElementById('infoTokenAge')) document.getElementById('infoTokenAge').textContent = `${ageDays} nap, ${ageHours} órája`;
        fetchChartData(`${ajaxBaseUrl}?action=token_extended_info&token_id=${currentTokenId}&start_date_info=${startDate}&end_date_info=${endDate}`).then(apiData => {
            if (apiData && !apiData.error) {
                if(document.getElementById('infoFirstOpen')) document.getElementById('infoFirstOpen').textContent = apiData.first_open_date ? formatDateForDisplay(apiData.first_open_date) : 'Még nem volt';
                if(document.getElementById('infoLastOpen')) document.getElementById('infoLastOpen').textContent = apiData.last_open_date ? formatDateForDisplay(apiData.last_open_date) : 'Még nem volt';
                if(document.getElementById('infoMostActiveDay')) document.getElementById('infoMostActiveDay').textContent = escapeHtml(apiData.most_active_day);
                if(document.getElementById('infoTopIp')) document.getElementById('infoTopIp').textContent = escapeHtml(apiData.top_ip);
                if(document.getElementById('infoTopReferrer')) document.getElementById('infoTopReferrer').textContent = escapeHtml(apiData.top_referrer_domain);
                if(document.getElementById('infoActiveDaysInPeriod')) document.getElementById('infoActiveDaysInPeriod').textContent = `${apiData.active_days_in_selected_period} nap`;
                const noDataWarningEl = document.getElementById('infoNoDataWarning');
                if (noDataWarningEl) {
                    if (startDate && endDate && apiData.active_days_in_selected_period === 0 && (apiData.first_open_date || apiData.last_open_date)) {
                        // Van korábbi aktivitás, de a szűrt periódusban nincs
                        noDataWarningEl.style.display = 'block';
                    } else {
                        noDataWarningEl.style.display = 'none';
                    }
                }
            } else {
                console.warn("Hiba a kiegészítő token információk betöltésekor:", apiData?.error);
                // Alapértelmezett szövegek, ha hiba van
                ['infoFirstOpen', 'infoLastOpen', 'infoMostActiveDay', 'infoTopIp', 'infoTopReferrer', 'infoActiveDaysInPeriod'].forEach(id => {
                    if(document.getElementById(id)) document.getElementById(id).textContent = 'Hiba';
                });
            }
        });
    }
    // loadTokenSpecificCharts vége
    // Kezdeti betöltés az URL-ben lévő (vagy alapértelmezett) dátumokkal
    // Ezeket a PHP már beállította az input mezők `value` attribútumában.
    const initialStartDate = startDateInput.value;
    const initialEndDate = endDateInput.value;
    loadTokenSpecificCharts(initialStartDate, initialEndDate);
});
