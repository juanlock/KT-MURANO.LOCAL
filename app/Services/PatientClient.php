<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Patient;
use Illuminate\Support\Facades\DB;

/**
 * A patient IS a customer (user's rule, 2026-10-21): every patient has its
 * clients row (patients.client_id), so the patient shows in People ->
 * Customers and its hospital bills are that customer's sales.
 *
 * Matching follows the customer identity rule: the same email in the same
 * company = the same customer; never by phone, never across companies, never
 * the company's Walk-in / default customer. No email (or no match) = a new
 * customer, with its customer login like any customer (LinkedUserService).
 * Name / phone / email / address changes on the patient are copied to its
 * customer.
 */
class PatientClient
{
    public static function ensure(Patient $patient): ?int
    {
        $companyId = (int) $patient->company_id;
        if (! $companyId) {
            return null;
        }

        $client = $patient->client_id ? Client::whereNull('deleted_at')->find($patient->client_id) : null;
        if ($client && (int) $client->company_id !== $companyId) {
            $client = null; // never another company's customer
        }

        $defaults = array_filter(array_map('intval', (array) DB::table('settings')->where('company_id', $companyId)
            ->whereNull('deleted_at')->first(['client_id', 'booking_default_client_id'])));

        if (! $client && $patient->email) {
            $client = Client::whereNull('deleted_at')->where('company_id', $companyId)
                ->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($patient->email))])
                ->when($defaults, fn ($q) => $q->whereNotIn('id', $defaults))
                ->orderBy('id')->first();
        }

        $data = array_filter([
            'name' => $patient->name,
            'phone' => $patient->phone,
            'email' => $patient->email,
            'adresse' => $patient->address,
            'city' => $patient->city,
        ], fn ($v) => $v !== null && $v !== '');

        if (! $client) {
            $client = Client::create($data + [
                'company_id' => $companyId,
                'code' => ((int) DB::table('clients')->max('code')) + 1,
                'opening_balance' => 0,
                'credit_limit' => 0,
            ]);
            try {
                $client->user_id = LinkedUserService::provision($companyId, 'customer', 'Customer', $client->name, $client->email, $client->phone);
                $client->save();
            } catch (\Throwable $e) {
                report($e); // the customer exists; its login can be set in Customers
            }
        } elseif (! in_array((int) $client->id, $defaults, true)) {
            $client->fill($data);
            if ($client->isDirty()) {
                $client->save();
            }
        }

        if ((int) $patient->client_id !== (int) $client->id) {
            Patient::withoutGlobalScope('company')->whereKey($patient->id)->update(['client_id' => $client->id]);
            $patient->client_id = $client->id;
        }

        return (int) $client->id;
    }
}
