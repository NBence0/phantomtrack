<?php
// phantomtrack/includes/Logger.php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/VisitorTracker.php';

class Logger {
    private static $trackers = [];

    // Singleton Visitor Tracker elérése galériánként
    private static function getVisitorId($galleryId) {
        if (!isset(self::$trackers[$galleryId])) {
            self::$trackers[$galleryId] = new VisitorTracker($galleryId);
        }
        return self::$trackers[$galleryId]->getId();
    }

    /**
     * Fő logoló metódus
     */
    public static function log($action, $data = [], $category = 'general', $galleryId = null) {
        if (!$galleryId) return false;

        try {
            $pdo = getDB();
            $vid = self::getVisitorId($galleryId); // Automatikusan létrehozza a cookie-t ha kell

            // 1. TELJESÍTMÉNY METRIKÁK
            if ($category === 'performance') {
                $stmt = $pdo->prepare("INSERT INTO log_performance (gallery_id, visitor_id, ttfb_ms, dom_load_ms, full_load_ms, resource_count) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $galleryId, $vid, 
                    $data['ttfb'] ?? 0,
                    $data['dom_load'] ?? 0,
                    $data['full_load'] ?? 0,
                    $data['resources'] ?? 0
                ]);
            }
            // 2. KLIENS HIBÁK
            elseif ($category === 'error') {
                $stmt = $pdo->prepare("INSERT INTO log_client_errors (gallery_id, visitor_id, message, file, line_no, stack_trace) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $galleryId, $vid,
                    substr($data['message'] ?? 'Unknown Error', 0, 1000),
                    substr($data['file'] ?? '', 0, 255),
                    $data['line'] ?? 0,
                    $data['stack'] ?? ''
                ]);
            }
            // 3. RENDSZER ESEMÉNYEK
            elseif ($category === 'system' && in_array($action, ['tab_hidden', 'tab_visible', 'resize'])) {
                $stmt = $pdo->prepare("INSERT INTO log_system_events (gallery_id, visitor_id, event_type, duration_ms, new_width, new_height) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $galleryId, $vid,
                    $action,
                    $data['duration'] ?? null,
                    $data['width'] ?? null,
                    $data['height'] ?? null
                ]);
            }
            // 4. GALÉRIA
            elseif ($category === 'gallery') {
                $dbAction = ($action === 'lightbox_view') ? 'lightbox_open' : 'image_tag_insert';
                $stmt = $pdo->prepare("INSERT INTO log_gallery (gallery_id, visitor_id, action_type, image_filename, image_index) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([
                    $galleryId, $vid, 
                    $dbAction, 
                    $data['image'] ?? 'unknown', 
                    $data['index'] ?? null
                ]);
            }
            // 5. ANALITIKA (Kilépéskor)
            elseif ($category === 'analytics') {
                $stmt = $pdo->prepare("INSERT INTO log_analytics (gallery_id, visitor_id, page_url, time_on_page_sec, scroll_depth_percent) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([
                    $galleryId, $vid, 
                    $data['page_url'] ?? '', 
                    $data['time_sec'] ?? 0, 
                    $data['scroll_depth_percent'] ?? 0
                ]);
            }
            // 6. OLDALLETÖLTÉS + ESZKÖZ FRISSÍTÉS
            elseif ($action === 'page_load' || $action === 'device_info') {
                if ($action === 'page_load') {
                    $stmt = $pdo->prepare("INSERT INTO log_page_views (gallery_id, visitor_id, url, referer) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$galleryId, $vid, $_SERVER['REQUEST_URI'], $data['referer'] ?? null]);
                }
                
                if (isset($data['viewport'])) {
                    $stmt = $pdo->prepare("UPDATE visitors SET viewport_width = ?, viewport_height = ?, language = ? WHERE id = ?");
                    $stmt->execute([
                        $data['viewport']['width'], 
                        $data['viewport']['height'], 
                        substr($data['language'] ?? 'en', 0, 10),
                        $vid
                    ]);
                }
            }
            // 7. INTERAKCIÓK
            else {
                $metaArray = $data['metadata'] ?? [];
                if ($category === 'system') $metaArray = $data;

                $metadata = !empty($metaArray) ? json_encode($metaArray, JSON_UNESCAPED_UNICODE) : null;
                $text = $data['text'] ?? $data['text_preview'] ?? '';

                $stmt = $pdo->prepare("
                    INSERT INTO log_user_actions 
                    (gallery_id, visitor_id, action_type, target_selector, target_text, mouse_x, mouse_y, page_section, metadata) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $galleryId, $vid,
                    $action,
                    substr($data['selector'] ?? '', 0, 255),
                    substr($text, 0, 255),
                    $data['x'] ?? null,
                    $data['y'] ?? null,
                    $data['section'] ?? null,
                    $metadata
                ]);
            }

            return true;

        } catch (Exception $e) {
            error_log("PhantomTrack Logger error: " . $e->getMessage());
            return false;
        }
    }
}
?>
