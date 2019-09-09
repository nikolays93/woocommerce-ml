<?php

namespace NikolayS93\Exchange;

use NikolayS93\Exchange\Model\ExchangeOffer;
use NikolayS93\Exchange\Model\ExchangeProduct;
use NikolayS93\Exchange\ORM\Collection;

class REST_Controller {

	const option_version = 'exchange_version';

	/**
	 * The capability required to use REST API.
	 *
	 * @default gets from Plugin
	 * @var string
	 */
	public $permissions = 'manage_options';

	/**
	 * The namespace for the REST API routes.
	 *
	 * @var string
	 */
	public $namespace = 'exchange/v1';

	/**
	 * @var string (float value)
	 */
	public $version;

	function __construct() {
		// Set CommerceML protocol version
		$this->version = get_option( self::option_version, '' );

		// Allow people to change what capability is required to use this plugin.
		$this->permissions = apply_filters( Plugin::PREFIX . 'rest_permissions', $this->permissions );
	}

	function register_routes() {
		register_rest_route( $this->namespace, '/status/', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'status' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			),
		) );

		register_rest_route( $this->namespace, '/init/', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'init' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			),
		) );

		register_rest_route( $this->namespace, '/file/', array(
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'file' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			),
		) );

		register_rest_route( $this->namespace, '/import/', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'import' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			),
		) );

		register_rest_route( $this->namespace, '/deactivate/', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'deactivate' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			),
		) );

		register_rest_route( $this->namespace, '/complete/', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'complete' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			),
		) );
	}

	public function permissions_check( $request ) {
		return current_user_can( $this->permissions );
	}

	public function status() {
		the_statistic_table();
	}

	/**
	 * @url http://v8.1c.ru/edi/edi_stnd/131/
	 *
	 * A. Начало сеанса (Авторизация)
	 * Выгрузка данных начинается с того, что система "1С:Предприятие" отправляет http-запрос следующего вида:
	 * http://<сайт>/<путь>/1c_exchange.php?type=catalog&mode=checkauth.
	 *
	 * A. Начало сеанса
	 * http://<сайт>/<путь> /1c_exchange.php?type=sale&mode=checkauth.
	 * @return 'success\nCookie\nCookie_value'
	 */
	public function checkauth() {
		foreach ( array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ) as $server_key ) {
			if ( ! isset( $_SERVER[ $server_key ] ) ) {
				continue;
			}

			list( , $auth_value ) = explode( ' ', $_SERVER[ $server_key ], 2 );
			$auth_value = base64_decode( $auth_value );
			list( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] ) = explode( ':', $auth_value );

			break;
		}

		if ( ! isset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] ) ) {
			Error::set_message( "No authentication credentials" );
		}

		$user = wp_authenticate( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] );
		if ( is_wp_error( $user ) ) {
			Error::set_message( $user );
		}
		Plugin::check_user_permissions( $user );

		$expiration  = TIMESTAMP + apply_filters( 'auth_cookie_expiration', DAY_IN_SECONDS, $user->ID, false );
		$auth_cookie = wp_generate_auth_cookie( $user->ID, $expiration );

		exit( "success\n" . COOKIENAME . "\n$auth_cookie" );
	}

	/**
	 * B. Запрос параметров от сайта
	 * http://<сайт>/<путь> /1c_exchange.php?type=catalog&mode=init
	 * B. Уточнение параметров сеанса
	 * http://<сайт>/<путь> /1c_exchange.php?type=sale&mode=init
	 *
	 * @return
	 * zip=yes|no - Сервер поддерживает Zip
	 * file_limit=<число> - максимально допустимый размер файла в байтах для передачи за один запрос
	 */
	public function init() {
		/** Zip required (if no - must die) */
		check_zip_extension();

		/**
		 * Option is empty then exchange end
		 * @var [type]
		 */
		if ( ! $start = get_option( 'exchange_start-date', '' ) ) {
			/**
			 * Refresh exchange version
			 * @var float isset($_GET['version']) ? ver >= 3.0 : ver <= 2.99
			 */
			update_option( 'exchange_version', ! empty( $_GET['version'] ) ? $_GET['version'] : '' );

			/**
			 * Set start wp date sql format
			 */
			update_option( 'exchange_start-date', current_time( 'mysql' ) );

			Plugin::set_mode( '' );
		}

		exit( "zip=yes\nfile_limit=" . get_filesize_limit() );
	}

	/**
	 * C. Получение файла обмена с сайта
	 * http://<сайт>/<путь> /1c_exchange.php?type=sale&mode=query.
	 */
	public function query() {
	}

	/**
	 * C. Выгрузка на сайт файлов обмена
	 * http://<сайт>/<путь> /1c_exchange.php?type=catalog&mode=file&filename=<имя файла>
	 * D. Отправка файла обмена на сайт
	 * http://<сайт>/<путь> /1c_exchange.php?type=sale&mode=file&filename=<имя файла>
	 *
	 * Загрузка CommerceML2 файла или его части в виде POST. (Пишем поток в файл и распаковывает его)
	 * @return string 'success'
	 */
	public function file() {
		/** @var \NikolayS93\Exchange\Plugin $Plugin */
		$Plugin = Plugin();

		$filename = Request::get_filename();
		$path_dir = $Plugin->get_exchange_dir( Request::get_type() );

		if ( ! empty( $filename ) ) {
			$path      = $path_dir . '/' . ltrim( $filename, "./\\" );
			$temp_path = "$path~";

			$input_file = fopen( "php://input", 'r' );
			$temp_file  = fopen( $temp_path, 'w' );
			stream_copy_to_stream( $input_file, $temp_file );

			if ( is_file( $path ) ) {
				$temp_header = file_get_contents( $temp_path, false, null, 0, 32 );
				if ( strpos( $temp_header, "<?xml " ) !== false ) {
					unlink( $path );
					Error::set_message( "Тэг xml во временном потоке не обнаружен.", "Notice" );
				}
			}

			$temp_file = fopen( $temp_path, 'r' );
			$file      = fopen( $path, 'a' );
			stream_copy_to_stream( $temp_file, $file );
			fclose( $temp_file );
			unlink( $temp_path );

			if ( 0 == filesize( $path ) ) {
				Plugin::error( sprintf( "File %s is empty", $path ) );
			}
		}

		$zip_paths = glob( "$path_dir/*.zip" );
		if ( empty( $zip_paths ) ) {
			Plugin::error( 'Archives list unavalible.' );
		}

		$r = Plugin::unzip( $zip_paths, $path_dir, false );
		if ( true !== $r ) {
			Plugin::error( 'Unzip archive error. ' . print_r( $r, 1 ) );
		}

		if ( 'catalog' == Plugin::get_type() ) {
			exit( "success\nФайл принят." );
		}
	}

	public function update_offers( Parser $Parser ) {
		/** @var $progress int Offset from */
		$progress = intval( Plugin::get( 'progress', 0, 'status' ) );
		$filename = Request::get_filename();

		$offers      = $Parser->get_offers();
		$offersCount = sizeof( $offers );
		/** @recursive update if is $offersCount > $offset */
		if ( $offersCount > $progress ) {
			Transaction::get_instance()->set_transaction_mode();

			/**
			 * Slice offers who offset better
			 */
			$offers = array_slice( $offers->fetch(), $progress, $this->offset['offer'] );

			/** Count offers who will be updated */
			$progress += sizeof( $offers );

			$answer = 'progress';

			/** Require retry */
			if ( $progress < $offersCount ) {
				Plugin::set_mode( '', array( 'progress' => (int) $progress ) );
			} /** Go away */
			else {
				if ( 0 === strpos( $filename, 'offers' ) ) {
					Plugin::set_mode( 'relationships' );
				} else {
					$answer = 'success';
					Plugin::set_mode( '' );
				}
			}

			$resOffers = Update::offers( $offers );

			// has new products without id
			if ( 0 < $resOffers['create'] ) {
				ExchangeOffer::fillExistsFromDB( $offers, $orphaned_only = true );
			}

			Update::offerPostMetas( $offers );

			if ( 0 === strpos( $filename, 'price' ) ) {
				$msg = "$progress из $offersCount цен обработано.";
			} elseif ( 0 === strpos( $filename, 'rest' ) ) {
				$msg = "$progress из $offersCount запасов обработано.";
			} else {
				$msg = "$progress из $offersCount предложений обработано.";
			}

			exit( "$answer\n$msg" );
		}
	}

	public function update_products_relationships( Parser $Parser ) {
		$msg = 'Обновление зависимостей завершено.';

		/** @var $progress int Offset from */
		$progress       = intval( Plugin::get( 'progress', 0, 'status' ) );
		$products       = $Parser->get_products();
		$products_count = $products->count();

		if ( $products_count > $progress ) {
			// Plugin::set_transaction_mode();
			$offset         = apply_filters( 'exchange_products_relationships_offset', 500, $products_count,
				Request::get_filename() );
			$products       = array_slice( $products->fetch(), $progress, $offset );
			$sizeOfProducts = sizeof( $products );

			/**
			 * @todo write really update counter
			 */
			$relationships = Update::relationships( $products );
			$progress      += $sizeOfProducts;
			$msg           = "$relationships зависимостей $sizeOfProducts товаров обновлено (всего $progress из $products_count обработано).";

			/** Require retry */
			if ( $progress < $products_count ) {
				Plugin::set_mode( 'relationships', array( 'progress' => (int) $progress ) );
				exit( "progress\n$msg" );
			}
		}

		Plugin::set_mode( '' );
		exit( "success\n$msg" );
	}

	public function update_offers_relationships( Parser $Parser ) {
		$msg = 'Обновление зависимостей завершено.';

		/** @var $progress int Offset from */
		$progress = intval( Plugin::get( 'progress', 0, 'status' ) );
		$offers   = $Parser->get_offers();

		if ( $offers->count() > $progress ) {
			// Plugin::set_transaction_mode();
			$offset       = apply_filters( 'exchange_offers_relationships_offset', 500, $offersCount, $filename );
			$offers       = array_slice( $offers->fetch(), $progress, $offset );
			$sizeOfOffers = sizeof( $offers );

			$relationships = Update::relationships( $offers );
			$progress      += $sizeOfOffers;
			$msg           = "$relationships зависимостей $sizeOfOffers предложений обновлено (всего $progress из $offersCount обработано).";

			/** Require retry */
			if ( $progress < $offersCount ) {
				Plugin::set_mode( 'relationships', array( 'progress' => (int) $progress ) );
				exit( "progress\n$msg" );
			}

			if ( floatval( $this->version ) < 3 ) {
				Plugin::set_mode( 'deactivate' );
				exit( "progress\n$msg" );
			}
		}

		Plugin::set_mode( '' );
		exit( "success\n$msg" );
	}

	/**
	 * D. Пошаговая загрузка данных
	 * http://<сайт>/<путь> /1c_exchange.php?type=catalog&mode=import&filename=<имя файла>
	 * @return 'progress|success|failure'
	 */
	public function import() {
		if ( ! $filename = Request::get_filename() ) {
			Error::set_message( "Filename is empty" );
		}

		/**
		 * Parse
		 */
		$files  = Parser::getFiles( $filename );
		$Parser = Parser::get_instance();
		$Parser->parse( $files );
		$Parser->fill_exists();

		Transaction::get_instance()->set_transaction_mode();

		if ( 'relationships' != Request::get_mode() ) {
			$this->update_terms( $Parser );
			$this->update_products( $Parser );
			$this->update_offers( $Parser );
		} else {
			$this->update_products_relationships( $Parser );
			$this->update_offers_relationships( $Parser );
		}

		exit( "success" ); // \n$mode
	}

	/**
	 * E. Деактивация данных
	 * http://<сайт>/<путь> /1c_exchange.php?type=catalog&mode=deactivate
	 * @return 'progress|success|failure'
	 * @note We need always update post_modified for true deactivate
	 * @since  3.0
	 */
	public function deactivate() {
		/**
		 * Чистим и пересчитываем количество записей в терминах
		 */
		if ( ! $start_date = get_option( 'exchange_start-date', '' ) ) {
			return;
		}

		/**
		 * move .xml files from exchange folder
		 */
		$path_dir = Parser::getDir();
		$files    = Parser::getFiles();

		if ( ! empty( $files ) ) {
			reset( $files );

			/**
			 * Meta data from any finded file
			 * @var array { version: float, is_full: bool }
			 */
			$summary = Plugin::get_summary_meta( current( $files ) );

			foreach ( $files as $file ) {
				@unlink( $file );
				// $pathname = $path_dir . '/' . date( 'Ymd' ) . '_debug/';
				// @mkdir( $pathname );
				// @rename( $file, $pathname . ltrim( basename( $file ), "./\\" ) );
			}

			/**
			 * Need deactivate deposits products
			 * $summary['version'] < 3 && $version < 3 &&
			 */
			if ( true === $summary['is_full'] ) {
				$post_lost = Plugin::get( 'post_lost' );

				if ( ! $post_lost ) {
					// $postmeta['_stock'] = 0; // required?
					$wpdb->query( "UPDATE $wpdb->postmeta pm
                            SET
                                pm.meta_value = 'outofstock'
                            WHERE
                                pm.meta_key = '_stock_status' AND
                                pm.post_id IN (
                                    SELECT p.ID FROM $wpdb->posts p
                                    WHERE
                                        p.post_type = 'product'
                                        AND p.post_modified < '$start_date'
                                )" );
				} elseif ( 'delete' == $post_lost ) {
					// delete query
				}
			}
		}

		/**
		 * Set pending status when post no has price meta
		 * Most probably no has offer (or code error in last versions)
		 * @var array $notExistsPrice List of objects
		 */
		$notExistsPrice = $wpdb->get_results( "
                SELECT p.ID, p.post_type, p.post_status
                FROM $wpdb->posts p
                WHERE
                    p.post_type = 'product'
                    AND p.post_status = 'publish'
                    AND p.post_modified > '$start_date'
                    AND NOT EXISTS (
                        SELECT pm.post_id, pm.meta_key FROM $wpdb->postmeta pm
                        WHERE p.ID = pm.post_id AND pm.meta_key = '_price'
                    )
            " );

		// Collect Ids
		$notExistsPriceIDs = array_map( 'intval', wp_list_pluck( $notExistsPrice, 'ID' ) );

		/**
		 * Set pending status when post has a less price meta (null value)
		 * @var array $nullPrice List of objects
		 */
		$nullPrice = $wpdb->get_results( "
                SELECT pm.post_id, pm.meta_key, pm.meta_value, p.post_type, p.post_status
                FROM $wpdb->postmeta pm
                INNER JOIN $wpdb->posts p ON pm.post_id = p.ID
                WHERE   p.post_type   = 'product'
                    AND p.post_status = 'publish'
                    AND p.post_modified > '$start_date'
                    AND pm.meta_key = '_price'
                    AND pm.meta_value = 0
            " );

		// Collect Ids
		$nullPriceIDs = array_map( 'intval', wp_list_pluck( $nullPrice, 'post_id' ) );

		// Merge Ids
		$deactivateIDs = array_unique( array_merge( $notExistsPriceIDs, $nullPriceIDs ) );

		$price_lost = Plugin::get( 'price_lost' );

		/**
		 * Deactivate
		 */
		if ( ! $price_lost && sizeof( $deactivateIDs ) ) {
			/**
			 * Execute query (change post status to pending)
			 */
			$wpdb->query(
				"UPDATE $wpdb->posts SET post_status = 'pending'
                    WHERE ID IN (" . implode( ',', $deactivateIDs ) . ")"
			);
		} elseif ( 'delete' == $price_lost ) {
			// delete query
		}

		/**
		 * @todo how define time rengу one exhange (if exchange mode complete clean date before new part of offers)
		 * Return post status if product has a better price (only new)
		 */
		// $betterPrice = $wpdb->get_results( "
		//     SELECT pm.post_id, pm.meta_key, pm.meta_value, p.post_type, p.post_status
		//     FROM $wpdb->postmeta pm
		//     INNER JOIN $wpdb->posts p ON pm.post_id = p.ID
		//     WHERE   p.post_type   = 'product'
		//         AND p.post_status = 'pending'
		//         AND p.post_modified = p.post_date
		//         AND pm.meta_key = '_price'
		//         AND pm.meta_value > 0
		// " );

		// // Collect Ids
		// $betterPriceIDs = array_map('intval', wp_list_pluck( $betterPrice, 'ID' ));

		// if( sizeof($betterPriceIDs) ) {
		//     $wpdb->query(
		//         "UPDATE $wpdb->posts SET post_status = 'publish'
		//         WHERE ID IN (". implode(',', $betterPriceIDs) .")"
		//     );
		// }

		$msg = 'Деактивация товаров завершена';

		if ( floatval( $version ) < 3 ) {
			Plugin::set_mode( 'complete' );
			exit( "progress\n$msg" );
		}

		exit( "success\n$msg" );
	}

	/**
	 * F. Завершающее событие загрузки данных
	 * http://<сайт>/<путь> /1c_exchange.php?type=catalog&mode=complete
	 * @since  3.0
	 */
	public function complete() {
		/**
		 * Insert count the number of records in a category
		 * /
		 * Update::update_term_counts();
		 */
		// flush_rewrite_rules();

		/**
		 * Reset start date
		 * @todo @fixit (check between)
		 */
		update_option( 'exchange_start-date', '' );

		/**
		 * Refresh version
		 */
		update_option( 'exchange_version', '' );

		delete_transient( 'wc_attribute_taxonomies' );

		Plugin::set_mode( '' );
		update_option( 'exchange_last-update', current_time( 'mysql' ) );

		exit( "success\nВыгрузка данных завершена" );
	}
}