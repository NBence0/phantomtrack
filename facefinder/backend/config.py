import os
import sys

# --- Adatbázis és Útvonalak ---
BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DATA_DIR = os.path.join(BASE_DIR, "data")
DB_PATH = os.path.join(DATA_DIR, "pipeline_data.db")
TEMP_DIR = os.path.join(BASE_DIR, "temp")

os.makedirs(DATA_DIR, exist_ok=True)
os.makedirs(TEMP_DIR, exist_ok=True)

# Új modellek útvonalai - ONNX modellek használata TensorRT helyett
MODELS_DIR = os.path.join(BASE_DIR, "models")

# --- AI Engine Konfiguráció ---
# ÚJ: Ha False, az AI modellek nem töltődnek be (csak az API és adatbázis/FAISS él)
LOAD_AI_MODELS = False

# .onnx fájlok útvonala (onnxruntime-gpu vagy cpu használatához)
# YOLO_ONNX = os.path.join(MODELS_DIR, "yolov8x-face-lindevs.onnx")
DET_ONNX = os.path.join(MODELS_DIR, "retinaface-resnet50.onnx")
# yolov8x-face-lindevs.onnx
# https://github.com/lindevs/yolov8-face
# https://huggingface.co/TheEeeeLin/HivisionIDPhotos_matting


LANDMARK_ONNX = os.path.join(MODELS_DIR, "2d106det.onnx")
ANTELOPE_ONNX = os.path.join(MODELS_DIR, "glintr100.onnx")
# glintr100.onnx
# https://github.com/deepinsight/insightface

ADAFACE_ONNX = os.path.join(MODELS_DIR, "adaface_ir101_webface12m.onnx")
# adaface_ir101_webface12m.onnx
# https://huggingface.co/minchul/cvlface_adaface_ir101_webface12m
# https://drive.google.com/file/d/1dgMFOASKnaujQcCL4sSYkKOkBrmXUUU1/view

VIT_ONNX = os.path.join(MODELS_DIR, "wf42m_pfc03_vit-l_fp32.onnx")
# wf42m_pfc03_vit-l_fp32.onnx
# https://huggingface.co/kunkunlin1221/face-recognition_vit-l-pfc0.3-cosface-web42m

HSE_ONNX = os.path.join(MODELS_DIR, "vit-base-age-gender.onnx")
# vit-base-age-gender.onnx
# https://huggingface.co/onnx-community/age-gender-prediction-ONNX

# Detektáló ablak mérete — nagyobb = pontosabb, de lassabb
# (640,640) jó egyensúly; (1024,1024) maximális pontosság
DET_SIZE = (1024, 1024)

# Hasonlóság küszöb a szelfi keresésnél (L2 távolság)
# 1.0 = szigorú, 1.25 = közepes, 1.4 = laza
SIMILARITY_THRESHOLD = 0.95 #1.15

# --- CUDA / GPU Konfiguráció ---
# True = NVIDIA GPU (CUDA) — onnxruntime-gpu szükséges
# False = CPU (alapértelmezett)
# Ha van elérhető CUDA, az onnxruntime-gpu ezt fogja használni.
if sys.platform == 'win32':
    USE_CUDA = True
else:
    USE_CUDA = False     

# --- Tiling (Csempézés) Konfiguráció ---
STANDARD_TILING_ENABLED = True
TILING_SIZE = 1024           # 640-ről 1024-re emelve
TILING_OVERLAP = 300         # 200-ról 300-ra emelve a biztos perem-találatokért
TILING_MIN_WIDTH = 1200      # Kicsit feljebb viheted, mert az 1024-es RetinaFace amúgy is hatalmasat lát

# ÚJ: Macro Tiling (Nagy, képernyőt kitöltő arcok feldarabolása és felbontás-növelése)
MACRO_TILING_ENABLED = True
MACRO_TILING_ROWS = 2                # Sorok száma (ne listaként add meg)
MACRO_TILING_COLS = 3                # Oszlopok száma
MACRO_TILING_OVERLAP_PERCENT = 0.20  # Százalékos átfedés a csempék között (0.20 = 20%)

# --- NMS (Non-Maximum Suppression) ---
#átfedő boxok szűrése tiling után (IoU küszöb)
NMS_IOU_THRESHOLD = 0.20     # 0.35-ről csökkentve: duplikált boxok megszüntetése ugyanazon az arcon

# --- Arcdetektálás pontossági hangolás ---
# Alacsonyabb küszöb = több arcot kap el (halvány, részleges arcokat is)
# Pontosabb felismeréshez: 0.25–0.40 javasolt.
DET_THRESH = 0.3

