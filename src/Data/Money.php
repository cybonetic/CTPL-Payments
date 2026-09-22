<?php

declare(strict_types=1);

namespace Ctpl\Payments\Data;

use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * An amount, in minor units, that cannot be a float.
 *
 * ------------------------------------------------------------------
 *  WHY THIS EXISTS RATHER THAN AN INT PARAMETER
 * ------------------------------------------------------------------
 *
 * The API takes `amount` as an integer in minor units — 125000 for
 * ₹1,250.00 — and rejects decimals rather than rounding them, because
 * quietly rounding somebody's money is worse than refusing it.
 *
 * An SDK that took `int $amount` would push that rejection to runtime and
 * to the platform, where the error arrives as a 422 halfway through a
 * checkout. `Money::rupees(1250.50)` does the conversion once, correctly,
 * at the point where the developer's intent is still visible; and
 * `Money::minor(125000)` is there for when the amount arrives already in
 * minor units, which is how it should be stored.
 *
 * Immutable, and arithmetic returns new instances. Two amounts in
 * different currencies refuse to combine rather than producing a number
 * that is wrong in a way nobody notices.
 */
final readonly class Money implements JsonSerializable, Stringable
{
    private function __construct(
        public int $minorUnits,
        public string $currency,
    ) {
    }

    /** From minor units — paise, cents. The way an amount should be stored. */
    public static function minor(int $minorUnits, string $currency = 'INR'): self
    {
        if ($minorUnits < 0) {
            throw new InvalidArgumentException('An amount cannot be negative.');
        }

        return new self($minorUnits, self::normaliseCurrency($currency));
    }

    /**
     * From a major-unit figure — 1250.50 for ₹1,250.50.
     *
     * Converted as a STRING operation, not by multiplying a float by 100.
     * `(int) (1250.50 * 100)` is 125049 on a binary float, and a payment
     * platform that is one paisa out on some amounts and not others is one
     * whose settlement never reconciles.
     */
    public static function major(float|int|string $amount, string $currency = 'INR'): self
    {
        $string = is_string($amount) ? trim($amount) : sprintf('%.10F', $amount);

        if (! preg_match('/^(\d+)(?:\.(\d*))?$/', $string, $m)) {
            throw new InvalidArgumentException(sprintf('[%s] is not an amount.', (string) $amount));
        }

        $exponent = self::exponentFor($currency);
        $fraction = rtrim($m[2] ?? '', '0');

        if (strlen($fraction) > $exponent) {
            throw new InvalidArgumentException(sprintf(
                '%s has more than %d decimal place(s), so it is not an amount in %s. '
                . 'Rounding it here would change what the customer is charged.',
                $string,
                $exponent,
                self::normaliseCurrency($currency),
            ));
        }

        return new self(
            (int) ($m[1] . str_pad($fraction, $exponent, '0')),
            self::normaliseCurrency($currency),
        );
    }

    /** ₹ — the common case, named so it reads. */
    public static function rupees(float|int|string $amount): self
    {
        return self::major($amount, 'INR');
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minorUnits + $other->minorUnits, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return self::minor($this->minorUnits - $other->minorUnits, $this->currency);
    }

    public function equals(self $other): bool
    {
        return $this->minorUnits === $other->minorUnits && $this->currency === $other->currency;
    }

    public function isZero(): bool
    {
        return $this->minorUnits === 0;
    }

    /** For display. Never send this back to the API. */
    public function toDecimalString(): string
    {
        $exponent = self::exponentFor($this->currency);

        if ($exponent === 0) {
            return (string) $this->minorUnits;
        }

        $padded = str_pad((string) $this->minorUnits, $exponent + 1, '0', STR_PAD_LEFT);

        return substr($padded, 0, -$exponent) . '.' . substr($padded, -$exponent);
    }

    public function __toString(): string
    {
        return $this->currency . ' ' . $this->toDecimalString();
    }

    /** @return array{amount: int, currency: string} */
    public function jsonSerialize(): array
    {
        return ['amount' => $this->minorUnits, 'currency' => $this->currency];
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException(sprintf(
                'Cannot combine %s and %s. They are different currencies, and a number that ignores '
                . 'that is wrong in a way nobody notices until settlement.',
                $this->currency,
                $other->currency,
            ));
        }
    }

    private static function normaliseCurrency(string $currency): string
    {
        $currency = strtoupper(trim($currency));

        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException(sprintf('[%s] is not an ISO 4217 currency code.', $currency));
        }

        return $currency;
    }

    /**
     * How many minor units make one major unit.
     *
     * Three today, because the platform takes INR and this is where the
     * next one is added rather than in a caller. The zero-decimal ones are
     * listed because assuming 100 everywhere charges a JPY customer a
     * hundred times the intended amount.
     */
    private static function exponentFor(string $currency): int
    {
        return match (self::normaliseCurrency($currency)) {
            'JPY', 'KRW', 'VND' => 0,
            'KWD', 'BHD', 'OMR' => 3,
            default => 2,
        };
    }
}
