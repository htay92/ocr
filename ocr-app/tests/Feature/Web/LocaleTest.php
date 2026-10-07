<?php

use App\Extraction\Data\ExtractedDocument;
use App\Models\Document;
use App\Models\User;
use App\Validation\ReceiptValidator;
use App\Validation\ValidationContext;
use Illuminate\Http\UploadedFile;

beforeEach(fn () => config(['app.locale' => 'ja']));

it('shows Japanese by default', function () {
    $this->get('/login')->assertOk()->assertSee(['ログイン', 'メールアドレス', 'English']);
});

it('switches to English and back, remembered in the session', function () {
    $this->get('/locale/en')->assertRedirect();
    $this->get('/login')->assertSee('Log in')->assertDontSee('ログイン');

    $this->get('/locale/ja');
    $this->get('/login')->assertSee('ログイン');

    $this->get('/locale/fr')->assertNotFound();
});

it('shows statuses, types and upload errors in Japanese', function () {
    $user = User::factory()->create();
    Document::factory()->for($user)->status('needs_review')->create(['type' => 'invoice']);
    $this->actingAs($user);

    $this->get('/documents')->assertSee(['書類一覧', '要確認', '請求書', 'アップロード']);

    $this->followingRedirects()
        ->from('/documents/upload')
        ->post('/documents', ['files' => [UploadedFile::fake()->create('huge.pdf', 20 * 1024 + 1, 'application/pdf')]])
        ->assertSee(['E-UPLOAD-02', 'ファイルは20480 KB以下にしてください。']);
});

it('stores check messages in Japanese', function () {
    app()->setLocale('ja');

    $results = (new ReceiptValidator)->validate(new ValidationContext(new ExtractedDocument(['total' => 1280]), 'receipt'));

    expect($results[0]->message)->toBe('合計金額 ¥1,280')
        ->and($results[1]->message)->toBe('発行日が見つかりません');
});
