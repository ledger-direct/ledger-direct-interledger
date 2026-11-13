<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Interledger\Client;

use DateTimeImmutable;
use Hardcastle\LedgerDirect\Interledger\Chain;
use Hardcastle\LedgerDirect\Interledger\ReferenceService;
use Hardcastle\LedgerDirect\Interledger\ScaledAmount;
use InvalidArgumentException;

/**
 * One incoming payment: the per-order resource a customer's wallet pays,
 * and the only place the payment's progress is recorded. Its URL is both
 * the payment link and the identity of the payment, which is why the
 * record keeps it as `destination_account` (Lesart A).
 *
 * `incomingAmount` is null when the resource was read without a grant:
 * the Open Payments public view carries `receivedAmount` and nothing
 * else. Everything downstream therefore treats the received amount as the
 * signal and the stored intent as the request.
 */
final readonly class IncomingPayment
{
    /**
     * @param array<string, mixed> $metadata whatever was sent when it was created
     */
    public function __construct(
        public string $url,
        public ?ScaledAmount $receivedAmount,
        public ?ScaledAmount $incomingAmount = null,
        public bool $completed = false,
        public ?DateTimeImmutable $expiresAt = null,
        public array $metadata = [],
        public string $walletAddress = '',
    ) {
        if ($url === '') {
            throw new InvalidArgumentException('An incoming payment is identified by its URL.');
        }
    }

    /** The id at the end of the URL — what the record carries as `hash`. */
    public function paymentId(): string
    {
        return Chain::paymentIdFrom($this->url);
    }

    /** Whether anything at all has arrived. */
    public function hasReceived(): bool
    {
        return $this->receivedAmount !== null && $this->receivedAmount->isPositive();
    }

    /** The merchant's reference, read back out of the metadata; null when it carries none. */
    public function reference(): ?int
    {
        $externalRef = $this->metadata['externalRef'] ?? null;

        return is_string($externalRef) ? ReferenceService::referenceFrom($externalRef) : null;
    }
}
