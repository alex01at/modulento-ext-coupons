<?php

declare(strict_types=1);

namespace Modulento\Coupons;

use Modulento\Core\Controller\Controller;
use Modulento\Core\Support\Money;
use Modulento\Core\Support\Session;

/** Coupon CRUD in the administration: list, create, edit, delete. */
final class AdminController extends Controller
{
    public function index(array $params): void
    {
        $this->render('@coupons/admin/index.twig', [
            'coupons' => Services::coupons($this->app)->all(),
            'apps' => $this->appChoices(),
        ]);
    }

    public function edit(array $params): void
    {
        $coupon = isset($params['id']) ? Services::coupons($this->app)->find((int) $params['id']) : null;
        if (isset($params['id']) && $coupon === null) {
            $this->notFound();
            return;
        }

        $this->renderForm($coupon);
    }

    public function save(array $params): void
    {
        $app = $this->app;
        $id = isset($params['id']) ? (int) $params['id'] : null;
        $coupons = Services::coupons($app);
        $coupon = $id !== null ? $coupons->find($id) : null;
        if ($id !== null && $coupon === null) {
            $this->notFound();
            return;
        }

        $appId = $this->postedAppId();
        $startsAt = trim((string) ($_POST['starts_at'] ?? '')) !== '' ? $_POST['starts_at'] . ' 00:00:00' : null;
        $expiresAt = trim((string) ($_POST['expires_at'] ?? '')) !== '' ? $_POST['expires_at'] . ' 23:59:59' : null;
        $code = (string) ($_POST['code'] ?? '');
        $type = (string) ($_POST['type'] ?? '');
        $value = (string) ($_POST['value'] ?? '');
        $currency = (string) ($_POST['currency'] ?? '');
        $active = isset($_POST['active']);
        $maxUses = (string) ($_POST['max_uses'] ?? '');
        $minSubtotal = (string) ($_POST['min_subtotal'] ?? '');

        $problem = $id === null
            ? $coupons->create($code, $type, $value, $currency, $appId, $active, $startsAt, $expiresAt, $maxUses, $minSubtotal)
            : $coupons->update($id, $code, $type, $value, $currency, $appId, $active, $startsAt, $expiresAt, $maxUses, $minSubtotal);

        if ($problem !== null) {
            $this->renderForm($coupon, [$this->trans($problem)], $_POST);
            return;
        }

        // Never the coupon's own id: admin_log.target_id references account
        // (id), not an arbitrary other table's row - same reasoning Abos's
        // own AdminController::createPlan() already follows.
        $app->adminLog->record($app->auth->account()['id'], $id === null ? 'coupons_create' : 'coupons_update', null, mb_strtoupper(trim($code)));
        Session::flash('success', $this->trans($id === null ? 'coupons.admin.created' : 'coupons.admin.saved'));
        $this->redirect('/admin/coupons');
    }

    public function delete(array $params): void
    {
        $app = $this->app;
        $id = (int) $params['id'];
        Services::coupons($app)->delete($id);
        $app->adminLog->record($app->auth->account()['id'], 'coupons_delete', null, (string) $id);
        Session::flash('success', $this->trans('coupons.admin.deleted'));
        $this->redirect('/admin/coupons');
    }

    /**
     * @param array<string, mixed>|null $typed the form as sent ($_POST), after a failed validation
     */
    private function renderForm(?array $coupon, array $errors = [], ?array $typed = null): void
    {
        $locale = $this->app->translator->locale();

        if ($typed !== null) {
            $values = [
                'code' => (string) ($typed['code'] ?? ''),
                'type' => (string) ($typed['type'] ?? 'percent'),
                'value' => (string) ($typed['value'] ?? ''),
                'currency' => (string) ($typed['currency'] ?? ''),
                'app_id' => (string) ($typed['app_id'] ?? '') !== '' ? $typed['app_id'] : null,
                'active' => isset($typed['active']),
                'starts_at' => (string) ($typed['starts_at'] ?? ''),
                'expires_at' => (string) ($typed['expires_at'] ?? ''),
                'max_uses' => (string) ($typed['max_uses'] ?? ''),
                'min_subtotal' => (string) ($typed['min_subtotal'] ?? ''),
            ];
        } elseif ($coupon !== null) {
            $values = [
                'code' => $coupon['code'],
                'type' => $coupon['type'],
                'value' => $coupon['type'] === 'percent' ? (string) $coupon['value'] : Money::input($coupon['value'], $locale),
                'currency' => $coupon['currency'],
                'app_id' => $coupon['app_id'],
                'active' => $coupon['active'],
                'starts_at' => $coupon['starts_at'] !== null ? substr($coupon['starts_at'], 0, 10) : '',
                'expires_at' => $coupon['expires_at'] !== null ? substr($coupon['expires_at'], 0, 10) : '',
                'max_uses' => $coupon['max_uses'] !== null ? (string) $coupon['max_uses'] : '',
                'min_subtotal' => $coupon['min_subtotal_cents'] !== null ? Money::input($coupon['min_subtotal_cents'], $locale) : '',
            ];
        } else {
            $values = [
                'code' => '', 'type' => 'percent', 'value' => '', 'currency' => mb_strtoupper($this->app->offers->currency()),
                'app_id' => null, 'active' => true, 'starts_at' => '', 'expires_at' => '', 'max_uses' => '', 'min_subtotal' => '',
            ];
        }

        $this->render('@coupons/admin/edit.twig', [
            'coupon' => $coupon,
            'values' => $values,
            'errors' => $errors,
            'apps' => $this->appChoices(),
        ]);
    }

    /** @return array<string, string> app-extension id => name, every discovered "app"-type manifest, sorted - the picker a coupon is scoped from (same convention Abos's plan editor already uses). */
    private function appChoices(): array
    {
        $choices = [];
        foreach ($this->app->extensions->discover() as $id => $manifest) {
            if ($manifest->type === 'app') {
                $choices[$id] = $manifest->name;
            }
        }

        return $choices;
    }

    /** The app a coupon form posted, kept only if it names a real "app"-type extension. */
    private function postedAppId(): ?string
    {
        $appId = (string) ($_POST['app_id'] ?? '');

        return $appId !== '' && isset($this->appChoices()[$appId]) ? $appId : null;
    }

    private function notFound(): void
    {
        http_response_code(404);
        $this->render('error.twig', ['status' => 404, 'message_key' => 'core.error.not_found']);
    }
}
