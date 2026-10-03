<?php

namespace Modules\Billing\Events;

/** The provider says this trial ends soon, and will charge the card when it does. */
class TrialEnding extends SubscriptionEvent {}
