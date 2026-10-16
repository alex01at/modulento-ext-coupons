<?php

declare(strict_types=1);

// Plain-PHP checks of the Coupons addon, on SQLite in memory, through a
// real App and the core's own router (same harness style core/tests/run.php
// uses for its own end-to-end checks):
//   MODULENTO_CORE=/path/to/modulento php tests/run.php
// The core is only needed for its classes and the real "freelancer"
// extension (both found through MODULENTO_CORE, or expected next to this
// repository at ../modulento). Exit code 0 means every check passed.

use Modulento\Coupons\AdminController;
use Modulento\Coupons\Coupons;
use Modulento\Coupons\Services;
use Modulento\Core\App;
use Modulento\Core\Support\Auth;

$here = dirname(__DIR__);
$core = getenv('MODULENTO_CORE') ?: dirname($here) . '/modulento';

if (!is_file($core . '/vendor/autoload.php')) {
    fwrite(STDERR, "No Modulento core with vendor/ found at {$core}.\n"
        . "Set MODULENTO_CORE to a working copy of the core in which \"composer install\" has run.\n");
    exit(1);
}
if (!is_dir($core . '/extensions/freelancer')) {
    fwrite(STDERR, "The core at {$core} needs the \"freelancer\" extension (extensions/freelancer) for the end-to-end checkout checks:\n"
        . "clone or symlink alex01at/modulento-ext-freelancer there.\n");
    exit(1);
}

require $core . '/vendor/autoload.php';

date_default_timezone_set('UTC');

$failed = [];
$checks = 0;

function check(string $label, bool $condition): void
{
    global $failed, $checks;
    $checks++;
    if (!$condition) {
        $failed[] = $label;
    }
}

// --- Database: the subset of the core's own schema that the freelancer
// extension, order placement and the administration actually touch, plus
// this addon's own two tables. Copied from core/tests/run.php's own
// fixture (proven against the real router, base.twig and OrderController)
// rather than re-derived, so nothing here is "close enough".
$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec("CREATE TABLE account (id INTEGER PRIMARY KEY, email TEXT UNIQUE, display_name TEXT, password_hash TEXT, status TEXT,
    status_note TEXT, identity_status TEXT NOT NULL DEFAULT 'none', identity_note TEXT, identity_decided_at TEXT, identity_decided_by INTEGER,
    email_verified_at TEXT, terms_accepted_at TEXT, locale TEXT, created_at TEXT, last_login_at TEXT)");
$pdo->exec("CREATE TABLE account_billing_profile (account_id INTEGER PRIMARY KEY REFERENCES account (id) ON DELETE CASCADE,
    company_name TEXT, website TEXT, vat_id TEXT, tax_id TEXT, street TEXT, postal_code TEXT, city TEXT, country TEXT, updated_at TEXT)");
$pdo->exec("CREATE TABLE notification (id INTEGER PRIMARY KEY, account_id INTEGER REFERENCES account (id) ON DELETE CASCADE,
    type TEXT, message_key TEXT, params TEXT, link TEXT, read_at TEXT, created_at TEXT)");
$pdo->exec("CREATE TABLE subscription_plan (id INTEGER PRIMARY KEY, slug TEXT UNIQUE, name TEXT, price_cents INTEGER, currency TEXT, period_months INTEGER, features TEXT NOT NULL DEFAULT '', offer_limits TEXT NOT NULL DEFAULT '{}', max_images_per_offer INTEGER NULL, per_seat INTEGER NOT NULL DEFAULT 0, min_quantity INTEGER NOT NULL DEFAULT 1, active INTEGER NOT NULL DEFAULT 1)");
$pdo->exec("CREATE TABLE subscription (id INTEGER PRIMARY KEY, account_id INTEGER REFERENCES account (id) ON DELETE CASCADE, plan_id INTEGER REFERENCES subscription_plan (id), status TEXT, period_end TEXT, provider_ref TEXT NULL, quantity INTEGER NOT NULL DEFAULT 1, stripe_item_ref TEXT NULL, reminded_for TEXT NULL, created_at TEXT, updated_at TEXT)");
$pdo->exec("CREATE TABLE provider (id INTEGER PRIMARY KEY, account_id INTEGER UNIQUE REFERENCES account (id) ON DELETE CASCADE, type TEXT, status TEXT,
    status_note TEXT, name TEXT, slug TEXT UNIQUE, legal_name TEXT, street TEXT, postal_code TEXT, city TEXT, country TEXT, contact_email TEXT, phone TEXT,
    vat_id TEXT, tax_id TEXT, company_register TEXT, self_certified_at TEXT, details_changed_at TEXT, decided_at TEXT, decided_by INTEGER, created_at TEXT, updated_at TEXT,
    avg_response_minutes INTEGER, response_sample_count INTEGER NOT NULL DEFAULT 0, rating_count INTEGER NOT NULL DEFAULT 0, rating_sum INTEGER NOT NULL DEFAULT 0, is_demo INTEGER NOT NULL DEFAULT 0)");
$pdo->exec("CREATE TABLE provider_translation (provider_id INTEGER REFERENCES provider (id) ON DELETE CASCADE, locale TEXT, headline TEXT, description TEXT, PRIMARY KEY (provider_id, locale))");
$pdo->exec("CREATE TABLE page (id INTEGER PRIMARY KEY, status TEXT, role TEXT UNIQUE, in_header INTEGER, in_footer INTEGER, position INTEGER, created_at TEXT, updated_at TEXT)");
$pdo->exec("CREATE TABLE page_translation (page_id INTEGER REFERENCES page (id) ON DELETE CASCADE, locale TEXT, title TEXT, slug TEXT,
    meta_description TEXT, body TEXT, PRIMARY KEY (page_id, locale), UNIQUE (locale, slug))");
