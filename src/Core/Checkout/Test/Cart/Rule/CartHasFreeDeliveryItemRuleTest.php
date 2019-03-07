<?php declare(strict_types=1);

namespace Shopware\Core\Checkout\Test\Cart\Rule;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryDate;
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryInformation;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\LineItem\LineItemCollection;
use Shopware\Core\Checkout\Cart\Rule\CartHasDeliveryFreeItemRule;
use Shopware\Core\Checkout\Cart\Rule\CartRuleScope;
use Shopware\Core\Checkout\CheckoutContext;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Struct\Uuid;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;

class CartHasFreeDeliveryItemRuleTest extends TestCase
{
    use IntegrationTestBehaviour;

    /**
     * @var EntityRepositoryInterface
     */
    private $ruleRepository;

    /**
     * @var EntityRepositoryInterface
     */
    private $conditionRepository;

    /**
     * @var Context
     */
    private $context;

    protected function setUp(): void
    {
        $this->ruleRepository = $this->getContainer()->get('rule.repository');
        $this->conditionRepository = $this->getContainer()->get('rule_condition.repository');
        $this->context = Context::createDefaultContext();
    }

    public function testIfShippingFreeLineArticlesAreCaught(): void
    {
        $cart = new Cart('test', Uuid::uuid4()->getHex());

        $lineItemCollection = new LineItemCollection();
        $lineItemCollection->add((new LineItem('dummyWithShippingCost', 'product', 3))->setDeliveryInformation(
                new DeliveryInformation(
                    9999,
                    50.0,
                    new DeliveryDate(new \DateTime('-6h'), new \DateTime('+3 weeks')),
                    new DeliveryDate(new \DateTime('-6h'), new \DateTime('+3 weeks')),
                    false
                )
            ));
        $lineItemCollection->add(
            (new LineItem('dummyNoShippingCost', 'product', 3))->setDeliveryInformation(
                new DeliveryInformation(
                    9999,
                    50.0,
                    new DeliveryDate(new \DateTime('-6h'), new \DateTime('+3 weeks')),
                    new DeliveryDate(new \DateTime('-6h'), new \DateTime('+3 weeks')),
                    true
                )
            )
        );

        $cart->addLineItems($lineItemCollection);

        $rule = new CartHasDeliveryFreeItemRule();

        $match = $rule->match(new CartRuleScope($cart, $this->createMock(CheckoutContext::class)));

        static::assertTrue($match->matches());
    }

    public function testIfRuleIsConsistent(): void
    {
        $ruleId = Uuid::uuid4()->getHex();

        $this->ruleRepository->create(
            [['id' => $ruleId, 'name' => 'Demo rule', 'priority' => 1]],
            Context::createDefaultContext()
        );

        $id = Uuid::uuid4()->getHex();
        $this->conditionRepository->create([
            [
                'id' => $id,
                'type' => (new CartHasDeliveryFreeItemRule())->getName(),
                'ruleId' => $ruleId,
            ],
        ], $this->context);

        static::assertNotNull($this->conditionRepository->search(new Criteria([$id]), $this->context)->get($id));
    }
}
