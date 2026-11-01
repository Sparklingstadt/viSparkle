<?php

use App\Models\User;
use App\Services\Legal\LegalDocuments;
use Inertia\Testing\AssertableInertia as Assert;

function legalConfig(array $overrides = []): void
{
    config(['legal' => array_merge([
        'seller_name' => '山田 太郎',
        'responsible_person' => '山田 太郎',
        'address' => '東京都千代田区1-1-1',
        'phone' => '03-0000-0000',
        'email' => 'legal@example.com',
        'disclose_on_request' => false,
    ], $overrides)]);
}

it('renders the tokushoho page with the operator details', function () {
    legalConfig();

    $this->get(route('legal.tokushoho'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('legal/tokushoho')
            ->where('ready', true)
            ->where('seller.seller_name', '山田 太郎')
            ->where('seller.responsible_person', '山田 太郎')
            ->where('seller.address', '東京都千代田区1-1-1')
            ->where('seller.phone', '03-0000-0000')
            ->where('seller.email', 'legal@example.com'));
});

it('shows the disclose-on-request wording for an empty address and phone', function () {
    legalConfig(['address' => '', 'phone' => null, 'disclose_on_request' => true]);

    $this->get(route('legal.tokushoho'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('ready', true)
            ->where('seller.address', '請求があれば遅滞なく開示します。')
            ->where('seller.phone', '請求があれば遅滞なく開示します。'));
});

it('still renders the tokushoho page when the details are not ready', function () {
    legalConfig(['seller_name' => '', 'address' => '', 'disclose_on_request' => false]);

    $this->get(route('legal.tokushoho'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('ready', false)
            ->where('seller.seller_name', null)
            ->where('seller.address', null));
});

it('renders the terms and the privacy policy with their version', function (string $route, string $name, string $title, string $text) {
    $this->get(route($route))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('legal/document')
            ->where('title', $title)
            ->where('version', '2026-11-01')
            ->where('html', fn (string $html) => str_contains($html, $text)
                && ! str_contains($html, '公開前に専門家の確認を受けること')
                && ! str_contains($html, 'version:')));

    expect(LegalDocuments::version($name))->toBe('2026-11-01');
})->with([
    'terms' => ['legal.terms', 'terms', '利用規約', 'プラットフォーム手数料'],
    'privacy' => ['legal.privacy', 'privacy', 'プライバシーポリシー', 'Stripe'],
]);

it('puts the privacy contact address from config into the policy', function () {
    legalConfig(['email' => 'privacy@example.com']);

    $this->get(route('legal.privacy'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('html', fn (string $html) => str_contains($html, 'privacy@example.com')));
});

it('exposes the terms version', function () {
    expect(LegalDocuments::termsVersion())->toBe('2026-11-01');
});

it('serves the legal pages to guests and members alike', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('legal.terms'))
        ->assertOk();
});

it('is ready only when the required details are filled', function (array $overrides, bool $ready) {
    legalConfig($overrides);

    expect(LegalDocuments::ready())->toBe($ready);
})->with([
    'all filled' => [[], true],
    'no seller name' => [['seller_name' => ''], false],
    'no responsible person' => [['responsible_person' => null], false],
    'no email' => [['email' => ''], false],
    'no address' => [['address' => ''], false],
    'no phone' => [['phone' => null], false],
    'no address, disclosed on request' => [['address' => '', 'disclose_on_request' => true], true],
    'no address or phone, disclosed on request' => [['address' => '', 'phone' => '', 'disclose_on_request' => true], true],
    'no email, disclosed on request' => [['email' => '', 'disclose_on_request' => true], false],
    'whitespace seller name' => [['seller_name' => '  '], false],
]);