$pdo->exec("CREATE TABLE account_token (id INTEGER PRIMARY KEY, account_id INTEGER REFERENCES account (id) ON DELETE CASCADE,
    purpose TEXT, token_hash TEXT UNIQUE, payload TEXT, expires_at TEXT, created_at TEXT)");
$pdo->exec("CREATE TABLE rate_limit_attempt (id INTEGER PRIMARY KEY, action TEXT, identifier TEXT, created_at TEXT)");
$pdo->exec("CREATE TABLE task_run (name TEXT PRIMARY KEY, last_started_at TEXT, last_finished_at TEXT, last_status TEXT, last_error TEXT)");
$pdo->exec("CREATE TABLE category (id INTEGER PRIMARY KEY, parent_id INTEGER REFERENCES category (id), position INTEGER, created_at TEXT)");
$pdo->exec("CREATE TABLE category_translation (category_id INTEGER REFERENCES category (id) ON DELETE CASCADE, locale TEXT, name TEXT, slug TEXT, PRIMARY KEY (category_id, locale), UNIQUE (locale, slug))");
$pdo->exec("CREATE TABLE offer (id INTEGER PRIMARY KEY, provider_id INTEGER REFERENCES provider (id) ON DELETE CASCADE, type TEXT, category_id INTEGER REFERENCES category (id) ON DELETE SET NULL,
    status TEXT, status_note TEXT, price_from INTEGER, currency TEXT, decided_at TEXT, decided_by INTEGER, published_at TEXT, created_at TEXT, updated_at TEXT,
    rating_count INTEGER NOT NULL DEFAULT 0, rating_sum INTEGER NOT NULL DEFAULT 0, is_demo INTEGER NOT NULL DEFAULT 0)");
$pdo->exec("CREATE TABLE offer_translation (offer_id INTEGER REFERENCES offer (id) ON DELETE CASCADE, locale TEXT, title TEXT, slug TEXT, summary TEXT, description TEXT, PRIMARY KEY (offer_id, locale), UNIQUE (locale, slug))");
$pdo->exec("CREATE TABLE offer_image (id INTEGER PRIMARY KEY, offer_id INTEGER REFERENCES offer (id) ON DELETE CASCADE, name TEXT, extension TEXT, width INTEGER, height INTEGER, position INTEGER, created_at TEXT)");
$pdo->exec("CREATE TABLE custom_field (id INTEGER PRIMARY KEY, entity TEXT, field_key TEXT, type TEXT, required INTEGER NOT NULL DEFAULT 0, position INTEGER NOT NULL DEFAULT 0, type_ids TEXT, created_at TEXT, UNIQUE (entity, field_key))");
$pdo->exec("CREATE TABLE custom_field_translation (field_id INTEGER REFERENCES custom_field (id) ON DELETE CASCADE, locale TEXT, label TEXT, option_labels TEXT, PRIMARY KEY (field_id, locale))");
$pdo->exec("CREATE TABLE custom_field_offer_value (field_id INTEGER REFERENCES custom_field (id) ON DELETE CASCADE, offer_id INTEGER REFERENCES offer (id) ON DELETE CASCADE, locale TEXT NOT NULL DEFAULT '', value TEXT, PRIMARY KEY (field_id, offer_id, locale))");
$pdo->exec("CREATE TABLE custom_field_provider_value (field_id INTEGER REFERENCES custom_field (id) ON DELETE CASCADE, provider_id INTEGER REFERENCES provider (id) ON DELETE CASCADE, locale TEXT NOT NULL DEFAULT '', value TEXT, PRIMARY KEY (field_id, provider_id, locale))");
$pdo->exec("CREATE TABLE x_freelancer_package (id INTEGER PRIMARY KEY, offer_id INTEGER REFERENCES offer (id) ON DELETE CASCADE, tier INTEGER, price INTEGER, delivery_days INTEGER, revisions INTEGER, UNIQUE (offer_id, tier))");
$pdo->exec("CREATE TABLE x_freelancer_package_translation (package_id INTEGER REFERENCES x_freelancer_package (id) ON DELETE CASCADE, locale TEXT, name TEXT, description TEXT, PRIMARY KEY (package_id, locale))");
$pdo->exec("CREATE TABLE x_freelancer_extra (id INTEGER PRIMARY KEY, offer_id INTEGER REFERENCES offer (id) ON DELETE CASCADE, position INTEGER, price INTEGER, extra_days INTEGER)");
$pdo->exec("CREATE TABLE x_freelancer_extra_translation (extra_id INTEGER REFERENCES x_freelancer_extra (id) ON DELETE CASCADE, locale TEXT, title TEXT, PRIMARY KEY (extra_id, locale))");
$pdo->exec("CREATE TABLE x_freelancer_requirement (offer_id INTEGER REFERENCES offer (id) ON DELETE CASCADE, locale TEXT, text TEXT, PRIMARY KEY (offer_id, locale))");
$pdo->exec("CREATE TABLE orders (id INTEGER PRIMARY KEY, buyer_id INTEGER REFERENCES account (id) ON DELETE SET NULL, provider_id INTEGER REFERENCES provider (id) ON DELETE SET NULL,
    offer_id INTEGER REFERENCES offer (id) ON DELETE SET NULL, flow TEXT, state TEXT, previous_state TEXT, state_actor TEXT, buyer_name TEXT, provider_name TEXT, offer_title TEXT,
    total INTEGER, currency TEXT, locale TEXT, payment_method TEXT, payment_state TEXT DEFAULT 'unpaid', paid_at TEXT, data TEXT, due_at TEXT, due_transition TEXT,
    terms_accepted_at TEXT, created_at TEXT, updated_at TEXT, closed_at TEXT)");
