<?php
use PHPUnit\Framework\TestCase;

/** Pure-logic tests: totals, order status rules, delivery -> order status sync. */
class LogicTest extends TestCase
{
    public function testSubtotalAndTotalWithoutDiscount(): void
    {
        $t = calc_totals([['unit_price' => 30, 'quantity' => 3], ['unit_price' => 250.50, 'quantity' => 1]], 0);
        $this->assertEquals(340.50, $t['subtotal']);
        $this->assertEquals(340.50, $t['total']);
    }

    public function testDiscountIsSubtracted(): void
    {
        $t = calc_totals([['unit_price' => 35, 'quantity' => 10]], '20.00');
        $this->assertEquals(350.00, $t['subtotal']);
        $this->assertEquals(20.00, $t['discount']);
        $this->assertEquals(330.00, $t['total']);
    }

    public function testDiscountCannotExceedSubtotalOrBeNegative(): void
    {
        $this->assertEquals(0.0, calc_totals([['unit_price' => 30, 'quantity' => 1]], 999)['total']);
        $this->assertEquals(30.0, calc_totals([['unit_price' => 30, 'quantity' => 1]], -5)['total']);
    }

    public function testNoFloatingPointDrift(): void
    {
        $t = calc_totals([['unit_price' => 0.10, 'quantity' => 3]], 0);
        $this->assertSame(0.3, $t['total']);
    }

    public function testAllowedOrderTransitions(): void
    {
        $this->assertTrue(can_transition('Pending', 'Completed'));
        $this->assertTrue(can_transition('Pending', 'Cancelled'));
        $this->assertTrue(can_transition('Processing', 'Out for Delivery'));
        $this->assertTrue(can_transition('Out for Delivery', 'Completed'));
    }

    public function testCompletedAndCancelledAreFinal(): void
    {
        foreach (ORDER_STATUSES as $to) {
            $this->assertFalse(can_transition('Completed', $to), "Completed -> $to");
            $this->assertFalse(can_transition('Cancelled', $to), "Cancelled -> $to");
        }
        $this->assertFalse(order_is_open('Completed'));
        $this->assertFalse(order_is_open('Cancelled'));
        $this->assertTrue(order_is_open('Pending'));
    }

    public function testUnknownStatusIsRejected(): void
    {
        $this->assertFalse(can_transition('Pending', 'Banana'));
        $this->assertFalse(can_transition('Banana', 'Completed'));
    }

    public function testDeliveryStatusMapsToOrderStatus(): void
    {
        $this->assertSame('Completed', delivery_to_order_status('Delivered'));
        $this->assertSame('Out for Delivery', delivery_to_order_status('Out for Delivery'));
        $this->assertSame('Processing', delivery_to_order_status('Preparing'));
        $this->assertSame('Processing', delivery_to_order_status('Failed'));
        $this->assertNull(delivery_to_order_status('Scheduled'));
    }

    public function testDeliveredIsFinal(): void
    {
        $this->assertSame([], delivery_transitions()['Delivered']);
    }
}
