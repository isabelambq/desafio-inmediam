# Correções realizadas

## Backend

### 1. Validação dos dados do cartão

**Problema:**  
O endpoint de pagamento não possuía validações suficientes para os dados enviados pelo cliente.

**Correção:**  
Foi adicionada validação para nome do titular, número do cartão, validade e CVV antes das chamadas à API da Asaas.

**Motivo:**  
Evitar o processamento de dados inválidos e impedir chamadas desnecessárias à API externa.

### 2. Validação da validade do cartão

**Problema:**  
Não havia uma verificação para impedir o uso de cartões expirados.

**Correção:**  
A validade informada é convertida para uma data e o pagamento é interrompido quando o cartão está expirado.

**Motivo:**  
Evitar o envio de uma tentativa de pagamento com um cartão que já está vencido.

### 3. Valor da cobrança controlado pelo backend

**Problema:**  
O valor da cobrança era recebido pelo frontend e utilizado na criação da cobrança na Asaas.

**Correção:**  
O valor utilizado na integração passou a ser o valor armazenado na cobrança no banco de dados (`$billing->amount`).

**Motivo:**  
O frontend não deve ser considerado uma fonte confiável para definir o valor de uma cobrança.

### 4. Centralização das configurações da Asaas

**Problema:**  
A API Key e a URL da Asaas estavam sendo obtidas diretamente no Controller, com a URL da API definida no código.

**Correção:**  
As configurações foram centralizadas em `config/services.php` e os valores são definidos pelo `.env`.

**Motivo:**  
Separar configuração de código e facilitar a alteração entre ambientes, como Sandbox e Produção.

### 5. Identificação do cliente na Asaas

**Problema:**  
O modelo de clientes não possuía um campo para armazenar o identificador do cliente criado na Asaas.

**Correção:**  
Foi criada uma nova migration para adicionar o campo `asaas_customer_id` à tabela `customers`. O sistema utiliza esse identificador para reutilizar o cliente já cadastrado na Asaas e somente criar um novo quando necessário.

**Motivo:**  
Evitar a criação de clientes duplicados na Asaas e manter a relação entre o cliente local e o cliente da plataforma de pagamentos.

### 6. Dados adicionais do cliente

**Problema:**  
Os dados necessários para `creditCardHolderInfo` não existiam na estrutura original de clientes.

**Correção:**  
Foram adicionados os campos `phone`, `postal_code` e `address_number` por meio de migration.

**Motivo:**  
Permitir que a integração utilize os dados de contato do cliente armazenados no banco, evitando valores fixos no código.

### 7. Tratamento de erros da integração com a Asaas

**Problema:**  
Respostas diferentes da API da Asaas eram tratadas indiscriminadamente como `502`.

**Correção:**  
As respostas HTTP da Asaas passaram a utilizar o status retornado pela API. Falhas de conexão continuam sendo tratadas como `502`.

**Motivo:**  
Diferenciar uma falha de comunicação de um erro retornado pela própria API.

### 8. Confirmação do pagamento antes da atualização local

**Problema:**  
O pagamento precisava ser confirmado pela Asaas antes de ser registrado como pago no sistema local.

**Correção:**  
A cobrança local só é marcada como `paid` quando a resposta da Asaas possui status `CONFIRMED`.

**Motivo:**  
Evitar que uma cobrança seja marcada como paga localmente sem confirmação do pagamento externo.

### 9. Reutilização de cartão pelo token

**Problema:**  
Um novo registro de cartão era criado a cada pagamento, sem verificar se o cartão já estava cadastrado para aquele cliente.

**Correção:**  
O sistema verifica `customer_id` e `card_token` antes de criar um novo registro.

**Motivo:**  
Evitar registros duplicados do mesmo cartão para o mesmo cliente.

### 10. Redução de dados retornados pela consulta de cobrança

**Problema:**  
O endpoint de consulta carregava também os pagamentos e os dados relacionados ao cartão.

**Correção:**  
O endpoint passou a retornar somente a cobrança e o plano necessário para a tela.

**Motivo:**  
Evitar a exposição de dados que não são necessários para essa operação.

### 11. Endpoint para listagem de cobranças

**Problema:**  
A tela inicial utilizava dados fixos das cobranças.

**Correção:**  
Foi criado o endpoint `GET /api/billing` para retornar as cobranças necessárias para a listagem.

**Motivo:**  
Permitir que a interface utilize os dados reais do backend.

## Frontend

### 1. Mensagem de erro no pagamento

**Problema:**  
O callback `onError` do formulário exibia uma mensagem de sucesso mesmo quando o pagamento falhava.