$pdo->exec("CREATE TABLE order_item (id INTEGER PRIMARY KEY, order_id INTEGER REFERENCES orders (id) ON DELETE CASCADE, position INTEGER, label TEXT, quantity INTEGER, unit_price INTEGER)");
$pdo->exec("CREATE TABLE order_event (id INTEGER PRIMARY KEY, order_id INTEGER REFERENCES orders (id) ON DELETE CASCADE, transition TEXT, from_state TEXT, to_state TEXT,
    actor_id INTEGER REFERENCES account (id) ON DELETE SET NULL, actor_role TEXT, note TEXT, created_at TEXT)");
$pdo->exec("CREATE TABLE order_message (id INTEGER PRIMARY KEY, order_id INTEGER REFERENCES orders (id) ON DELETE CASCADE, account_id INTEGER REFERENCES account (id) ON DELETE SET NULL,
    author_role TEXT, body TEXT, flagged_word TEXT, status TEXT NOT NULL DEFAULT 'visible', decided_at TEXT, decided_by INTEGER REFERENCES account (id) ON DELETE SET NULL, created_at TEXT)");
$pdo->exec("CREATE TABLE order_file (id INTEGER PRIMARY KEY, order_id INTEGER REFERENCES orders (id) ON DELETE CASCADE, event_id INTEGER REFERENCES order_event (id) ON DELETE CASCADE,
    message_id INTEGER REFERENCES order_message (id) ON DELETE CASCADE, account_id INTEGER REFERENCES account (id) ON DELETE SET NULL, author_role TEXT, original_name TEXT, stored_name TEXT, size INTEGER, created_at TEXT)");
$pdo->exec("CREATE TABLE review (id INTEGER PRIMARY KEY, order_id INTEGER UNIQUE REFERENCES orders (id) ON DELETE CASCADE, offer_id INTEGER REFERENCES offer (id) ON DELETE SET NULL,
    provider_id INTEGER REFERENCES provider (id) ON DELETE CASCADE, author_id INTEGER REFERENCES account (id) ON DELETE SET NULL, author_name TEXT, rating INTEGER, body TEXT, locale TEXT,
    status TEXT DEFAULT 'published', status_note TEXT, reply TEXT, replied_at TEXT, created_at TEXT)");
$pdo->exec("CREATE TABLE provider_payment (provider_id INTEGER REFERENCES provider (id) ON DELETE CASCADE, method TEXT, data TEXT, status TEXT, updated_at TEXT, PRIMARY KEY (provider_id, method))");
$pdo->exec("CREATE TABLE order_payment (id INTEGER PRIMARY KEY, order_id INTEGER REFERENCES orders (id) ON DELETE CASCADE, method TEXT, provider_reference TEXT, status TEXT,
    amount INTEGER, currency TEXT, created_at TEXT, updated_at TEXT, UNIQUE (method, provider_reference))");
$pdo->exec("CREATE TABLE account_avatar (account_id INTEGER PRIMARY KEY REFERENCES account (id) ON DELETE CASCADE, name TEXT, extension TEXT, created_at TEXT)");
$pdo->exec("CREATE TABLE offer_message (id INTEGER PRIMARY KEY, offer_id INTEGER REFERENCES offer (id) ON DELETE CASCADE, asker_id INTEGER REFERENCES account (id) ON DELETE CASCADE, author_id INTEGER REFERENCES account (id) ON DELETE SET NULL, body TEXT,
    flagged_word TEXT, status TEXT NOT NULL DEFAULT 'visible', decided_at TEXT, decided_by INTEGER REFERENCES account (id) ON DELETE SET NULL, created_at TEXT)");
$pdo->exec("CREATE TABLE message_seen (account_id INTEGER REFERENCES account (id) ON DELETE CASCADE, scope TEXT, ref_id INTEGER, sub_id INTEGER DEFAULT 0, seen_id INTEGER, PRIMARY KEY (account_id, scope, ref_id, sub_id))");
$pdo->exec("CREATE TABLE admin_log (id INTEGER PRIMARY KEY, created_at TEXT, actor_id INTEGER REFERENCES account (id) ON DELETE SET NULL, action TEXT, target_id INTEGER REFERENCES account (id) ON DELETE SET NULL, detail TEXT NOT NULL DEFAULT '')");
$pdo->exec("CREATE TABLE account_preference (account_id INTEGER REFERENCES account (id) ON DELETE CASCADE, name TEXT, value TEXT, PRIMARY KEY (account_id, name))");
$pdo->exec("CREATE TABLE account_rating (id INTEGER PRIMARY KEY, rater_id INTEGER REFERENCES account (id) ON DELETE SET NULL, rater_name TEXT,
    rated_id INTEGER REFERENCES account (id) ON DELETE CASCADE, rating INTEGER, body TEXT, locale TEXT, status TEXT DEFAULT 'published',
    status_note TEXT, reply TEXT, replied_at TEXT, created_at TEXT, UNIQUE (rater_id, rated_id))");
