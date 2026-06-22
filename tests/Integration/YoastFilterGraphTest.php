<?php

namespace Automattic\CoAuthorsPlus\Tests\Integration;

use CoAuthors\Integrations\Yoast;

/**
 * Regression coverage for issue #1113.
 *
 * Yoast's `wpseo_schema_graph` filter can run on a singular request whose post
 * is not present in Yoast's indexable table, in which case the context carries
 * no post. Before the guard, `filter_graph()` dereferenced `$context->post->ID`
 * regardless, raising "Attempt to read property ID on null" and falling through
 * into Yoast-only code. The method must instead return the graph untouched.
 *
 * @covers \CoAuthors\Integrations\Yoast::filter_graph
 */
class YoastFilterGraphTest extends TestCase {

	public function test_filter_graph_returns_data_unchanged_when_context_post_is_null(): void {
		$post_id = $this->factory()->post->create();
		$this->go_to( get_permalink( $post_id ) );
		$this->assertTrue( is_singular(), 'The guard is only reached on a singular request.' );

		$context       = new \stdClass();
		$context->post = null;

		$data = array(
			array(
				'@type' => 'WebPage',
				'@id'   => 'https://example.com/#webpage',
			),
		);

		$this->assertSame(
			$data,
			Yoast::filter_graph( $data, $context ),
			'filter_graph() must return the graph unchanged when the context has no post.'
		);
	}

	public function test_filter_graph_returns_data_unchanged_when_context_post_id_is_empty(): void {
		$post_id = $this->factory()->post->create();
		$this->go_to( get_permalink( $post_id ) );
		$this->assertTrue( is_singular(), 'The guard is only reached on a singular request.' );

		$context           = new \stdClass();
		$context->post     = new \stdClass();
		$context->post->ID = 0;

		$data = array(
			array(
				'@type' => 'WebPage',
			),
		);

		$this->assertSame(
			$data,
			Yoast::filter_graph( $data, $context ),
			'filter_graph() must return the graph unchanged when the context post has no ID.'
		);
	}
}
