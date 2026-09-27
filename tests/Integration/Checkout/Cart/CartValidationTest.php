<?php

declare(strict_types=1);

namespace Kommandhub\ClickAndPickSW\Tests\Integration\Checkout\Cart;

use Kommandhub\ClickAndPickSW\Checkout\Cart\Error\PickupLocationRequiredCartBlockerError;
use Kommandhub\ClickAndPickSW\Checkout\PickupSelection\PickupContextStorage;
use Kommandhub\ClickAndPickSW\KmhClickAndPickSW;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Content\Product\Aggregate\ProductVisibility\ProductVisibilityDefinition;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Test\TestCaseBase\SalesChannelApiTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextFactory;

#[Group('kernel')]
class CartValidationTest extends TestCase
{
    use IntegrationTestBehaviour;
    use SalesChannelApiTestBehaviour;

    private CartService $cartService;
    private AbstractSalesChannelContextFactory $factory;
    private PickupContextStorage $pickupContextStorage;
    private EntityRepository $productRepository;

    protected function setUp(): void
    {
        $this->cartService = $this->getContainer()->get(CartService::class);
        $this->factory = $this->getContainer()->get(SalesChannelContextFactory::class);
        $this->pickupContextStorage = $this->getContainer()->get(PickupContextStorage::class);
        $this->productRepository = $this->getContainer()->get('product.repository');
    }

    public function testOrderIsBlockedWhenPickupLocationIsMissing(): void
    {
        $salesChannelId = $this->createSalesChannel()['id'];

        // Create the product (and its tax) before the sales-channel context, so
        // the context loads the tax into its registry for price calculation.
        $productId = $this->createProduct(Context::createDefaultContext(), $salesChannelId);

        $context = $this->factory->create(Uuid::randomHex(), $salesChannelId, [
            'shippingMethodId' => KmhClickAndPickSW::SHIPPING_METHOD_ID,
        ]);

        $cart = $this->cartService->getCart($context->getToken(), $context);

        $lineItem = new LineItem($productId, LineItem::PRODUCT_LINE_ITEM_TYPE, $productId, 1);
        $this->cartService->add($cart, $lineItem, $context);

        $cart = $this->cartService->getCart($context->getToken(), $context);

        $errors = $cart->getErrors();
        $hasError = false;

        foreach ($errors as $error) {
            if ($error instanceof PickupLocationRequiredCartBlockerError) {
                $hasError = true;
                break;
            }
        }

        static::assertTrue($hasError, 'Cart should have PickupLocationRequiredCartBlockerError when Click & Pick is selected but no location is chosen.');
    }

    private function createProduct(Context $context, string $salesChannelId): string
    {
        $id = Uuid::randomHex();
        $this->productRepository->create([
            [
                'id' => $id,
                'productNumber' => Uuid::randomHex(),
                'stock' => 1,
                'name' => 'Test Product',
                'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 10, 'net' => 9, 'linked' => false]],
                'tax' => ['name' => 'test', 'taxRate' => 19],
                'visibilities' => [
                    ['salesChannelId' => $salesChannelId, 'visibility' => ProductVisibilityDefinition::VISIBILITY_ALL],
                ],
            ],
        ], $context);

        return $id;
    }
}
