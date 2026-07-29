<?php

declare(strict_types=1);

/*************************************************************************************/
/*      This file is part of the Thelia package.                                     */
/*                                                                                   */
/*      Copyright (c) OpenStudio                                                     */
/*      email : dev@thelia.net                                                       */
/*      web : http://www.thelia.net                                                  */
/*                                                                                   */
/*      For the full copyright and license information, please view the LICENSE.txt  */
/*      file that was distributed with this source code.                             */
/*************************************************************************************/

namespace PayzenMulti;

use Payzen\Model\PayzenConfigQuery;
use Payzen\Payzen;
use PayzenMulti\Event\ValidationPaymentEvent;
use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Exception\PropelException;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;
use Symfony\Component\HttpFoundation\Response;
use Thelia\Core\Translation\Translator;
use Thelia\Model\Order;

/**
 * Instalment payment through Payzen, layered on top of the single-payment Payzen module.
 *
 * Only what actually differs from Payzen is overridden — the payment mode, the amount limits and
 * the extra validation event — so the gateway logic lives in exactly one place.
 *
 * All configuration lives in Payzen's own screen, under "Multiple times payment": this module
 * deliberately ships no back-office, no template and no route.
 *
 * WARNING — do not use the inherited $this->trans() helper. Payzen::trans() passes
 * self::MODULE_DOMAIN, and `self::` is not a forwarding call: it always resolves to Payzen's
 * domain ("payzen"), never to this class's ("payzenmulti"). A string added here and translated
 * through that helper would be looked up in the wrong domain and silently rendered untranslated.
 * Call Translator::getInstance()->trans(..., self::MODULE_DOMAIN) directly instead, as
 * getLabel() does.
 */
class PayzenMulti extends Payzen
{
    public const MODULE_DOMAIN = 'payzenmulti';

    /**
     * Intentionally empty: Payzen::postActivation() creates the payzen_config table and seeds
     * its defaults. Letting it run from here would reset the parent module's configuration
     * every time this one is activated.
     */
    public function postActivation(?ConnectionInterface $con = null): void
    {
    }

    /**
     * Intentionally empty, and the counterpart of postActivation() above.
     *
     * Payzen::destroy() drops the payzen_config table and deletes Payzen's confirmation message
     * when $deleteModuleData is true. Inherited as-is, uninstalling THIS module with "delete
     * module data" would wipe the configuration of Payzen — gateway keys included — while Payzen
     * itself stays active.
     *
     * This module owns no data of its own (no table, no message, no config key: the multi_* keys
     * live in Payzen's table), so there is nothing legitimate for it to delete.
     */
    public function destroy(?ConnectionInterface $con = null, $deleteModuleData = false): void
    {
    }

    /**
     * Adds a project-level veto on top of Payzen's own checks.
     *
     * Delegates to the parent rather than repeating its test-mode / allowed-IP logic: that
     * implementation already calls $this->checkMinMaxAmount(), so the instalment limits below
     * apply through late static binding. Only the ValidationPaymentEvent is specific to this
     * module — it lets another module invalidate instalment payment without touching Payzen.
     */
    public function isValidPayment(): bool
    {
        if (!parent::isValidPayment()) {
            return false;
        }

        $validationEvent = (new ValidationPaymentEvent())->setValid(true);

        $this->getDispatcher()->dispatch(
            $validationEvent,
            ValidationPaymentEvent::PAYZEN_MULTI_VALIDATION_PAYEMENT
        );

        return $validationEvent->isValid();
    }

    /**
     * @throws PropelException
     */
    public function pay(Order $order): Response
    {
        return $this->doPay($order, 'MULTI');
    }

    /**
     * Amount limits specific to instalment payments (multi_* configuration keys), replacing the
     * single-payment limits the parent reads. Called by the parent's isValidPayment().
     */
    protected function checkMinMaxAmount(): bool
    {
        $orderTotal = $this->getCurrentOrderTotalAmount();

        $minimumAmount = (float) PayzenConfigQuery::read('multi_minimum_amount', '0');
        $maximumAmount = (float) PayzenConfigQuery::read('multi_maximum_amount', '0');

        return $orderTotal > 0
            && ($minimumAmount <= 0 || $orderTotal >= $minimumAmount)
            && ($maximumAmount <= 0 || $orderTotal <= $maximumAmount);
    }

    /**
     * Must stay overridden, even though the body mirrors the parent's.
     *
     * Payzen::configureServices() is written as
     * `load(self::getModuleCode().'\\', __DIR__)`. Inherited as-is that breaks: __DIR__ is
     * resolved where the code is *written* (Payzen's directory) while getModuleCode() uses
     * static::class and returns "PayzenMulti", so the container scans Payzen's directory
     * expecting PayzenMulti\* classes and aborts cache warmup with:
     *
     *     Expected to find class "PayzenMulti\Controller\ConfigurationController" in file
     *     ".../modules/Payzen/Controller/ConfigurationController.php"
     *
     * Redeclaring it here makes __DIR__ point at this module, which also keeps
     * auto-discovery working for anything added later (the Event/ directory, a future service).
     * Excludes use relative paths rather than THELIA_MODULE_DIR, the Thelia 2 idiom.
     */
    public static function configureServices(ServicesConfigurator $servicesConfigurator): void
    {
        $servicesConfigurator->load(self::getModuleCode().'\\', __DIR__)
            ->exclude([__DIR__.'/I18n/*', __DIR__.'/Config/*', __DIR__.'/PayzenMulti.php'])
            ->autowire()
            ->autoconfigure();
    }

    /**
     * Human-readable payment label, e.g. "Pay with Payzen in 4 times".
     *
     * Belongs to no contract (neither BaseModule nor PaymentModuleInterface declares it) and
     * nothing in Thelia 3 calls it: the checkout label comes from the module's i18n title, which
     * PaymentModuleService reads via setLocale()/getTitle(). Kept for any external caller, but
     * inert on the Thelia 3 front-office — see the Readme.
     */
    public function getLabel(): string
    {
        return Translator::getInstance()->trans(
            "Pay with Payzen in '%s' times",
            ['%s' => PayzenConfigQuery::read('multi_number_of_payments', '4')],
            self::MODULE_DOMAIN
        );
    }
}
