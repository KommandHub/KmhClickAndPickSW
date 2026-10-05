<?php

declare(strict_types=1);

namespace Kommandhub\ClickAndPickSW\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Kommandhub\ClickAndPickSW\Event\PickupOrderReadyEvent;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Bug-fix re-sync for installs created before the mail/flow fixes:
 *
 * - Repoints the customer "pickup ready" flow from the generic
 *   `state_enter.order_delivery.state.ready` event (which fires for every
 *   delivery, pickup or not, and carries no pickup data) to the plugin's own
 *   {@see PickupOrderReadyEvent}. That event fires only for pickup orders and
 *   exposes the pickup location + appointment, so the mail can show where/when.
 * - Rewrites the pickup-ready and admin-notification mail templates to the
 *   current content (location + time on the customer mail, timezone-correct
 *   pickup times, fixed plain-text line breaks).
 *
 * Fresh installs already get the correct content/event from the original
 * migrations; this only heals existing rows and is idempotent.
 */
class Migration1760500000ResyncPickupMailsAndFlow extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1760500000;
    }

    /**
     * @throws Exception
     */
    public function update(Connection $connection): void
    {
        $this->repointPickupReadyFlow($connection);
        $this->resyncMailTemplates($connection);
    }

    /**
     * @throws Exception
     */
    private function repointPickupReadyFlow(Connection $connection): void
    {
        $connection->executeStatement(
            'UPDATE `flow` SET `event_name` = :event WHERE `id` = :id',
            [
                'event' => PickupOrderReadyEvent::EVENT_NAME,
                'id' => Uuid::fromHexToBytes(Migration1760115677AddPickupMailSendFlow::SEND_PICKUP_READY_FLOW_ID),
            ]
        );

        $this->registerIndexer($connection, 'flow.indexer');
    }

    /**
     * @throws Exception
     */
    private function resyncMailTemplates(Connection $connection): void
    {
        $content = [
            Migration1760113852PickupReadyMailTemplate::PICKUP_READY_TEMPLATE_ID => [
                'en-GB' => [
                    Migration1760113852PickupReadyMailTemplate::getPickupReadyContentHtmlEn(),
                    Migration1760113852PickupReadyMailTemplate::getPickupReadyContentPlainEn(),
                ],
                'de-DE' => [
                    Migration1760113852PickupReadyMailTemplate::getPickupReadyContentHtmlDe(),
                    Migration1760113852PickupReadyMailTemplate::getPickupReadyContentPlainDe(),
                ],
            ],
            Migration1760113852PickupReadyMailTemplate::ADMIN_ORDER_PLACED_TEMPLATE_ID => [
                'en-GB' => [
                    Migration1760113852PickupReadyMailTemplate::getAdminOrderPlacedContentHtmlEn(),
                    Migration1760113852PickupReadyMailTemplate::getAdminOrderPlacedContentPlainEn(),
                ],
                'de-DE' => [
                    Migration1760113852PickupReadyMailTemplate::getAdminOrderPlacedContentHtmlDe(),
                    Migration1760113852PickupReadyMailTemplate::getAdminOrderPlacedContentPlainDe(),
                ],
            ],
        ];

        foreach ($content as $templateId => $byLocale) {
            $mailTemplateId = Uuid::fromHexToBytes($templateId);

            foreach ($byLocale as $locale => [$html, $plain]) {
                $languageId = $this->getLanguageIdByLocale($connection, $locale);

                if ($languageId === null) {
                    continue;
                }

                $connection->executeStatement(
                    'UPDATE `mail_template_translation`
                     SET `content_html` = :html, `content_plain` = :plain, `updated_at` = NOW()
                     WHERE `mail_template_id` = :mailTemplateId AND `language_id` = :languageId',
                    [
                        'html' => $html,
                        'plain' => $plain,
                        'mailTemplateId' => $mailTemplateId,
                        'languageId' => $languageId,
                    ]
                );
            }
        }
    }

    /**
     * @throws Exception
     */
    private function getLanguageIdByLocale(Connection $connection, string $locale): ?string
    {
        $languageId = $connection->fetchOne(
            'SELECT `language`.`id`
             FROM `language`
             INNER JOIN `locale` ON `locale`.`id` = `language`.`locale_id`
             WHERE `locale`.`code` = :code',
            ['code' => $locale]
        );

        return \is_string($languageId) && $languageId !== '' ? $languageId : null;
    }
}
