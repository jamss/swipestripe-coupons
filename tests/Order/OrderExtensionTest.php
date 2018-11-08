<?php
declare(strict_types=1);

namespace SwipeStripe\Coupons\Tests\Order;

use Money\Money;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Omnipay\Model\Payment;
use SwipeStripe\Coupons\Order\OrderCoupon;
use SwipeStripe\Coupons\Order\OrderCouponAddOn;
use SwipeStripe\Coupons\Order\OrderExtension;
use SwipeStripe\Coupons\Order\OrderItem\OrderItemCoupon;
use SwipeStripe\Coupons\Order\OrderItem\OrderItemExtension;
use SwipeStripe\Coupons\Tests\Fixtures\Fixtures;
use SwipeStripe\Coupons\Tests\NeedsSupportedCurrencies;
use SwipeStripe\Coupons\Tests\TestProduct;
use SwipeStripe\Order\Order;
use SwipeStripe\Order\OrderAddOn;
use SwipeStripe\Order\OrderItem\OrderItem;
use SwipeStripe\Order\PaymentExtension;
use SwipeStripe\Order\PaymentStatus;
use SwipeStripe\Order\ViewOrderPage;

/**
 * Class OrderExtensionTest
 * @package SwipeStripe\Coupons\Tests\Order
 */
class OrderExtensionTest extends SapphireTest
{
    use NeedsSupportedCurrencies;

    /**
     * @var array
     */
    protected static $extra_dataobjects = [
        TestProduct::class,
    ];

    /**
     * @var array
     */
    protected static $fixture_file = [
        Fixtures::PRODUCTS,
        Fixtures::ITEM_COUPONS,
        Fixtures::ORDER_COUPONS,
    ];

    /**
     * @var bool
     */
    protected $usesDatabase = true;

    /**
     * @inheritDoc
     */
    public static function setUpBeforeClass()
    {
        parent::setUpBeforeClass();
        static::setupSupportedCurrencies();
    }

    /**
     * @inheritDoc
     */
    protected function setUp()
    {
        parent::setUp();

        ViewOrderPage::singleton()->requireDefaultRecords();
    }

    /**
     *
     */
    public function testApplyCoupon()
    {
        /** @var Order|OrderExtension $order */
        $order = Order::singleton()->createCart();
        $this->assertCount(0, $order->OrderCouponAddOns());

        /** @var OrderCoupon $coupon */
        $coupon = OrderCoupon::get()->first();
        $order->applyCoupon($coupon);

        $this->assertCount(1, $order->OrderCouponAddOns());

        /** @var OrderCouponAddOn $addOn */
        $addOn = $order->OrderCouponAddOns()->first();
        $this->assertInstanceOf(get_class($coupon), $addOn->Coupon());
        $this->assertSame($coupon->ID, $addOn->Coupon()->ID);
    }

    /**
     *
     */
    public function testHasCoupons()
    {
        /** @var Order|OrderExtension $order */
        $order = Order::singleton()->createCart();
        $this->assertFalse($order->hasCoupons());

        /** @var OrderCoupon $coupon */
        $coupon = OrderCoupon::get()->first();
        $order->applyCoupon($coupon);
        $this->assertTrue($order->hasCoupons());
    }

    /**
     *
     */
    public function testHasCouponsDifferentAddOn()
    {
        /** @var Order|OrderExtension $order */
        $order = Order::singleton()->createCart();
        $this->assertFalse($order->hasCoupons());

        $addOn = OrderAddOn::create();
        $addOn->OrderID = $order->ID;
        $addOn->write();
        $this->assertFalse($order->hasCoupons());
    }

    /**
     *
     */
    public function testClearAppliedOrderCoupons()
    {
        /** @var Order|OrderExtension $order */
        $order = Order::singleton()->createCart();

        /** @var OrderCoupon $orderCoupon */
        $orderCoupon = OrderCoupon::get()->first();
        $order->applyCoupon($orderCoupon);

        $this->assertTrue($order->hasCoupons());
        $order->clearAppliedOrderCoupons();
        $this->assertFalse($order->hasCoupons());
    }

    /**
     *
     */
    public function testClearAppliedOrderItemCoupons()
    {
        /** @var Order|OrderExtension $order */
        $order = Order::singleton()->createCart();

        /** @var TestProduct $product */
        $product = $this->objFromFixture(TestProduct::class, 'product');
        $order->addItem($product);

        /** @var OrderItemCoupon $orderItemCoupon */
        $orderItemCoupon = $this->objFromFixture(OrderItemCoupon::class, 'twenty-dollars');
        /** @var OrderItem|OrderItemExtension $orderItem */
        $orderItem = $order->getOrderItem($product);
        $orderItem->applyCoupon($orderItemCoupon);

        $this->assertTrue($order->hasCoupons());
        $order->clearAppliedOrderItemCoupons();
        $this->assertFalse($order->hasCoupons());
    }

    /**
     * @throws \Exception
     */
    public function testPaymentCapturedReducesLimitedUses()
    {
        /** @var Order|OrderExtension $order */
        $order = Order::singleton()->createCart();
        $order->CustomerEmail = 'test@example.org';

        /** @var TestProduct $product */
        $product = $this->objFromFixture(TestProduct::class, 'product');
        /** @var OrderCoupon $coupon */
        $coupon = $this->objFromFixture(OrderCoupon::class, 'limited-uses');
        $order->addItem($product);
        $order->applyCoupon($coupon);
        $order->Lock();

        $this->assertSame(10, $coupon->RemainingUses);
        $this->assertFalse(boolval($order->OrderCouponAddOns()->first()->UseRecorded));
        $payment = $this->addPaymentWithStatus($order, $order->Total()->getMoney(), PaymentStatus::CAPTURED);
        $order->paymentCaptured($payment);

        $coupon = OrderCoupon::get()->byID($coupon->ID);
        $this->assertSame(9, $coupon->RemainingUses);
        $this->assertTrue(boolval($order->OrderCouponAddOns()->first()->UseRecorded));

        // Ensure double-capture won't double-decrement uses
        $order->paymentCaptured($payment);
        $coupon = OrderCoupon::get()->byID($coupon->ID);
        $this->assertSame(9, $coupon->RemainingUses);
    }

    /**
     * @param Order $order
     * @param Money $amount
     * @param string $status
     * @return Payment|PaymentExtension
     */
    protected function addPaymentWithStatus(Order $order, Money $amount, string $status): Payment
    {
        /** @var Payment|PaymentExtension $payment */
        $payment = Payment::create()->init('Dummy',
            $this->getSupportedCurrencies()->formatDecimal($amount),
            $amount->getCurrency()->getCode());
        $payment->Status = $status;
        $payment->OrderID = $order->ID;
        $payment->write();

        return $payment;
    }
}
