<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Services\Legal\LegalDocuments;
use Inertia\Inertia;
use Inertia\Response;

class LegalController extends Controller
{
    private const DISCLOSE_ON_REQUEST = '請求があれば遅滞なく開示します。';

    public function tokushoho(): Response
    {
        $value = fn (string $key): ?string => trim((string) config("legal.{$key}")) ?: null;
        $disclosable = fn (string $key): ?string => $value($key)
            ?? (config('legal.disclose_on_request') ? self::DISCLOSE_ON_REQUEST : null);

        return Inertia::render('legal/tokushoho', [
            'ready' => LegalDocuments::ready(),
            'seller' => [
                'seller_name' => $value('seller_name'),
                'responsible_person' => $value('responsible_person'),
                'address' => $disclosable('address'),
                'phone' => $disclosable('phone'),
                'email' => $value('email'),
            ],
        ]);
    }

    public function terms(): Response
    {
        return $this->document('terms', '利用規約');
    }

    public function privacy(): Response
    {
        return $this->document('privacy', 'プライバシーポリシー');
    }

    private function document(string $name, string $title): Response
    {
        return Inertia::render('legal/document', [
            'title' => $title,
            'version' => LegalDocuments::version($name),
            'html' => LegalDocuments::html($name),
        ]);
    }
}
