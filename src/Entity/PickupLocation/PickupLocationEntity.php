<?php

declare(strict_types=1);

namespace Kommandhub\ClickAndPickSW\Entity\PickupLocation;

use Kommandhub\ClickAndPickSW\Entity\PickupLocation\Aggregate\PickupLocationOpeningHour\PickupLocationOpeningHourCollection;
use Kommandhub\ClickAndPickSW\Entity\PickupLocation\Aggregate\PickupLocationSpecialHour\PickupLocationSpecialHourCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;

class PickupLocationEntity extends Entity
{
    use EntityIdTrait;

    protected string $name;
    protected string $street;
    protected ?string $phoneNumber = null;
    protected string $email;
    protected ?string $additionalAddressLine1 = null;
    protected ?string $additionalAddressLine2 = null;
    protected string $city;
    protected string $postalCode;
    protected ?string $timeFormat = '24h';
    protected ?string $timezone = null;
    protected ?string $latitude = null;
    protected ?string $longitude = null;
    protected bool $active;
    protected ?string $locationCode = null;
    protected string $defaultSalesChannelId;
    protected ?\DateTimeInterface $createdAt = null;
    protected ?\DateTimeInterface $updatedAt = null;
    protected ?SalesChannelCollection $salesChannels = null;
    protected ?SalesChannelEntity $defaultSalesChannel = null;
    protected ?PickupLocationOpeningHourCollection $openingHoursSchedule = null;
    protected ?PickupLocationSpecialHourCollection $specialHours = null;

    public function getTimezone(): ?string
    {
        return $this->timezone;
    }

    public function setTimezone(?string $timezone): void
    {
        $this->timezone = $timezone;
    }

    public function getOpeningHoursSchedule(): ?PickupLocationOpeningHourCollection
    {
        return $this->openingHoursSchedule;
    }

    public function setOpeningHoursSchedule(?PickupLocationOpeningHourCollection $openingHoursSchedule): void
    {
        $this->openingHoursSchedule = $openingHoursSchedule;
    }

    public function getSpecialHours(): ?PickupLocationSpecialHourCollection
    {
        return $this->specialHours;
    }

    public function setSpecialHours(?PickupLocationSpecialHourCollection $specialHours): void
    {
        $this->specialHours = $specialHours;
    }

    public function getSalesChannels(): ?SalesChannelCollection
    {
        return $this->salesChannels;
    }

    public function setSalesChannels(?SalesChannelCollection $salesChannels): void
    {
        $this->salesChannels = $salesChannels;
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeInterface $updatedAt): void
    {
        $this->updatedAt = $updatedAt;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(?\DateTimeInterface $createdAt): void
    {
        $this->createdAt = $createdAt;
    }

    public function getLocationCode(): ?string
    {
        return $this->locationCode;
    }

    public function setLocationCode(?string $locationCode): void
    {
        $this->locationCode = $locationCode;
    }

    public function getLongitude(): ?string
    {
        return $this->longitude;
    }

    public function setLongitude(?string $longitude): void
    {
        $this->longitude = $longitude;
    }

    public function getLatitude(): ?string
    {
        return $this->latitude;
    }

    public function setLatitude(?string $latitude): void
    {
        $this->latitude = $latitude;
    }

    public function getTimeFormat(): ?string
    {
        return $this->timeFormat;
    }

    public function setTimeFormat(?string $timeFormat): void
    {
        $this->timeFormat = $timeFormat ?? '24h';
    }

    public function getPostalCode(): string
    {
        return $this->postalCode;
    }

    public function setPostalCode(string $postalCode): void
    {
        $this->postalCode = $postalCode;
    }

    public function getCity(): string
    {
        return $this->city;
    }

    public function setCity(string $city): void
    {
        $this->city = $city;
    }

    public function getAdditionalAddressLine2(): ?string
    {
        return $this->additionalAddressLine2;
    }

    public function setAdditionalAddressLine2(?string $additionalAddressLine2): void
    {
        $this->additionalAddressLine2 = $additionalAddressLine2;
    }

    public function getAdditionalAddressLine1(): ?string
    {
        return $this->additionalAddressLine1;
    }

    public function setAdditionalAddressLine1(?string $additionalAddressLine1): void
    {
        $this->additionalAddressLine1 = $additionalAddressLine1;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): void
    {
        $this->email = $email;
    }

    public function getPhoneNumber(): ?string
    {
        return $this->phoneNumber;
    }

    public function setPhoneNumber(?string $phoneNumber): void
    {
        $this->phoneNumber = $phoneNumber;
    }

    public function getStreet(): string
    {
        return $this->street;
    }

    public function setStreet(string $street): void
    {
        $this->street = $street;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
    }

    public function getDefaultSalesChannelId(): string
    {
        return $this->defaultSalesChannelId;
    }

    public function setDefaultSalesChannelId(string $defaultSalesChannelId): void
    {
        $this->defaultSalesChannelId = $defaultSalesChannelId;
    }

    public function getDefaultSalesChannel(): ?SalesChannelEntity
    {
        return $this->defaultSalesChannel;
    }

    public function setDefaultSalesChannel(?SalesChannelEntity $defaultSalesChannel): void
    {
        $this->defaultSalesChannel = $defaultSalesChannel;
    }
}
