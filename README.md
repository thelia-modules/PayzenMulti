# Payzen Multi

This module offers to your customers the Payzen payment in several times, operated by the Lyra
Networks compagny.

Requires the [Payzen](https://github.com/Thelia-modules/Payzen) module **2.0 or later**: this
module extends its main class and reuses its configuration, its gateway call and its payment
form.

## Installation

### Manually

* Copy the module into ```<thelia_root>/local/modules/``` directory and be sure that the name of the module is PayzenMulti.
* Activate it in your thelia administration panel

### Composer

Add it in your main thelia composer.json file

```
composer require thelia/payzen-multi-module:~2.0
```

## Usage

To use the PayzenMulti module, you must first install the basic Payzen module:
`https://github.com/Thelia-modules/Payzen` and configure it.
Then all configurations for PayzenMulti are in the Payzen basic setup in the part
"Multiple times payment".

You can set a minimum and maximum amount to reach for the customer to choose this payment method,
if they are at 0 there'll no minimum or maximum.
You can also choose the value (in percent) of the first payment, the number payments to be made
by the customer and the interval between this payment.

## Thelia 3 support

The module works on Thelia 3, and keeps working on Thelia 2 — no version gate is needed (see
"Why no isThelia3()" below).

**It could not load at all before 2.1.0.** Payzen had been migrated with native return types
(`postActivation(): void`, `pay(): Response`, `isValidPayment(): bool`), while this module
overrode those same methods without any return type. PHP rejects that outright:

```
Declaration of PayzenMulti\PayzenMulti::postActivation(?ConnectionInterface $con = null)
must be compatible with Payzen\Payzen::postActivation(?ConnectionInterface $con = null): void
```

It also imported `Thelia\Core\HttpFoundation\Response`, a class Thelia 3 no longer ships — the
parent uses `Symfony\Component\HttpFoundation\Response`.

### What this module contains

Almost nothing, on purpose:

| | |
|---|---|
| PHP classes | **2** — `PayzenMulti` (extending `Payzen`) and `Event\ValidationPaymentEvent` |
| Templates | none — the payment form comes from Payzen |
| Controllers, hooks, services, loops, forms | none |
| Routes | none — configuration lives in Payzen's screen |
| Database tables | none — settings live in Payzen's `payzen_config` |

Only what genuinely differs from single payment is overridden: the payment mode (`MULTI`), the
amount limits (`multi_*` configuration keys) and the validation event.

### Vetoing instalment payment

`isValidPayment()` dispatches `ValidationPaymentEvent` (`action.payzen.multi.validation.payment`)
once Payzen's own checks have passed, so another module can hide instalment payment without
touching Payzen:

```php
public function onPayzenMultiValidation(ValidationPaymentEvent $event): void
{
    if (/* your rule */) {
        $event->setValid(false);
    }
}
```

That method **delegates to `parent::isValidPayment()`** instead of repeating its test-mode and
allowed-IP logic: the parent already calls `$this->checkMinMaxAmount()`, which this class
overrides, so the instalment limits apply through late static binding. The previous copy of those
~30 lines had drifted from the parent (loose `==` comparisons, non-strict `in_array`).

### Why no `isThelia3()`

Other bi-compatible modules in this ecosystem gate their Thelia 3 code paths on an
`isThelia3()` check. That is unnecessary here: adding a return type to a method whose parent
declares none is legal in PHP, so these signatures satisfy both an untyped and a typed Payzen.
There is no version-specific code to gate.

That said, **the real compatibility boundary is Payzen's, not this module's**. `composer.json`
requires `thelia/payzen-module: ~2.0`, and it is that version which determines whether the
pair runs on Thelia 2 or 3. An older Payzen (`~1.x`) would still be satisfied by this module's
code, but is excluded by the constraint. Loosen it only after checking which Payzen line actually
supports your target Thelia version.

### Inheriting from another module: two traps

Both are pre-existing behaviours of `Payzen`, worth knowing before touching this class.

- **Never use the inherited `$this->trans()`.** `Payzen::trans()` passes `self::MODULE_DOMAIN`,
  and `self::` is not a forwarding call: it always resolves to `"payzen"`, never to
  `"payzenmulti"`. A string translated through it would be looked up in the wrong domain and
  rendered untranslated, with no error. Use
  `Translator::getInstance()->trans(..., self::MODULE_DOMAIN)`, as `getLabel()` does.
- **`postActivation()` and `destroy()` are both overridden empty on purpose.** Payzen's versions
  create and drop the shared `payzen_config` table. Letting them run from here would reset
  Payzen's configuration on activation, and — with "delete module data" ticked — **drop Payzen's
  gateway configuration** when uninstalling this module, even though Payzen stays active. This
  module owns no data of its own.

The methods this module depends on (`doPay()`, `checkMinMaxAmount()`) form an implicit contract
with Payzen. A signature change there breaks this class at the next `composer update`, with no
automated guard — there are no tests in this module.

### Payment label on the Thelia 3 front-office

`getLabel()` returns "Pay with Payzen in 4 times", but **nothing in Thelia 3 calls it** — the
method belongs to no contract (neither `BaseModule` nor `PaymentModuleInterface` declares it).
On Thelia 3 the checkout label comes from the module's own i18n title, which
`Thelia\Domain\Module\Payment\PaymentModuleService` reads through `setLocale()`/`getTitle()`
before exposing it on `GET /api/front/payment/modules`.

So the number of instalments is **not** reflected in the label out of the box. To show it,
either edit the module title in the back-office, or add a listener on
`TheliaEvents::MODULE_PAYMENT_GET_OPTIONS` that publishes it as a payment option (see the
`PayPal` or `PayPlugOney` modules for that pattern).

## Changelog

### 2.1.0

- Thelia 3 support: return types aligned with the Payzen parent class, which the module could no
  longer extend (fatal error at class load)
- `Thelia\Core\HttpFoundation\Response` replaced with `Symfony\Component\HttpFoundation\Response`
  (the former no longer exists in Thelia 3)
- `declare(strict_types=1)`
- `isValidPayment()` now delegates to `parent::isValidPayment()` instead of repeating its
  test-mode/allowed-IP logic, while keeping the `ValidationPaymentEvent` dispatch
- **Fixed** the French label, which hardcoded "3 fois" and ignored the configured number of
  instalments
- `module.xml` required `Payzen >=2.O` — the letter O instead of a zero, a constraint no
  version could satisfy; fixed to `>=2.0.0`
- `module.xml` declares the `module-2_2.xsd` schema and `<thelia>2.5.0</thelia>`
- Removed the empty skeleton declarations from `config.xml`, `routing.xml` and `schema.xml`
- `destroy()` overridden empty: uninstalling this module with "delete module data" used to drop
  Payzen's shared `payzen_config` table and its confirmation message, wiping the gateway
  configuration of a module that stays active
