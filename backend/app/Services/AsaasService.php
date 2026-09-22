<?php
namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AsaasService
{
    private PendingRequest $client;

    public function __construct()
    {
        $this->client = Http::baseUrl(config('services.asaas.base_url'))
            ->withHeaders([
                'access_token' => config('services.asaas.api_key'),
            ]);
    }

    // Cria um cliente na Asaas.
    public function createCustomer(array $data)
    {
        try {
            return $this->client->post('/customers', $data);
        } catch (ConnectionException $e) {
            throw new HttpException(
                502,
                'Não foi possível conectar à Asaas',
                $e
            );
        }
    }

    // Cria uma cobrança na Asaas.
    public function createPayment(array $data)
    {
        try {
            return $this->client->post('/payments', $data);
        } catch (ConnectionException $e) {
            throw new HttpException(
                502,
                'Não foi possível conectar à Asaas',
                $e
            );
        }
    }

    // Processa uma cobrança com cartão de crédito na Asaas.
    public function payWithCreditCard(string $chargeId, array $data)
    {
        try {
            return $this->client->post(
                "/payments/{$chargeId}/payWithCreditCard",
                $data
            );
        } catch (ConnectionException $e) {
            throw new HttpException(
                502,
                'Não foi possível conectar à Asaas',
                $e
            );
        }
    }
}