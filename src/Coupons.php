<?php

declare(strict_types=1);

namespace Modulento\Coupons;

use Closure;
use Modulento\Core\App;
use Modulento\Core\Support\Clock;
use Modulento\Core\Support\Money;
use PDO;
use PDOException;

/**
 * Coupon codes an operator hands out, scoped to one "app"-type extension
 * (freelancer, shop, auction, card - only one is ever active at a time) or
 * left general, same convention as the FAQ extension's own extension_id
 * column and the Abos addon's x_subscription_plan.app_id.
 *
 * The currently active app is resolved lazily, through the closure given to
 * the constructor, the moment a scoped check (isVisible()/problem()) is
 * actually made - never at registration time, since other extensions may
 * still be loading and $app->extensions->loaded() would be incomplete.
 * find()/findByCode()/all() stay unscoped on purpose: the administration
 * manages every coupon, including one written for an app that is not
 * active right now (or not installed any more).
 *
 * Note on scope: a coupon scoped to "shop" is only ever reachable through
 * Registrar::orderDiscount(), the generic order-placement path (today:
 * freelancer only - shop has its own separate, already-built discount
 * system in modulento-ext-shop and does not go through this addon at all).
 * Scoping a coupon to "shop" here is for forward-compatibility and
 * consistency, not because it does anything useful today - see this
 * repository's README for the full explanation.
 */
final class Coupons
{
    /** @param Closure(): ?string $activeAppId */
    public function __construct(private PDO $db, private Closure $activeAppId)
    {
    }

    /**
     * The one "app"-type extension currently loaded, or null (none, or -
     * in principle unreachable since the core enforces exactly one -
     * several). Only ever meaningful once every extension has registered,
     * so this must not be called from Extension::register() itself.
     */
    public static function resolveActiveAppId(App $app): ?string
    {
        foreach ($app->extensions->loaded() as $id => $manifest) {
            if ($manifest->type === 'app') {
                return $id;
            }
        }

        return null;
    }

    /** Whether a coupon (as find()/all() return it) applies under the app that is active right now. */
    public function isVisible(array $coupon): bool
    {
        return $coupon['app_id'] === null || $coupon['app_id'] === ($this->activeAppId)();
    }

