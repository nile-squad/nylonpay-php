<?php

declare(strict_types=1);

namespace NileSquad\NylonPay;

/**
 * Merchant-facing SDK facade with all payment operations.
 */
final class NylonPay
{
    private const UUID_REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    private readonly Transport $transport;

    /** @var array<string, mixed> */
    private readonly array $config;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->transport = new Transport([
            'apiKey' => $config['apiKey'],
            'apiSecret' => $config['apiSecret'],
            'baseUrl' => $config['baseUrl'] ?? Config::DEFAULT_BASE_URL,
            'timeoutMs' => $config['timeoutMs'] ?? Config::DEFAULT_TIMEOUT_MS,
            'maxRetries' => $config['maxRetries'] ?? Config::DEFAULT_MAX_RETRIES,
            'httpClient' => $config['httpClient'] ?? null,
        ]);
    }

    /**
     * @param array<string, mixed> $input
     */
    public function collectPayment(array $input): PaymentInstance
    {
        $payload = $this->prepareCollectPayload($input);
        $payload = $this->applyBeforeHook('beforeCollect', $payload, $this->prepareCollectPayload(...));

        $result = $this->transport->send([
            'action' => Config::SDK_ACTIONS['collectPayment'],
            'payload' => Wire::toWire($payload),
        ]);

        $this->runAfterHook('afterCollect', $result, $payload, $input);

        if ($result->isErr()) {
            return new PaymentInstance(
                ['reference' => $payload['reference'], 'status' => 'pending'],
                array_merge($this->commonDeps(), ['initialError' => ParseError::parse($result->error())]),
            );
        }

        /** @var array<string, mixed> $value */
        $value = $result->value();

        return new PaymentInstance($value, $this->commonDeps());
    }

    /**
     * @param array<string, mixed> $input
     * @return Result<array<string, mixed>, string>
     */
    public function collectPaymentAndResolve(array $input): Result
    {
        $payload = $this->prepareCollectPayload($input);
        $payload = $this->applyBeforeHook('beforeCollect', $payload, $this->prepareCollectPayload(...));

        $result = $this->transport->send([
            'action' => Config::SDK_ACTIONS['collectPaymentAndResolve'],
            'payload' => Wire::toWire($payload),
        ]);

        $this->runAfterHook('afterCollect', $result, $payload, $input);

        if ($result->isErr()) {
            return $result;
        }

        /** @var array<string, mixed> $transaction */
        $transaction = $result->value();

        return $this->continueResolveIfNeeded($transaction);
    }

    /**
     * @param array<string, mixed> $input
     */
    public function makePayout(array $input): PaymentInstance
    {
        $payload = $this->preparePayoutPayload($input);
        $payload = $this->applyBeforeHook('beforePayout', $payload, $this->preparePayoutPayload(...));

        $result = $this->transport->send([
            'action' => Config::SDK_ACTIONS['makePayout'],
            'payload' => Wire::toWire($payload),
        ]);

        $this->runAfterHook('afterPayout', $result, $payload, $input);

        if ($result->isErr()) {
            return new PaymentInstance(
                ['reference' => $payload['reference'], 'status' => 'pending'],
                array_merge($this->commonDeps(), ['initialError' => ParseError::parse($result->error())]),
            );
        }

        /** @var array<string, mixed> $value */
        $value = $result->value();

        return new PaymentInstance($value, $this->commonDeps());
    }

    /**
     * @param array<string, mixed> $input
     * @return Result<array<string, mixed>, string>
     */
    public function makePayoutAndResolve(array $input): Result
    {
        $payload = $this->preparePayoutPayload($input);
        $payload = $this->applyBeforeHook('beforePayout', $payload, $this->preparePayoutPayload(...));

        $result = $this->transport->send([
            'action' => Config::SDK_ACTIONS['makePayoutAndResolve'],
            'payload' => Wire::toWire($payload),
        ]);

        $this->runAfterHook('afterPayout', $result, $payload, $input);

        if ($result->isErr()) {
            return $result;
        }

        /** @var array<string, mixed> $transaction */
        $transaction = $result->value();

        return $this->continueResolveIfNeeded($transaction);
    }

    /**
     * @param array<string, mixed> $input
     * @return Result<array<string, mixed>, string>
     */
    public function getStatus(array $input): Result
    {
        $reference = $input['reference'] ?? null;
        if (!is_string($reference) || trim($reference) === '') {
            throw ParseError::createSdkException(new SdkError('validation', 'reference is required'));
        }

        return $this->transport->send([
            'action' => Config::SDK_ACTIONS['getStatus'],
            'payload' => Wire::toWire(['reference' => $reference]),
        ]);
    }

    /**
     * @param array<string, mixed> $input
     * @return Result<array<string, mixed>, string>
     */
    public function getTransaction(array $input): Result
    {
        $id = $input['id'] ?? null;
        $reference = $input['reference'] ?? null;

        if ((!is_string($id) || $id === '') && (!is_string($reference) || $reference === '')) {
            throw ParseError::createSdkException(new SdkError('validation', 'id or reference is required'));
        }

        return $this->transport->send([
            'action' => Config::SDK_ACTIONS['getTransaction'],
            'payload' => Wire::toWire(array_filter([
                'id' => is_string($id) ? $id : null,
                'reference' => is_string($reference) ? $reference : null,
            ], static fn (mixed $value): bool => $value !== null)),
        ]);
    }

    /**
     * @param array<string, mixed> $input
     * @return Result<array<string, mixed>, string>
     */
    public function listTransactions(array $input = []): Result
    {
        $result = $this->transport->send([
            'action' => Config::SDK_ACTIONS['listTransactions'],
            'payload' => Wire::toWire($input),
        ]);

        if ($result->isErr()) {
            return $result;
        }

        /** @var array<string, mixed> $data */
        $data = $result->value();

        return Result::ok([
            'transactions' => $data['transactions'] ?? [],
            'count' => (int) ($data['count'] ?? 0),
            'limit' => (int) ($data['limit'] ?? 20),
            'offset' => (int) ($data['offset'] ?? 0),
            'tags' => $data['tags'] ?? [],
        ]);
    }

    /**
     * @param array<string, mixed> $options
     * @return Result<array<string, mixed>, string>
     */
    public function getTransactionsByTag(string $tag, array $options = []): Result
    {
        if (trim($tag) === '') {
            throw ParseError::createSdkException(new SdkError('validation', 'tag is required'));
        }

        return $this->listTransactions(array_merge($options, ['tags' => [$tag]]));
    }

    /**
     * @param array<string, mixed> $input
     * @return Result<array<string, mixed>, string>
     */
    public function verifyPhone(array $input): Result
    {
        $phoneNumber = $input['phoneNumber'] ?? null;
        if (!is_string($phoneNumber) || trim($phoneNumber) === '') {
            throw ParseError::createSdkException(new SdkError('validation', 'phoneNumber is required'));
        }

        $normalized = Phone::normalize($phoneNumber);
        if (!Phone::isValidFormat($normalized)) {
            throw ParseError::createSdkException(new SdkError('validation', 'phoneNumber must be a valid phone number'));
        }

        $payload = array_merge($input, ['phoneNumber' => $normalized]);

        return $this->transport->send([
            'action' => Config::SDK_ACTIONS['verifyPhone'],
            'payload' => Wire::toWire($payload),
        ]);
    }

    /**
     * @param array<string, mixed> $input
     * @return Result<array<string, mixed>, string>
     */
    public function createInvoice(array $input): Result
    {
        $amount = $input['amount'] ?? null;
        if (!is_int($amount) || $amount <= 0) {
            throw ParseError::createSdkException(new SdkError('validation', 'amount must be a positive integer'));
        }

        if ($amount < Config::MIN_COLLECTION_AMOUNT) {
            throw ParseError::createSdkException(new SdkError(
                'validation',
                'Collection amount must be at least ' . Config::MIN_COLLECTION_AMOUNT . ' UGX',
            ));
        }

        $customerEmail = $input['customerEmail'] ?? null;
        if (!is_string($customerEmail) || trim($customerEmail) === '') {
            throw ParseError::createSdkException(new SdkError('validation', 'customerEmail is required'));
        }

        $items = $input['items'] ?? null;
        if (is_array($items)) {
            if (count($items) > 50) {
                throw ParseError::createSdkException(new SdkError('validation', 'items must not exceed 50'));
            }

            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $quantity = $item['quantity'] ?? null;
                $unitPrice = $item['unitPrice'] ?? null;

                if (!is_int($quantity) || $quantity <= 0) {
                    throw ParseError::createSdkException(new SdkError('validation', 'item quantity must be a positive integer'));
                }

                if (!is_int($unitPrice) || $unitPrice <= 0) {
                    throw ParseError::createSdkException(new SdkError('validation', 'item unitPrice must be a positive integer'));
                }
            }
        }

        return $this->transport->send([
            'action' => Config::SDK_ACTIONS['createInvoice'],
            'payload' => Wire::toWire($input),
        ]);
    }

    /**
     * @param array{
     *   payload: string|resource,
     *   signature: string,
     *   secret: string,
     *   toleranceSeconds?: int|null
     * } $input
     */
    public function verifyWebhookSignature(array $input): bool
    {
        return VerifyWebhook::verify($input);
    }

    /** @return array<string, mixed> */
    private function commonDeps(): array
    {
        return [
            'fetchStatus' => fn (array $payload): Result => $this->getStatus($payload),
            'fetchTransaction' => fn (array $payload): Result => $this->getTransaction($payload),
            'pollIntervalMs' => $this->config['maxPollIntervalMs'] ?? Config::DEFAULT_MAX_POLL_INTERVAL_MS,
            'maxPollDurationMs' => $this->config['maxPollDurationMs'] ?? null,
            'maxPollAttempts' => $this->config['maxPollAttempts'] ?? null,
            'onDelayed' => $this->config['onDelayed'] ?? 'wait',
        ];
    }

    /** @return Result<array<string, mixed>, string> */
    private function continueResolveIfNeeded(array $transaction): Result
    {
        $status = (string) ($transaction['status'] ?? '');
        if (PollInterval::isTerminal($status)) {
            return Result::ok($transaction);
        }

        return PollUntilTerminal::run(array_merge($this->pollDeps(), [
            'reference' => (string) ($transaction['reference'] ?? ''),
        ]));
    }

    /** @return array<string, mixed> */
    private function pollDeps(): array
    {
        return [
            'fetchStatus' => fn (array $payload): Result => $this->getStatus($payload),
            'fetchTransaction' => fn (array $payload): Result => $this->getTransaction($payload),
            'pollIntervalMs' => $this->config['maxPollIntervalMs'] ?? Config::DEFAULT_MAX_POLL_INTERVAL_MS,
            'maxPollDurationMs' => $this->config['maxPollDurationMs'] ?? null,
            'maxPollAttempts' => $this->config['maxPollAttempts'] ?? null,
            'onDelayed' => $this->config['onDelayed'] ?? 'wait',
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function prepareCollectPayload(array $input): array
    {
        $reference = $this->resolveReference($input['reference'] ?? null);
        $amount = $input['amount'] ?? null;

        if (!is_int($amount) || $amount <= 0) {
            throw ParseError::createSdkException(new SdkError('validation', 'amount must be a positive integer'));
        }

        if ($amount < Config::MIN_COLLECTION_AMOUNT) {
            throw ParseError::createSdkException(new SdkError(
                'validation',
                'Collection amount must be at least ' . Config::MIN_COLLECTION_AMOUNT . ' UGX',
            ));
        }

        $customer = $input['customer'] ?? null;
        if (!is_array($customer)) {
            throw ParseError::createSdkException(new SdkError('validation', 'customer.name is required'));
        }

        $name = $customer['name'] ?? null;
        $phoneNumber = $customer['phoneNumber'] ?? null;

        if (!is_string($name) || trim($name) === '') {
            throw ParseError::createSdkException(new SdkError('validation', 'customer.name is required'));
        }

        if (!is_string($phoneNumber) || trim($phoneNumber) === '') {
            throw ParseError::createSdkException(new SdkError('validation', 'customer.phoneNumber is required'));
        }

        $normalizedPhone = Phone::normalize($phoneNumber);
        if (!Phone::isValidFormat($normalizedPhone)) {
            throw ParseError::createSdkException(new SdkError('validation', 'customer.phoneNumber must be a valid phone number'));
        }

        $description = $input['description'] ?? null;
        if (!is_string($description) || trim($description) === '') {
            throw ParseError::createSdkException(new SdkError('validation', 'description is required'));
        }

        $method = $input['method'] ?? 'mobileMoney';
        if ($method === 'bank' && !isset($input['bank'])) {
            throw ParseError::createSdkException(new SdkError('validation', 'bank details are required when method is "bank"'));
        }

        $payload = $input;
        $payload['reference'] = $reference;
        $payload['customer'] = array_merge($customer, ['phoneNumber' => $normalizedPhone]);

        return $payload;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function preparePayoutPayload(array $input): array
    {
        $reference = $this->resolveReference($input['reference'] ?? null);
        $amount = $input['amount'] ?? null;

        if (!is_int($amount) || $amount <= 0) {
            throw ParseError::createSdkException(new SdkError('validation', 'amount must be a positive integer'));
        }

        if ($amount < Config::MIN_DISBURSEMENT_AMOUNT) {
            throw ParseError::createSdkException(new SdkError(
                'validation',
                'Payout amount must be at least ' . Config::MIN_DISBURSEMENT_AMOUNT . ' UGX',
            ));
        }

        $customer = $input['customer'] ?? null;
        if (!is_array($customer)) {
            throw ParseError::createSdkException(new SdkError('validation', 'customer.name is required'));
        }

        $name = $customer['name'] ?? null;
        $phoneNumber = $customer['phoneNumber'] ?? null;

        if (!is_string($name) || trim($name) === '') {
            throw ParseError::createSdkException(new SdkError('validation', 'customer.name is required'));
        }

        if (!is_string($phoneNumber) || trim($phoneNumber) === '') {
            throw ParseError::createSdkException(new SdkError('validation', 'customer.phoneNumber is required'));
        }

        $normalizedPhone = Phone::normalize($phoneNumber);
        if (!Phone::isValidFormat($normalizedPhone)) {
            throw ParseError::createSdkException(new SdkError('validation', 'customer.phoneNumber must be a valid phone number'));
        }

        $description = $input['description'] ?? null;
        if (!is_string($description) || trim($description) === '') {
            throw ParseError::createSdkException(new SdkError('validation', 'description is required'));
        }

        $destination = $input['destination'] ?? null;
        if (!is_array($destination)) {
            throw ParseError::createSdkException(new SdkError('validation', 'destination.accountHolderName is required'));
        }

        $accountHolderName = $destination['accountHolderName'] ?? null;
        $accountNumber = $destination['accountNumber'] ?? null;

        if (!is_string($accountHolderName) || trim($accountHolderName) === '') {
            throw ParseError::createSdkException(new SdkError('validation', 'destination.accountHolderName is required'));
        }

        if (!is_string($accountNumber) || trim($accountNumber) === '') {
            throw ParseError::createSdkException(new SdkError('validation', 'destination.accountNumber is required'));
        }

        $payload = $input;
        $payload['reference'] = $reference;
        $payload['customer'] = array_merge($customer, ['phoneNumber' => $normalizedPhone]);

        return $payload;
    }

    private function resolveReference(?string $reference): string
    {
        if ($reference === null) {
            return $this->generateReference();
        }

        if (preg_match(self::UUID_REGEX, $reference) !== 1) {
            throw ParseError::createSdkException(new SdkError('validation', 'reference must be a valid UUID'));
        }

        return $reference;
    }

    private function generateReference(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $prepare
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function applyBeforeHook(string $hookName, array $payload, callable $prepare): array
    {
        $hooks = $this->config['hooks'] ?? null;
        if (!is_array($hooks)) {
            return $payload;
        }

        $hook = $hooks[$hookName] ?? null;
        if (!is_array($hook) || ($hook['enabled'] ?? true) === false) {
            return $payload;
        }

        $mutated = $this->runHook($hook, $payload);
        if ($mutated === null) {
            return $payload;
        }

        if (!is_array($mutated)) {
            return $payload;
        }

        if (!isset($mutated['reference'])) {
            $mutated['reference'] = $payload['reference'];
        }

        return $prepare($mutated);
    }

    /**
     * @param array<string, mixed> $hook
     * @param array<string, mixed> $payload
     */
    private function runHook(array $hook, mixed $payload): mixed
    {
        $fn = $hook['fn'] ?? null;
        if (!is_callable($fn)) {
            return null;
        }

        $result = Result::try(static fn () => $fn($payload));
        if ($result->isErr()) {
            $onError = $hook['onError'] ?? null;
            if (is_callable($onError)) {
                Result::try(static fn () => $onError($result->error()));
            }

            return null;
        }

        return $result->value();
    }

    /**
     * @param array<string, mixed> $wirePayload
     * @param array<string, mixed> $rawInput
     */
    private function runAfterHook(string $hookName, Result $result, array $wirePayload, array $rawInput): void
    {
        $hooks = $this->config['hooks'] ?? null;
        if (!is_array($hooks)) {
            return;
        }

        $hook = $hooks[$hookName] ?? null;
        if (!is_array($hook) || ($hook['enabled'] ?? true) === false) {
            return;
        }

        $hookResult = $result->isOk()
            ? Result::ok([
                'reference' => is_array($result->value()) ? ($result->value()['reference'] ?? null) : null,
                'status' => is_array($result->value()) ? ($result->value()['status'] ?? null) : null,
            ])
            : Result::err($result->error());

        $this->runHook($hook, [
            'result' => $hookResult,
            'wire' => $wirePayload,
            'raw' => $rawInput,
        ]);
    }
}
