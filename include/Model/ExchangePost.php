<?php

namespace NikolayS93\Exchange\Model;

use NikolayS93\Exchange\ORM\ExchangeItemMeta;
use NikolayS93\Exchange\Utils;

/**
 * Works with posts, term_relationships, postmeta
 */
class ExchangePost
{
    use ExchangeItemMeta;

    public $warehouse = array();

    /**
     * @var WP_Post
     * @sql FROM $wpdb->posts
     *      WHERE ID = %d
     */
    private $post;

    static function get_structure( $key )
    {
        $structure = array(
            'posts' => array(
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

        if( isset($structure[ $key ]) ) {
            return $structure[ $key ];
        }

        return false;
    }

    function getTarget( $context )
    {
        $target = null;

        switch ($context) {
            case 'properties':
            case 'arProperties':
            case 'property':
                $target = 'properties';
                break;

            case 'warehouse':
            case 'warehouses':
            case 'arWarehouses':
                $target = 'warehouse';
                break;

            case 'developer':
            case 'developers':
            case 'arDevelopers':
                $target = 'properties';
                break;

            case 'product_cat':
            case 'products_cat':
            case 'product_cats':
            default:
                $target = 'product_cat';
                break;
        }

        return $target;
    }

    function setRelationship( $context = '', ExchangeTerm $term ) // , ExchangeAttribute $tax = null
    {
        $target = $this->getTarget( $context );
        array_push($this->$target, new Relationship( array(
            'external' => $term->getExternal(),
            'id'       => $term->get_id(),
        ) ));
    }

    function __construct( Array $post, $ext = '', $meta = array() )
    {
        $args = wp_parse_args( $post, array(
            'post_author'       => get_current_user_id(),
            'post_status'       => apply_filters('ExchangePost__post_status', 'publish'),
            'comment_status'    => apply_filters('ExchangePost__comment_status', 'closed'),
            'post_type'         => 'product',
            'post_mime_type'    => '',
            'post_date'         => date  ('Y-m-d H:i:s'),
            'post_modified'     => date  ('Y-m-d H:i:s'),
            'post_date_gmt'     => gmdate('Y-m-d H:i:s'),
            'post_modified_gmt' => gmdate('Y-m-d H:i:s'),
        ) );

        if( empty($args['post_name']) ) {
            $args['post_name'] = Utils::esc_cyr($args['post_title']);
        }

        /**
         * For no offer defaults
         */
        $meta = wp_parse_args( $meta, array(
            '_price' => 0,
            '_regular_price' => 0,
            '_manage_stock' => 'no',
            '_stock_status' => 'outofstock',
            '_stock' => 0,
        ) );

        /**
         * @todo generate guid
         */

        $this->post = new \WP_Post( (object) $args );
        $this->setMeta($meta);
        $this->setExternal($ext ? $ext : $args['post_mime_type']);
    }

    function get_id()
    {
        return $this->post->ID;
    }

    function prepare()
    {
    }

    function getObject()
    {
        return $this->post;
    }

    public function getExternal()
    {
        return $this->post->post_mime_type;
    }

    public function getRawExternal()
    {
        @list(, $ext) = explode('/', $this->getExternal());
        return $ext;
    }

    public function setExternal( $ext )
    {
        if( 0 !== strpos($ext, 'XML') ) {
            $ext = 'XML/' . $ext;
        }

        $this->post->post_mime_type = (String) $ext;
    }

    public function deactivate()
    {
        global $wpdb;

        $wpdb->update(
            $wpdb->posts,
            // set
            array( 'post_status' => 'draft' ),
            // where
            array(
                'post_mime_type' => $this->getExternal(),
                'post_status'    => 'publish',
            )
        );
    }

    /**
     * [fillExistsProductData description]
     * @param  array  &$products      products or offers collections
     * @param  boolean $orphaned_only [description]
     * @return [type]                 [description]
     */
    static public function fillExistsFromDB( &$products, $orphaned_only = false )
    {
        /** @global wpdb wordpress database object */
        global $wpdb, $site_url;

        $site_url = get_site_url();
        $date_now = date('Y-m-d H:i:s');
        $gmdate_now = gmdate('Y-m-d H:i:s');

        /** @var List of external code items list in database attribute context (%s='%s') */
        $externals = array();

        /** @var array list of objects exists from posts db */
        $exists = array();

        /** @var $product NikolayS93\Exchange\Model\ProductModel or */
        /** @var $product NikolayS93\Exchange\Model\OfferModel */
        /**
         * EXPLODE FOR SIMPLE ONLY
         * @todo
         */
        foreach ($products as $rawExternalCode => $product)
        {
            if( !$orphaned_only || ($orphaned_only && !$product->get_id()) ) {
                list($ext) = explode("#", $product->getExternal());
                $externals[] = "`post_mime_type` = '". $ext ."'";
            }
        }

        if( !empty($externals) ) {
            //ID, post_date, post_date_gmt, post_name, post_mime_type
            $exists_query = "
                SELECT *
                FROM $wpdb->posts
                WHERE post_type = 'product'
                AND (\n". implode(" \t\n OR ", $externals) . "\n)";

            $exists = $wpdb->get_results( $exists_query );

            unset($externals);
        }

        // $startExchange = get_option( 'exchange_start-date', '' );
        // $intStartExchange = strtotime($startExchange);

        foreach ($exists as $exist)
        {
            /** @var post_mime_type without XML/ */
            $mime = substr($exist->post_mime_type, 4);

            if( $mime && isset($products[ $mime ]->post) ) {

                $originalPost = $products[ $mime ]->post;

                /** @var stdObject (similar WP_Post) */
                $post = &$products[ $mime ]->post;

                /**
                 * Set exists data
                 */
                foreach (get_object_vars( $exist ) as $vKey => $vVal)
                {
                    if( !empty($vVal) ) {
                        $post->$vKey = $vVal;
                    }
                }

                /**
                 * ..without modified date
                 */
                $post->post_modified     = $date_now;
                $post->post_modified_gmt = $gmdate_now;

                /**
                 * @todo What do you want to keep the same?
                 */
                $post = apply_filters( 'exchange-keep-product', $post, $originalPost, $exist );
            }
        }
    }
}
