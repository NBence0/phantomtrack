import sqlite3
import threading
import logging
import numpy as np
import os
from contextlib import contextmanager


logger = logging.getLogger(__name__)

class DatabaseManager:
    def __init__(self, db_path: str, vector_dim: int = 512, commit_every: int = 100):
        self.db_path = db_path
        self.vector_dim = vector_dim
        self.commit_every = commit_every
        self.lock = threading.Lock()
        self._pending_rows =[]

        os.makedirs(os.path.dirname(self.db_path), exist_ok=True)
        self._init_db()

    def _init_db(self):
        with sqlite3.connect(self.db_path) as conn:
            conn.execute("PRAGMA journal_mode=WAL;")
            conn.execute("PRAGMA mmap_size = 104857600;")
            conn.execute("PRAGMA cache_size = -102400;") 
            conn.execute("""
                CREATE TABLE IF NOT EXISTS faces (
                    face_id INTEGER PRIMARY KEY AUTOINCREMENT,
                    video_path TEXT,
                    media_type TEXT DEFAULT 'video',
                    timestamp_sec REAL,
                    bbox TEXT,
                    cluster_id INTEGER DEFAULT -1,
                    face_thumb TEXT DEFAULT NULL,
                    age INTEGER,
                    gender TEXT,
                    det_score REAL,
                    quality_score REAL,
                    emb_antelope BLOB,
                    emb_adaface BLOB,
                    emb_vit BLOB
                )
            """)

            try:
                conn.execute("ALTER TABLE faces ADD COLUMN face_thumb TEXT DEFAULT NULL")
            except sqlite3.OperationalError:
                pass

            missing_cols =[
                ("face_emb_idx", "INTEGER"),
                ("age", "INTEGER"),
                ("gender", "TEXT"),
                ("det_score", "REAL"),
                ("pitch", "REAL"),
                ("yaw", "REAL"),
                ("roll", "REAL"),
                ("kps", "TEXT")
            ]
            for col, dtype in missing_cols:
                try:
                    conn.execute(f"ALTER TABLE faces ADD COLUMN {col} {dtype} DEFAULT NULL")
                except sqlite3.OperationalError:
                    pass

            conn.execute("CREATE INDEX IF NOT EXISTS idx_faces_cluster ON faces(cluster_id)")
            conn.execute("CREATE INDEX IF NOT EXISTS idx_faces_video ON faces(video_path)")
            
            new_cols = [
                ("quality_score", "REAL"),
                ("emb_antelope", "BLOB"),
                ("emb_adaface", "BLOB")
            ]
            for col, dtype in new_cols:
                try:
                    conn.execute(f"ALTER TABLE faces ADD COLUMN {col} {dtype} DEFAULT NULL")
                except sqlite3.OperationalError:
                    pass

            conn.execute("""
                UPDATE faces
                SET face_emb_idx = (face_id - 1)
                WHERE face_emb_idx IS NULL
            """)

            conn.execute("""
                CREATE TABLE IF NOT EXISTS jobs (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    file_path TEXT UNIQUE,
                    status TEXT DEFAULT 'pending',
                    retry_count INTEGER DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            """)
            # Fejlesztés #4: retry_count oszlop migrálása meglévő adatbázishoz
            try:
                conn.execute("ALTER TABLE jobs ADD COLUMN retry_count INTEGER DEFAULT 0")
            except sqlite3.OperationalError:
                pass
            conn.execute("""
                CREATE TABLE IF NOT EXISTS persons (
                    cluster_id INTEGER PRIMARY KEY,
                    name TEXT DEFAULT NULL,
                    notes TEXT DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            """)
            conn.commit()

    @contextmanager
    def get_connection(self):
        conn = sqlite3.connect(self.db_path)
        conn.row_factory = sqlite3.Row
        try:
            yield conn
        finally:
            conn.close()

    def add_job(self, file_path: str):
        with self.get_connection() as conn:
            try:
                conn.execute("INSERT INTO jobs (file_path) VALUES (?)", (file_path,))
                conn.commit()
                return True
            except sqlite3.IntegrityError:
                return False

    def get_pending_job(self):
        with self.get_connection() as conn:
            cur = conn.cursor()
            # Fejlesztés #4: failed jobokat is újrafuttatjuk ha retry_count < MAX_RETRY_COUNT
            cur.execute("""
                SELECT id, file_path FROM jobs
                WHERE status = 'pending'
                   OR (status = 'failed' AND retry_count < 3)
                ORDER BY status DESC, created_at ASC
                LIMIT 1
            """)
            row = cur.fetchone()
            if row:
                conn.execute("UPDATE jobs SET status = 'processing' WHERE id = ?", (row[0],))
                conn.commit()
                return {"id": row[0], "file_path": row[1]}
            return None

    def mark_job_done(self, job_id: int):
        with self.get_connection() as conn:
            conn.execute("UPDATE jobs SET status = 'done' WHERE id = ?", (job_id,))
            conn.commit()

    def mark_job_failed(self, job_id: int):
        with self.get_connection() as conn:
            # Fejlesztés #4: retry_count növelése; ha eléri a max-ot, véglegesen failed marad
            conn.execute("""
                UPDATE jobs
                SET retry_count = retry_count + 1,
                    status = CASE WHEN retry_count + 1 >= 3 THEN 'failed' ELSE 'pending' END
                WHERE id = ?
            """, (job_id,))
            conn.commit()

    def save_faces(self, video_path: str, media_type: str, timestamp: float, faces):
        if not faces:
            return
        with self.lock:
            for face in faces:
                bbox_str = ",".join(map(str, face.bbox.astype(int)))
                thumb = getattr(face, '_thumb_name', None) or ''
                age = int(getattr(face, 'age', 0) or 0)
                gender = getattr(face, 'sex', getattr(face, 'gender', '')) or ''
                score = float(getattr(face, 'det_score', 0) or 0)
                
                pose = getattr(face, 'pose', None)
                pitch, yaw, roll = [float(x) for x in pose] if pose is not None and len(pose) == 3 else [0.0, 0.0, 0.0]
                kps = ",".join([str(round(float(x),1)) for x in face.kps.flatten()]) if hasattr(face, 'kps') else ""
                
                quality = float(getattr(face, 'adaface_score', 0.0))
                emb_antelope = face.embedding.astype(np.float32).tobytes()
                emb_adaface = getattr(face, 'adaface_embedding', np.zeros(512)).astype(np.float32).tobytes()
                emb_vit = getattr(face, 'vit_embedding', np.zeros(512)).astype(np.float32).tobytes()
                
                self._pending_rows.append((
                    video_path, media_type, timestamp, bbox_str, thumb, 
                    age, gender, score, pitch, yaw, roll, kps,
                    quality, sqlite3.Binary(emb_antelope), sqlite3.Binary(emb_adaface), sqlite3.Binary(emb_vit)
                ))

            if len(self._pending_rows) >= self.commit_every:
                self._flush_locked()

    def flush(self):
        with self.lock:
            self._flush_locked()

    def _flush_locked(self):
        if not self._pending_rows:
            return

        with sqlite3.connect(self.db_path) as conn:
            conn.execute("PRAGMA journal_mode=WAL;")
            conn.executemany(
                "INSERT INTO faces (video_path, media_type, timestamp_sec, bbox, face_thumb, age, gender, det_score, pitch, yaw, roll, kps, quality_score, emb_antelope, emb_adaface, emb_vit) "
                "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                self._pending_rows
            )
            conn.commit()

        self._pending_rows =[]

    def rename_person(self, cluster_id: int, name: str, notes: str = None):
        with self.get_connection() as conn:
            conn.execute("""
                INSERT INTO persons (cluster_id, name, notes, updated_at)
                VALUES (?, ?, ?, CURRENT_TIMESTAMP)
                ON CONFLICT(cluster_id) DO UPDATE SET
                    name = excluded.name,
                    notes = excluded.notes,
                    updated_at = CURRENT_TIMESTAMP
            """, (cluster_id, name, notes))
            conn.commit()

    def get_all_persons(self):
        with self.get_connection() as conn:
            cur = conn.cursor()
            cur.execute("SELECT cluster_id, name, notes, updated_at FROM persons ORDER BY cluster_id")
            return [dict(row) for row in cur.fetchall()]

    def get_pending_job_count(self) -> int:
        with self.get_connection() as conn:
            cur = conn.cursor()
            cur.execute("SELECT count(*) FROM jobs WHERE status = 'pending'")
            row = cur.fetchone()
            return row[0] if row else 0