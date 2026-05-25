import os
import sys
import zipfile
import json
import base64
# pyrefly: ignore [missing-import]
import cv2
import tempfile
import shutil
import numpy as np

# A backend importokhoz biztosítjuk a helyes sys.path-t
BASE_DIR = os.path.dirname(os.path.abspath(__file__))
PHANTOM_DIR = os.path.dirname(BASE_DIR)
sys.path.insert(0, PHANTOM_DIR)

# --- Config patch: meg kell előzni, hogy a config.py SQLite-ot vagy DB-t nyisson ---
# Először betöltjük a config modult, majd felülírjuk amit kell
import backend.config as config
config.LOAD_AI_MODELS = True
# DB_PATH-t és TEMP_DIR-t a helyi temp mappára irányítjuk,
# hogy ne próbáljon Linux-os /var/www útvonalakra írni
_local_temp = os.path.join(PHANTOM_DIR, "data")
os.makedirs(_local_temp, exist_ok=True)
config.DATA_DIR = _local_temp
config.DB_PATH  = os.path.join(_local_temp, "pipeline_data.db")

# A DatabaseManager-t NEM importáljuk, NEM példányosítjuk.
# Az engine.py importja sqlite3-t de nem használja (régi maradvány) — ez rendben van.
try:
    from backend.engine import FaceEngine
except ImportError as e:
    print(f"[HIBA] Nem sikerült betölteni a FaceEngine-t: {e}")
    print("Győződj meg róla, hogy az onnxruntime (GPU verzió) és a többi függőség telepítve van.")
    sys.exit(1)

def encode_base64(data):
    if data is None:
        return None
    if isinstance(data, np.ndarray):
        return base64.b64encode(data.tobytes()).decode('utf-8')
    if isinstance(data, bytes):
        return base64.b64encode(data).decode('utf-8')
    return data

def main():
    if len(sys.argv) < 3:
        print("Használat: python offline_indexer.py <pending.json> <kepek_mappaja>")
        sys.exit(1)

    json_path = sys.argv[1]
    img_folder = sys.argv[2]
    
    if not os.path.exists(json_path):
        print(f"[HIBA] A JSON fájl nem található: {json_path}")
        sys.exit(1)
        
    if not os.path.exists(img_folder):
        print(f"[HIBA] A képek mappája nem található: {img_folder}")
        sys.exit(1)

    print(f"[*] JSON feldolgozása: {json_path}")
    print(f"[*] Képek keresése itt: {img_folder}")
    print("[*] FaceEngine inicializálása (modellek betöltése, ez eltarthat egy darabig)...")
    
    # Init Engine (db_manager nélkül is megy az arckereső funkció)
    engine = FaceEngine(db_manager=None)
    engine._init_engine()
    
    with open(json_path, 'r', encoding='utf-8') as f:
        try:
            pending_tasks = json.load(f)
        except Exception as e:
            print(f"[HIBA] Hibás JSON formátum: {e}")
            sys.exit(1)
            
    if not isinstance(pending_tasks, list):
        print("[HIBA] A JSON fájlnak listát kell tartalmaznia!")
        sys.exit(1)

    results = []
    print(f"[*] Összesen {len(pending_tasks)} feladat a JSON-ban. Feldolgozás indul...")

    for i, task in enumerate(pending_tasks):
        orig_name = task.get('original_filename', '')
        stored_name = task.get('stored_filename', '')
        
        if not orig_name and not stored_name:
            continue
            
        # Először próbáljuk az eredeti néven, ha az nincs, akkor a tárolt néven
        img_path = os.path.join(img_folder, orig_name)
        if not os.path.exists(img_path):
            img_path = os.path.join(img_folder, stored_name)
            
        if not os.path.exists(img_path):
            print(f"[{i+1}/{len(pending_tasks)}] [Kihagyva] Nem található fájl a mappában: {orig_name} vagy {stored_name}")
            continue
        
        print(f"[{i+1}/{len(pending_tasks)}] {orig_name} feldolgozása...")
        
        img_bgr = cv2.imread(img_path)
        if img_bgr is None:
            print(f"    [HIBA] Nem sikerült beolvasni a képet.")
            continue
            
        try:
            faces = engine.get_faces(img_bgr)
            if not faces:
                print(f"    Nem található arc.")
                continue
                
            print(f"    {len(faces)} arc detektálva.")
            
            for idx, face in enumerate(faces):
                # Thumbnail generálás (crop + base64)
                bbox = face.bbox
                x1, y1, x2, y2 = map(int, bbox)
                # Adunk neki kis margót
                w = x2 - x1
                h = y2 - y1
                x1 = max(0, x1 - int(w * 0.2))
                y1 = max(0, y1 - int(h * 0.2))
                x2 = min(img_bgr.shape[1], x2 + int(w * 0.2))
                y2 = min(img_bgr.shape[0], y2 + int(h * 0.2))
                
                face_crop = img_bgr[y1:y2, x1:x2]
                _, buffer = cv2.imencode('.webp', face_crop, [cv2.IMWRITE_WEBP_QUALITY, 80])
                thumb_b64 = "data:image/webp;base64," + base64.b64encode(buffer).decode('utf-8')
                
                # Bbox és kps konvertálás stringbe/listába
                bbox_str = ",".join(map(str, face.bbox))
                kps_str = ",".join(map(str, face.kps.flatten())) if face.kps is not None else None
                
                res = {
                    "filename": stored_name,
                    "bbox": bbox_str,
                    "face_thumb": thumb_b64,
                    "age": int(face.age) if face.age is not None else None,
                    "gender": face.gender,
                    "det_score": float(face.det_score) if face.det_score is not None else None,
                    "quality_score": float(face.quality_score) if hasattr(face, 'quality_score') and face.quality_score is not None else None,
                    "pitch": float(face.pose[0]) if face.pose is not None else None,
                    "yaw": float(face.pose[1]) if face.pose is not None else None,
                    "roll": float(face.pose[2]) if face.pose is not None else None,
                    "kps": kps_str,
                    "emb_antelope": encode_base64(face.embedding), # Az engine az antelope-t teszi az embedding mezőbe
                    "emb_adaface": encode_base64(getattr(face, 'adaface_embedding', None)),
                    "emb_vit": encode_base64(getattr(face, 'vit_embedding', None))
                }
                results.append(res)
                
        except Exception as e:
            print(f"    [HIBA] Kivétel történt: {e}")
            
    # Eredmények mentése
    out_file = "results.json"
    with open(out_file, 'w', encoding='utf-8') as f:
        json.dump(results, f)
        
    print(f"\n[*] Feldolgozás kész! {len(results)} arc adatai elmentve a '{out_file}' fájlba.")
    print("[*] Ezt a fájlt töltsd fel a VisionAI admin felületén.")

if __name__ == "__main__":
    main()
