<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Plugin Name: Clean Existing Media Filenames
 * Plugin URI: https://github.com/Trollhag/clean-existing-media-filenames
 * Description: This plugin cleans the filenames of the existing attachments.
 * Version: 1.0.0
 * Author: Trollhag
 * Text Domain: clean-existing-media-filenames
 * Domain Path: /languages
 * License: GPL2
 */

/**
 * Main plugin class.
 */
class CleanExistingMediaFilenames {

	/**
	 * Adds plugin actions and filters.
	 */
	public function __construct() {

		add_action( 'wp_ajax_clean_existing_media_filenames', array( $this, 'ajax_clean_media' ) );
		add_action( 'admin_init', array( $this, 'admin_init' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'add_action_links' ) );

	}

	/**
	 * Add custom action links to the plugin's row in the plugins list.
	 *
	 * @param array $links   Original action links.
	 * @return array Action links with new addition.
	 */
	public function add_action_links( $links ) {
		return array_merge(
			$links,
			array(
				'<a href="' . admin_url( 'options-media.php' ) . '">' . __( 'Settings' ) . '</a>',
			)
		);
	}

	/**
	 * Adds plugin settings at admin_init action.
	 *
	 * @return void
	 */
	public function admin_init() {

		add_settings_section(
			'clean_existing_media_filenames',
			__( 'Clean Existing Media Filenames', 'clean-existing-media-filenames' ),
			null,
			'media'
		);
		add_settings_field(
			'clean_existing_media_filenames',
			'',
			array(
				$this,
				'render_start_cleaning',
			),
			'media',
			'clean_existing_media_filenames'
		);

	}

	/**
	 * Renderes start cleaning button and script.
	 *
	 * @return void
	 */
	public function render_start_cleaning() {
		$media       = array();
		$media_query = get_posts(
			array(
				'post_type'   => 'attachment',
				'numberposts' => -1,
				'post_status' => null,
			)
		);
		foreach ( $media_query as $p ) {
			$meta     = wp_get_attachment_metadata( $p->ID );
			$filename = basename( $meta['file'] );
			if ( $this->clean_filename( $filename ) !== $filename ) {
				$media[] = $p->ID;
			}
		}
		?>
		<script>
			var existing_media = <?php echo wp_json_encode( $media ); ?>;
			function clean_existing_media_filenames() {
				if (existing_media.length > 0) {
					var id = existing_media.shift();
					jQuery.post('<?php echo esc_url( admin_url( 'admin-ajax.php?action=clean_existing_media_filenames' ) ); ?>&id=' + id, '', function(result) {
						var message = document.getElementById('clean_existing_media_filenames_message');
						if (result === -2) {
							var p = document.createElement('P')
							p.innerText = "<?php esc_html_e( 'Failed to rename file. Check your WordPress filesystem permissions.', 'clean-existing-media-filenames' ); ?>";
							message.appendChild(p);
						} else if (result instanceof Array) {
							var p = document.createElement('P')
							p.innerText = result[0] + ' > ' + result[1];
							message.appendChild(p);
							console.log(result);
						}
						clean_existing_media_filenames();
					})
				}
			}
		</script>
		<?php if ( count( $media ) === 0 ) : ?>
			<p><?php esc_html_e( 'No filenames need to be clean.', 'clean-existing-media-filenames' ); ?></p>
		<?php endif; ?>
		<button type="button" class="button default" onclick="clean_existing_media_filenames()" <?php echo esc_attr( count( $media ) === 0 ? 'disabled' : '' ); ?>>
			<?php esc_html_e( 'Fix filenames', 'clean-existing-media-filenames' ); ?>
		</button>
		<div id="clean_existing_media_filenames_message"></div>
		<?php
	}

	/**
	 * Returns a clean filename.
	 *
	 * Blatently borrowed from Clean Image Filenames by Upperdog.
	 *
	 * @link https://wordpress.org/plugins/clean-image-filenames/
	 *
	 * @param string $filename   Filename to clean.
	 * @return string
	 */
	public function clean_filename( $filename ) {

		$input = array(
			'ß',
			'·',
			'%', // Remove any % characters, which are URL safe but not filename safe.
		);

		$output = array(
			'ss',
			'.',
			'',
		);

		$path         = pathinfo( $filename );
		$new_filename = preg_replace( '/.' . $path['extension'] . '$/', '', $filename );
		$new_filename = str_replace( $input, $output, $new_filename );
		return sanitize_title( $new_filename ) . '.' . $path['extension'];

	}

	/**
	 * Undocumented function
	 *
	 * @return void
	 */
	public function ajax_clean_media() {

		$id         = filter_input( INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT );
		$meta       = wp_get_attachment_metadata( $id );
		$upload_dir = wp_upload_dir();

		$name     = basename( $meta['file'] );
		$new_name = $this->clean_filename( $name );

		if ( $name !== $new_name ) {

			$new_file = str_replace( $name, $new_name, $meta['file'] );

			$renamed = rename( $upload_dir['basedir'] . '/' . $meta['file'], $upload_dir['basedir'] . '/' . $new_file );

			if ( $renamed ) {

				$meta['file'] = $new_file;

				if ( array_key_exists( 'sizes', $meta ) ) {

					foreach ( $meta['sizes'] as $size => $data ) {

						$size_file_name = str_replace( $name, $data['file'], $meta['file'] );
						$size_new_name  = $this->clean_filename( $data['file'] );
						$size_new_file  = str_replace( $name, $size_new_name, $meta['file'] );

						$size_renamed = rename( $upload_dir['basedir'] . '/' . $size_file_name, $upload_dir['basedir'] . '/' . $size_new_file );

						if ( $size_renamed ) {
							$meta['sizes'][ $size ]['file'] = $size_new_name;
						}
					}
				}

				$backup = get_post_meta( intval( $id ), '_wp_attachment_backup_sizes', true );
				if ( is_array( $backup ) ) {

					foreach ( $backup as $size => $data ) {

						$backup[ $size ]['file'] = $this->clean_filename( $data['file'] );

					}

					update_post_meta( intval( $id ), '_wp_attachment_backup_sizes', $backup );
				}

				update_post_meta( intval( $id ), '_wp_attached_file', $new_file );
				update_post_meta( intval( $id ), '_wp_attachment_metadata', $meta );

				wp_send_json( array( $name, $new_name ), 200 );

			} else {
				wp_send_json( -2, 200 );
			}
		}

		wp_send_json( -1, 200 );

	}

}
$clean_existing_media_filenames = new CleanExistingMediaFilenames();
