-- ==============================================================================
-- VisionAI Facefinder SQLite Inicializáló Szkript
-- ==============================================================================
-- FIGYELEM: Ezt a fájlt NEM kötelező manuálisan lefuttatni!
-- A Python démon (backend/database.py) automatikusan létrehozza és frissíti
-- a táblákat, amint elindul (Automatikus Migráció).
--
-- Ez a fájl csupán dokumentációs és referenciális célokat szolgál, hogy egyértelmű
-- legyen az adatbázis sémája a fejlesztők számára.
-- ==============================================================================

PRAGMA journal_mode=WAL;
PRAGMA mmap_size=104857600;
PRAGMA cache_size=-102400;

-- ------------------------------------------------------------------------------
-- 1. FACES TÁBLA
-- Ebben a táblában tároljuk az összes valaha felismert arcot (bounding box, pontszám,
-- demográfia), valamint magát a képkivágást (thumb) és az 512-dimenziós vektorokat.
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS faces (
    face_id INTEGER PRIMARY KEY AUTOINCREMENT,
    
    -- Média metaadatok
    video_path TEXT,                       -- A fotó vagy videó elérési útja (relatív)
    media_type TEXT DEFAULT 'video',       -- 'image' vagy 'video'
    timestamp_sec REAL,                    -- Ha videó, hányadik másodpercben látható
    bbox TEXT,                             -- Bounding box koordináták: x1,y1,x2,y2
    
    -- Klaszterezés (Azonosítás)
    cluster_id INTEGER DEFAULT -1,         -- Ha -1, még nincs klaszterezve. Egyéb esetben a persons táblára mutat.
    face_emb_idx INTEGER DEFAULT NULL,     -- Vektor index a memóriában (opcionális)
    
    -- Arckép Cache
    face_thumb TEXT DEFAULT NULL,          -- Base64 kódolt WEBP mini-arckép (A weboldal ezen keresztül jeleníti meg)
    
    -- Demográfia és Minőség
    age INTEGER DEFAULT NULL,              -- Becsült életkor
    gender TEXT DEFAULT NULL,              -- Becsült nem (M/F)
    det_score REAL DEFAULT NULL,           -- Mennyire biztos, hogy arc? (0.0 - 1.0)
    quality_score REAL DEFAULT NULL,       -- Képminőség pontszám
    
    -- Fejpozíció és Kulcspontok
    pitch REAL DEFAULT NULL,               -- Bólintás (fent/lent)
    yaw REAL DEFAULT NULL,                 -- Fordulás (jobb/bal)
    roll REAL DEFAULT NULL,                -- Dőlés
    kps TEXT DEFAULT NULL,                 -- 5 arci kulcspont (Szemek, Orr, Száj)
    
    -- Bináris Embedding Vektorok (BLOB)
    emb_antelope BLOB DEFAULT NULL,
    emb_adaface BLOB DEFAULT NULL,
    emb_vit BLOB DEFAULT NULL
);

-- Indexek a gyors kereséshez a PHP-ből
CREATE INDEX IF NOT EXISTS idx_faces_cluster ON faces(cluster_id);
CREATE INDEX IF NOT EXISTS idx_faces_video ON faces(video_path);

-- ------------------------------------------------------------------------------
-- 2. JOBS TÁBLA (VÁRÓLISTA)
-- A PHP ide szúrja be a képeket, amit a Python worker másodpercenként kiolvas.
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    file_path TEXT UNIQUE,                 -- Fájlnév (Nem lehet duplikált)
    status TEXT DEFAULT 'pending',         -- 'pending', 'processing', 'done', 'failed'
    retry_count INTEGER DEFAULT 0,         -- Hányszor futott hibára
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ------------------------------------------------------------------------------
-- 3. PERSONS TÁBLA (SZEREPLŐK)
-- Metaadatok az egyes cluster_id-khoz (Pl. emberi név, megjegyzések)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS persons (
    cluster_id INTEGER PRIMARY KEY,        -- Megegyezik a faces.cluster_id-vel
    name TEXT DEFAULT NULL,                -- A személy neve (Pl. Bence)
    notes TEXT DEFAULT NULL,               -- Bármilyen jegyzet a személyhez
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
