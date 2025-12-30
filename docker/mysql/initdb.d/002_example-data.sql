-- =========================================================
-- Freezer Inventory – Example / Seed Data (NEW MODEL)
-- =========================================================

SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;
SET sql_safe_updates = 0;

-- -----------------------------
-- 1) Container Type (Demo) + Container (Demo)
-- -----------------------------
INSERT INTO container_types (shape, volume_ml, height_mm, width_mm, length_mm, material, note)
VALUES ('RECT', 1000, 70, 120, 180, 'PLASTIC', 'Demo: 1.0L rechteckig')
ON DUPLICATE KEY UPDATE
  height_mm = VALUES(height_mm),
  width_mm  = VALUES(width_mm),
  length_mm = VALUES(length_mm),
  material  = VALUES(material),
  note      = VALUES(note);

INSERT INTO containers (container_code, container_type_id, is_active, in_use, note)
SELECT
  'BOX-01' AS container_code,
  ct.id    AS container_type_id,
  1        AS is_active,
  0        AS in_use,
  'Demo-Box' AS note
FROM container_types ct
WHERE ct.shape='RECT' AND ct.volume_ml=1000
ON DUPLICATE KEY UPDATE
  container_type_id = VALUES(container_type_id),
  is_active         = VALUES(is_active),
  in_use            = VALUES(in_use),
  note              = VALUES(note);

-- -----------------------------
-- 2) Demo Rezepte (mit kcal_per_portion!)
-- -----------------------------
INSERT INTO recipes (name, recipe_type, kcal_per_portion, default_best_before_days, description, instructions)
VALUES
  ('Rouladen', 'PROTEIN', 700, 60, 'Seed-Daten für UI', 'Demo'),
  ('Apfelrotkohl', 'SIDE', 200, 60, 'Seed-Daten für UI', 'Demo'),
  ('Rouladensoße', 'SAUCE', 120, 90, 'Seed-Daten für UI', 'Demo')
ON DUPLICATE KEY UPDATE
  recipe_type = VALUES(recipe_type),
  kcal_per_portion = VALUES(kcal_per_portion),
  default_best_before_days = VALUES(default_best_before_days),
  description = VALUES(description),
  instructions = VALUES(instructions);

-- -----------------------------
-- 3) Demo Set + Komponenten (NEUES MODEL)
-- -----------------------------
INSERT INTO sets (name, note)
VALUES ('Rinderrouladen mit Apfelrotkohl und Kartoffeln', 'Demo-Set für Wizard / Packen')
ON DUPLICATE KEY UPDATE
  note = VALUES(note);

-- Komponenten: Protein=Rouladen, Side=Apfelrotkohl, Side=Kartoffeln (FREE)
-- kcal_total als Snapshot (bei Rezepten übernehmen wir kcal_per_portion)
INSERT INTO set_components (set_id, component_type, source_type, recipe_id, free_text, amount_text, kcal_total, sort_order)
SELECT
  s.id,
  'PROTEIN',
  'RECIPE',
  r.id,
  NULL,
  '1',
  r.kcal_per_portion,
  0
FROM sets s
JOIN recipes r ON r.name='Rouladen'
WHERE s.name='Rinderrouladen mit Apfelrotkohl und Kartoffeln'
ON DUPLICATE KEY UPDATE
  amount_text = VALUES(amount_text),
  kcal_total  = VALUES(kcal_total),
  sort_order  = VALUES(sort_order);

INSERT INTO set_components (set_id, component_type, source_type, recipe_id, free_text, amount_text, kcal_total, sort_order)
SELECT
  s.id,
  'SIDE',
  'RECIPE',
  r.id,
  NULL,
  '1',
  r.kcal_per_portion,
  1
FROM sets s
JOIN recipes r ON r.name='Apfelrotkohl'
WHERE s.name='Rinderrouladen mit Apfelrotkohl und Kartoffeln'
ON DUPLICATE KEY UPDATE
  amount_text = VALUES(amount_text),
  kcal_total  = VALUES(kcal_total),
  sort_order  = VALUES(sort_order);

INSERT INTO set_components (set_id, component_type, source_type, recipe_id, free_text, amount_text, kcal_total, sort_order)
SELECT
  s.id,
  'SIDE',
  'FREE',
  NULL,
  'Kartoffeln',
  '300 g',
  150,
  2
