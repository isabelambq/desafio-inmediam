<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {   
        // Retorna apenas os dados relevantes do pagamento após sua confirmação.
        return [
            'id' => $this->id,
            'amount_paid' => $this->amount_paid,
            'status' => $this->status,
            'paid_at' => $this->paid_at,
        ];
    }
}