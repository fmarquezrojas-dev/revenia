<?php

namespace App\Actions\Customers;

use App\Domain\Customers\RucValidator;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class SaveCustomer
{
    public function handle(User $actor, array $data, ?Customer $customer = null): Customer
    {
        try {
            return DB::transaction(function () use ($actor, $data, $customer): Customer {
                if ($customer) {
                    $customer = Customer::query()
                        ->where('company_id', $actor->company_id)
                        ->whereKey($customer->id)
                        ->lockForUpdate()
                        ->firstOrFail();
                    Gate::forUser($actor)->authorize('update', $customer);
                } else {
                    Gate::forUser($actor)->authorize('create', Customer::class);
                    $customer = new Customer;
                    $customer->company_id = $actor->company_id;
                    $customer->is_active = true;
                }

                $customer->fill($data);
                // A concurrent PATCH may have changed the other half of the document pair.
                if (
                    ($customer->doc_type === 'ruc' && ! RucValidator::isValid($customer->doc_number))
                    || ($customer->doc_type === 'dni' && ! preg_match('/\A[0-9]{8}\z/', $customer->doc_number))
                ) {
                    throw ValidationException::withMessages([
                        'doc_number' => ['El número no es válido para el tipo de documento actual. Revisa el cliente e intenta nuevamente.'],
                    ]);
                }
                $customer->save();

                return $customer->refresh();
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            if (! str_contains($exception->getMessage(), 'customers_company_document_unique')) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'doc_number' => ['Ya existe un cliente con este tipo y número de documento en tu empresa.'],
            ]);
        }
    }
}
