<?php

namespace justinholtweb\bee\events;

use justinholtweb\bee\models\RecommendationSet;
use yii\base\Event;

/**
 * Raised after a recommendation request comes back and its elements have been resolved.
 *
 * Handlers can reorder or drop entries — the place to bolt on merchandising rules that belong to
 * the site rather than to the model.
 */
class RecommendationEvent extends Event
{
    public RecommendationSet $set;

    /** The request parameters that produced it. */
    public array $params = [];
}
