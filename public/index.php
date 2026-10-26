<?php

use Slim\Factory\AppFactory;
use Dotenv\Dotenv;
use App\Middleware\CorsMiddleware;
use App\Middleware\JwtMiddleware;
use App\Controllers\AuthController;
use App\Controllers\CategoryController;
use App\Controllers\AccountController;
use App\Controllers\TransactionController;
use App\Controllers\DashboardController;
use App\Helpers\ResponseHelper;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;

require __DIR__ . '/../vendor/autoload.php';

// Load environment variables (.env if present, plus system/container environment variables)
if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
}
foreach (getenv() as $key => $val) {
    if (!isset($_ENV[$key])) {
        $_ENV[$key] = $val;
    }
}
foreach ($_SERVER as $key => $val) {
    if (!isset($_ENV[$key]) && is_string($val)) {
        $_ENV[$key] = $val;
    }
}


$app = AppFactory::create();

// Parse JSON request bodies automatically
$app->addBodyParsingMiddleware();

// Routing Middleware
$app->addRoutingMiddleware();

// Custom CORS Middleware
$app->add(new CorsMiddleware());

// Global Error Handler
$errorMiddleware = $app->addErrorMiddleware(true, true, true);
$errorMiddleware->setDefaultErrorHandler(function (
    Request $request,
    Throwable $exception,
    bool $displayErrorDetails,
    bool $logErrors,
    bool $logErrorDetails
) use ($app) {
    $response = $app->getResponseFactory()->createResponse();
    $statusCode = 500;
    
    if (is_numeric($exception->getCode()) && $exception->getCode() >= 400 && $exception->getCode() < 600) {
        $statusCode = (int)$exception->getCode();
    }

    $message = $displayErrorDetails ? $exception->getMessage() : 'An internal server error occurred';
    
    return ResponseHelper::error($response, $message, $statusCode);
});

// Root API Endpoint / Health check
$app->get('/', function (Request $request, Response $response) {
    return ResponseHelper::success($response, [
        'app' => $_ENV['APP_NAME'] ?? 'Expense Tracker API',
        'version' => '1.0.0',
        'status' => 'online',
        'time' => date('Y-m-d H:i:s')
    ], 'API is running');
});

$app->get('/api', function (Request $request, Response $response) {
    return ResponseHelper::success($response, [
        'endpoints' => [
            // Auth
            'POST /api/auth/register' => 'Register user (name, email, password, image)',
            'POST /api/auth/login' => 'User login and get Bearer JWT token',
            'GET /api/auth/me' => '[Auth] Get current user profile',

            // Categories
            'GET /api/categories' => '[Auth] List user categories with live spend & transaction stats',
            'GET /api/categories/{id}' => '[Auth] Get category details and metrics',
            'POST /api/categories' => '[Auth] Create a category (name, iconName, color, budget)',
            'PUT /api/categories/{id}' => '[Auth] Update category (name, iconName, color, budget)',
            'DELETE /api/categories/{id}' => '[Auth] Delete category (supports ?force=true if linked transactions exist)',

            // Accounts
            'GET /api/accounts' => '[Auth] List user accounts',
            'GET /api/accounts/{id}' => '[Auth] Get account details',
            'POST /api/accounts' => '[Auth] Create account (type, amount, title, icons)',
            'PUT /api/accounts/{id}' => '[Auth] Update account',
            'DELETE /api/accounts/{id}' => '[Auth] Delete account',

            // Transactions
            'GET /api/transactions' => '[Auth] List user transactions (supports query filters: type, category_fk, account_fk, search)',
            'GET /api/transactions/{id}' => '[Auth] Get transaction details',
            'POST /api/transactions' => '[Auth] Create transaction (category_fk, account_fk, description, account, date, note, amount, type)',
            'PUT /api/transactions/{id}' => '[Auth] Update transaction',
            'DELETE /api/transactions/{id}' => '[Auth] Delete transaction',

            // Dashboard & Financial Performance Endpoints
            'GET /api/dashboard' => '[Auth] Complete master dashboard (all sections combined)',
            'GET /api/dashboard/financial-performance' => '[Auth] Financial Performance screen data (?timeframe=this_month|last_month|last_3_months|this_year)',
            'GET /api/dashboard/monthly-averages' => '[Auth] Historical monthly averages with prior period trends (?months=6)',
            'GET /api/dashboard/summary' => '[Auth] Top metric cards (Total balance, income, expenses, net savings, savings rate)',
            'GET /api/dashboard/spending-overview' => '[Auth] Monthly income vs expenses comparison chart data (?months=6)',
            'GET /api/dashboard/expense-breakdown' => '[Auth] Category expense breakdown & donut chart data (?month=YYYY-MM or ?timeframe=...)',
            'GET /api/dashboard/recent-transactions' => '[Auth] Recent transactions list with categories and accounts (?limit=5)'
        ]
    ], 'Expense Tracker REST API Documentation');
});

// CORS Preflight Options handler for any route
$app->options('/[{params:.*}]', function (Request $request, Response $response) {
    return $response;
});

// Auth Routes (Public)
$app->post('/api/auth/register', [AuthController::class, 'register']);
$app->post('/api/auth/login', [AuthController::class, 'login']);

// Database Maintenance: Create indexes for fast queries
$app->get('/api/maintenance/indexes', function (Request $request, Response $response) {
    $results = \App\Database::ensureIndexes();
    return ResponseHelper::success($response, $results, 'Indexes verified and created');
});

// Protected API Routes (Requires JWT Authentication)
$app->group('/api', function ($group) {
    // Current User Profile
    $group->get('/auth/me', [AuthController::class, 'me']);

    // Dashboard & Financial Performance Endpoints
    $group->get('/dashboard', [DashboardController::class, 'index']);
    $group->get('/dashboard/financial-performance', [DashboardController::class, 'financialPerformance']);
    $group->get('/dashboard/monthly-averages', [DashboardController::class, 'monthlyAverages']);
    $group->get('/financial-performance', [DashboardController::class, 'financialPerformance']);
    $group->get('/dashboard/summary', [DashboardController::class, 'summary']);
    $group->get('/dashboard/spending-overview', [DashboardController::class, 'spendingOverview']);
    $group->get('/dashboard/expense-breakdown', [DashboardController::class, 'expenseBreakdown']);
    $group->get('/dashboard/recent-transactions', [DashboardController::class, 'recentTransactions']);

    // Category CRUD
    $group->get('/categories', [CategoryController::class, 'index']);
    $group->get('/categories/{id}', [CategoryController::class, 'show']);
    $group->post('/categories', [CategoryController::class, 'create']);
    $group->put('/categories/{id}', [CategoryController::class, 'update']);
    $group->delete('/categories/{id}', [CategoryController::class, 'delete']);

    // Account CRUD
    $group->get('/accounts', [AccountController::class, 'index']);
    $group->get('/accounts/{id}', [AccountController::class, 'show']);
    $group->post('/accounts', [AccountController::class, 'create']);
    $group->put('/accounts/{id}', [AccountController::class, 'update']);
    $group->delete('/accounts/{id}', [AccountController::class, 'delete']);

    // Transaction CRUD
    $group->get('/transactions', [TransactionController::class, 'index']);
    $group->get('/transactions/{id}', [TransactionController::class, 'show']);
    $group->post('/transactions', [TransactionController::class, 'create']);
    $group->put('/transactions/{id}', [TransactionController::class, 'update']);
    $group->delete('/transactions/{id}', [TransactionController::class, 'delete']);
})->add(new JwtMiddleware());

$app->run();
