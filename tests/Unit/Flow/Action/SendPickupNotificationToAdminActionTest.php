<?php

declare(strict_types=1);

namespace Kommandhub\ClickAndPickSW\Tests\Unit\Flow\Action;

use Kommandhub\ClickAndPickSW\Entity\OrderPickupLocation\OrderPickupLocationEntity;
use Kommandhub\ClickAndPickSW\Entity\PickupLocation\PickupLocationEntity;
use Kommandhub\ClickAndPickSW\Flow\Action\SendPickupNotificationToAdminAction;
use Kommandhub\ClickAndPickSW\Flow\Aware\OrderPickupLocationAware;
use Kommandhub\ClickAndPickSW\Flow\Aware\PickupLocationAware;
use Kommandhub\ClickAndPickSW\Migration\Migration1760113852PickupReadyMailTemplate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderCustomer\OrderCustomerEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Content\Flow\Dispatching\StorableFlow;
use Shopware\Core\Content\Mail\Service\AbstractMailService;
use Shopware\Core\Content\Mail\Service\MailAttachmentsConfig;
use Shopware\Core\Content\MailTemplate\MailTemplateEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Event\OrderAware;
use Shopware\Core\System\SystemConfig\SystemConfigService;

#[CoversClass(SendPickupNotificationToAdminAction::class)]
class SendPickupNotificationToAdminActionTest extends TestCase
{
    private const ORDER_ID = '0123456789abcdef0123456789abcdef';
    private const SALES_CHANNEL_ID = '11111111111111111111111111111111';
    private const PICKUP_LOCATION_ID = 'fedcba9876543210fedcba9876543210';
    private const CONFIGURED_TEMPLATE_ID = 'c8826ee629d39b4ecf88c984424e8733';

    private AbstractMailService&MockObject $mailService;

    private EntityRepository&MockObject $mailTemplateRepository;

    private SystemConfigService&MockObject $systemConfigService;

    private LoggerInterface&MockObject $logger;

    private SendPickupNotificationToAdminAction $action;

