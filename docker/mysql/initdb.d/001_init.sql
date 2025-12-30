-- =========================================================
-- Freezer Inventory – Initial Schema
-- MySQL 8+
-- Wird automatisch beim ersten Container-Start ausgeführt
-- =========================================================

SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;
SET sql_safe_updates = 0;
SET FOREIGN_KEY_CHECKS = 0;

-- =========================================================
-- 1) Rezepte
-- =========================================================
CREATE TABLE IF NOT EXISTS recipes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(200) NOT NULL,
  recipe_type ENUM('MEAL','PROTEIN','SAUCE','SIDE','BASE','BREAKFAST','DESSERT','MISC') NOT NULL DEFAULT 'MEAL',
  ingredients_text MEDIUMTEXT NULL,
  prep_text MEDIUMTEXT NULL,
  reheat_text MEDIUMTEXT NULL,
  yield_portions SMALLINT UNSIGNED NULL,
  kcal_per_portion SMALLINT UNSIGNED NULL,
  is_veggie TINYINT(1) NOT NULL DEFAULT 0,
  is_vegan  TINYINT(1) NOT NULL DEFAULT 0,
  tags_text VARCHAR(200) NULL,
  description TEXT NULL,
  instructions MEDIUMTEXT NULL,
  default_best_before_days SMALLINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_recipes_name (name),
  KEY idx_recipes_type (recipe_type),
  KEY idx_recipes_flags (is_veggie, is_vegan)
) ENGINE=InnoDB;

-- =========================================================
-- 2) Boxen-Typen + Boxen
-- =========================================================
CREATE TABLE IF NOT EXISTS container_types (
  id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  shape ENUM('RECT','ROUND','OVAL') NOT NULL,
  volume_ml SMALLINT UNSIGNED NOT NULL,
  height_mm SMALLINT UNSIGNED NULL,
  width_mm SMALLINT UNSIGNED NULL,
  length_mm SMALLINT UNSIGNED NULL,
  material ENUM('PLASTIC','GLASS') NULL,
  note VARCHAR(100) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_container_types (shape, volume_ml)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS containers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  container_code VARCHAR(32) NOT NULL,
  container_type_id SMALLINT UNSIGNED NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  in_use TINYINT(1) NOT NULL DEFAULT 0,
  note VARCHAR(200) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_containers_code (container_code),
  KEY idx_containers_type (container_type_id),
  CONSTRAINT fk_containers_type
    FOREIGN KEY (container_type_id)
    REFERENCES container_types(id)
    ON DELETE SET NULL
) ENGINE=InnoDB;

-- =========================================================
-- 3) Typ-Defaults
-- =========================================================
CREATE TABLE IF NOT EXISTS item_type_defaults (
  item_type ENUM('M','P','S','B','Z','F','D','X') NOT NULL,
  best_before_days SMALLINT UNSIGNED NOT NULL,
  thaw_method ENUM('FRIDGE','MICROWAVE','PAN','OVEN','NONE') NOT NULL DEFAULT 'NONE',
  reheat_minutes SMALLINT UNSIGNED NULL,
  note VARCHAR(200) NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (item_type)
) ENGINE=InnoDB;

INSERT INTO item_type_defaults
  (item_type, best_before_days, thaw_method, reheat_minutes, note)
VALUES
  ('M',  90, 'MICROWAVE', 8,  'Komplettes Meal'),
  ('P',  60, 'PAN',       8,  'Protein'),
  ('S',  90, 'MICROWAVE', 3,  'Soßen'),
  ('B',  60, 'OVEN',     10,  'Beilagen'),
  ('Z', 120, 'MICROWAVE', 3,  'Zutat/Base'),
  ('F',  60, 'MICROWAVE', 3,  'Frühstück'),
  ('D',  90, 'FRIDGE',   NULL,'Dessert'),
  ('X',  45, 'NONE',     NULL,'Misc')
ON DUPLICATE KEY UPDATE
  best_before_days = VALUES(best_before_days),
  thaw_method      = VALUES(thaw_method),
  reheat_minutes   = VALUES(reheat_minutes),
  note             = VALUES(note);

-- =========================================================
-- 4) Inventar-Items
-- =========================================================
CREATE TABLE IF NOT EXISTS inventory_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

  id_code VARCHAR(16) NOT NULL,
  item_type ENUM('M','P','S','B','Z','F','D','X') NOT NULL,
  name VARCHAR(200) NOT NULL,

  recipe_id BIGINT UNSIGNED NULL,

  is_veggie TINYINT(1) NOT NULL DEFAULT 0,
  is_vegan  TINYINT(1) NOT NULL DEFAULT 0,

  portion_text VARCHAR(80) NULL,
  weight_g SMALLINT UNSIGNED NULL,
  volume_ml SMALLINT UNSIGNED NULL,
  kcal SMALLINT UNSIGNED NULL,

  frozen_at DATE NOT NULL,
  best_before_at DATE NULL,
  storage_location VARCHAR(32) NULL,

  prep_notes VARCHAR(500) NULL,
  thaw_method ENUM('FRIDGE','MICROWAVE','PAN','OVEN','NONE') NOT NULL DEFAULT 'NONE',
  reheat_minutes SMALLINT UNSIGNED NULL,

  status ENUM('IN_FREEZER','TAKEN_OUT','EATEN','DISCARDED') NOT NULL DEFAULT 'IN_FREEZER',
  status_changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  storage_type ENUM('BOX','FREE','FREEZER_BAG','VACUUM_BAG') NOT NULL DEFAULT 'BOX',
  container_id BIGINT UNSIGNED NULL,

  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY uq_inventory_id_code (id_code),
  KEY idx_inventory_status_type_date (status, item_type, frozen_at),
  KEY idx_inventory_recipe (recipe_id),
  KEY idx_inventory_best_before (best_before_at),
  KEY idx_inventory_container (container_id),

  CONSTRAINT fk_inventory_recipe
    FOREIGN KEY (recipe_id) REFERENCES recipes(id)
    ON DELETE SET NULL,

  CONSTRAINT fk_inventory_container
    FOREIGN KEY (container_id) REFERENCES containers(id)
    ON DELETE SET NULL
) ENGINE=InnoDB;

