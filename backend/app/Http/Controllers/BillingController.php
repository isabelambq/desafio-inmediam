<?php

namespace App\Http\Controllers;

use App\Models\Billing;
use App\Models\CreditCard;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;

class BillingController
{
    public function show(string $id): JsonResponse
    {
        $billing = Billing::with(['plan', 'payments.creditCard'])->find($id);

        if (!$billing) {
            return response()->json(['error' => 'Cobrança não encontrada'], 404);
        }

        return response()->json($billing);
    }

    public function pay(string $id, Request $request): JsonResponse
    {
        $billing = Billing::find($id);

        if (!$billing) {
            return response()->json(['error' => 'Cobrança não encontrada'], 404);
        }

        if ($billing->status === 'paid') {
            return response()->json([
                'error' => 'Pagamento já foi concluído'
            ], 409);
        }

        $request->validate([
            'card_holder_name' => 'required|string|max:255',
            'card_number' => 'required|string|digits_between:13,19',
            'expiry_date' => [
                'required',
                'string',
                'regex:/^(0[1-9]|1[0-2])\/\d{2}$/',
            ],
            'cvv' => 'required|string|digits_between:3,4',
        ]);

        [$month, $year] = explode('/', $request->expiry_date);
        $year = '20' . $year;
        $expiryDate = Carbon::createFromDate($year, $month)->endOfMonth();

        if ($expiryDate->isPast()) {
            return response()->json([
                'error' => 'Cartão expirado'
            ], 422);
        }

        $apiKey = env('ASAAS_API_KEY');
        $baseUrl = "https://sandbox.asaas.com/api/v3";

        try {
            $customer = Http::withHeaders(['access_token' => $apiKey])->post("$baseUrl/customers", [
                'name' => $billing->customer->name,
                'email' => $billing->customer->email,
                'cpfCnpj' => $billing->customer->document,
                'notificationDisabled' => true,
            ]);
       } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Erro ao criar cliente na Asaas'
            ], 502);
        }

        if (!$customer->successful()) {
            return response()->json([
                'error' => 'Erro ao criar cliente na Asaas'
            ], 502);
        }

        $customer = (object) $customer->json();

        $charge = Http::withHeaders(['access_token' => $apiKey])->post("$baseUrl/payments", [
            'customer' => $customer->id,
            'billingType' => 'CREDIT_CARD',
            'value' => $billing->amount,
            'dueDate' => $billing->due_date,
        ]);

        if (!$charge->successful()) {
            return response()->json([
                'error' => 'Erro ao criar cobrança na Asaas'
            ], 502);
        }

        $charge = (object) $charge->json();

        $response = Http::withHeaders(['access_token' => $apiKey])->post("$baseUrl/payments/{$charge->id}/payWithCreditCard", [
            'creditCard' => [
                'holderName' => $request->card_holder_name,
                'number' => $request->card_number,
                'expiryMonth' => explode('/', $request->expiry_date)[0],
                'expiryYear' => '20' . explode('/', $request->expiry_date)[1],
                'ccv' => $request->cvv,
            ],
            'creditCardHolderInfo' => [
                'name' => $billing->customer->name,
                'email' => $billing->customer->email,
                'cpfCnpj' => $billing->customer->document,
                'phone' => '0000000000',
                'postalCode' => '00000000',
                'addressNumber' => '0',
            ],
        ]);

        if (!$response->successful()) {
            return response()->json([
                'error' => 'Erro ao processar pagamento na Asaas'
            ], 502);
        }

        $response = (object) $response->json();

        if ($response->status !== 'CONFIRMED') {
            return response()->json([
                'error' => 'Pagamento não concluído'
            ], 502);
        }

        $credit_card = CreditCard::create([
            'customer_id' => $billing->customer_id,
            'card_holder_name' => $request->card_holder_name,
            'card_last_four' => $response->creditCard['creditCardNumber'],
            'card_brand' => $response->creditCard['creditCardBrand'],
            'card_token' => $response->creditCard['creditCardToken'],
        ]);

        $payment = Payment::create([
            'billing_id' => $billing->id,
            'credit_card_id' => $credit_card->id,
            'amount_paid' => $billing->amount,
            'status' => $response->status,
            'paid_at' => now(),
        ]);

        $billing->status = 'paid';
        $billing->save();

        return response()->json($payment);
    }
}