$pdo->exec("CREATE TABLE role (id INTEGER PRIMARY KEY, name TEXT)");
$pdo->exec("CREATE TABLE role_permission (role_id INTEGER REFERENCES role (id) ON DELETE CASCADE, permission TEXT)");
$pdo->exec("CREATE TABLE account_role (account_id INTEGER REFERENCES account (id) ON DELETE CASCADE, role_id INTEGER REFERENCES role (id) ON DELETE CASCADE)");
$pdo->exec("CREATE TABLE extension (id TEXT PRIMARY KEY, version TEXT, enabled INTEGER)");
$pdo->exec("CREATE TABLE setting (name TEXT PRIMARY KEY, value TEXT)");
$pdo->exec("CREATE TABLE media (id INTEGER PRIMARY KEY, file TEXT UNIQUE, title TEXT, width INTEGER, height INTEGER, bytes INTEGER, created_at TEXT)");
$pdo->exec("CREATE TABLE package (kind TEXT, id TEXT, repo TEXT, version TEXT, installed_at TEXT, PRIMARY KEY (kind, id))");

$pdo->exec("CREATE TABLE x_coupon (
    id INTEGER PRIMARY KEY, code TEXT UNIQUE NOT NULL, type TEXT NOT NULL, value INTEGER NOT NULL, currency TEXT NOT NULL,
    app_id TEXT NULL, active INTEGER NOT NULL DEFAULT 1, starts_at TEXT NULL, expires_at TEXT NULL,
    max_uses INTEGER NULL, used_count INTEGER NOT NULL DEFAULT 0, min_subtotal_cents INTEGER NULL,
    created_at TEXT NOT NULL, updated_at TEXT NOT NULL
)");
$pdo->exec("CREATE TABLE x_coupon_redemption (
    id INTEGER PRIMARY KEY, coupon_id INTEGER NOT NULL REFERENCES x_coupon (id) ON DELETE CASCADE,
    order_id INTEGER UNIQUE NOT NULL REFERENCES orders (id) ON DELETE CASCADE,
    account_id INTEGER NOT NULL REFERENCES account (id) ON DELETE CASCADE,
    amount_cents INTEGER NOT NULL, created_at TEXT NOT NULL
)");
$pdo->exec('PRAGMA foreign_keys = ON');

// The migration and the schema above must name the same columns.
foreach (['x_coupon' => '001_coupons.sql', 'x_coupon_redemption' => '001_coupons.sql'] as $table => $file) {
    $migration = (string) file_get_contents($here . '/migrations/' . $file);
    preg_match('/CREATE TABLE ' . $table . ' \((.*?)\n\) ENGINE/s', $migration, $body);
    preg_match_all('/^\s{4}([a-z_]+) [A-Z]/m', $body[1] ?? '', $columns);
    $inTest = array_column($pdo->query("PRAGMA table_info({$table})")->fetchAll(), 'name');
    sort($columns[1]);
    sort($inTest);
    check("schema: {$table} has the same columns in the migration and in the tests", $columns[1] !== [] && $columns[1] === $inTest);
}

$pdo->exec("INSERT INTO setting VALUES ('core.languages', 'de,en'), ('core.default_language', 'de')");
$testHash = password_hash('correct horse battery', PASSWORD_BCRYPT, ['cost' => 4]);
$insertAccount = $pdo->prepare("INSERT INTO account (id, email, password_hash, status, email_verified_at, locale, created_at) VALUES (?, ?, ?, ?, '2026-01-01 00:00:00', 'de', '2026-01-01 00:00:00')");
foreach ([[1, 'buyer@example.test', 'active'], [2, 'provider@example.test', 'active'], [3, 'admin@example.test', 'active']] as [$id, $email, $status]) {
    $insertAccount->execute([$id, $email, $testHash, $status]);
}
$pdo->exec("INSERT INTO role VALUES (1, 'admin')");
$pdo->exec("INSERT INTO role_permission VALUES (1, '*')");
$pdo->exec("INSERT INTO account_role VALUES (3, 1)");