    /** One coupon, any app, active or not - for the administration, and for internal lookups that check visibility themselves. */
    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM x_coupon WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row !== false ? self::cast($row) : null;
    }

    /** Case-insensitive: the code is normalised to uppercase here and on save. */
    public function findByCode(string $code): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM x_coupon WHERE code = :code');
        $stmt->execute(['code' => mb_strtoupper(trim($code))]);
        $row = $stmt->fetch();

        return $row !== false ? self::cast($row) : null;
    }

    /** Every coupon, any app, active or not - for the administration's own list. */
    public function all(): array
    {
        return array_map([self::class, 'cast'], $this->db->query('SELECT * FROM x_coupon ORDER BY id DESC')->fetchAll());
    }

    /**
     * Whether a coupon can currently be applied to an order of this
     * currency and (pre-discount) subtotal - checked, in order, against:
     * app scope, active, not yet started, expired, currency, usage limit,
     * minimum subtotal.
     *
     * @return string|null a language key of the problem, null if it applies
     */
    public function problem(array $coupon, string $currency, int $subtotal): ?string
    {
        if (!$this->isVisible($coupon)) {
            return 'coupons.error.wrong_app';
        }
        if (!$coupon['active']) {
            return 'coupons.error.inactive';
        }
        $now = Clock::now();
        if ($coupon['starts_at'] !== null && $coupon['starts_at'] > $now) {
            return 'coupons.error.not_yet_valid';
        }
        if ($coupon['expires_at'] !== null && $coupon['expires_at'] < $now) {
            return 'coupons.error.expired';
        }
        if ($coupon['currency'] !== $currency) {
            return 'coupons.error.currency';
        }
        if ($coupon['max_uses'] !== null && $coupon['used_count'] >= $coupon['max_uses']) {
            return 'coupons.error.used_up';
        }
        if ($coupon['min_subtotal_cents'] !== null && $subtotal < $coupon['min_subtotal_cents']) {
            return 'coupons.error.min_subtotal';
        }

        return null;
    }

    /** The amount taken off (minor units), never more than the subtotal itself. */
    public function discountAmount(array $coupon, int $subtotal): int
    {
        $raw = $coupon['type'] === 'percent' ? (int) round($subtotal * $coupon['value'] / 100) : $coupon['value'];

        return max(0, min($raw, $subtotal));
    }

    /**
     * Prorates a discount amount across order lines, by each line's share
     * of the subtotal, and returns the lines with their price reduced -
     * split into two rows where a line's own share does not divide evenly
     * by its quantity, so the total is exact to the cent without ever
     * needing a negative price (order_item.unit_price is unsigned). Ported
     * from modulento-ext-shop's Discounts::apply(), which solves the same
     * problem for its own, separate discount system.
     *
     * @param list<array{label: string, quantity: int, unit_price: int}> $items
     * @return list<array{label: string, quantity: int, unit_price: int}>
     */
    public function apply(array $items, int $discountAmount): array
    {
        if ($discountAmount <= 0 || $items === []) {
            return $items;
        }

        $subtotal = array_sum(array_map(fn (array $i) => $i['quantity'] * $i['unit_price'], $items));
        if ($subtotal <= 0) {
            return $items;
        }

        $result = [];
        $remaining = $discountAmount;
        $lastIndex = count($items) - 1;
        foreach ($items as $index => $item) {
            $lineTotal = $item['quantity'] * $item['unit_price'];
            // The last line absorbs whatever rounding left over, so the
            // lines sum to exactly subtotal - discountAmount.
            $reduction = $index === $lastIndex ? $remaining : min($remaining, (int) floor($lineTotal * $discountAmount / $subtotal));
            $remaining -= $reduction;
            $newTotal = $lineTotal - $reduction;

            if ($reduction === 0) {
                $result[] = $item;
            } elseif ($newTotal % $item['quantity'] === 0) {
                $result[] = ['label' => $item['label'], 'quantity' => $item['quantity'], 'unit_price' => intdiv($newTotal, $item['quantity'])];
            } else {
                // Does not divide evenly: one unit carries the remainder,
                // the rest stay at the original price.
                $fullUnits = $item['quantity'] - 1;
                $result[] = ['label' => $item['label'], 'quantity' => $fullUnits, 'unit_price' => $item['unit_price']];
                $result[] = ['label' => $item['label'], 'quantity' => 1, 'unit_price' => $newTotal - $fullUnits * $item['unit_price']];
            }
        }

        return $result;
    }

    /**
     * Takes one use, only where one is left - the guard is in the UPDATE
     * itself, so two buyers racing for the last use cannot both win it.
     * A coupon with max_uses = null always succeeds.
     */
    public function reserve(int $id): bool
    {
        $stmt = $this->db->prepare('UPDATE x_coupon SET used_count = used_count + 1 WHERE id = :id AND (max_uses IS NULL OR used_count < max_uses)');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() === 1;
    }

    /** One redemption, keyed by the order it ended up on - see Extension::register() for when this runs. */
    public function redeem(int $couponId, int $orderId, int $accountId, int $amountCents): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO x_coupon_redemption (coupon_id, order_id, account_id, amount_cents, created_at) VALUES (:coupon_id, :order_id, :account_id, :amount_cents, :now)'
        );
        $stmt->execute(['coupon_id' => $couponId, 'order_id' => $orderId, 'account_id' => $accountId, 'amount_cents' => $amountCents, 'now' => Clock::now()]);
    }

    /** @return string|null a language key of the problem, null on success */
    public function create(string $code, string $type, string $valueInput, string $currency, ?string $appId, bool $active, ?string $startsAt, ?string $expiresAt, ?string $maxUsesInput, ?string $minSubtotalInput): ?string
    {
        $values = $this->validated(null, $code, $type, $valueInput, $currency, $startsAt, $expiresAt, $maxUsesInput, $minSubtotalInput);
        if (is_string($values)) {
            return $values;
        }

        $now = Clock::now();
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO x_coupon (code, type, value, currency, app_id, active, starts_at, expires_at, max_uses, min_subtotal_cents, created_at, updated_at)
                 VALUES (:code, :type, :value, :currency, :app_id, :active, :starts_at, :expires_at, :max_uses, :min_subtotal_cents, :now, :now2)'
            );
            $stmt->execute($values + ['app_id' => $appId, 'active' => $active ? 1 : 0, 'now' => $now, 'now2' => $now]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                return 'coupons.admin.error.code_taken';
            }
            throw $e;
        }

        return null;
    }

    /** The code and every setting can change; used_count and its history (x_coupon_redemption) are never touched here. */
    public function update(int $id, string $code, string $type, string $valueInput, string $currency, ?string $appId, bool $active, ?string $startsAt, ?string $expiresAt, ?string $maxUsesInput, ?string $minSubtotalInput): ?string
    {
        $values = $this->validated($id, $code, $type, $valueInput, $currency, $startsAt, $expiresAt, $maxUsesInput, $minSubtotalInput);
        if (is_string($values)) {
            return $values;
        }

        try {
            $stmt = $this->db->prepare(
                'UPDATE x_coupon SET code = :code, type = :type, value = :value, currency = :currency, app_id = :app_id, active = :active,
                    starts_at = :starts_at, expires_at = :expires_at, max_uses = :max_uses, min_subtotal_cents = :min_subtotal_cents, updated_at = :now
                 WHERE id = :id'
            );
            $stmt->execute($values + ['app_id' => $appId, 'active' => $active ? 1 : 0, 'now' => Clock::now(), 'id' => $id]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                return 'coupons.admin.error.code_taken';
            }
            throw $e;
        }

        return null;
    }

    /** Deletes a coupon outright, redemption history and all (ON DELETE CASCADE) - there is no "in use" guard, unlike a subscription plan: past orders keep their own (already discounted) totals regardless. */
    public function delete(int $id): void
    {
        $this->db->prepare('DELETE FROM x_coupon WHERE id = :id')->execute(['id' => $id]);
    }

    /**
     * @param int|null $excludeId the coupon's own id, so a save that does
     *        not change the code is not refused as "taken" by itself
     * @return array{code: string, type: string, value: int, currency: string, starts_at: ?string, expires_at: ?string, max_uses: ?int, min_subtotal_cents: ?int}|string column values, or a language key of the problem
     */
    private function validated(?int $excludeId, string $code, string $type, string $valueInput, string $currency, ?string $startsAt, ?string $expiresAt, ?string $maxUsesInput, ?string $minSubtotalInput): array|string
    {
        $code = mb_strtoupper(trim($code));
        if (preg_match('/^[A-Z0-9][A-Z0-9-]{1,31}$/', $code) !== 1) {
            return 'coupons.admin.error.code';
        }
        $existing = $this->findByCode($code);
        if ($existing !== null && $existing['id'] !== $excludeId) {
            return 'coupons.admin.error.code_taken';
        }

        if ($type === 'percent') {
            $value = (int) $valueInput;
            if ($value < 1 || $value > 100) {
                return 'coupons.admin.error.value';
            }
        } elseif ($type === 'fixed') {
            $value = Money::parse($valueInput);
            if ($value === null || $value < 1) {
                return 'coupons.admin.error.value';
            }
        } else {
            return 'coupons.admin.error.value';
        }

        $currency = mb_strtoupper(trim($currency));
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            return 'coupons.admin.error.currency';
        }

        if ($startsAt !== null && $expiresAt !== null && $startsAt > $expiresAt) {
            return 'coupons.admin.error.dates';
        }

        $maxUses = trim((string) $maxUsesInput) !== '' ? (int) $maxUsesInput : null;
        if ($maxUses !== null && $maxUses < 1) {
            return 'coupons.admin.error.max_uses';
        }

        $minSubtotal = null;
        $minSubtotalTrimmed = trim((string) $minSubtotalInput);
        if ($minSubtotalTrimmed !== '') {
            $minSubtotal = Money::parse($minSubtotalTrimmed);
            if ($minSubtotal === null) {
                return 'coupons.admin.error.min_subtotal';
            }
        }

        return [
            'code' => $code, 'type' => $type, 'value' => $value, 'currency' => $currency,
            'starts_at' => $startsAt, 'expires_at' => $expiresAt,
            'max_uses' => $maxUses, 'min_subtotal_cents' => $minSubtotal,
        ];
    }

    private static function cast(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'code' => $row['code'],
            'type' => $row['type'],
            'value' => (int) $row['value'],
            'currency' => $row['currency'],
            'app_id' => $row['app_id'],
            'active' => (bool) $row['active'],
            'starts_at' => $row['starts_at'],
            'expires_at' => $row['expires_at'],
            'max_uses' => $row['max_uses'] !== null ? (int) $row['max_uses'] : null,
            'used_count' => (int) $row['used_count'],
            'min_subtotal_cents' => $row['min_subtotal_cents'] !== null ? (int) $row['min_subtotal_cents'] : null,
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }
}
