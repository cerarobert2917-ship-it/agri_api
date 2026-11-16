<?php
require __DIR__ . '/helpers/functions.php';
require __DIR__ . '/config/Database.php';
require __DIR__ . '/middleware/Auth.php';
foreach (glob(__DIR__ . '/controllers/*.php') as $f) require $f;

loadEnv(__DIR__ . '/.env');
if (env('APP_ENV') === 'local') ini_set('display_errors', '0');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// kunin ang path pagkatapos ng /agri_api
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = '/' . trim(preg_replace('#^.*?/agri_api#', '', $path), '/');
$method = $_SERVER['REQUEST_METHOD'];

$routes = [
    'GET /health'            => fn() => respond(true, 'API is running', ['time' => date('c')]),
    'POST /auth/register'    => ['AuthController', 'register'],
    'POST /auth/login'       => ['AuthController', 'login'],
    'POST /auth/google'      => ['AuthController', 'google'],
    'POST /auth/refresh'     => ['AuthController', 'refresh'],
    'POST /auth/logout'      => ['AuthController', 'logout'],
    'GET /auth/me'           => ['AuthController', 'me'],
    'GET /auth/profile'      => ['AuthController', 'profile'],
    'POST /auth/profile'     => ['AuthController', 'updateProfile'],
    'POST /auth/change-password' => ['AuthController', 'changePassword'],
    'GET /categories'        => ['ProductController', 'categories'],
    'GET /products/search'   => ['ProductController', 'search'],
    'GET /stores'            => ['ProductController', 'stores'],
    'GET /catalog'           => ['ProductController', 'catalog'],
    'GET /owner/stores/available' => ['OwnerController', 'availableStores'],
    'POST /owner/store/claim' => ['OwnerController', 'claim'],
    'GET /owner/store'       => ['OwnerController', 'myStore'],
    'POST /owner/store'      => ['OwnerController', 'saveStore'],
    'GET /owner/products'    => ['OwnerController', 'products'],
    'POST /owner/products'   => ['OwnerController', 'saveProduct'],
    'POST /owner/products/update' => ['OwnerController', 'quickUpdate'],
    'POST /owner/products/delete' => ['OwnerController', 'deleteProduct'],
    'GET /admin/claims'      => ['AdminController', 'claims'],
    'POST /admin/claims/review' => ['AdminController', 'review'],
    'GET /admin/stores'      => ['AdminController', 'stores'],
    'POST /admin/stores'     => ['AdminController', 'createStore'],
    'GET /admin/dashboard'   => ['AdminController', 'dashboard'],
    'POST /admin/claims/verify' => ['AdminController', 'verifyClaim'],
    'GET /admin/stores/detail' => ['AdminController', 'storeDetail'],
    'POST /admin/stores/active' => ['AdminController', 'setStoreActive'],
    'GET /admin/users'       => ['AdminController', 'users'],
    'POST /admin/users/status' => ['AdminController', 'setUserStatus'],
    'GET /admin/products'    => ['AdminController', 'products'],
    'POST /admin/products/moderate' => ['AdminController', 'moderateProduct'],
    'GET /admin/analytics'   => ['AdminController', 'analytics'],
    'GET /admin/logs'        => ['AdminController', 'logs'],
    'GET /notifications/changes' => ['NotificationController', 'changes'],
    'POST /notifications/seen' => ['NotificationController', 'seen'],
    'POST /notifications/delete' => ['NotificationController', 'delete'],
    'POST /notifications/clear' => ['NotificationController', 'clear'],
    'POST /ai/chat'          => ['AiController', 'chat'],
];

try {
    $handler = $routes["$method $path"] ?? null;
    if (!$handler) respond(false, 'Endpoint not found.', null, 404);
    is_callable($handler) && !is_array($handler) ? $handler() : (new $handler[0])->{$handler[1]}();
} catch (Throwable $e) {
    error_log($e);
    respond(false, 'A server error occurred.', env('APP_ENV') === 'local' ? $e->getMessage() : null, 500);
}
