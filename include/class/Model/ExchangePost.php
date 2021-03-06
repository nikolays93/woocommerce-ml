<?php

namespace NikolayS93\Exchange\Model;

use NikolayS93\Exchange\ORM\ExchangeItemMeta;
use NikolayS93\Exchange\Plugin;
use NikolayS93\Exchange\ORM\Collection;

/**
 * Works with posts, term_relationships, postmeta
 */
class ExchangePost {
	use ExchangeItemMeta;

	public $warehouse = array();

	/**
	 * @var WP_Post
	 * @sql FROM $wpdb->posts
	 *      WHERE ID = %d
	 */
	private $post;

	function __construct( array $post, $ext = '', $meta = array() ) {
		$args = wp_parse_args( $post, array(
			'post_author'    => get_current_user_id(),
			'post_status'    => apply_filters( 'ExchangePost__post_status', 'publish' ),
			'comment_status' => apply_filters( 'ExchangePost__comment_status', 'closed' ),
			'post_type'      => 'product',
			'post_mime_type' => '',
		) );

		if ( empty( $args['post_name'] ) ) {
			$args['post_name'] = sanitize_title( \NikolayS93\Exchange\esc_cyr( $args['post_title'], false ) );
		}

		/**
		 * For no offer defaults
		 */
		$meta = wp_parse_args( $meta, array(
			'_price'         => 0,
			'_regular_price' => 0,
			'_manage_stock'  => 'no',
			'_stock_status'  => 'outofstock',
			'_stock'         => 0,
		) );

		/**
		 * @todo generate guid
		 */

		$this->post = new \WP_Post( (object) $args );
		$this->set_meta( $meta );
		$this->set_external( $ext ? $ext : $args['post_mime_type'] );

		$this->warehouse = new Collection();
	}

	static function get_structure( $key ) {
		$structure = array(
			'posts'    => array(
				'ID'                    => '%d',
				'post_author'           => '%d',
				'post_date'             => '%s',
				'post_date_gmt'         => '%s',
				'post_content'          => '%s',
				'post_title'            => '%s',
				'post_excerpt'          => '%s',
				'post_status'           => '%s',
				'comment_status'        => '%s',
				'ping_status'           => '%s',
				'post_password'         => '%s',
				'post_name'             => '%s',
				'to_ping'               => '%s',
				'pinged'                => '%s',
				'post_modified'         => '%s',
				'post_modified_gmt'     => '%s',
				'post_content_filtered' => '%s',
				'post_parent'           => '%d',
				'guid'                  => '%s',
				'menu_order'            => '%d',
				'post_type'             => '%s',
				'post_mime_type'        => '%s',
				'comment_count'         => '%d',
			),
			'postmeta' => array(
				'meta_id'    => '%d',
				'post_id'    => '%d',
				'meta_key'   => '%s',
				'meta_value' => '%s',
			)
		);

		if ( isset( $structure[ $key ] ) ) {
			return $structure[ $key ];
		}

		return false;
	}

	function set_relationship( $context = '', $relation, $value = false ) {
		switch ( $context ) {
			case 'product_cat':
			case 'warehouse':
			case 'developer':
				if ( $relation instanceof ExchangeTerm ) {
					$this->$context->add( $relation );
				} else {
					Plugin::error( 'Fatal error: $relation must be ExchangeTerm' );
				}
				break;

			case 'properties':
				if ( $relation instanceof ExchangeAttribute ) {
					$relationValue = clone $relation;
					$relationValue->set_value( $value );
					$relationValue->reset_terms();

					$this->$context->add( $relationValue );
				} else {
					Plugin::error( 'Fatal error: $relation must be ExchangeAttribute' );
				}
				break;

			default:
				Plugin::error( 'Fatal error: $relation unnamed $context relation' );
		}
	}

	function is_new() {
		$start_date = get_option( 'exchange_start-date', '' );

		if ( $start_date && strtotime( $start_date ) <= strtotime( $this->post->post_date ) ) {
			return true;
		}

		/**
		 * 2d secure ;D
		 */
		if ( empty( $this->post->post_modified ) || $this->post->post_date == $this->post->post_modified ) {
			return true;
		}

		return false;
	}

	function set_id( $value ) {
		$this->post->ID = intval( $value );
	}

	function get_id() {
		return $this->post->ID;
	}

	function prepare() {
	}

	function get_object() {
		return $this->post;
	}

	public function get_external() {
		return $this->post->post_mime_type;
	}

	public function get_raw_external() {
		@list( , $ext ) = explode( '/', $this->get_external() );

		return $ext;
	}

	public function set_external( $ext ) {
		if ( 0 !== strpos( $ext, 'XML' ) ) {
			$ext = 'XML/' . $ext;
		}

		$this->post->post_mime_type = (string) $ext;
	}

	public function deactivate() {
		global $wpdb;

		$wpdb->update(
			$wpdb->posts,
			// set
			array( 'post_status' => 'draft' ),
			// where
			array(
				'post_mime_type' => $this->get_external(),
				'post_status'    => 'publish',
			)
		);
	}

