-- Game cover images uploaded by the admin. Files live in <site>/uploads/games/ (not in git);
-- the column is added automatically, run this only if the DB user lacks ALTER rights.
ALTER TABLE product_prices ADD COLUMN image_file VARCHAR(80) NULL;
