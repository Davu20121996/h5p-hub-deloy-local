<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AdminTenantController;
use App\Http\Controllers\DemoPlatformController;
use App\Http\Controllers\H5pController;
use App\Http\Controllers\LtiController;
use App\Http\Controllers\TenantWorkspaceController;
use App\Http\Controllers\WorkspaceController;
use App\Http\Middleware\RequireAdmin;
use Illuminate\Support\Facades\Route;

Route::get('/admin/login', [AdminController::class, 'login'])->name('admin.login');
Route::post('/admin/login', [AdminController::class, 'authenticate'])->middleware('throttle:5,1')->name('admin.authenticate');
Route::middleware(RequireAdmin::class)->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [AdminController::class, 'index'])->name('index');
    Route::post('/logout', [AdminController::class, 'logout'])->name('logout');
    Route::post('/registrations', [AdminController::class, 'register'])->name('register');
    Route::post('/keys', [AdminController::class, 'generateKeys'])->name('keys.generate');
    Route::post('/libraries', [AdminController::class, 'install'])->name('install');
    Route::get('/tenants', [AdminTenantController::class, 'index'])->name('tenants.index');
    Route::get('/tenants/create', [AdminTenantController::class, 'create'])->name('tenants.create');
    Route::post('/tenants', [AdminTenantController::class, 'store'])->name('tenants.store');
    Route::get('/tenants/{tenant}', [AdminTenantController::class, 'show'])->name('tenants.show');
    Route::put('/tenants/{tenant}', [AdminTenantController::class, 'update'])->name('tenants.update');
    Route::post('/tenants/{tenant}/client-credentials', [AdminTenantController::class, 'clientCredentials'])->name('tenants.client-credentials');
    Route::post('/tenants/{tenant}/connections', [AdminTenantController::class, 'connection'])->name('tenants.connections.store');
    Route::put('/tenants/{tenant}/connections/{deployment}', [AdminTenantController::class, 'connection'])->name('tenants.connections.update');
    Route::post('/tenants/{tenant}/users/{identity}/contents', [AdminTenantController::class, 'import'])->name('tenants.contents.import');
    Route::post('/tenants/{tenant}/users/{identity}/client-sync', [AdminTenantController::class, 'syncClientAccount'])->name('tenants.users.client-sync');
    Route::patch('/tenants/{tenant}/users/{identity}/role', [AdminTenantController::class, 'role'])->name('tenants.users.role');
    Route::post('/tenants/{tenant}/users', [AdminTenantController::class, 'addAuthor'])->name('tenants.users.store');
    Route::patch('/tenants/{tenant}/contents/{content}/visibility', [AdminTenantController::class, 'visibility'])->name('tenants.visibility');
    Route::get('/tenants/{tenant}/contents/{content}/preview', [AdminTenantController::class, 'preview'])->name('tenants.preview');
    Route::get('/tenants/{tenant}/contents/{content}/export', [AdminTenantController::class, 'export'])->name('tenants.export');
    Route::get('/tenants/{tenant}/contents/{content}/files/{path?}', [AdminTenantController::class, 'files'])->where('path', '.*')->name('tenants.files');
});

Route::view('/', 'welcome')->name('home');
Route::get('/demo', [DemoPlatformController::class, 'index']);
Route::get('/demo/platform/{site}/jwks', [DemoPlatformController::class, 'jwks']);
Route::get('/demo/platform/{site}/authorize', [DemoPlatformController::class, 'authorize']);
Route::post('/demo/platform/{site}/return', [DemoPlatformController::class, 'returned']);
Route::match(['get', 'post'], '/lti/login', [LtiController::class, 'login'])->middleware('throttle:60,1')->name('lti.login');
Route::post('/lti/launch', [LtiController::class, 'launch'])->middleware('throttle:120,1')->name('lti.launch');
Route::get('/lti/jwks', [LtiController::class, 'jwks'])->name('lti.jwks');
Route::get('/h5p-assets/{kind}/{path?}', [H5pController::class, 'assets'])->where('path', '.*')->name('h5p.assets');

Route::middleware('lti.launch')->prefix('workspace/{launch}')->group(function () {
    Route::get('/', [WorkspaceController::class, 'index'])->name('workspace.index');
    Route::get('/tenant', [TenantWorkspaceController::class, 'index'])->name('workspace.tenant');
    Route::patch('/tenant/users/{identity}/role', [TenantWorkspaceController::class, 'role'])->name('workspace.tenant.role');
    Route::get('/create', [WorkspaceController::class, 'edit'])->name('content.create');
    Route::post('/create', [WorkspaceController::class, 'save'])->name('content.store');
    Route::post('/import', [WorkspaceController::class, 'import'])->middleware('throttle:10,1')->name('content.import');
    Route::get('/content/{uuid}/edit', [WorkspaceController::class, 'edit'])->name('content.edit');
    Route::post('/content/{uuid}/edit', [WorkspaceController::class, 'save'])->name('content.update');
    Route::patch('/content/{uuid}/status', [WorkspaceController::class, 'status'])->name('content.status');
    Route::get('/content/{uuid}/play', [H5pController::class, 'play'])->name('content.play');
    Route::post('/content/{uuid}/result', [H5pController::class, 'result'])->middleware('throttle:30,1')->name('content.result');
    Route::post('/content/{uuid}/retry', [H5pController::class, 'retry'])->name('content.retry');
    Route::get('/content/{uuid}/export', [WorkspaceController::class, 'export'])->name('content.export');
    Route::post('/content/{uuid}/duplicate', [WorkspaceController::class, 'duplicate'])->name('content.duplicate');
    Route::delete('/content/{uuid}', [WorkspaceController::class, 'destroy'])->name('content.destroy');
    Route::post('/deep-link', [LtiController::class, 'complete'])->name('lti.complete');
    Route::match(['get', 'post'], '/ajax/{action?}', [H5pController::class, 'ajax'])->name('h5p.ajax');
    Route::get('/files', [H5pController::class, 'files'])->name('h5p.files.base');
    Route::get('/files/{path}', [H5pController::class, 'files'])->where('path', '.*')->name('h5p.files');
});