-- =========================================================
-- 5) Ratings
-- =========================================================
CREATE TABLE IF NOT EXISTS ratings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  inventory_item_id BIGINT UNSIGNED NOT NULL,
  ease_stars TINYINT UNSIGNED NULL CHECK (ease_stars BETWEEN 1 AND 5),
  fresh_stars TINYINT UNSIGNED NULL CHECK (fresh_stars BETWEEN 1 AND 5),
  thawed_stars TINYINT UNSIGNED NULL CHECK (thawed_stars BETWEEN 1 AND 5),
  notes VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rating_item (inventory_item_id),
  CONSTRAINT fk_ratings_item
    FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id)
    ON DELETE CASCADE
) ENGINE=InnoDB;

-- =========================================================
-- 6) Events
-- =========================================================
CREATE TABLE IF NOT EXISTS inventory_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  inventory_item_id BIGINT UNSIGNED NOT NULL,
  event_type ENUM('CREATED','UPDATED','STATUS_CHANGED','MOVED_CONTAINER','DISCARDED') NOT NULL,
  from_status ENUM('IN_FREEZER','TAKEN_OUT','EATEN','DISCARDED') NULL,
  to_status   ENUM('IN_FREEZER','TAKEN_OUT','EATEN','DISCARDED') NULL,
  note VARCHAR(300) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_events_item_date (inventory_item_id, created_at),
  CONSTRAINT fk_events_item
    FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id)
    ON DELETE CASCADE
) ENGINE=InnoDB;

-- =========================================================
-- 7) ID-Counter
-- =========================================================
CREATE TABLE IF NOT EXISTS id_counters (
  item_type ENUM('M','P','S','B','Z','F','D','X') NOT NULL,
  next_number INT UNSIGNED NOT NULL DEFAULT 1,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (item_type)
) ENGINE=InnoDB;

INSERT INTO id_counters (item_type, next_number) VALUES
  ('M',1),('P',1),('S',1),('B',1),('Z',1),('F',1),('D',1),('X',1)
ON DUPLICATE KEY UPDATE next_number = next_number;

-- =========================================================
-- 8) Sets + Boxen (Meal-Prep-Builder)
-- =========================================================
CREATE TABLE IF NOT EXISTS sets (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(200) NOT NULL,
  note TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS set_components (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  set_id BIGINT UNSIGNED NOT NULL,
  component_type VARCHAR(20) NOT NULL,
  source_type ENUM('RECIPE','FREE') NOT NULL,
  recipe_id BIGINT UNSIGNED NULL,
  free_text VARCHAR(255) NULL,
  amount_text VARCHAR(100) NULL,
  kcal_total INT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_set_components_set FOREIGN KEY (set_id) REFERENCES sets(id) ON DELETE CASCADE,
  CONSTRAINT fk_set_components_recipe FOREIGN KEY (recipe_id) REFERENCES recipes(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS set_boxes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  set_id BIGINT UNSIGNED NOT NULL,
  container_id BIGINT UNSIGNED NULL,
  box_code VARCHAR(16) NOT NULL,
  box_type VARCHAR(20) NOT NULL,
  portion_factor DECIMAL(6,2) NULL,
  portion_text VARCHAR(50) NULL,
  kcal_total INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_set_boxes_set FOREIGN KEY (set_id) REFERENCES sets(id) ON DELETE CASCADE,
  CONSTRAINT fk_set_boxes_container FOREIGN KEY (container_id) REFERENCES containers(id) ON DELETE RESTRICT,
  CONSTRAINT uq_set_box_code UNIQUE (set_id, box_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS set_box_components (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  set_box_id BIGINT UNSIGNED NOT NULL,
  set_component_id BIGINT UNSIGNED NOT NULL,
  CONSTRAINT fk_sbc_box FOREIGN KEY (set_box_id) REFERENCES set_boxes(id) ON DELETE CASCADE,
  CONSTRAINT fk_sbc_component FOREIGN KEY (set_component_id) REFERENCES set_components(id) ON DELETE RESTRICT,
  CONSTRAINT uq_sbc UNIQUE (set_box_id, set_component_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- ===================== DONE =====================
