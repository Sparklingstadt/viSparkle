<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ReportTarget;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateProductVisibilityRequest;
use App\Models\AdminAction;
use App\Models\Product;
use App\Models\Report;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ProductModerationController extends Controller
{
    public function show(Product $product): Response
    {
        $product->load('creator');

        $reports = Report::query()
            ->with('reporter')
            ->where('target_type', ReportTarget::Product)
            ->where('target_id', $product->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Report $report) => [
                'id' => $report->id,
                'reporter_name' => $report->reporter?->name,
                'reason_label' => $report->reason->label(),
                'body' => $report->body,
                'status' => $report->status->value,
                'created_at' => $report->created_at,
            ]);

        return Inertia::render('admin/product', [
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'description' => $product->description,
                'price' => $product->price,
                'stock' => $product->stock,
                'is_published' => $product->is_published,
                'hidden' => $product->hidden_at !== null,
                'hidden_at' => $product->hidden_at,
                'hidden_reason' => $product->hidden_reason,
                'creator' => ['name' => $product->creator->display_name, 'slug' => $product->creator->slug],
                'url' => route('shop.products.show', [$product->creator, $product->id]),
            ],
            'reports' => $reports,
        ]);
    }

    public function update(UpdateProductVisibilityRequest $request, Product $product): RedirectResponse
    {
        $hidden = $request->boolean('hidden');
        $reason = $hidden ? (string) $request->validated('reason') : null;

        DB::transaction(function () use ($request, $product, $hidden, $reason) {
            Product::withoutTimestamps(fn () => $product->forceFill([
                'hidden_at' => $hidden ? ($product->hidden_at ?? now()) : null,
                'hidden_reason' => $reason,
            ])->save());

            AdminAction::record($request->user(), $hidden ? 'product.hidden' : 'product.unhidden', $product, $reason);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => $hidden ? '商品を非公開にしました。' : '商品を公開に戻しました。']);

        return back();
    }
}
