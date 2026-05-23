import sys
import os
import asyncio
import argparse

current_dir = os.path.dirname(os.path.abspath(__file__))
root_dir = os.path.dirname(current_dir)
if root_dir not in sys.path:
    sys.path.insert(0, root_dir)

try:
    from backend import config
except ImportError:
    print("Hiba: A 'backend' modul nem található.")
    sys.exit(1)

def run_windows():
    parser = argparse.ArgumentParser(description="VisionAI Windows Launcher")
    parser.add_argument("--port", type=int, default=8000, help="Port beállítása (default: 8000)")
    args = parser.parse_args()

    try:
        from backend.main import app
        import uvicorn
    except ImportError as e:
        print(f"Hiba az importálásnál: {e}")
        print("Győződj meg róla, hogy a szükséges CUDA és ONNX Runtime könyvtárak elérhetőek a PATH-ban.")
        sys.exit(1)
    
    print("\n" + "="*50)
    print("   VisionAI Backend - WINDOWS INDITAS (ONNX Runtime)")
    print("="*50)
    print(f"[*] Báziskönyvtár: {root_dir}")
    print(f"[*] Gyorsítás: ONNX Runtime (NVIDIA GPU javasolt)")
    
    db_path = config.DB_PATH
    if not os.path.exists(db_path):
        print(f"[!] Adatbázis nem található: {db_path}")
    else:
        print(f"[*] Adatbázis: OK")

    print(f"\n[*] API elérhető: http://127.0.0.1:{args.port}")
    print("-" * 50 + "\n")
    
    try:
        uvicorn.run(app, host="127.0.0.1", port=args.port, reload=False, workers=1)
    except KeyboardInterrupt:
        print("\n[!] Szerver leállítása...")
    except Exception as e:
        print(f"\n[HIBA] Váratlan hiba történt: {e}")

if __name__ == "__main__":
    if sys.platform == 'win32':
        asyncio.set_event_loop_policy(asyncio.WindowsSelectorEventLoopPolicy())
    
    run_windows()