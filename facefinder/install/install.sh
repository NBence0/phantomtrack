#!/bin/bash
# VisionAI / Facefinder Telepítő Szkript
# Ez a szkript felkészíti a szervert a Facefinder futtatására.

set -e

echo "==============================================="
echo "🧠 VisionAI Facefinder Telepítő"
echo "==============================================="

# 1. Rendszerkövetelmények ellenőrzése
echo "[1/4] Rendszerkövetelmények ellenőrzése..."
if ! command -v python3 &> /dev/null; then
    echo "❌ Hiba: Python3 nincs telepítve!"
    exit 1
fi

if ! command -v sqlite3 &> /dev/null; then
    echo "⚠️ Figyelmeztetés: SQLite3 CLI eszköz nem található, de ajánlott az adatbázis karbantartásához."
    echo "Telepítéshez: sudo apt-get install sqlite3"
fi

# 2. Könyvtárak létrehozása és jogosultságok
echo "[2/4] Mappák és jogosultságok beállítása..."
mkdir -p data temp backend/models pylib
chmod 0777 data
chmod 0777 temp

# Hozzunk létre egy üres log fájlt, hogy a weboldal azonnal tudja olvasni
touch temp/fastapi.log
chmod 0666 temp/fastapi.log

# 3. Adatbázis inicializálás
echo "[3/4] Adatbázis környezet előkészítése..."
if [ ! -f "data/pipeline_data.db" ]; then
    echo "Az adatbázis még nem létezik, az első API híváskor vagy démon indulásakor fog automatikusan létrejönni."
else
    chmod 0666 data/pipeline_data.db
    echo "Meglévő adatbázis jogosultságok frissítve."
fi

# 4. Python függőségek telepítése
echo "[4/4] Python csomagok letöltése a lokális pylib mappába..."
if [ -f "requirements.txt" ]; then
    # Ha van requirements.txt, telepítjük a csomagokat a pylib könyvtárba
    python3 -m pip install -r requirements.txt -t pylib/
else
    echo "A requirements.txt nem található. Alapértelmezett csomagok telepítése..."
    python3 -m pip install fastapi uvicorn pydantic numpy opencv-python-headless onnxruntime decord scikit-learn -t pylib/
fi

echo "==============================================="
echo "✅ Sikeres telepítés! A Facefinder készen áll a használatra."
echo "További információkért és az API integrációhoz olvasd el a README.md fájlt!"
echo "==============================================="
