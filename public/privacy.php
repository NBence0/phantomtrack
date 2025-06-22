<?php 
// Helye: public/privacy.php

require_once __DIR__ . '/../includes/header_public.php'; 
$pageTitle = "Adatkezelési Nyilatkozat";
?>

<div class="content-header">
    <h1><?php echo escape($pageTitle); ?></h1>
</div>

<div class="static-page-content glass-effect">
    <h2>1. Az Adatkezelő Adatai</h2>
    <p>
        <strong>Név:</strong> NBence<br>
        <strong>Elérhetőség:</strong> Kapcsolat oldalon keresztül<br>
        <strong>Weboldal:</strong> https://phantomtrack.hu
    </p>

    <h2>2. A Kezelt Adatok Köre</h2>
    <p>A PhantomTrack szolgáltatás a regisztráció és használat során az alábbi személyes adatokat kezeli:</p>
    <ul>
        <li>Felhasználónév</li>
        <li>E-mail cím</li>
        <li>Jelszó (bcrypt hash formában, visszafejthetetlenül titkosítva)</li>
    </ul>
    <p>A Szolgáltatás használata során a követőkódok (pixelek) megnyitásakor automatikusan naplózásra kerülhetnek az alábbi információk a megnyitást végző eszközről:</p>
    <ul>
        <li>IP-cím (anonimizált, ha be van állítva)</li>
        <li>User-Agent (böngésző és operációs rendszer típusa)</li>
        <li>Hivatkozó oldal (Referrer)</li>
        <li>Földrajzi hely (ország, város – IP-geolokáció alapján, ha engedélyezett)</li>
        <li>Időbélyeg (timestamp)</li>
    </ul>
    <p><strong>Fontos:</strong> A Felhasználó kizárólagos felelőssége, hogy a saját látogatói felé megfelelő adatkezelési tájékoztatást nyújtson a követőkód használatáról, a GDPR 13–14. cikkének megfelelően.</p>

    <h2>3. Az Adatkezelés Célja és Jogalapja</h2>
    <p>Az adatkezelés célja a szolgáltatás működtetése, a Felhasználók azonosítása, statisztikák előállítása, valamint a visszaélések megelőzése.</p>
    <p>Az adatkezelés jogalapja:
        <ul>
            <li>Regisztrált felhasználók esetén: a regisztrációval adott önkéntes hozzájárulás (GDPR 6. cikk (1) bekezdés a) pont)</li>
            <li>Technikai naplóadatok esetén: jogos érdek (GDPR 6. cikk (1) f) – a rendszer biztonsága, visszaélések megelőzése)</li>
        </ul>
    </p>

    <h2>4. Adattárolás Időtartama</h2>
    <p>A regisztráció során megadott személyes adatokat a felhasználói fiók törléséig, vagy a hozzájárulás visszavonásáig tároljuk. A naplóadatokat legfeljebb 90 napig őrizzük, kivéve, ha jogi kötelezettség hosszabb tárolást ír elő.</p>

    <h2>5. Az Érintettek Jogai</h2>
    <p>A Felhasználót az alábbi jogok illetik meg:</p>
    <ul>
        <li>Hozzáférés a rá vonatkozó adatokhoz</li>
        <li>Helyesbítés kérése</li>
        <li>Törlés kérése (pl. kapcsolatfelvételi űrlapon keresztül)</li>
        <li>Adatkezelés korlátozása</li>
        <li>Adathordozhatósághoz való jog</li>
        <li>Hozzájárulás visszavonása (nem érinti a visszavonás előtti jogszerűséget)</li>
        <li>Panasz benyújtása a Nemzeti Adatvédelmi és Információszabadság Hatósághoz (NAIH)</li>
    </ul>

    <h2>6. Adatbiztonság</h2>
    <p>Az Adatkezelő minden szükséges technikai és szervezési intézkedést megtesz annak érdekében, hogy az adatokat biztonságosan kezelje és megvédje a jogosulatlan hozzáféréstől, módosítástól, törléstől vagy nyilvánosságra hozataltól. Ide tartozik például a HTTPS titkosítás, jelszóhash-elés, és rendszeres mentések.</p>

    <h2>7. Cookie-k és Külső Szolgáltatók</h2>
    <p>A Szolgáltatás működéséhez technikai jellegű cookie-kat használunk, amelyek nem alkalmasak a felhasználók beazonosítására. Harmadik fél (pl. analitika, CDN) szolgáltatók nem férnek hozzá a személyes adatokhoz.</p>

    <h2>8. Egyéb rendelkezések</h2>
    <p>Ez a tájékoztató a Szolgáltatás honlapjának láblécéből bármikor elérhető. Az Adatkezelő fenntartja a jogot, hogy jelen tájékoztatót egyoldalúan módosítsa. A módosításokról a Felhasználókat értesíti.</p>

    <p><em>Hatályba lépés dátuma: 2024. 01. 01. – Utolsó módosítás: 2025. 06. 22.</em></p>
</div>

<?php require_once __DIR__ . '/../includes/footer_public.php'; ?>