	/**
	 * [fillExistsProductData description]
	 *
	 * @param array  &$products products or offers collections
	 * @param boolean $orphaned_only [description]
	 *
	 * @return [type]                 [description]
	 */
	static public function fill_exists_from_DB( &$products, $orphaned_only = false ) {
		// $startExchange = get_option( 'exchange_start-date', '' );
		// $intStartExchange = strtotime($startExchange);

		/** @global \WP_Query $wpdb wordpress database object */
		global $wpdb;

		/** @var array $post_mime_types List of external code items list in database attribute context (%s='%s') */
		$post_mime_types = array();

		/** @var array list of objects exists from posts db */
		$exists = array();

		/** @var $product ExchangePost */
		/**
		 * EXPLODE FOR SIMPLE ONLY
		 * @todo
		 */
		foreach ( $products as $rawExternalCode => $product ) {
			if ( ! $orphaned_only || ( $orphaned_only && ! $product->get_id() ) ) {
				list( $product_ext ) = explode( '#', $product->get_external() );
				$post_mime_types[] = "`post_mime_type` = '" . esc_sql( $product_ext ) . "'";
			}
		}

		if ( $post_mime_type = implode( " \t\n OR ", $post_mime_types ) ) {
			// ID, post_author, post_date, post_title, post_content, post_excerpt, post_date_gmt, post_name, post_mime_type - required
			$exists = $wpdb->get_results( "
                SELECT *
                FROM $wpdb->posts
                WHERE post_type = 'product'
                AND (\n\t\n $post_mime_type \n)" );
		}

		/** @var $exists array products from db */
		foreach ( $exists as $exist ) {
			/** @var $mime string post_mime_type without XML/ */
			$mime = substr( $exist->post_mime_type, 4 );

			if ( $mime && isset( $products[ $mime ], $products[ $mime ]->post ) ) {
				/** Skip if selected (unset new data field from array (@care)) */
				// if( $post_name = Plugin::get('post_name') )         unset( $exist->post_name );
				if ( ! Plugin::get( 'skip_post_author' ) ) {
					unset( $exist->post_author );
				}
				if ( ! Plugin::get( 'skip_post_title' ) ) {
					unset( $exist->post_title );
				}
				if ( ! Plugin::get( 'skip_post_content' ) ) {
					unset( $exist->post_content );
				}
				if ( ! Plugin::get( 'skip_post_excerpt' ) ) {
					unset( $exist->post_excerpt );
				}

				foreach ( get_object_vars( $exist ) as $key => $value ) {
					$products[ $mime ]->post->$key = $value;
				}
			}
		}
	}

	function get_all_relative_externals( $orphaned_only = false ) {
		$arExternals = array();

		if ( ! empty( $this->product_cat ) ) {
			foreach ( $this->product_cat as $product_cat ) {
				if ( $orphaned_only && $product_cat->get_id() ) {
					continue;
				}
				$arExternals[] = $product_cat->get_external();
			}
		}

		if ( ! empty( $this->warehouse ) ) {
			foreach ( $this->warehouse as $warehouse ) {
				if ( $orphaned_only && $warehouse->get_id() ) {
					continue;
				}
				$arExternals[] = $warehouse->get_external();
			}
		}

		if ( ! empty( $this->developer ) ) {
			foreach ( $this->developer as $developer ) {
				if ( $orphaned_only && $developer->get_id() ) {
					continue;
				}
				$arExternals[] = $developer->get_external();
			}
		}

		if ( ! empty( $this->properties ) ) {
			foreach ( $this->properties as $property ) {
				foreach ( $property->get_terms() as $ex_term ) {
					if ( $orphaned_only && $ex_term->get_id() ) {
						continue;
					}

					$arExternals[] = $ex_term->get_external();
				}
			}
		}

		return $arExternals;
	}

	function fill_exists_relatives_from_DB() {
		/** @global wpdb $wpdb built in wordpress db object */
		global $wpdb;

		$arExternals = $this->get_all_relative_externals( true );

		if ( ! empty( $arExternals ) ) {
			foreach ( $arExternals as $strExternal ) {
				$arSqlExternals[] = "`meta_value` = '{$strExternal}'";
			}

			$arTerms = array();

			$exsists_terms_query = "
                SELECT term_id, meta_key, meta_value
                FROM $wpdb->termmeta
                WHERE meta_key = '" . ExchangeTerm::get_ext_ID() . "'
                    AND (" . implode( " \t\n OR ", array_unique( $arSqlExternals ) ) . ")";

			$ardbTerms = $wpdb->get_results( $exsists_terms_query );
			foreach ( $ardbTerms as $ardbTerm ) {
				$arTerms[ $ardbTerm->meta_value ] = $ardbTerm->term_id;
			}

			if ( ! empty( $this->product_cat ) ) {
				foreach ( $this->product_cat as &$product_cat ) {
					$ext = $product_cat->get_external();
					if ( ! empty( $arTerms[ $ext ] ) ) {
						$product_cat->set_id( $arTerms[ $ext ] );
					}
				}
			}

			if ( ! empty( $this->warehouse ) ) {
				foreach ( $this->warehouse as &$warehouse ) {
					$ext = $warehouse->get_external();
					if ( ! empty( $arTerms[ $ext ] ) ) {
						$warehouse->set_id( $arTerms[ $ext ] );
					}
				}
			}

			if ( ! empty( $this->developer ) ) {
				foreach ( $this->developer as &$developer ) {
					$ext = $developer->get_external();
					if ( ! empty( $arTerms[ $ext ] ) ) {
						$developer->set_id( $arTerms[ $ext ] );
					}
				}
			}

			if ( ! empty( $this->properties ) ) {
				foreach ( $this->properties as &$property ) {
					if ( $property instanceof ExchangeAttribute ) {
						foreach ( $property->get_terms() as &$term ) {
							$ext = $term->get_external();
							if ( ! empty( $arTerms[ $ext ] ) ) {
								$term->set_id( $arTerms[ $ext ] );
							}
						}
					} else {
						Plugin::error( 'property: ' . print_r( $property, 1 ) . ' not has attribute instance.' );
					}
				}
			}
		}
	}

	function get_product_meta() {
		$meta = $this->get_meta();

		unset( $meta['_price'], $meta['_regular_price'], $meta['_manage_stock'], $meta['_stock_status'], $meta['_stock'] );

		return $meta;
	}
}
