<?php

declare(strict_types=1);

namespace Modulento\Coupons;

use Modulento\Core\App;

/**
 * Builds this addon's Coupons against the running request's App - the one
 * place that wires its app-scoping closure up, so Extension::register()
 * and AdminController construct the very same thing the very same way.
 */
final class Services
{
    public static function coupons(App $app): Coupons
    {
        return new Coupons($app->db, fn (): ?string => Coupons::resolveActiveAppId($app));
    }
}
