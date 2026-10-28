# Changelog

## 2.2.0

- The instalments can be paid for the balance left by another means of payment, a gift card for one: the module declares `Thelia\Module\BalanceChargingPaymentModuleInterface`, and the first payment and the instalments are cut from the amount Payzen charges (`getAmountToCharge()`), not from the order total.
- Requires the Payzen module 3.2.0 or later, which sends that amount, and Thelia 3.3.0 or later, where the interface exists.
