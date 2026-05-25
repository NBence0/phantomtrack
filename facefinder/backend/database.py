import pymysql
from pymysql.constants import CLIENT
import threading
import logging
import numpy as np
import os
import re
from contextlib import contextmanager

logger = logging.getLogger(__name__)

class DatabaseManager:
    def __init__(self, commit_every: int = 100, db_path=None): # db_path ignored now
        self.commit_every = commit_every
        self.lock = threading.Lock()
        self._pending_rows = []
        self.db_config = self._parse_php_config('/var/www/nbence.hu/phantomtrack/config.php')
        self._init_db()

    def _parse_php_config(self, filepath):
        config = {'host': 'localhost', 'user': 'root', 'password': '', 'database': 'phantomtrack_db'}
        try:
            with open(filepath, 'r', encoding='utf-8') as f:
                content = f.read()
            host_m = re.search(r"define\('DB_HOST',\s*'([^']+)'\);", content)
            if host_m: config['host'] = host_m.group(1)
            name_m = re.search(r"define\('DB_NAME',\s*'([^']+)'\);", content)
            if name_m: config['database'] = name_m.group(1)
            user_m = re.search(r"define\('DB_USER',\s*'([^']+)'\);", content)
            if user_m: config['user'] = user_m.group(1)
            pass_m = re.search(r"define\('DB_PASS',\s*'([^']+)'\);", content)
            if pass_m: config['password'] = pass_m.group(1)
        except Exception as e:
            logger.error(f"Failed to parse config: {e}")
        return config

    @contextmanager
    def get_connection(self):
        host = self.db_config['host']
        # Ha a host 'localhost' vagy '127.0.0.1', Unix socketen csatlakozunk
        # mert a MariaDB TCP-n (127.0.0.1) nem enged kapcsolatot
        use_socket = None
        if host in ('localhost', '127.0.0.1'):
            for candidate in ('/run/mysqld/mysqld.sock', '/var/run/mysqld/mysqld.sock', '/tmp/mysql.sock'):
                if os.path.exists(candidate):
                    use_socket = candidate
                    break

        conn = pymysql.connect(
            host='localhost',
            user=self.db_config['user'],
            password=self.db_config['password'],
            database=self.db_config['database'],
            unix_socket=use_socket,
            cursorclass=pymysql.cursors.DictCursor,
            client_flag=CLIENT.MULTI_STATEMENTS
        )
        try:
            yield conn
        finally:
            conn.close()

    def _init_db(self):
        with self.get_connection() as conn:
            with conn.cursor() as cur:
                cur.execute("SELECT 1")
            
    def add_job(self, file_path: str, gallery_id: int):
        with self.get_connection() as conn:
            try:
                with conn.cursor() as cur:
                    cur.execute("INSERT INTO ff_jobs (file_path, gallery_id) VALUES (%s, %s)", (file_path, gallery_id))
                conn.commit()
                return True
            except pymysql.err.IntegrityError:
                return False

    def get_pending_job(self):
        with self.get_connection() as conn:
            with conn.cursor() as cur:
                # FOR UPDATE SKIP LOCKED not strictly needed if only one worker thread per db, but good practice
                cur.execute("""
                    SELECT id, file_path, gallery_id FROM ff_jobs
                    WHERE status = 'pending'
                       OR (status = 'failed' AND retry_count < 3)
                    ORDER BY status DESC, created_at ASC
                    LIMIT 1
                    FOR UPDATE SKIP LOCKED
                """)
                row = cur.fetchone()
                if row:
                    cur.execute("UPDATE ff_jobs SET status = 'processing' WHERE id = %s", (row['id'],))
                    conn.commit()
                    return row
            return None

    def mark_job_done(self, job_id: int):
        with self.get_connection() as conn:
            with conn.cursor() as cur:
                cur.execute("UPDATE ff_jobs SET status = 'done' WHERE id = %s", (job_id,))
            conn.commit()

    def mark_job_failed(self, job_id: int):
        with self.get_connection() as conn:
            with conn.cursor() as cur:
                cur.execute("""
                    UPDATE ff_jobs
                    SET retry_count = retry_count + 1,
                        status = CASE WHEN retry_count + 1 >= 3 THEN 'failed' ELSE 'pending' END
                    WHERE id = %s
                """, (job_id,))
            conn.commit()

    def save_faces(self, video_path: str, media_type: str, timestamp: float, gallery_id: int, faces):
        if not faces:
            return
        with self.lock:
            for face in faces:
                bbox_str = ",".join(map(str, face.bbox.astype(int)))
                
                thumb_bytes = getattr(face, '_thumb_name', b'')
                if thumb_bytes:
                    import base64
                    thumb_b64 = "data:image/webp;base64," + base64.b64encode(thumb_bytes).decode('utf-8')
                else:
                    thumb_b64 = None

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
                    gallery_id, video_path, media_type, timestamp, bbox_str, thumb_b64, 
                    age, gender, score, pitch, yaw, roll, kps,
                    quality, emb_antelope, emb_adaface, emb_vit
                ))

            if len(self._pending_rows) >= self.commit_every:
                self._flush_locked()

    def flush(self):
        with self.lock:
            self._flush_locked()

    def _flush_locked(self):
        if not self._pending_rows:
            return

        with self.get_connection() as conn:
            with conn.cursor() as cur:
                cur.executemany(
                    "INSERT INTO ff_faces (gallery_id, video_path, media_type, timestamp_sec, bbox, face_thumb, age, gender, det_score, pitch, yaw, roll, kps, quality_score, emb_antelope, emb_adaface, emb_vit) "
                    "VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)",
                    self._pending_rows
                )
            conn.commit()

        self._pending_rows = []

    def rename_person(self, gallery_id: int, cluster_id: int, name: str, notes: str = None):
        with self.get_connection() as conn:
            with conn.cursor() as cur:
                cur.execute("""
                    INSERT INTO ff_persons (cluster_id, gallery_id, name, notes, updated_at)
                    VALUES (%s, %s, %s, %s, CURRENT_TIMESTAMP)
                    ON DUPLICATE KEY UPDATE
                        name = VALUES(name),
                        notes = VALUES(notes),
                        updated_at = CURRENT_TIMESTAMP
                """, (cluster_id, gallery_id, name, notes))
            conn.commit()

    def get_all_persons(self, gallery_id: int):
        with self.get_connection() as conn:
            with conn.cursor() as cur:
                cur.execute("SELECT cluster_id, name, notes, updated_at FROM ff_persons WHERE gallery_id = %s ORDER BY cluster_id", (gallery_id,))
                return cur.fetchall()

    def get_pending_job_count(self) -> int:
        with self.get_connection() as conn:
            with conn.cursor() as cur:
                cur.execute("SELECT count(*) as c FROM ff_jobs WHERE status = 'pending'")
                row = cur.fetchone()
                return row['c'] if row else 0