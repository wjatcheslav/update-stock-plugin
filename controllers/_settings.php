<?php

class updateStockSettings {
	function __construct( $parent ) {
		add_action( 'admin_menu', array( $this, 'usp_add_pages' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'usp_load_styles' ) );
		add_filter( 'upload_mimes', array( $this, 'usp_mime_types' ), 1, 1 );
	}

	// Enable admin styles
	function usp_load_styles() {
		global $updateStock;
		wp_enqueue_style( 'usp_css', $updateStock->url . '/assets/css/style.min.css', false, '1.0.0' );
	}

	// Adds an option and a page to the admin menu "Update Stock" and "Logs"
	function usp_add_pages() {
		add_menu_page( __( 'Update Stock' ), __( 'Update Stock' ), 8, __FILE__, array(
			$this,
			'usp_update_stock_page'
		) );
		add_submenu_page( __FILE__, __( 'Logs' ), __( 'Logs' ), 8, 'usp_logs_subpage', array(
			$this,
			'usp_logs_subpage'
		) );
	}

	// Update stock page
	function usp_update_stock_page() {
		if ( is_admin() ) {
			if ( ! empty( $_FILES ) ) {
				if ( wp_verify_nonce( $_POST['usp_fileup_nonce'], 'usp_file_upload' ) ) {
					if ( ! function_exists( 'wp_handle_upload' ) ) {
						require_once( ABSPATH . 'wp-admin/includes/file.php' );
					}
					$file      = &$_FILES['usp_file_upload'];
					$overrides = array( 'test_form' => false );
					$movefile  = wp_handle_upload( $file, $overrides );

					// XLSX validation
					$finfo                = finfo_open( FILEINFO_MIME_TYPE );
					$validation_xlsx_type = finfo_file( $finfo, $movefile['file'] );
					@finfo_close( $finfo );
					if ( $movefile && empty( $movefile['error'] ) && ( strcmp( $validation_xlsx_type, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' ) == 0 || strcmp( $validation_xlsx_type, 'application/zip' ) == 0 ) ) {

						// Unzip
						$zip = new ZipArchive();
						$zip->open( $movefile['file'] );
						$data_upload_dir         = wp_upload_dir();
						$data_upload_dir['path'] .= '/tmp';
						$zip->extractTo( $data_upload_dir['path'] );

						// Begin XML validation
						$finfo                      = finfo_open( FILEINFO_MIME_TYPE );
						$validation_first_xml_type  = finfo_file( $finfo, $data_upload_dir['path'] . '/xl/sharedStrings.xml' );
						$validation_second_xml_type = finfo_file( $finfo, $data_upload_dir['path'] . '/xl/worksheets/sheet1.xml' );
						@finfo_close( $finfo );
						if ( strcmp( $validation_first_xml_type, 'application/xml' ) == 0 && strcmp( $validation_second_xml_type, 'application/xml' ) == 0 ) {

							// Open up shared strings & the first worksheet
							$strings = simplexml_load_file( $data_upload_dir['path'] . '/xl/sharedStrings.xml' );
							$sheet   = simplexml_load_file( $data_upload_dir['path'] . '/xl/worksheets/sheet1.xml' );

							// Parse the rows
							$xlrows           = $sheet->sheetData->row;
							$usp_progress_log = get_option( 'usp_progress_log' );
							$total_counter    = 0;

							// REST API obj's
							$request             = new WP_REST_Request( 'POST' );
							$products_controller = new WC_REST_Products_Controller;

							foreach ( $xlrows as $xlrow ) {
								$arr = array();

								// In each row, grab it's value
								foreach ( $xlrow->c as $cell ) {
									$v = self::my_clean( (string) $cell->v );

									// If it has a "t" (type?) of "s" (string?), use the value to look up string value
									if ( isset( $cell['t'] ) && $cell['t'] == 's' ) {
										$s  = array();
										$si = $strings->si[ (int) $v ];

										// Register & alias the default namespace or you'll get empty results in the xpath query
										$si->registerXPathNamespace( 'n', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main' );

										// Cat together all of the 't' (text?) node values
										foreach ( $si->xpath( './/n:t' ) as $t ) {
											$s[] = self::my_clean( (string) $t );
										}
										$v = implode( $s );
									}
									$arr[] = $v;
								}

								// Assuming the first row are headers, stick them in the headers array
								if ( count( $headers ) == 0 ) {
									$headers = $arr;
								} else {

									// Combine the row with the headers - make sure we have the same column count
									$values = array_pad( $arr, count( $headers ), '' );

									$data_id  = (int) self::my_clean( $values[0] ) * 1;
									$data_sku = self::my_clean( $values[1] );

									if ( is_numeric( $data_sku ) ) {
										$data_sku = $data_sku * 1;
										$data_sku .= '';
									}

									$data_stock_status = false;
									if ( (int) self::my_clean( $values[2] * 1 ) != 0 ) {
										$data_stock_status = true;
									}
									$data_stock_status_log = 'OUT OF STOCK';
									if ( $data_stock_status ) {
										$data_stock_status_log = 'IN STOCK';
									}
									$data_stock_status_err_log = $data_stock_status_log;

									$data_id_log = 'NOT SET';
									if ( ! $data_id == 0 && ! empty( $data_id ) ) {
										$data_id_log = $data_id;
									}

									$data_sku_log = 'NOT SET';
									if ( ! empty( $data_sku ) ) {
										$data_sku_log = $data_sku;
									}

									if ( empty( $data_id ) || empty( $data_sku ) || ! is_numeric( $data_id ) || ! is_string( $data_sku ) || ! is_bool( $data_stock_status ) ) {
										$usp_progress_log = esc_html( '<code>Data error: #ID: ' . __( $data_id_log ) . ', #SKU: ' . __( $data_sku_log ) . ' #STOCK_STATUS: ' . __( $data_stock_status_err_log ) . ' has not been updated.</code>' . $usp_progress_log );
										continue;
									}

									// Begin update datas via REST
									$data = [
										'id'       => $data_id,
										'sku'      => $data_sku,
										'in_stock' => $data_stock_status,
									];

									$request->set_body_params( $data );
									$responce = $products_controller->update_item( $request );
									if ( isset( $responce->errors ) || isset( $responce->error_data ) ) {
										if ( ! empty( $responce->errors['woocommerce_rest_product_invalid_id'][0] ) ) {
											$responce_log = ' Responce: ' . esc_html( $responce->errors['woocommerce_rest_product_invalid_id'][0] );
										}
										$usp_progress_log = '<p><code>Product error #ID: ' . esc_html( $data_id ) . ' has not been updated.' . $responce_log . '</code></p>' . $usp_progress_log;
									} else {
										++ $total_counter;
										$usp_progress_log = '<p><code>Success #ID: ' . esc_html( $data_id ) . ', #SKU: ' . esc_html( $data_sku ) . ', #STOCK_STATUS: ' . esc_html( $data_stock_status_log ) . '</code></p>' . $usp_progress_log;
									}
									// End update datas via REST
								}
							}

							// Clean files
							@unlink( $movefile['file'] );
							@unlink( $data_upload_dir['path'] . '/_rels/.rels' );
							@self::delete_dir( $data_upload_dir['path'] );

							// Save logs
							update_option( 'usp_update_log', '<p><code>Updated ' . $total_counter . ' products => ' . esc_html( date( "D M j G:i:s Y" ) ) . '</code></p>' . get_option( 'usp_update_log' ) );
							update_option( 'usp_progress_log', $usp_progress_log );

							echo '<div id="usp-notice_success" class="updated settings-error notice is-dismissible"><p><strong>Success... Updated ' . $total_counter . ' products</strong></p><button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button></div>';

						} else {
							echo '<div id="usp-notice_error" class="error settings-error notice is-dismissible"><p><strong>An error occurred while loading the file! XLSX format failure.</strong></p><button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button></div>';
						}
					} else {
						echo '<div id="usp-notice_error" class="error settings-error notice is-dismissible"><p><strong>An error occurred while loading the file!</strong></p><button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button></div>';
					} // End XML validation
				}
			}
			echo '<h1>' . __( 'Update Sock' ) . '</h1>';
			echo '<div class="upload-ui">';
			echo '<h2 class="upload-instructions drop-instructions">Select XLSX file to upload</h2>';
			echo '<form enctype="multipart/form-data" action="" method="post">';
			wp_nonce_field( 'usp_file_upload', 'usp_fileup_nonce' );
			echo '<input type="file" name="usp_file_upload" value="Select File" />';
			echo '<input type="submit" name="sumit" id="submit" class="button button-primary" value="Upload" />
					 </form></div>';

			/* Disable progress bar
			echo '<h2 class="progress_bar">Progress bar</h2>';
			echo get_option( 'usp_progress_log' );
			 */

		} else {
			echo '<div id="usp-notice_error" class="error settings-error notice is-dismissible"><p><strong>' . __( 'Permission denied.' ) . '</strong></p></div>';
		}
	}

	// Logs page
	function usp_logs_subpage() {
		if ( is_admin() ) {
			echo '<h1>' . __( 'Logs' ) . '</h1>';
			if ( empty( get_option( 'usp_update_log' ) ) ) {
				echo '<code>' . __( 'Log is empty.' ) . '</code>';
			} else {
				echo get_option( 'usp_update_log' );
			}
		} else {
			echo '<div id="usp-notice_error" class="error settings-error notice is-dismissible"><p><strong>' . __( 'Permission denied.' ) . '</strong></p></div>';
		}
	}

	// Allow XML & XLSX types
	function usp_mime_types( $mime_types ) {
		$mime_types['xml']  = 'application/xml';
		$mime_types['xlsx'] = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

		return $mime_types;
	}

	// Custom complex clean
	function my_clean( $value = '' ) {
		$value = trim( $value );
		$value = stripslashes( $value );
		$value = strip_tags( $value );
		$value = htmlspecialchars( $value );

		return $value;
	}

	function delete_dir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			throw new InvalidArgumentException( $dir . 'must be a directory' );
		}
		if ( substr( $dir, strlen( $dir ) - 1, 1 ) != '/' ) {
			$dir .= '/';
		}
		$files = glob( $dir . '*', GLOB_MARK );
		foreach ( $files as $file ) {
			if ( is_dir( $file ) ) {
				@self::delete_dir( $file );
			} else {
				@unlink( $file );
			}
		}
		@rmdir( $dir );
	}
}