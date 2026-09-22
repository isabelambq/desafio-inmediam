<?php

namespace App\Http\Controllers;

use App\Models\Billing;
use App\Models\CreditCard;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use App\Http\Requests\PayBillingRequest;
use Carbon\Carbon;
use App\Services\AsaasService;
use App\Http\Resources\BillingResource;
use App\Http\Resources\PaymentResource;

class BillingController
{
    public function __construct(
    private AsaasService $asaasService
    ) {
    }

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

    public function show(string $id): JsonResponse|BillingResource
    {
        // Retorna apenas os dados necessários para exibir a cobrança e o plano.
        $billing = Billing::with('plan')->find($id);

        if (!$billing) {
            return response()->json(['error' => 'Cobrança não encontrada'], 404);
        }

        return new BillingResource($billing);
    }

    public function pay(string $id, PayBillingRequest $request): JsonResponse|PaymentResource
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

        $data = $request->validated();

        // Converte a validade do cartão para uma data e impede o uso de cartões expirados.
        [$month, $year] = explode('/', $data['expiry_date']);
        $year = '20' . $year;
        $expiryDate = Carbon::createFromDate($year, $month)->endOfMonth();

        if ($expiryDate->isPast()) {
            return response()->json([
                'error' => 'Cartão expirado'
            ], 422);
        }

        // Reutiliza o cliente já cadastrado na Asaas para evitar a criação de duplicados.
        if ($billing->customer->asaas_customer_id) {
            $asaasCustomerId = $billing->customer->asaas_customer_id;
        } else {
            // Cria o cliente na Asaas somente quando ele ainda não possui um ID cadastrado.
            $customer = $this->asaasService->createCustomer([
                'name' => $billing->customer->name,
                'email' => $billing->customer->email,
                'cpfCnpj' => $billing->customer->document,
                'notificationDisabled' => true,
            ]);

            if (!$customer->successful()) {
                return response()->json([
                    'error' => 'Erro ao criar cliente na Asaas',
                    'asaas_error' => $customer->json()
                ], $customer->status());
            }

            $asaasCustomerId = $customer->json('id');

            $billing->customer->asaas_customer_id = $asaasCustomerId;
            $billing->customer->save();
        }

        $charge = $this->asaasService->createPayment([
            'customer' => $asaasCustomerId,
            'billingType' => 'CREDIT_CARD',
            // Usa o valor da cobrança armazenado no banco, evitando confiar em valor enviado pelo frontend.
            'value' => $billing->amount,
            'dueDate' => $billing->due_date,
        ]);

        if (!$charge->successful()) {
            return response()->json([
                'error' => 'Erro ao criar cobrança na Asaas',
                'asaas_error' => $charge->json()
            ], $charge->status());
        }

        $charge = (object) $charge->json();

        $response = $this->asaasService->payWithCreditCard(
            $charge->id,
            [
                'creditCard' => [
                    'holderName' => $data['card_holder_name'],
                    'number' => $data['card_number'],
                    'expiryMonth' => explode('/', $data['expiry_date'])[0],
                    'expiryYear' => '20' . explode('/', $data['expiry_date'])[1],
                    'ccv' => $data['cvv'],
                ],
                'creditCardHolderInfo' => [
                    'name' => $billing->customer->name,
                    'email' => $billing->customer->email,
                    'cpfCnpj' => $billing->customer->document,
                    'phone' => $billing->customer->phone,
                    'postalCode' => $billing->customer->postal_code,
                    'addressNumber' => $billing->customer->address_number,
                ],
            ]
        );

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
                'card_holder_name' => $data['card_holder_name'],
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

        return new PaymentResource($payment);
    }
}
