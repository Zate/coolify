<?php

use App\Http\Controllers\Api\APIDeploy as Deploy;
use App\Http\Controllers\Api\APIProject as Project;
use App\Http\Controllers\Api\APIServer as Server;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return 'OK';
});
Route::post('/feedback', function (Request $request) {
    $content = $request->input('content');
    $webhook_url = config('coolify.feedback_discord_webhook');
    if ($webhook_url) {
        Http::post($webhook_url, [
            'content' => $content
        ]);
    }
    return response()->json(['message' => 'Feedback sent.'], 200);
});

Route::group([
    'middleware' => ['auth:sanctum'],
    'prefix' => 'v1'
], function () {
    Route::get('/version', function () {
        return response(config('version'));
    });
    Route::get('/deploy', [Deploy::class, 'deploy']);
    Route::get('/servers', [Server::class, 'servers']);
    Route::get('/server/{uuid}', [Server::class, 'server_by_uuid']);
    Route::get('/projects', [Project::class, 'projects']);
    Route::get('/project/{uuid}', [Project::class, 'project_by_uuid']);
    Route::get('/project/{uuid}/{environment_name}', [Project::class, 'environment_details']);
});

Route::get('/{any}', function () {
    return response()->json(['error' => 'Not found.'], 404);
})->where('any', '.*');

// Route::middleware(['throttle:5'])->group(function () {
//     Route::get('/unsubscribe/{token}', function () {
//         try {
//             $token = request()->token;
//             $email = decrypt($token);
//             if (!User::whereEmail($email)->exists()) {
//                 return redirect(RouteServiceProvider::HOME);
//             }
//             if (User::whereEmail($email)->first()->marketing_emails === false) {
//                 return 'You have already unsubscribed from marketing emails.';
//             }
//             User::whereEmail($email)->update(['marketing_emails' => false]);
//             return 'You have been unsubscribed from marketing emails.';
//         } catch (\Throwable $e) {
//             return 'Something went wrong. Please try again or contact support.';
//         }
//     })->name('unsubscribe.marketing.emails');
// });
