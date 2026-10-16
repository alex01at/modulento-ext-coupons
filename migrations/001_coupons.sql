-- Coupon codes an operator hands out, and the record of each successful
-- redemption.
--
-- app_id names the one "app"-type extension (freelancer/shop/auction/card)
-- a coupon is scoped to, or NULL for a general one usable under any app -
-- the exact same convention the FAQ extension's own extension_id column
-- and the Abos addon's x_subscription_plan.app_id already use. No foreign
-- key on purpose: a coupon outlives whatever happens to the app it was
-- written for, and simply stops being reachable (not broken) once that app
-- is no longer the one installed.
--
-- currency is required even for a percentage coupon: it is compared
-- against the offer's own currency at checkout, and a mismatch refuses the
-- code rather than silently letting a coupon meant for one currency apply
-- to an order in another.
--
-- used_count is taken with an atomic "UPDATE ... WHERE used_count <
-- max_uses" (see Coupons::reserve()), the same safeguard
-- modulento-ext-shop's own x_shop_discount.used_count relies on, so two
-- buyers racing for the last use cannot both win it.
CREATE TABLE x_coupon (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(32) NOT NULL,
    type ENUM('percent', 'fixed') NOT NULL,
    value INT UNSIGNED NOT NULL,
    currency CHAR(3) NOT NULL,
    app_id VARCHAR(32) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    starts_at DATETIME NULL,
    expires_at DATETIME NULL,
    max_uses INT UNSIGNED NULL,
    used_count INT UNSIGNED NOT NULL DEFAULT 0,
    min_subtotal_cents INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_x_coupon_code (code),
    KEY idx_x_coupon_app (app_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per order a coupon was actually applied to. Keyed by order_id
-- (not by (coupon_id, account_id, created_at)): the core fires
-- Modulento\Core\Event\OrderStateChanged right after Order::create(), with
-- the new order's id, its old state (null for a fresh order) and the
-- transition ("place") - everything needed to write this row properly
-- keyed, picked up through Registrar::listen() instead of improvising a key
-- from only what orderDiscount() itself sees (which never learns the order
-- id - see src/Extension.php for how the two hooks hand this off to each
-- other within the one request that places the order).
CREATE TABLE x_coupon_redemption (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    coupon_id INT UNSIGNED NOT NULL,
    order_id INT UNSIGNED NOT NULL,
    account_id INT UNSIGNED NOT NULL,
    amount_cents INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_x_coupon_redemption_order (order_id),
    KEY idx_x_coupon_redemption_coupon (coupon_id),
    CONSTRAINT fk_x_coupon_redemption_coupon FOREIGN KEY (coupon_id) REFERENCES x_coupon (id) ON DELETE CASCADE,
    CONSTRAINT fk_x_coupon_redemption_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE,
    CONSTRAINT fk_x_coupon_redemption_account FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
