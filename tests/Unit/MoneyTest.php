<?php

declare(strict_types=1);

namespace Ctpl\Payments\Tests\Unit;

use Ctpl\Payments\Data\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Amounts, and the float that must never reach the wire.
 *
 * The API takes minor-unit integers and rejects decimals rather than
 * rounding them. This class is what stops a developer discovering that
 * from a 422 halfway through a checkout.
 */
final class MoneyTest extends TestCase
{
    #[Test]
    public function minor_units_are_what_the_api_receives(): void
    {
        $this->assertSame(125000, Money::minor(125000)->minorUnits);
        $this->assertSame(['amount' => 125000, 'currency' => 'INR'], Money::minor(125000)->jsonSerialize());
    }

    /**
     * The whole reason `major()` is a string operation.
     *
     * `(int) ($amount * 100)` truncates a binary float that landed just
     * below the value it was meant to be, and WHICH amounts it does that
     * to is not predictable by looking at them: ₹19.99 becomes 1998 paise
     * and ₹1,250.50 does not. A payment platform that is one paisa out on
     * some amounts and not others is one whose settlement never
     * reconciles, and nobody notices until they add up a month.
     *
     * The second half of this test asserts the naive conversion IS wrong
     * for those amounts. That is not decoration — it is what stops
     * somebody reading `major()`, deciding the string handling is
     * overwrought, and replacing it with a multiplication.
     */
    #[Test]
    public function a_float_that_would_round_wrong_does_not(): void
    {
        foreach (['0.29' => 29, '1.15' => 115, '8.70' => 870, '4.35' => 435, '19.99' => 1999] as $major => $minor) {
            $this->assertSame($minor, Money::rupees((float) $major)->minorUnits, $major . ' as a float');
            $this->assertSame($minor, Money::rupees($major)->minorUnits, $major . ' as a string');

            // And the obvious implementation gets it wrong.
            $this->assertSame($minor - 1, (int) ((float) $major * 100), $major . ' truncated');
        }

        $this->assertSame(125050, Money::rupees(1250.50)->minorUnits);
        $this->assertSame(1, Money::rupees(0.01)->minorUnits);
    }

    #[Test]
    public function more_precision_than_the_currency_has_is_refused_not_rounded(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/more than 2 decimal place/');

        Money::rupees('1250.505');
    }

    /** Zero-decimal currencies exist, and assuming 100 charges 100x. */
    #[Test]
    public function a_zero_decimal_currency_is_not_multiplied_by_a_hundred(): void
    {
        $this->assertSame(1250, Money::major(1250, 'JPY')->minorUnits);
        $this->assertSame('1250', Money::major(1250, 'JPY')->toDecimalString());
    }

    #[Test]
    public function three_decimal_currencies_too(): void
    {
        $this->assertSame(1250500, Money::major('1250.500', 'KWD')->minorUnits);
    }

    #[Test]
    public function it_renders_for_a_human(): void
    {
        $this->assertSame('1250.50', Money::minor(125050)->toDecimalString());
        $this->assertSame('INR 1250.50', (string) Money::minor(125050));
        $this->assertSame('0.05', Money::minor(5)->toDecimalString());
    }

    #[Test]
    public function two_currencies_refuse_to_combine(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/different currencies/');

        Money::minor(100, 'INR')->add(Money::minor(100, 'USD'));
    }

    #[Test]
    public function a_negative_amount_is_not_an_amount(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::minor(-1);
    }
}
