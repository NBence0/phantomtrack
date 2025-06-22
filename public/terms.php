<?php 
// Helye: public/terms.php

// Behívjuk az oldalsáv nélküli, publikus fejlécet
require_once __DIR__ . '/../includes/header_public.php'; 

// Oldal címe
$pageTitle = "Általános Szerződési Feltételek";
?>

<div class="content-header">
    <h1><?php echo escape($pageTitle); ?></h1>
</div>

<div class="static-page-content glass-effect">
    <h2>1. A Szolgáltatás Tárgya</h2>
    <p>Jelen Általános Szerződési Feltételek (továbbiakban: ÁSZF) a PhantomTrack képpontkövető szolgáltatás (továbbiakban: Szolgáltatás) használatának feltételeit szabályozzák.</p>
    <p>A Szolgáltatás célja, hogy a regisztrált felhasználók (továbbiakban: Felhasználók) digitális tartalmaik megnyitásait követhessék nyomon statisztikai célból.</p>
    <p>A Szolgáltatás használata díjmentes, de regisztrációhoz kötött. A regisztrációval a Felhasználó elfogadja a jelen ÁSZF és az Adatkezelési Tájékoztató rendelkezéseit.</p>

    <h2>2. A Szolgáltatás Használatának Feltételei</h2>
    <p>A Felhasználó köteles a Szolgáltatást jogszerűen, a hatályos adatvédelmi és elektronikus kereskedelmi jogszabályokkal összhangban használni.</p>
    <p>Tilos a Szolgáltatás használata:</p>
    <ul>
        <li>kéretlen elektronikus levelek (spam) küldésére,</li>
        <li>bármilyen illegális tevékenység követésére vagy elősegítésére,</li>
        <li>olyan oldalon való elhelyezésre, amely sérti harmadik fél jogait.</li>
    </ul>
    <p>A Felhasználó köteles a saját látogatói felé biztosítani a követőkód használatára vonatkozó megfelelő adatkezelési tájékoztatást.</p>

    <h2>3. A Szolgáltató Jogai és Felelőssége</h2>
    <p>A Szolgáltató (NBence) minden ésszerű intézkedést megtesz a Szolgáltatás folyamatos, hibamentes működése érdekében, de nem garantálja annak megszakításmentes vagy hibamentes működését.</p>
    <p>A Szolgáltató nem vállal felelősséget a Szolgáltatás használatából eredő esetleges közvetett vagy közvetlen károkért, beleértve az adatvesztést, szolgáltatáskiesést, vagy a Felhasználó által okozott harmadik fél általi jogsértéseket.</p>

    <h2>4. Fiókhasználat és Törlés</h2>
    <p>A Felhasználó a regisztrációval jogosulttá válik a Szolgáltatás igénybevételére. A fiók törlése a <strong>Kapcsolat</strong> oldalon keresztül kérvényezhető. A törlés végleges, az adatok visszaállítására nincs lehetőség.</p>

    <h2>5. Felelősség Korlátozása</h2>
    <p>A Szolgáltatás "ahogy van" ("as is") formában érhető el. A Szolgáltató kizár minden kifejezett vagy beleértett garanciát, beleértve, de nem kizárólagosan a pontosságra, megbízhatóságra, vagy egy adott célra való alkalmasságra vonatkozó garanciákat.</p>

    <h2>6. Szerzői jogok és tartalomhasználat</h2>
    <p>A Szolgáltatás teljes tartalma – beleértve a forráskódot, felületet, szövegeket, vizuális elemeket és működési logikát – szerzői jogi védelem alatt áll. A tartalom másolása, újrafelhasználása, módosítása vagy terjesztése a jogosult előzetes engedélye nélkül tilos.</p>
    <p>Bármely ilyen tevékenység jogi következményeket vonhat maga után, különös tekintettel a szellemi tulajdonjog megsértésére.</p>
    <p><strong>Kivételt képeznek ez alól</strong> a rendszer által generált adatok, mint például a követőkód-tokenek, statisztikai grafikonok és azok adattartalma – ezek szabadon felhasználhatók, megoszthatók.</p>
    <p>A jogosulatlan tartalommásolást a rendszer naplózhatja, és szükség esetén a jogsértés bizonyítékául szolgálhat.</p>

    <h2>7. Adatvédelem</h2>
    <p>A Szolgáltató a Felhasználók személyes adatait az <a href="<?php echo BASE_URL; ?>public/privacy.php">Adatkezelési Nyilatkozat</a> rögzített elvek szerint kezeli. Az adatkezelési tájékoztató elérhető a weboldal láblécéből.</p>

    <h2>8. Az ÁSZF Módosítása</h2>
    <p>A Szolgáltató jogosult jelen ÁSZF egyoldalú módosítására. A módosításokról a Felhasználók a weboldalon keresztül értesülnek. A módosított ÁSZF a közzététel időpontjától érvényes és alkalmazandó.</p>

    <h2>9. Irányadó Jog és Jogviták</h2>
    <p>Jelen ÁSZF-re a magyar jog az irányadó. A Felek vállalják, hogy a vitás kérdéseket elsősorban békés úton rendezik. Amennyiben ez nem vezet eredményre, a jogvita elbírálására a Szolgáltató székhelye szerinti illetékes bíróság kizárólagosan jogosult.</p>

    <p><em>Hatályba lépés dátuma: 2024. 01. 01. – Utolsó módosítás: 2025. 06. 22.</em></p>
</div>


<?php 
// Behívjuk az oldalsáv nélküli, publikus láblécet
require_once __DIR__ . '/../includes/footer_public.php'; 
?>