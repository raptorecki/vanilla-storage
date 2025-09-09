/*M!999999\- enable the sandbox mode */ 
-- MariaDB dump 10.19  Distrib 10.11.11-MariaDB, for debian-linux-gnu (aarch64)
--
-- Host: localhost    Database: storage
-- ------------------------------------------------------
-- Server version	10.11.11-MariaDB-0+deb12u1-log

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `st_drives`
--

DROP TABLE IF EXISTS `st_drives`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `st_drives` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `an_serial` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `legacy_name` varchar(255) NOT NULL,
  `vendor` varchar(255) NOT NULL,
  `model` varchar(255) NOT NULL,
  `model_number` varchar(255) NOT NULL,
  `size` int(11) NOT NULL,
  `serial` varchar(255) NOT NULL,
  `firmware` varchar(255) NOT NULL,
  `smart` varchar(255) NOT NULL,
  `summary` varchar(255) NOT NULL,
  `pair_id` int(11) DEFAULT NULL COMMENT 'ID of the paired drive',
  `date_added` datetime NOT NULL,
  `date_updated` datetime NOT NULL,
  `dead` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 if drive is damaged/inoperable',
  `online` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 if drive is currently connected to a system',
  `offsite` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 if drive is stored at an offsite location',
  `encrypted` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 if the drive is encrypted',
  `empty` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 if the drive is empty and ready for use',
  `filesystem` varchar(50) DEFAULT NULL COMMENT 'Filesystem of the drive (e.g., ext4, ntfs, apfs)',
  PRIMARY KEY (`id`),
  KEY `pair_id_idx` (`pair_id`)
) ENGINE=InnoDB AUTO_INCREMENT=98 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `st_files`
--

DROP TABLE IF EXISTS `st_files`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `st_files` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `drive_id` int(11) NOT NULL,
  `partition_number` int(11) NOT NULL DEFAULT 1,
  `path` text NOT NULL,
  `path_hash` varchar(64) NOT NULL COMMENT 'SHA256 hash of the path for a unique key',
  `filename` varchar(255) NOT NULL,
  `size` bigint(20) unsigned NOT NULL COMMENT 'File size in bytes',
  `md5_hash` char(32) DEFAULT NULL COMMENT 'MD5 hash of the file content',
  `media_format` varchar(255) DEFAULT NULL COMMENT 'e.g., mov,mp4,mkv',
  `media_codec` varchar(255) DEFAULT NULL COMMENT 'e.g., h264, hevc',
  `media_resolution` varchar(255) DEFAULT NULL COMMENT 'e.g., 1920x1080',
  `ctime` datetime NOT NULL COMMENT 'File creation/inode change time',
  `mtime` datetime NOT NULL COMMENT 'File modification time',
  `file_category` varchar(50) DEFAULT NULL COMMENT 'e.g., Video, Audio, Image, Document',
  `is_directory` tinyint(1) NOT NULL DEFAULT 0,
  `date_added` datetime NOT NULL,
  `date_deleted` datetime DEFAULT NULL COMMENT 'Timestamp when a file was found to be deleted',
  `media_duration` float DEFAULT NULL COMMENT 'Duration in seconds',
  `exif_date_taken` datetime DEFAULT NULL COMMENT 'Date photo was taken from EXIF data',
  `exif_camera_model` varchar(255) DEFAULT NULL COMMENT 'Camera model from EXIF data',
  `product_name` varchar(255) DEFAULT NULL,
  `product_version` varchar(255) DEFAULT NULL,
  `exiftool_json` mediumtext DEFAULT NULL,
  `thumbnail_path` varchar(255) DEFAULT NULL,
  `last_scan_id` int(11) DEFAULT NULL,
  `filetype` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `drive_path_unique` (`drive_id`,`path_hash`),
  UNIQUE KEY `drive_id_partition_path_hash_unique` (`drive_id`,`partition_number`,`path_hash`),
  KEY `filename_idx` (`filename`),
  KEY `drive_id` (`drive_id`),
  KEY `md5_hash_idx` (`md5_hash`),
  KEY `fk_last_scan` (`last_scan_id`),
  CONSTRAINT `fk_last_scan` FOREIGN KEY (`last_scan_id`) REFERENCES `st_scans` (`scan_id`) ON DELETE SET NULL,
  CONSTRAINT `st_files_drive_id_fk` FOREIGN KEY (`drive_id`) REFERENCES `st_drives` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1730471 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `st_recovery`
--

DROP TABLE IF EXISTS `st_recovery`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `st_recovery` (
  `recovery_id` int(11) NOT NULL AUTO_INCREMENT,
  `drive_id` int(11) NOT NULL,
  `scan_id` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `tool` varchar(50) NOT NULL,
  `command` text NOT NULL,
  `output_type` varchar(20) NOT NULL,
  `output_data` longblob DEFAULT NULL,
  `output_text` longtext DEFAULT NULL,
  `description` text DEFAULT NULL,
  PRIMARY KEY (`recovery_id`),
  KEY `idx_drive_id` (`drive_id`),
  KEY `idx_scan_id` (`scan_id`),
  CONSTRAINT `st_recovery_ibfk_1` FOREIGN KEY (`drive_id`) REFERENCES `st_drives` (`id`) ON DELETE CASCADE,
  CONSTRAINT `st_recovery_ibfk_2` FOREIGN KEY (`scan_id`) REFERENCES `st_scans` (`scan_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `st_scans`
--

DROP TABLE IF EXISTS `st_scans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `st_scans` (
  `scan_id` int(11) NOT NULL AUTO_INCREMENT,
  `drive_id` int(11) NOT NULL,
  `status` enum('running','interrupted','completed','failed') NOT NULL DEFAULT 'running',
  `last_scanned_path` text DEFAULT NULL,
  `scan_date` datetime NOT NULL,
  `total_items_scanned` int(11) DEFAULT 0,
  `new_files_added` int(11) DEFAULT 0,
  `existing_files_updated` int(11) DEFAULT 0,
  `files_marked_deleted` int(11) DEFAULT 0,
  `files_skipped` int(11) DEFAULT 0,
  `scan_duration` int(11) DEFAULT 0,
  `thumbnails_created` int(11) DEFAULT 0,
  `thumbnail_creations_failed` int(11) DEFAULT 0,
  `thumbnails_queued` int(11) DEFAULT 0,
  `thumbnail_queueing_failed` int(11) DEFAULT 0,
  `estimated_total_items` int(11) DEFAULT 0,
  `estimated_total_size` bigint(20) DEFAULT 0,
  `bytes_processed` bigint(20) DEFAULT 0,
  `eta_calculated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`scan_id`),
  KEY `drive_id` (`drive_id`),
  CONSTRAINT `st_scans_ibfk_1` FOREIGN KEY (`drive_id`) REFERENCES `st_drives` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=59 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `st_smart`
--

DROP TABLE IF EXISTS `st_smart`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `st_smart` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `drive_id` int(11) NOT NULL,
  `output` text DEFAULT NULL,
  `scan_date` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `drive_id` (`drive_id`),
  CONSTRAINT `st_smart_ibfk_1` FOREIGN KEY (`drive_id`) REFERENCES `st_drives` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=136 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `st_version`
--

DROP TABLE IF EXISTS `st_version`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `st_version` (
  `version` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2025-09-10  1:43:55
