<?php

namespace justinholtweb\bee\events;

use justinholtweb\bee\models\Interaction;
use yii\base\Event;

/**
 * Raised before an interaction is sent. Set `isValid = false` to drop it.
 */
class InteractionEvent extends Event
{
    public Interaction $interaction;

    public bool $isValid = true;
}
