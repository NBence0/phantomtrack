import numpy as np
import os
# pyrefly: ignore[import-error, missing-import]
import cv2
import threading
import warnings
import logging
import queue as _queue
from contextlib import contextmanager

# pyrefly: ignore[import-error, missing-import]
import faiss
from scipy.sparse import coo_matrix
from sklearn.cluster import DBSCAN

import math
from itertools import product

try:
    # pyrefly: ignore[import-error, missing-import]
    import onnxruntime as ort
except ImportError:
    print("[HIBA] Az 'onnxruntime-gpu' vagy 'onnxruntime' nincs telepítve.")

warnings.filterwarnings("ignore", category=UserWarning)
warnings.filterwarnings("ignore", category=FutureWarning)

logger = logging.getLogger(__name__)

from backend.config import (
    DET_SIZE, USE_CUDA, SIMILARITY_THRESHOLD,
    DET_ONNX, ANTELOPE_ONNX, ADAFACE_ONNX, VIT_ONNX, HSE_ONNX,
    STANDARD_TILING_ENABLED, TILING_SIZE, TILING_OVERLAP, TILING_MIN_WIDTH, NMS_IOU_THRESHOLD,
    WEIGHT_ANTELOPE, WEIGHT_ADAFACE, WEIGHT_VIT,
    DET_THRESH, QUALITY_MIN_CONFIDENCE, QUALITY_MIN_SIZE, QUALITY_GATE_ADAFACE,
    DBSCAN_EPS, DBSCAN_MIN_SAMPLES, QUALITY_BLUR_THRESHOLD,
    MACRO_TILING_ENABLED, MACRO_TILING_ROWS, MACRO_TILING_COLS, MACRO_TILING_OVERLAP_PERCENT,
    LOAD_AI_MODELS, USE_L2_NORMALIZED_FUSION, GPU_MULTI_STREAM, GPU_VRAM_LIMIT_PER_WORKER_GB,
)

# pyrefly: ignore[import-error, missing-import]
from insightface.app.common import Face


