   
<h2 class="section-title" style="margin-top:30px;"><i class="fas fa-link"></i> Top Referrerek</h2>
<div class="dashboard-section">
    <div class="chart-container glass-effect" style="height: 300px; padding: 15px;">
        <canvas id="tdTopReferrersChart"></canvas>
    </div>
</div>

<h2 class="section-title" style="margin-top:30px;"><i class="fas fa-map-marked-alt"></i> Földrajzi Adatok</h2>
<div class="dashboard-grid" style="grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));">
    <div class="dashboard-section">
        <h3 class="subsection-title"><i class="fas fa-globe-americas"></i> Top Országok</h3>
        <div class="chart-container glass-effect" style="height: 300px; padding: 15px;">
            <canvas id="tdCountryDistributionChart"></canvas>
        </div>
    </div>
    <div class="dashboard-section">
        <h3 class="subsection-title"><i class="fas fa-city"></i> Top Városok</h3>
        <div class="chart-container glass-effect" style="height: 300px; padding: 15px;">
            <canvas id="tdCityDistributionChart"></canvas>
        </div>
    </div>
     <div class="dashboard-section">
        <h3 class="subsection-title"><i class="fas fa-network-wired"></i> Top ISP-k</h3>
        <div class="chart-container glass-effect" style="height: 300px; padding: 15px;">
            <canvas id="tdIspDistributionChart"></canvas>
        </div>
    </div>
</div>

<h2 class="section-title" style="margin-top:30px;"><i class="fas fa-microchip"></i> Technikai Részletek</h2>
<div class="dashboard-grid" style="grid-template-columns: 1fr 1fr;"> <?php // Két oszlop ?>
    <div class="dashboard-section">
        <h3 class="subsection-title"><i class="fas fa-calendar-day"></i> Megnyitások Hét Napjai Szerint</h3>
        <div class="chart-container glass-effect" style="height: 280px; padding: 15px;">
            <canvas id="tdDayOfWeekChart"></canvas>
        </div>
    </div>
    <div class="dashboard-section">
        <h3 class="subsection-title"><i class="far fa-clock"></i> Óránkénti Aktivitás (Elmúlt 24 óra)</h3>
        <div class="chart-container glass-effect" style="height: 280px; padding: 15px;">
            <canvas id="tdHourlyLast24hChart"></canvas>
        </div>
    </div>
</div>

<div class="dashboard-section" style="margin-top:20px;">
    <h3 class="subsection-title"><i class="fas fa-map-pin"></i> Leggyakoribb IP Címek (Top 10)</h3>
    <div class="table-container glass-effect" style="max-height: 300px; overflow-y: auto;" id="tdTopIpsContainer">
        <p class="text-center text-muted" style="padding:20px;">Betöltés...</p>
    </div>
</div>

<h2 class="section-title" style="margin-top:30px;"><i class="fas fa-cubes"></i> Összesített Statisztikák (Kiválasztott Időszakra)</h2>
<div class="token-stats-cards-grid"> <?php // Most itt vannak a stat kártyák ?>
    <div class="stat-card glass-effect">
        <h3><i class="far fa-eye"></i> Összes Megnyitás</h3>
        <p class="stat-value" id="tdTotalOpensVal">Betöltés...</p>
        <small id="tdTotalOpensPeriod">Kiválasztott időszak</small>
    </div>
    <div class="stat-card glass-effect">
        <h3><i class="fas fa-users"></i> Egyedi IP-k</h3>
        <p class="stat-value" id="tdUniqueIpsVal">Betöltés...</p>
        <small id="tdUniqueIpsPeriod">Kiválasztott időszak</small>
    </div>
     <div class="stat-card glass-effect">
        <h3><i class="fas fa-user-friends"></i> Egyedi / Összes Arány</h3>
        <p class="stat-value" id="tdUniqueRatioVal">Betöltés...</p>
        <small id="tdUniqueRatioInfo">Arány</small>
    </div>
</div>



<h2 class="section-title" style="margin-top:30px;"><i class="fas fa-chalkboard-teacher"></i> Látogatói Profil</h2>
<div class="dashboard-grid" style="grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));">
    <div class="dashboard-section">
        <h3 class="subsection-title"><i class="fab fa-firefox-browser"></i> Böngészők</h3>
        <div class="chart-container-small glass-effect">
            <canvas id="tdBrowserDistributionChart"></canvas>
        </div>
    </div>
    <div class="dashboard-section">
        <h3 class="subsection-title"><i class="fas fa-desktop"></i> Operációs Rendszerek</h3>
        <div class="chart-container-small glass-effect">
            <canvas id="tdOsDistributionChart"></canvas>
        </div>
    </div>
    <div class="dashboard-section">
        <h3 class="subsection-title"><i class="fas fa-mobile-alt"></i> Eszköztípusok</h3>
        <div class="chart-container-small glass-effect">
            <canvas id="tdDeviceTypeDistributionChart"></canvas>
        </div>
    </div>
