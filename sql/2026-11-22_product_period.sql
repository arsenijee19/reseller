-- Subscription period per product (PS Plus 1/3/12 months), set in admin → Proizvodi → Period.
-- Added automatically; run only if the DB user lacks ALTER rights.
ALTER TABLE product_prices ADD COLUMN subscription_months TINYINT UNSIGNED NULL;
