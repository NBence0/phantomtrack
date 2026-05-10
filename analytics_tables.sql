CREATE TABLE IF NOT EXISTS `visitors` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `gallery_id` int(11) NOT NULL,
  `visitor_uuid` char(36) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `screen_resolution` varchar(20) DEFAULT NULL,
  `first_seen` datetime DEFAULT current_timestamp(),
  `last_seen` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `viewport_width` int(5) DEFAULT NULL,
  `viewport_height` int(5) DEFAULT NULL,
  `language` varchar(10) DEFAULT NULL,
  `connection_type` varchar(20) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_uuid_gallery` (`visitor_uuid`, `gallery_id`),
  KEY `idx_last_seen` (`last_seen`),
  KEY `fk_visitors_gallery` (`gallery_id`),
  CONSTRAINT `fk_visitors_gallery_constraint` FOREIGN KEY (`gallery_id`) REFERENCES `galleries` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `log_analytics` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `gallery_id` int(11) NOT NULL,
  `visitor_id` int(11) NOT NULL,
  `page_url` varchar(2048) NOT NULL,
  `time_on_page_sec` int(11) DEFAULT 0,
  `scroll_depth_percent` int(3) DEFAULT 0,
  `interaction_count` int(11) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_analytics_visitor` (`visitor_id`),
  KEY `fk_analytics_gallery` (`gallery_id`),
  CONSTRAINT `fk_analytics_visitor_constraint` FOREIGN KEY (`visitor_id`) REFERENCES `visitors` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_analytics_gallery_constraint` FOREIGN KEY (`gallery_id`) REFERENCES `galleries` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `log_client_errors` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `gallery_id` int(11) NOT NULL,
  `visitor_id` int(11) NOT NULL,
  `message` text NOT NULL,
  `file` varchar(255) DEFAULT NULL,
  `line_no` int(11) DEFAULT NULL,
  `stack_trace` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_err_visitor` (`visitor_id`),
  KEY `fk_err_gallery` (`gallery_id`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `fk_err_visitor_constraint` FOREIGN KEY (`visitor_id`) REFERENCES `visitors` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_err_gallery_constraint` FOREIGN KEY (`gallery_id`) REFERENCES `galleries` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `log_gallery` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `gallery_id` int(11) NOT NULL,
  `visitor_id` int(11) NOT NULL,
  `action_type` enum('lightbox_open','image_tag_insert') NOT NULL,
  `image_filename` varchar(255) NOT NULL,
  `image_index` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_gallerylog_visitor` (`visitor_id`),
  KEY `fk_gallerylog_gallery` (`gallery_id`),
  CONSTRAINT `fk_gallerylog_visitor_constraint` FOREIGN KEY (`visitor_id`) REFERENCES `visitors` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_gallerylog_gallery_constraint` FOREIGN KEY (`gallery_id`) REFERENCES `galleries` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `log_page_views` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `gallery_id` int(11) NOT NULL,
  `visitor_id` int(11) NOT NULL,
  `url` varchar(2048) NOT NULL,
  `referer` varchar(2048) DEFAULT NULL,
  `viewed_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_views_visitor` (`visitor_id`),
  KEY `fk_views_gallery` (`gallery_id`),
  KEY `idx_viewed_at` (`viewed_at`),
  KEY `idx_visitor_time` (`visitor_id`,`viewed_at`),
  CONSTRAINT `fk_views_visitor_constraint` FOREIGN KEY (`visitor_id`) REFERENCES `visitors` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_views_gallery_constraint` FOREIGN KEY (`gallery_id`) REFERENCES `galleries` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `log_performance` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `gallery_id` int(11) NOT NULL,
  `visitor_id` int(11) NOT NULL,
  `ttfb_ms` int(11) DEFAULT 0,
  `dom_load_ms` int(11) DEFAULT 0,
  `full_load_ms` int(11) DEFAULT 0,
  `resource_count` int(11) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_perf_visitor` (`visitor_id`),
  KEY `fk_perf_gallery` (`gallery_id`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_load_time` (`full_load_ms`),
  CONSTRAINT `fk_perf_visitor_constraint` FOREIGN KEY (`visitor_id`) REFERENCES `visitors` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_perf_gallery_constraint` FOREIGN KEY (`gallery_id`) REFERENCES `galleries` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `log_system_events` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `gallery_id` int(11) NOT NULL,
  `visitor_id` int(11) NOT NULL,
  `event_type` enum('tab_hidden','tab_visible','resize') NOT NULL,
  `duration_ms` int(11) DEFAULT NULL,
  `new_width` int(5) DEFAULT NULL,
  `new_height` int(5) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_sys_visitor` (`visitor_id`),
  KEY `fk_sys_gallery` (`gallery_id`),
  CONSTRAINT `fk_sys_visitor_constraint` FOREIGN KEY (`visitor_id`) REFERENCES `visitors` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sys_gallery_constraint` FOREIGN KEY (`gallery_id`) REFERENCES `galleries` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `log_user_actions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `gallery_id` int(11) NOT NULL,
  `visitor_id` int(11) NOT NULL,
  `action_type` varchar(50) NOT NULL,
  `target_selector` varchar(255) DEFAULT NULL,
  `target_text` varchar(255) DEFAULT NULL,
  `mouse_x` int(5) DEFAULT NULL,
  `mouse_y` int(5) DEFAULT NULL,
  `page_section` varchar(50) DEFAULT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL CHECK (json_valid(`metadata`)),
  `created_at` datetime(3) DEFAULT current_timestamp(3),
  PRIMARY KEY (`id`),
  KEY `fk_actions_visitor` (`visitor_id`),
  KEY `fk_actions_gallery` (`gallery_id`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_action_time` (`action_type`,`created_at`),
  CONSTRAINT `fk_actions_visitor_constraint` FOREIGN KEY (`visitor_id`) REFERENCES `visitors` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_actions_gallery_constraint` FOREIGN KEY (`gallery_id`) REFERENCES `galleries` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
