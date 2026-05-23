# pyrefly: ignore [missing-import]
import asyncio
import os
import concurrent.futures
import numpy as np
# pyrefly: ignore [import-error, missing-import]
import cv2
import logging
from pathlib import Path

# pyrefly: ignore [import-error, missing-import]
from decord import VideoReader, cpu
# pyrefly: ignore [missing-import]
import decord

from rich.progress import Progress, SpinnerColumn, TextColumn, BarColumn, TaskProgressColumn, TimeRemainingColumn
from rich.console import Console
from rich.panel import Panel
import time
import gc

from backend.config import (
    PROCESS_EVERY_N_FRAME, IMAGE_EXTENSIONS, VIDEO_EXTENSIONS, BASE_DIR,
    AUTO_SCAN_ON_STARTUP, ASYNCIO_TIMEOUT, LOAD_AI_MODELS 
)
from backend.database import DatabaseManager
from backend.engine import FaceEngine

decord.bridge.set_bridge("native")
logger = logging.getLogger(__name__)
console = Console()

from backend.config import GPU_MULTI_STREAM, GPU_PARALLEL_WORKERS

_executor = concurrent.futures.ThreadPoolExecutor(
    max_workers=GPU_PARALLEL_WORKERS if GPU_MULTI_STREAM else 2
)


def _fix_exif_orientation(img_bgr):
    """
    E3 fix: EXIF orientáció javítás PIL segítségével.
    Ha a PIL nem elérhető, az eredeti képet adja vissza.
    """
    try:
        from PIL import Image
        import io
        # BGR → RGB → PIL
        img_rgb = cv2.cvtColor(img_bgr, cv2.COLOR_BGR2RGB)
        pil_img = Image.fromarray(img_rgb)

        exif_data = pil_img.getexif()
        orientation = exif_data.get(274)  # 274 = Orientation tag

        rotations = {3: 180, 6: 270, 8: 90}
        if orientation in rotations:
            pil_img = pil_img.rotate(rotations[orientation], expand=True)
            img_rgb = np.array(pil_img)
            img_bgr = cv2.cvtColor(img_rgb, cv2.COLOR_RGB2BGR)
    except Exception:
        pass
    return img_bgr


def save_face_thumb(img_bgr, bbox, file_path, index) -> bytes:
    """Kivágja az arcot és nyers WebP bájtokat ad vissza (BLOB tároláshoz)."""
    try:
        x1, y1, x2, y2 = [int(v) for v in bbox]
        h_img, w_img = img_bgr.shape[:2]
        
        # Egy kis padding az arcnak (opcionális, de szebb)
        w, h = x2 - x1, y2 - y1
        pad_x = int(w * 0.2)
        pad_y = int(h * 0.2)
        
        sx1 = max(0, x1 - pad_x)
        sy1 = max(0, y1 - pad_y)
        sx2 = min(w_img, x2 + pad_x)
        sy2 = min(h_img, y2 + pad_y)
        
        face_img = img_bgr[sy1:sy2, sx1:sx2]
        if face_img.size == 0:
            return b''

        # 160x160 méret és 90-es minőségű WebP
        face_img = cv2.resize(face_img, (160, 160), interpolation=cv2.INTER_AREA)
        _, buffer = cv2.imencode('.webp', face_img, [cv2.IMWRITE_WEBP_QUALITY, 90])
        return buffer.tobytes()
    except Exception:
        return b''


