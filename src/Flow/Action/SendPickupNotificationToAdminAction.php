<?php

declare(strict_types=1);

namespace Kommandhub\ClickAndPickSW\Flow\Action;

use Kommandhub\ClickAndPickSW\Entity\OrderPickupLocation\OrderPickupLocationEntity;
use Kommandhub\ClickAndPickSW\Entity\PickupLocation\PickupLocationEntity;
use Kommandhub\ClickAndPickSW\Flow\Aware\OrderPickupLocationAware;
use Kommandhub\ClickAndPickSW\Flow\Aware\PickupLocationAware;
use Kommandhub\ClickAndPickSW\Migration\Migration1760113852PickupReadyMailTemplate;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Content\Flow\Dispatching\Action\FlowAction;
use Shopware\Core\Content\Flow\Dispatching\DelayableAction;
use Shopware\Core\Content\Flow\Dispatching\StorableFlow;
use Shopware\Core\Content\Mail\Service\AbstractMailService;
use Shopware\Core\Content\Mail\Service\MailAttachmentsConfig;
use Shopware\Core\Content\MailTemplate\MailTemplateEntity;
use Shopware\Core\Content\MailTemplate\Subscriber\MailSendSubscriberConfig;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Event\OrderAware;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Flow Builder action that emails the order's pickup location so it can prepare
 * the order. Recipient is the pickup location's own address (not the customer),
 * so any pickup flow (order placed, order ready, …) can reuse it by requiring
 * {@see OrderAware} and {@see PickupLocationAware}.
 *
 * The mail template is taken from the action config (`mailTemplateId`) when set,
 * otherwise the plugin's admin pickup template — so the action works both with a
 * config-less migration flow and a merchant-configured one.
 */
class SendPickupNotificationToAdminAction extends FlowAction implements DelayableAction
{
    public const ACTION_NAME = 'action.kmh.pickup.notify_admin';

    public function __construct(
        private readonly AbstractMailService $mailService,
        private readonly EntityRepository $mailTemplateRepository,
        private readonly SystemConfigService $systemConfigService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getName(): string
    {
        return self::ACTION_NAME;
    }

    /**
     * @return array<string>
     */
    public function requirements(): array
    {
        return [OrderAware::class, PickupLocationAware::class];
    }

    public function handleFlow(StorableFlow $flow): void
    {
        if (!$flow->hasData(OrderAware::ORDER) || !$flow->hasData(PickupLocationAware::PICKUP_LOCATION)) {
            return;
        }

        $order = $flow->getData(OrderAware::ORDER);
        $pickupLocation = $flow->getData(PickupLocationAware::PICKUP_LOCATION);

        if (!$order instanceof OrderEntity || !$pickupLocation instanceof PickupLocationEntity) {
            return;
        }

        // The order pickup record (chosen time + instructions) is optional flow
        // data — present on the plugin's pickup triggers, absent if some other
        // pickup-location-aware event reuses this action.
        $pickupOrderLocation = $flow->hasData(OrderPickupLocationAware::ORDER_PICKUP_LOCATION)
            ? $flow->getData(OrderPickupLocationAware::ORDER_PICKUP_LOCATION)
            : null;

        if (!$pickupOrderLocation instanceof OrderPickupLocationEntity) {
            $pickupOrderLocation = null;
        }

        $recipientEmail = trim($pickupLocation->getEmail());

        if ($recipientEmail === '' || !$this->isValidEmail($recipientEmail)) {
            $this->logger->warning('Pickup location email is missing or invalid; skipping admin notification.', [
                'pickupLocationId' => $pickupLocation->getId(),
                'orderId' => $order->getId(),
                'recipientEmail' => $recipientEmail,
            ]);

            return;
        }

        $context = $flow->getContext();

        try {
            $template = $this->loadMailTemplate($this->resolveTemplateId($flow), $context);

            if ($template === null) {
                $this->logger->error('Pickup admin mail template not found; skipping notification.', [
                    'orderId' => $order->getId(),
                ]);

                return;
            }

            // A missing/invalid configured sender is not fatal: omit it and let
            // the mail service resolve the sales channel's own sender address.
            $data = $this->buildMailData($template, $order, $pickupLocation, $pickupOrderLocation, $recipientEmail, $this->resolveSenderEmail($order), $context);
            /** @var array<string, mixed> $templateData */
            $templateData = $data['mailTemplateData'];
            $this->mailService->send($data, $context, $templateData);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send pickup admin notification.', [
                'exception' => $e,
                'orderId' => $order->getId(),
            ]);
        }
    }

    private function resolveTemplateId(StorableFlow $flow): string
    {
        $configured = $flow->getConfig()['mailTemplateId'] ?? null;

        return \is_string($configured) && $configured !== ''
            ? $configured
            : Migration1760113852PickupReadyMailTemplate::ADMIN_ORDER_PLACED_TEMPLATE_ID;
    }

    private function loadMailTemplate(string $templateId, Context $context): ?MailTemplateEntity
    {
        $criteria = (new Criteria([$templateId]))
            ->addAssociation('mailTemplateType')
            ->setLimit(1);

        $template = $this->mailTemplateRepository->search($criteria, $context)->first();

        return $template instanceof MailTemplateEntity ? $template : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildMailData(
        MailTemplateEntity $template,
        OrderEntity $order,
        PickupLocationEntity $pickupLocation,
        ?OrderPickupLocationEntity $pickupOrderLocation,
        string $recipientEmail,
        ?string $senderEmail,
        Context $context
    ): array {
        $data = [
            'subject' => $template->getSubject(),
            'senderName' => $template->getSenderName(),
            'recipients' => [
                $recipientEmail => $pickupLocation->getName(),
            ],
            'salesChannelId' => $order->getSalesChannelId(),
            'mailTemplateData' => [
                'order' => $order,
                'customer' => $order->getOrderCustomer(),
                'pickupLocation' => $pickupLocation,
                'pickupOrderLocation' => $pickupOrderLocation,
            ],
            'contentHtml' => $template->getContentHtml(),
            'contentPlain' => $template->getContentPlain(),
            'attachmentsConfig' => new MailAttachmentsConfig(
                $context,
                $template,
                new MailSendSubscriberConfig(false),
                [],
                $order->getId()
            ),
        ];

        // Only pin the sender when we have a valid one; otherwise the mail
        // service falls back to the sales channel's configured sender.
        if ($senderEmail !== null) {
            $data['senderEmail'] = $senderEmail;
        }

        return $data;
    }

    private function resolveSenderEmail(OrderEntity $order): ?string
    {
        $senderEmail = $this->systemConfigService->get('core.basicInformation.email', $order->getSalesChannelId());

        if (!\is_string($senderEmail)) {
            return null;
        }

        $senderEmail = trim($senderEmail);

        return $this->isValidEmail($senderEmail) ? $senderEmail : null;
    }

    private function isValidEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}
