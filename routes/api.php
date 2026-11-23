<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\CheckoutContextController;
use App\Http\Controllers\Api\V1\IdentityController;
use App\Http\Controllers\Api\V1\NotificationsController;
use App\Http\Controllers\Api\V1\OperationsController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\QuotesController;
use App\Http\Controllers\Api\V1\ReviewsController;
use App\Http\Middleware\MobileClient;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:mobile-login')->name('auth.login');
    Route::post('auth/register', [IdentityController::class, 'register'])->middleware('throttle:mobile-identity-request')->name('auth.register');
    Route::post('auth/register/confirm', [IdentityController::class, 'confirm'])->middleware('throttle:mobile-identity-confirm')->name('auth.register.confirm');
    Route::post('auth/forgot-password', [IdentityController::class, 'forgotPassword'])->middleware('throttle:mobile-identity-request')->name('auth.forgot-password');
    Route::post('auth/reset-password', [IdentityController::class, 'resetPassword'])->middleware('throttle:mobile-identity-confirm')->name('auth.reset-password');
    Route::middleware(['auth:sanctum', MobileClient::class])->group(function () {
        Route::middleware('throttle:mobile-private')->group(function () {
            $operations = [
                ['get', 'inquiries', 'inquiries', 'inquiries.index', null],
                ['post', 'services/{service}/inquiries', 'open', 'inquiries.store', 'mobile-inquiry-writes'],
                ['get', 'inquiries/{inquiry}', 'inquiry', 'inquiries.show', null],
                ['patch', 'inquiries/{inquiry}/cancel', 'cancelInquiry', 'inquiries.cancel', 'mobile-inquiry-writes'],
                ['get', 'conversations', 'conversations', 'conversations.index', null],
                ['get', 'inquiries/{inquiry}/messages', 'messages', 'messages.index', null],
                ['post', 'inquiries/{inquiry}/messages', 'send', 'messages.store', 'mobile-message-writes'],
                ['post', 'inquiries/{inquiry}/messages/read', 'read', 'messages.read', 'mobile-read-writes'],
                ['get', 'inquiries/{inquiry}/reservation-options', 'options', 'reservations.options', null],
                ['post', 'inquiries/{inquiry}/reservations', 'propose', 'reservations.store', 'mobile-reservation-writes'],
                ['get', 'reservations', 'reservations', 'reservations.index', null],
                ['get', 'reservations/{reservation}', 'reservation', 'reservations.show', null],
                ['patch', 'reservations/{reservation}/commit', 'commitReservation', 'reservations.commit', 'mobile-reservation-writes'],
                ['patch', 'reservations/{reservation}/cancel', 'cancelReservation', 'reservations.cancel', 'mobile-reservation-writes'],
            ];
            foreach ($operations as [$method, $uri, $action, $name, $limiter]) {
                $route = Route::$method($uri, [OperationsController::class, $action])->name($name)
                    ->where(['inquiry' => '[0-9]+', 'reservation' => '[0-9]+', 'service' => '[0-9]+']);
                if ($limiter) {
                    $route->middleware('throttle:'.$limiter);
                }
            }
        });
        Route::middleware('throttle:mobile-private')->group(function () {
            Route::get('inquiries/{inquiry}/journey', \App\Http\Controllers\Api\V1\ContractingJourneyController::class)->whereNumber('inquiry')->name('inquiries.journey');
            Route::get('inquiries/{inquiry}/quotes', [QuotesController::class, 'index'])->whereNumber('inquiry')->name('quotes.index');
            Route::post('inquiries/{inquiry}/quotes', [QuotesController::class, 'issue'])->whereNumber('inquiry')->middleware('throttle:mobile-quote-writes')->name('quotes.issue');
            Route::get('quotes/{quote}', [QuotesController::class, 'show'])->whereUuid('quote')->name('quotes.show');
            Route::post('quotes/{quote}/accept', [QuotesController::class, 'accept'])->whereUuid('quote')->middleware('throttle:mobile-quote-writes')->name('quotes.accept');
            Route::get('quotes/{quote}/acceptance', [QuotesController::class, 'acceptance'])->whereUuid('quote')->name('quotes.acceptance');
            Route::get('reservations/{reservation}/checkout-context', CheckoutContextController::class)->whereNumber('reservation')->name('reservations.checkout-context');
        });
        Route::get('me', [AuthController::class, 'me'])->name('me');
        Route::middleware('throttle:mobile-private')->group(function () {
            Route::get('reviews', [ReviewsController::class, 'index'])->name('reviews.index');
            Route::get('reviews/{review}', [ReviewsController::class, 'show'])->whereNumber('review')->name('reviews.show');
            Route::post('reservations/{reservation}/review', [ReviewsController::class, 'store'])->whereNumber('reservation')
                ->middleware('throttle:mobile-review-writes')->name('reviews.store');
            Route::patch('reviews/{review}/withdraw', [ReviewsController::class, 'withdraw'])->whereNumber('review')
                ->middleware('throttle:mobile-review-writes')->name('reviews.withdraw');
        });
        Route::middleware('throttle:mobile-private')->group(function () {
            Route::get('me/profile', [ProfileController::class, 'show'])->name('profile.show');
            Route::patch('me/profile', [ProfileController::class, 'update'])->middleware('throttle:mobile-profile-writes')->name('profile.update');
            Route::get('me/photo', [ProfileController::class, 'photo'])->name('profile.photo.show');
            Route::post('me/photo', [ProfileController::class, 'storePhoto'])->middleware('throttle:mobile-profile-writes')->name('profile.photo.store');
        });
        Route::middleware('throttle:mobile-private')->group(function () {
            Route::get('notifications', [NotificationsController::class, 'index'])->name('notifications.index');
            Route::get('notifications/unread-count', [NotificationsController::class, 'unreadCount'])->name('notifications.unread-count');
            Route::post('notifications/{source_type}/{id}/read', [NotificationsController::class, 'read'])->where(['source_type' => 'inquiry|activity', 'id' => '[1-9][0-9]{0,18}'])
                ->middleware('throttle:mobile-read-writes')->name('notifications.read');
        });
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
    });
    Route::middleware('throttle:mobile-catalog')->group(function () {
        Route::get('services', [CatalogController::class, 'index'])->name('services.index');
        Route::get('services/{service}', [CatalogController::class, 'show'])->whereNumber('service')->name('services.show');
        Route::get('services/{service}/availability', [CatalogController::class, 'availability'])->whereNumber('service')->name('services.availability');
        Route::get('services/{service}/reviews', [ReviewsController::class, 'forService'])->whereNumber('service')->name('services.reviews.index');
        Route::get('providers', [CatalogController::class, 'providersIndex'])->name('providers.index');
        Route::get('providers/{provider}', [CatalogController::class, 'provider'])->whereNumber('provider')->name('providers.show');
        Route::get('providers/{provider}/reviews', [ReviewsController::class, 'forProvider'])->whereNumber('provider')->name('providers.reviews.index');
    });
});
