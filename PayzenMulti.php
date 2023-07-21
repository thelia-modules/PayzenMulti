<?php
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
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\HttpFoundation\Response;
use Thelia\Core\Translation\Translator;
use Thelia\Model\Order;

class PayzenMulti extends Payzen
{
    const MODULE_DOMAIN = "payzenmulti";

    public function postActivation(ConnectionInterface $con = null): void
    {
        //Declare postActivation for inherit from Payzen and don't clear payzen data on activation
    }

    /**
     * @return boolean true to allow usage of this payment module, false otherwise.
     */
    public function isValidPayment(): bool
    {
        $valid = false;

        $mode = PayzenConfigQuery::read('mode', false);

        // If we're in test mode, do not display Payzen on the front office, except for allowed IP addresses.
        if ('TEST' == $mode) {

            $raw_ips = explode("\n", PayzenConfigQuery::read('allowed_ip_list', ''));

            $allowed_client_ips = array();

            foreach ($raw_ips as $ip) {
                $allowed_client_ips[] = trim($ip);
            }

            $client_ip = $this->getRequest()->getClientIp();

            $valid = in_array($client_ip, $allowed_client_ips);

        } elseif ('PRODUCTION' == $mode) {
            $valid = true;
        }

        if ($valid) {
            // Check if total order amount is in the module's limits
            $valid = $this->checkMinMaxAmount();
        }

        if ($valid) {
            $this->getDispatcher()->dispatch(new ValidationPaymentEvent(), TheliaEvents::MODULE_PAYMENT_IS_VALID);
        }

        return $valid;
    }

    public function getLabel(): string
    {
        $count    = PayzenConfigQuery::read('multi_number_of_payments', 4);
        return Translator::getInstance()->trans("Pay with Payzen in '%s' times", ['%s' => $count], PayzenMulti::MODULE_DOMAIN);
    }

    /**
     *
     *  Method used by payment gateway.
     *
     *  If this method return a \Thelia\Core\HttpFoundation\Response instance, this response is sent to the
     *  browser.
     *
     *  In many cases, it's necessary to send a form to the payment gateway.
     *  On your response you can return this form already
     *  completed, ready to be sent
     *
     * @param Order $order processed order
     * @return Response the HTTP response
     * @throws PropelException
     */
    public function pay(Order $order): Response
    {
        return $this->doPay($order, 'MULTI');
    }


    /**
     * Check if total order amount is in the module's limits
     *
     * @return bool true if the current order total is within the min and max limits
     */
    protected function checkMinMaxAmount(): bool
    {
        // Check if total order amount is in the module's limits
        $order_total = $this->getCurrentOrderTotalAmount();

        $min_amount = PayzenConfigQuery::read('multi_minimum_amount', 0);
        $max_amount = PayzenConfigQuery::read('multi_maximum_amount', 0);

        return $order_total > 0 &&
        ($min_amount <= 0 || $order_total >= $min_amount) &&
        ($max_amount <= 0 || $order_total <= $max_amount);
    }

    public static function configureServices(ServicesConfigurator $servicesConfigurator): void
    {
        $servicesConfigurator->load(self::getModuleCode().'\\', __DIR__)
            ->exclude([THELIA_MODULE_DIR . ucfirst(self::getModuleCode()). "/I18n/*"])
            ->autowire()
            ->autoconfigure();
    }
}
