<?php
/**
 * Author-mapping fixture for the reassign-terms Behat characterisation scenarios.
 *
 * The reassign-terms subcommand documents an --author-mapping=<file> flag whose
 * file is expected to define $cli_user_map (old user_login => new user_login).
 * See php/cli/class-reassign-terms-command.php.
 *
 * @package Automattic\CoAuthorsPlus
 */

$cli_user_map = array(
	'olduser' => 'newuser',
);
