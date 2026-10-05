<?php

declare(strict_types=1);

namespace Kommandhub\ClickAndPickSW\Migration;

use DateTime;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * @internal
 */
class Migration1760113852PickupReadyMailTemplate extends MigrationStep
{
    // Existing template constants
    final const PICKUP_READY_TEMPLATE_ID = 'b8826ee629d39b4ecf88c984424e8732';
    final const PICKUP_READY_TEMPLATE_TYPE_ID = '582ad2d4dfc0b95af9ac16b4d70a3fb9';

    // New admin notification constants
    final const ADMIN_ORDER_PLACED_TEMPLATE_ID = 'c8826ee629d39b4ecf88c984424e8733';
    final const ADMIN_ORDER_PLACED_TEMPLATE_TYPE_ID = '682ad2d4dfc0b95af9ac16b4d70a3fba';

    public function getCreationTimestamp(): int
    {
        return 1760113852;
    }

    /**
     * @throws Exception
     */
    public function update(Connection $connection): void
    {
        $this->createPickupReadyTemplate($connection);
        $this->createAdminOrderPlacedTemplate($connection);
    }

    /**
     * @throws Exception
     */
    private function createPickupReadyTemplate(Connection $connection): void
    {
        // Create mail template type
        $this->createMailTemplateType(
            $connection,
            self::PICKUP_READY_TEMPLATE_TYPE_ID,
            'order_delivery.state.ready_for_pickup',
            [
                'en-GB' => 'Order ready for pickup',
                'de-DE' => 'Bestellung abholbereit'
            ]
        );

        // Create mail template
        $this->createMailTemplate(
            $connection,
            self::PICKUP_READY_TEMPLATE_ID,
            self::PICKUP_READY_TEMPLATE_TYPE_ID,
            [
                'en-GB' => [
                    'senderName' => '{{ salesChannel.name }}',
                    'subject' => 'Order ready for pickup',
                    'description' => 'Your order is ready for pickup',
                    'contentHtml' => self::getPickupReadyContentHtmlEn(),
                    'contentPlain' => self::getPickupReadyContentPlainEn(),
                ],
                'de-DE' => [
                    'senderName' => '{{ salesChannel.name }}',
                    'subject' => 'Bestellung abholbereit',
                    'description' => 'Ihre Bestellung ist abholbereit',
                    'contentHtml' => self::getPickupReadyContentHtmlDe(),
                    'contentPlain' => self::getPickupReadyContentPlainDe(),
                ]
            ]
        );
    }

    /**
     * @throws Exception
     */
    private function createAdminOrderPlacedTemplate(Connection $connection): void
    {
        // Create mail template type for admin notification
        $this->createMailTemplateType(
            $connection,
            self::ADMIN_ORDER_PLACED_TEMPLATE_TYPE_ID,
            'order.state.placed.admin',
            [
                'en-GB' => 'New Pickup Order Placed - Admin Notification',
                'de-DE' => 'Neue Abholbestellung aufgegeben - Admin Benachrichtigung'
            ]
        );

        // Create mail template for admin notification
        $this->createMailTemplate(
            $connection,
            self::ADMIN_ORDER_PLACED_TEMPLATE_ID,
            self::ADMIN_ORDER_PLACED_TEMPLATE_TYPE_ID,
            [
                'en-GB' => [
                    'senderName' => '{{ salesChannel.name }}',
                   'subject' => 'New pickup order received - #{{ order.orderNumber }}',
                   'description' => 'Internal admin notification when a pickup order is placed',
                    'contentHtml' => self::getAdminOrderPlacedContentHtmlEn(),
                    'contentPlain' => self::getAdminOrderPlacedContentPlainEn(),
                ],
                'de-DE' => [
                    'senderName' => '{{ salesChannel.name }}',
                   'subject' => 'Neue Abholbestellung erhalten - #{{ order.orderNumber }}',
                   'description' => 'Interne Admin-Benachrichtigung bei einer neuen Abholbestellung',
                    'contentHtml' => self::getAdminOrderPlacedContentHtmlDe(),
                    'contentPlain' => self::getAdminOrderPlacedContentPlainDe(),
                ]
            ]
        );
    }