# --- Quality Shield (Minőségi Pajzs) ---
# Ezek a beállítások kiszűrik a "szemét" az adatbázisból.
QUALITY_MIN_SIZE = 32        # Enyhítve 35-ről: kis/háttér arcok is bekerülnek
QUALITY_MAX_YAW = 80         # Maximum elfordulás oldalra (fok). 90 = teljes profil.
QUALITY_MIN_CONFIDENCE = 0.33 # Magasabb küszöb a mentett arcokhoz 0,30
QUALITY_BLUR_THRESHOLD = 20.0 # Élességi küszöb (Laplacian variancia). 0 = kikapcsolt.
QUALITY_GATE_ADAFACE = 7.5   # AdaFace norm-küszöb — 15.0 túl magas volt, sok arcot dobott el


# --- Klaszterezés (DBSCAN + Merge) ---
USE_L2_NORMALIZED_FUSION = True
# Szigorúbb eps (pl. 0.30 - 0.35) megakadályozza, hogy különböző emberek egy csoportba kerüljenek (chaining effect).
DBSCAN_EPS = 0.60
DBSCAN_MIN_SAMPLES = 2

# Súlyozás a kereséshez (sum = 1.0)
# Ha valamelyik modell bizonytalan, a többi kompenzál
WEIGHT_ANTELOPE = 1
WEIGHT_ADAFACE = 0
WEIGHT_VIT = 0

# Klaszter összeolvasztási küszöb (cosine távolság)
# Ha két csoport átlaga ennél közelebb van, egyesítjük őket.
CLUSTER_MERGE_THRESHOLD = 0.43 #0.35

# --- Worker Konfiguráció ---
AUTO_SCAN_ON_STARTUP = False # True esetén indításkor beszkenneli az /images mappát
if sys.platform == 'win32':
    AUTO_SCAN_ON_STARTUP = True

PROCESS_EVERY_N_FRAME = 15   # Videónál minden N-edik képkocka
VIDEO_EXTENSIONS = ('.mp4', '.mkv', '.avi', '.mov')
IMAGE_EXTENSIONS = ('.jpg', '.jpeg', '.png', '.webp')

# Asyncio timeout egy kép/képkocka feldolgozására (másodpercben)
ASYNCIO_TIMEOUT = 300.0

# --- Fejlesztés #4: Retry logika ---
MAX_RETRY_COUNT = 3  # Ennyi sikertelen kísérlet után marad véglegesen 'failed'

# --- GPU Multi-Stream (RTX 4090 / high-end GPU) ---
# True esetén N párhuzamos GPU pipeline fut egyidejűleg.
# FIGYELEM: Minden pipeline külön VRAM-ot foglal!
# 1060-on tartsd False-on! 4090-en (24GB): GPU_PARALLEL_WORKERS=4, VRAM_PER=5 -> 20GB
GPU_MULTI_STREAM = False
GPU_PARALLEL_WORKERS = 4          # Párhuzamos pipeline-ok száma
GPU_VRAM_LIMIT_PER_WORKER_GB = 5  # VRAM limit worker-enként GB-ban


def validate_config():
    """Fejlesztés #5: Konfigurációs validáció indításkor — hibás beállítások korai felderítése."""
    errors = []
    if not os.path.exists(DET_ONNX) and LOAD_AI_MODELS:
        errors.append(f"Hiányzó detektálási modell: {DET_ONNX}")
    if not os.path.exists(ANTELOPE_ONNX) and LOAD_AI_MODELS:
        errors.append(f"Hiányzó Antelope modell: {ANTELOPE_ONNX}")
    if not os.path.exists(ADAFACE_ONNX) and LOAD_AI_MODELS:
        errors.append(f"Hiányzó AdaFace modell: {ADAFACE_ONNX}")
    if not os.path.exists(VIT_ONNX) and LOAD_AI_MODELS:
        errors.append(f"Hiányzó ViT modell: {VIT_ONNX}")
    if not os.path.exists(HSE_ONNX) and LOAD_AI_MODELS:
        errors.append(f"Hiányzó HSE modell: {HSE_ONNX}")
    weight_sum = WEIGHT_ANTELOPE + WEIGHT_ADAFACE + WEIGHT_VIT
    if abs(weight_sum - 1.0) > 0.01:
        errors.append(f"Súlyok összege nem 1.0 (jelenlegi: {weight_sum:.3f})")
    if not (0 < DET_THRESH < 1):
        errors.append(f"DET_THRESH értéke érvénytelen: {DET_THRESH}")
    if QUALITY_GATE_ADAFACE < 0:
        errors.append(f"QUALITY_GATE_ADAFACE nem lehet negatív: {QUALITY_GATE_ADAFACE}")
    if TILING_OVERLAP >= TILING_SIZE:
        errors.append(f"TILING_OVERLAP ({TILING_OVERLAP}) >= TILING_SIZE ({TILING_SIZE}) — végtelen ciklus!")
    if errors:
        print("\n[CONFIG HIBA] Az alábbi konfigurációs hibák találhatók:")
        for e in errors:
            print(f"  ❌ {e}")
        raise RuntimeError(f"Konfigurációs hiba — {len(errors)} probléma. Javítsd a config.py-t!")
    print("[CONFIG OK] Konfiguráció érvényes.")