</div>
<h2 class="section-title" style="margin-top:30px;"><i class="fas fa-calendar-alt"></i> Időbeli Elemzések Részletesen</h2>
<div class="dashboard-grid" style="grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));">
    <div class="dashboard-section">
        <h3 class="subsection-title"><i class="fas fa-calendar-week"></i> Heti Megnyitások</h3>
        <div class="chart-container glass-effect" style="height: 280px; padding: 15px;">
            <canvas id="tdWeeklyOpensChart"></canvas>
        </div>
    </div>
    <div class="dashboard-section">
        <h3 class="subsection-title"><i class="far fa-calendar-alt"></i> Havi Megnyitások</h3>
        <div class="chart-container glass-effect" style="height: 280px; padding: 15px;">
            <canvas id="tdMonthlyOpensChart"></canvas>
        </div>
    </div>
    <div class="dashboard-section">
        <h3 class="subsection-title"><i class="fas fa-calendar"></i> Megnyitások Hónap Napjai Szerint</h3>
        <div class="chart-container glass-effect" style="height: 280px; padding: 15px;">
            <canvas id="tdMonthDayOpensChart"></canvas>
        </div>
    </div>
</div>

<h2 class="section-title" style="margin-top:30px;"><i class="fas fa-cogs"></i> Látogatói Technológia Mélyebben</h2>
<div class="dashboard-grid" style="grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));">
    <div class="dashboard-section">
        <h3 class="subsection-title"><i class="fab fa-html5"></i> Böngésző Motorok</h3>
        <div class="chart-container-small glass-effect">
            <canvas id="tdBrowserEngineChart"></canvas>
        </div>
    </div>
    <div class="dashboard-section">
        <h3 class="subsection-title"><i class="fas fa-mobile"></i> Mobil Eszköz Márkák</h3>
        <div class="chart-container-small glass-effect">
            <canvas id="tdMobileBrandChart"></canvas>
        </div>
    </div>
</div>

<h2 class="section-title" style="margin-top:30px;"><i class="fas fa-share-alt"></i> Forgalmi Források Részletesen</h2>
<div class="dashboard-grid" style="grid-template-columns: 1fr 1fr;">
    <div class="dashboard-section">
        <h3 class="subsection-title"><i class="fas fa-search"></i> Keresőmotorok Aránya</h3>
        <div class="chart-container-small glass-effect">
            <canvas id="tdSearchEngineReferrerChart"></canvas>
        </div>
    </div>
    <div class="dashboard-section">
        <h3 class="subsection-title"><i class="fas fa-users"></i> Közösségi Média Aránya</h3>
        <div class="chart-container-small glass-effect">
            <canvas id="tdSocialMediaReferrerChart"></canvas>
        </div>
    </div>
</div>

<h2 class="section-title" style="margin-top:30px;"><i class="fas fa-info-circle"></i> Kiegészítő Információk a Tokenről</h2>
<div class="info-cards-extended-grid glass-effect" style="padding: var(--card-padding);">
    <div class="info-item-extended">
        <h4><i class="far fa-clock"></i> Token Életkora:</h4>
        <p id="infoTokenAge">Betöltés...</p>
    </div>
    <div class="info-item-extended">
        <h4><i class="fas fa-calendar-check"></i> Első Megnyitás:</h4>
        <p id="infoFirstOpen">Betöltés...</p>
    </div>
    <div class="info-item-extended">
        <h4><i class="fas fa-calendar-times"></i> Utolsó Megnyitás:</h4>
        <p id="infoLastOpen">Betöltés...</p>
    </div>
    <div class="info-item-extended">
        <h4><i class="fas fa-fire"></i> Legaktívabb Nap:</h4>
        <p id="infoMostActiveDay">Betöltés...</p>
    </div>
    <div class="info-item-extended">
        <h4><i class="fas fa-skull-crossbones"></i> Top IP Cím:</h4>
        <p id="infoTopIp">Betöltés...</p>
    </div>
    <div class="info-item-extended">
        <h4><i class="fas fa-globe"></i> Top Referrer Domain:</h4>
        <p id="infoTopReferrer">Betöltés...</p>
    </div>
    <div class="info-item-extended">
        <h4><i class="fas fa-calendar-day"></i> Aktív Napok Száma (Szűrt Időszakban):</h4>
        <p id="infoActiveDaysInPeriod">Betöltés...</p>
    </div>
    <div class="info-item-extended" id="infoNoDataWarning" style="display:none; color: var(--color-warning);">
        <h4><i class="fas fa-exclamation-triangle"></i> Figyelmeztetés:</h4>
        <p>A kiválasztott dátumtartományban nincs rögzített aktivitás ehhez a tokenhez.</p>
    </div>
</div>