FROM sets s
WHERE s.name='Rinderrouladen mit Apfelrotkohl und Kartoffeln'
ON DUPLICATE KEY UPDATE
  amount_text = VALUES(amount_text),
  kcal_total  = VALUES(kcal_total),
  sort_order  = VALUES(sort_order);

-- -----------------------------
-- 4) Optional: bereits "gepackte" Box (eine echte BOX) + Inventory Booking
--    -> damit du sofort im Inventar siehst: P001 in BOX-01 und Container in_use=1
-- -----------------------------
-- Box P001 (PROTEIN) in BOX-01, portion_factor=1, kcal_total = 700
INSERT INTO set_boxes (set_id, container_id, box_code, box_type, portion_factor, portion_text, kcal_total)
SELECT
  s.id,
  c.id,
  'P001',
  'PROTEIN',
  1.00,
  '1 Portion',
  700
FROM sets s
JOIN containers c ON c.container_code='BOX-01'
WHERE s.name='Rinderrouladen mit Apfelrotkohl und Kartoffeln'
ON DUPLICATE KEY UPDATE
  portion_factor = VALUES(portion_factor),
  portion_text   = VALUES(portion_text),
  kcal_total     = VALUES(kcal_total);

-- Zuordnung Box -> Komponente (PROTEIN/Rouladen)
INSERT INTO set_box_components (set_box_id, set_component_id)
SELECT
  sb.id,
  sc.id
FROM sets s
JOIN set_boxes sb ON sb.set_id = s.id AND sb.box_code='P001'
JOIN set_components sc ON sc.set_id = s.id AND sc.component_type='PROTEIN' AND sc.source_type='RECIPE'
WHERE s.name='Rinderrouladen mit Apfelrotkohl und Kartoffeln'
ON DUPLICATE KEY UPDATE
  set_component_id = VALUES(set_component_id);

-- Inventory Item (P001) buchen + Container belegen
INSERT INTO inventory_items
  (id_code, item_type, name, recipe_id, is_veggie, is_vegan,
   portion_text, kcal, frozen_at, best_before_at,
   prep_notes, thaw_method, reheat_minutes,
   status, storage_type, container_id)
SELECT
  'P001',
  'P',
  'Roulade (Demo)',
  r.id,
  0,
  0,
  '1 Portion',
  700,
  CURDATE(),
  NULL,
  'Demo',
  'PAN',
  8,
  'IN_FREEZER',
  'BOX',
  c.id
FROM recipes r
JOIN containers c ON c.container_code='BOX-01'
WHERE r.name='Rouladen'
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  kcal = VALUES(kcal),
  container_id = VALUES(container_id),
  storage_type = VALUES(storage_type),
  status = VALUES(status);

UPDATE containers
SET in_use = 1
WHERE container_code='BOX-01';

-- -----------------------------
-- 5) Zusätzlich: ein komplettes Meal als Einzelelement (M001) zum Testen der "Meals"-Ansicht
-- -----------------------------
INSERT INTO inventory_items
  (id_code, item_type, name, recipe_id, is_veggie, is_vegan,
   portion_text, kcal, frozen_at, best_before_at,
   prep_notes, thaw_method, reheat_minutes,
   status, storage_type, container_id)
SELECT
  'M001',
  'M',
  'Rouladen mit Rotkohl (Demo)',
  NULL,
  0,
  0,
  '1 Portion',
  900,
  CURDATE(),
  NULL,
  'Demo',
  'MICROWAVE',
  8,
  'IN_FREEZER',
  'FREE',
  NULL
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  kcal = VALUES(kcal),
  status = VALUES(status);

-- -----------------------------
-- 6) Optional: Rating für P001
-- -----------------------------
INSERT INTO ratings (inventory_item_id, ease_stars, fresh_stars, thawed_stars, notes)
SELECT
  ii.id, 3, 4, 4, 'Demo-Rating'
FROM inventory_items ii
WHERE ii.id_code='P001'
ON DUPLICATE KEY UPDATE
  ease_stars   = VALUES(ease_stars),
  fresh_stars  = VALUES(fresh_stars),
  thawed_stars = VALUES(thawed_stars),
  notes        = VALUES(notes);
