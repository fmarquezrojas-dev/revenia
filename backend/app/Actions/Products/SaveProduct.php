<?php

namespace App\Actions\Products;

use App\Models\Product;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class SaveProduct
{
    public function handle(User $actor, array $data, ?Product $product = null): Product
    {
        try {
            return DB::transaction(function () use ($actor, $data, $product): Product {
                if ($product) {
                    $product = Product::query()
                        ->where('company_id', $actor->company_id)
                        ->whereKey($product->id)
                        ->lockForUpdate()
                        ->firstOrFail();
                    Gate::forUser($actor)->authorize('update', $product);
                } else {
                    Gate::forUser($actor)->authorize('create', Product::class);
                    $product = new Product;
                    $product->company_id = $actor->company_id;
                    $product->is_active = true;
                }

                $product->fill($data);
                $product->save();

                return $product->refresh();
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            if (! str_contains($exception->getMessage(), 'products_company_code_unique')) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'code' => ['Ya existe un producto con este código en tu empresa.'],
            ]);
        }
    }
}
