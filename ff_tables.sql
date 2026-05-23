CREATE TABLE IF NOT EXISTS `ff_faces` (
  `face_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `gallery_id` int(11) NOT NULL,
  `video_path` varchar(255) DEFAULT NULL,
  `media_type` varchar(20) DEFAULT 'video',
  `timestamp_sec` float DEFAULT NULL,
  `bbox` varchar(50) DEFAULT NULL,
  `cluster_id` int(11) DEFAULT -1,
  `face_emb_idx` int(11) DEFAULT NULL,
  `face_thumb` longtext DEFAULT NULL,
  `age` int(11) DEFAULT NULL,
  `gender` varchar(10) DEFAULT NULL,
  `det_score` float DEFAULT NULL,
  `quality_score` float DEFAULT NULL,
  `pitch` float DEFAULT NULL,
  `yaw` float DEFAULT NULL,
  `roll` float DEFAULT NULL,
  `kps` varchar(255) DEFAULT NULL,
  `emb_antelope` blob DEFAULT NULL,
  `emb_adaface` blob DEFAULT NULL,
  `emb_vit` blob DEFAULT NULL,
  PRIMARY KEY (`face_id`),
  KEY `idx_gallery` (`gallery_id`),
  KEY `idx_cluster` (`cluster_id`),
  KEY `idx_video` (`video_path`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ff_jobs` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `gallery_id` int(11) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `status` varchar(20) DEFAULT 'pending',
  `retry_count` int(11) DEFAULT 0,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_filepath_gallery` (`file_path`, `gallery_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ff_persons` (
  `cluster_id` int(11) NOT NULL,
  `gallery_id` int(11) NOT NULL,
  `name` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`cluster_id`, `gallery_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `ff_faces`
  ADD CONSTRAINT `fk_faces_gallery` FOREIGN KEY (`gallery_id`) REFERENCES `galleries` (`id`) ON DELETE CASCADE;
  
ALTER TABLE `ff_jobs`
  ADD CONSTRAINT `fk_jobs_gallery` FOREIGN KEY (`gallery_id`) REFERENCES `galleries` (`id`) ON DELETE CASCADE;

ALTER TABLE `ff_persons`
  ADD CONSTRAINT `fk_persons_gallery` FOREIGN KEY (`gallery_id`) REFERENCES `galleries` (`id`) ON DELETE CASCADE;
