<?php

declare(strict_types=1);

namespace Modulento\Coupons;

use Modulento\Core\App;
use Modulento\Core\Event\OrderStateChanged;
use Modulento\Core\Extension\Extension as ExtensionContract;
use Modulento\Core\Extension\Registrar;
use Modulento\Core\Support\Router;
use RuntimeException;

/**
 * Coupon codes, applied at checkout through the core's generic
 * order-placement path (Registrar::checkoutSection() /
 * Registrar::orderDiscount(), see core commit "Add checkoutSection() and
 * orderDiscount() extension hooks"). As of today that path is only ever
 * walked by offer types whose OrderFlow::checkout() is true - freelancer,
 * not shop (which has its own, separate, already-built discount system in
 * modulento-ext-shop), not auction (no checkout moment: an order is
 * created directly on winning a bid) and not card (does not use the order
 * system at all). See this repository's README for the full explanation;
 * a coupon scoped to "shop" here is accepted and stored for
 * forward-compatibility and consistency with Abos's same app_id
 * convention, but is not reachable through shop's own checkout today.
 */
final class Extension implements ExtensionContract
{
    public const PERMISSION = 'coupons.manage';

    public function register(Registrar $registrar): void
    {
        $registrar->permission(self::PERMISSION, 'coupons.permission.manage');

        $registrar->routes(function (Router $router): void {
            $router->get('/admin/coupons', [AdminController::class, 'index'], self::PERMISSION);
            $router->get('/admin/coupons/new', [AdminController::class, 'edit'], self::PERMISSION);
            $router->post('/admin/coupons/new', [AdminController::class, 'save'], self::PERMISSION);
            $router->get('/admin/coupons/{id}', [AdminController::class, 'edit'], self::PERMISSION);
            $router->post('/admin/coupons/{id}', [AdminController::class, 'save'], self::PERMISSION);
            $router->post('/admin/coupons/{id}/delete', [AdminController::class, 'delete'], self::PERMISSION);
        });

        $registrar->adminMenu('coupons.admin.menu', '/admin/coupons', self::PERMISSION, 'marketplace');

        $registrar->checkoutSection('@coupons/checkout.twig', fn (array $offer, array $input, App $app): array => [
            // Whatever was typed before, so it survives a validation error
            // (e.g. another field on the same form was wrong) - see
            // Registrar::checkoutSection()'s own docblock.
            'code' => (string) ($input['coupon_code'] ?? ''),
        ]);

        // A reservation made in the orderDiscount() modifier below, carried
        // to the moment the order actually gets an id - a fresh local
        // variable each time register() runs (once per request, see
        // ExtensionManager::loadEnabled()), never a static property, so
        // nothing here outlives the one HTTP request that placed the order.
        //
        // Why this exists at all: OrderController::place() applies
        // orderDiscount() modifiers BEFORE Orders::create() runs (so a
        // refused code can still show as a form error instead of a half-
        // placed order), which means the modifier itself never learns the
        // new order's id. The core, however, already fires
        // Modulento\Core\Event\OrderStateChanged right after
        // Orders::create() - with that id, the order's old state (null for
        // a fresh order) and the transition ("place") - so this addon
        // listens for exactly that event to write x_coupon_redemption
        // properly keyed by order_id, rather than improvising a key from
        // only (coupon_id, account_id, created_at) as the fallback the
        // brief for this addon allowed for.
        //
        // The usage slot itself is still reserved atomically inside the
        // modifier, not deferred to the event: that is what makes two
        // buyers racing for the last use safe (see Coupons::reserve()). One
        // honest limitation this leaves: if something else in the same
        // request refuses the order for an unrelated reason (e.g. no
        // payment method, terms not accepted) AFTER this modifier already
        // ran, the reserved use is not given back and no redemption row is
        // ever written for it - documented in the README rather than hidden.
        $pending = null;

        $registrar->orderDiscount(function (array $items, array $offer, array $input, App $app) use (&$pending): array {
            $code = trim((string) ($input['coupon_code'] ?? ''));
            if ($code === '') {
                return $items;
            }

            $coupons = Services::coupons($app);
            $coupon = $coupons->findByCode($code);
            $subtotal = array_sum(array_map(fn (array $item) => $item['quantity'] * $item['unit_price'], $items));
            $problem = $coupon === null ? 'coupons.error.invalid' : $coupons->problem($coupon, (string) $offer['currency'], $subtotal);
            if ($problem !== null) {
                throw new RuntimeException($problem);
            }

            if (!$coupons->reserve($coupon['id'])) {
                // Lost a race for the last use between the check above and here.
                throw new RuntimeException('coupons.error.used_up');
            }

            $amount = $coupons->discountAmount($coupon, $subtotal);
            $pending = ['coupon_id' => $coupon['id'], 'account_id' => (int) $app->auth->account()['id'], 'amount_cents' => $amount];

            return $coupons->apply($items, $amount);
        });

        $registrar->listen(OrderStateChanged::class, function (OrderStateChanged $event, App $app) use (&$pending): void {
            if ($pending === null || $event->oldState !== null || $event->transition !== 'place') {
                return;
            }

            Services::coupons($app)->redeem($pending['coupon_id'], $event->orderId, $pending['account_id'], $pending['amount_cents']);
            $pending = null;
        });
    }
}
