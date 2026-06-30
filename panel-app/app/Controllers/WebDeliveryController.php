<?php
declare(strict_types=1);

namespace Controllers;

use Core\WebDeliveryStatus;

/** Read-only server-wide origin delivery diagnostics. */
final class WebDeliveryController extends BaseController
{
    public function index(array $params = []): void
    {
        if (demo_mode()) {
            $status = WebDeliveryStatus::sample();
        } else {
            $result = run_cli('web-delivery:status');
            $status = !empty($result['success'])
                ? WebDeliveryStatus::parse((string) ($result['output'] ?? ''))
                : WebDeliveryStatus::unavailable();
        }

        $this->view('web-delivery/index', ['delivery' => $status]);
    }
}
