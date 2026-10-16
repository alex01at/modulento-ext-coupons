# Coupons

An addon for [Modulento](https://github.com/alex01at/modulento) that adds
coupon/discount codes an operator can hand out: a proper admin-managed
table of codes (percent or fixed-amount, usage limits, a date range, a
minimum order total, scoped to one app or general), not a single
hardcoded one.

## Important: where this actually applies

Modulento has no single chokepoint for "apply a discount" across every
offer type. Shop has its own, separate, already-built discount system
(`modulento-ext-shop`'s `Discounts`/`CartDiscount`, gated behind the
`shop.discounts` module) with its own checkout controller that bypasses
the generic order form entirely - this addon does not touch or duplicate
it, and a coupon from here is simply never seen by shop's checkout.
Auction has no checkout moment at all (an order is created directly on
winning a bid). Card does not use the order system at all.

This addon's coupon mechanism only ever applies through the core's
**generic order-placement path** - `OrderController::place()` →
`OrderFlow::build()` → `Orders::create()` - via two extension hooks,
`Registrar::checkoutSection()` and `Registrar::orderDiscount()`. As of
today, that is **freelancer only**. Any future offer type whose
`OrderFlow::checkout()` is `true` and that goes through the same generic
path picks this up automatically, with no change needed here.

A coupon can still be scoped to "shop" (or any other app) in the admin UI
- accepted and stored for consistency with how Abos's subscription plans
scope to an app, and so it is ready the day an app other than freelancer
adopts the generic checkout path - but doing so today creates a coupon
that is simply never reachable, since shop's own checkout never asks this
addon anything. This is a plain limitation of the current hook design, not
a bug; stated here rather than hidden.

## What a coupon has

- **code** - unique, compared case-insensitively (normalised to uppercase
  on save and on lookup).
- **type** - `percent` (1-100) or `fixed` (an amount in minor units).
- **currency** - required for both types; compared against the offer's
  own currency, and the code is refused (not silently skipped) on a
  mismatch.
- **app_id** - nullable scope, see above; `null` applies under any active
  app.
- **active**, **starts_at**/**expires_at** (both nullable), **max_uses**
  (nullable - unlimited if unset) and **used_count**, **min_subtotal_cents**
  (nullable - the coupon only applies once the order's pre-discount total
  reaches it).

## How it applies a discount

`order_item.unit_price` is `INT UNSIGNED` in the core's schema, so a
discount reduces it rather than adding a negative line, prorated across
every line so none goes negative and the remainder lands on the last one
- the exact proration already solved in modulento-ext-shop's own
`Discounts::apply()`, ported here (`Coupons::apply()`) rather than
reinvented.

A submitted code is validated (exists, active, app-scoped correctly for
the app that is active now, not before `starts_at`, not past
`expires_at`, currency matches the offer's, usage limit not reached,
`min_subtotal_cents` met against the pre-discount total) and, on success,
the usage slot is **reserved atomically** (`UPDATE ... WHERE used_count <
max_uses`, like `modulento-ext-shop`'s own `Discounts::reserve()`) so two
buyers racing for the last use of a limited code cannot both win it. An
empty code is a silent no-op, never an error; any other problem throws
`RuntimeException('coupons.error.<reason>')`, which `OrderController::place()`
turns into a form error.

### Why the redemption row is keyed by order id, not by (coupon_id, account_id, created_at)

The usage slot has to be reserved *during* the `orderDiscount()` modifier
itself, before the order exists, so the atomic reservation above actually
protects the last use. But by the time `Orders::create()` runs and an
order id exists, the modifier has already returned. Rather than falling
back to keying a redemption row by `(coupon_id, account_id, created_at)`,
this addon listens for `Modulento\Core\Event\OrderStateChanged` - which
the core already fires right after `Orders::create()`, with the new
order's id, its old state (`null` for a fresh order) and the transition
(`"place"`) - and writes `x_coupon_redemption` properly keyed by
`order_id` from there. See `src/Extension.php` for how the two hooks hand
a pending reservation off to each other within the one request that
places the order (a plain local variable, not a static property - nothing
here outlives that one request).

One honest limitation this still leaves: everything else `place()` checks
(payment method, terms accepted, file uploads, the note's length, rate
limiting, the open-orders cap) runs *after* `orderDiscount()`. If one of
those refuses the order for a reason that has nothing to do with the
coupon, the reserved use is not given back and no redemption row is ever
written for it - a leaked use, not a double-spent one. This is a
trade-off of the current hook order, not something this addon works
around.

## Admin UI

**Administration → Coupons** (permission `coupons.manage`, under the
"marketplace" menu group): list, create, edit and delete coupons, each
showing its current `used_count`/`max_uses`, its scoped app (a picker
listing every installed "app"-type extension - freelancer, shop, auction,
card - the same convention Abos's plan editor already uses), its active
state and its valid date range.

## Public-facing piece

`checkoutSection('@coupons/checkout.twig', ...)` adds a small code field to
the order form, between the offer type's own fields and the choice of
payment method; a typed code survives a failed validation (the error
itself shows through the order form's own generic error banner).

## Installing

Clone or symlink this repository into `extensions/coupons` of a Modulento
installation, then enable it under Administration → Extensions.

## Running its tests

```
MODULENTO_CORE=/path/to/modulento php tests/run.php
```
