-- BayanAlert database. Import in phpMyAdmin or: mysql -u root -p < database.sql
CREATE DATABASE IF NOT EXISTS bayanalert CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE bayanalert;

CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(160) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  mobile VARCHAR(30) NOT NULL DEFAULT '',
  purok VARCHAR(20) NOT NULL DEFAULT 'Purok 1',
  postal VARCHAR(10) NOT NULL DEFAULT '4119',
  role ENUM('resident','admin') NOT NULL DEFAULT 'resident',
  prefs JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE reports (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ref VARCHAR(20) NULL UNIQUE,
  user_id INT NULL,
  title VARCHAR(200) NOT NULL,
  category VARCHAR(60) NOT NULL,
  purok VARCHAR(20) NOT NULL,
  description TEXT NOT NULL,
  status ENUM('pending','progress','urgent','resolved') NOT NULL DEFAULT 'pending',
  photos TINYINT NOT NULL DEFAULT 0,
  is_new TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX (status), INDEX (user_id)
) ENGINE=InnoDB;

CREATE TABLE comments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  report_id INT NOT NULL,
  author VARCHAR(120) NOT NULL,
  message TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (report_id) REFERENCES reports(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Demo accounts: admin@bayanalert.ph / admin123   and   juan.delacruz@email.com / password123
INSERT INTO users (name,email,password_hash,purok,role) VALUES
 ('Barangay Admin','admin@bayanalert.ph','$2y$12$wpxgpvh.zAWko1J2QyAiseAQQBUqYd38ZlXmNwlUuHDX37u75sWrS','Purok 1','admin'),
 ('Juan Dela Cruz','juan.delacruz@email.com','$2y$12$VILBz7fXdqnpcXCZ2sLmpuMcH9ta4rUuJDDzC.MTBoU720aekBw8G','Purok 3','resident');

INSERT INTO reports (ref,user_id,title,category,purok,description,status,photos,is_new,created_at) VALUES
 ('CMP-2026-0142',2,'Overflowing drainage along Purok 3','Drainage','Purok 3','The main drainage beside the Purok 3 road has been clogged with garbage for several days. Wastewater now overflows onto the street whenever it rains.','progress',3,0,'2026-10-12 08:14:00'),
 ('CMP-2026-0139',2,'Uncollected garbage near Talon Covered Court','Sanitation','Purok 1','Garbage has not been collected for a week.','pending',0,0,'2026-10-11 09:30:00'),
 ('DIS-2026-0031',2,'Flooded road after heavy rain','Flood','Purok 4','The road is impassable after heavy rain.','urgent',0,0,'2026-10-10 14:05:00'),
 ('CMP-2026-0127',2,'Broken streetlight at Purok 5','Street Lighting','Purok 5','The streetlight has been out for two weeks.','resolved',0,0,'2026-10-08 18:20:00');
