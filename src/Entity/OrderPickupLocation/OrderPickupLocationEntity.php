<?php

declare(strict_types=1);

namespace Kommandhub\ClickAndPickSW\Entity\OrderPickupLocation;

use Kommandhub\ClickAndPickSW\Entity\PickupLocation\PickupLocationEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class OrderPickupLocationEntity extends Entity
{
    use EntityIdTrait;

    protected string $orderId;

    protected string $orderVersionId;

    protected ?string $pickupLocationId = null;

    protected ?\DateTimeInterface $pickupTime = null;

    protected ?string $comment = null;

    protected ?OrderEntity $order = null;

    protected ?PickupLocationEntity $pickupLocation = null;

    public function getOrderId(): string
    {
        return $this->orderId;
    }

    public function setOrderId(string $orderId): void
    {
        $this->orderId = $orderId;
    }

    public function getOrderVersionId(): string
    {
        return $this->orderVersionId;
    }

    public function setOrderVersionId(string $orderVersionId): void
    {
        $this->orderVersionId = $orderVersionId;
    }

    public function getPickupLocationId(): ?string
    {
        return $this->pickupLocationId;
    }

    public function setPickupLocationId(?string $pickupLocationId): void
    {
        $this->pickupLocationId = $pickupLocationId;
    }

    public function getPickupTime(): ?\DateTimeInterface
    {
        return $this->pickupTime;
    }

    public function setPickupTime(?\DateTimeInterface $pickupTime): void
    {
        $this->pickupTime = $pickupTime;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function setComment(?string $comment): void
    {
        $this->comment = $comment;
    }

    public function getOrder(): ?OrderEntity
    {
        return $this->order;
    }

    public function setOrder(?OrderEntity $order): void
    {
        $this->order = $order;
    }

    public function getPickupLocation(): ?PickupLocationEntity
    {
        return $this->pickupLocation;
    }

    public function setPickupLocation(?PickupLocationEntity $pickupLocation): void
    {
        $this->pickupLocation = $pickupLocation;
    }
}
