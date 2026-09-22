<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\ConnectionException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AsaasService
{
    private string $apiKey;
    private string $baseUrl;

    public function __construct()
    {
        // Carrega as configurações da Asaas centralizadas em config/services.php.
        $this->apiKey = config('services.asaas.api_key');
        $this->baseUrl = config('services.asaas.base_url');
    }

    // Cria um cliente na Asaas.
    public function createCustomer(array $data)
    {
        try {
            return Http::withHeaders([
                'access_token' => $this->apiKey,
            ])->post("{$this->baseUrl}/customers", $data);
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
            return Http::withHeaders([
                'access_token' => $this->apiKey,
            ])->post("{$this->baseUrl}/payments", $data);
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
            return Http::withHeaders([
                'access_token' => $this->apiKey,
            ])->post(
                "{$this->baseUrl}/payments/{$chargeId}/payWithCreditCard",
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