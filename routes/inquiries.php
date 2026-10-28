<?php

use App\Enums\InquiryAction;
use App\Http\Controllers\ServiceInquiryController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:web')->prefix('cuenta/solicitudes')->name('inquiries.')->group(function () {
    Route::get('/', [ServiceInquiryController::class, 'index'])->name('index');
    Route::get('/recibidas', [ServiceInquiryController::class, 'received'])->name('received');
    Route::get('/servicios/{service}/crear', [ServiceInquiryController::class, 'create'])->whereNumber('service')->name('create');
    Route::post('/servicios/{service}', [ServiceInquiryController::class, 'store'])->whereNumber('service')
        ->middleware('throttle:service-inquiries')->name('store');
    Route::get('/{inquiry}', [ServiceInquiryController::class, 'show'])->whereNumber('inquiry')->name('show');
    foreach (['aceptar' => InquiryAction::Accept, 'rechazar' => InquiryAction::Reject, 'cancelar' => InquiryAction::Cancel, 'finalizar' => InquiryAction::Complete] as $path => $decision) {
        Route::patch('/{inquiry}/'.$path, [ServiceInquiryController::class, 'decide'])->whereNumber('inquiry')
            ->defaults('decision', $decision->value)->name($decision->value);
    }
});