**Correção:**  
A mensagem foi alterada para informar corretamente que ocorreu um erro no processamento do pagamento.

**Motivo:**  
Garantir que o usuário receba um feedback coerente com o resultado da operação.

### 2. Validação dos dados do formulário

**Problema:**  
Os campos do formulário possuíam pouca validação no frontend.

**Correção:**  
Foram adicionadas validações utilizando Zod para número do cartão, nome do titular, validade e CVV.

**Motivo:**  
Fornecer feedback imediato ao usuário e evitar o envio de dados claramente inválidos ao backend.

### 3. Correção da formatação do valor da cobrança

**Problema:**  
O valor da cobrança recebido pela API era utilizado diretamente na formatação monetária, resultando em `R$ NaN` na interface.

**Correção:**  
O valor recebido da API passou a ser convertido para número antes da formatação.

**Motivo:**  
Garantir que o valor da cobrança seja exibido corretamente na interface.

### 4. Máscara do número do cartão

**Problema:**  
O número do cartão era enviado ao backend com espaços quando informado com formatação, causando falha na validação.

**Correção:**  
Foi adicionada uma máscara visual para o número do cartão e os caracteres não numéricos são removidos antes do envio à API.

**Motivo:**  
Permitir uma experiência de preenchimento mais amigável sem alterar o formato esperado pelo backend.

### 5. Máscara da validade do cartão

**Problema:**  
O usuário poderia informar a validade sem o formato esperado.

**Correção:**  
Foi adicionada uma máscara que formata automaticamente a entrada para `MM/AA`.

**Motivo:**  
Padronizar a entrada do usuário e facilitar o preenchimento do campo.

### 6. Máscara do CVV

**Problema:**  
O campo permitia caracteres que não faziam parte do CVV.

**Correção:**  
O campo passou a aceitar somente números e foi limitado a quatro dígitos.

**Motivo:**  
Garantir uma entrada mais adequada ao formato esperado pelo backend.

### 7. Remoção da formatação do cartão antes do envio

**Problema:**  
O cartão formatado com espaços estava sendo enviado ao backend, causando falha na validação.

**Correção:**  
Os caracteres não numéricos são removidos antes do envio para a API.

**Motivo:**  
Permitir que o usuário utilize a máscara visual sem alterar o formato esperado pelo backend.

### 8. Valor da cobrança não enviado pelo frontend

**Problema:**  
O componente de pagamento recebia e enviava o valor da cobrança pelo frontend, embora o valor confiável estivesse no backend.

**Correção:**  
O `amount` foi removido das propriedades e da requisição do formulário.

**Motivo:**  
Evitar que a interface seja responsável por fornecer um dado que deve ser controlado pelo backend.
 
### 9. Lista de cobranças na tela inicial

**Problema:**  
A tela inicial utilizava dados fixos de clientes, planos e status.

**Correção:**  
A Home passou a consultar `GET /api/billing` e montar a lista dinamicamente com os dados retornados pela API.

**Motivo:**  
Garantir que a tela inicial apresente o estado atual das cobranças e evitar inconsistências entre a Home e a tela de detalhes.

### 10. Atualização da cobrança após o pagamento

**Problema:**  
Após um pagamento bem-sucedido, a tela permanecia com os dados anteriores até que o usuário navegasse para outra página ou atualizasse a página.

**Correção:**  
Após o pagamento ser concluído com sucesso, a consulta da cobrança é invalidada para que os dados sejam buscados novamente e o status seja atualizado na interface.

**Motivo:**  
Garantir que a interface reflita imediatamente o estado atualizado da cobrança após o pagamento.

### 11. Remoção de configuração depreciada do TypeScript

**Problema:**  
O `tsconfig.json` utilizava a opção `baseUrl`, que estava marcada como depreciada pelo TypeScript.

**Correção:**  
A opção `baseUrl` foi removida, mantendo a configuração de `paths` utilizada pelos imports do projeto.

**Motivo:**  
Eliminar o warning de depreciação e manter a configuração do TypeScript compatível com as versões atuais.

## Testes realizados

### Testes automatizados

- `php artisan test` executado com sucesso.
- `npm.cmd run build` executado com sucesso após as alterações no frontend.

### Testes manuais

Foram realizados testes dos principais cenários do fluxo de pagamento:

