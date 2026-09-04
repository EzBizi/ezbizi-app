<?php

namespace App\Support\Newsletter;

use RuntimeException;

/**
 * A mailing that did not go, with a sentence a person can act on.
 *
 * The provider's own words are kept when there are any: "You must have a From
 * email address" ends a support conversation that "HTTP 400" starts.
 */
class NewsletterSendFailed extends RuntimeException
{
}
