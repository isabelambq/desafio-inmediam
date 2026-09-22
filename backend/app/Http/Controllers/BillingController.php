<?php

namespace App\Http\Controllers;

use App\Models\Billing;
use Illuminate\Http\JsonResponse;
use App\Http\Requests\PayBillingRequest;
use App\Http\Resources\BillingResource;
use App\Http\Resources\PaymentResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use App\Services\BillingPaymentService;

class BillingController
{
    public function __construct(
    private BillingPaymentService $billingPaymentService
    ) {
    }

    public function index(): AnonymousResourceCollection
    {
        // Retorna as cobranças paginadas com apenas os dados necessários para a listagem.
        $data = Billing::query()
            ->select([
                'id',
                'plan_id',
                'customer_id',
                'amount',
                'status',
                'due_date',
            ])
            ->with([
                'plan:id,name,description,price,active',
            ])
            ->paginate();

        return BillingResource::collection($data);
    }

    public function show(Billing $billing): JsonResponse|BillingResource
    {
        $billing->load('plan');

        return new BillingResource($billing);
    }

    public function pay(string $id, PayBillingRequest $request): JsonResponse|PaymentResource
    {
        $data = $request->validated();

        $payment = $this->billingPaymentService->pay($id, $data);

        return new PaymentResource($payment);
    }
}
