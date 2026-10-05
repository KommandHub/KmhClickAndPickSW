<?php declare(strict_types=1);

namespace Kommandhub\ClickAndPickSW\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Kommandhub\ClickAndPickSW\KmhClickAndPickSW;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryStates;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineTransition\StateMachineTransitionActions;

/**
 * @internal
 */
class Migration1760109246AddReadyForPickupOrderState extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1760109246;
    }

    /**
     * @throws Exception
     */
    public function update(Connection $connection): void
    {
        $stateMachineId = $this->fetchOrderDeliveryStateId($connection);

        if (!$stateMachineId) {
            throw new \RuntimeException('Order delivery state machine not found');
        }

        $germanId = $this->fetchLanguageId('de-DE', $connection);
        $defaultLangId = $this->fetchLanguageId('en-GB', $connection);

        $readyForPickupStateId = Uuid::fromHexToBytes(
            KmhClickAndPickSW::STATE_READY_FOR_PICKUP_ID
        );
        $readyForPickupTechnicalName = \Kommandhub\ClickAndPickSW\Entity\Order\Aggregated\OrderDelivery\OrderDeliveryStates::STATE_READY_FOR_PICKUP;

        $now = (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT);

        // Check if state already exists before inserting
        if (!$this->stateMachineStateExists($connection, $readyForPickupStateId)) {
            $this->insertStateMachineState($connection, $readyForPickupStateId, $stateMachineId, $readyForPickupTechnicalName, $now);

            // Only insert translations if the state was newly created
            $this->insertStateTranslation($connection, $defaultLangId, $germanId, $readyForPickupStateId, $now);
        }

        $openStateId = $this->fetchStateId(OrderDeliveryStates::STATE_OPEN, $stateMachineId, $connection);
        $shippedStateId = $this->fetchStateId(OrderDeliveryStates::STATE_SHIPPED, $stateMachineId, $connection);
        $cancelledStateId = $this->fetchStateId(OrderDeliveryStates::STATE_CANCELLED, $stateMachineId, $connection);

        // Insert transitions only if they don't exist
        $this->insertStateMachineTransitionIfNotExists(
            $connection,
            $stateMachineId,
            \Kommandhub\ClickAndPickSW\Entity\Order\Aggregated\OrderDelivery\StateMachineTransitionActions::ACTION_READY,
            $openStateId,
            $readyForPickupStateId,
            $now
        );

        $this->insertStateMachineTransitionIfNotExists(
            $connection,
            $stateMachineId,
            StateMachineTransitionActions::ACTION_REOPEN,
            $readyForPickupStateId,
            $openStateId,
            $now
        );

        $this->insertStateMachineTransitionIfNotExists(
            $connection,
            $stateMachineId,
            StateMachineTransitionActions::ACTION_SHIP,
            $readyForPickupStateId,
            $shippedStateId,
            $now
        );

        $this->insertStateMachineTransitionIfNotExists(
            $connection,
            $stateMachineId,
            StateMachineTransitionActions::ACTION_CANCEL,
            $readyForPickupStateId,
            $cancelledStateId,
            $now
        );
    }

    /**
     * @throws Exception
     */
    private function insertStateMachineState(Connection $connection, string $id, string $stateMachineId, string $technicalName, string $createdAt): void
    {
        $connection->insert('state_machine_state', [
            'id' => $id,
            'state_machine_id' => $stateMachineId,
            'technical_name' => $technicalName,
            'created_at' => $createdAt
        ]);
    }

    /**
     * @throws Exception
     */
    private function insertStateTranslation(Connection $connection, ?string $defaultLangId, ?string $germanId, string $stateId, string $createdAt): void
    {
        if ($defaultLangId !== $germanId && $defaultLangId) {
            // Check if translation already exists
            if (!$this->stateTranslationExists($connection, $stateId, $defaultLangId)) {
                $connection->insert('state_machine_state_translation', [
                    'state_machine_state_id' => $stateId,
                    'language_id' => $defaultLangId,
                    'name' => 'Ready',
                    'created_at' => $createdAt
                ]);
            }
        }

        if ($germanId) {
            // Check if translation already exists
            if (!$this->stateTranslationExists($connection, $stateId, $germanId)) {
                $connection->insert('state_machine_state_translation', [
                    'state_machine_state_id' => $stateId,
                    'language_id' => $germanId,
                    'name' => 'Bereit',
                    'created_at' => $createdAt
                ]);
            }
        }
    }

    /**
     * @throws Exception
     */
    private function insertStateMachineTransitionIfNotExists(
        Connection $connection,
        string $stateMachineId,
        string $actionName,
        ?string $fromStateId,
        ?string $toStateId,
        string $createdAt
    ): void {
        if (!$fromStateId || !$toStateId) {
            return;
        }

        // Check if transition already exists
        if ($this->stateMachineTransitionExists($connection, $stateMachineId, $actionName, $fromStateId, $toStateId)) {
            return;
        }

        $connection->insert('state_machine_transition', [
            'id' => Uuid::randomBytes(),
            'state_machine_id' => $stateMachineId,
            'action_name' => $actionName,
            'from_state_id' => $fromStateId,
            'to_state_id' => $toStateId,
            'created_at' => $createdAt
        ]);
    }

    /**
     * @throws Exception
     */
    private function stateMachineStateExists(Connection $connection, string $stateId): bool
    {
        $result = $connection->fetchOne(
            'SELECT 1 FROM state_machine_state WHERE id = :id',
            ['id' => $stateId]
        );

        return $result !== false;
    }

    /**
     * @throws Exception
     */
    private function stateTranslationExists(Connection $connection, string $stateId, string $languageId): bool
    {
        $result = $connection->fetchOne(
            'SELECT 1 FROM state_machine_state_translation WHERE state_machine_state_id = :state_id AND language_id = :language_id',
            [
                'state_id' => $stateId,
                'language_id' => $languageId
            ]
        );

        return $result !== false;
    }

    /**
     * @throws Exception
     */
    private function stateMachineTransitionExists(
        Connection $connection,
        string $stateMachineId,
        string $actionName,
        string $fromStateId,
        string $toStateId
    ): bool {
        $result = $connection->fetchOne(
            'SELECT 1 FROM state_machine_transition WHERE
             state_machine_id = :state_machine_id AND
             action_name = :action_name AND
             from_state_id = :from_state_id AND
             to_state_id = :to_state_id',
            [
                'state_machine_id' => $stateMachineId,
                'action_name' => $actionName,
                'from_state_id' => $fromStateId,
                'to_state_id' => $toStateId
            ]
        );

        return $result !== false;
    }

    /**
     * @throws Exception
     */
    private function fetchOrderDeliveryStateId(Connection $connection): string
    {
        $stateMachineId = $connection->fetchOne('SELECT id FROM state_machine WHERE technical_name = :technical_name', [
            'technical_name' => 'order_delivery.state',
        ]);

        if (!\is_string($stateMachineId) || $stateMachineId === '') {
            throw new \RuntimeException('Order delivery state machine id not found.');
        }

        return $stateMachineId;
    }

    /**
     * @throws Exception
     */
    private function fetchStateId(string $technicalName, string $stateMachineId, Connection $connection): ?string
    {
        $stateId = $connection->fetchOne(
            'SELECT `id` FROM `state_machine_state` WHERE
            `technical_name` = :technical_name AND
            `state_machine_id` = :state_machine_id
            LIMIT 1',
            [
                'technical_name' => $technicalName,
                'state_machine_id' => $stateMachineId,
            ]
        );
        if ($stateId === false) {
            return null;
        }

        if (!\is_string($stateId) || $stateId === '') {
            throw new \RuntimeException(\sprintf('Invalid state id for technical name "%s".', $technicalName));
        }

        return $stateId;
    }

    /**
     * @throws Exception
     */
    private function fetchLanguageId(string $code, Connection $connection): ?string
    {
        $langId = $connection->fetchOne(
            'SELECT `language`.`id` FROM `language` INNER JOIN `locale` ON `language`.`translation_code_id` = `locale`.`id` WHERE `code` = :code LIMIT 1',
            ['code' => $code]
        );
        if (!$langId && $code !== 'en-GB') {
            return null;
        }

        if (!$langId) {
            return Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM);
        }

        if (!\is_string($langId)) {
            throw new \RuntimeException(\sprintf('Invalid language id for code "%s".', $code));
        }

        return $langId;
    }
}
