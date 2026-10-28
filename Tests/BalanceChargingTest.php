<?php

declare(strict_types=1);

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PayzenMulti\Tests;

use Payzen\Model\PayzenConfigQuery;
use Payzen\Payzen\PayzenField;
use PayzenMulti\PayzenMulti;
use Thelia\Domain\Payment\Enum\OrderPaymentPartState;
use Thelia\Model\ModuleQuery;
use Thelia\Model\Order;
use Thelia\Model\OrderPaymentPart;
use Thelia\Model\OrderStatus;
use Thelia\Module\BalanceChargingPaymentModuleInterface;
use Thelia\Test\IntegrationTestCase;

/**
 * An order of 100 with a pending part of 30 (a gift card, for one) leaves a balance of 70: the instalments of PayzenMulti
 * are cut from 70, not from the total of the order.
 *
 * Boots the shop the module is installed in, on the test database `bin/test-prepare` creates:
 *   vendor/bin/phpunit --bootstrap vendor/autoload.php vendor/thelia/modules/PayzenMulti/Tests
 */
final class BalanceChargingTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!static::getContainer()->has('module.PayzenMulti')) {
            self::markTestSkipped('PayzenMulti is not installed in this shop.');
        }

        PayzenConfigQuery::set('multi_first_payment', '0');
        PayzenConfigQuery::set('multi_number_of_payments', '4');
        PayzenConfigQuery::set('multi_payments_interval', '30');
    }

    public function testThePaymentModuleDeclaresThatItChargesTheBalance(): void
    {
        self::assertInstanceOf(BalanceChargingPaymentModuleInterface::class, static::getContainer()->get('module.PayzenMulti'));
    }

    public function testTheInstalmentsAddUpToTheBalanceOfAnOrderWithAPart(): void
    {
        $fields = $this->parameters($this->orderOf100WithAPartOf30());

        self::assertSame('7000', (string) $fields['vads_amount']->getValue());
        self::assertSame('MULTI:first=1750;count=4;period=30', $fields['vads_payment_config']->getValue());
    }

    public function testTheInstalmentsAddUpToTheWholeOrderWhenItHasNoPart(): void
    {
        $fields = $this->parameters($this->orderOf100());

        self::assertSame('10000', (string) $fields['vads_amount']->getValue());
        self::assertSame('MULTI:first=2500;count=4;period=30', $fields['vads_payment_config']->getValue());
    }

    /**
     * @return array<string, PayzenField>
     */
    private function parameters(Order $order): array
    {
        $module = static::getContainer()->get('module.PayzenMulti');
        self::assertInstanceOf(PayzenMulti::class, $module);

        return (new \ReflectionMethod($module, 'getPayzenParameters'))->invoke($module, $order, 'MULTI', '');
    }

    private function orderOf100(): Order
    {
        $fixtures = $this->createFixtureFactory();
        $customer = $fixtures->customer($fixtures->customerTitle());
        $fixtures->address($customer, overrides: ['label' => 'Home'])->setIsDefault(1)->save();
        $order = $fixtures->order($customer, ['statusCode' => OrderStatus::CODE_NOT_PAID, 'paymentModuleCode' => 'PayzenMulti']);
        $fixtures->orderProduct($order, ['price' => '100.000000', 'promoPrice' => '100.000000']);

        return $order;
    }

    /**
     * The parts as the checkout writes them: the instant part first, then the primary part of the provider for the rest.
     */
    private function orderOf100WithAPartOf30(): Order
    {
        $order = $this->orderOf100();

        $this->part($order, 'Cheque', '30.000000', false, 0);
        $this->part($order, 'PayzenMulti', '70.000000', true, 1);

        return $order;
    }

    private function part(Order $order, string $moduleCode, string $amount, bool $isPrimary, int $position): void
    {
        $module = ModuleQuery::create()->findOneByCode($moduleCode);
        self::assertNotNull($module, \sprintf('The test database has no %s module.', $moduleCode));

        (new OrderPaymentPart())
            ->setOrderId($order->getId())
            ->setPaymentModuleId($module->getId())
            ->setAmount($amount)
            ->setState(OrderPaymentPartState::PENDING->value)
            ->setIsPrimary($isPrimary)
            ->setPosition($position)
            ->save();
    }
}
