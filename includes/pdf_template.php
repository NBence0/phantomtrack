<?php
/**
 * Ez a template fájl generálja a PDF riport HTML és CSS kódját.
 * (VERZIÓ 8.0 - Stabil Táblázatos Grid a kompakt elrendezésért)
 *
 * Szükséges változók a meghívó szkriptből:
 * - $tokenName (string): A követő token neve.
 * - $charts (array): A base64 kódolt grafikonokat tartalmazó tömb.
 * - $currentUsername (string): A riportot generáló felhasználó neve.
 */
?>
<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <style>
        @page {
            header: html_myHeader;
            footer: html_myFooter;
            margin-top: 25mm;
            margin-bottom: 20mm;
            margin-left: 15mm;
            margin-right: 15mm;
        }
        body { 
            font-family: "dejavusans", sans-serif; 
            font-size: 9pt; 
            color: #333; 
        }
        h1, h2, h3.section-title { 
            font-family: "dejavusanscondensed", sans-serif; 
            font-weight: bold; 
            page-break-after: avoid; 
        }
        h1 { 
            font-size: 20pt; 
            color: #0F0F23; 
            text-align: center; 
            margin-bottom: 5px; 
        }
        h2 { 
            font-size: 14pt; 
            color: #00D4FF; 
            text-align: center; 
            margin-top: 0; 
            margin-bottom: 25px; 
            font-weight: normal; 
        }
        h3.section-title {
            font-size: 13pt;
            color: #0F0F23;
            border-bottom: 2px solid #4ECDC4;
            padding-bottom: 4px;
            margin-top: 15px;
            margin-bottom: 10px;
        }
        .chart-container {
            border: 1px solid #e8e8e8;
            padding: 10px;
            margin-bottom: 10px;
            page-break-inside: avoid;
        }
        .chart-title { 
            font-size: 10pt; 
            font-weight: bold; 
            text-align: center; 
            margin-bottom: 5px; 
        }
        img { 
            max-width: 100%; 
            height: auto; 
            display: block; 
            margin: 0 auto; 
        }
        
        /* === TÁBLÁZATOS GRID RENDSZER === */
        .grid-table {
            width: 100%;
            border-collapse: separate;
            /* A cellák közötti vízszintes térköz */
            border-spacing: 12px 0; 
            page-break-inside: avoid;
        }
        .grid-table td {
            width: 50%; /* Alapértelmezett kétoszlopos */
            vertical-align: top;
            padding: 0;
        }
    </style>
</head>
<body>
    <?php
        $chartMap = [];
        if (is_array($charts)) {
            foreach ($charts as $chart) {
                if (isset($chart['title'])) {
                    $key = strtolower(str_replace(' ', '', $chart['title']));
                    $chartMap[$key] = $chart;
                }
            }
        }
        
        function render_chart_html($key, $title, $chartMap) {
            $key = strtolower(str_replace(' ', '', $key));
            $html = '<div class="chart-container">';
            $html .= '<div class="chart-title">' . escape($title) . '</div>';
            if (isset($chartMap[$key]) && !empty($chartMap[$key]['imageData']) && strpos($chartMap[$key]['imageData'], 'data:image/png;base64,') === 0) {
                $html .= '<img src="' . $chartMap[$key]['imageData'] . '">';
            } else {
                $html .= '<p style="text-align:center; color:#999; padding: 20px 0;">Nincs megjeleníthető adat.</p>';
            }
            $html .= '</div>';
            return $html;
        }
    ?>

    <!-- Fejléc és Lábléc HTML definíciója az mPDF számára -->
    <htmlpageheader name="myHeader">
        <div style="border-bottom: 1px solid #00D4FF; font-size: 9pt; text-align: center; padding-bottom: 3px; color: #444;">
            PhantomTrack Teljesítmény Riport: <strong><?php echo escape($tokenName); ?></strong>
        </div>
    </htmlpageheader>

    <htmlpagefooter name="myFooter">
        <div style="border-top: 1px solid #ccc; font-size: 9pt; text-align: center; padding-top: 3px;">
            Generálta: <?php echo escape($currentUsername); ?> | {DATE Y-m-d H:i} | {PAGENO}/{nbpg} oldal
        </div>
    </htmlpagefooter>


    <!-- TARTALOM (KOMPAKT, TÁBLÁZATOS ELRENDEZÉSSEL) -->
    <h1>PhantomTrack Teljesítmény Riport</h1>
    <h2><?php echo escape($tokenName); ?></h2>
    
    <h3 class="section-title">Áttekintő Grafikonok</h3>
    <?php echo render_chart_html('Napi Megnyitások', 'Napi Megnyitások', $chartMap); ?>
    <?php echo render_chart_html('Óránkénti Aktivitás', 'Óránkénti Aktivitás', $chartMap); ?>
    <?php echo render_chart_html('Top Referrerek', 'Top Referrerek', $chartMap); ?>

    <h3 class="section-title">Látogatói Profil</h3>
    <table class="grid-table">
        <tr>
            <td><?php echo render_chart_html('Böngésző Eloszlás', 'Böngésző Eloszlás', $chartMap); ?></td>
            <td><?php echo render_chart_html('Operációs Rendszer Eloszlás', 'Operációs Rendszer Eloszlás', $chartMap); ?></td>
        </tr>
        <tr>
            <td><?php echo render_chart_html('Eszköztípus Eloszlás', 'Eszköztípus Eloszlás', $chartMap); ?></td>
            <td><?php echo render_chart_html('Böngésző Motorok', 'Böngésző Motorok', $chartMap); ?></td>
        </tr>
    </table>
    <?php echo render_chart_html('Mobil Eszköz Márkák', 'Mobil Eszköz Márkák', $chartMap); ?>
    
    <pagebreak />

    <h3 class="section-title">Földrajzi Adatok</h3>
    <table class="grid-table">
         <tr>
            <td><?php echo render_chart_html('Top Országok', 'Top Országok', $chartMap); ?></td>
            <td><?php echo render_chart_html('Top Városok', 'Top Városok', $chartMap); ?></td>
        </tr>
    </table>
    <?php echo render_chart_html('Top ISP-k', 'Top ISP-k', $chartMap); ?>
    
    <pagebreak />

    <h3 class="section-title">Időbeli Elemzések</h3>
    <table class="grid-table">
        <tr>
            <td><?php echo render_chart_html('Heti Megnyitások', 'Heti Megnyitások', $chartMap); ?></td>
            <td><?php echo render_chart_html('Havi Megnyitások', 'Havi Megnyitások', $chartMap); ?></td>
        </tr>
    </table>
    <?php echo render_chart_html('Megnyitások a Hét Napjai Szerint', 'Megnyitások a Hét Napjai Szerint', $chartMap); ?>
    <?php echo render_chart_html('Megnyitások a Hónap Napjai Szerint', 'Megnyitások a Hónap Napjai Szerint', $chartMap); ?>
    
</body>
</html>