import sqlite3
import os

DB_PATH = "/var/www/nbence.hu/face/data/pipeline_data.db"
NEW_IMAGES_DIR = "/var/www/nbence.hu/face/images/"

def fix_paths():
    if not os.path.exists(DB_PATH):
        print(f"Hiba: Adatbazis nem talalhato: {DB_PATH}")
        return

    conn = sqlite3.connect(DB_PATH)
    cur = conn.cursor()

    print("--- Utvonalak javitasa az adatbazisban ---")

    cur.execute("SELECT face_id, video_path FROM faces")
    faces = cur.fetchall()
    print(f"Faces rekordok szama: {len(faces)}")
    
    face_updates =[]
    for fid, old_path in faces:
        if '\\' in old_path or 'd:' in old_path.lower():
            filename = old_path.replace('\\', '/').split('/')[-1]
            new_path = os.path.join(NEW_IMAGES_DIR, filename)
            face_updates.append((new_path, fid))

    if face_updates:
        print(f"Javitando faces rekord: {len(face_updates)}")
        cur.executemany("UPDATE faces SET video_path = ? WHERE face_id = ?", face_updates)
        print("Faces sikeresen frissitve.")
    else:
        print("Nem talaltam javitando utvonalat a faces tablaban.")

    cur.execute("SELECT id, file_path FROM jobs")
    jobs = cur.fetchall()
    print(f"Jobs rekordok szama: {len(jobs)}")
    
    job_updates =[]
    for jid, old_path in jobs:
        if '\\' in old_path or 'd:' in old_path.lower():
            filename = old_path.replace('\\', '/').split('/')[-1]
            new_path = os.path.join(NEW_IMAGES_DIR, filename)
            job_updates.append((new_path, jid))

    if job_updates:
        print(f"Javitando jobs rekord: {len(job_updates)}")
        for new_p, jid in job_updates:
            try:
                cur.execute("UPDATE jobs SET file_path = ? WHERE id = ?", (new_p, jid))
            except sqlite3.IntegrityError:
                cur.execute("DELETE FROM jobs WHERE id = ?", (jid,))
        print("Jobs sikeresen frissitve.")
    else:
        print("Nem talaltam javitando utvonalat a jobs tablaban.")

    conn.commit()
    conn.close()
    print("--- Kesz! ---")

if __name__ == "__main__":
    fix_paths()