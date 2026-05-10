#!/bin/bash
# PhantomTrack - Gallery Pretty URL fix
# Futtatás: sudo bash /var/www/nbence.hu/phantomtrack/fix_gallery_routes.sh

CONF="/etc/apache2/sites-enabled/001-final.conf"
BACKUP="/etc/apache2/sites-enabled/001-final.conf.bak.$(date +%Y%m%d_%H%M%S)"

echo "=== PhantomTrack Gallery Route Fix ==="
echo ""

# 1. Backup
echo "[1/4] Backup készítése: $BACKUP"
cp "$CONF" "$BACKUP"
echo "      OK"

# 2. Ellenőrzés: már be van-e írva?
if grep -q "PHANTOMTRACK: Gallery Pretty URL" "$CONF"; then
    echo "[!] A gallery kivétel már szerepel a konfigban. Kilépés."
    exit 0
fi

# 3. Insert the gallery exception BEFORE the nbence.hu aldomain block
python3 - <<'PYEOF'
import re

conf_path = "/etc/apache2/sites-enabled/001-final.conf"

with open(conf_path, 'r') as f:
    content = f.read()

# Keresés: a nbence.hu VirtualHost blokkban az aldomain rewrite előtt
# Az általános aldomain -> mappa rész nbence.hu-hoz
OLD = """    # Aldomain -> mappa
    RewriteCond %{HTTP_HOST} ^([^.]+)\\.nbence\\.hu$ [NC]
    RewriteCond %{HTTP_HOST} !^www\\. [NC]
    RewriteCond %{REQUEST_URI} !^/%1/
    RewriteRule ^(.*)$ /%1/$1 [L]
</VirtualHost>

# ========================================================
# 3. NBTMP.HU"""

NEW = """    # PHANTOMTRACK: Gallery Pretty URL kivétel
    # Ezt az altalanos aldomain rewrite ELOTT kell kezelni!
    RewriteCond %{HTTP_HOST} ^phantomtrack\\.nbence\\.hu$ [NC]
    RewriteRule ^/gallery/([^/]+)/([^/]+)/?$ /phantomtrack/gallery_view.php?user=$1&slug=$2 [L,QSA]

    # Aldomain -> mappa
    RewriteCond %{HTTP_HOST} ^([^.]+)\\.nbence\\.hu$ [NC]
    RewriteCond %{HTTP_HOST} !^www\\. [NC]
    RewriteCond %{REQUEST_URI} !^/%1/
    RewriteRule ^(.*)$ /%1/$1 [L]
</VirtualHost>

# ========================================================
# 3. NBTMP.HU"""

if OLD in content:
    new_content = content.replace(OLD, NEW, 1)
    with open(conf_path, 'w') as f:
        f.write(new_content)
    print("      [OK] Beillesztve a gallery kivétel.")
else:
    print("      [HIBA] Nem találtam a célblokkot! Kézi szerkesztés szükséges.")
    exit(1)
PYEOF

if [ $? -ne 0 ]; then
    echo "[HIBA] Python script sikertelen. Visszaállítás..."
    cp "$BACKUP" "$CONF"
    exit 1
fi

# 4. Szintaxis ellenőrzés
echo "[3/4] Apache szintaxis ellenőrzés..."
if apache2ctl configtest 2>&1 | grep -q "Syntax OK"; then
    echo "      Syntax OK"
    echo "[4/4] Apache reload..."
    systemctl reload apache2
    echo "      OK - Apache újraindítva!"
    echo ""
    echo "=== KÉSZ! Tesztelj egy galériát: ==="
    echo "    curl -I 'https://phantomtrack.nbence.hu/gallery/FELHASZNALONEV/SLUG'"
else
    echo "      [HIBA] Szintaxishiba! Visszaállítás..."
    cp "$BACKUP" "$CONF"
    apache2ctl configtest
    exit 1
fi
