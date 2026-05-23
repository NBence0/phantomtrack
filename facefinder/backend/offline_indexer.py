import os
import sys
import zipfile
import json
import base64
import cv2
import tempfile
import shutil
import glob
import numpy as np

# A backend importokhoz biztosítjuk a helyes sys.path-t
BASE_DIR = os.path.dirname(os.path.abspath(__file__))
sys.path.append(os.path.dirname(BASE_DIR))

# Kényszerítjük az AI modellek betöltését
import backend.config as config
config.LOAD_AI_MODELS = True

try:
    from backend.engine import FaceEngine
except ImportError as e:
    print(f"[HIBA] Nem sikerült betölteni a FaceEngine-t: {e}")
    print("Győződj meg róla, hogy a script a 'backend' mappában van és az onnxruntime telepítve van.")
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
    if len(sys.argv) < 2:
        print("Használat: python offline_indexer.py <letöltött_zip_fájl_útvonala>")
        sys.exit(1)

    zip_path = sys.argv[1]
    if not os.path.exists(zip_path):
        print(f"[HIBA] A ZIP fájl nem található: {zip_path}")
        sys.exit(1)

    print(f"[*] ZIP feldolgozása: {zip_path}")
    print("[*] FaceEngine inicializálása (modellek betöltése, ez eltarthat egy darabig)...")
    
    # Init Engine (db_manager nélkül is megy az arckereső funkció)
    engine = FaceEngine(db_manager=None)
    engine._init_engine()
    
    # Ideiglenes mappa létrehozása
    temp_dir = tempfile.mkdtemp(prefix="visionai_")
    print(f"[*] Fájlok kicsomagolása ide: {temp_dir}")
    
    with zipfile.ZipFile(zip_path, 'r') as zip_ref:
        zip_ref.extractall(temp_dir)

    results = []
    
    # Megkeressük az összes kicsomagolt fájlt (a ZIP-ben "gallery_X/filename" struktúra van)
    files = glob.glob(os.path.join(temp_dir, '**', '*.*'), recursive=True)
    images = [f for f in files if os.path.isfile(f)]
    
    print(f"[*] Összesen {len(images)} fájl kicsomagolva. Feldolgozás indul...")

    for i, img_path in enumerate(images):
        # A relatív útvonal lesz a fájlnév, pl. "gallery_2/21" -> a backend ebből kiszedi a basenamet
        rel_path = os.path.basename(img_path)
        
        print(f"[{i+1}/{len(images)}] {rel_path} feldolgozása...")
        
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
                    "filename": rel_path,
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
    
    # Takarítás
    try:
        shutil.rmtree(temp_dir)
    except:
        pass

if __name__ == "__main__":
    main()
