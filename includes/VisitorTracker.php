<?php
// phantomtrack/includes/VisitorTracker.php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php'; // getIpAddress-hez

class VisitorTracker {
    private $pdo;
    private $visitorId = null;
    private $visitorUuid = null;
    private $galleryId;

    public function __construct($galleryId) {
        $this->pdo = getDB();
        $this->galleryId = $galleryId;
        $this->identifyVisitor();
    }

    private function identifyVisitor() {
        if (isset($_COOKIE['visitor_uuid'])) {
            $this->visitorUuid = $_COOKIE['visitor_uuid'];
            $this->visitorId = $this->getVisitorIdByUuid($this->visitorUuid);
        }

        if (!$this->visitorId) {
            if (!$this->visitorUuid) {
                $this->visitorUuid = $this->generateUuid();
            }
            $this->createNewVisitor($this->visitorUuid);
            
            // Süti beállítása 1 évre
            setcookie('visitor_uuid', $this->visitorUuid, time() + (86400 * 365), "/");
        } else {
            // Meglévő user -> Frissítjük a last_seen dátumot
            $this->updateLastSeen();
        }
    }

    private function createNewVisitor($uuid) {
        $stmt = $this->pdo->prepare("
            INSERT INTO visitors (gallery_id, visitor_uuid, ip_address, user_agent) 
            VALUES (:gid, :uuid, :ip, :ua)
        ");
        $stmt->execute([
            ':gid' => $this->galleryId,
            ':uuid' => $uuid,
            ':ip' => getIpAddress(),
            ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
        ]);
        $this->visitorId = $this->pdo->lastInsertId();
    }

    private function getVisitorIdByUuid($uuid) {
        $stmt = $this->pdo->prepare("SELECT id FROM visitors WHERE visitor_uuid = ? AND gallery_id = ?");
        $stmt->execute([$uuid, $this->galleryId]);
        return $stmt->fetchColumn();
    }

    private function updateLastSeen() {
        $stmt = $this->pdo->prepare("UPDATE visitors SET last_seen = NOW(), ip_address = ? WHERE id = ?");
        $stmt->execute([getIpAddress(), $this->visitorId]);
    }

    private function generateUuid() {
        return sprintf( '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    public function getId() {
        return $this->visitorId;
    }
}
?>