class FaceIndexManager:
    """Objektumorientált wrapper a FAISS indexeléshez és kereséshez (Memóriabarát klaszterezés)."""
    def __init__(self, db_manager, antelope_w, adaface_w, vit_w):
        self.db_manager = db_manager
        self.weights = (np.sqrt(antelope_w), np.sqrt(adaface_w), np.sqrt(vit_w))
        # 3 darab 512 dimenziós vektor összefűzve
        self.index = faiss.IndexFlatIP(512 * 3)
        self.face_metadata = []
        self._loaded_gallery_id = None
        self.lock = threading.Lock()

    def _get_fused_vector(self, ant, ada, vit):
        """A vektorok összefűzése súlyozva, vagy L2 normalizált szupervektorként."""
        if USE_L2_NORMALIZED_FUSION:
            v = np.concatenate([ant, ada, vit], axis=-1)
            norm = np.linalg.norm(v)
            if norm > 0:
                v = v / norm
            return v.astype(np.float32)
        else:
            w_ant, w_ada, w_vit = self.weights
            v = np.concatenate([ant * w_ant, ada * w_ada, vit * w_vit], axis=-1)
            return v.astype(np.float32)

    def load_data_if_needed(self, gallery_id, force_reload=False):
        """Kereséshez és klaszterezéshez betölti és RAM-ban tartja az indexet (DB túlterhelés elkerülése)."""
        with self.lock:
            if self._loaded_gallery_id == gallery_id and not force_reload:
                return
            
            self.index.reset()
            self.face_metadata = []
            
            with self.db_manager.get_connection() as conn:
                with conn.cursor() as cur:
                    cur.execute("SELECT face_id, video_path, media_type, timestamp_sec, bbox, emb_antelope, emb_adaface, emb_vit FROM ff_faces WHERE gallery_id = %s AND emb_antelope IS NOT NULL", (gallery_id,))
                    rows = cur.fetchall()

            if not rows:
                self._loaded_gallery_id = gallery_id
                return

            vectors = []
            for row in rows:
                ant = np.frombuffer(row['emb_antelope'], dtype=np.float32)
                ada = np.frombuffer(row['emb_adaface'], dtype=np.float32)
                vit = np.frombuffer(row['emb_vit'], dtype=np.float32)
                vectors.append(self._get_fused_vector(ant, ada, vit))
                self.face_metadata.append(row)

            if vectors:
                self.index.add(np.array(vectors))
            self._loaded_gallery_id = gallery_id

    def search_similar(self, query_face, gallery_id, threshold, max_results=15):
        self.load_data_if_needed(gallery_id)
        if not self.face_metadata:
            return []

        q_ant = query_face.embedding.astype(np.float32)
        q_ada = getattr(query_face, 'adaface_embedding', q_ant)
        q_vit = getattr(query_face, 'vit_embedding', q_ant)
        q_vec = self._get_fused_vector(q_ant, q_ada, q_vit).reshape(1, -1)

        distances, indices = self.index.search(q_vec, max_results)
        
        results = []
        for dist, idx in zip(distances[0], indices[0]):
            if idx == -1: continue
            real_dist = 1.0 - dist # Inner Product konvertálása távolsággá
            if real_dist < threshold:
                row = self.face_metadata[idx]
                results.append({
                    "file_path": row['video_path'], "media_type": row['media_type'],
                    "timestamp": row['timestamp_sec'], "bbox": [float(x) for x in row['bbox'].split(',')],
                    "distance": float(real_dist)
                })
        return results

    def cluster_dbscan(self, gallery_id, eps, min_samples):
        self.load_data_if_needed(gallery_id, force_reload=True)
        if len(self.face_metadata) < 2: return 0

        # Mátrix helyett Range Search használata (N^2 RAM szörnyeteg kilövése)
        radius = float(1.0 - eps)
        lims, D, I = self.index.range_search(
            self.index.reconstruct_n(0, self.index.ntotal), 
            radius
        )
        
        n = self.index.ntotal
        repeats = np.diff(lims).astype(np.int64)
        row_idx = np.repeat(np.arange(n), repeats)
        
        col_idx = I
        # Lebegőpontos pontatlanságok miatt a similaritás > 1.0 is lehet, ami negatív távolságot eredményezne.
        distances = np.clip(1.0 - D, 0.0, 2.0)

        # Ritka (sparse) mátrix építése csak az eps sugarú környezet pontjaiból
        sparse_dist = coo_matrix((distances, (row_idx, col_idx)), shape=(n, n))
        clustering = DBSCAN(eps=eps, min_samples=min_samples, metric='precomputed').fit(sparse_dist)
        
        ids = [row['face_id'] for row in self.face_metadata]
        with self.db_manager.get_connection() as conn:
            with conn.cursor() as cur:
                cur.executemany("UPDATE ff_faces SET cluster_id = %s WHERE face_id = %s", 
                                [(int(label), f_id) for f_id, label in zip(ids, clustering.labels_)])
            conn.commit()
            
        return len(set(clustering.labels_) - {-1})


