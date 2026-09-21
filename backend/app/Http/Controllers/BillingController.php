<?php

namespace App\Http\Controllers;

use App\Models\Billing;
use App\Models\CreditCard;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\ConnectionException;
use Carbon\Carbon;

class BillingController
{
    public function index(): JsonResponse
    {
        // Retorna apenas os dados necessários para a listagem das cobranças.
        return response()->json(
            Billing::with([
                'plan:id,name',
                'customer:id,name',
            ])->get([
                'id',
                'plan_id',
                'customer_id',
                'status',
            ])
        );
    }

    public function show(string $id): JsonResponse
    {
        // Retorna apenas os dados necessários para exibir a cobrança e o plano.
        $billing = Billing::with('plan')->find($id);

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

        // Valida os dados do cartão antes de iniciar qualquer chamada à Asaas.
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

        // Converte a validade do cartão para uma data e impede o uso de cartões expirados.
        [$month, $year] = explode('/', $request->expiry_date);
        $year = '20' . $year;
        $expiryDate = Carbon::createFromDate($year, $month)->endOfMonth();

        if ($expiryDate->isPast()) {
            return response()->json([
                'error' => 'Cartão expirado'
            ], 422);
        }

        // Carrega as configurações do Asaas centralizadas em config/services.php.
        $apiKey = config('services.asaas.api_key');
        $baseUrl = config('services.asaas.base_url');

        // Reutiliza o cliente já cadastrado na Asaas para evitar a criação de duplicados.
        if ($billing->customer->asaas_customer_id) {
            $asaasCustomerId = $billing->customer->asaas_customer_id;
        } else {
            // Cria o cliente na Asaas somente quando ele ainda não possui um ID cadastrado.
            // Trata falhas de comunicação com a Asaas separadamente de erros HTTP retornados pela API.
            try {
                $customer = Http::withHeaders(['access_token' => $apiKey])->post("$baseUrl/customers", [
                    'name' => $billing->customer->name,
                    'email' => $billing->customer->email,
                    'cpfCnpj' => $billing->customer->document,
                    'notificationDisabled' => true,
                ]);
            } catch (ConnectionException $e) {
                return response()->json([
                    'error' => 'Não foi possível conectar à Asaas'
                ], 502);
            }

            if (!$customer->successful()) {
                return response()->json([
                    'error' => 'Erro ao criar cliente na Asaas'
                ], $customer->status());
            }

            $asaasCustomerId = $customer->json('id');
            
            $billing->customer->asaas_customer_id = $asaasCustomerId;
            $billing->customer->save();

        }

        try {
            $charge = Http::withHeaders(['access_token' => $apiKey])->post("$baseUrl/payments", [
                'customer' => $asaasCustomerId,
                'billingType' => 'CREDIT_CARD',
                // Usa o valor da cobrança armazenado no banco, evitando confiar em um valor enviado pelo frontend.
                'value' => $billing->amount,
                'dueDate' => $billing->due_date,
            ]);
        } catch (ConnectionException $e) {
            return response()->json([
                'error' => 'Não foi possível conectar à Asaas'
            ], 502);
        }

        if (!$charge->successful()) {
            return response()->json([
                'error' => 'Erro ao criar cobrança na Asaas'
            ], $charge->status());
        }

        $charge = (object) $charge->json();

        try {
            $response = Http::withHeaders(['access_token' => $apiKey])->post("$baseUrl/payments/{$charge->id}/payWithCreditCard", [
                'creditCard' => [
                    'holderName' => $request->card_holder_name,
                    'number' => $request->card_number,
                    'expiryMonth' => explode('/', $request->expiry_date)[0],
                    'expiryYear' => '20' . explode('/', $request->expiry_date)[1],
                    'ccv' => $request->cvv,
                ],
                // Utiliza os dados de contato do cliente armazenados no banco para preencher as informações exigidas pela Asaas.
                'creditCardHolderInfo' => [
                    'name' => $billing->customer->name,
                    'email' => $billing->customer->email,
                    'cpfCnpj' => $billing->customer->document,
                    'phone' => $billing->customer->phone,
                    'postalCode' => $billing->customer->postal_code,
                    'addressNumber' => $billing->customer->address_number,
                ],
            ]);
        } catch (ConnectionException $e) {
            return response()->json([
                'error' => 'Não foi possível conectar à Asaas'
            ], 502);
        }

        if (!$response->successful()) {
            return response()->json([
                'error' => 'Erro ao processar pagamento na Asaas'
            ], $response->status());
        }

        $response = (object) $response->json();

        // Garante que o pagamento foi confirmado pela Asaas antes de registrá-lo como pago localmente.
        if ($response->status !== 'CONFIRMED') {
            return response()->json([
                'error' => 'Pagamento não concluído'
            ], 422);
        }

        // Procura um cartão já cadastrado para o cliente usando o token retornado pela Asaas.
        $credit_card = CreditCard::where('customer_id', $billing->customer_id)
            ->where('card_token', $response->creditCard['creditCardToken'])
            ->first();

        // Cria um novo registro somente quando o cartão ainda não estiver cadastrado.
        if (!$credit_card) {
            $credit_card = CreditCard::create([
                'customer_id' => $billing->customer_id,
                'card_holder_name' => $request->card_holder_name,
                'card_last_four' => $response->creditCard['creditCardNumber'],
                'card_brand' => $response->creditCard['creditCardBrand'],
                'card_token' => $response->creditCard['creditCardToken'],
            ]);
        }

        // Registra o pagamento aprovado no banco de dados local.
        $payment = Payment::create([
            'billing_id' => $billing->id,
            'credit_card_id' => $credit_card->id,
            'amount_paid' => $billing->amount,
            'status' => $response->status,
            'paid_at' => now(),
        ]);

        // Atualiza a cobrança local após a confirmação do pagamento.
        $billing->status = 'paid';
        $billing->save();

        return response()->json($payment);
    }
}
