import asyncio
# pyrefly: ignore[import-error, missing-import]
import cv2
import os

# Fix for decord: ensure PATH environment variable exists
if 'PATH' not in os.environ:
    os.environ['PATH'] = '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin'

# pyrefly: ignore[import-error, missing-import]
from pydantic import BaseModel
# pyrefly: ignore[import-error, missing-import]
from fastapi import FastAPI, HTTPException, BackgroundTasks
# pyrefly: ignore[import-error, missing-import]
from fastapi.responses import FileResponse
from typing import Optional

from backend.config import LOAD_AI_MODELS, validate_config
from backend.database import DatabaseManager
from backend.engine import FaceEngine
from backend.worker import BackgroundWorker

app = FastAPI(title="VisionAI API")

db_manager = DatabaseManager()
engine = FaceEngine(db_manager)
worker = BackgroundWorker(db_manager, engine)

class FilePathRequest(BaseModel):
    path: str
    gallery_id: int

@app.on_event("startup")
async def startup_event():
    validate_config()
    engine._init_engine()
    asyncio.create_task(worker.start())

@app.on_event("shutdown")
async def shutdown_event():
    worker.stop()

@app.post("/api/queue/scan_images")
async def scan_images(background_tasks: BackgroundTasks):
    img_dir = os.path.join(os.path.dirname(os.path.dirname(os.path.dirname(__file__))), "images")
    if not os.path.exists(img_dir):
        raise HTTPException(status_code=404, detail="Images directory not found")
    background_tasks.add_task(worker.scan_directory, img_dir)
    return {"success": True, "message": f"Szkennelés elindítva: {img_dir}"}

@app.post("/api/queue")
async def add_to_queue(req: FilePathRequest):
    if not os.path.exists(req.path):
        raise HTTPException(status_code=400, detail="File does not exist")
    added = db_manager.add_job(req.path, req.gallery_id)
    if added:
        return {"success": True, "message": "Job added"}
    return {"success": False, "message": "Job already exists in queue"}

@app.get("/api/queue/status")
async def queue_status():
    with db_manager.get_connection() as conn:
        with conn.cursor() as cur:
            cur.execute("SELECT status, count(*) as c FROM ff_jobs GROUP BY status")
            counts = {row['status']: row['c'] for row in cur.fetchall()}
            return {"success": True, "status": counts}

@app.post("/api/search")
async def search_face(req: FilePathRequest):
    if not LOAD_AI_MODELS:
        raise HTTPException(status_code=503, detail="A keresés ideiglenesen le van tiltva: Az AI modellek nincsenek betöltve a szerveren.")

    if not os.path.exists(req.path):
        raise HTTPException(status_code=400, detail="Selfie image does not exist")

    img = cv2.imread(req.path)
    if img is None:
        raise HTTPException(status_code=400, detail="Could not read image")

    results = engine.search_similar(img, req.gallery_id)
    return {"success": True, "results": results}

@app.get("/api/faces_in_image")
async def get_faces_in_image(path: str, gallery_id: int):
    with db_manager.get_connection() as conn:
        with conn.cursor() as cur:
            cur.execute("""
                SELECT face_id, bbox, face_thumb, age, gender, det_score,
                       pitch, yaw, roll, kps as landmarks
                FROM ff_faces WHERE video_path = %s AND gallery_id = %s
            """, (path, gallery_id))
            rows = cur.fetchall()

    result = []
    for row in rows:
        result.append({
            "face_id": row['face_id'],
            "bbox": [float(x) for x in row['bbox'].split(',')],
            "thumb": row['face_thumb'],
            "age": row['age'],
            "gender": row['gender'],
            "score": row['det_score'],
            "pose": {"pitch": row['pitch'], "yaw": row['yaw'], "roll": row['roll']},
            "kps": row['landmarks']
        })
    return {"success": True, "faces": result}

@app.post("/api/cluster")
async def cluster_faces(gallery_id: int, background_tasks: BackgroundTasks):
    background_tasks.add_task(engine.cluster_all_faces, gallery_id)
    return {"success": True, "msg": "A klaszterezés elindult a háttérben. Kövesd a folyamatot a logban!"}

