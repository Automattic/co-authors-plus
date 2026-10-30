<?php
/**
 * The co-authors-plus command namespace.
 *
 * @package Automattic\CoAuthorsPlus
 */

declare( strict_types=1 );

namespace Automattic\CoAuthorsPlus\CLI;

use WP_CLI\Dispatcher\CommandNamespace;

/**
 * Manage co-authors and guest authors.
 *
 * Every subcommand registers itself, which leaves WP-CLI to invent the
 * `co-authors-plus` namespace that contains them, and an invented namespace has
 * no description. Declaring it here restores the summary that used to come from
 * the docblock of the single class the commands once shared.
 */
class Command_Namespace extends CommandNamespace {
}