- Consulta de cobrança inexistente, retornando `404`.
- Tentativa de pagamento de cobrança já paga, retornando `409`.
- Validação de dados inválidos do cartão, retornando `422`.
- Validação de cartão expirado.
- Criação e reutilização de cliente na Asaas.
- Processamento de pagamento utilizando cartão de teste no ambiente Sandbox da Asaas.
- Confirmação do pagamento no backend após retorno `CONFIRMED`.
- Funcionamento do fluxo completo pelo frontend.
- Máscaras de número do cartão, validade e CVV.
- Verificação da lista de cobranças na Home utilizando dados reais da API.
- Atualização automática do status da cobrança após pagamento realizado com sucesso.

## Pontos de melhoria para produção

Alguns pontos foram identificados durante a análise e poderiam ser evoluídos em um ambiente de produção. Eles não foram implementados por aumentarem a complexidade além do necessário para o escopo do desafio.

### Autenticação e autorização

O projeto não possui mecanismo de autenticação de usuários. O endpoint `GET /api/billing`, criado para alimentar a listagem da tela inicial, permanece acessível sem autenticação. A resposta foi limitada aos dados necessários para a listagem, mas, em um cenário de produção, o endpoint deveria exigir autenticação e restringir as cobranças ao usuário/cliente autorizado.

A implementação de autenticação completa não foi incluída por não fazer parte do escopo original do desafio.

### Autorização no PayBillingRequest

O `FormRequest` não possui um método `authorize()` explícito. Como o projeto não possui mecanismo de autenticação/autorização implementado, a autorização padrão do Laravel é mantida.

Em um cenário com usuários autenticados, essa regra deveria validar se o usuário possui permissão para realizar o pagamento da cobrança.

### Idempotência e concorrência

A implementação possui proteção contra o pagamento de cobranças que já estão com status `paid` e contra duplicação de cartões pelo token.

Em um ambiente de produção, seria importante implementar um mecanismo completo de idempotência e controle de concorrência para impedir que múltiplas requisições simultâneas processem a mesma cobrança.

### Concorrência na criação do cliente Asaas

O método responsável por reutilizar ou criar o cliente na Asaas pode sofrer uma condição de corrida em requisições simultâneas. Duas requisições podem verificar ao mesmo tempo que o cliente ainda não possui `asaas_customer_id` e ambas tentarem criar um novo cliente na Asaas.

Em um ambiente de produção, seria necessário implementar uma estratégia de sincronização ou idempotência para garantir que apenas um cliente externo seja criado e associado ao cliente local.

### Persistência do identificador da cobrança na Asaas

O identificador da cobrança criada na Asaas não é persistido localmente antes da confirmação do pagamento.

Em um cenário de produção, seria interessante armazenar o `chargeId` da Asaas para permitir o rastreamento da operação e evitar a criação de uma nova cobrança caso ocorra uma falha após a criação da cobrança externa.

### Tratamento de estados intermediários da Asaas

Atualmente, o pagamento local só é concluído quando a Asaas retorna o status `CONFIRMED`.

Em um cenário de produção, estados intermediários como `AUTHORIZED` ou `PENDING` poderiam ser tratados separadamente, mantendo a cobrança em processamento até que o status definitivo fosse confirmado. Esse acompanhamento poderia ser realizado por consulta posterior à Asaas ou por webhooks.

### Timeout e retry nas integrações externas

As chamadas à API da Asaas poderiam possuir configurações explícitas de timeout e uma estratégia de retry para falhas de comunicação.

Para operações de pagamento, o retry deve ser utilizado com cuidado e associado a um mecanismo de idempotência, evitando que uma nova tentativa resulte em uma cobrança duplicada.

### Validação Luhn do cartão

A validação atual verifica formato e quantidade de dígitos, mas não aplica o algoritmo de Luhn.

Em um cenário de produção, essa validação poderia ser adicionada no backend para rejeitar números de cartão estruturalmente inválidos antes de enviar a requisição à Asaas.

### Estados de carregamento e erro das consultas

O formulário possui estado de processamento e mensagens de sucesso/erro.

Não foram implementados estados específicos de carregamento e erro para as consultas da Home e da tela de cobrança. Em um cenário de produção, essas situações poderiam receber tratamentos específicos na interface.

### Separação adicional de camadas

O projeto já utiliza Services e Form Requests para separar responsabilidades.

Como evolução arquitetural, poderiam ser adicionadas outras camadas, como Repositories, caso a complexidade do sistema justificasse essa abstração. Para o escopo do desafio, essa separação adicional não foi considerada necessária.

### Separação entre regras de negócio e camada HTTP

Os Services utilizam `HttpException` para representar erros durante o processamento.

Em uma arquitetura mais desacoplada, os Services poderiam lançar exceções específicas de domínio, deixando a camada HTTP responsável por transformar essas exceções em códigos e respostas HTTP.

Essa separação não foi implementada por não ser necessária para o escopo do desafio.