class BackgroundWorker:
    def __init__(self, db_manager: DatabaseManager, engine: FaceEngine):
        self.db = db_manager
        self.engine = engine
        self._running = False
        self.progress = None # Ide kerül a grafikus felület

    async def start(self):
        if not LOAD_AI_MODELS:
            console.print("[bold yellow]LOAD_AI_MODELS = False. A feldolgozó (worker) letiltva, nem indul el.[/bold yellow]")
            return
        
        self._running = True
        loop = asyncio.get_event_loop()
        
        if AUTO_SCAN_ON_STARTUP:
            img_dir = os.path.join(os.path.dirname(BASE_DIR), "images")
            if os.path.exists(img_dir):
                console.print(f"[bold cyan]🔍 Automatikus szkennelés indítása: {img_dir}...[/bold cyan]")
                loop.run_in_executor(_executor, self.scan_directory, img_dir)

        # --- A GYÖNYÖRŰ GRAFIKUS FELÜLET INICIALIZÁLÁSA ---
        self.progress = Progress(
            SpinnerColumn(),
            TextColumn("[progress.description]{task.description}"),
            BarColumn(bar_width=40),
            TaskProgressColumn(),
            TextColumn("[bold cyan]{task.completed}/{task.total}"), # Számláló (pl. 5/12)
            TextColumn("•"),
            TimeRemainingColumn(), # ETA
            console=console,
            expand=False
        )

        with self.progress:
            # Fő taszk: Az összes fájl
            main_task = self.progress.add_task("[bold blue]Fájlok", total=1)
            # Al-taszk: Aktuális kép csempézése
            file_task = self.progress.add_task("[yellow]Várakozás...", total=1, visible=False)

            while self._running:
                # Dinamikusan frissítjük az összes hátralévő fájl számát
                pending_count = self.db.get_pending_job_count()
                if pending_count > 0:
                    # Ha vannak feladatok, beállítjuk a fő csíkot (mennyi van még hátra)
                    self.progress.update(main_task, total=self.progress.tasks[main_task].completed + pending_count)

                try:
                    job = self.db.get_pending_job()
                except Exception as e:
                    await asyncio.sleep(5)
                    continue

                if not job:
                    await asyncio.sleep(2)
                    continue

                job_id = job["id"]
                file_path = job["file_path"]
                filename = Path(file_path).name

                # Aktuális fájl csíkjának megjelenítése
                self.progress.update(file_task, description=f"[bold magenta]Kép: {filename}", completed=0, visible=True)

                try:
                    # Átadjuk a progress objektumokat is
                    future = loop.run_in_executor(_executor, self.process_file, file_path, self.progress, file_task)
                    success = await asyncio.wait_for(future, timeout=ASYNCIO_TIMEOUT)

                    if success:
                        self.db.mark_job_done(job_id)
                        self.progress.advance(main_task, 1)
                    else:
                        self.db.mark_job_failed(job_id)
                        console.print(f"[red]❌ Hiba a fájl feldolgozásakor: {filename}[/red]")
                        self.progress.advance(main_task, 1)

                except asyncio.TimeoutError:
                    self.db.mark_job_failed(job_id)
                    console.print(f"[red]⏱️ Időtúllépés ({ASYNCIO_TIMEOUT}s): {filename}[/red]")
                except Exception as e:
                    self.db.mark_job_failed(job_id)
                    console.print(f"[red]⚠️ Kritikus hiba ({filename}): {e}[/red]")

                await asyncio.sleep(0.01)

    def stop(self):
        self._running = False
        _executor.shutdown(wait=False)

    def scan_directory(self, root_path: str):
        """Bejár egy könyvtárat és minden új képet/videót betesz a sorba."""
        count = 0
        for root, _, files in os.walk(root_path):
            for file in files:
                ext = os.path.splitext(file)[1].lower()
                if ext in IMAGE_EXTENSIONS or ext in VIDEO_EXTENSIONS:
                    full_path = os.path.join(root, file)
                    if self.db.add_job(full_path):
                        count += 1
        return count

    def process_file(self, file_path: str, progress, file_task) -> bool:
        ext = Path(file_path).suffix.lower()
        if ext in IMAGE_EXTENSIONS:
            return self._process_image(file_path, progress, file_task)
        elif ext in VIDEO_EXTENSIONS:
            return self._process_video(file_path, progress, file_task)
        return False

    def _process_image(self, file_path: str, progress, file_task) -> bool:
        filename = Path(file_path).name
        try:
            img = cv2.imdecode(np.fromfile(file_path, dtype=np.uint8), cv2.IMREAD_COLOR)
        except Exception:
            return False

        if img is None:
            return False

        img = _fix_exif_orientation(img)

        # CALLBACK A CSEMPÉZÉSHEZ (Ez frissíti a 2. csíkot)
        def tile_update(current_tile, total_tiles, faces_in_tile):
            text_color = "[green]" if faces_in_tile > 0 else "[dim white]"
            face_text = "" # f"{text_color}(+ {faces_in_tile} arc ezen a részen)[/]" if faces_in_tile > 0 else ""
            
            progress.update(
                file_task, 
                total=total_tiles, 
                completed=current_tile,
                description=f"[magenta]Csempézés: {filename} {face_text}"
            )

        # Arcok keresése (a callback átadásával)
        faces = self.engine.get_faces(img, tile_callback=tile_update)

        # UI befejezése az adott képre
        progress.update(file_task, completed=progress.tasks[file_task].total, description=f"[bold green]✔ {filename} kész")

        if not faces:
            self.db.save_faces(file_path, "image", 0.0, [])
            self.db.flush()
            console.print(f"[dim white]  └─ {filename}: Nem találtunk arcot.[/dim white]")
            return True

        for i, face in enumerate(faces):
            thumb_data = save_face_thumb(img, face.bbox, file_path, i)
            face._thumb_name = thumb_data 

        self.db.save_faces(file_path, "image", 0.0, faces)
        self.db.flush()
        
        console.print(f"[bold green]📸 {filename} feldolgozva -> [white bg=green] {len(faces)} [/white bg=green] minőségi arc mentve.[/bold green]")
        return True
    
    def _process_video(self, file_path: str, progress, file_task) -> bool:
        filename = Path(file_path).name
        start_time = time.time()  # Munkavégzés kezdetének rögzítése
        vr = None
        
        try:
            vr = VideoReader(file_path, ctx=cpu(0))
            fps = vr.get_avg_fps()
            if fps <= 0: 
                return False

            frames_to_process = len(list(range(0, len(vr), PROCESS_EVERY_N_FRAME)))
            progress.update(file_task, total=frames_to_process, completed=0, description=f"[blue]Videó képkockák: {filename}")

            total_faces_in_video = 0
            processed_count = 0

            for idx in range(0, len(vr), PROCESS_EVERY_N_FRAME):
                # Zombi szál megelőzés: Belső timeout kikényszerítése
                if time.time() - start_time > ASYNCIO_TIMEOUT:
                    console.print(f"[red]⏱️ Időtúllépés miatti belső megszakítás: {filename}[/red]")
                    break

                frame_rgb = vr[idx].asnumpy()
                frame_bgr = cv2.cvtColor(frame_rgb, cv2.COLOR_RGB2BGR)

                h, w = frame_bgr.shape[:2]
                if w > 3840:
                    scale = 3840 / w
                    frame_bgr = cv2.resize(frame_bgr, (max(1, int(w * scale)), max(1, int(h * scale))))

                timestamp = idx / fps
                faces = self.engine.get_faces(frame_bgr)
                total_faces_in_video += len(faces)

                for i, face in enumerate(faces):
                    thumb_data = save_face_thumb(frame_bgr, face.bbox, file_path + f"@{idx}", i)
                    face._thumb_name = thumb_data

                self.db.save_faces(file_path, "video", timestamp, faces)
                
                processed_count += 1
                progress.update(file_task, completed=processed_count, description=f"[blue]Videó: {filename} [dim](+ {len(faces)} arc)[/dim]")

            self.db.flush()
            console.print(f"[bold blue]🎬 Videó {filename} kész -> Összesen [white bg=blue] {total_faces_in_video} [/white bg=blue] arc detektálva.[/bold blue]")
            return True
            
        except Exception as e:
            console.print(f"[red]⚠️ Hiba videó közben ({filename}): {e}[/red]")
            return False
            
        finally:
            # Memória leak explicit megszüntetése (Garbage Collector hívással)
            if vr is not None:
                del vr
            gc.collect()

    def backfill_thumbnails(self, limit: int = 500) -> int:
        """Újragenerálja a hiányzó vagy fájl-alapú thumbnail-eket az eredeti képekből."""
        with self.db.get_connection() as conn:
            cur = conn.cursor()
            cur.execute("""
                SELECT face_id, video_path, bbox, timestamp_sec 
                FROM faces 
                WHERE face_thumb IS NULL OR face_thumb NOT LIKE 'data:%'
                LIMIT ?
            """, (limit,))
            rows = cur.fetchall()

        if not rows:
            return 0

        count = 0
        for face_id, video_path, bbox_str, timestamp in rows:
            try:
                if not os.path.exists(video_path):
                    continue
                
                bbox = [float(x) for x in bbox_str.split(',')]
                img = None
                
                if any(video_path.lower().endswith(ext) for ext in IMAGE_EXTENSIONS):
                    img = cv2.imdecode(np.fromfile(video_path, dtype=np.uint8), cv2.IMREAD_COLOR)
                elif any(video_path.lower().endswith(ext) for ext in VIDEO_EXTENSIONS):
                    vr = VideoReader(video_path, ctx=cpu(0))
                    fps = vr.get_avg_fps()
                    frame_idx = int(timestamp * fps)
                    if frame_idx < len(vr):
                        img = cv2.cvtColor(vr[frame_idx].asnumpy(), cv2.COLOR_RGB2BGR)

                if img is not None:
                    thumb_data = save_face_thumb(img, bbox, video_path, face_id)
                    if thumb_data:
                        with self.db.get_connection() as conn:
                            conn.execute("UPDATE faces SET face_thumb = ? WHERE face_id = ?", (thumb_data, face_id))
                        count += 1
            except Exception:
                pass

        return count
