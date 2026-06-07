<?php
/**
 * Co-authors Iterator class
 *
 * @package Automattic\CoAuthorsPlus
 */

/**
 * Co-authors iterator.
 */
class CoAuthorsIterator {
	public $position = - 1;
	public $original_authordata;
	public $current_author;
	public $authordata_array;
	public $count;

	public function __construct( $postID = 0 ) {
		global $post, $authordata;

		$postID = (int) $postID;

		if ( ! $postID && $post ) {
			$postID = $post->ID;
		}

		if ( ! $postID ) {
			wp_trigger_error( __METHOD__, 'No post ID provided for CoAuthorsIterator constructor. Are you not in a loop or is $post not set?' );
		}

		$this->original_authordata = $authordata;
		$this->current_author      = $authordata;
		$this->authordata_array    = get_coauthors( $postID );
		$this->count               = count( $this->authordata_array );
	}

	public function iterate(): bool {
		global $authordata;

		$this->position++;

		// At the end of the loop.
		if ( $this->position > $this->count - 1 ) {
			$authordata           = $this->original_authordata; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			$this->current_author = $this->original_authordata;
			$this->position       = - 1;

			return false;
		}

		// At the beginning of the loop.
		if ( 0 === $this->position && ! empty( $authordata ) ) {
			$this->original_authordata = $authordata;
		}

		$authordata           = $this->authordata_array[ $this->position ]; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$this->current_author = $this->authordata_array[ $this->position ];

		return true;
	}

	public function get_position() {
		return $this->position === - 1 ? false : $this->position;
	}

	public function is_last(): bool {
		return $this->position === $this->count - 1;
	}

	public function is_first(): bool {
		return $this->position === 0;
	}

	public function count(): int {
		return $this->count;
	}

	public function get_all() {
		return $this->authordata_array;
	}
}