@app.get("/api/clusters/all")
async def get_clusters(gallery_id: int):
    with db_manager.get_connection() as conn:
        with conn.cursor() as cur:
            cur.execute("""
                SELECT f.cluster_id, f.face_id, f.video_path, f.bbox,
                       p.name, p.notes, f.face_thumb, f.age, f.gender, f.det_score,
                       f.pitch, f.yaw, f.roll
                FROM ff_faces f
                LEFT JOIN ff_persons p ON f.cluster_id = p.cluster_id AND f.gallery_id = p.gallery_id
                WHERE f.cluster_id != -1 AND f.gallery_id = %s
                ORDER BY f.cluster_id
            """, (gallery_id,))
            rows = cur.fetchall()

    clusters = {}
    for row in rows:
        cid = row['cluster_id']
        if cid not in clusters:
            clusters[cid] = {
                "cluster_id": cid,
                "name": row['name'],
                "notes": row['notes'],
                "count": 0,
                "faces": []
            }
        
        clusters[cid]["count"] += 1
        if len(clusters[cid]["faces"]) < 5:
            clusters[cid]["faces"].append({
                "face_id": row['face_id'],
                "path": row['video_path'],
                "bbox": [float(x) for x in row['bbox'].split(',')],
                "thumb": row['face_thumb'],
                "age": row['age'],
                "gender": row['gender'],
                "score": row['det_score']
            })
            
    return {"success": True, "clusters": list(clusters.values())}

@app.post("/api/persons/rename")
async def rename_person(gallery_id: int, cluster_id: int, name: str, notes: Optional[str] = None):
    db_manager.rename_person(gallery_id, cluster_id, name, notes)
    return {"success": True}

@app.post("/api/maintenance/backfill_thumbs")
async def backfill_thumbs(background_tasks: BackgroundTasks):
    background_tasks.add_task(worker.backfill_missing_thumbs)
    return {"success": True, "message": "Backfill task started"}

@app.get("/api/persons")
async def get_persons(gallery_id: int):
    persons = db_manager.get_all_persons(gallery_id)
    return {"success": True, "persons": [{"cluster_id": r['cluster_id'], "name": r['name'], "notes": r['notes']} for r in persons]}

@app.post("/api/maintenance/fix_paths")
async def fix_paths_endpoint():
    """Windows elérési utakat javít Linux utakra az adatbázisban."""
    import io
    import contextlib
    from backend.fix_paths import fix_paths
    buf = io.StringIO()
    with contextlib.redirect_stdout(buf):
        fix_paths()
    output = buf.getvalue()
    return {"success": True, "message": "Útvonalak javítása kész.", "detail": output}

@app.get("/api/maintenance/db_export")
async def db_export():
    """Teljes SQLite adatbázis letöltése backup céljából."""
    import shutil
    from backend.config import DB_PATH
    # WAL checkpoint: befésüli a WAL-t a főfájlba
    with db_manager.get_connection() as conn:
        conn.execute("PRAGMA wal_checkpoint(TRUNCATE)")
    backup_path = os.path.join(os.path.dirname(DB_PATH), "pipeline_data_backup.db")
    shutil.copy2(DB_PATH, backup_path)
    os.chmod(backup_path, 0o664)
    return FileResponse(
        backup_path,
        media_type="application/octet-stream",
        filename="pipeline_data_backup.db"
    )

@app.post("/api/maintenance/db_supplement")
async def db_supplement(background_tasks: BackgroundTasks):
    """Csak a még nem indexelt képeket adja hozzá a queue-hoz (kiegészítés, nem teljes újraindexelés)."""
    img_dir = os.path.join(os.path.dirname(os.path.dirname(os.path.dirname(__file__))), "images")
    if not os.path.exists(img_dir):
        raise HTTPException(status_code=404, detail="Az images mappa nem található.")
    
    async def _supplement():
        IMAGE_EXTS = ('.jpg', '.jpeg', '.png', '.webp')
        added = 0
        skipped = 0
        with db_manager.get_connection() as conn:
            cur = conn.cursor()
            cur.execute("SELECT file_path FROM jobs")
            existing = {row[0] for row in cur.fetchall()}
            cur.execute("SELECT video_path FROM faces")
            indexed = {row[0] for row in cur.fetchall()}
            known = existing | indexed
        
        for fname in os.listdir(img_dir):
            if not fname.lower().endswith(IMAGE_EXTS):
                continue
            full_path = os.path.join(img_dir, fname)
            if full_path in known:
                skipped += 1
                continue
            if db_manager.add_job(full_path):
                added += 1
        print(f"[supplement] {added} új kép hozzáadva, {skipped} már ismert.")
    
    background_tasks.add_task(_supplement)
    return {"success": True, "message": "DB kiegészítés elindult a háttérben. Kövesd a logban!"}