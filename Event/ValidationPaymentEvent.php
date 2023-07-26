<?php

namespace PayzenMulti\Event;

use Thelia\Core\Event\ActionEvent;

class ValidationPaymentEvent extends ActionEvent
{
    const PAYZEN_MULTI_VALIDATION_PAYEMENT = 'action.payzen.multi.validation.payment';

    private bool $valid;

    public function isValid(): bool
    {
        return $this->valid;
    }

    /**
     * @param bool $valid
     */
    public function setValid(bool $valid): static
    {
        $this->valid = $valid;
        return $this;
    }
}