    protected function setUp(): void
    {
        $this->mailService = $this->createMock(AbstractMailService::class);
        $this->mailTemplateRepository = $this->createMock(EntityRepository::class);
        $this->systemConfigService = $this->createMock(SystemConfigService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->action = new SendPickupNotificationToAdminAction(
            $this->mailService,
            $this->mailTemplateRepository,
            $this->systemConfigService,
            $this->logger,
        );
    }

    public function testActionMetadata(): void
    {
        static::assertSame('action.kmh.pickup.notify_admin', SendPickupNotificationToAdminAction::getName());
        static::assertSame([OrderAware::class, PickupLocationAware::class], $this->action->requirements());
    }

    public function testSendsMailToPickupLocationEmailUsingDefaultTemplate(): void
    {
        $order = $this->order();
        $pickupLocation = $this->location(' pickup@shop.test ');
        $template = $this->template();
        $flow = $this->flow($order, $pickupLocation);

        $this->systemConfigService
            ->expects(static::once())
            ->method('get')
            ->with('core.basicInformation.email', self::SALES_CHANNEL_ID)
            ->willReturn(' admin@shop.test ');

        $this->mailTemplateRepository
            ->expects(static::once())
            ->method('search')
            ->with(
                static::callback(static fn (Criteria $criteria): bool => $criteria->getIds() === [
                    Migration1760113852PickupReadyMailTemplate::ADMIN_ORDER_PLACED_TEMPLATE_ID,
                ]),
                $flow->getContext()
            )
            ->willReturn($this->templateResult($template));

        $this->mailService
            ->expects(static::once())
            ->method('send')
            ->with(
                static::callback(function (array $data) use ($order, $pickupLocation): bool {
                    return $data['subject'] === 'Pickup notification'
                        && $data['senderName'] === 'Shopware Sandbox'
                        && $data['senderEmail'] === 'admin@shop.test'
                        && $data['recipients'] === ['pickup@shop.test' => 'Downtown Store']
                        && $data['salesChannelId'] === self::SALES_CHANNEL_ID
                        && $data['mailTemplateData']['order'] === $order
                        && $data['mailTemplateData']['customer'] === $order->getOrderCustomer()
                        && $data['mailTemplateData']['pickupLocation'] === $pickupLocation
                        && $data['contentHtml'] === '<p>Ready</p>'
                        && $data['contentPlain'] === 'Ready'
                        && $data['attachmentsConfig'] instanceof MailAttachmentsConfig;
                }),
                $flow->getContext(),
                static::callback(static fn (array $templateData): bool => $templateData['order'] === $order)
            );

        $this->action->handleFlow($flow);
    }

    public function testUsesConfiguredTemplateIdWhenProvided(): void
    {
        $flow = $this->flow($this->order(), $this->location('pickup@shop.test'), [
            'mailTemplateId' => self::CONFIGURED_TEMPLATE_ID,
        ]);

        $this->systemConfigService->method('get')->willReturn('admin@shop.test');
        $this->mailTemplateRepository
            ->expects(static::once())
            ->method('search')
            ->with(
                static::callback(static fn (Criteria $criteria): bool => $criteria->getIds() === [self::CONFIGURED_TEMPLATE_ID]),
                $flow->getContext()
            )
            ->willReturn($this->templateResult($this->template()));

        $this->mailService->expects(static::once())->method('send');

        $this->action->handleFlow($flow);
    }

    public function testDoesNothingWhenFlowDataIsMissing(): void
    {
        $this->systemConfigService->expects(static::never())->method('get');
        $this->mailTemplateRepository->expects(static::never())->method('search');
        $this->mailService->expects(static::never())->method('send');

        $this->action->handleFlow(new StorableFlow('pickup.order.placed', Context::createDefaultContext()));
    }

    public function testDoesNothingWhenFlowDataTypesAreInvalid(): void
    {
        $flow = new StorableFlow(
            'pickup.order.placed',
            Context::createDefaultContext(),
            [],
            [
                OrderAware::ORDER => new \stdClass(),
                PickupLocationAware::PICKUP_LOCATION => new \stdClass(),
            ]
        );

        $this->systemConfigService->expects(static::never())->method('get');
        $this->mailTemplateRepository->expects(static::never())->method('search');
        $this->mailService->expects(static::never())->method('send');

        $this->action->handleFlow($flow);
    }

    public function testSkipsWhenPickupLocationEmailIsMissing(): void
    {
        $this->logger
            ->expects(static::once())
            ->method('warning')
            ->with(
                'Pickup location email is missing or invalid; skipping admin notification.',
                static::callback(static fn (array $context): bool => $context['pickupLocationId'] === self::PICKUP_LOCATION_ID
                    && $context['orderId'] === self::ORDER_ID
                    && $context['recipientEmail'] === '')
            );

        $this->systemConfigService->expects(static::never())->method('get');
        $this->mailTemplateRepository->expects(static::never())->method('search');
        $this->mailService->expects(static::never())->method('send');

        $this->action->handleFlow($this->flow($this->order(), $this->location('')));
    }

    public function testSkipsWhenPickupLocationEmailIsInvalid(): void
    {
        $this->logger
            ->expects(static::once())
            ->method('warning')
            ->with(
                'Pickup location email is missing or invalid; skipping admin notification.',
                static::callback(static fn (array $context): bool => $context['recipientEmail'] === 'not-an-email')
            );

        $this->systemConfigService->expects(static::never())->method('get');
        $this->mailTemplateRepository->expects(static::never())->method('search');
        $this->mailService->expects(static::never())->method('send');

        $this->action->handleFlow($this->flow($this->order(), $this->location('not-an-email')));
    }

    public function testSendsWithoutPinnedSenderWhenSenderEmailConfigurationIsMissing(): void
    {
        $this->systemConfigService->method('get')->willReturn(null);
        $this->mailTemplateRepository->method('search')->willReturn($this->templateResult($this->template()));

        // Missing sender config must not drop the mail: the sender key is simply
        // omitted so the mail service resolves the sales channel's own sender.
        $this->mailService
            ->expects(static::once())
            ->method('send')
            ->with(static::callback(static fn (array $data): bool => !\array_key_exists('senderEmail', $data)));

        $this->action->handleFlow($this->flow($this->order(), $this->location('pickup@shop.test')));
    }

    public function testSendsWithoutPinnedSenderWhenSenderEmailConfigurationIsInvalid(): void
    {
        $this->systemConfigService->method('get')->willReturn('invalid-address');
        $this->mailTemplateRepository->method('search')->willReturn($this->templateResult($this->template()));

        $this->mailService
            ->expects(static::once())
            ->method('send')
            ->with(static::callback(static fn (array $data): bool => !\array_key_exists('senderEmail', $data)));

        $this->action->handleFlow($this->flow($this->order(), $this->location('pickup@shop.test')));
    }

    public function testSkipsWhenMailTemplateMissing(): void
    {
        $this->systemConfigService->method('get')->willReturn('admin@shop.test');
        $this->mailTemplateRepository->method('search')->willReturn($this->templateResult(null));
        $this->mailService->expects(static::never())->method('send');
        $this->logger
            ->expects(static::once())
            ->method('error')
            ->with(
                'Pickup admin mail template not found; skipping notification.',
                static::callback(static fn (array $context): bool => $context['orderId'] === self::ORDER_ID)
            );

        $this->action->handleFlow($this->flow($this->order(), $this->location('pickup@shop.test')));
    }

    public function testSkipsWhenTemplateLookupReturnsUnexpectedEntity(): void
    {
        $this->systemConfigService->method('get')->willReturn('admin@shop.test');
        $this->mailTemplateRepository->method('search')->willReturn($this->templateResult(new \stdClass()));
        $this->mailService->expects(static::never())->method('send');
        $this->logger->expects(static::once())->method('error');

        $this->action->handleFlow($this->flow($this->order(), $this->location('pickup@shop.test')));
    }

    public function testLogsAndSwallowsTemplateLookupFailure(): void
    {
        $this->systemConfigService->method('get')->willReturn('admin@shop.test');
        $this->mailTemplateRepository
            ->method('search')
            ->willThrowException(new \RuntimeException('template lookup failed'));

        $this->mailService->expects(static::never())->method('send');
        $this->logger
            ->expects(static::once())
            ->method('error')
            ->with(
                'Failed to send pickup admin notification.',
                static::callback(static fn (array $context): bool => $context['orderId'] === self::ORDER_ID
                    && $context['exception'] instanceof \RuntimeException)
            );

        $this->action->handleFlow($this->flow($this->order(), $this->location('pickup@shop.test')));
    }

    public function testLogsAndSwallowsMailServiceFailure(): void
    {
        $this->systemConfigService->method('get')->willReturn('admin@shop.test');
        $this->mailTemplateRepository->method('search')->willReturn($this->templateResult($this->template()));
        $this->mailService->method('send')->willThrowException(new \RuntimeException('smtp down'));
        $this->logger
            ->expects(static::once())
            ->method('error')
            ->with(
                'Failed to send pickup admin notification.',
                static::callback(static fn (array $context): bool => $context['orderId'] === self::ORDER_ID
                    && $context['exception'] instanceof \RuntimeException)
            );

        $this->action->handleFlow($this->flow($this->order(), $this->location('pickup@shop.test')));
    }

    public function testPassesOrderPickupRecordIntoTemplateData(): void
    {
        $record = new OrderPickupLocationEntity();
        $record->setId('cccccccccccccccccccccccccccccccc');
        $record->setComment('Ring the bell');

        $this->systemConfigService->method('get')->willReturn('admin@shop.test');
        $this->mailTemplateRepository->method('search')->willReturn($this->templateResult($this->template()));

        $this->mailService
            ->expects(static::once())
            ->method('send')
            ->with(
                static::isType('array'),
                static::anything(),
                static::callback(
                    static fn (array $templateData): bool => ($templateData['pickupOrderLocation'] ?? null) === $record
                )
            );

        $this->action->handleFlow($this->flow($this->order(), $this->location('pickup@shop.test'), [], $record));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function flow(
        OrderEntity $order,
        PickupLocationEntity $location,
        array $config = [],
        ?OrderPickupLocationEntity $record = null
    ): StorableFlow {
        $data = [
            OrderAware::ORDER => $order,
            PickupLocationAware::PICKUP_LOCATION => $location,
        ];

        if ($record !== null) {
            $data[OrderPickupLocationAware::ORDER_PICKUP_LOCATION] = $record;
        }

        $flow = new StorableFlow('pickup.order.placed', Context::createDefaultContext(), [], $data);
        $flow->setConfig($config);

        return $flow;
    }

    private function order(): OrderEntity
    {
        $customer = new OrderCustomerEntity();
        $customer->setId('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
        $customer->setCustomerId('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb');
        $customer->setEmail('customer@shop.test');
        $customer->setFirstName('Jamie');
        $customer->setLastName('Doe');

        $order = new OrderEntity();
        $order->setId(self::ORDER_ID);
        $order->setSalesChannelId(self::SALES_CHANNEL_ID);
        $order->setOrderCustomer($customer);

        return $order;
    }

    private function location(string $email): PickupLocationEntity
    {
        $location = new PickupLocationEntity();
        $location->setId(self::PICKUP_LOCATION_ID);
        $location->setEmail($email);
        $location->setName('Downtown Store');

        return $location;
    }

    private function template(): MailTemplateEntity
    {
        $template = new MailTemplateEntity();
        $template->setId(self::CONFIGURED_TEMPLATE_ID);
        $template->setSubject('Pickup notification');
        $template->setSenderName('Shopware Sandbox');
        $template->setContentHtml('<p>Ready</p>');
        $template->setContentPlain('Ready');

        return $template;
    }

    private function templateResult(object|null $template): EntitySearchResult&MockObject
    {
        $result = $this->createMock(EntitySearchResult::class);
        $result->method('first')->willReturn($template);

        return $result;
    }
}