$pdo->exec("INSERT INTO provider (id, account_id, status, name, slug, created_at, updated_at) VALUES (1, 2, 'approved', 'Provider GmbH', 'provider-gmbh', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
$pdo->exec("INSERT INTO offer (id, provider_id, type, status, price_from, currency, published_at, created_at, updated_at) VALUES (1, 1, 'freelancer.service', 'published', 5000, 'EUR', '2026-01-01 00:00:00', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
$pdo->exec("INSERT INTO offer_translation (offer_id, locale, title, slug, summary, description) VALUES (1, 'de', 'Testdienst', 'testdienst', 'Kurz', 'Lang')");
$pdo->exec("INSERT INTO x_freelancer_package (id, offer_id, tier, price, delivery_days, revisions) VALUES (1, 1, 1, 5000, 3, 1)");
$pdo->exec("INSERT INTO x_freelancer_package_translation (package_id, locale, name, description) VALUES (1, 'de', 'Basis', 'Grundpaket')");
$pdo->exec("INSERT INTO x_freelancer_extra (id, offer_id, position, price, extra_days) VALUES (1, 1, 0, 2500, 1)");
$pdo->exec("INSERT INTO x_freelancer_extra_translation (extra_id, locale, title) VALUES (1, 'de', 'Express')");

// --- The extension folder, symlinked next to the real "freelancer" extension and a bare "shop" app stand-in ------
$uploads = sys_get_temp_dir() . '/modulento-coupons-test-' . bin2hex(random_bytes(4));
mkdir($uploads . '/extensions', 0777, true);
symlink($here, $uploads . '/extensions/coupons');
symlink($core . '/extensions/freelancer', $uploads . '/extensions/freelancer');

// A bare "app"-type stand-in, exactly like modulento-ext-abos's own test
// uses for its app-scoping checks: real enough for
// Coupons::resolveActiveAppId() to see (it only looks for a loaded
// manifest of type "app"), without needing shop's whole schema - this
// addon's own README explains why a coupon scoped to "shop" is still not
// reachable through shop's own (separate) checkout today regardless.
mkdir($uploads . '/extensions/shop/src', 0777, true);
file_put_contents($uploads . '/extensions/shop/extension.json', json_encode([
    'id' => 'shop', 'name' => 'Shop', 'version' => '0.1.0', 'api' => 1, 'type' => 'app', 'namespace' => 'Modulento\\TestShop',
]));
file_put_contents($uploads . '/extensions/shop/src/Extension.php', '<?php
declare(strict_types=1);
namespace Modulento\\TestShop;
final class Extension implements \\Modulento\\Core\\Extension\\Extension {
    public function register(\\Modulento\\Core\\Extension\\Registrar $registrar): void {}
}
');

$testThemes = sys_get_temp_dir() . '/modulento-coupons-test-themes-' . bin2hex(random_bytes(4));
mkdir($testThemes, 0777, true);
symlink($core . '/themes/default', $testThemes . '/default');
symlink($core . '/themes/admin', $testThemes . '/admin');

$config = [
    'app' => [
        'env' => 'dev', 'url' => 'https://coupons.example', 'name' => 'Testseite', 'root' => $core,
        'extensions' => $uploads . '/extensions', 'uploads' => $uploads, 'secret_key' => $uploads . '/secret.key',
        'themes' => $testThemes, 'work' => sys_get_temp_dir() . '/modulento-coupons-test-work-' . bin2hex(random_bytes(4)),
    ],
    'update' => ['token' => ''],
    'packages' => ['sources' => []],
    'mail' => ['from' => 'noreply@example.test', 'transport' => 'log', 'log_path' => sys_get_temp_dir() . '/modulento-coupons-test-mail-' . bin2hex(random_bytes(4)) . '.log'],
];

/**
 * A fresh App with exactly the given extensions enabled (besides coupons,
 * always on) - the active "app" is resolved lazily from $app->extensions
 * ->loaded(), which is only populated once loadEnabled() runs, so a
 * different active app means a different App instance.
 *
 * @param string[] $enabledAppIds
 */
function buildApp(array $enabledAppIds, PDO $pdo, array $config, string $core): App
{
    $pdo->exec("UPDATE extension SET enabled = 0 WHERE id <> 'coupons'");
    foreach ($enabledAppIds as $id) {
        $pdo->prepare("INSERT INTO extension (id, version, enabled) VALUES (:id, '0', 1)
            ON CONFLICT(id) DO UPDATE SET enabled = 1")->execute(['id' => $id]);
    }

    $app = new App($config, $pdo, 'de');
    $app->translator->load($core . '/core/lang', 'core');
    $app->extensions->loadEnabled($app);

    return $app;
}

$pdo->exec("INSERT INTO extension (id, version, enabled) VALUES ('coupons', '0.1.0', 1)");

// --- Coupons: CRUD, validation ------------------------------------------------
$app = buildApp(['freelancer'], $pdo, $config, $core);
check('extension: the core loads it as an addon', isset($app->extensions->loaded()['coupons']) && $app->extensions->loaded()['coupons']->type === 'addon');
check('extension: permission and admin menu entry are announced', ($app->permissions()['coupons.manage'] ?? null) === 'coupons.permission.manage'
    && array_filter($app->adminMenu(), fn (array $e) => $e['path'] === '/admin/coupons' && $e['permission'] === 'coupons.manage' && $e['group'] === 'marketplace') !== []);

$coupons = Services::coupons($app);

$percentId = null;
$problem = $coupons->create('save20', 'percent', '20', 'eur', null, true, null, null, null, null);
check('Coupons::create(): a general percent coupon, code normalised to uppercase', $problem === null);
$saveTwenty = $coupons->findByCode('save20');
check('Coupons::findByCode(): case-insensitive lookup finds it, currency upper-cased', $saveTwenty !== null && $saveTwenty['code'] === 'SAVE20' && $saveTwenty['currency'] === 'EUR' && $saveTwenty['app_id'] === null);
$percentId = $saveTwenty['id'];

check('Coupons::create(): a duplicate code is refused', $coupons->create('SAVE20', 'percent', '10', 'EUR', null, true, null, null, null, null) === 'coupons.admin.error.code_taken');
check('Coupons::create(): an out-of-range percent is refused', $coupons->create('BADPCT', 'percent', '150', 'EUR', null, true, null, null, null, null) === 'coupons.admin.error.value');
check('Coupons::create(): an invalid fixed amount is refused', $coupons->create('BADFIX', 'fixed', 'nope', 'EUR', null, true, null, null, null, null) === 'coupons.admin.error.value');
check('Coupons::create(): a malformed code is refused', $coupons->create('a b', 'percent', '10', 'EUR', null, true, null, null, null, null) === 'coupons.admin.error.code');
check('Coupons::create(): a bad currency is refused', $coupons->create('BADCUR', 'percent', '10', 'EURO', null, true, null, null, null, null) === 'coupons.admin.error.currency');
check('Coupons::create(): starts_at after expires_at is refused', $coupons->create('BADDATES', 'percent', '10', 'EUR', null, true, '2030-01-01 00:00:00', '2020-01-01 00:00:00', null, null) === 'coupons.admin.error.dates');
check('Coupons::create(): a zero max_uses is refused', $coupons->create('BADMAX', 'percent', '10', 'EUR', null, true, null, null, '0', null) === 'coupons.admin.error.max_uses');

$coupons->create('FLAT500', 'fixed', '5,00', 'EUR', null, true, null, null, null, null);
$flat = $coupons->findByCode('FLAT500');
check('Coupons::create(): a fixed-amount coupon stores minor units parsed from a money string', $flat['type'] === 'fixed' && $flat['value'] === 500);

check('Coupons::update(): changes stick', $coupons->update($percentId, 'SAVE20', 'percent', '25', 'EUR', null, true, null, null, null, null) === null
    && $coupons->find($percentId)['value'] === 25);
check('Coupons::update(): the coupon\'s own code is not "taken" by itself', $coupons->update($percentId, 'SAVE20', 'percent', '20', 'EUR', null, true, null, null, null, null) === null);

$toDelete = $coupons->findByCode('FLAT500')['id'] ?? null;
// Re-create a throwaway coupon to delete, so FLAT500 survives for the checkout tests below.
$coupons->create('THROWAWAY', 'percent', '5', 'EUR', null, true, null, null, null, null);
$throwawayId = $coupons->findByCode('THROWAWAY')['id'];
$coupons->delete($throwawayId);
check('Coupons::delete(): the coupon is gone', $coupons->find($throwawayId) === null);
check('Coupons::all(): lists every coupon regardless of app', count($coupons->all()) >= 2);

// --- App scoping (unit level: Coupons::isVisible()/problem(), no real order needed under "shop" - see README) ----
$coupons->create('SHOPONLY', 'percent', '15', 'EUR', 'shop', true, null, null, null, null);
$shopOnly = $coupons->findByCode('SHOPONLY');
check('app scoping: a coupon scoped to "shop" is invisible while "freelancer" is the active app', !$coupons->isVisible($shopOnly)
    && $coupons->problem($shopOnly, 'EUR', 1000) === 'coupons.error.wrong_app');
check('app scoping: a general coupon (app_id null) is always visible', $coupons->isVisible($saveTwenty));

$appShop = buildApp(['shop'], $pdo, $config, $core);
$couponsShop = Services::coupons($appShop);
check('app scoping: the same "shop"-scoped coupon is visible once "shop" is the active app', $couponsShop->isVisible($couponsShop->findByCode('SHOPONLY')));
check('app scoping: the general coupon is visible there too', $couponsShop->isVisible($couponsShop->findByCode('SAVE20')));
check('Coupons::resolveActiveAppId(): finds the one loaded "app"-type manifest', Coupons::resolveActiveAppId($appShop) === 'shop' && Coupons::resolveActiveAppId(buildApp([], $pdo, $config, $core)) === null);

// --- Coupons::apply(): proration, ported from modulento-ext-shop's own Discounts::apply() ----
$lines = [['label' => 'A', 'quantity' => 1, 'unit_price' => 5000], ['label' => 'B', 'quantity' => 1, 'unit_price' => 2500]];
$prorated = $coupons->apply($lines, 1500);
check('Coupons::apply(): prorated across two lines, nothing negative, exact to the cent', $prorated[0]['unit_price'] === 4000 && $prorated[1]['unit_price'] === 2000
    && array_sum(array_map(fn (array $l) => $l['quantity'] * $l['unit_price'], $prorated)) === 7500 - 1500);
check('Coupons::apply(): no discount is a no-op', $coupons->apply($lines, 0) === $lines);
check('Coupons::discountAmount(): percent, never more than the subtotal', $coupons->discountAmount(['type' => 'percent', 'value' => 20], 5000) === 1000
    && $coupons->discountAmount(['type' => 'fixed', 'value' => 999999], 5000) === 5000);

// --- End to end: a real freelancer order placement through the public checkout ----
/** @return array{status: int, body: string} */
function req(PDO $pdo, array $config, string $method, string $path, ?int $accountId, array $post = []): array
{
    $_SESSION = [];
    if ($accountId !== null) {
        $hash = $pdo->query('SELECT password_hash FROM account WHERE id = ' . $accountId)->fetchColumn();
        $_SESSION = ['account_id' => $accountId, 'auth_stamp' => Auth::stamp((string) $hash)];
    }
    $_SESSION['_csrf'] = 'test-token';
    if ($method === 'POST') {
        $post += ['_csrf' => 'test-token'];
    }
    $_POST = $post;
    parse_str((string) parse_url($path, PHP_URL_QUERY), $_GET);
    $_SERVER['REQUEST_URI'] = $path;
    http_response_code(200);

    $app = new Modulento\Core\App($config, $pdo);
    $app->translator->load($config['app']['root'] . '/core/lang', 'core');
    Modulento\Core\Kernel::registerCore($app);
    $app->extensions->loadEnabled($app);
    Modulento\Core\Kernel::registerLast($app);
    Modulento\Core\Kernel::loadThemeTexts($app);
    $redirect = Modulento\Core\Kernel::prepareRequest($app, $path, startSession: false);

    ob_start();
    if ($redirect === null) {
        $app->router->dispatch($method, $app->path);
    }
    $body = (string) ob_get_clean();

    return ['status' => (int) http_response_code(), 'body' => $body];
}

$get = fn (string $path, ?int $as = null) => req($pdo, $config, 'GET', $path, $as);
$post = fn (string $path, array $fields, ?int $as = null) => req($pdo, $config, 'POST', $path, $as, $fields);
$orderRow = fn (int $id) => $pdo->query("SELECT * FROM orders WHERE id = {$id}")->fetch();
$orderItems = fn (int $id) => $pdo->query("SELECT * FROM order_item WHERE order_id = {$id} ORDER BY position")->fetchAll();
$lastOrder = fn () => (int) $pdo->query('SELECT MAX(id) FROM orders')->fetchColumn();
// Orders::openCount() refuses a 4th open order on the same offer by the same
// buyer (OrderController::MAX_OPEN_PER_OFFER = 3) - closing each order right
// after this suite is done asserting on it keeps every later placement free
// to go through, the same way a real order would eventually be closed.
$close = fn (int $id) => $pdo->exec("UPDATE orders SET closed_at = '2026-01-01 00:00:00' WHERE id = {$id}");
$orderPath = '/offers/testdienst/order';

buildApp(['freelancer'], $pdo, $config, $core); // only used to flip the extension table back to "freelancer" for req()

$r = $get($orderPath, 1);
check('checkoutSection(): the code field is rendered on the order form, empty by default', $r['status'] === 200 && str_contains($r['body'], 'name="coupon_code"') && str_contains($r['body'], 'id="coupon_code" name="coupon_code" type="text" maxlength="32" value=""'));

$before = $lastOrder();
$post($orderPath, ['package' => '1'], 1);
$withoutCode = $lastOrder();
check('orderDiscount(): no code, the price is untouched (silent no-op)', $withoutCode !== $before && (int) $orderRow($withoutCode)['total'] === 5000);
$close($withoutCode);

$r = $post($orderPath, ['package' => '1', 'coupon_code' => 'doesnotexist'], 1);
check('orderDiscount(): an unknown code refuses the order, with its own message, no order created', $lastOrder() === $withoutCode && str_contains($r['body'], 'Dieser Rabattcode ist ungültig.'));
check('checkoutSection(): the typed code survives the validation error', str_contains($r['body'], 'value="doesnotexist"'));

$post($orderPath, ['package' => '1', 'coupon_code' => 'save20'], 1);
$withPercent = $lastOrder();
check('orderDiscount(): a valid percent code (case-insensitive) reduces the line\'s unit price', $withPercent !== $withoutCode && (int) $orderRow($withPercent)['total'] === 4000 && (int) $orderItems($withPercent)[0]['unit_price'] === 4000);
$redemption = $pdo->query("SELECT * FROM x_coupon_redemption WHERE order_id = {$withPercent}")->fetch();
check('redemption: written once the order has an id, through Modulento\\Core\\Event\\OrderStateChanged - not guessed at modifier time', $redemption !== false
    && (int) $redemption['coupon_id'] === $percentId && (int) $redemption['account_id'] === 1 && (int) $redemption['amount_cents'] === 1000);
check('Coupons::reserve(): used_count went up by exactly one for this redemption', $coupons->find($percentId)['used_count'] === 1);
$close($withPercent);

$post($orderPath, ['package' => '1', 'coupon_code' => 'FLAT500'], 1);
$withFixed = $lastOrder();
check('orderDiscount(): a valid fixed-amount code reduces the line by exactly that amount', (int) $orderRow($withFixed)['total'] === 4500);
$close($withFixed);

$post($orderPath, ['package' => '1', 'extras' => ['0'], 'coupon_code' => 'SAVE20'], 1);
$withTwoLines = $lastOrder();
$items = $orderItems($withTwoLines);
check('orderDiscount(): prorated correctly across two order lines, matching Coupons::apply() directly, nothing negative',
    count($items) === 2 && (int) $items[0]['unit_price'] === 4000 && (int) $items[1]['unit_price'] === 2000
    && array_sum(array_map(fn (array $i) => (int) $i['quantity'] * (int) $i['unit_price'], $items)) === 6000);
$close($withTwoLines);

// Expired / inactive / wrong currency / past the usage limit - each its own message, no order created.
$coupons->create('OLDCODE', 'percent', '10', 'EUR', null, true, null, '2020-01-01 00:00:00', null, null);
$before = $lastOrder();
$r = $post($orderPath, ['package' => '1', 'coupon_code' => 'OLDCODE'], 1);
check('orderDiscount(): an expired code is refused, no order created', $lastOrder() === $before && str_contains($r['body'], 'Dieser Rabattcode ist abgelaufen.'));

$coupons->create('OFFCODE', 'percent', '10', 'EUR', null, false, null, null, null, null);
$r = $post($orderPath, ['package' => '1', 'coupon_code' => 'OFFCODE'], 1);
check('orderDiscount(): an inactive code is refused, no order created', $lastOrder() === $before && str_contains($r['body'], 'Dieser Rabattcode ist nicht mehr aktiv.'));

$coupons->create('USDCODE', 'percent', '10', 'USD', null, true, null, null, null, null);
$r = $post($orderPath, ['package' => '1', 'coupon_code' => 'USDCODE'], 1);
check('orderDiscount(): a currency mismatch is refused, no order created', $lastOrder() === $before && str_contains($r['body'], 'Dieser Rabattcode gilt nicht für diese Währung.'));

$coupons->create('BIGORDER', 'percent', '10', 'EUR', null, true, null, null, null, '1.000,00');
$r = $post($orderPath, ['package' => '1', 'coupon_code' => 'BIGORDER'], 1);
check('orderDiscount(): below the coupon\'s minimum subtotal is refused, no order created', $lastOrder() === $before && str_contains($r['body'], 'Der Bestellwert erreicht den Mindestwert für diesen Rabattcode nicht.'));

$coupons->create('FUTURECODE', 'percent', '10', 'EUR', null, true, '2099-01-01 00:00:00', null, null, null);
$r = $post($orderPath, ['package' => '1', 'coupon_code' => 'FUTURECODE'], 1);
check('orderDiscount(): a not-yet-valid code is refused, no order created', $lastOrder() === $before && str_contains($r['body'], 'Dieser Rabattcode ist noch nicht gültig.'));

// Atomic reservation: max_uses = 1, two attempts, only the first succeeds.
$coupons->create('ONEUSE', 'percent', '10', 'EUR', null, true, null, null, '1', null);
$beforeOneUse = $lastOrder();
$post($orderPath, ['package' => '1', 'coupon_code' => 'ONEUSE'], 1);
$firstUse = $lastOrder();
check('atomic reservation: the first attempt succeeds and the order is discounted', $firstUse !== $beforeOneUse && (int) $orderRow($firstUse)['total'] === 4500);
$close($firstUse);
$r = $post($orderPath, ['package' => '1', 'coupon_code' => 'ONEUSE'], 1);
check('atomic reservation: a second attempt on the same max_uses=1 code is refused, no second order created', $lastOrder() === $firstUse && str_contains($r['body'], 'Dieser Rabattcode wurde schon zu oft eingelöst.'));
check('atomic reservation: used_count never exceeds max_uses', $coupons->find($coupons->findByCode('ONEUSE')['id'])['used_count'] === 1);

// --- Administration: list, create, edit, delete, permission-gated ------------
check('admin: the route needs the permission', $get('/admin/coupons', 1)['status'] === 403);
$r = $get('/admin/coupons', 3);
check('admin: the list renders for an admin, showing a coupon\'s code and usage', $r['status'] === 200 && str_contains($r['body'], 'SAVE20'));

$r = $post('/admin/coupons/new', [
    'code' => 'ADMINNEW', 'type' => 'percent', 'value' => '30', 'currency' => 'eur', 'app_id' => '', 'active' => '1',
    'starts_at' => '', 'expires_at' => '', 'max_uses' => '', 'min_subtotal' => '',
], 3);
$created = $coupons->findByCode('ADMINNEW');
check('admin: create() through the controller normalises and stores the coupon', $created !== null && $created['value'] === 30 && $created['currency'] === 'EUR');

$r = $get('/admin/coupons/' . $created['id'], 3);
check('admin: the edit form renders with the stored values', $r['status'] === 200 && str_contains($r['body'], 'value="ADMINNEW"'));

$post('/admin/coupons/' . $created['id'], [
    'code' => 'ADMINNEW', 'type' => 'fixed', 'value' => '12,00', 'currency' => 'EUR', 'app_id' => 'shop', 'active' => '1',
    'starts_at' => '', 'expires_at' => '', 'max_uses' => '5', 'min_subtotal' => '',
], 3);
$updated = $coupons->find($created['id']);
check('admin: update() through the controller changes type, value and app scope', $updated['type'] === 'fixed' && $updated['value'] === 1200 && $updated['app_id'] === 'shop' && $updated['max_uses'] === 5);

$r = $post('/admin/coupons/' . $created['id'], [
    'code' => 'SAVE20', 'type' => 'percent', 'value' => '10', 'currency' => 'EUR', 'app_id' => '', 'active' => '1',
    'starts_at' => '', 'expires_at' => '', 'max_uses' => '', 'min_subtotal' => '',
], 3);
check('admin: a code already taken by another coupon is refused, with its own message', $coupons->find($created['id'])['code'] === 'ADMINNEW' && str_contains($r['body'], 'Dieser Code ist schon vergeben.'));

$post('/admin/coupons/' . $created['id'] . '/delete', [], 3);
check('admin: delete() through the controller removes it', $coupons->find($created['id']) === null);

// --- Clean up ----------------------------------------------------------------
$remove = function (string $dir) use (&$remove): void {
    foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $entry) {
        is_dir("{$dir}/{$entry}") && !is_link("{$dir}/{$entry}") ? $remove("{$dir}/{$entry}") : unlink("{$dir}/{$entry}");
    }
    rmdir($dir);
};
$remove($uploads);
$remove($testThemes);

// --- Language files ----------------------------------------------------------
$de = require $here . '/lang/de.php';
$en = require $here . '/lang/en.php';
check('lang: de and en have the same keys', array_keys($de) === array_keys($en));
check('lang: every key starts with "coupons."', array_filter(array_keys($de), fn (string $key) => !str_starts_with($key, 'coupons.')) === []);
$used = [];
foreach ([...glob($here . '/templates/{,*/,*/*/}*.twig', GLOB_BRACE), ...glob($here . '/src/*.php')] as $file) {
    preg_match_all("/'(coupons\.[a-z0-9_.]+[a-z0-9])'/", (string) file_get_contents($file), $found);
    $used = array_merge($used, $found[1]);
}
// Not a language key: Extension::PERMISSION's own value, which happens to share the "coupons." prefix convention.
$unknown = array_diff(array_unique($used), array_keys($de), ['coupons.manage']);
check('lang: every key used in templates and code exists: ' . implode(', ', $unknown), $unknown === []);

if ($failed !== []) {
    echo "FAILED:\n";
    foreach ($failed as $label) {
        echo "  - {$label}\n";
    }
    echo count($failed) . " of {$checks} checks failed\n";
    exit(1);
}

echo "OK ({$checks} checks)\n";
