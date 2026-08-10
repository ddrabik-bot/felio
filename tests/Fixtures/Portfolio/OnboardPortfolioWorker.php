<?php

use App\Models\User;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;

putenv('SESSION_DRIVER=file');
$_ENV['SESSION_DRIVER'] = 'file';
$_SERVER['SESSION_DRIVER'] = 'file';

require __DIR__.'/../../../vendor/autoload.php';

$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();

[$script, $userId, $accountReference, $barrier] = $argv;
$ready = "{$barrier}.".getmypid().'.ready';
touch($ready);

while (file_exists($barrier) === false) {
    usleep(10_000);
}

$user = User::query()->findOrFail($userId);
$session = $app['session']->driver();
$session->start();
$session->put('_token', $csrfToken = bin2hex(random_bytes(20)));
$session->put($app['auth']->guard()->getName(), $user->getAuthIdentifier());
$session->save();

$request = Request::create(
    '/portfolio/onboarding',
    'POST',
    ['accountReference' => $accountReference],
    [$session->getName() => $session->getId()],
    [],
    ['HTTP_X_CSRF_TOKEN' => $csrfToken],
);
$response = $app->make(HttpKernel::class)->handle($request);

if ($response->getStatusCode() !== 302 || parse_url((string) $response->headers->get('Location'), PHP_URL_PATH) !== '/portfolio/imports/xtb') {
    fwrite(STDERR, "Expected authenticated onboarding redirect, got {$response->getStatusCode()} to {$response->headers->get('Location')}.\n");
    exit(1);
}