class FaceEngine:
    _instance = None

    def __new__(cls, db_manager=None):
        if cls._instance is None:
            cls._instance = super(FaceEngine, cls).__new__(cls)
            cls._instance._initialized = False
            cls._instance._lock = threading.Lock()
            # ÚJ: Index Manager bekötése a Singletonba
            cls._instance.index_manager = FaceIndexManager(
                db_manager, WEIGHT_ANTELOPE, WEIGHT_ADAFACE, WEIGHT_VIT
            )
        return cls._instance

    def _init_engine(self):
        with self._lock:
            if self._initialized:
                return
            
            if not LOAD_AI_MODELS:
                print("\n" + "-"*30)
                print("[INFO] VisionAI: LOAD_AI_MODELS = False.")
                print("[INFO] ONNX modellek betöltése KIKAPCSOLVA. Csak a FAISS/Adatbázis elérhető.")
                print("-" * 30 + "\n")
                self._initialized = True # True-ra rakjuk, hogy ne próbálja végtelen ciklusban újra betölteni
                return
            
            print("VisionAI Engine: Loading ONNX Models...")

            if USE_CUDA:
                # Multi-stream módban minden pipeline külön VRAM limitet kap;
                # do_copy_in_default_stream=False -> memóriaátvitel a saját stream-en történik,
                # ami lehetővé teszi a valódi párhuzamos GPU-GPU futást.
                vram_gb = GPU_VRAM_LIMIT_PER_WORKER_GB if GPU_MULTI_STREAM else 5
                providers = [
                    ('CUDAExecutionProvider', {
                        'device_id': 0,
                        'gpu_mem_limit': int(vram_gb * 1024 ** 3),
                        'cudnn_conv_algo_search': 'HEURISTIC',
                        'do_copy_in_default_stream': not GPU_MULTI_STREAM,
                        'arena_extend_strategy': 'kNextPowerOfTwo',
                    }),
                    'CPUExecutionProvider'
                ]
                mode = f"GPU Multi-Stream ({GPU_VRAM_LIMIT_PER_WORKER_GB}GB/pipeline)" if GPU_MULTI_STREAM else "GPU (CUDA / GTX 1060 optimalizált)"
                print(f"  → Üzemmód: {mode}")
            else:
                providers = ['CPUExecutionProvider']
                print("  → Üzemmód: CPU")

            opt = ort.SessionOptions()
            # Teljes ONNX graph optimalizáció: operátorok összevonása, konstans-kiértékelés, stb.
            opt.graph_optimization_level = ort.GraphOptimizationLevel.ORT_ENABLE_ALL
            # Memória-mintázat optimalizálás: a model-futtatásokat cache-eli, gyorsabb ismételt hívás
            opt.enable_mem_pattern = True
            # CPU aréna: a CPU-oldali allokációkat előre lefoglalja (GPU→CPU másoláshoz hasznos)
            opt.enable_cpu_mem_arena = True
            # Inferencia módja: SEQUENTIAL (Pascal-on általában jobb mint a PARALLEL)
            opt.execution_mode = ort.ExecutionMode.ORT_SEQUENTIAL
            # CPU threadek: eggyel kevesebb mint az összes mag (asyncio loop-nak hagy egyet)
            opt.intra_op_num_threads = max(1, os.cpu_count() - 1)

            self.det_sess = ort.InferenceSession(DET_ONNX, sess_options=opt, providers=providers)
            self.antelope_sess = ort.InferenceSession(ANTELOPE_ONNX, sess_options=opt, providers=providers)
            self.ada_sess = ort.InferenceSession(ADAFACE_ONNX, sess_options=opt, providers=providers)
            self.vit_sess = ort.InferenceSession(VIT_ONNX, sess_options=opt, providers=providers)
            self.hse_sess = ort.InferenceSession(HSE_ONNX, sess_options=opt, providers=providers)

            self._initialized = True
            print("All ONNX models loaded successfully.")
            print("-" * 30 + "\n")

    def _get_anchors(self, target_size):
        """RetinaFace 'Horgony dobozok' generálása. Gyorsítótárazzuk, hogy ne kelljen minden képkockánál újraszámolni."""
        if hasattr(self, '_cached_anchors') and getattr(self, '_cached_target_size', None) == target_size:
            return self._cached_anchors
            
        min_sizes = [[16, 32], [64, 128], [256, 512]]
        steps = [8, 16, 32]
        feature_maps = [[math.ceil(target_size[1]/step), math.ceil(target_size[0]/step)] for step in steps]
        
        anchors = []
        for k, f in enumerate(feature_maps):
            min_sizes_k = min_sizes[k]
            for i, j in product(range(f[0]), range(f[1])):
                for min_size in min_sizes_k:
                    s_kx = min_size / target_size[1]
                    s_ky = min_size / target_size[0]
                    dense_cx = [x * steps[k] / target_size[1] for x in [j + 0.5]]
                    dense_cy = [y * steps[k] / target_size[0] for y in [i + 0.5]]
                    for cy, cx in product(dense_cy, dense_cx):
                        # JAVÍTÁS: += helyett .append(), így (43008, 4) méretű 2D mátrix lesz belőle lapos lista helyett!
                        anchors.append([cx, cy, s_kx, s_ky])
        
        self._cached_anchors = np.array(anchors, dtype=np.float32)
        self._cached_target_size = target_size
        return self._cached_anchors

    def _preprocess_retinaface(self, img_bgr):
        h, w = img_bgr.shape[:2]
        target_w, target_h = DET_SIZE # (1024, 1024)
        scale = min(target_w / w, target_h / h)
        nw, nh = int(w * scale), int(h * scale)
        
        img_resized = cv2.resize(img_bgr, (nw, nh), interpolation=cv2.INTER_LINEAR)
        
        # A RetinaFace esetében a fekete kitöltés (0) a standard
        canvas = np.zeros((target_h, target_w, 3), dtype=np.float32)
        canvas[:nh, :nw] = img_resized
        
        # RetinaFace ResNet50 Standard Normalizálás: 
        # NINCS 255-ös osztás! Csak kivonjuk a BGR átlagokat.
        canvas -= np.array([104.0, 117.0, 123.0], dtype=np.float32)
        
        blob = canvas.transpose(2, 0, 1)
        blob = np.expand_dims(blob, axis=0)
        return blob, scale

    def _postprocess_retinaface(self, outputs, scale, target_size=DET_SIZE, conf_thresh=DET_THRESH):
        loc, conf, landms = None, None, None
        
        # Okos kimenet-válogató: Megnézi a tenzorok utolsó dimenzióját
        for out in outputs:
            if len(out.shape) == 3: out = out[0] # Batch dimenzió levágása
            if out.shape[-1] == 4: loc = out
            elif out.shape[-1] == 2: conf = out[:, 1] # 2 osztály esetén az 1-es az arc
            elif out.shape[-1] == 1: conf = out[:, 0]
            elif out.shape[-1] == 10: landms = out
            
        if loc is None or conf is None:
            return []
            
        # Gyors szűrés: Csak a magabiztos dobozokkal számolunk tovább (CPU kímélő)
        mask = conf > conf_thresh
        loc, conf = loc[mask], conf[mask]
        if landms is not None: landms = landms[mask]
            
        if len(loc) == 0:
            return []
            
        priors = self._get_anchors(target_size)[mask]
        variances = [0.1, 0.2]
        
        # RetinaFace Bounding Box dekódolás matematikai képlete
        boxes = np.concatenate((
            priors[:, :2] + loc[:, :2] * variances[0] * priors[:, 2:],
            priors[:, 2:] * np.exp(loc[:, 2:] * variances[1])
        ), axis=1)
        boxes[:, :2] -= boxes[:, 2:] / 2
        boxes[:, 2:] += boxes[:, :2]
        
        # Visszaskálázás az eredeti képméretre (scale mindkét tengelyen azonos a letterbox miatt)
        boxes[:, 0::2] = np.clip(boxes[:, 0::2] * target_size[0] / scale, 0, None)
        boxes[:, 1::2] = np.clip(boxes[:, 1::2] * target_size[1] / scale, 0, None)
        
        # Arcpontok dekódolása (ha a modell visszaadta őket)
        landmarks = np.zeros((len(loc), 5, 2))
        if landms is not None:
            for i in range(5):
                landmarks[:, i, 0] = (priors[:, 0] + landms[:, i*2] * variances[0] * priors[:, 2]) * target_size[0] / scale
                landmarks[:, i, 1] = (priors[:, 1] + landms[:, i*2+1] * variances[0] * priors[:, 3]) * target_size[1] / scale
                
        # NMSBoxes OpenCV formátum előkészítése [x, y, w, h]
        nms_boxes = [[float(b[0]), float(b[1]), float(b[2]-b[0]), float(b[3]-b[1])] for b in boxes]
        indices = cv2.dnn.NMSBoxes(nms_boxes, conf.tolist(), conf_thresh, NMS_IOU_THRESHOLD)
        
        final_faces = []
        for i in indices:
            idx = i[0] if isinstance(i, (list, np.ndarray)) else i
            face = Face(bbox=np.array(boxes[idx]), kps=np.array(landmarks[idx]), det_score=conf[idx])
            final_faces.append(face)
            
        return final_faces
    # Ezt a FaceEngine osztályhoz adjuk hozzá:
    
    def get_faces(self, img_bgr: np.ndarray, tile_callback=None):
        if not self._initialized:
            self._init_engine()
        
        # ÚJ: Ha az AI engine ki van kapcsolva, az azonnal üres találattal visszatér
        if img_bgr is None or not LOAD_AI_MODELS:
            return []

        h, w = img_bgr.shape[:2]
        all_detected_faces = []

        # 1. MACRO TILING (ÚJ: Nagy arcok felosztása a config alapján)
        if MACRO_TILING_ENABLED:
            macro_faces = self._get_faces_macro_tiled(img_bgr, tile_callback)
            all_detected_faces.extend(macro_faces)

        # 2. STANDARD TILING (Régi pixeles felosztás kis arcokhoz)
        # Fejlesztés #2: Portré mód (álló kép) felismerése
        if STANDARD_TILING_ENABLED and (w >= TILING_MIN_WIDTH or h >= TILING_MIN_WIDTH):
            std_faces = self._get_faces_tiled(img_bgr, tile_callback)
            all_detected_faces.extend(std_faces)

        # 3. ALAPÉRTELMEZETT / BIZTONSÁGI FELDOLGOZÁS
        # Ha egyik csempézés sincs aktív, mindenképp átfuttatjuk a retinafacen-n az egész képet.
        if not STANDARD_TILING_ENABLED and not MACRO_TILING_ENABLED:
            if tile_callback: tile_callback(1, 1, 0)
            blob, scale = self._preprocess_retinaface(...)
            outputs = self.det_sess.run(None, {self.det_sess.get_inputs()[0].name: blob})
            full_faces = self._postprocess_retinaface(outputs, scale, conf_thresh=DET_THRESH)
            all_detected_faces.extend(full_faces)
            if tile_callback: tile_callback(1, 1, len(full_faces))

        # ÚJ: Mivel több módszer is futhat egyszerre, rengeteg átfedő találat lehet.
        # Itt alkalmazzuk a közös szűrőt (NMS) az összes eddig talált arcra!
        detected_faces = self._apply_nms(all_detected_faces)

        processed_faces = []
        for face in detected_faces:
            quality_ok, reason = self._check_basic_quality(face, img_bgr)
            if not quality_ok:
                logger.debug(f"[SKIP] Face det_score={face.det_score:.2f} — reason: {reason}")
                continue
                
            # --- Igazítás és Feature extrakció (RetinaFace kps alapján) ---
            aligned_112 = self._align_face(img_bgr, face.kps, target_size=(112, 112))
            aligned_224 = self._align_face(img_bgr, face.kps, target_size=(224, 224))
            
            if aligned_112 is None or aligned_224 is None:
                logger.debug(f"[SKIP] Face alignment failed")
                continue
            
            ada_emb, ada_score = self._run_rec_model(self.ada_sess, aligned_112)
            if ada_score < QUALITY_GATE_ADAFACE:
                logger.debug(f"[SKIP] Face ada_score={ada_score:.2f} < {QUALITY_GATE_ADAFACE}")
                continue
            
            face.adaface_embedding = ada_emb
            face.adaface_score = ada_score
            
            antelope_emb, _ = self._run_rec_model(self.antelope_sess, aligned_112)
            face.embedding = antelope_emb

            vit_emb, _ = self._run_rec_model(self.vit_sess, aligned_112)
            face.vit_embedding = vit_emb

            age, gender = self._run_age_gender(aligned_224)
            face.age = age
            face.gender = gender
            
            processed_faces.append(face)
            
        return processed_faces

    def _get_faces_tiled(self, img_bgr: np.ndarray, tile_callback=None):
        h, w = img_bgr.shape[:2]
        ts = TILING_SIZE
        ov = TILING_OVERLAP
        all_faces =[]

        x_steps = list(range(0, w, ts - ov))
        y_steps = list(range(0, h, ts - ov))
        total_tiles = len(x_steps) * len(y_steps)

        tile_count = 0
        for y in y_steps:
            for x in x_steps:
                tile_count += 1
                x2, y2 = min(w, x + ts), min(h, y + ts)
                tile = img_bgr[y:y2, x:x2]

                if tile.shape[0] < 64 or tile.shape[1] < 64:
                    if tile_callback: tile_callback(tile_count, total_tiles, 0)
                    continue

                blob, scale = self._preprocess_retinaface(tile)
                outputs = self.det_sess.run(None, {self.det_sess.get_inputs()[0].name: blob})
                faces_in_tile = self._postprocess_retinaface(outputs, scale, conf_thresh=DET_THRESH)

                if tile_callback: tile_callback(tile_count, total_tiles, len(faces_in_tile))

                for face in faces_in_tile:
                    face.bbox[0] += x; face.bbox[1] += y; face.bbox[2] += x; face.bbox[3] += y
                    face.kps[:, 0] += x; face.kps[:, 1] += y
                    all_faces.append(face)

        return all_faces

    def _get_faces_macro_tiled(self, img_bgr: np.ndarray, tile_callback=None):
        h, w = img_bgr.shape[:2]
        
        # 1. Rábízzuk a kódra: Automatikus rács-tájolás a képarányhoz.
        # Ha a kép álló, de a config fekvő (cols > rows), megfordítjuk az értékeket!
        rows, cols = MACRO_TILING_ROWS, MACRO_TILING_COLS
        if h > w and cols > rows:
            rows, cols = cols, rows
        elif w > h and rows > cols:
            rows, cols = cols, rows
            
        p = MACRO_TILING_OVERLAP_PERCENT
        
        # 2. Csempe méretek pontos kiszámítása az átfedés miatt
        # Képlet: Teljes_méret = Oszlopok * Csempe_Szélesség - (Oszlopok - 1) * (Csempe_Szélesség * Átfedés_%)
        tw = w / (cols - p * (cols - 1)) if cols > 1 else w
        th = h / (rows - p * (rows - 1)) if rows > 1 else h
        
        # Lépésköz (mennyit ugrunk az x és y tengelyen csempénként)
        step_x = tw * (1 - p)
        step_y = th * (1 - p)
        
        all_faces = []
        total_tiles = rows * cols
        tile_count = 0
        
        for r in range(rows):
            for c in range(cols):
                tile_count += 1
                
                # Koordináták számítása (int konverzióval)
                x1 = int(c * step_x)
                y1 = int(r * step_y)
                
                # Biztonsági háló kerekítési hibák ellen: az utolsó csempe mindig kifut a peremig!
                x2 = w if c == cols - 1 else int(x1 + tw)
                y2 = h if r == rows - 1 else int(y1 + th)
                
                if x2 <= x1 or y2 <= y1:
                    continue
                    
                tile = img_bgr[y1:y2, x1:x2]
                
                # Biztonsági ellenőrzés túl kicsi szeletekre
                if tile.shape[0] < 64 or tile.shape[1] < 64:
                    if tile_callback: tile_callback(tile_count, total_tiles, 0)
                    continue
                    
                # A kivágás torzításmentes átméretezését és kitöltését a RetinaFace preprocess elvégzi (letterbox)
                blob, scale = self._preprocess_retinaface(tile)
                outputs = self.det_sess.run(None, {self.det_sess.get_inputs()[0].name: blob})
                faces_in_tile = self._postprocess_retinaface(outputs, scale, conf_thresh=DET_THRESH)
                
                if tile_callback: tile_callback(tile_count, total_tiles, len(faces_in_tile))
                
                # A dobozok és arcpontok visszaszámolása az eredeti, hatalmas kép koordinátáira
                for face in faces_in_tile:
                    face.bbox[0] += x1; face.bbox[1] += y1
                    face.bbox[2] += x1; face.bbox[3] += y1
                    face.kps[:, 0] += x1; face.kps[:, 1] += y1
                    all_faces.append(face)
                    
        return all_faces # Az összesítést és NMS duplikátum szűrést a hívó get_faces végzi!

    def _apply_nms(self, faces):
        if not faces: return []
        raw_boxes = np.array([f.bbox for f in faces], dtype=np.float32)
        # NMSBoxes [x, y, w, h] formátumot vár — konvertálás x1,y1,x2,y2-ből
        nms_boxes = [[float(b[0]), float(b[1]), float(b[2]-b[0]), float(b[3]-b[1])] for b in raw_boxes]
        scores = np.array([f.det_score for f in faces], dtype=np.float32)
        indices = cv2.dnn.NMSBoxes(nms_boxes, scores.tolist(), DET_THRESH, NMS_IOU_THRESHOLD)
        return [faces[i[0] if isinstance(i, (list, np.ndarray)) else i] for i in indices]

    def _run_rec_model(self, sess, aligned_face):
        img = aligned_face[:, :, ::-1].transpose(2, 0, 1).astype(np.float32)
        img = (img - 127.5) / 127.5
        
        blob = np.expand_dims(img, axis=0)
        out = sess.run(None, {sess.get_inputs()[0].name: blob})[0][0]
        
        norm = np.linalg.norm(out)
        return out / (norm + 1e-6), norm

    def _run_age_gender(self, aligned_face):
        img = aligned_face[:, :, ::-1].transpose(2, 0, 1).astype(np.float32) / 255.0
        blob = np.expand_dims(img, axis=0)
        
        out = self.hse_sess.run(None, {self.hse_sess.get_inputs()[0].name: blob})[0][0]
        age = int(max(0, min(100, round(out[0]))))
        gender = 'F' if out[1] >= 0.5 else 'M'
        return age, gender

    def _align_face(self, img_bgr, kps, target_size=(112, 112)):
        dst_map = np.array([
            [38.2946, 51.6963], [73.5318, 51.5014], [56.0252, 71.7366],
            [41.5493, 92.3655], [70.7299, 92.2041]
        ], dtype=np.float32)
        if target_size != (112, 112):
            dst_map *= (target_size[0] / 112.0)
            
        tform = cv2.estimateAffinePartial2D(kps.astype(np.float32), dst_map)[0]
        if tform is None:
            return None
        return cv2.warpAffine(img_bgr, tform, target_size, borderValue=0.0)

    def _check_basic_quality(self, face, img_bgr):
        if face.det_score < QUALITY_MIN_CONFIDENCE: 
            return False, "low_confidence"
        x1, y1, x2, y2 = face.bbox
        if (x2-x1) < QUALITY_MIN_SIZE or (y2-y1) < QUALITY_MIN_SIZE: 
            return False, "too_small"
            
        if QUALITY_BLUR_THRESHOLD > 0:
            h_img, w_img = img_bgr.shape[:2]
            sx1, sy1 = max(0, int(x1)), max(0, int(y1))
            sx2, sy2 = min(w_img, int(x2)), min(h_img, int(y2))
            face_crop = img_bgr[sy1:sy2, sx1:sx2]
            if face_crop.size > 0:
                gray = cv2.cvtColor(face_crop, cv2.COLOR_BGR2GRAY)
                blur_score = cv2.Laplacian(gray, cv2.CV_64F).var()
                if blur_score < QUALITY_BLUR_THRESHOLD:
                    return False, f"blurred ({blur_score:.1f})"
                    
        return True, ""

    def search_similar(self, query_face, gallery_id: int, max_results: int = 15):
        # ÚJ: Keresés átirányítása a RAM-ba gyorsítottt objektumra
        return self.index_manager.search_similar(query_face, gallery_id, SIMILARITY_THRESHOLD, max_results)

    def cluster_all_faces(self, gallery_id: int):
        import traceback
        try:
            res = self.index_manager.cluster_dbscan(gallery_id, DBSCAN_EPS, DBSCAN_MIN_SAMPLES)
            with open('/var/www/nbence.hu/phantomtrack/facefinder/temp/cluster_debug.log', 'a') as f:
                f.write(f"Cluster finished for {gallery_id}: {res} clusters found\n")
            return res
        except Exception as e:
            with open('/var/www/nbence.hu/phantomtrack/facefinder/temp/cluster_debug.log', 'a') as f:
                f.write(f"Cluster ERROR for {gallery_id}:\n{traceback.format_exc()}\n")
            raise