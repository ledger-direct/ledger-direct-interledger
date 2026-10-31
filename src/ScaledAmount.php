<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Interledger;

use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * An Open Payments amount: an integer of the asset's smallest unit, the
 * asset code, and the scale that turns one into the other. `{"value":
 * "130", "assetCode": "EUR", "assetScale": 2}` is 1.30 EUR.
 *
 * It exists because the two sides of this package count differently. Open
 * Payments counts in unscaled integers; the core's PaymentIntent,
 * SettlementPolicy and every payment page count in decimal strings. Doing
 * that conversion in one place — exactly, over brick/math, never over
 * floats — is what keeps a requested amount and the amount the resource
 * server echoes back from drifting by a cent.
 */
final readonly class ScaledAmount
{
    /** Open Payments allows a scale of 0..255; anything past this is a typo, not a currency. */
    private const MAX_SCALE = 30;

    /**
     * @param string $value the unscaled, non-negative integer as a decimal string
     */
    public function __construct(
        public string $value,
        public string $assetCode,
        public int $assetScale,
    ) {
        if (preg_match('/^(0|[1-9][0-9]*)$/D', $value) !== 1) {
            throw new InvalidArgumentException(
                "An Open Payments amount is a non-negative integer string, got '{$value}'."
            );
        }

        if ($assetCode === '') {
            throw new InvalidArgumentException('An Open Payments amount needs an asset code.');
        }

        if ($assetScale < 0 || $assetScale > self::MAX_SCALE) {
            throw new InvalidArgumentException("Asset scale {$assetScale} is out of range.");
        }
    }

    /**
     * The amount for a decimal string, rounded half-up to the asset's
     * scale — the core's rounding, so a quote lands on the same number
     * here as it would on any other chain.
     *
     * Half-up and not up: the caller then takes decimal() back out of
     * this object for the record, so what is asked for and what is sent
     * to the resource server are the same number by construction. A
     * requested amount rounded differently from the `incomingAmount`
     * would make an order that can never settle.
     */
    public static function fromDecimal(string $decimal, string $assetCode, int $assetScale): self
    {
        try {
            $unscaled = BigDecimal::of($decimal)
                ->toScale($assetScale, self::halfUp())
                ->getUnscaledValue();
        } catch (MathException $exception) {
            throw new InvalidArgumentException(
                "'{$decimal}' is not a usable amount for {$assetCode}: " . $exception->getMessage(),
                0,
                $exception,
            );
        }

        return new self((string) $unscaled, $assetCode, $assetScale);
    }

    /** @param array{value?: mixed, assetCode?: mixed, assetScale?: mixed} $amount */
    public static function fromArray(array $amount): self
    {
        foreach (['value', 'assetCode', 'assetScale'] as $key) {
            if (!isset($amount[$key])) {
                throw new InvalidArgumentException("An Open Payments amount is missing '{$key}'.");
            }
        }

        return new self((string) $amount['value'], (string) $amount['assetCode'], (int) $amount['assetScale']);
    }

    /** The amount as a decimal string at the asset's scale: "1.30", "0.00", "12". */
    public function decimal(): string
    {
        return (string) BigDecimal::ofUnscaledValue(BigInteger::of($this->value), $this->assetScale);
    }

    public function isPositive(): bool
    {
        return $this->value !== '0';
    }

    public function isSameAsset(self $other): bool
    {
        return $this->assetCode === $other->assetCode && $this->assetScale === $other->assetScale;
    }

    /** What the Open Payments request body carries. */
    public function toArray(): array
    {
        return [
            'value' => $this->value,
            'assetCode' => $this->assetCode,
            'assetScale' => $this->assetScale,
        ];
    }

    /**
     * The amount as the PaymentIntent carries it (Lesart A): an
     * IssuedCurrencyAmount whose issuer is the wallet address — the
     * account-holding institution is the issuer of an Interledger
     * balance.
     *
     * @return array{currency: string, value: string, issuer: string}
     */
    public function issuedCurrency(string $issuer): array
    {
        if ($issuer === '') {
            throw new InvalidArgumentException('An Interledger amount needs the wallet address as its issuer.');
        }

        return [
            'currency' => $this->assetCode,
            'value' => $this->decimal(),
            'issuer' => $issuer,
        ];
    }

    /**
     * brick/math renamed its RoundingMode cases in 0.15.0 and kept no
     * alias; resolving the case by name is what lets this package span
     * the same brick/math range the core does. See PriceService for the
     * full reasoning — a library must not pick the shop's brick/math.
     */
    private static function halfUp(): RoundingMode
    {
        static $mode = null;

        if ($mode === null) {
            $name = defined(RoundingMode::class . '::HalfUp') ? 'HalfUp' : 'HALF_UP';
            /** @var RoundingMode $mode */
            $mode = constant(RoundingMode::class . '::' . $name);
        }

        return $mode;
    }
}