    /**
     * @throws Exception
     */
    private function createMailTemplateType(Connection $connection, string $typeId, string $technicalName, array $translations): void
    {
        $mailTemplateTypeId = Uuid::fromHexToBytes($typeId);

        if (!$this->mailTemplateTypeExists($connection, $mailTemplateTypeId, $technicalName)) {
            $connection->insert('mail_template_type', [
                'id' => $mailTemplateTypeId,
                'technical_name' => $technicalName,
                'available_entities' => json_encode(['order' => 'order', 'salesChannel' => 'sales_channel']),
                'template_data' => '{"order":{"orderNumber":"10060","orderCustomer":{"firstName":"Max","lastName":"Mustermann"},"amountTotal":150.50,"lineItems":[{"label":"Product 1","quantity":1,"price":{"unitPrice":100.0}}]},"salesChannel":{"name":"Storefront"}}',
                'created_at' => (new DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            ]);
        }

        foreach ($translations as $locale => $name) {
            $languageId = $this->getLanguageIdByLocale($connection, $locale);
            if (!empty($languageId)) {
                if (!$this->mailTemplateTypeTranslationExists($connection, $mailTemplateTypeId, $languageId)) {
                    $connection->insert('mail_template_type_translation', [
                        'mail_template_type_id' => $mailTemplateTypeId,
                        'language_id' => $languageId,
                        'name' => $name,
                        'created_at' => (new DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
                    ]);
                }
            }
        }
    }

    /**
     * @throws Exception
     */
    private function createMailTemplate(Connection $connection, string $templateId, string $templateTypeId, array $translations): void
    {
        $mailTemplateId = Uuid::fromHexToBytes($templateId);
        $mailTemplateTypeId = Uuid::fromHexToBytes($templateTypeId);

        if (!$this->mailTemplateExists($connection, $mailTemplateId)) {
            $connection->insert('mail_template', [
                'id' => $mailTemplateId,
                'mail_template_type_id' => $mailTemplateTypeId,
                'system_default' => 0,
                'created_at' => (new DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            ]);
        }

        foreach ($translations as $locale => $translation) {
            $languageId = $this->getLanguageIdByLocale($connection, $locale);
            if (!empty($languageId)) {
                if (!$this->mailTemplateTranslationExists($connection, $mailTemplateId, $languageId)) {
                    $connection->insert('mail_template_translation', [
                        'mail_template_id' => $mailTemplateId,
                        'language_id' => $languageId,
                        'sender_name' => $translation['senderName'],
                        'subject' => $translation['subject'],
                        'description' => $translation['description'],
                        'content_html' => $translation['contentHtml'],
                        'content_plain' => $translation['contentPlain'],
                        'created_at' => (new DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
                    ]);
                }
            }
        }
    }

    /**
     * @throws Exception
     */
    private function mailTemplateTypeExists(Connection $connection, string $mailTemplateTypeId, string $technicalName): bool
    {
        $sql = <<<SQL
            SELECT 1
            FROM `mail_template_type`
            WHERE `id` = :id OR `technical_name` = :technicalName
            LIMIT 1
        SQL;

        return (bool) $connection->executeQuery($sql, [
            'id' => $mailTemplateTypeId,
            'technicalName' => $technicalName,
        ])->fetchOne();
    }

    /**
     * @throws Exception
     */
    private function mailTemplateTypeTranslationExists(Connection $connection, string $mailTemplateTypeId, string $languageId): bool
    {
        $sql = <<<SQL
            SELECT 1
            FROM `mail_template_type_translation`
            WHERE `mail_template_type_id` = :mailTemplateTypeId
              AND `language_id` = :languageId
            LIMIT 1
        SQL;

        return (bool) $connection->executeQuery($sql, [
            'mailTemplateTypeId' => $mailTemplateTypeId,
            'languageId' => $languageId,
        ])->fetchOne();
    }

    /**
     * @throws Exception
     */
    private function mailTemplateExists(Connection $connection, string $mailTemplateId): bool
    {
        $sql = <<<SQL
            SELECT 1
            FROM `mail_template`
            WHERE `id` = :id
            LIMIT 1
        SQL;

        return (bool) $connection->executeQuery($sql, [
            'id' => $mailTemplateId,
        ])->fetchOne();
    }

    /**
     * @throws Exception
     */
    private function mailTemplateTranslationExists(Connection $connection, string $mailTemplateId, string $languageId): bool
    {
        $sql = <<<SQL
            SELECT 1
            FROM `mail_template_translation`
            WHERE `mail_template_id` = :mailTemplateId
              AND `language_id` = :languageId
            LIMIT 1
        SQL;

        return (bool) $connection->executeQuery($sql, [
            'mailTemplateId' => $mailTemplateId,
            'languageId' => $languageId,
        ])->fetchOne();
    }

    /**
     * @throws Exception
     */
    private function getLanguageIdByLocale(Connection $connection, string $locale): ?string
    {
        $sql = <<<SQL
            SELECT `language`.`id`
            FROM `language`
            INNER JOIN `locale` ON `locale`.`id` = `language`.`locale_id`
            WHERE `locale`.`code` = :code
        SQL;

        $languageId = $connection->executeQuery($sql, ['code' => $locale])->fetchOne();

        return \is_string($languageId) && $languageId !== '' ? $languageId : null;
    }

    // Pickup Ready Content - English
    public static function getPickupReadyContentHtmlEn(): string
    {
        return <<<MAIL
        <div style="font-family:arial; font-size:12px;">
            {% set pickupTz = pickupLocation.timezone|default('UTC') %}
            <p>
                Dear {{ order.orderCustomer.firstName }} {{ order.orderCustomer.lastName }},
                <br><br>
                We are pleased to inform you that your order {{ order.orderNumber }} is now ready for pickup.<br><br>
                {% if pickupLocation %}
                    <strong>Where to collect:</strong><br>
                    {{ pickupLocation.name }}<br>
                    {{ pickupLocation.street }}<br>
                    {% if pickupLocation.postalCode %}{{ pickupLocation.postalCode }} {% endif %}{{ pickupLocation.city }}<br>
                    {% if pickupOrderLocation.pickupTime %}<br><strong>Pickup time:</strong> {{ pickupOrderLocation.pickupTime|format_datetime('medium', 'short', locale='en-GB', timezone=pickupTz) }}<br>{% endif %}
                    <br>
                {% endif %}
                Please bring a valid ID and your order confirmation when collecting your order.<br><br>
                If you have any questions, feel free to contact us.<br><br>
                Best regards,<br>
                Your {{ salesChannel.name }} Team
            </p>
        </div>
        MAIL;
    }

    public static function getPickupReadyContentPlainEn(): string
    {
        return <<<MAIL
        {% set pickupTz = pickupLocation.timezone|default('UTC') %}
        Dear {{ order.orderCustomer.firstName }} {{ order.orderCustomer.lastName }},

        Your order {{ order.orderNumber }} is now ready for pickup.
        {% if pickupLocation %}
        Where to collect:
        {{ pickupLocation.name }}
        {{ pickupLocation.street }}
        {% if pickupLocation.postalCode %}{{ pickupLocation.postalCode }} {% endif %}{{ pickupLocation.city }}
        {% if pickupOrderLocation.pickupTime %}Pickup time: {{ pickupOrderLocation.pickupTime|format_datetime('medium', 'short', locale='en-GB', timezone=pickupTz) }}
        {% endif %}
        {% endif %}
        Please bring a valid ID and your order confirmation when collecting your order.

        Best regards,
        Your {{ salesChannel.name }} Team
        MAIL;
    }

    // Pickup Ready Content - German
    public static function getPickupReadyContentHtmlDe(): string
    {
        return <<<MAIL
        <div style="font-family:arial; font-size:12px;">
            {% set pickupTz = pickupLocation.timezone|default('UTC') %}
            <p>
                Sehr geehrte/r {{ order.orderCustomer.firstName }} {{ order.orderCustomer.lastName }},
                <br><br>
                wir freuen uns, Ihnen mitteilen zu können, dass Ihre Bestellung {{ order.orderNumber }} ab sofort zur Abholung bereitsteht.<br><br>
                {% if pickupLocation %}
                    <strong>Abholort:</strong><br>
                    {{ pickupLocation.name }}<br>
                    {{ pickupLocation.street }}<br>
                    {% if pickupLocation.postalCode %}{{ pickupLocation.postalCode }} {% endif %}{{ pickupLocation.city }}<br>
                    {% if pickupOrderLocation.pickupTime %}<br><strong>Abholzeit:</strong> {{ pickupOrderLocation.pickupTime|format_datetime('medium', 'short', locale='de-DE', timezone=pickupTz) }}<br>{% endif %}
                    <br>
                {% endif %}
                Bitte bringen Sie zur Abholung einen gültigen Ausweis sowie Ihre Bestellbestätigung mit.<br><br>
                Bei Rückfragen stehen wir Ihnen gerne zur Verfügung.<br><br>
                Mit freundlichen Grüßen<br>
                Ihr {{ salesChannel.name }} Team
            </p>
        </div>
        MAIL;
    }

    public static function getPickupReadyContentPlainDe(): string
    {
        return <<<MAIL
        {% set pickupTz = pickupLocation.timezone|default('UTC') %}
        Sehr geehrte/r {{ order.orderCustomer.firstName }} {{ order.orderCustomer.lastName }},

        Ihre Bestellung {{ order.orderNumber }} ist jetzt abholbereit.
        {% if pickupLocation %}
        Abholort:
        {{ pickupLocation.name }}
        {{ pickupLocation.street }}
        {% if pickupLocation.postalCode %}{{ pickupLocation.postalCode }} {% endif %}{{ pickupLocation.city }}
        {% if pickupOrderLocation.pickupTime %}Abholzeit: {{ pickupOrderLocation.pickupTime|format_datetime('medium', 'short', locale='de-DE', timezone=pickupTz) }}
        {% endif %}
        {% endif %}
        Bitte bringen Sie zur Abholung einen gültigen Ausweis sowie Ihre Bestellbestätigung mit.

        Mit freundlichen Grüßen,
        Ihr {{ salesChannel.name }} Team
        MAIL;
    }

    // Admin Order Placed Content - English
    public static function getAdminOrderPlacedContentHtmlEn(): string
    {
        return <<<MAIL
        <div style="font-family:arial; font-size:12px;">
            {% set currencyIsoCode = order.currency.isoCode %}
            {% set pickupTz = pickupOrderLocation.pickupLocation.timezone|default('UTC') %}
            A new pickup order has been placed and requires preparation for collection.<br>
            <br>
            Order number: {{ order.orderNumber }}<br>
            Customer: {{ order.orderCustomer.firstName }} {{ order.orderCustomer.lastName }}<br>
            Ordered on: {{ order.orderDateTime|format_datetime('medium', 'short', locale='en-GB') }}<br>
            {% set pickupTime = pickupOrderLocation.pickupTime %}
            {% if pickupTime %}Requested pickup time: {{ pickupTime|format_datetime('medium', 'short', locale='en-GB', timezone=pickupTz) }}<br>{% endif %}
            {% if pickupOrderLocation.comment %}Customer note: {{ pickupOrderLocation.comment }}<br>{% endif %}
            <br>
            Please prepare the order for pickup and ensure it is ready when the customer arrives.<br>
            <br>

            <strong>Order details:</strong><br>
            <br>

            <table border="0" style="font-family:Arial, Helvetica, sans-serif; font-size:12px;">
                <tr>
                    <td bgcolor="#F7F7F2" style="border-bottom:1px solid #cccccc;"><strong>Prod. no.</strong></td>
                    <td bgcolor="#F7F7F2" style="border-bottom:1px solid #cccccc;"><strong>Product image</strong></td>
                    <td bgcolor="#F7F7F2" style="border-bottom:1px solid #cccccc;"><strong>Description</strong></td>
                    <td bgcolor="#F7F7F2" style="border-bottom:1px solid #cccccc;"><strong>Quantities</strong></td>
                    <td bgcolor="#F7F7F2" style="border-bottom:1px solid #cccccc;"><strong>Price</strong></td>
                    <td bgcolor="#F7F7F2" style="border-bottom:1px solid #cccccc;"><strong>Total</strong></td>
                </tr>

                {% for lineItem in order.nestedLineItems %}
                    {% set nestingLevel = 0 %}
                    {% set nestedItem = lineItem %}
                    {% block lineItem %}
                        <tr>
                            <td>{% if nestedItem.payload.productNumber is defined %}{{ nestedItem.payload.productNumber|u.wordwrap(80) }}{% endif %}</td>
                            <td>{% if nestedItem.cover is defined and nestedItem.cover is not null %}<img src="{{ nestedItem.cover.url }}" width="75" height="auto"/>{% endif %}</td>
                            <td>
                                {% if nestingLevel > 0 %}
                                    {% for i in 1..nestingLevel %}
                                        <span style="position: relative;">
                                    <span style="display: inline-block;
                                        position: absolute;
                                        width: 6px;
                                        height: 20px;
                                        top: 0;
                                        border-left:  2px solid rgba(0, 0, 0, 0.15);
                                        margin-left: {{ i * 10 }}px;"></span>
                                </span>
                                    {% endfor %}
                                {% endif %}

                                <div{% if nestingLevel > 0 %} style="padding-left: {{ (nestingLevel + 1) * 10 }}px"{% endif %}>
                                    {{ nestedItem.label|u.wordwrap(80) }}
                                </div>

                                {% if nestedItem.payload.options is defined and nestedItem.payload.options|length >= 1 %}
                                    <div>
                                        {% for option in nestedItem.payload.options %}
                                            {{ option.group }}: {{ option.option }}
                                            {% if nestedItem.payload.options|last != option %}
                                                {{ " | " }}
                                            {% endif %}
                                        {% endfor %}
                                    </div>
                                {% endif %}

                                {% if nestedItem.payload.features is defined and nestedItem.payload.features|length >= 1 %}
                                    {% set referencePriceFeatures = nestedItem.payload.features|filter(feature => feature.type == 'referencePrice') %}
                                    {% if referencePriceFeatures|length >= 1 %}
                                        {% set referencePriceFeature = referencePriceFeatures|first %}
                                        <div>
                                            {{ referencePriceFeature.value.purchaseUnit }} {{ referencePriceFeature.value.unitName }}
                                            ({{ referencePriceFeature.value.price|currency(currencyIsoCode) }} per {{ referencePriceFeature.value.referenceUnit }} {{ referencePriceFeature.value.unitName }})
                                        </div>
                                    {% endif %}
                                {% endif %}
                            </td>
                            <td style="text-align: center">{{ nestedItem.quantity }}</td>
                            <td>{{ nestedItem.unitPrice|currency(currencyIsoCode) }}</td>
                            <td>{{ nestedItem.totalPrice|currency(currencyIsoCode) }}</td>
                        </tr>

                        {% if nestedItem.children.count > 0 %}
                            {% set nestingLevel = nestingLevel + 1 %}
                            {% for lineItem in nestedItem.children %}
                                {% set nestedItem = lineItem %}
                                {{ block('lineItem') }}
                            {% endfor %}
                        {% endif %}
                    {% endblock %}
                {% endfor %}
            </table>

            {% set delivery = order.deliveries.first %}

            {% set displayRounded = order.totalRounding.interval != 0.01 or order.totalRounding.decimals != order.itemRounding.decimals %}
            {% set decimals = order.totalRounding.decimals %}
            {% set total = order.price.totalPrice %}
            {% if displayRounded %}
                {% set total = order.price.rawTotal %}
                {% set decimals = order.itemRounding.decimals %}
            {% endif %}
            <p>
                <br>
                <br>
                {% for shippingCost in order.deliveries %}
                    Shipping costs: {{ shippingCost.shippingCosts.totalPrice|currency(currencyIsoCode) }}<br>
                {% endfor %}

                Net total: {{ order.amountNet|currency(currencyIsoCode) }}<br>
                {% for calculatedTax in order.price.calculatedTaxes %}
                    {% if order.taxStatus is same as('net') %}plus{% else %}including{% endif %} {{ calculatedTax.taxRate }}% VAT. {{ calculatedTax.tax|currency(currencyIsoCode) }}<br>
                {% endfor %}
                {% if not displayRounded %}<strong>{% endif %}Total gross: {{ total|currency(currencyIsoCode,decimals=decimals) }}{% if not displayRounded %}</strong>{% endif %}<br>
                {% if displayRounded %}
                    <strong>Rounded total gross: {{ order.price.totalPrice|currency(currencyIsoCode,decimals=order.totalRounding.decimals) }}</strong><br>
                {% endif %}
                <br>

                {% if order.transactions is defined and order.transactions is not empty %}
                    <strong>Selected payment type:</strong> {{ order.transactions.first.paymentMethod.translated.name }}<br>
                    {{ order.transactions.first.paymentMethod.translated.description }}<br>
                    <br>
                {% endif %}

                {% if delivery %}
                    <strong>Selected shipping type:</strong> {{ delivery.shippingMethod.translated.name }}<br>
                    {{ delivery.shippingMethod.translated.description }}<br>
                    <br>
                {% endif %}

                {% set billingAddress = order.addresses.get(order.billingAddressId) %}
                <strong>Billing address:</strong><br>
                {{ billingAddress.company }}<br>
                {{ billingAddress.firstName }} {{ billingAddress.lastName }}<br>
                {{ billingAddress.street }} <br>
                {{ billingAddress.zipcode }} {{ billingAddress.city }}<br>
                {{ billingAddress.country.translated.name }}<br>
                <br>

                {% if delivery %}
                    <strong>Shipping address:</strong><br>
                    {{ delivery.shippingOrderAddress.company }}<br>
                    {{ delivery.shippingOrderAddress.firstName }} {{ delivery.shippingOrderAddress.lastName }}<br>
                    {{ delivery.shippingOrderAddress.street }} <br>
                    {{ delivery.shippingOrderAddress.zipcode}} {{ delivery.shippingOrderAddress.city }}<br>
                    {{ delivery.shippingOrderAddress.country.translated.name }}<br>
                    <br>
                {% endif %}
                {% if order.orderCustomer.vatIds %}
                    Customer VAT-ID: {{ order.orderCustomer.vatIds|first }}<br>
                {% endif %}
                <br>
                Please verify the order details, selected payment method, and shipping information before handing over the pickup.
                <br>
                If additional customer communication is required, follow up directly with the customer using the contact details on the order.
                <br>
                {% if a11yDocuments is defined and a11yDocuments is not empty %}
                    <br>
                    Accessible order documents are available for internal review:<br><br>
                    <ul>
                        {% for a11y in a11yDocuments %}
                            {% set documentLink = rawUrl(
                                'frontend.account.order.single.document.a11y',
                                {
                                    documentId: a11y.documentId,
                                    deepLinkCode: a11y.deepLinkCode,
                                    fileType: a11y.fileExtension,
                                },
                                salesChannel.domains|first.url
                            )%}
                            <li><a href="{{ documentLink }}" target="_blank">{{ documentLink }}</a></li>
                        {% endfor %}
                    </ul>
                {% endif %}
            </p>
            <br>
        </div>
        MAIL;
    }

    public static function getAdminOrderPlacedContentPlainEn(): string
    {
        return <<<MAIL
        {% set currencyIsoCode = order.currency.isoCode %}
        {% set pickupTz = pickupOrderLocation.pickupLocation.timezone|default('UTC') %}
        A new pickup order has been placed and requires preparation for collection.

        Order number: {{ order.orderNumber }}
        Customer: {{ order.orderCustomer.firstName }} {{ order.orderCustomer.lastName }}
        Ordered on: {{ order.orderDateTime|format_datetime('medium', 'short', locale='en-GB') }}
        {% set pickupTime = pickupOrderLocation.pickupTime %}
        {% if pickupTime %}Requested pickup time: {{ pickupTime|format_datetime('medium', 'short', locale='en-GB', timezone=pickupTz) }}
        {% endif %}
        {% if pickupOrderLocation.comment %}Customer note: {{ pickupOrderLocation.comment }}
        {% endif %}

        Please prepare the order for pickup and ensure it is ready when the customer arrives.

        Order details:

        {% for lineItem in order.lineItems %}
        Pos. {{ loop.index }}
        ---------------------
        {% if lineItem.payload.productNumber is defined %}
        Product number {{ lineItem.payload.productNumber|u.wordwrap(80) }},
        {% endif %}
        {% if nestedItem.cover is defined and nestedItem.cover is not null %}
        Image {{ lineItem.cover.alt }},
        {% endif %}
        Description {{ lineItem.label|u.wordwrap(80) }},
        {% if lineItem.payload.options is defined and lineItem.payload.options|length >= 1 %}
        {% for option in lineItem.payload.options %}
        {{ option.group }}: {{ option.option }}{{ ", " }}
        {% endfor %}
        {% endif %}
        {% if lineItem.payload.features is defined and lineItem.payload.features|length >= 1 %}
        {% set referencePriceFeatures = lineItem.payload.features|filter(feature => feature.type == 'referencePrice') %}
        {% if referencePriceFeatures|length >= 1 %}
        {% set referencePriceFeature = referencePriceFeatures|first %}
        {{ referencePriceFeature.value.purchaseUnit }} {{ referencePriceFeature.value.unitName }}({{ referencePriceFeature.value.price|currency(currencyIsoCode) }} per {{ referencePriceFeature.value.referenceUnit }} {{ referencePriceFeature.value.unitName }}),
        {% endif %}
        {% endif %}
        Quantity {{ lineItem.quantity }},
        Price {{ lineItem.unitPrice|currency(currencyIsoCode) }},
        Total {{ lineItem.totalPrice|currency(currencyIsoCode) }},

        {% endfor %}
        {% set delivery = order.deliveries.first %}
        {% set displayRounded = order.totalRounding.interval != 0.01 or order.totalRounding.decimals != order.itemRounding.decimals %}
        {% set decimals = order.totalRounding.decimals %}
        {% set total = order.price.totalPrice %}
        {% if displayRounded %}
            {% set total = order.price.rawTotal %}
            {% set decimals = order.itemRounding.decimals %}
        {% endif %}
        {% for shippingCost in order.deliveries %}
        Shipping costs: {{ shippingCost.shippingCosts.totalPrice|currency(currencyIsoCode) }}
        {% endfor %}
        Net total: {{ order.amountNet|currency(currencyIsoCode) }}
        {% for calculatedTax in order.price.calculatedTaxes %}
        {% if order.taxStatus is same as('net') %}plus{% else %}including{% endif %} {{ calculatedTax.taxRate }}% VAT. {{ calculatedTax.tax|currency(currencyIsoCode) }}
        {% endfor %}
        Total gross: {{ total|currency(currencyIsoCode,decimals=decimals) }}
        {% if displayRounded %}
        Rounded total gross: {{ order.price.totalPrice|currency(currencyIsoCode,decimals=order.totalRounding.decimals) }}
        {% endif %}

        {% if order.transactions is defined and order.transactions is not empty %}
        Selected payment type: {{ order.transactions.first.paymentMethod.translated.name }}
        {{ order.transactions.first.paymentMethod.translated.description }}
        {% endif %}

        {% if delivery %}
        Selected shipping type: {{ delivery.shippingMethod.translated.name }}
        {{ delivery.shippingMethod.translated.description }}
        {% endif %}
        {% set billingAddress = order.addresses.get(order.billingAddressId) %}
        Billing address:
        {{ billingAddress.company }}
        {{ billingAddress.firstName }} {{ billingAddress.lastName }}
        {{ billingAddress.street }}
        {{ billingAddress.zipcode }} {{ billingAddress.city }}
        {{ billingAddress.country.translated.name }}

        {% if delivery %}
        Shipping address:
        {{ delivery.shippingOrderAddress.company }}
        {{ delivery.shippingOrderAddress.firstName }} {{ delivery.shippingOrderAddress.lastName }}
        {{ delivery.shippingOrderAddress.street }}
        {{ delivery.shippingOrderAddress.zipcode}} {{ delivery.shippingOrderAddress.city }}
        {{ delivery.shippingOrderAddress.country.translated.name }}
        {% endif %}

        {% if order.orderCustomer.vatIds %}
        Customer VAT-ID: {{ order.orderCustomer.vatIds|first }}
        {% endif %}

        Please verify the order details, selected payment method, and shipping information before handing over the pickup.
        If additional customer communication is required, follow up directly with the customer using the contact details on the order.

        {% if a11yDocuments is defined and a11yDocuments is not empty %}
        Accessible order documents are available for internal review:

        {% for a11y in a11yDocuments %}
        {% set documentLink = rawUrl(
            'frontend.account.order.single.document.a11y',
            {
                documentId: a11y.documentId,
                'deepLinkCode': a11y.deepLinkCode,
                fileType: a11y.fileExtension,
            },
            salesChannel.domains|first.url
        )%}
        - {{ documentLink }}
        {% endfor %}
        {% endif %}
        MAIL;
    }

    // Admin Order Placed Content - German
    public static function getAdminOrderPlacedContentHtmlDe(): string
    {
        return <<<MAIL
        <div style="font-family:arial; font-size:12px;">

            {% set currencyIsoCode = order.currency.isoCode %}
            {% set pickupTz = pickupOrderLocation.pickupLocation.timezone|default('UTC') %}

            Eine neue Abholbestellung wurde aufgegeben und muss für die Abholung vorbereitet werden.<br>
            <br>
            Bestellnummer: {{ order.orderNumber }}<br>
            Kunde: {{ order.orderCustomer.firstName }} {{ order.orderCustomer.lastName }}<br>
            Bestelldatum: {{ order.orderDateTime|format_datetime('medium', 'short', locale='de-DE') }}<br>
            {% set pickupTime = pickupOrderLocation.pickupTime %}
            {% if pickupTime %}Gewünschte Abholzeit: {{ pickupTime|format_datetime('medium', 'short', locale='de-DE', timezone=pickupTz) }}<br>{% endif %}
            {% if pickupOrderLocation.comment %}Kundenhinweis: {{ pickupOrderLocation.comment }}<br>{% endif %}
            <br>
            Bitte bereiten Sie die Bestellung zur Abholung durch den Kunden vor.<br>

            <br>
            <strong>Bestelldetails:</strong><br>
            <br>

            <table border="0" style="font-family:Arial, Helvetica, sans-serif; font-size:12px;">
                <tr>
                    <td bgcolor="#F7F7F2" style="border-bottom:1px solid #cccccc;"><strong>Produkt-Nr.</strong></td>
                    <td bgcolor="#F7F7F2" style="border-bottom:1px solid #cccccc;"><strong>Produktbild</strong></td>
                    <td bgcolor="#F7F7F2" style="border-bottom:1px solid #cccccc;"><strong>Bezeichnung</strong></td>
                    <td bgcolor="#F7F7F2" style="border-bottom:1px solid #cccccc;"><strong>Menge</strong></td>
                    <td bgcolor="#F7F7F2" style="border-bottom:1px solid #cccccc;"><strong>Preis</strong></td>
                    <td bgcolor="#F7F7F2" style="border-bottom:1px solid #cccccc;"><strong>Summe</strong></td>
                </tr>

                {% for lineItem in order.nestedLineItems %}
                    {% set nestingLevel = 0 %}
                    {% set nestedItem = lineItem %}
                    {% block lineItem %}
                        <tr>
                            <td>{% if nestedItem.payload.productNumber is defined %}{{ nestedItem.payload.productNumber|u.wordwrap(80) }}{% endif %}</td>
                            <td>{% if nestedItem.cover is defined and nestedItem.cover is not null %}<img src="{{ nestedItem.cover.url }}" width="75" height="auto"/>{% endif %}</td>
                            <td>
                                {% if nestingLevel > 0 %}
                                    {% for i in 1..nestingLevel %}
                                        <span style="position: relative;">
                                        <span style="display: inline-block;
                                            position: absolute;
                                            width: 6px;
                                            height: 20px;
                                            top: 0;
                                            border-left:  2px solid rgba(0, 0, 0, 0.15);
                                            margin-left: {{ i * 10 }}px;"></span>
                                    </span>
                                    {% endfor %}
                                {% endif %}

                                <div{% if nestingLevel > 0 %} style="padding-left: {{ (nestingLevel + 1) * 10 }}px"{% endif %}>
                                    {{ nestedItem.label|u.wordwrap(80) }}
                                </div>

                                {% if nestedItem.payload.options is defined and nestedItem.payload.options|length >= 1 %}
                                    <div>
                                        {% for option in nestedItem.payload.options %}
                                            {{ option.group }}: {{ option.option }}
                                            {% if nestedItem.payload.options|last != option %}
                                                {{ " | " }}
                                            {% endif %}
                                        {% endfor %}
                                    </div>
                                {% endif %}

                                {% if nestedItem.payload.features is defined and nestedItem.payload.features|length >= 1 %}
                                    {% set referencePriceFeatures = nestedItem.payload.features|filter(feature => feature.type == 'referencePrice') %}
                                    {% if referencePriceFeatures|length >= 1 %}
                                        {% set referencePriceFeature = referencePriceFeatures|first %}
                                        <div>
                                            {{ referencePriceFeature.value.purchaseUnit }} {{ referencePriceFeature.value.unitName }}
                                            ({{ referencePriceFeature.value.price|currency(currencyIsoCode) }} pro {{ referencePriceFeature.value.referenceUnit }} {{ referencePriceFeature.value.unitName }})
                                        </div>
                                    {% endif %}
                                {% endif %}
                            </td>
                            <td style="text-align: center">{{ nestedItem.quantity }}</td>
                            <td>{{ nestedItem.unitPrice|currency(currencyIsoCode) }}</td>
                            <td>{{ nestedItem.totalPrice|currency(currencyIsoCode) }}</td>
                        </tr>

                        {% if nestedItem.children.count > 0 %}
                            {% set nestingLevel = nestingLevel + 1 %}
                            {% for lineItem in nestedItem.children %}
                                {% set nestedItem = lineItem %}
                                {{ block('lineItem') }}
                            {% endfor %}
                        {% endif %}
                    {% endblock %}
                {% endfor %}
            </table>

            {% set delivery = order.deliveries.first %}

            {% set displayRounded = order.totalRounding.interval != 0.01 or order.totalRounding.decimals != order.itemRounding.decimals %}
            {% set decimals = order.totalRounding.decimals %}
            {% set total = order.price.totalPrice %}
            {% if displayRounded %}
                {% set total = order.price.rawTotal %}
                {% set decimals = order.itemRounding.decimals %}
            {% endif %}
            <p>
                <br>
                <br>
                {% for shippingCost in order.deliveries %}
                    Versandkosten: {{ shippingCost.shippingCosts.totalPrice|currency(currencyIsoCode) }}<br>
                {% endfor %}
                Gesamtkosten Netto: {{ order.amountNet|currency(currencyIsoCode) }}<br>
                {% for calculatedTax in order.price.calculatedTaxes %}
                    {% if order.taxStatus is same as('net') %}zzgl.{% else %}inkl.{% endif %} {{ calculatedTax.taxRate }}% MwSt. {{ calculatedTax.tax|currency(currencyIsoCode) }}<br>
                {% endfor %}
                {% if not displayRounded %}<strong>{% endif %}Gesamtkosten Brutto: {{ total|currency(currencyIsoCode,decimals=decimals) }}{% if not displayRounded %}</strong>{% endif %}<br>
                {% if displayRounded %}
                    <strong>Gesamtkosten Brutto gerundet: {{ order.price.totalPrice|currency(currencyIsoCode,decimals=order.totalRounding.decimals) }}</strong><br>
                {% endif %}
                <br>

                {% if order.transactions is defined and order.transactions is not empty %}
                    <strong>Gewählte Zahlungsart:</strong> {{ order.transactions.first.paymentMethod.translated.name }}<br>
                    {{ order.transactions.first.paymentMethod.translated.description }}<br>
                    <br>
                {% endif %}

                {% if delivery %}
                    <strong>Gewählte Versandart:</strong> {{ delivery.shippingMethod.translated.name }}<br>
                    {{ delivery.shippingMethod.translated.description }}<br>
                    <br>
                {% endif %}

                {% set billingAddress = order.addresses.get(order.billingAddressId) %}
                <strong>Rechnungsadresse:</strong><br>
                {{ billingAddress.company }}<br>
                {{ billingAddress.firstName }} {{ billingAddress.lastName }}<br>
                {{ billingAddress.street }} <br>
                {{ billingAddress.zipcode }} {{ billingAddress.city }}<br>
                {{ billingAddress.country.translated.name }}<br>
                <br>

                {% if delivery %}
                    <strong>Lieferadresse:</strong><br>
                    {{ delivery.shippingOrderAddress.company }}<br>
                    {{ delivery.shippingOrderAddress.firstName }} {{ delivery.shippingOrderAddress.lastName }}<br>
                    {{ delivery.shippingOrderAddress.street }} <br>
                    {{ delivery.shippingOrderAddress.zipcode}} {{ delivery.shippingOrderAddress.city }}<br>
                    {{ delivery.shippingOrderAddress.country.translated.name }}<br>
                    <br>
                {% endif %}
                {% if order.orderCustomer.vatIds %}
                    Kunden-USt-ID: {{ order.orderCustomer.vatIds|first }}<br>
                {% endif %}
                <br>
                Bitte prüfen Sie die Bestelldaten, die gewählte Zahlungsart und die Versandinformationen, bevor Sie die Abholung an den Kunden übergeben.
                <br>
                Wenn weitere Kundenkommunikation erforderlich ist, nehmen Sie direkt mit dem Kunden Kontakt auf.
                <br>
                {% if a11yDocuments is defined and a11yDocuments is not empty %}
                    <br>
                    Barrierefreie Dokumente stehen zur internen Prüfung zur Verfügung:<br><br>
                    <ul>
                        {% for a11y in a11yDocuments %}
                            {% set documentLink = rawUrl(
                                'frontend.account.order.single.document.a11y',
                                {
                                    documentId: a11y.documentId,
                                    deepLinkCode: a11y.deepLinkCode,
                                    fileType: a11y.fileExtension,
                                },
                                salesChannel.domains|first.url
                            )%}
                            <li><a href="{{ documentLink }}" target="_blank">{{ documentLink }}</a></li>
                        {% endfor %}
                    </ul>
                {% endif %}
            </p>
            <br>
        </div>
        MAIL;
    }

    public static function getAdminOrderPlacedContentPlainDe(): string
    {
        return <<<MAIL
        {% set currencyIsoCode = order.currency.isoCode %}
        {% set pickupTz = pickupOrderLocation.pickupLocation.timezone|default('UTC') %}
        Eine neue Abholbestellung wurde aufgegeben und muss für die Abholung vorbereitet werden.

        Bestellnummer: {{ order.orderNumber }}
        Kunde: {{ order.orderCustomer.firstName }} {{ order.orderCustomer.lastName }}
        Bestelldatum: {{ order.orderDateTime|format_datetime('medium', 'short', locale='de-DE') }}
        {% set pickupTime = pickupOrderLocation.pickupTime %}
        {% if pickupTime %}Gewünschte Abholzeit: {{ pickupTime|format_datetime('medium', 'short', locale='de-DE', timezone=pickupTz) }}
        {% endif %}
        {% if pickupOrderLocation.comment %}Kundenhinweis: {{ pickupOrderLocation.comment }}
        {% endif %}

        Bitte bereiten Sie die Bestellung zur Abholung vor und stellen Sie sicher, dass sie zum Zeitpunkt der Abholung bereit ist.

        Bestelldetails:

        {% for lineItem in order.lineItems %}
        Pos. {{ loop.index }}
        ---------------------
        {% if lineItem.payload.productNumber is defined %}
        Artikel-Nr. {{ lineItem.payload.productNumber|u.wordwrap(80) }},
        {% endif %}
        {% if nestedItem.cover is defined and nestedItem.cover is not null %}
        Produktbild {{ lineItem.cover.alt }},
        {% endif %}
        Beschreibung {{ lineItem.label|u.wordwrap(80) }},
        {% if lineItem.payload.options is defined and lineItem.payload.options|length >= 1 %}
        {% for option in lineItem.payload.options %}
        {{ option.group }}: {{ option.option }}{{ ", " }}
        {% endfor %}
        {% endif %}
        {% if lineItem.payload.features is defined and lineItem.payload.features|length >= 1 %}
        {% set referencePriceFeatures = lineItem.payload.features|filter(feature => feature.type == 'referencePrice') %}
        {% if referencePriceFeatures|length >= 1 %}
        {% set referencePriceFeature = referencePriceFeatures|first %}
        {{ referencePriceFeature.value.purchaseUnit }} {{ referencePriceFeature.value.unitName }}({{ referencePriceFeature.value.price|currency(currencyIsoCode) }} pro {{ referencePriceFeature.value.referenceUnit }} {{ referencePriceFeature.value.unitName }}),
        {% endif %}
        {% endif %}
        Menge {{ lineItem.quantity }},
        Preis {{ lineItem.unitPrice|currency(currencyIsoCode) }},
        Summe {{ lineItem.totalPrice|currency(currencyIsoCode) }},

        {% endfor %}
        {% set delivery = order.deliveries.first %}
        {% set displayRounded = order.totalRounding.interval != 0.01 or order.totalRounding.decimals != order.itemRounding.decimals %}
        {% set decimals = order.totalRounding.decimals %}
        {% set total = order.price.totalPrice %}
        {% if displayRounded %}
            {% set total = order.price.rawTotal %}
            {% set decimals = order.itemRounding.decimals %}
        {% endif %}
        {% for shippingCost in order.deliveries %}
        Versandkosten: {{ shippingCost.shippingCosts.totalPrice|currency(currencyIsoCode) }}
        {% endfor %}
        Gesamtkosten Netto: {{ order.amountNet|currency(currencyIsoCode) }}
        {% for calculatedTax in order.price.calculatedTaxes %}
        {% if order.taxStatus is same as('net') %}zzgl.{% else %}inkl.{% endif %} {{ calculatedTax.taxRate }}% MwSt. {{ calculatedTax.tax|currency(currencyIsoCode) }}
        {% endfor %}
        Gesamtkosten Brutto: {{ total|currency(currencyIsoCode,decimals=decimals) }}
        {% if displayRounded %}
        Gesamtkosten Brutto gerundet: {{ order.price.totalPrice|currency(currencyIsoCode,decimals=order.totalRounding.decimals) }}
        {% endif %}

        {% if order.transactions is defined and order.transactions is not empty %}
        Gewählte Zahlungsart: {{ order.transactions.first.paymentMethod.translated.name }}
        {{ order.transactions.first.paymentMethod.translated.description }}
        {% endif %}

        {% if delivery %}
        Gewählte Versandart: {{ delivery.shippingMethod.translated.name }}
        {{ delivery.shippingMethod.translated.description }}
        {% endif %}
        {% set billingAddress = order.addresses.get(order.billingAddressId) %}
        Rechnungsadresse:
        {{ billingAddress.company }}
        {{ billingAddress.firstName }} {{ billingAddress.lastName }}
        {{ billingAddress.street }}
        {{ billingAddress.zipcode }} {{ billingAddress.city }}
        {{ billingAddress.country.translated.name }}

        {% if delivery %}
        Lieferadresse:
        {{ delivery.shippingOrderAddress.company }}
        {{ delivery.shippingOrderAddress.firstName }} {{ delivery.shippingOrderAddress.lastName }}
        {{ delivery.shippingOrderAddress.street }}
        {{ delivery.shippingOrderAddress.zipcode}} {{ delivery.shippingOrderAddress.city }}
        {{ delivery.shippingOrderAddress.country.translated.name }}
        {% endif %}

        {% if order.orderCustomer.vatIds %}
        Kunden-USt-ID: {{ order.orderCustomer.vatIds|first }}
        {% endif %}

        Bitte prüfen Sie die Bestelldaten, die gewählte Zahlungsart und die Versandinformationen, bevor Sie die Abholung an den Kunden übergeben.
        Wenn weitere Kundenkommunikation erforderlich ist, nehmen Sie direkt mit dem Kunden Kontakt auf.

        {% if a11yDocuments is defined and a11yDocuments is not empty %}
        Barrierefreie Dokumente stehen zur internen Prüfung zur Verfügung:

        {% for a11y in a11yDocuments %}
        {% set documentLink = rawUrl(
            'frontend.account.order.single.document.a11y',
            {
                documentId: a11y.documentId,
                deepLinkCode: a11y.deepLinkCode,
                fileType: a11y.fileExtension,
            },
            salesChannel.domains|first.url
        )%}
        - {{ documentLink }}
        {% endfor %}
        {% endif %}
        MAIL;
    }
}
